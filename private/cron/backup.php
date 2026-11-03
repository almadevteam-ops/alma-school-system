<?php
// מריצים מ-cron של cPanel (פעם ביום), למשל:
//   /usr/local/bin/php /home/USER/private/cron/backup.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/backup.php';
[$ok, $msg] = bk_run();
echo ($ok ? 'OK: ' : 'ERROR: ') . $msg . "\n";
exit($ok ? 0 : 1);
