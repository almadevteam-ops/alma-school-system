<?php
declare(strict_types=1);
// ייבוא תלמידות מקובץ CSV: פענוח, נרמול, בדיקות ותצוגה מקדימה. אין כאן כתיבה למסד – זה ב-import.php.
const IMPORT_MAX_BYTES = 2 * 1024 * 1024;
const IMPORT_MAX_ROWS = 2000;
const IMPORT_FIELDS = [
  'name' => 'שם מלא', 'grade' => 'כיתה', 'cls' => 'מחזור/כיתה מפורטת', 'idn' => 'תעודת זהות', 'dob' => 'תאריך לידה',
  'addr' => 'כתובת', 'city' => 'עיר', 'email' => 'אימייל', 'phone' => 'טלפון התלמידה',
  'f_name' => 'שם האב', 'f_phone' => 'טלפון האב', 'f_id' => 'ת״ז האב',
  'm_name' => 'שם האם', 'm_phone' => 'טלפון האם', 'm_id' => 'ת״ז האם',
  'hmo' => 'קופת חולים', 'marital' => 'מצב הורים', 'status' => 'סטטוס', 'notes' => 'הערות',
];
const IMPORT_MAXLEN = ['name' => 100, 'grade' => 4, 'cls' => 8, 'idn' => 20, 'dob' => 12, 'addr' => 200, 'city' => 80, 'email' => 150, 'phone' => 30,
  'f_name' => 80, 'f_phone' => 30, 'f_id' => 20, 'm_name' => 80, 'm_phone' => 30, 'm_id' => 20, 'hmo' => 30, 'marital' => 12, 'status' => 12, 'notes' => 2000];

function import_norm_header(string $h): string {
  $h = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', '', $h) ?? '';
  $h = str_replace(['״', '"', '׳', "'", '_', '-', '.'], '', $h);
  return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $h) ?? ''));
}
function import_header_map(): array {
  $a = [
    'name' => ['שם', 'שם מלא', 'שם התלמידה', 'שם תלמידה', 'תלמידה', 'name', 'full name'],
    'grade' => ['כיתה', 'שכבה', 'grade', 'class'],
    'cls' => ['מחזור', 'כיתה מפורטת', 'מחזור כיתה', 'cls'],
    'idn' => ['תז', 'ת ז', 'תעודת זהות', 'מספר זהות', 'זהות', 'id', 'idn'],
    'dob' => ['תאריך לידה', 'ת לידה', 'dob', 'birth'],
    'addr' => ['כתובת', 'רחוב', 'address', 'addr'],
    'city' => ['עיר', 'יישוב', 'ישוב', 'city'],
    'email' => ['אימייל', 'מייל', 'דואל', 'email', 'mail'],
    'phone' => ['טלפון', 'טלפון התלמידה', 'נייד', 'נייד התלמידה', 'phone'],
    'f_name' => ['שם האב', 'אב', 'f name', 'fname'],
    'f_phone' => ['טלפון האב', 'נייד האב', 'f phone', 'fphone'],
    'f_id' => ['תז האב', 'ת ז האב', 'זהות האב', 'f id', 'fid'],
    'm_name' => ['שם האם', 'אם', 'm name', 'mname'],
    'm_phone' => ['טלפון האם', 'נייד האם', 'm phone', 'mphone'],
    'm_id' => ['תז האם', 'ת ז האם', 'זהות האם', 'm id', 'mid'],
    'hmo' => ['קופת חולים', 'קופה', 'hmo'],
    'marital' => ['מצב הורים', 'מצב משפחתי', 'marital'],
    'status' => ['סטטוס', 'מצב', 'status'],
    'notes' => ['הערות', 'הערה', 'notes'],
  ];
  $m = [];
  foreach ($a as $k => $list) foreach ($list as $x) $m[import_norm_header($x)] = $k;
  return $m;
}
function import_decode(string $raw): string {
  if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
  if (!mb_check_encoding($raw, 'UTF-8')) {
    $c = @iconv('Windows-1255', 'UTF-8//IGNORE', $raw);
    $raw = $c === false ? '' : $c;
  }
  return str_replace(["\r\n", "\r"], "\n", $raw);
}
function import_grade(string $g): string {
  $g = trim(str_replace(['״', '"', '׳', "'", 'כיתה', "\u{200F}", "\u{200E}"], '', $g));
  $num = ['7' => 'ז', '8' => 'ח', '9' => 'ט', '10' => 'י', '11' => 'יא', '12' => 'יב'];
  if (isset($num[$g])) return $num[$g];
  $g = preg_replace('/\s+/u', '', $g) ?? '';
  // "ט1", "ט 2", "יא3" -> שכבה בלבד
  if (preg_match('/^(יב|יא|ז|ח|ט|י)\d*$/u', $g, $m)) return $m[1];
  return $g;
}
function import_id_ok(string $v): bool {
  $v = preg_replace('/\D/', '', $v) ?? ''; if ($v === '' || strlen($v) > 9) return false;
  $v = str_pad($v, 9, '0', STR_PAD_LEFT); $s = 0;
  for ($i = 0; $i < 9; $i++) { $n = (int)$v[$i] * (($i % 2) + 1); $s += $n > 9 ? $n - 9 : $n; }
  return $s % 10 === 0;
}
function import_id_norm(string $v): string {
  $d = preg_replace('/\D/', '', $v) ?? '';
  return ($d !== '' && strlen($d) <= 9) ? str_pad($d, 9, '0', STR_PAD_LEFT) : trim($v);
}
// מחזיר ['fatal'=>string|null, 'rows'=>[ ['line'=>int,'d'=>array,'errors'=>[],'warns'=>[],'dup'=>string] ], 'unknown'=>[כותרות שלא זוהו]]
function import_parse(string $raw, array $existing): array {
  $out = ['fatal' => null, 'rows' => [], 'unknown' => []];
  $text = import_decode($raw);
  $first = strtok($text, "\n") ?: '';
  $delim = ','; $best = substr_count($first, ',');
  foreach (["\t", ';'] as $dl) { $c = substr_count($first, $dl); if ($c > $best) { $best = $c; $delim = $dl; } }
  $fh = fopen('php://temp', 'w+'); fwrite($fh, $text); rewind($fh);
  $hdr = fgetcsv($fh, 0, $delim, '"', '');
  if (!$hdr || count($hdr) < 2) return array_merge($out, ['fatal' => 'לא זוהתה שורת כותרות בקובץ.']);
  $map = import_header_map(); $cols = [];
  foreach ($hdr as $i => $h) {
    $k = $map[import_norm_header((string)$h)] ?? null;
    if ($k && !in_array($k, $cols, true)) $cols[$i] = $k; elseif (trim((string)$h) !== '') $out['unknown'][] = trim((string)$h);
  }
  if (!in_array('name', $cols, true) || !in_array('grade', $cols, true)) {
    return array_merge($out, ['fatal' => 'חובה שיהיו בקובץ עמודות "שם" ו"כיתה". הכותרות שזוהו: ' . implode(', ', array_map(fn($k) => IMPORT_FIELDS[$k], $cols)) . '.']);
  }
  $byId = []; $byNG = [];
  foreach ($existing as $r) { if ($r['idn'] !== '') $byId[$r['idn']] = true; $byNG[mb_strtolower($r['name']) . '|' . $r['grade']] = true; }
  $seenId = []; $seenNG = []; $line = 1;
  while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
    $line++;
    if (count(array_filter($r, fn($x) => trim((string)$x) !== '')) === 0) continue;
    if (count($out['rows']) >= IMPORT_MAX_ROWS) return array_merge($out, ['fatal' => 'יותר מ-' . IMPORT_MAX_ROWS . ' שורות. פצלי לכמה קבצים.']);
    $d = array_fill_keys(array_keys(IMPORT_FIELDS), '');
    foreach ($cols as $i => $k) $d[$k] = mb_substr(trim((string)($r[$i] ?? '')), 0, IMPORT_MAXLEN[$k] + 40);
    $errs = []; $warns = [];
    $d['grade'] = import_grade($d['grade']);
    if ($d['name'] === '') $errs[] = 'חסר שם';
    if (!in_array($d['grade'], GRADES, true)) $errs[] = 'כיתה לא מוכרת (' . ($d['grade'] === '' ? 'ריקה' : $d['grade']) . ')';
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errs[] = 'אימייל לא תקין';
    foreach (IMPORT_MAXLEN as $k => $mx) if (mb_strlen($d[$k]) > $mx) $errs[] = IMPORT_FIELDS[$k] . ' ארוך מדי';
    foreach (['idn' => 'ת״ז', 'f_id' => 'ת״ז האב', 'm_id' => 'ת״ז האם'] as $k => $lbl) {
      if ($d[$k] === '') continue;
      $d[$k] = import_id_norm($d[$k]);
      if (!import_id_ok($d[$k])) $warns[] = $lbl . ' לא עוברת בדיקת תקינות';
    }
    $st = $d['status'];
    if ($st === '' ) $d['status'] = 'active';
    elseif (str_starts_with($st, 'עזב') || str_starts_with($st, 'לא פעיל') || $st === 'left') $d['status'] = 'left';
    elseif (str_starts_with($st, 'ממתינ') || $st === 'pending') $d['status'] = 'pending';
    elseif (str_starts_with($st, 'פעיל') || $st === 'active') $d['status'] = 'active';
    else { $warns[] = 'סטטוס לא מוכר – נקבע פעילה'; $d['status'] = 'active'; }
    $d['marital'] = (str_contains($d['marital'], 'גרוש') || $d['marital'] === 'divorced') ? 'divorced' : 'married';
    $dup = '';
    $ng = mb_strtolower($d['name']) . '|' . $d['grade'];
    if ($d['idn'] !== '' && (isset($byId[$d['idn']]))) $dup = 'כבר קיימת במערכת (לפי ת״ז)';
    elseif ($d['idn'] === '' && isset($byNG[$ng])) $dup = 'כבר קיימת במערכת (שם וכיתה)';
    elseif ($d['idn'] !== '' && isset($seenId[$d['idn']])) $dup = 'כפולה בתוך הקובץ (ת״ז)';
    elseif ($d['idn'] === '' && isset($seenNG[$ng])) $dup = 'כפולה בתוך הקובץ (שם וכיתה)';
    if ($d['idn'] !== '') $seenId[$d['idn']] = true; else $seenNG[$ng] = true;
    $out['rows'][] = ['line' => $line, 'd' => $d, 'errors' => $errs, 'warns' => $warns, 'dup' => $dup];
  }
  fclose($fh);
  if (!$out['rows']) $out['fatal'] = 'לא נמצאו שורות נתונים בקובץ.';
  return $out;
}
