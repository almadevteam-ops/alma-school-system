<?php
declare(strict_types=1);
// גיבוי חשבוניות וקבלות של התקציב לגוגל דרייב: תיקיית "חשבוניות עלמא גיבוי תוכנה" ← תיקייה לכל חודש ← קובץ בשם העסק.
// משתמש באותו חיבור דרייב של הגיבויים (דף "גיבויים"). התיקייה נוצרת בתוך תיקיית הגיבויים.
const BDRIVE_ROOT = 'חשבוניות עלמא גיבוי תוכנה';
function bdrive_cols(): bool { static $ok = null; if ($ok === null) { try { $ok = bpay_ready() && (bool)db()->query("SHOW COLUMNS FROM budget_docs LIKE 'drive_at'")->fetch(); } catch (Throwable $t) { $ok = false; } } return $ok; }
function bdrive_on(): bool { return setting_get('bdrive_on', '1') === '1'; }
function bdrive_ready(): bool { return bdrive_cols() && function_exists('gd_ready') && gd_ready() && !is_test_env(); }
function bdrive_month_name(string $date): string {
  static $m = ['', 'ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר'];
  $t = strtotime($date) ?: time(); return date('Y-m', $t) . ' ' . $m[(int)date('n', $t)];
}
function bdrive_clean(string $s): string { $s = trim((string)preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', ' ', $s)); return mb_substr(preg_replace('/\s+/u', ' ', $s) ?? $s, 0, 90); }
/** פרטי המסמך לשם הקובץ ולתיקיית החודש: עסק, תאריך, סכום. */
function bdrive_info(array $d): array {
  $biz = ''; $date = ''; $amt = null;
  if (!empty($d['payable_id'])) { $st = db()->prepare('SELECT supplier, inv_date, total FROM budget_payables WHERE id = ?'); $st->execute([$d['payable_id']]); if ($p = $st->fetch()) { $biz = (string)$p['supplier']; $date = (string)($p['inv_date'] ?? ''); $amt = (float)$p['total']; } }
  if (!empty($d['tx_id'])) { $st = db()->prepare('SELECT name, tx_date, amount FROM budget_tx WHERE id = ?'); $st->execute([$d['tx_id']]); if ($t = $st->fetch()) { if ($biz === '') $biz = (string)$t['name']; if ($date === '') $date = (string)$t['tx_date']; if ($amt === null) $amt = (float)$t['amount']; } }
  if ($date === '') $date = substr((string)$d['created_at'], 0, 10);
  return ['biz' => bdrive_clean($biz) ?: 'ללא שם עסק', 'date' => $date, 'amt' => $amt];
}
function bdrive_file_name(array $d, array $i): string {
  $ext = pathinfo((string)$d['stored'], PATHINFO_EXTENSION) ?: 'pdf';
  return $i['biz'] . ' ' . $i['date'] . ($i['amt'] !== null ? ' ₪' . rtrim(rtrim(number_format($i['amt'], 2, '.', ''), '0'), '.') : '') . ($d['kind'] === 'rec' ? ' (קבלה)' : '') . '.' . $ext;
}
function bdrive_cache(): array { $j = json_decode(setting_get('bdrive_folders', ''), true); return is_array($j) ? $j : ['root' => '', 'months' => []]; }
function bdrive_cache_save(array $c): void { setting_set('bdrive_folders', json_encode($c, JSON_UNESCAPED_UNICODE), 'system'); }
/** OAuth: מוצא או יוצר תיקייה בתוך תיקיית אב. */
function bdrive_o_folder(string $parent, string $name): string {
  $auth = ['Authorization: Bearer ' . gd_token()];
  $q = "'" . $parent . "' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $name) . "'";
  [$c, , $b] = gd_http('GET', gd_url('api') . '/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']), $auth);
  if ($c !== 200) throw new RuntimeException('חיפוש תיקייה בדרייב נכשל (' . $c . ').');
  $j = json_decode($b, true); if (!empty($j['files'][0]['id'])) return (string)$j['files'][0]['id'];
  [$c, , $b] = gd_http('POST', gd_url('api') . '/drive/v3/files?supportsAllDrives=true', array_merge($auth, ['Content-Type: application/json; charset=UTF-8']), json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent]], JSON_UNESCAPED_UNICODE));
  $j = json_decode($b, true); if (($c !== 200 && $c !== 201) || empty($j['id'])) throw new RuntimeException('יצירת תיקייה בדרייב נכשלה (' . $c . ').');
  return (string)$j['id'];
}
function bdrive_o_upload(string $path, string $name, string $parent): string {
  $auth = ['Authorization: Bearer ' . gd_token()];
  [$code, $h] = gd_http('POST', gd_url('api') . '/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', array_merge($auth, ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: application/octet-stream', 'X-Upload-Content-Length: ' . filesize($path)]), json_encode(['name' => $name, 'parents' => [$parent]], JSON_UNESCAPED_UNICODE));
  if ($code !== 200 || empty($h['location'])) throw new RuntimeException('פתיחת ההעלאה נכשלה (' . $code . ').');
  [$code2, , $b2] = gd_http('PUT', $h['location'], array_merge($auth, ['Content-Type: application/octet-stream']), null, $path);
  if ($code2 !== 200 && $code2 !== 201) throw new RuntimeException('ההעלאה נכשלה (' . $code2 . ').');
  $j = json_decode($b2, true); return (string)($j['id'] ?? '');
}
/** מעלה מסמך אחד לדרייב. מחזיר את מזהה הקובץ. */
function bdrive_upload_doc(array $d): string {
  $path = bdoc_dir() . '/' . basename((string)$d['stored']); if (!is_file($path)) throw new RuntimeException('הקובץ חסר בשרת.');
  $i = bdrive_info($d); $name = bdrive_file_name($d, $i); $month = bdrive_month_name($i['date']);
  if (gd_mode() === 'script') {
    $j = gd_script(['action' => 'inv_init', 'root' => BDRIVE_ROOT, 'month' => $month, 'name' => $name, 'size' => filesize($path)]);
    [$code, , $b] = gd_http('PUT', (string)$j['url'], ['Content-Type: application/octet-stream'], null, $path);
    if ($code !== 200 && $code !== 201) throw new RuntimeException('ההעלאה נכשלה (' . $code . ').');
    $r = json_decode($b, true); return (string)($r['id'] ?? '');
  }
  $c = bdrive_cache();
  if (empty($c['root'])) { $c['root'] = bdrive_o_folder(gd_get('folder'), BDRIVE_ROOT); bdrive_cache_save($c); }
  if (empty($c['months'][$month])) { $c['months'][$month] = bdrive_o_folder((string)$c['root'], $month); bdrive_cache_save($c); }
  return bdrive_o_upload($path, $name, (string)$c['months'][$month]);
}
/** מסמכים שעוד לא גובו. */
function bdrive_pending(): int { return bdrive_cols() ? (int)db()->query('SELECT COUNT(*) FROM budget_docs WHERE drive_at IS NULL')->fetchColumn() : 0; }
/** גיבוי מסמכים ממתינים, עד $max או $seconds שניות. מחזיר [הועלו, שגיאה]. */
function bdrive_sync(int $max = 10, int $seconds = 25): array {
  if (!bdrive_ready() || !bdrive_on()) return [0, ''];
  $t0 = time(); $n = 0; $err = '';
  $rows = db()->query('SELECT * FROM budget_docs WHERE drive_at IS NULL ORDER BY id LIMIT ' . (int)$max)->fetchAll();
  foreach ($rows as $d) {
    if (time() - $t0 > $seconds) break;
    try { $id = bdrive_upload_doc($d); db()->prepare('UPDATE budget_docs SET drive_id = ?, drive_at = ?, drive_err = ? WHERE id = ?')->execute([$id, now(), '', $d['id']]); $n++; }
    catch (Throwable $t) { $err = mb_substr($t->getMessage(), 0, 190); db()->prepare('UPDATE budget_docs SET drive_err = ? WHERE id = ?')->execute([$err, $d['id']]); break; }
  }
  if ($err !== '') setting_set('bdrive_last_err', $err, 'system'); elseif ($n) setting_set('bdrive_last_err', '', 'system');
  if ($n) setting_set('bdrive_last_ok', now(), 'system');
  return [$n, $err];
}
/** מיד אחרי שמירת מסמך: גיבוי לדרייב ברקע, אחרי שהתשובה כבר נשלחה למשתמש. אם נכשל, ה-cron משלים. */
function bdrive_after(): void {
  static $on = false; if ($on || !bdrive_ready() || !bdrive_on()) return; $on = true;
  register_shutdown_function(function () { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); try { bdrive_sync(5, 20); } catch (Throwable $t) {} });
}
/** הפעלה עצלה מדפי התקציב (לכל היותר פעם בכמה דקות, מעט מסמכים). */
function bdrive_lazy(): void {
  if (!bdrive_ready() || !bdrive_on()) return; $k = setting_get('bdrive_last_run', ''); if ($k !== '' && time() - (int)$k < 120) return;
  if (!bdrive_pending()) return; setting_set('bdrive_last_run', (string)time(), 'system'); try { bdrive_sync(3, 12); } catch (Throwable $t) {}
}
