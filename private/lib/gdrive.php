<?php
declare(strict_types=1);
// העתק מחוץ לשרת: העלאת קובץ הגיבוי (שכבר מוצפן) לתיקיה בגוגל דרייב של המשתמשת, דרך OAuth עם refresh token (בלי ספריות חיצוניות).
// הגדרה חד-פעמית בעמוד "גיבויים": client id, client secret, refresh token ומזהה תיקיה. הערכים הסודיים נשמרים מוצפנים.
const GD_KEEP = 21;
function gd_url(string $k): string { return (string)cfg($k === 'token' ? 'gd_token_url' : 'gd_api', $k === 'token' ? 'https://oauth2.googleapis.com/token' : 'https://www.googleapis.com'); }
function gd_get(string $k): string {
  $v = setting_get('gd_' . $k, ''); if ($v === '') return '';
  if (in_array($k, ['secret', 'refresh'], true)) { $p = dec_bytes((string)base64_decode($v, true), 'gdrive'); return $p ?? ''; }
  return $v;
}
function gd_save(string $client, string $secret, string $refresh, string $folder, string $by): void {
  setting_set('gd_client', $client, $by); setting_set('gd_folder', $folder, $by);
  if ($secret !== '') setting_set('gd_secret', base64_encode(enc_bytes($secret, 'gdrive')), $by);
  if ($refresh !== '') setting_set('gd_refresh', base64_encode(enc_bytes($refresh, 'gdrive')), $by);
}
function gd_ready(): bool { return gd_get('client') !== '' && gd_get('secret') !== '' && gd_get('refresh') !== '' && gd_get('folder') !== ''; }
/** בקשת HTTP. מחזיר [קוד, כותרות, גוף]. $file – קובץ להעלאה בזרימה. */
function gd_http(string $method, string $url, array $hdr = [], $body = null, ?string $file = null): array {
  $c = curl_init($url); $heads = [];
  curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_HTTPHEADER => $hdr, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$heads) { $p = explode(':', $h, 2); if (count($p) === 2) $heads[strtolower(trim($p[0]))] = trim($p[1]); return strlen($h); }]);
  if ($file !== null) { $fh = fopen($file, 'rb'); curl_setopt_array($c, [CURLOPT_UPLOAD => true, CURLOPT_PUT => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($file), CURLOPT_CUSTOMREQUEST => $method]); }
  elseif ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body);
  $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE); $er = curl_error($c); curl_close($c); if (isset($fh)) fclose($fh);
  if ($out === false) throw new RuntimeException('שגיאת רשת: ' . $er);
  return [$code, $heads, (string)$out];
}
function gd_token(): string {
  [$code, , $b] = gd_http('POST', gd_url('token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id' => gd_get('client'), 'client_secret' => gd_get('secret'), 'refresh_token' => gd_get('refresh'), 'grant_type' => 'refresh_token']));
  $j = json_decode($b, true); if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('האימות מול גוגל נכשל (' . $code . '): ' . ($j['error_description'] ?? $j['error'] ?? 'בדקו את הפרטים'));
  return (string)$j['access_token'];
}
/** בודק גישה לתיקיה. מחזיר שם התיקיה. */
function gd_check(): string {
  $t = gd_token(); [$code, , $b] = gd_http('GET', gd_url('api') . '/drive/v3/files/' . rawurlencode(gd_get('folder')) . '?fields=id,name,mimeType&supportsAllDrives=true', ['Authorization: Bearer ' . $t]);
  $j = json_decode($b, true); if ($code !== 200 || ($j['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') throw new RuntimeException('התיקיה לא נמצאה או שאין גישה אליה (' . $code . ').');
  return (string)$j['name'];
}
/** מעלה קובץ גיבוי לתיקיה ומוחק עותקים ישנים (משאיר GD_KEEP אחרונים). מחזיר [שם, כמה נמחקו]. */
function gd_upload(string $path): array {
  $t = gd_token(); $auth = ['Authorization: Bearer ' . $t]; $name = basename($path);
  [$code, $h] = gd_http('POST', gd_url('api') . '/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', array_merge($auth, ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: application/octet-stream', 'X-Upload-Content-Length: ' . filesize($path)]), json_encode(['name' => $name, 'parents' => [gd_get('folder')]]));
  if ($code !== 200 || empty($h['location'])) throw new RuntimeException('פתיחת ההעלאה נכשלה (' . $code . ').');
  [$code2, , $b2] = gd_http('PUT', $h['location'], array_merge($auth, ['Content-Type: application/octet-stream']), null, $path);
  if ($code2 !== 200 && $code2 !== 201) throw new RuntimeException('ההעלאה נכשלה (' . $code2 . ').');
  $del = 0;
  $q = "'" . gd_get('folder') . "' in parents and trashed = false and name contains 'alma-backup-'";
  [$c3, , $b3] = gd_http('GET', gd_url('api') . '/drive/v3/files?' . http_build_query(['q' => $q, 'orderBy' => 'name desc', 'pageSize' => 200, 'fields' => 'files(id,name)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']), $auth);
  $files = $c3 === 200 ? (json_decode($b3, true)['files'] ?? []) : [];
  foreach (array_slice($files, GD_KEEP) as $f) { if (!preg_match('/^alma-backup-\d{8}-\d{6}\.almb$/', (string)($f['name'] ?? ''))) continue; [$cd] = gd_http('DELETE', gd_url('api') . '/drive/v3/files/' . rawurlencode((string)$f['id']) . '?supportsAllDrives=true', $auth); if ($cd === 204 || $cd === 200) $del++; }
  return [$name, $del];
}
/** מעלה את הגיבוי האחרון ורושם סטטוס. */
function gd_run(): array {
  if (!gd_ready()) return [false, 'גוגל דרייב לא הוגדר.'];
  $l = bk_list(); if (!$l) return [false, 'אין גיבוי להעלאה.'];
  try { [$n, $d] = gd_upload(bk_dir() . '/' . $l[0]['name']); setting_set('gd_last', json_encode(['ok' => true, 'time' => time(), 'file' => $n, 'del' => $d]), 'system'); return [true, "הועלה לדרייב: $n" . ($d ? " (נמחקו $d ישנים)" : '')]; }
  catch (Throwable $t) { setting_set('gd_last', json_encode(['ok' => false, 'time' => time(), 'error' => $t->getMessage()]), 'system'); return [false, $t->getMessage()]; }
}
