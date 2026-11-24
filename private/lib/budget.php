<?php
declare(strict_types=1);
// תקציב פנימי של בית הספר – לוגיקה משותפת (הדפים: budget.php, budget_tx.php, budget_cats.php). מנהלת מערכת בלבד.

const BUDGET_UNITS_DEFAULT = ['עלמא', 'חטיבת בנים', 'מדרשת עילא'];
const BUDGET_MAX_IMPORT = 3000;
/** שמות כלליים בדוח הבנק: משמשים להוצאות שונות, לכן אין ללמוד מהם כלל אוטומטי או לשייך לפי היסטוריה (העברות באינטרנט מפורטות ברשימת ההעברות; העברות זהב משויכות ידנית). */
const BUDGET_GENERIC = ['העברה באינטרנט', 'העברה בזה"ב', 'דביט כא"ל', 'הפקדת שיק בסלולר', 'הפקדת שיק בכספת'];
const BUDGET_IB = 'העברה באינטרנט';
const BUDGET_DEBIT = 'דביט כא"ל';

function budget_units(): array {
  $v = array_values(array_unique(array_filter(array_map('trim', explode("\n", setting_get('budget_units', ''))), fn($x) => $x !== '')));
  return $v ?: BUDGET_UNITS_DEFAULT;
}
function budget_cats(): array { return array_column(db()->query('SELECT name FROM budget_cats ORDER BY name')->fetchAll(), 'name'); }

/** שנת לימודים לפי שנת ההתחלה הלועזית (1.9.S עד 31.8.S+1) – שם עברי, למשל 2026 => תשפ"ז. */
function budget_year_label(int $s): string {
  $h = ($s + 3761) % 1000; $L = [400 => 'ת', 300 => 'ש', 200 => 'ר', 100 => 'ק', 90 => 'צ', 80 => 'פ', 70 => 'ע', 60 => 'ס', 50 => 'נ', 40 => 'מ', 30 => 'ל', 20 => 'כ', 10 => 'י', 9 => 'ט', 8 => 'ח', 7 => 'ז', 6 => 'ו', 5 => 'ה', 4 => 'ד', 3 => 'ג', 2 => 'ב', 1 => 'א'];
  $out = '';
  foreach ([400, 300, 200, 100] as $v) while ($h >= $v) { $out .= $L[$v]; $h -= $v; }
  if ($h === 15) { $out .= 'טו'; $h = 0; } elseif ($h === 16) { $out .= 'טז'; $h = 0; }
  foreach ([90, 80, 70, 60, 50, 40, 30, 20, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1] as $v) if ($h >= $v) { $out .= $L[$v]; $h -= $v; }
  $n = mb_strlen($out); return $n > 1 ? mb_substr($out, 0, $n - 1) . '"' . mb_substr($out, -1) : $out;
}
function budget_year_of(string $iso): int { $y = (int)substr($iso, 0, 4); return (int)substr($iso, 5, 2) >= 9 ? $y : $y - 1; }
function budget_year_current(): int { return budget_year_of(date('Y-m-d')); }
/** רשימת שנות לימודים להצגה: כל שנה שיש בה תנועות, והשנה הנוכחית. */
function budget_years(): array {
  $ys = [budget_year_current()];
  foreach (db()->query('SELECT DISTINCT tx_date FROM budget_tx')->fetchAll() as $r) $ys[] = budget_year_of((string)$r['tx_date']);
  $ys = array_values(array_unique($ys)); rsort($ys); return $ys;
}
/** טווח תאריכים לשנה (S), או null לכל התקופה. */
function budget_range(?int $s): ?array { return $s === null ? null : [sprintf('%04d-09-01', $s), sprintf('%04d-08-31', $s + 1)]; }

function budget_money($n): string { return '₪' . number_format((float)$n, 2, '.', ',') . ''; }
function budget_money0($n): string { $f = (float)$n; return '₪' . number_format($f, abs($f - round($f)) < 0.005 ? 0 : 2, '.', ','); }

/** שיוך אוטומטי לקטגוריה לפי מילות מפתח (הראשון שמתאים). */
function budget_auto(string $name, string $descr, ?array &$rules = null): string {
  if ($rules === null) $rules = db()->query('SELECT kw, category FROM budget_rules ORDER BY ord, id')->fetchAll();
  $t = $name . ' ' . $descr;
  foreach ($rules as $r) if ($r['kw'] !== '' && mb_strpos($t, $r['kw']) !== false) return (string)$r['category'];
  return '';
}
function budget_amount(string $v): ?float {
  $v = trim(str_replace(['₪', ',', ' ', "\u{A0}"], '', $v));
  if ($v === '') return null;
  $neg = false; if (preg_match('/^\((.*)\)$/', $v, $m)) { $neg = true; $v = $m[1]; }
  if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) return null;
  $f = (float)$v; if ($neg) $f = -$f;
  return abs($f) > 9999999999 ? null : round($f, 2);
}
function budget_date(string $v): string {
  $v = trim($v); if ($v === '') return '';
  if (is_numeric($v) && (float)$v > 30000 && (float)$v < 80000) return gmdate('Y-m-d', (int)(((float)$v - 25569) * 86400));
  if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})~', $v, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
  elseif (preg_match('~^(\d{1,2})[/.\- ](\d{1,2})[/.\- ](\d{4}|\d{2})~', $v, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; if ($y < 100) $y += 2000; }
  else return '';
  return checkdate($mo, $d, $y) && $y >= 2000 && $y <= 2100 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
}
function budget_unit_for(string $unit, string $cat): string {
  $units = budget_units(); if ($unit !== '' && in_array($unit, $units, true)) return $unit;
  if ($cat !== '' && in_array($cat, $units, true)) return $cat;
  return $units[0];
}
function budget_cat_ensure(string $c): void { if ($c !== '') db()->prepare('INSERT IGNORE INTO budget_cats (name) VALUES (?)')->execute([mb_substr($c, 0, 100)]); }

/** קריאת טבלת HTML (קובץ ".xls" שהבנק מייצא בפועל כ-HTML, או htm). מחזיר שורות של תאים. */
function budget_html_rows(string $path): array {
  $raw = (string)file_get_contents($path, false, null, 0, 6 * 1024 * 1024);
  if (str_starts_with($raw, "\xD0\xCF\x11\xE0")) throw new RuntimeException('זה קובץ Excel ישן (xls בינארי). יש לפתוח אותו ב-Excel ולשמור בשם חדש כ-xlsx או CSV.');
  if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
  if (!mb_check_encoding($raw, 'UTF-8')) $raw = (string)@iconv('windows-1255', 'UTF-8//IGNORE', $raw);
  if (stripos($raw, '<table') === false || (stripos($raw, 'c_rgszSh') !== false && stripos($raw, 'frameset') !== false && substr_count(strtolower($raw), '<tr') < 6))
    throw new RuntimeException('הקובץ הזה הוא רק "עטיפה" של Excel בלי הנתונים עצמם (הבנק שומר את הנתונים בתיקייה נפרדת לצדו). יש לפתוח אותו ב-Excel במחשב ולשמור בשם חדש כ-xlsx או CSV, או להעלות את קובץ ה-sheet001.htm שבתיקייה "...files" שליד הקובץ.');
  $dom = new DOMDocument(); $prev = libxml_use_internal_errors(true);
  $dom->loadHTML('<?xml encoding="UTF-8">' . $raw); libxml_clear_errors(); libxml_use_internal_errors($prev);
  $rows = [];
  foreach ($dom->getElementsByTagName('tr') as $tr) {
    $r = []; foreach ($tr->childNodes as $td) if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) $r[] = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $td->textContent)));
    if (array_filter($r, fn($v) => $v !== '')) $rows[] = $r;
    if (count($rows) > BUDGET_MAX_IMPORT + 40) break;
  }
  return $rows;
}

/** ייבוא מ-xlsx/csv/xls-html. עמודות: תאריך, שם, תיאור, אסמכתה, סכום, קטגוריה, יחידה, סוג (הכנסה/הוצאה), חשבונית, קבלה. מדלג על שורות זהות לקיימות. */
function budget_import(string $path, string $name, string $by): array {
  if (preg_match('/\.xlsx$/i', $name)) $rows = xlsx_rows($path);
  elseif (preg_match('/\.(xls|htm|html)$/i', $name)) $rows = budget_html_rows($path);
  else {
    $fh = fopen($path, 'rb'); if (!$fh) throw new RuntimeException('לא ניתן לקרוא את הקובץ.');
    $first = (string)fgets($fh); rewind($fh); $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $bom = fread($fh, 3); if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    $rows = []; while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) { $rows[] = $r; if (count($rows) > BUDGET_MAX_IMPORT + 20) break; } fclose($fh);
  }
  $hi = -1; $col = [];
  $alias = ['תאריך' => 'd', 'שם' => 'n', 'ספק' => 'n', 'תיאור' => 'e', 'פירוט' => 'e', 'אסמכתה' => 'r', 'אסמכתא' => 'r', 'סכום' => 'a', 'חובה' => 'db', 'בחובה' => 'db', 'זכות' => 'cr', 'בזכות' => 'cr', 'אסמכתא' => 'r', 'פעולה' => 'e', 'הפעולה' => 'e', 'תיאור הפעולה' => 'e', 'פרטים' => 'e', 'סוג תנועה' => 'e', 'קטגוריה' => 'c', 'יחידה' => 'u', 'סוג' => 't', 'חשבונית' => 'inv', 'קבלה' => 'rec'];
  foreach ($rows as $i => $r) { $m = []; foreach ($r as $j => $v) { $k = $alias[trim((string)$v)] ?? null; if ($k && !isset($m[$k])) $m[$k] = $j; } if (isset($m['d']) && (isset($m['a']) || isset($m['db']) || isset($m['cr']))) { $hi = $i; $col = $m; break; } if ($i > 15) break; }
  if ($hi < 0) throw new RuntimeException('לא נמצאה שורת כותרות. נדרשות לפחות העמודות "תאריך" ו"סכום".');
  $rows = array_slice($rows, $hi + 1);
  if (count($rows) > BUDGET_MAX_IMPORT) throw new RuntimeException('יותר מדי שורות בקובץ (מקסימום ' . BUDGET_MAX_IMPORT . ').');
  $g = fn(array $r, string $k) => isset($col[$k]) ? trim((string)($r[$col[$k]] ?? '')) : '';
  $rules = null; $units = budget_units(); $pdo = db();
  $ex = $pdo->prepare('SELECT COUNT(*) FROM budget_tx WHERE tx_date=? AND name=? AND descr=? AND ref=? AND amount=? AND is_income=?'); $inDb = []; $inFile = []; $exIb = $pdo->prepare('SELECT COUNT(*) FROM budget_tx WHERE tx_date=? AND ref=? AND amount=? AND is_income=0');
  $ins = $pdo->prepare('INSERT INTO budget_tx (tx_date,name,descr,ref,amount,is_income,category,unit,has_invoice,has_receipt,created_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
  $added = 0; $dup = 0; $bad = 0; $pdo->beginTransaction();
  try {
    foreach ($rows as $r) {
      if (!array_filter($r, fn($v) => trim((string)$v) !== '')) continue;
      $d = budget_date($g($r, 'd')); $inc = null;
      if (isset($col['db']) || isset($col['cr'])) {   // דוח תנועות בנק: חובה = הוצאה, זכות = הכנסה
        $cr = budget_amount($g($r, 'cr')); $db = budget_amount($g($r, 'db'));
        if ($cr !== null && $cr != 0) { $a = abs($cr); $inc = 1; } elseif ($db !== null && $db != 0) { $a = abs($db); $inc = 0; } else $a = null;
      } else $a = budget_amount($g($r, 'a'));
      if ($d === '' || $a === null) { $bad++; continue; }
      $n = mb_substr($g($r, 'n'), 0, 150); $e = mb_substr($g($r, 'e'), 0, 300); $ref = mb_substr($g($r, 'r'), 0, 100);
      if ($n === '') { $n = mb_substr($e, 0, 150); $e = ''; }
      $n = trim((string)preg_replace('/\s*\(\x{05D9}\)\s*$/u', '', $n)) ?: $n;   // סימון "(י)" בסוף תיאור תנועה בנקאית
      $c = mb_substr($g($r, 'c'), 0, 100); if ($c === '') $c = budget_auto($n, $e, $rules);
      if ($inc === null) $inc = $g($r, 't') === 'הכנסה' ? 1 : 0; $u = budget_unit_for($g($r, 'u'), $c);
      $kk = implode("\x1f", [$d, $n, $e, $ref, $a, $inc]);   // שורות זהות בקובץ הן תנועות נפרדות: מדלגים רק על מספר השורות שכבר קיימות במסד
      if (!isset($inDb[$kk])) {
        if ($n === BUDGET_IB && !$inc && $ref !== '') { $exIb->execute([$d, $ref, $a]); $inDb[$kk] = (int)$exIb->fetchColumn(); }   // העברה שכבר יובאה בפירוט מרשימת העברות
        elseif (!$inc && str_starts_with($n, BUDGET_DEBIT) && budget_has_card()) { $ex->execute([$d, $n, $e, $ref, $a, $inc]); $tb = $pdo->prepare('SELECT COUNT(*) FROM budget_tomb WHERE tx_date=? AND ref=? AND amount=?'); $tb->execute([$d, $ref, $a]); $inDb[$kk] = (int)$ex->fetchColumn() + (int)$tb->fetchColumn(); }   // דביט שכבר פורט לרכישות
        else { $ex->execute([$d, $n, $e, $ref, $a, $inc]); $inDb[$kk] = (int)$ex->fetchColumn(); }
      }
      $inFile[$kk] = ($inFile[$kk] ?? 0) + 1; if ($inFile[$kk] <= $inDb[$kk]) { $dup++; continue; }
      if (!$inc && str_starts_with($n, BUDGET_DEBIT) && budget_card_reconcile($d, $ref, (float)$a)) { $dup++; continue; }   // רכישות האשראי כבר יובאו: הדביט מוחלף בהן
      budget_cat_ensure($c);
      $ins->execute([$d, $n, $e, $ref, $a, $inc, $c, $u, $g($r, 'inv') === 'כן' ? 1 : 0, $g($r, 'rec') === 'כן' ? 1 : 0, now(), mb_substr($by, 0, 100)]); $added++;
    }
    $pdo->commit();
  } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
  return [$added, $dup, $bad];
}


/** שיוך לפי היסטוריה: אותו שם ספק (ואם אין – אותו תיאור) ששויך בעבר, הקטגוריה האחרונה שנבחרה. */
function budget_history_cat(string $name, string $descr): string {
  static $q = null, $memo = []; $k = $name . "\x1f" . $descr; if (isset($memo[$k])) return $memo[$k];
  if ($q === null) { $q = [db()->prepare("SELECT category, COUNT(*) c FROM budget_tx WHERE name = ? AND category <> '' GROUP BY category ORDER BY c DESC, MAX(id) DESC"), db()->prepare("SELECT category, COUNT(*) c FROM budget_tx WHERE descr = ? AND descr <> '' AND category <> '' GROUP BY category ORDER BY c DESC, MAX(id) DESC")]; }
  $dom = function (PDOStatement $st, string $v): string {   // קטגוריה רק אם היא מכריעה (לפחות 80% מהתנועות הקודמות), כדי לא לנחש בשמות כלליים
    $st->execute([$v]); $r = $st->fetchAll(); if (!$r) return ''; $tot = array_sum(array_column($r, 'c')); return $r[0]['c'] / $tot >= 0.8 ? (string)$r[0]['category'] : '';
  };
  if (in_array($name, BUDGET_GENERIC, true)) return $memo[$k] = '';
  $c = ''; if ($name !== '') $c = $dom($q[0], $name);
  if ($c === '' && $descr !== '') $c = $dom($q[1], $descr);
  return $memo[$k] = $c;
}

/** האם עדכון מסד הנתונים של פירוט האשראי (עמודת src וטבלת budget_tomb) בוצע. */
function budget_has_card(): bool {
  static $ok = null;
  if ($ok === null) { try { $ok = (bool)db()->query("SHOW COLUMNS FROM budget_tx LIKE 'src'")->fetch() && (bool)db()->query("SHOW TABLES LIKE 'budget_tomb'")->fetch(); } catch (Throwable $t) { $ok = false; } }
  return $ok;
}
/** תת-קבוצה של סכומים (באגורות) ששווה ליעד. מעדיפה פריטים מוקדמים. מחזירה אינדקסים או null. */
function budget_subset(array $cents, int $target): ?array {
  $n = count($cents); if ($n === 0 || $n > 40) return null;
  $allPos = min($cents) >= 0; $nodes = 0; $res = null;
  $dfs = function (int $i, int $sum, array $pick) use (&$dfs, $cents, $n, $target, $allPos, &$nodes, &$res) {
    if ($res !== null || ++$nodes > 400000) return;
    if ($pick && $sum === $target) { $res = $pick; return; }
    if ($i >= $n || ($allPos && $sum > $target)) return;
    $dfs($i + 1, $sum + $cents[$i], array_merge($pick, [$i]));
    $dfs($i + 1, $sum, $pick);
  };
  $dfs(0, 0, []); return $res;
}
/** זיהוי דוח "פירוט עסקאות" של חברת האשראי (כאל) מתוך שורות xlsx. מחזיר null אם זה לא הדוח הזה. */
function budget_card_rows(array $rows): ?array {
  $last4 = ''; $hi = -1; $col = [];
  foreach ($rows as $i => $r) {
    if ($i > 12) break;
    foreach ($r as $v) if ($last4 === '' && preg_match('/המסתיים\s*ב-?\s*(\d{4})/u', (string)$v, $m)) $last4 = $m[1];
    $m2 = []; foreach ($r as $j => $v) { $t = preg_replace('/\s+/u', '', (string)$v); $k = ['תאריךעסקה' => 'd', 'שםביתעסק' => 'n', 'סכוםחיוב' => 'a', 'ענף' => 's', 'סוגעסקה' => 'k'][$t] ?? null; if ($k && !isset($m2[$k])) $m2[$k] = $j; }
    if (isset($m2['d'], $m2['n'], $m2['a'])) { $hi = $i; $col = $m2; break; }
  }
  if ($hi < 0 || $last4 === '') return null;
  $tx = [];
  foreach (array_slice($rows, $hi + 1) as $r) {
    $d = budget_date(trim((string)($r[$col['d']] ?? ''))); $a = budget_amount(trim((string)($r[$col['a']] ?? ''))); $n = mb_substr(trim((string)($r[$col['n']] ?? '')), 0, 150);
    if ($d === '' || $a === null || $n === '' || $a == 0) continue;
    $tx[] = ['d' => $d, 'n' => $n, 'a' => $a, 's' => isset($col['s']) ? trim((string)($r[$col['s']] ?? '')) : '', 'k' => isset($col['k']) ? trim((string)($r[$col['k']] ?? '')) : ''];
    if (count($tx) > BUDGET_MAX_IMPORT) break;
  }
  return $tx ? ['last4' => $last4, 'tx' => $tx] : null;
}
/** תצוגה מקדימה לדוח אשראי: כל רכישה כשורה, והתאמה של קבוצות רכישות לשורות "דביט כא"ל" מדוח הבנק (לפי סכום כולל מדויק). */
function budget_card_prepare(array $card): array {
  $pdo = db(); $rules = null; $ref = 'כרטיס ' . $card['last4']; $items = []; $inDb = []; $seen = [];
  $ex = $pdo->prepare('SELECT COUNT(*) FROM budget_tx WHERE tx_date=? AND name=? AND descr=? AND ref=? AND amount=? AND is_income=?');
  foreach ($card['tx'] as $t) {
    $inc = $t['a'] < 0 ? 1 : 0; $a = abs((float)$t['a']); $e = mb_substr(trim($t['s'] . ($t['k'] === 'הוראת קבע' ? ' · הוראת קבע' : '')), 0, 300);
    $key = implode("\x1f", [$t['d'], $t['n'], $e, $a, $inc]);
    if (!isset($inDb[$key])) { $ex->execute([$t['d'], $t['n'], $e, $ref, $a, $inc]); $inDb[$key] = (int)$ex->fetchColumn(); }
    $seen[$key] = ($seen[$key] ?? 0) + 1;
    $cat = budget_auto($t['n'], $e, $rules); if ($cat === '') $cat = budget_history_cat($t['n'], $e);
    $items[] = ['d' => $t['d'], 'n' => $t['n'], 'e' => $e, 'r' => $ref, 'a' => $a, 'inc' => $inc, 'cat' => $cat, 'dup' => $seen[$key] <= $inDb[$key], 'upd' => 0, 'src' => 'card', 'grp' => 0, 'gl' => ''];
  }
  $q = $pdo->prepare("SELECT id, tx_date, amount FROM budget_tx WHERE is_income = 0 AND src = '' AND name LIKE ? AND ref LIKE ? ORDER BY tx_date, id");
  $q->execute([BUDGET_DEBIT . '%', '%' . $card['last4']]);
  $avail = array_keys(array_filter($items, fn($x) => !$x['dup'])); usort($avail, fn($i, $j) => strcmp($items[$i]['d'], $items[$j]['d']) ?: $i <=> $j);
  foreach ($q->fetchAll() as $deb) {
    $lo = date('Y-m-d', strtotime($deb['tx_date'] . ' -45 days'));
    $cand = array_values(array_filter($avail, fn($i) => $items[$i]['d'] <= $deb['tx_date'] && $items[$i]['d'] >= $lo));
    $pick = budget_subset(array_map(fn($i) => (int)round(($items[$i]['inc'] ? -1 : 1) * $items[$i]['a'] * 100), $cand), (int)round((float)$deb['amount'] * 100));
    if ($pick === null) continue;
    foreach ($pick as $k) { $i = $cand[$k]; $items[$i]['grp'] = (int)$deb['id']; $items[$i]['gl'] = BUDGET_DEBIT . ' ' . date('d/m/Y', strtotime($deb['tx_date'])) . ' · ' . budget_money($deb['amount']); }
    $avail = array_values(array_diff($avail, array_map(fn($k) => $cand[$k], $pick)));
  }
  return $items;
}
/** ייבוא דוח בנק, שורת דביט: האם רכישות אשראי שכבר יובאו (src=card) מסתכמות בדיוק בסכום. אם כן, מסמנות אותן כמותאמות ומחזירות true. */
function budget_card_reconcile(string $date, string $ref, float $amount): bool {
  if (!budget_has_card() || !preg_match('/(\d{4})$/', $ref, $m)) return false; $pdo = db();
  $q = $pdo->prepare("SELECT id, amount, is_income FROM budget_tx WHERE src = 'card' AND ref = ? AND tx_date <= ? AND tx_date >= ? ORDER BY tx_date, id");
  $q->execute(['כרטיס ' . $m[1], $date, date('Y-m-d', strtotime($date . ' -45 days'))]); $c = $q->fetchAll(); if (!$c) return false;
  $pick = budget_subset(array_map(fn($r) => (int)round(((int)$r['is_income'] ? -1 : 1) * (float)$r['amount'] * 100), $c), (int)round($amount * 100)); if ($pick === null) return false;
  $up = $pdo->prepare("UPDATE budget_tx SET src = 'cardok' WHERE id = ?"); foreach ($pick as $k) $up->execute([(int)$c[$k]['id']]);
  $pdo->prepare('INSERT INTO budget_tomb (tx_date, ref, amount, name, created_at) VALUES (?,?,?,?,?)')->execute([$date, $ref, $amount, BUDGET_DEBIT, now()]);
  return true;
}

/** הכנת שורות מ-PDF לתצוגה מקדימה: אימות, שיוך אוטומטי, זיהוי כפילויות, והתאמה לשורות "העברה באינטרנט" שכבר יובאו מדוח הבנק (לפי אסמכתה וסכום). בלי כתיבה למסד. */
function budget_prepare(array $recs): array {
  $rules = null; $pdo = db(); $out = [];
  $ex = $pdo->prepare('SELECT COUNT(*) FROM budget_tx WHERE tx_date=? AND name=? AND descr=? AND ref=? AND amount=? AND is_income=?'); $inDb = []; $seen = [];
  $ph = $pdo->prepare('SELECT id FROM budget_tx WHERE name = ? AND ref = ? AND amount = ? AND is_income = 0 ORDER BY id'); $usedPh = [];
  foreach (array_slice($recs, 0, BUDGET_MAX_IMPORT) as $r) {
    if (!is_array($r)) continue;
    $d = budget_date((string)($r['d'] ?? '')); $a = budget_amount((string)($r['a'] ?? ''));
    $n = mb_substr(trim((string)($r['n'] ?? '')), 0, 150); $e = mb_substr(trim((string)($r['e'] ?? '')), 0, 300); $ref = mb_substr(trim((string)($r['r'] ?? '')), 0, 100);
    $inc = ($r['t'] ?? '') === 'in' ? 1 : 0;
    if ($d === '' || $a === null || $a <= 0 || $n === '') continue;
    $key = implode("\x1f", [$d, $n, $e, $ref, $a, $inc]);
    if (!isset($inDb[$key])) { $ex->execute([$d, $n, $e, $ref, $a, $inc]); $inDb[$key] = (int)$ex->fetchColumn(); }
    $seen[$key] = ($seen[$key] ?? 0) + 1; $dup = $seen[$key] <= $inDb[$key];   // שורות זהות בקובץ הן תנועות נפרדות
    $upd = 0;
    if (!$dup && !$inc && $ref !== '' && $n !== BUDGET_IB) {   // העברה מפורטת שמחליפה שורת "העברה באינטרנט" מדוח הבנק
      $pk = $ref . "\x1f" . $a; $ph->execute([BUDGET_IB, $ref, $a]);
      foreach ($ph->fetchAll(PDO::FETCH_COLUMN) as $pid) if (!isset($usedPh[$pid])) { $usedPh[$pid] = 1; $upd = (int)$pid; break; }
    }
    $cat = budget_auto($n, $e, $rules); if ($cat === '') $cat = budget_history_cat($n, $e);
    $out[] = ['d' => $d, 'n' => $n, 'e' => $e, 'r' => $ref, 'a' => $a, 'inc' => $inc, 'cat' => $cat, 'dup' => $dup, 'upd' => $upd];
  }
  return $out;
}
/** כתיבת שורות שנבחרו (אינדקסים) מתוך budget_prepare / budget_card_prepare. מחזיר [נוספו, ללא שיוך, עודכנו (העברות), דביטים שפורטו]. */
function budget_commit(array $items, array $pick, string $by): array {
  $pdo = db(); $added = 0; $un = 0; $upd = 0; $rep = 0; $hasCard = budget_has_card();
  $ins = $pdo->prepare('INSERT INTO budget_tx (tx_date,name,descr,ref,amount,is_income,category,unit,has_invoice,has_receipt,created_at,created_by' . ($hasCard ? ',src' : '') . ') VALUES (?,?,?,?,?,?,?,?,0,0,?,?' . ($hasCard ? ',?' : '') . ')');
  $up = $pdo->prepare("UPDATE budget_tx SET name=?, descr=?, category=?, unit=?, updated_at=? WHERE id=? AND name=?");
  $set = array_flip(array_map('intval', $pick)); $skip = [];   // קבוצת רכישות שמחליפה דביט נכתבת רק אם כל פריטיה סומנו
  foreach ($items as $i => $x) if (!$x['dup'] && !empty($x['grp']) && !isset($set[$i])) $skip[$x['grp']] = 1;
  $done = [];
  $pdo->beginTransaction();
  try {
    foreach ($pick as $i) {
      $x = $items[(int)$i] ?? null; if (!$x || $x['dup'] || (!empty($x['grp']) && isset($skip[$x['grp']]))) continue;
      budget_cat_ensure($x['cat']); $u = budget_unit_for('', $x['cat']);
      if (!empty($x['upd'])) { $up->execute([$x['n'], $x['e'], $x['cat'], $u, now(), (int)$x['upd'], BUDGET_IB]); if ($up->rowCount()) { $upd++; if ($x['cat'] === '') $un++; continue; } }
      $row = [$x['d'], $x['n'], $x['e'], $x['r'], $x['a'], $x['inc'], $x['cat'], $u, now(), mb_substr($by, 0, 100)];
      if ($hasCard) $row[] = !empty($x['grp']) ? 'cardok' : (string)($x['src'] ?? '');
      $ins->execute($row); $added++; if ($x['cat'] === '') $un++;
      if (!empty($x['grp'])) $done[$x['grp']] = 1;
    }
    foreach (array_keys($done) as $gid) {   // הדביט שהוחלף ברכישות: נמחק, ונשמר "קבר" כדי שייבוא חוזר של דוח הבנק לא יחזיר אותו
      $d = $pdo->prepare("SELECT tx_date, ref, amount, name FROM budget_tx WHERE id = ? AND src = ''"); $d->execute([$gid]); $r = $d->fetch(); if (!$r) continue;
      $pdo->prepare('INSERT INTO budget_tomb (tx_date, ref, amount, name, created_at) VALUES (?,?,?,?,?)')->execute([$r['tx_date'], $r['ref'], $r['amount'], $r['name'], now()]);
      $pdo->prepare('DELETE FROM budget_tx WHERE id = ?')->execute([$gid]); $rep++;
    }
    $pdo->commit();
  } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
  return [$added, $un, $upd, $rep];
}
/** למידה משיוך ידני: כלל חדש לפי שם הספק, והחלתו על תנועות נוספות שעדיין לא משויכות. מחזיר כמה תנועות נוספות שויכו (-1 אם הכלל כבר קיים). */
function budget_learn(string $kw, string $cat): int {
  $kw = mb_substr(trim($kw), 0, 100); if ($kw === '' || $cat === '') return -1;
  $pdo = db(); if (in_array($kw, BUDGET_GENERIC, true)) return -2; $g = $pdo->prepare('SELECT COUNT(*) FROM budget_tx WHERE name = ?'); $g->execute([$kw]); if ((int)$g->fetchColumn() > 10) return -2;   // שם כללי של הבנק (למשל "העברה באינטרנט") – לא ללמוד, אחרת כל התנועות יקבלו אותה קטגוריה
  $q = $pdo->prepare('SELECT 1 FROM budget_rules WHERE kw = ? LIMIT 1'); $q->execute([$kw]); if ($q->fetchColumn()) return -1;
  $ord = (int)$pdo->query('SELECT COALESCE(MAX(ord),0) FROM budget_rules')->fetchColumn() + 10;
  $pdo->prepare('INSERT INTO budget_rules (ord, kw, category) VALUES (?,?,?)')->execute([$ord, $kw, $cat]);
  $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw) . '%';
  $u = $pdo->prepare("UPDATE budget_tx SET category = ?, updated_at = ? WHERE category = '' AND CONCAT(name, ' ', descr) LIKE ?"); $u->execute([$cat, now(), $like]);
  return $u->rowCount();
}

/** תנאי WHERE ופרמטרים לפי מסנני הדף. */
function budget_where(array $f): array {
  $w = []; $a = [];
  if (!empty($f['range'])) { $w[] = 'tx_date BETWEEN ? AND ?'; $a[] = $f['range'][0]; $a[] = $f['range'][1]; }
  if (($f['cat'] ?? '') === '__un') $w[] = "category = ''"; elseif (($f['cat'] ?? '') !== '') { $w[] = 'category = ?'; $a[] = $f['cat']; }
  if (($f['unit'] ?? '') !== '') { $w[] = 'unit = ?'; $a[] = $f['unit']; }
  if (($f['type'] ?? '') === 'in') $w[] = 'is_income = 1'; elseif (($f['type'] ?? '') === 'out') $w[] = 'is_income = 0';
  switch ($f['doc'] ?? '') {
    case 'ni': $w[] = 'has_invoice = 0'; break; case 'nr': $w[] = 'has_receipt = 0'; break;
    case 'nb': $w[] = 'has_invoice = 0 AND has_receipt = 0'; break; case 'ok': $w[] = 'has_invoice = 1 AND has_receipt = 1'; break;
  }
  if (($f['month'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}$/', (string)$f['month'])) { $w[] = "DATE_FORMAT(tx_date,'%Y-%m') = ?"; $a[] = $f['month']; }
  $q = trim((string)($f['q'] ?? ''));
  if ($q !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'; $w[] = "(name LIKE ? OR descr LIKE ? OR ref LIKE ? OR category LIKE ? OR unit LIKE ? OR CAST(amount AS CHAR) LIKE ? OR DATE_FORMAT(tx_date,'%d/%m/%Y') LIKE ?)"; array_push($a, $like, $like, $like, $like, $like, $like, $like); }
  return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}
/** עמודת עמלה/גרף: אחוז רוחב (0..100) לשימוש במחלקת CSS wNN (בלי style בתוך ה-HTML בגלל CSP). */
function budget_bar(string $label, float $val, float $max, string $text, bool $inc = false): string {
  $p = $max > 0 ? (int)max(1, min(100, round(abs($val) / $max * 100))) : 1;
  return '<div class="bgb"><span>' . e($label) . '</span><i class="w' . $p . ($inc ? ' inc' : '') . '"></i><span class="n">' . e($text) . '</span></div>';
}

/** מסנני הדף מתוך GET: שנה (y=שנת התחלה או all), קטגוריה, יחידה, סוג, מסמכים, חודש, חיפוש. */
function budget_filters(): array {
  $ys = budget_years(); $yr = (string)($_GET['y'] ?? ''); $y = $yr === 'all' ? null : (ctype_digit($yr) && in_array((int)$yr, $ys, true) ? (int)$yr : budget_year_current());
  $cat = (string)($_GET['cat'] ?? ''); $unit = (string)($_GET['unit'] ?? ''); if ($unit !== '' && !in_array($unit, budget_units(), true)) $unit = '';
  $type = (string)($_GET['type'] ?? ''); if (!in_array($type, ['in', 'out'], true)) $type = '';
  $doc = (string)($_GET['doc'] ?? ''); if (!in_array($doc, ['ni', 'nr', 'nb', 'ok'], true)) $doc = '';
  $mo = (string)($_GET['month'] ?? ''); if (!preg_match('/^\d{4}-\d{2}$/', $mo)) $mo = '';
  return ['y' => $y, 'yr' => $y === null ? 'all' : (string)$y, 'years' => $ys, 'range' => budget_range($y), 'cat' => mb_substr($cat, 0, 100), 'unit' => $unit, 'type' => $type, 'doc' => $doc, 'month' => $mo, 'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100)];
}
function budget_year_select(array $f): string {
  $o = '<option value="all"' . ($f['y'] === null ? ' selected' : '') . '>כל התקופה</option>';
  foreach ($f['years'] as $y) $o .= '<option value="' . $y . '"' . ($f['y'] === $y ? ' selected' : '') . '>שנת ' . e(budget_year_label($y)) . ' (' . $y . '–' . ($y + 1) . ')</option>';
  return '<label>שנה<select name="y">' . $o . '</select></label>';
}
