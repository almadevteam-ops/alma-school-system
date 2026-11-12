<?php
// נקרא מ-autodeploy.sh: שולח מייל על כל פריסה אוטומטית (הצלחה או כשלון), כדי שפריסה שלא ביקשתם לא תעבור בשקט.
//   php deploy_notify.php ok COMMIT "נושא ה-commit"   |   php deploy_notify.php fail "הודעת שגיאה"
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
try {
  if (setting_get('deploy_notify', '1') === '0') exit(0);
  $kind = $argv[1] ?? ''; // הנמענים: מנהלות המערכת הפעילות (כתובת המייל של קודי הכניסה שלהן); אם אין, נמעני ההתראות של המערכת
  $to = array_values(array_unique(array_filter(array_map('trim', db()->query("SELECT mfa_email FROM users WHERE role = 'admin' AND active = 1 AND mfa_email <> ''")->fetchAll(PDO::FETCH_COLUMN)), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL))));
  if (!$to) $to = array_values(array_unique(array_filter(array_column(notify_recipients(), 'email')))); if (!$to) exit(0);
  $ver = is_file(PRIV . '/VERSION') ? trim((string)file_get_contents(PRIV . '/VERSION')) : '?'; $when = date('d/m/Y H:i');
  if ($kind === 'ok') {
    $subject = 'עלמא – נפרסה גרסה חדשה'; $body = "בוצעה פריסה אוטומטית ב-$when.\nגרסה: $ver\nשינוי: " . ($argv[3] ?? '') . " (" . ($argv[2] ?? '') . ")\n"
      . (migrations_pending() ? "\nשימו לב: יש עדכון מסד נתונים ממתין. יש ללחוץ \"עדכון מסד נתונים\" במערכת.\n" : '')
      . "\nאם לא ביקשתם את השינוי הזה, יש לפנות מיד לבדיקה (GitHub ו-cPanel).";
  } elseif ($kind === 'fail') {
    $msg = (string)($argv[2] ?? ''); $h = md5($msg); $f = PRIV . '/deploy_notified';
    if (is_file($f) && trim((string)file_get_contents($f)) === $h) exit(0);      // אותה שגיאה כבר דווחה
    file_put_contents($f, $h); $subject = 'עלמא – הפריסה האוטומטית נכשלה'; $body = "הפריסה האוטומטית נכשלה ב-$when:\n$msg\n\nהמערכת ממשיכה לעבוד בגרסה הקודמת ($ver). פרטים בקובץ autodeploy.log ובדף \"בדיקת מערכת\".";
  } else exit(0);
  if ($kind === 'ok') @unlink(PRIV . '/deploy_notified');
  foreach ($to as $e) { try { send_mail($e, $subject, $body); } catch (Throwable $t) {} }
} catch (Throwable $t) { fwrite(STDERR, $t->getMessage() . "\n"); }
