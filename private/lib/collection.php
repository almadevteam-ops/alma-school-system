<?php
declare(strict_types=1);
// שנת לימודים: ספטמבר = חודש 1 ... אוגוסט = חודש 12
const SCHOOL_MONTHS = [1 => 'ספטמבר', 2 => 'אוקטובר', 3 => 'נובמבר', 4 => 'דצמבר', 5 => 'ינואר', 6 => 'פברואר', 7 => 'מרץ', 8 => 'אפריל', 9 => 'מאי', 10 => 'יוני', 11 => 'יולי', 12 => 'אוגוסט'];
const PAY_METHODS = ['hok_bank' => 'הוראת קבע בנק', 'hok_card' => 'הו״ק אשראי (נדרים פלוס)', 'check' => 'צ׳קים', 'cash' => 'מזומן', 'transfer' => 'העברה', 'payer' => 'גורם משלם (עמותה)', 'other' => 'אחר'];
const CHARGE_KINDS = ['books' => 'ספרי לימוד', 'tour' => 'סיור', 'activities' => 'חוגים', 'other' => 'אחר'];
/** קטגוריות חיוב שנוספו ידנית (נשמרות בהגדרות). מופיעות בחיוב לכל התיקים ובחיובים נוספים של כל תיק. */
function charge_cats_custom(): array {
  $j = json_decode(setting_get('charge_cats', '[]'), true);
  return is_array($j) ? array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), $j), fn($x) => $x !== ''))) : [];
}
function charge_cats_save(array $l, string $by): void { setting_set('charge_cats', json_encode(array_values($l), JSON_UNESCAPED_UNICODE), $by); }
const BOOKS_BY_GRADE = ['ז' => 250, 'ח' => 250, 'ט' => 350, 'י' => 350, 'יא' => 450, 'יב' => 250];
const PAYER_KINDS = ['parents' => 'הורים', 'father' => 'אבא', 'mother' => 'אמא'];
const COLLECTION_ROLES = ['admin', 'secretary', 'finance'];

function money($v): string { $v = (float)$v; return number_format($v, fmod($v, 1) == 0.0 ? 0 : 2) . ' ₪'; }
function money_in(string $s): ?float {
  $s = trim(str_replace([',', '₪', ' '], ['', '', ''], $s));
  if ($s === '' || !preg_match('/^\d{1,7}(\.\d{1,2})?$/', $s)) return null;
  return round((float)$s, 2);
}
function date_in(string $s): ?string {
  $s = trim($s);
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $s;
  if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})$/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
  return null;
}
function date_out(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function method_label(string $m): string { return PAY_METHODS[$m] ?? ($m === '' ? '' : $m); }

/** חישוב מצב גבייה למשפחות. מחזיר [family_id => summary]. */
function collection_data(?array $ids = null, ?int $ts = null, array $extra = []): array {
  $pdo = db();
  $w = ''; $a = [];
  if ($ids !== null) {
    if (!$ids) return [];
    $w = ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $a = array_map('intval', $ids);
  }
  // תעריף אפקטיבי = תעריף התיק פחות הנחות מאושרות של תלמידות פעילות בתיק
  $st = $pdo->prepare('SELECT id, monthly, months, (SELECT COALESCE(SUM(s.disc_amt),0) FROM students s WHERE s.family_id = families.id AND s.disc_on = 1 AND s.disc_status = \'approved\' AND s.archived_at IS NULL AND s.status <> \'left\') disc,
    (SELECT COUNT(*) FROM students s WHERE s.family_id = families.id AND s.archived_at IS NULL) ns,
    (SELECT COUNT(*) FROM students s WHERE s.family_id = families.id AND s.archived_at IS NULL AND s.status = \'left\' AND s.left_month IS NOT NULL) nl,
    (SELECT MAX(s.left_month) FROM students s WHERE s.family_id = families.id AND s.archived_at IS NULL AND s.status = \'left\') lm FROM families' . $w); $st->execute($a);
  $out = []; $cur = school_month_now($ts);
  foreach ($st->fetchAll() as $f) {
    $n = max(1, min(12, (int)$f['months']));
    $eff = max(0.0, (float)$f['monthly'] - (float)$f['disc']);
    $cap = ((int)$f['ns'] > 0 && (int)$f['ns'] === (int)$f['nl']) ? max(0, (int)$f['lm'] - 1) : $n;   // כל התלמידות בתיק עזבו: החיוב נעצר מחודש העזיבה
    $out[(int)$f['id']] = ['monthly' => $eff, 'base' => (float)$f['monthly'], 'disc' => (float)$f['disc'], 'months' => $n, 'cap' => min($n, $cap), 'tuition' => 0.0, 'marks' => [],
      'cur' => $cur, 'tuition_due' => 0.0, 'balance_year' => 0.0, 'credit' => 0.0, 'charges' => 0.0, 'paid' => 0.0, 'cpaid' => 0.0, 'balance' => 0.0, 'assigned' => array_fill(1, $n, 0.0), 'pool' => 0.0, 'cov' => [], 'status' => []];
  }
  if (!$out) return [];
  $in = implode(',', array_fill(0, count($out), '?')); $keys = array_keys($out);
  $st = $pdo->prepare("SELECT family_id, month, mark FROM month_marks WHERE family_id IN ($in)"); $st->execute($keys);
  foreach ($st->fetchAll() as $r) $out[(int)$r['family_id']]['marks'][(int)$r['month']] = (string)$r['mark'];
  $st = $pdo->prepare("SELECT family_id, SUM(amount - disc_amt) s FROM charges WHERE voided_at IS NULL AND mark <> 'n' AND family_id IN ($in) GROUP BY family_id"); $st->execute($keys);
  foreach ($st->fetchAll() as $r) $out[(int)$r['family_id']]['charges'] = (float)$r['s'];
  $st = $pdo->prepare("SELECT family_id, charge_id, for_month, SUM(amount) s FROM payments WHERE voided_at IS NULL AND family_id IN ($in) GROUP BY family_id, charge_id, for_month"); $st->execute($keys);
  $prow = $st->fetchAll();
  foreach ($extra as $xf => $xl) foreach ($xl as [$xa, $xm]) if (isset($out[(int)$xf])) $prow[] = ['family_id' => (int)$xf, 'charge_id' => null, 'for_month' => $xm ?: null, 's' => $xa];
  foreach ($prow as $r) {
    $o =& $out[(int)$r['family_id']]; $s = (float)$r['s'];
    $o['paid'] += $s;
    if ($r['charge_id'] !== null) $o['cpaid'] += $s;
    if ($r['charge_id'] === null) {
      $m = $r['for_month'] === null ? 0 : (int)$r['for_month'];
      if ($m >= 1 && $m <= $o['months']) $o['assigned'][$m] += $s; else $o['pool'] += $s;
    }
    unset($o);
  }
  foreach ($out as &$o) {
    // 1) כל תשלום ששויך לחודש מכסה אותו עד התעריף; העודף עובר ל"מאגר". 2) המאגר ממלא קודם את החודשים הישנים שחסרים (חוב), ואז קדימה (זכות).
    $pool = $o['pool']; $cur = $o['cur']; $c = []; $na = []; $billed = 0; $due = 0.0;
    for ($m = 1; $m <= $o['months']; $m++) {
      $mk = $o['marks'][$m] ?? '';
      $na[$m] = $m > $o['cap'] || $mk === 'n';
      $a = $o['assigned'][$m];
      if ($na[$m] || $o['monthly'] <= 0.004) { $c[$m] = $a; continue; }
      $c[$m] = min($a, $o['monthly']); $pool += $a - $c[$m];
    }
    for ($m = 1; $m <= $o['months'] && $pool > 0.004; $m++) {
      $mk = $o['marks'][$m] ?? '';
      if ($na[$m] || $o['monthly'] <= 0.004 || $mk === 'w' || $mk === 'r') continue;
      $need = $o['monthly'] - $c[$m];
      if ($need > 0.004) { $t = min($need, $pool); $c[$m] += $t; $pool -= $t; }
    }
    for ($m = 1; $m <= $o['months']; $m++) {
      $mk = $o['marks'][$m] ?? ''; $cv = $c[$m];
      $o['cov'][$m] = round($cv, 2);
      if (!$na[$m]) { $billed++; if ($m <= $cur) $due += $o['monthly']; }
      $full = $o['monthly'] > 0 && $cv >= $o['monthly'] - 0.004;
      $o['status'][$m] = ($na[$m] || $o['monthly'] <= 0) ? 'na' : ($full ? 'paid' : ($mk === 'r' ? 'ref' : ($mk === 'w' ? 'wip' : ($m > $cur ? 'fut' : ($cv > 0.004 ? 'part' : 'none')))));
    }
    $o['billed'] = $billed; $o['tuition'] = round($o['monthly'] * $billed, 2); $o['tuition_due'] = round($due, 2);
    $o['paid'] = round($o['paid'], 2); $o['cpaid'] = round($o['cpaid'], 2);
    $o['balance_year'] = round($o['tuition'] + $o['charges'] - $o['paid'], 2);
    $o['balance'] = round($due + $o['charges'] - $o['paid'], 2);   // חוב נכון להיום: חודשים עתידיים אינם נספרים, ותשלום מראש מופיע כזכות (שלילי)
    $o['credit'] = $o['balance'] < -0.004 ? round(-$o['balance'], 2) : 0.0;
  }
  unset($o);
  return $out;
}
/** מספר החודש הנוכחי בשנת הלימודים (ספטמבר=1 … אוגוסט=12). */
function school_month_now(?int $ts = null): int { return (((int)date('n', $ts ?? time()) - 9 + 12) % 12) + 1; }
/**
 * פיגור נכון להיום: חודש נחשב "לתשלום" מהראשון בו (חצות, שעון ישראל), כולל החודש הנוכחי. חודשים קדימה אינם חוב.
 * מחזיר: due (חודשים לתשלום עד היום), behind (מהם לא שולמו במלואם), gap (₪ חסרים בשכר לימוד לחודשים אלה), cgap (חיובים נוספים שלא שולמו), late (₪ סה"כ).
 */
function collection_arrears(array $s, $collectDay = null, ?int $ts = null): array {
  $due = max(0, min(school_month_now($ts), (int)$s['months']));   // חודש הופך לחוב ב-1 לחודש בחצות; חודשים עתידיים לא נספרים
  $behind = 0; $gap = 0.0; $ms = [];
  for ($m = 1; $m <= $due; $m++) {
    if (in_array($s['status'][$m] ?? 'na', ['na', 'wip', 'ref'], true)) continue;
    $miss = $s['monthly'] - (float)$s['cov'][$m];
    if ($miss > 0.004) { $behind++; $gap += $miss; $ms[] = SCHOOL_MONTHS[$m]; }
  }
  $cgap = max(0.0, round((float)$s['charges'] - (float)$s['cpaid'], 2));
  return ['due' => $due, 'behind' => $behind, 'months' => $ms, 'gap' => round($gap, 2), 'cgap' => $cgap, 'late' => round($gap + $cgap, 2)];
}
/** אנשי קשר לתיקים: [family_id => ['f' => ['אבא','שם','טלפון'], 'm' => ['אמא',...]]] – הראשון שמולא מבין האחיות. */
function family_contacts(array $ids): array {
  if (!$ids) return [];
  $st = db()->prepare('SELECT family_id, f_name, f_phone, m_name, m_phone FROM students WHERE archived_at IS NULL AND family_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
  $st->execute(array_map('intval', $ids)); $o = [];
  foreach ($st->fetchAll() as $r) {
    $f = (int)$r['family_id'];
    foreach (['f' => 'אבא', 'm' => 'אמא'] as $k => $lab) {
      if (isset($o[$f][$k]) || trim((string)$r[$k . '_phone']) === '') continue;
      $o[$f][$k] = [$lab, trim((string)$r[$k . '_name']), trim((string)$r[$k . '_phone'])];
    }
  }
  return $o;
}
function tel_href(string $p): string { return 'tel:' . preg_replace('/[^0-9+]/', '', $p); }
function month_cells(array $s, bool $big = false): string {
  $h = '';
  foreach ($s['status'] as $m => $x) {
    $amt = (float)$s['cov'][$m];
    if ($big) {
      $t = $x === 'na' ? '–' : number_format($amt, fmod($amt, 1) == 0.0 ? 0 : 2);
      $h .= '<div class="mb ' . $x . '" title="' . e(SCHOOL_MONTHS[$m] . ': ' . money($amt)) . '"><small>' . e(SCHOOL_MONTHS[$m]) . '</small><b>' . e($t) . '</b></div>';
      continue;
    }
    $sym = ['paid' => '✓', 'part' => '½', 'none' => '·', 'na' => '–', 'wip' => '…', 'ref' => '↺', 'fut' => '·'][$x];
    $h .= '<span class="mc ' . $x . '" title="' . e(SCHOOL_MONTHS[$m] . ': ' . money($amt)) . '">' . $sym . '</span>';
  }
  return $h;
}
function csv_safe(string $v): string { return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }
function norm_name(string $s): string {
  $w = preg_split('/\s+/u', trim(preg_replace('/[^\x{05D0}-\x{05EA}\s]/u', ' ', $s)) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
  sort($w); return implode(' ', $w);
}
function family_default_name(array $students): string {
  $first = $students ? explode(' ', trim($students[0]))[0] : '';
  return $first !== '' ? 'משפחת ' . $first : 'משפחה חדשה';
}
function family_students(int $fid): array {
  $st = db()->prepare('SELECT id, name, grade, cls, f_name, f_phone, m_name, m_phone, marital, status FROM students WHERE family_id = ? AND archived_at IS NULL ORDER BY FIELD(grade,\'ז\',\'ח\',\'ט\',\'י\',\'יא\',\'יב\'), name');
  $st->execute([$fid]); return $st->fetchAll();
}

const COLIMP_HEADERS = ['students' => ['תלמידות', 'תלמידה', 'שמות'], 'name' => ['שם משפחה', 'שם תיק', 'משפחה'], 'monthly' => ['תעריף חודשי', 'תעריף', 'גובה שכל', 'גובה שכר לימוד'],
  'months' => ['חודשים', 'מספר חודשים'], 'method' => ['צורת תשלום', 'אמצעי תשלום', 'אמצעי'], 'day' => ['יום גבייה', 'ת גביה', 'יום'], 'payer' => ['גורם משלם', 'עמותה'],
  'discount' => ['הנחה', 'סיבת הנחה'], 'notes' => ['הערה', 'הערות']];
const COLIMP_METHODS = ['אשראי' => 'hok_card', 'הוק אשראי' => 'hok_card', 'בנקאי' => 'hok_bank', 'הוק בנק' => 'hok_bank', 'הוראת קבע בנק' => 'hok_bank', 'צקים' => 'check', 'צקים ' => 'check', 'צק' => 'check', 'מזומן' => 'cash', 'העברה' => 'transfer', 'גורם משלם' => 'payer'];
function colimp_parse(string $raw): array {
  $out = ['fatal' => null, 'rows' => []];
  $raw = import_decode($raw);
  $lines = explode("\n", $raw, 2); $first = $lines[0] ?? '';
  $delim = ','; $best = substr_count($first, ',');
  foreach ([';' => ';', "\t" => "\t"] as $d) { $c = substr_count($first, $d); if ($c > $best) { $best = $c; $delim = $d; } }
  $fh = fopen('php://memory', 'r+'); fwrite($fh, $raw); rewind($fh);
  $hdr = fgetcsv($fh, 0, $delim, '"', '');
  if (!$hdr) return array_merge($out, ['fatal' => 'הקובץ ריק.']);
  $map = []; $al = [];
  foreach (COLIMP_HEADERS as $k => $list) foreach ($list as $x) $al[import_norm_header($x)] = $k;
  foreach ($hdr as $i => $h) { $n = import_norm_header((string)$h); if (isset($al[$n])) $map[$al[$n]] = $i; }
  if (!isset($map['students']) || !isset($map['monthly'])) return array_merge($out, ['fatal' => 'חובה שיהיו עמודות "תלמידות" ו"תעריף חודשי".']);
  $idx = []; $nm = [];
  foreach (db()->query('SELECT id, name, grade, cls, family_id FROM students')->fetchAll() as $s) { $nm[norm_name($s['name'])][] = $s; }
  $seen = []; $line = 1;
  while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
    $line++;
    if (count($r) === 1 && trim((string)$r[0]) === '') continue;
    $g = fn($k) => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';
    $row = ['line' => $line, 'errors' => [], 'students' => [], 'student_names' => [], 'name' => $g('name'), 'monthly' => 0.0, 'months' => 10, 'method' => '', 'day' => null, 'payer' => $g('payer'), 'discount' => mb_substr($g('discount'), 0, 255), 'notes' => mb_substr($g('notes'), 0, 2000)];
    $names = array_values(array_filter(array_map('trim', preg_split('/[;+\n]/u', $g('students')) ?: [])));
    if (!$names) $row['errors'][] = 'אין שמות תלמידות';
    foreach ($names as $n) {
      // רמז כיתה אופציונלי בסוף השם: "כהן יעל (י1)" – משמש רק כשיש כמה תלמידות באותו שם
      $hint = '';
      if (preg_match('/^(.*?)\s*[\[(]([^\])]{1,10})[\])]\s*$/u', $n, $hm)) { $n = trim($hm[1]); $hint = trim($hm[2]); }
      $c = $nm[norm_name($n)] ?? [];
      if (count($c) > 1 && $hint !== '') {
        $c2 = array_values(array_filter($c, fn($x) => $x['grade'] === $hint || $x['cls'] === $hint || $x['grade'] . $x['cls'] === $hint));
        if (count($c2) >= 1) $c = $c2;
      }
      if (count($c) === 0) { $row['errors'][] = 'לא נמצאה תלמידה: ' . $n; continue; }
      if (count($c) > 1) { $row['errors'][] = 'כמה תלמידות בשם: ' . $n; continue; }
      $s = $c[0];
      if ($s['family_id'] !== null) { $row['errors'][] = 'כבר בתיק גבייה: ' . $n; continue; }
      if (isset($seen[$s['id']])) { $row['errors'][] = 'מופיעה פעמיים בקובץ: ' . $n; continue; }
      $seen[$s['id']] = true; $row['students'][] = (int)$s['id']; $row['student_names'][] = $s['name'];
    }
    $m = money_in($g('monthly')); if ($m === null) { $row['errors'][] = 'תעריף לא תקין: ' . $g('monthly'); } else $row['monthly'] = $m;
    if ($g('months') !== '') { $mo = (int)$g('months'); if (in_array($mo, [10, 12], true)) $row['months'] = $mo; else $row['errors'][] = 'חודשים חייב להיות 10 או 12'; }
    $meth = $g('method');
    if ($meth !== '') { $key = trim(str_replace(['״', '"', '׳', "'", '-', '.'], '', $meth)); $row['method'] = COLIMP_METHODS[$key] ?? (isset(PAY_METHODS[$meth]) ? $meth : ''); if ($row['method'] === '') $row['errors'][] = 'צורת תשלום לא מוכרת: ' . $meth; }
    $d = $g('day'); if ($d !== '') { if (ctype_digit($d) && (int)$d >= 1 && (int)$d <= 31) $row['day'] = (int)$d; else $row['errors'][] = 'יום גבייה לא תקין: ' . $d; }
    if ($row['name'] === '' && $row['student_names']) $row['name'] = family_default_name($row['student_names']);
    $out['rows'][] = $row;
    if (count($out['rows']) > 1000) return array_merge($out, ['fatal' => 'יותר מדי שורות.']);
  }
  return $out;
}

/** תווית "מי משלם": הורים / אבא / אמא / שם הגורם המשלם */
function payer_label(array $f): string {
  if (($f['payer_kind'] ?? 'parents') === 'org') return (string)($f['payer'] ?? '');
  return PAYER_KINDS[$f['payer_kind'] ?? 'parents'] ?? 'הורים';
}

function month_in(string $s): ?int {
  $s = trim($s); if ($s === '') return null;
  if (ctype_digit($s) && (int)$s >= 1 && (int)$s <= 12) return (int)$s;
  foreach (SCHOOL_MONTHS as $k => $v) if ($s === $v) return $k;
  return null;
}
const PAYIMP_HEADERS = ['student' => ['תלמידה', 'שם', 'תלמידות'], 'month' => ['חודש'], 'amount' => ['סכום'], 'date' => ['תאריך', 'תאריך תשלום'], 'method' => ['אמצעי', 'אמצעי תשלום', 'צורת תשלום'], 'ref' => ['אסמכתא'], 'note' => ['הערה', 'הערות'], 'for' => ['עבור']];
function payimp_parse(string $raw): array {
  $out = ['fatal' => null, 'rows' => []];
  $raw = import_decode($raw);
  $first = explode("\n", $raw, 2)[0] ?? ''; $delim = ','; $best = substr_count($first, ',');
  foreach ([';', "\t"] as $d) { $c = substr_count($first, $d); if ($c > $best) { $best = $c; $delim = $d; } }
  $fh = fopen('php://memory', 'r+'); fwrite($fh, $raw); rewind($fh);
  $hdr = fgetcsv($fh, 0, $delim, '"', '');
  if (!$hdr) return array_merge($out, ['fatal' => 'הקובץ ריק.']);
  $al = []; $map = [];
  foreach (PAYIMP_HEADERS as $k => $l) foreach ($l as $x) $al[import_norm_header($x)] = $k;
  foreach ($hdr as $i => $h) { $n = import_norm_header((string)$h); if (isset($al[$n])) $map[$al[$n]] = $i; }
  if (!isset($map['student']) || !isset($map['amount'])) return array_merge($out, ['fatal' => 'חובה שיהיו עמודות "תלמידה" ו"סכום".']);
  $nm = [];
  foreach (db()->query('SELECT s.id, s.name, s.grade, s.cls, s.family_id, f.months FROM students s LEFT JOIN families f ON f.id = s.family_id')->fetchAll() as $s) $nm[norm_name($s['name'])][] = $s;
  $methods = array_flip(PAY_METHODS); $line = 1;
  while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
    $line++; if (count($r) === 1 && trim((string)$r[0]) === '') continue;
    $g = fn($k) => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';
    $row = ['line' => $line, 'errors' => [], 'family_id' => null, 'student' => $g('student'), 'month' => null, 'amount' => 0.0, 'date' => null, 'method' => 'other', 'charge_id' => null, 'for' => $g('for'), 'ref' => mb_substr($g('ref'), 0, 60), 'note' => mb_substr($g('note'), 0, 255)];
    $n = $row['student']; $hint = '';
    if (preg_match('/^(.*?)\s*[\[(]([^\])]{1,10})[\])]\s*$/u', $n, $hm)) { $n = trim($hm[1]); $hint = trim($hm[2]); }
    $c = $nm[norm_name($n)] ?? [];
    if (count($c) > 1 && $hint !== '') { $c2 = array_values(array_filter($c, fn($x) => $x['grade'] === $hint || $x['cls'] === $hint)); if ($c2) $c = $c2; }
    if (count($c) !== 1) $row['errors'][] = count($c) ? 'כמה תלמידות בשם: ' . $n : 'לא נמצאה תלמידה: ' . $n;
    elseif (!$c[0]['family_id']) $row['errors'][] = 'אין תיק גבייה ל' . $n;
    else $row['family_id'] = (int)$c[0]['family_id'];
    if ($row['for'] !== '' && $row['family_id']) {
      $cq = db()->prepare('SELECT id FROM charges WHERE family_id = ? AND voided_at IS NULL AND label LIKE ? ORDER BY id LIMIT 1');
      $cq->execute([$row['family_id'], str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $row['for']) . '%']);
      $cid = $cq->fetchColumn();
      if ($cid) $row['charge_id'] = (int)$cid; else $row['errors'][] = 'אין חיוב "' . $row['for'] . '" בתיק (להוסיף קודם חיוב לכולן)';
    }
    $a = money_in($g('amount')); if ($a === null || $a <= 0) $row['errors'][] = 'סכום לא תקין: ' . $g('amount'); else $row['amount'] = $a;
    if ($g('month') !== '') { $m = month_in($g('month')); if ($m === null) $row['errors'][] = 'חודש לא מוכר: ' . $g('month'); else $row['month'] = $m; }
    if ($g('date') !== '') { $d = date_in($g('date')); if ($d === null) $row['errors'][] = 'תאריך לא תקין: ' . $g('date'); else $row['date'] = $d; }
    elseif ($row['month']) { $y = $row['month'] <= 4 ? 2026 : 2027; $mm = [1 => 9, 2 => 10, 3 => 11, 4 => 12, 5 => 1, 6 => 2, 7 => 3, 8 => 4, 9 => 5, 10 => 6, 11 => 7, 12 => 8][$row['month']]; $row['date'] = sprintf('%04d-%02d-01', $y, $mm); $row['note'] = trim($row['note'] . ' (תאריך משוער)'); }
    else $row['errors'][] = 'חסרים חודש או תאריך';
    $mt = $g('method'); if ($mt !== '') { $k = isset(PAY_METHODS[$mt]) ? $mt : ($methods[$mt] ?? (COLIMP_METHODS[str_replace(['״', '"', '׳', "'"], '', $mt)] ?? null)); if ($k === null) $row['errors'][] = 'אמצעי לא מוכר: ' . $mt; else $row['method'] = $k; }
    if (preg_match('/\d{12,}/', preg_replace('/[\s\-]/', '', $row['ref'] . $row['note']))) $row['errors'][] = 'נראה כמו מספר כרטיס אשראי';
    $out['rows'][] = $row;
    if (count($out['rows']) > 3000) return array_merge($out, ['fatal' => 'יותר מדי שורות.']);
  }
  return $out;
}

/** קישור לתשלום מאובטח (ניתן לשינוי בהגדרות). */
function pay_url(): string {
  $u = setting_get('pay_url', 'https://www.matara.pro/nedarimplus/online/?mosad=7001899');
  return preg_match('~^https://~i', $u) ? $u : '';
}
/** קישור וואטסאפ עם הודעה מוכנה למספר ישראלי. */
function wa_url(string $phone, string $text): string {
  $d = preg_replace('/\D/', '', $phone) ?? '';
  if ($d !== '' && $d[0] === '0') $d = '972' . substr($d, 1);
  return 'https://wa.me/' . $d . '?text=' . rawurlencode($text);
}
/** הודעת תזכורת לשכר לימוד. */
function wa_reminder(string $names, array $arrears, float $balance): string {
  $ms = $arrears['months'] ?? [];
  $t = 'שלום, תזכורת בנוגע לשכר הלימוד של ' . $names . ($ms ? ' (חודשים: ' . implode(', ', $ms) . ')' : '') . '. יתרה לתשלום: ' . (int)round($balance) . ' ש"ח. תודה!';
  $p = pay_url(); if ($p !== '') $t .= "\nלתשלום מאובטח: " . $p;
  return $t;
}
/** תיקים של "מורשת גד" (תלמידות בדירה): [family_id => ['name'=>..., 'names'=>...]] */
function gad_families(): array {
  $gid = gad_payer_id();
  $st = db()->prepare("SELECT f.id, f.name, (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM students s WHERE s.family_id = f.id AND s.archived_at IS NULL) names FROM families f WHERE (f.payer_kind = 'org' AND f.payer_id = ?) ORDER BY f.name");
  $st->execute([$gid]); $o = [];
  foreach ($st->fetchAll() as $r) $o[(int)$r['id']] = $r;
  return $o;
}
