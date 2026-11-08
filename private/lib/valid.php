<?php
declare(strict_types=1);
// ולידציות ונירמול: טלפון ישראלי, תעודת זהות, קופת חולים.
const HMO_LIST = ['מאוחדת', 'לאומית', 'כללית', 'מכבי'];
const HMO_UNKNOWN = 'לא ידוע';

/** מנרמל טלפון ל-ספרות בלבד עם 0 בהתחלה (חסר 0 מהאקסל, +972, מקפים ורווחים). אם אי אפשר – מחזיר את המקור כמות שהוא. */
function norm_phone(string $v): string {
  $raw = trim($v); if ($raw === '') return '';
  $d = preg_replace('/\D/', '', $raw) ?? '';
  if (str_starts_with($d, '00972')) $d = '0' . substr($d, 5);
  elseif (str_starts_with($d, '972') && strlen($d) >= 11) $d = '0' . substr($d, 3);
  elseif (strlen($d) === 9 && in_array($d[0], ['5', '7'], true)) $d = '0' . $d;
  elseif (strlen($d) === 8 && in_array($d[0], ['2', '3', '4', '8', '9'], true)) $d = '0' . $d;
  return phone_ok($d) ? $d : $raw;
}
function phone_ok(string $d): bool { return (bool)preg_match('/^0(5\d{8}|7\d{8}|[2-489]\d{7})$/', $d); }
/** תצוגה: 050-1234567 / 04-1234567. ערך לא תקין מוצג כמות שהוא. */
function fmt_phone(string $v): string {
  $d = trim($v);
  if (!phone_ok($d)) { $n = norm_phone($d); if (!phone_ok($n)) return $d; $d = $n; }
  return strlen($d) === 10 ? substr($d, 0, 3) . '-' . substr($d, 3) : substr($d, 0, 2) . '-' . substr($d, 2);
}
function valid_il_id(string $v): bool {
  $v = preg_replace('/\D/', '', $v) ?? ''; if ($v === '' || strlen($v) > 9) return false;
  $v = str_pad($v, 9, '0', STR_PAD_LEFT); $s = 0;
  for ($i = 0; $i < 9; $i++) { $n = (int)$v[$i] * (($i % 2) + 1); $s += $n > 9 ? $n - 9 : $n; }
  return $s % 10 === 0;
}
/** מנרמל ת״ז: ספרות בלבד, מושלם ל-9 ספרות. */
function norm_id(string $v): string {
  $d = preg_replace('/\D/', '', $v) ?? '';
  return ($d !== '' && strlen($d) <= 9) ? str_pad($d, 9, '0', STR_PAD_LEFT) : trim($v);
}
/** ממפה טקסט חופשי לאחת מקופות החולים; ריק → "לא ידוע"; ערך לא מוכר נשאר כמות שהוא. */
function hmo_norm(string $v): string {
  $t = mb_strtolower(trim($v)); if ($t === '' || $t === 'לא ידוע' || $t === 'לא ידועה') return HMO_UNKNOWN;
  foreach (['מאוחדת' => ['מאוחד', 'meuhedet'], 'לאומית' => ['לאומי', 'leumit'], 'כללית' => ['כלל', 'clalit'], 'מכבי' => ['מכבי', 'maccabi']] as $k => $keys)
    foreach ($keys as $x) if (str_contains($t, $x)) return $k;
  return trim($v);
}
