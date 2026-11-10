<?php
declare(strict_types=1);
// העלאת דוח הכנסות מתוכנת הגבייה (xlsx / csv): קריאה, זיהוי תיק לפי ניקוד, והכנה לקליטה. הכתיבה למסד ב-income_import.php.
const INCOME_MAX_BYTES = 5 * 1024 * 1024;
const INCOME_MAX_ROWS = 3000;

/** קורא את הגיליון הראשון של קובץ xlsx למערך שורות (בלי ספריות חיצוניות). */
function xlsx_rows(string $path): array {
  if (!class_exists('ZipArchive')) throw new RuntimeException('שרת האחסון לא תומך בקריאת xlsx. אפשר לשמור את הקובץ כ-CSV ולהעלות אותו.');
  $z = new ZipArchive();
  if ($z->open($path) !== true) throw new RuntimeException('קובץ האקסל לא תקין.');
  $tot = 0; for ($i = 0; $i < $z->numFiles; $i++) $tot += (int)($z->statIndex($i)['size'] ?? 0);
  if ($tot > 60 * 1024 * 1024) { $z->close(); throw new RuntimeException('קובץ האקסל גדול מדי.'); }
  $ss = [];
  if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
    $sx = @simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    if ($sx) foreach ($sx->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $ss[] = $t; }
  }
  $sheet = 'xl/worksheets/sheet1.xml';
  if ($z->locateName($sheet) === false) { for ($i = 0; $i < $z->numFiles; $i++) { $n = (string)$z->getNameIndex($i); if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $n)) { $sheet = $n; break; } } }
  $x = $z->getFromName($sheet); $z->close();
  if ($x === false) throw new RuntimeException('לא נמצא גיליון בקובץ.');
  $sx = @simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
  if (!$sx) throw new RuntimeException('קובץ האקסל לא ניתן לקריאה.');
  $rows = [];
  foreach ($sx->sheetData->row as $row) {
    $r = [];
    foreach ($row->c as $c) {
      $ref = (string)$c['r']; $col = 0; foreach (str_split(preg_replace('/\d+/', '', $ref) ?? '') as $ch) $col = $col * 26 + (ord($ch) - 64);
      $col--; $t = (string)$c['t']; $v = (string)$c->v;
      if ($t === 's') $v = $ss[(int)$v] ?? ''; elseif ($t === 'inlineStr') $v = (string)$c->is->t;
      while (count($r) < $col) $r[] = '';
      $r[$col] = $v;
    }
    $rows[] = $r;
    if (count($rows) > INCOME_MAX_ROWS + 20) break;
  }
  return $rows;
}
function xl_date(string $v): string {
  $v = trim($v);
  if ($v !== '' && is_numeric($v) && (float)$v > 30000 && (float)$v < 80000) return gmdate('Y-m-d', (int)(((float)$v - 25569) * 86400));
  if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})~', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
  if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $v, $m)) return "$m[1]-$m[2]-$m[3]";
  return '';
}
function school_month_of(string $iso): int { return $iso === '' ? 0 : (((int)substr($iso, 5, 2) - 9 + 12) % 12) + 1; }

/** קריאת הדוח: מוצא שורת כותרות (תורם + סכום) וממפה עמודות. */
function income_parse(string $path, string $name): array {
  $out = ['fatal' => null, 'rows' => []];
  try {
    if (preg_match('/\.xlsx$/i', $name)) $rows = xlsx_rows($path);
    else {
      $raw = import_decode((string)file_get_contents($path)); $first = explode("\n", $raw, 2)[0] ?? ''; $delim = ',';
      foreach ([';', "\t"] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;
      $fh = fopen('php://memory', 'r+'); fwrite($fh, $raw); rewind($fh); $rows = [];
      while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) { $rows[] = $r; if (count($rows) > INCOME_MAX_ROWS + 20) break; }
    }
  } catch (Throwable $t) { return array_merge($out, ['fatal' => $t->getMessage()]); }
  $hi = -1;
  foreach ($rows as $i => $r) { $n = array_map(fn($x) => import_norm_header((string)$x), $r); if (in_array('תורם', $n, true) && in_array('סכום', $n, true)) { $hi = $i; break; } }
  if ($hi < 0) return array_merge($out, ['fatal' => 'לא נמצאה שורת כותרות עם "תורם" ו"סכום". זה דוח הכנסות מתוכנת הגבייה?']);
  $H = array_map(fn($x) => import_norm_header((string)$x), $rows[$hi]);
  $ix = fn(string $n) => ($k = array_search(import_norm_header($n), $H, true)) === false ? -1 : (int)$k;
  $C = ['date' => $ix('תאריך'), 'src' => $ix('מקור'), 'donor' => $ix('תורם'), 'idn' => $ix('תז'), 'phone' => $ix('טלפון'), 'email' => $ix('מייל'), 'cat' => $ix('קטגוריה'), 'note' => $ix('הערות'), 'type' => $ix('סוג'), 'amt' => $ix('סכום'), 'rc' => $ix('מספר קבלה'), 'tx' => $ix('מספר עסקה')];
  foreach (array_slice($rows, $hi + 1) as $r) {
    $g = fn(string $k) => $C[$k] >= 0 ? trim((string)($r[$C[$k]] ?? '')) : '';
    $a = (float)preg_replace('/[^\d.]/', '', $g('amt'));
    if ($a <= 0 || $g('donor') === '') continue;
    $row = ['date' => xl_date($g('date')), 'donor' => mb_substr($g('donor'), 0, 120), 'idn' => preg_replace('/\D/', '', $g('idn')) ?? '', 'phone' => $g('phone'), 'email' => strtolower($g('email')),
      'cat' => mb_substr($g('cat'), 0, 100), 'note' => mb_substr($g('note'), 0, 200), 'type' => $g('type'), 'src' => $g('src'), 'amt' => round($a, 2), 'rc' => $g('rc'), 'tx' => $g('tx')];
    $row['key'] = $row['rc'] !== '' ? 'r' . $row['rc'] : ($row['tx'] !== '' ? 't' . $row['tx'] : 'h' . substr(md5($row['date'] . '|' . $row['amt'] . '|' . $row['donor']), 0, 12));
    $row['method'] = income_method($row['src'], $row['type']);
    $row['par'] = $row['cat'] === '' || (bool)preg_match('/הורים|שכ/u', $row['cat']);
    $row['don'] = (bool)preg_match('/תרומה/u', $row['cat']);
    $out['rows'][] = $row;
    if (count($out['rows']) >= INCOME_MAX_ROWS) break;
  }
  return $out;
}
function income_method(string $src, string $type): string {
  $s = str_replace(['״', '"', '׳', "'"], '', $src); $t = str_replace(['״', '"', '׳', "'"], '', $type);
  if ($s === 'אשראי') return 'hok_card'; if ($s === 'בנקאי') return 'hok_bank';
  if (str_contains($t, 'צק') || str_contains($t, 'צ ק') || str_contains($t, 'צ׳ק')) return 'check'; if (str_contains($t, 'מזומן')) return 'cash';
  return 'other';
}

function inc_nz(string $t): string { $t = str_replace(['"', "'", '׳', '״', '`'], '', $t); return trim(preg_replace('/\s+/u', ' ', preg_replace('/[.,\-()\/]/u', ' ', $t) ?? '') ?? ''); }
function inc_tk(string $t): array { return array_values(array_filter(explode(' ', inc_nz($t)), fn($x) => $x !== '')); }
function inc_lev1(string $a, string $b): int {
  if ($a === $b) return 0; if (abs(mb_strlen($a) - mb_strlen($b)) > 1) return 9;
  $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY); $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY); $i = 0;
  while ($i < count($x) && $i < count($y) && $x[$i] === $y[$i]) $i++;
  if (count($x) === count($y)) return array_slice($x, $i + 1) === array_slice($y, $i + 1) ? 1 : 2;
  return count($x) > count($y) ? (array_slice($x, $i + 1) === array_slice($y, $i) ? 1 : 2) : (array_slice($y, $i + 1) === array_slice($x, $i) ? 1 : 2);
}
function inc_near(string $a, string $b): bool { $la = mb_strlen($a); $lb = mb_strlen($b); return $la > 2 && $lb > 2 && inc_lev1($a, $b) <= ($la > 4 ? 1 : 0); }
function inc_d9(string $x): string { $d = preg_replace('/\D/', '', $x) ?? ''; return strlen($d) >= 9 ? substr($d, -9) : ''; }

/** אינדקס תלמידות לזיהוי. */
function income_index(): array {
  $o = [];
  foreach (db()->query("SELECT id, family_id, name, idn, f_id, m_id, phone, f_phone, m_phone, home_phone, email, f_name, m_name FROM students WHERE archived_at IS NULL AND family_id IS NOT NULL")->fetchAll() as $s) {
    $ids = array_values(array_filter(array_map(fn($x) => ($d = preg_replace('/\D/', '', (string)$x)) !== '' ? str_pad($d, 9, '0', STR_PAD_LEFT) : '', [$s['idn'], $s['f_id'], $s['m_id']])));
    $ph = array_values(array_filter(array_map('inc_d9', [$s['phone'], $s['f_phone'], $s['m_phone'], $s['home_phone']])));
    $N = inc_tk((string)$s['name']);
    $o[] = ['fid' => (int)$s['family_id'], 'sid' => (int)$s['id'], 'fam' => $N[0] ?? '', 'given' => array_slice($N, 1), 'ids' => $ids, 'ph' => $ph, 'email' => strtolower(trim((string)$s['email'])),
      'par' => array_merge(array_slice(inc_tk((string)$s['f_name']), 0, 1), array_slice(inc_tk((string)$s['m_name']), 0, 1))];
  }
  return $o;
}
/** מחזיר רשימת מזהי תיקים בעלי הניקוד הגבוה ביותר (ריק = לא זוהה). */
function income_match(array $r, array $idx): array {
  $T = inc_tk($r['donor'] . ' ' . $r['note']); $D = inc_tk($r['donor']); $best = 0; $L = [];
  $rid = $r['idn'] !== '' ? str_pad($r['idn'], 9, '0', STR_PAD_LEFT) : ''; $rph = inc_d9($r['phone']);
  foreach ($idx as $s) {
    $st = 0; $sc = 0;
    if ($rid !== '' && in_array($rid, $s['ids'], true)) $st += 6;
    if ($rph !== '' && in_array($rph, $s['ph'], true)) $st += 4;
    if ($r['email'] !== '' && $s['email'] !== '' && $r['email'] === $s['email']) $st += 3;
    $fh = $s['fam'] !== '' && array_filter($T, fn($t) => inc_near($t, $s['fam']));
    if ($fh) $sc += 2;
    if ($s['given'] && array_filter($s['given'], fn($f) => array_filter($T, fn($t) => inc_near($t, $f)))) $sc += 2;
    if ($s['par'] && array_filter($s['par'], fn($p) => array_filter($D, fn($t) => inc_near($t, $p)))) $sc += 1;
    $v = ($st + $sc >= 3 && ($st || $fh)) ? $st + $sc : 0;
    if ($v > $best) { $best = $v; $L = [$s['fid']]; } elseif ($v && $v === $best && !in_array($s['fid'], $L, true)) $L[] = $s['fid'];
  }
  return $L;
}

function inc_cat(string $c): string { $c = trim($c); return preg_match('/^ספרי לימוד/u', $c) ? 'ספרי לימוד' : $c; }
/**
 * קולטת שורת דוח הכנסות לתיק אחד או יותר (חלוקה שווה). kind: t = שכר לימוד, c = חיוב חד־פעמי.
 * $st מצטבר: n, dup, sum, newCh. $medAmt = סכום מקובל לחיוב חד־פעמי חדש (חציון הקטגוריה בדוח).
 */
function income_place(PDO $pdo, array $r, array $ids, string $kind, int $mo, string $batch, int $uid, float $medAmt, array &$st): void {
  $each = round($r['amt'] / count($ids), 2); $rest = round($r['amt'] - $each * count($ids), 2);
  $ins = $pdo->prepare('INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, batch, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
  $have = $pdo->prepare("SELECT 1 FROM payments WHERE ref = ? AND family_id = ? AND batch LIKE 'inc%' AND voided_at IS NULL LIMIT 1");
  foreach ($ids as $k => $fid) {
    $have->execute([$r['key'], $fid]); if ($have->fetchColumn()) { $st['dup']++; continue; }
    $amt = $each + ($k === 0 ? $rest : 0); $cid = null;
    if ($kind === 'c') {
      $label = inc_cat($r['cat']) !== '' ? inc_cat($r['cat']) : 'חיוב';
      $q = $pdo->prepare('SELECT id, label FROM charges WHERE family_id = ? AND voided_at IS NULL'); $q->execute([$fid]);
      foreach ($q->fetchAll() as $c) if (inc_nz($c['label']) === inc_nz($label)) { $cid = (int)$c['id']; break; }
      if (!$cid) { $pdo->prepare('INSERT INTO charges (family_id, kind, label, amount, created_by, created_at) VALUES (?,?,?,?,?,?)')->execute([$fid, 'other', mb_substr($label, 0, 100), max($medAmt, $amt) ?: $amt, $uid, now()]); $cid = (int)$pdo->lastInsertId(); $st['newCh']++; }
    }
    $ins->execute([$fid, $cid, $kind === 't' && $mo ? $mo : null, $amt, $r['date'] !== '' ? $r['date'] : date('Y-m-d'), $r['method'], mb_substr($r['key'], 0, 60), mb_substr('דוח הכנסות: ' . $r['donor'] . ($r['note'] !== '' ? ' · ' . $r['note'] : ''), 0, 255), $batch, $uid, now()]);
    $st['n']++; $st['sum'] += $amt;
  }
}
/** שומרת שורה שלא שויכה, כדי שלא יהיה צורך לקלוט את הדוח שוב. מפתח הדוח ייחודי, כך שהעלאה חוזרת לא מכפילה. */
function income_park(PDO $pdo, array $r, string $reportName, int $uid): bool {
  $h = $pdo->prepare("SELECT 1 FROM payments WHERE ref = ? AND batch LIKE 'inc%' AND voided_at IS NULL LIMIT 1"); $h->execute([mb_substr($r['key'], 0, 60)]); if ($h->fetchColumn()) return false;   // כבר נקלטה (לכל תיק)
  $q = $pdo->prepare('INSERT IGNORE INTO income_pending (rkey, paid_on, donor, amount, cat, note, data, report_name, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
  $q->execute([mb_substr($r['key'], 0, 60), $r['date'] !== '' ? $r['date'] : null, $r['donor'], $r['amt'], $r['cat'], $r['note'], json_encode($r, JSON_UNESCAPED_UNICODE), mb_substr($reportName, 0, 120), $uid, now()]);
  return $q->rowCount() > 0;
}
