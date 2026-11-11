<?php
// מריצים מ-cPanel כל 10 דקות:
//   */10 * * * * /usr/local/bin/php /home/USER/private/cron/backup.php
// הסקריפט מחליט לבד: פעם ביום (אחרי השעה bk_full_hour, ברירת מחדל 02:00) גיבוי מלא, בשאר הריצות דלתא (רק שינויים).
// אפשר גם: --full (מלא עכשיו), --delta, --drive (רק סנכרון לדרייב).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
$force = in_array('--full', $argv, true) ? 'full' : (in_array('--delta', $argv, true) ? 'delta' : (in_array('--drive', $argv, true) ? 'drive' : ''));
[$ok, $lines] = bk_cycle($force);
foreach ($lines as $l) echo date('Y-m-d H:i:s') . ' ' . $l . "\n";
if (!$ok) fwrite(STDERR, "ERROR: backup cycle reported a failure\n");
exit($ok ? 0 : 1);
