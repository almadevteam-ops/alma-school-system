<?php
// כלי שחזור (CLI בלבד). לא נוגע בשום דבר בלי הוראה מפורשת.
//   php restore.php extract BACKUP.almb OUTDIR        – פירוק קובץ אחד לתיקיה (גם בצורה הישנה: php restore.php BACKUP.almb OUTDIR)
//   php restore.php list --src=DIR                    – מה יש בתיקיה ומה הרצף שניתן לשחזר
//   php restore.php chain --src=DIR --db=NAME [--db-user=U --db-pass=P --db-host=H] [--to="YYYY-mm-dd HH:MM"] --storage=OUTDIR [--wipe] [--yes-live]
//       משחזר גיבוי מלא + דלתאות עד הזמן המבוקש (ברירת מחדל: הכי עדכני) לבסיס נתונים נפרד ולתיקיית storage.
//       בסיס הנתונים החי של המערכת דורש גם --yes-live.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
$a = array_slice($argv, 1); $opt = []; $pos = [];
foreach ($a as $x) { if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $x, $m)) $opt[$m[1]] = $m[2] ?? '1'; else $pos[] = $x; }
$cmd = $pos[0] ?? ''; $fail = function (string $m, int $c = 1) { fwrite(STDERR, "ERROR: $m\n"); exit($c); };
try {
  if ($cmd !== 'extract' && $cmd !== 'list' && $cmd !== 'chain' && count($pos) === 2) { $pos = ['extract', $pos[0], $pos[1]]; $cmd = 'extract'; }
  if ($cmd === 'extract') {
    if (count($pos) < 3) $fail('usage: php restore.php extract BACKUP.almb OUTDIR', 2);
    $l = bk_extract($pos[1], $pos[2]); echo 'OK: ' . count($l) . " entries extracted to {$pos[2]}\n"; exit(0);
  }
  $src = rtrim($opt['src'] ?? '', '/'); if ($src === '' || !is_dir($src)) $fail('--src=DIR חסר או לא קיים', 2);
  if ($cmd === 'list') {
    foreach (array_merge(glob($src . '/alma-backup-*.almb') ?: [], glob($src . '/alma-delta-*.almb') ?: []) as $f) $rows[bk_stamp(basename($f)) . basename($f)] = basename($f) . '  ' . round(filesize($f) / 1024) . 'KB';
    ksort($rows); echo implode("\n", $rows ?? []) . "\n";
    $c = bk_chain($src); echo "\nרצף לשחזור: " . count($c['files']) . ' קבצים, האחרון: ' . end($c['files']) . "\n" . ($c['warn'] ? 'אזהרה: ' . $c['warn'] . "\n" : ''); exit(0);
  }
  if ($cmd === 'chain') {
    $db = $opt['db'] ?? ''; $storage = $opt['storage'] ?? ''; if ($db === '' || $storage === '') $fail('חובה --db ו---storage', 2);
    $host = $opt['db-host'] ?? cfg('db_host', 'localhost'); $user = $opt['db-user'] ?? cfg('db_user'); $pass = $opt['db-pass'] ?? cfg('db_pass');
    $live = ($db === cfg('db_name') && $host === cfg('db_host', 'localhost')); if ($live && !isset($opt['yes-live'])) $fail('זה בסיס הנתונים החי של המערכת. אם זו כוונתכם הוסיפו --yes-live (וגם --wipe).', 2);
    if (realpath($storage) !== false && realpath($storage) === realpath(PRIV . '/storage') && !isset($opt['yes-live'])) $fail('זו תיקיית storage החיה. הוסיפו --yes-live אם זו כוונתכם.', 2);
    $to = isset($opt['to']) ? strtotime($opt['to'] . ((strlen($opt['to']) <= 16) ? ':59' : '')) : null; if (isset($opt['to']) && !$to) $fail('פורמט --to לא תקין', 2);
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
    $r = bk_restore($src, $pdo, $storage, $to, isset($opt['wipe']));
    echo implode("\n", $r['log']) . "\nOK: שוחזר עד " . $r['last'] . "\n"; exit($r['warn'] ? 3 : 0);
  }
  $fail("פקודה לא מוכרת. ראו את ההערות בראש הקובץ.", 2);
} catch (Throwable $t) { $fail($t->getMessage()); }
