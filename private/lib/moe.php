<?php
declare(strict_types=1);
// סנכרון מול מצבת משרד החינוך: קריאת קובץ, זיהוי עמודות לפי כותרות, התאמה לפי ת״ז ובהיעדרה לפי שם, והשוואת שדות.
const MOE_MAX_BYTES = 5242880;
const MOE_MAX_ROWS = 3000;
const MOE_FIELDS = [
  'idn' => 'ת.ז', 'name' => 'שם מלא', 'first' => 'שם פרטי', 'last' => 'שם משפחה', 'dob' => 'תאריך לידה', 'addr' => 'כתובת', 'city' => 'עיר',
  'phone' => 'טלפון תלמידה', 'email' => 'אימייל', 'grade' => 'כיתה / שכבה', 'f_name' => 'שם האב', 'f_phone' => 'טלפון אב', 'f_id' => 'ת.ז אב',
  'm_name' => 'שם האם', 'm_phone' => 'טלפון אם', 'm_id' => 'ת.ז אם', 'home_phone' => 'טלפון בית', 'hmo' => 'קופת חולים',
];
/** שדות שמותר לעדכן בתלמידה קיימת (השם והכיתה אינם מתעדכנים מכאן). */
const MOE_SYNC = ['idn', 'dob', 'addr', 'city', 'phone', 'email', 'f_name', 'f_phone', 'f_id', 'm_name', 'm_phone', 'm_id', 'home_phone', 'hmo'];
const MOE_AUTO = [
  ['f_id', '/(זהות|ת\.?\s?ז).*אב|אב.*(זהות|ת\.?\s?ז)/u'], ['m_id', '/(זהות|ת\.?\s?ז).*אם|אם.*(זהות|ת\.?\s?ז)/u'],
  ['f_phone', '/(טלפון|נייד|פלאפון).*אב|אב.*(טלפון|נייד)/u'], ['m_phone', '/(טלפון|נייד|פלאפון).*אם|אם.*(טלפון|נייד)/u'],
  ['f_name', '/שם.*אב|^אב$/u'], ['m_name', '/שם.*אם|^אם$/u'], ['idn', '/זהות|ת\.?\s?ז/u'], ['first', '/פרטי/u'], ['last', '/משפחה/u'],
  ['dob', '/לידה/u'], ['addr', '/כתובת|רחוב/u'], ['city', '/ישוב|יישוב|עיר/u'], ['home_phone', '/טלפון בית|^בית/u'], ['phone', '/^טלפון נייד$/u'],
  ['phone', '/טלפון|נייד|פלאפון/u'], ['email', '/דוא|מייל/u'], ['grade', '/שכבה|כית/u'], ['hmo', '/קופ/u'], ['name', '/^שם( תלמיד\S*)?$/u'],
];

function moe_read(string $path, string $name): array {
  if (preg_match('/\.xlsx$/i', $name)) return xlsx_rows($path);
  $raw = import_decode((string)file_get_contents($path)); $first = explode("\n", $raw, 2)[0] ?? ''; $delim = ',';
  foreach ([';', "\t"] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;
  $fh = fopen('php://memory', 'r+'); fwrite($fh, $raw); rewind($fh); $rows = [];
  while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) { $rows[] = $r; if (count($rows) > MOE_MAX_ROWS + 20) break; }
  return $rows;
}
/** שורת הכותרות: בין 15 הראשונות, לפחות 3 תאים עם אותיות עבריות וללא מספרים ארוכים. */
function moe_header_row(array $rows): int {
  foreach (array_slice($rows, 0, 15, true) as $i => $r) {
    $v = array_values(array_filter(array_map(fn($x) => trim((string)$x), $r), fn($x) => $x !== ''));
    if (count($v) >= 3 && !array_filter($v, fn($x) => preg_match('/^\d{6,}$/', $x)) && count(array_filter($v, fn($x) => preg_match('/[א-ת]/u', $x))) >= 3) return (int)$i;
  }
  return 0;
}
function moe_auto(array $hd): array {
  $used = []; $map = [];
  foreach ($hd as $i => $h) {
    $h = trim((string)$h); if ($h === '') continue;
    foreach (MOE_AUTO as [$k, $re]) if (!isset($used[$k]) && preg_match($re, $h)) { $used[$k] = 1; $map[(int)$i] = $k; break; }
  }
  return $map;
}
function moe_tk(string $s): array { return array_values(array_filter(preg_split('/\s+/u', trim(preg_replace('/[^א-ת ]/u', '', $s) ?? '')) ?: [], fn($x) => $x !== '')); }
function moe_same_name(string $a, string $b): bool { $t = moe_tk($a); $n = moe_tk($b); return $t && !array_diff($t, $n); }

/** ערך מנורמל לפי סוג השדה. */
function moe_norm(string $k, string $v): string {
  $v = trim($v);
  if ($v === '') return '';
  return match ($k) {
    'idn', 'f_id', 'm_id' => norm_id($v),
    'dob' => dob_iso($v),
    'phone', 'f_phone', 'm_phone', 'home_phone' => norm_phone($v),
    'hmo' => hmo_norm($v),
    'email' => mb_strtolower($v),
    default => preg_replace('/\s+/u', ' ', $v) ?? $v,
  };
}
/** מחשב את שלוש הרשימות: רק במשרד / בשני הצדדים (עם הבדלים) / רק אצלנו. */
function moe_compute(array $rows, int $hi, array $map): array {
  $inv = []; foreach ($map as $i => $k) if ($k !== '') $inv[$k] = (int)$i;
  $M = [];
  foreach (array_slice($rows, $hi + 1) as $c) {
    $g = fn(string $k) => isset($inv[$k]) ? trim((string)($c[$inv[$k]] ?? '')) : '';
    $x = [];
    foreach (MOE_FIELDS as $k => $_) if (!in_array($k, ['name', 'first', 'last', 'grade'], true)) $x[$k] = moe_norm($k, $g($k));
    $x['nm'] = $g('name') !== '' ? $g('name') : trim($g('last') . ' ' . $g('first'));
    $gr = preg_replace('/["\'׳״\s]/u', '', preg_replace('/כיתה|שכבה/u', '', $g('grade')) ?? '') ?? '';
    $x['grade'] = ''; $x['cls'] = '';
    if (preg_match('/^(יא|יב|י|ח|ט|ז)\D*(\d)?$/u', $gr, $m)) { $x['grade'] = $m[1]; $x['cls'] = $m[1] . ($m[2] ?? ''); }
    elseif (preg_match('/^(7|8|9|10|11|12)$/', $gr)) { $x['grade'] = import_grade($gr); $x['cls'] = $x['grade']; }
    if ($x['nm'] !== '' || $x['idn'] !== '') $M[] = $x;
  }
  $S = db()->query('SELECT id, name, idn, status, archived_at, ' . implode(', ', MOE_SYNC) . ' FROM students')->fetchAll();
  $byId = []; foreach ($S as $s) { $d = preg_replace('/\D/', '', (string)$s['idn']); if ($d !== '') $byId[str_pad($d, 9, '0', STR_PAD_LEFT)][] = $s; }
  $find = function (array $x) use ($byId, $S) {
    if ($x['idn'] !== '' && isset($byId[$x['idn']])) return $byId[$x['idn']][0];
    if ($x['nm'] === '') return null;
    foreach ($S as $s) {
      $sd = preg_replace('/\D/', '', (string)$s['idn']);
      if ($x['idn'] !== '' && $sd !== '') continue;   // לשני הצדדים יש ת״ז והן שונות: לא אותה תלמידה
      if (moe_same_name((string)$s['name'], $x['nm'])) return $s;
    }
    return null;
  };
  $only = []; $both = []; $seen = [];
  foreach ($M as $i => $x) { $s = $find($x); if (!$s) { $only[] = $i; continue; } $both[] = [(int)$s['id'], $i]; $seen[(int)$s['id']] = 1; }
  $miss = [];
  foreach ($S as $s) if ($s['status'] === 'active' && $s['archived_at'] === null && !isset($seen[(int)$s['id']])) $miss[] = (int)$s['id'];
  return ['M' => $M, 'only' => $only, 'both' => $both, 'miss' => $miss, 'S' => array_column($S, null, 'id')];
}
/** הבדלים בין תלמידה קיימת לרשומה ממשרד החינוך, לפי מצב: fill = רק שדות ריקים; over = גם החלפה. */
function moe_diffs(array $s, array $x, string $mode): array {
  $o = [];
  foreach (MOE_SYNC as $k) {
    $new = (string)($x[$k] ?? ''); if ($new === '') continue;
    if ($k === 'addr') $new = split_street_house($new)[0]; if ($new === '') continue;
    if (in_array($k, ['idn', 'f_id', 'm_id'], true) && !valid_il_id($new)) continue;
    if ($k === 'dob' && dob_iso($new) === '') continue;
    $cur = trim((string)($s[$k] ?? '')); if ($k === 'hmo' && $cur === HMO_UNKNOWN) $cur = '';
    if ($cur === $new) continue;
    if ($mode === 'fill' && $cur !== '') continue;
    $o[$k] = [$cur, $new];
  }
  return $o;
}
