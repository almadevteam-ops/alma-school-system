<?php
// שחזור לתיקייה זמנית (לא דורס כלום): php restore.php /path/alma-backup-XXXX.almb /path/outdir
// בתיקייה ייווצרו db.sql.gz ו-storage/*.bin. טעינת הדאמפ למסד נעשית ידנית (phpMyAdmin / mysql).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/backup.php';
if ($argc < 3) { fwrite(STDERR, "usage: php restore.php BACKUP.almb OUTDIR\n"); exit(2); }
try { $l = bk_extract($argv[1], $argv[2]); echo 'OK: ' . count($l) . " entries extracted to {$argv[2]}\n"; }
catch (Throwable $t) { fwrite(STDERR, 'ERROR: ' . $t->getMessage() . "\n"); exit(1); }
