<?php
declare(strict_types=1);
// עדכון תשלומי גבייה חד־פעמית מקובץ (xlsx/csv): שם תלמידה, סטטוס (שולם / לא שולם), צורת תשלום.
// הקובץ רק מוצג ומותאם לתלמידות (תצוגה מקדימה); הכתיבה למסד נעשית רק אחרי אישור ב-charges.php.

/** קורא את הקובץ: מחזיר [שם, סטטוס, צורת תשלום] לכל שורה. */
function ci_read(string $path, string $name): array {
  if (preg_match('/\.xlsx$/i', $name)) $rows = xlsx_rows($path);
  else {
    $fh = fopen($path, 'rb'); if (!$fh) throw new RuntimeException('לא ניתן לקרוא את הקובץ.');
    $first = (string)fgets($fh); rewind($fh); $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);
    $rows = []; while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) { $rows[] = $r; if (count($rows) > INCOME_MAX_ROWS + 20) break; } fclose($fh);
  }
  $col = []; $hi = -1;
  foreach ($rows as $i => $r) {
    $m = [];
    foreach ($r as $j => $v) {
      $t = trim((string)$v);
      if (!isset($m['n']) && preg_match('/^(שם|שם התלמידה|תלמידה)$/u', $t)) $m['n'] = $j;
      elseif (!isset($m['s']) && preg_match('/^(סטטוס|מצב|מצב תשלום)$/u', $t)) $m['s'] = $j;
      elseif (!isset($m['m']) && preg_match('/^(צורת תשלום|אמצעי תשלום|אופן תשלום)$/u', $t)) $m['m'] = $j;
    }
    if (isset($m['n'], $m['s'])) { $hi = $i; $col = $m; break; }
    if ($i > 10) break;
  }
  if ($hi < 0) throw new RuntimeException('לא נמצאה שורת כותרות. נדרשות העמודות "שם התלמידה" ו"סטטוס" (ואפשר גם "צורת תשלום").');
  $out = [];
  foreach (array_slice($rows, $hi + 1) as $r) {
    $n = trim((string)($r[$col['n']] ?? '')); if ($n === '') continue;
    $out[] = [mb_substr($n, 0, 100), mb_substr(trim((string)($r[$col['s']] ?? '')), 0, 40), isset($col['m']) ? mb_substr(trim((string)($r[$col['m']] ?? '')), 0, 40) : ''];
  }
  if (count($out) > INCOME_MAX_ROWS) throw new RuntimeException('יותר מדי שורות בקובץ (מקסימום ' . INCOME_MAX_ROWS . ').');
  return $out;
}
/** 'paid' | 'unpaid' | '' (לא מובן). */
function ci_status(string $s): string {
  $s = trim($s); if ($s === '') return '';
  if (preg_match('/לא\s*שולם|טרם|חייבת|חסר|ממתינ/u', $s)) return 'unpaid';
  return preg_match('/שולם|שילמה|שולמה|שלם/u', $s) ? 'paid' : '';
}
/** ברירת מחדל לאמצעי תשלום לפי הטקסט שבקובץ. */
function ci_method_guess(string $s): string {
  if (preg_match('/אשראי|כרטיס|נדרים/u', $s)) return 'hok_card';
  if (preg_match('/מזומן/u', $s)) return 'cash';
  if (preg_match('/צ.?ק/u', $s)) return 'check';
  if (preg_match('/העברה|בנק/u', $s)) return 'transfer';
  if (preg_match('/הוראת קבע|הו.ק/u', $s)) return 'hok_bank';
  return 'other';
}
/** תלמידות פעילות עם החיוב שלהן בגבייה $label (אם יש). */
function ci_index(string $label): array {
  $q = db()->prepare("SELECT s.id sid, s.name, s.grade, s.family_id fid, f.name fname, c.id cid, c.amount, c.disc_amt, c.mark,
      COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0) paid
    FROM students s JOIN families f ON f.id = s.family_id LEFT JOIN charges c ON c.family_id = f.id AND c.label = ? AND c.voided_at IS NULL
    WHERE s.archived_at IS NULL AND s.status = 'active'");
  $q->execute([$label]); $all = $q->fetchAll(); $cnt = [];
  foreach ($all as $r) $cnt[(int)$r['fid']] = ($cnt[(int)$r['fid']] ?? 0) + 1;
  foreach ($all as &$r) { $r['T'] = inc_tk((string)$r['name']); $r['n_fam'] = $cnt[(int)$r['fid']]; } unset($r);
  return $all;
}
function ci_tok_eq(string $a, string $b): bool { return $a === $b || inc_near($a, $b); }
/** מחזירה [דרגה, רשימת מועמדות]. דרגה 3 = שם זהה (בכל סדר), 2 = שם אחד מוכל בשני, 0 = לא זוהתה. */
function ci_match(string $name, array $idx): array {
  $T = inc_tk($name); if (!$T) return [0, []];
  $best = 0; $L = [];
  foreach ($idx as $s) {
    $S = $s['T']; if (!$S) continue;
    $mS = count(array_filter($S, fn($x) => array_filter($T, fn($t) => ci_tok_eq($t, $x))));
    $mT = count(array_filter($T, fn($t) => array_filter($S, fn($x) => ci_tok_eq($t, $x))));
    $tier = 0;
    if (count($S) === count($T) && $mS === count($S) && $mT === count($T)) $tier = 3;
    elseif (($mS === count($S) && count($S) >= 2) || ($mT === count($T) && count($T) >= 2)) $tier = 2;
    if ($tier > $best) { $best = $tier; $L = [$s]; } elseif ($tier && $tier === $best) $L[] = $s;
  }
  return [$best, $L];
}
/** שורה מסכמת / אנונימית שאי אפשר לשייך לתלמידה (למשל "65 בנות אנונימי"). */
function ci_is_summary(string $name): bool { return (bool)preg_match('/אנונימי|^\d+\s|סה.?כ|בנות\s*$/u', $name); }
/** סכום ברירת מחדל לתלמידה: חלקה בחיוב של התיק (חיוב קבוע לכל תלמידה), לא יותר מהיתרה. */
function ci_default_amount(array $s, float $alreadyThisRun = 0.0): float {
  $net = (float)$s['amount'] - (float)$s['disc_amt']; $left = round($net - (float)$s['paid'] - $alreadyThisRun, 2);
  return max(0.0, min($left, round($net / max(1, (int)$s['n_fam']), 2)));
}
