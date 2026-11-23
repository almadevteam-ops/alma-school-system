<?php
// מריצים מ-cPanel (למשל כל 10 דקות): */10 * * * * /usr/local/bin/php /home/USER/private/cron/reminders.php
// (אם כבר מוגדר cron של הגיבוי, אין צורך – הוא מריץ גם את התזכורות.)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
echo date('Y-m-d H:i:s') . ' נשלחו ' . rem_cycle() . " תזכורות\n";
