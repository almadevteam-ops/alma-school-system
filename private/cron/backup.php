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
try { $dn = doc_process(40); if ($dn) echo date('Y-m-d H:i:s') . " נשלחו $dn מסמכים מהתור\n"; } catch (Throwable $t) { fwrite(STDERR, "docs: " . $t->getMessage() . "\n"); }
try { [$dn2, $de2] = bdrive_sync(40, 45); if ($dn2) echo date('Y-m-d H:i:s') . " גובו $dn2 חשבוניות לדרייב\n"; if ($de2) fwrite(STDERR, "bdrive: $de2\n"); } catch (Throwable $t) { fwrite(STDERR, "bdrive: " . $t->getMessage() . "\n"); }
try { $bn = bpay_remind_cycle(); if ($bn) echo date('Y-m-d H:i:s') . " נשלחה תזכורת על $bn חשבוניות לתשלום\n"; } catch (Throwable $t) { fwrite(STDERR, "bpay reminders: " . $t->getMessage() . "\n"); }
try { $rn = rem_cycle(); if ($rn) echo date('Y-m-d H:i:s') . " נשלחו $rn תזכורות גבייה\n"; } catch (Throwable $t) { fwrite(STDERR, "reminders: " . $t->getMessage() . "\n"); }
exit($ok ? 0 : 1);
