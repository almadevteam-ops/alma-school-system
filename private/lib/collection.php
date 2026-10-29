<?php
declare(strict_types=1);
// שנת לימודים: ספטמבר = חודש 1 ... אוגוסט = חודש 12
const SCHOOL_MONTHS = [1 => 'ספטמבר', 2 => 'אוקטובר', 3 => 'נובמבר', 4 => 'דצמבר', 5 => 'ינואר', 6 => 'פברואר', 7 => 'מרץ', 8 => 'אפריל', 9 => 'מאי', 10 => 'יוני', 11 => 'יולי', 12 => 'אוגוסט'];
const PAY_METHODS = ['hok_bank' => 'הוראת קבע בנק', 'hok_card' => 'הו״ק אשראי (נדרים פלוס)', 'check' => 'צ׳קים', 'cash' => 'מזומן', 'transfer' => 'העברה', 'payer' => 'גורם משלם (עמותה)', 'other' => 'אחר'];
const CHARGE_KINDS = ['books' => 'ספרי לימוד', 'tour' => 'סיור', 'activities' => 'חוגים', 'other' => 'אחר'];
const BOOKS_BY_GRADE = ['ז' => 250, 'ח' => 250, 'ט' => 350, 'י' => 350, 'יא' => 350];
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
function collection_data(?array $ids = null): array {
  $pdo = db();
  $w = ''; $a = [];
  if ($ids !== null) {
    if (!$ids) return [];
    $w = ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $a = array_map('intval', $ids);
  }
  $st = $pdo->prepare('SELECT id, monthly, months FROM families' . $w); $st->execute($a);
  $out = [];
  foreach ($st->fetchAll() as $f) {
    $n = max(1, min(12, (int)$f['months']));
    $out[(int)$f['id']] = ['monthly' => (float)$f['monthly'], 'months' => $n, 'tuition' => round((float)$f['monthly'] * $n, 2),
      'charges' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'assigned' => array_fill(1, $n, 0.0), 'pool' => 0.0, 'cov' => [], 'status' => []];
  }
  if (!$out) return [];
  $in = implode(',', array_fill(0, count($out), '?')); $keys = array_keys($out);
  $st = $pdo->prepare("SELECT family_id, SUM(amount) s FROM charges WHERE voided_at IS NULL AND family_id IN ($in) GROUP BY family_id"); $st->execute($keys);
  foreach ($st->fetchAll() as $r) $out[(int)$r['family_id']]['charges'] = (float)$r['s'];
  $st = $pdo->prepare("SELECT family_id, charge_id, for_month, SUM(amount) s FROM payments WHERE voided_at IS NULL AND family_id IN ($in) GROUP BY family_id, charge_id, for_month"); $st->execute($keys);
  foreach ($st->fetchAll() as $r) {
    $o =& $out[(int)$r['family_id']]; $s = (float)$r['s'];
    $o['paid'] += $s;
    if ($r['charge_id'] === null) {
      $m = $r['for_month'] === null ? 0 : (int)$r['for_month'];
      if ($m >= 1 && $m <= $o['months']) $o['assigned'][$m] += $s; else $o['pool'] += $s;
    }
    unset($o);
  }
  foreach ($out as &$o) {
    $pool = $o['pool'];
    for ($m = 1; $m <= $o['months']; $m++) {
      $c = $o['assigned'][$m];
      $need = $o['monthly'] - $c;
      if ($need > 0.004 && $pool > 0.004) { $t = min($need, $pool); $c += $t; $pool -= $t; }
      $o['cov'][$m] = round($c, 2);
      $o['status'][$m] = $o['monthly'] <= 0 ? 'na' : ($c >= $o['monthly'] - 0.004 ? 'paid' : ($c > 0.004 ? 'part' : 'none'));
    }
    $o['paid'] = round($o['paid'], 2);
    $o['balance'] = round($o['tuition'] + $o['charges'] - $o['paid'], 2);
  }
  unset($o);
  return $out;
}
function month_cells(array $s): string {
  $h = '';
  foreach ($s['status'] as $m => $x) {
    $sym = ['paid' => '✓', 'part' => '½', 'none' => '·', 'na' => '–'][$x];
    $h .= '<span class="mc ' . $x . '" title="' . e(SCHOOL_MONTHS[$m] . ': ' . money($s['cov'][$m])) . '">' . $sym . '</span>';
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
  $st = db()->prepare('SELECT id, name, grade, cls, f_name, f_phone, m_name, m_phone, marital, status FROM students WHERE family_id = ? ORDER BY FIELD(grade,\'ז\',\'ח\',\'ט\',\'י\',\'יא\',\'יב\'), name');
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
