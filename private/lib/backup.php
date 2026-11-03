<?php
declare(strict_types=1);
// גיבוי: קובץ אחד מוצפן (AES-256-GCM במנות) שמכיל את מסד הנתונים (SQL דחוס) ואת כל הקבצים המוצפנים של private/storage.
// הפענוח דורש את private/app.key – חובה לשמור אותו בנפרד (מחוץ לשרת)!
const BK_MAGIC = 'ALMB1';
const BK_CHUNK = 1048576;
const BK_KEEP_DAILY = 14;
const BK_KEEP_WEEKLY = 8;

function bk_dir(): string {
  $d = PRIV . '/backups';
  if (!is_dir($d)) { @mkdir($d, 0700, true); }
  if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n");
  return $d;
}
function bk_status_file(): string { return bk_dir() . '/last.json'; }
function bk_status(): array {
  $f = bk_status_file();
  $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
  return is_array($j) ? $j : [];
}
function bk_list(): array {
  $out = [];
  foreach (glob(bk_dir() . '/alma-backup-*.almb') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
  usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
  return $out;
}

/** כותב זרם מוצפן במנות. */
final class BkWriter {
  private $fh; private string $buf = ''; private int $i = 0; private string $key;
  public function __construct(string $path) {
    $this->fh = fopen($path, 'wb'); if (!$this->fh) throw new RuntimeException('cannot open backup file');
    @chmod($path, 0600);
    fwrite($this->fh, BK_MAGIC);
    $this->key = derive_key('backup');
  }
  private function flushChunk(string $plain): void {
    $iv = random_bytes(12); $aad = 'bk:' . $this->i++;
    $c = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if ($c === false) throw new RuntimeException('encrypt failed');
    fwrite($this->fh, pack('N', strlen($c)) . $iv . $tag . $c);
  }
  public function write(string $data): void {
    $this->buf .= $data;
    while (strlen($this->buf) >= BK_CHUNK) { $this->flushChunk(substr($this->buf, 0, BK_CHUNK)); $this->buf = substr($this->buf, BK_CHUNK); }
  }
  /** מוסיף קובץ מהדיסק כרשומה: שם, גודל, תוכן, sha256. */
  public function addFile(string $name, string $path): void {
    $size = (int)filesize($path);
    $this->write(pack('n', strlen($name)) . $name . pack('J', $size));
    $in = fopen($path, 'rb'); $h = hash_init('sha256'); $left = $size;
    while ($left > 0) { $d = fread($in, min(262144, $left)); if ($d === false || $d === '') throw new RuntimeException('read failed: ' . $name); $left -= strlen($d); hash_update($h, $d); $this->write($d); }
    fclose($in);
    $this->write(hash_final($h, true));
  }
  public function close(): void {
    $this->write(pack('n', 0));
    if ($this->buf !== '') $this->flushChunk($this->buf);
    $this->buf = ''; fclose($this->fh);
  }
}

/** קורא וקובע שלמות. $onEntry(string $name, string $tmpPathOrNull) נקרא לכל רשומה כשהיא נכתבת לקובץ זמני. מחזיר רשימת [שם, גודל]. */
function bk_read(string $path, ?callable $onEntry = null): array {
  $in = fopen($path, 'rb'); if (!$in) throw new RuntimeException('cannot open');
  if (fread($in, strlen(BK_MAGIC)) !== BK_MAGIC) throw new RuntimeException('not a backup file');
  $key = derive_key('backup'); $i = 0; $buf = ''; $eof = false;
  $need = function (int $n) use (&$buf, &$eof, &$i, $in, $key): string {
    while (strlen($buf) < $n && !$eof) {
      $hd = fread($in, 4);
      if ($hd === false || $hd === '') { $eof = true; break; }
      if (strlen($hd) < 4) throw new RuntimeException('truncated');
      $len = unpack('N', $hd)[1]; $rest = fread($in, 28 + $len);
      if ($rest === false || strlen($rest) < 28 + $len) throw new RuntimeException('truncated');
      $p = openssl_decrypt(substr($rest, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($rest, 0, 12), substr($rest, 12, 16), 'bk:' . $i++);
      if ($p === false) throw new RuntimeException('decrypt failed (wrong key or corrupted)');
      $buf .= $p;
    }
    if (strlen($buf) < $n) throw new RuntimeException('truncated');
    $r = substr($buf, 0, $n); $buf = substr($buf, $n); return $r;
  };
  $list = []; $tmp = bk_dir() . '/tmp-read-' . bin2hex(random_bytes(4));
  try {
    while (true) {
      $nl = unpack('n', $need(2))[1];
      if ($nl === 0) break;
      $name = $need($nl); $size = unpack('J', $need(8))[1];
      $o = fopen($tmp, 'wb'); $h = hash_init('sha256'); $left = $size;
      while ($left > 0) { $d = $need(min(262144, $left)); $left -= strlen($d); hash_update($h, $d); fwrite($o, $d); }
      fclose($o);
      if (!hash_equals(hash_final($h, true), $need(32))) throw new RuntimeException('checksum mismatch: ' . $name);
      if ($onEntry) $onEntry($name, $tmp);
      $list[] = [$name, $size];
    }
  } finally { fclose($in); @unlink($tmp); }
  return $list;
}

function bk_pdo(): PDO {
  return new PDO('mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4', cfg('db_user'), cfg('db_pass'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
  ]);
}
/** דוגם את כל המסד לקובץ gz. מחזיר מספר שורות. */
function bk_dump_db(string $gzPath): int {
  $pdo = bk_pdo(); $gz = gzopen($gzPath, 'wb6'); if (!$gz) throw new RuntimeException('cannot write dump');
  gzwrite($gz, "-- Alma backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
  $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
  $rows = 0;
  foreach ($tables as [$t]) {
    $q = '`' . str_replace('`', '``', (string)$t) . '`';
    $cr = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
    gzwrite($gz, "DROP TABLE IF EXISTS $q;\n" . $cr[1] . ";\n\n");
    $st = $pdo->query('SELECT * FROM ' . $q); $batch = [];
    $flush = function () use (&$batch, $gz, $q) { if ($batch) { gzwrite($gz, "INSERT INTO $q VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; } };
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
      $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $pdo->quote((string)$v)), $r)) . ')';
      $rows++; if (count($batch) >= 200) $flush();
    }
    $flush(); $st->closeCursor(); gzwrite($gz, "\n");
  }
  gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n"); gzclose($gz);
  return $rows;
}
function bk_prune(): int {
  $all = bk_list(); $keep = [];
  foreach (array_slice($all, 0, BK_KEEP_DAILY) as $b) $keep[$b['name']] = 1;
  $w = 0;
  foreach ($all as $b) { // שבועי: הגיבוי הראשון של כל שבוע (לפי תאריך בשם)
    if ($w >= BK_KEEP_WEEKLY) break;
    if (preg_match('/alma-backup-(\d{8})-/', $b['name'], $m) && date('w', strtotime($m[1])) === '0') { $keep[$b['name']] = 1; $w++; }
  }
  $del = 0;
  foreach ($all as $b) if (empty($keep[$b['name']])) { @unlink(bk_dir() . '/' . $b['name']); $del++; }
  return $del;
}
/** מריץ גיבוי מלא. מחזיר [ok, הודעה]. */
function bk_run(): array {
  $dir = bk_dir(); $lock = fopen($dir . '/.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return [false, 'גיבוי אחר רץ כרגע.'];
  @set_time_limit(0); $t0 = microtime(true); $tmpDump = $dir . '/tmp-dump-' . bin2hex(random_bytes(4)) . '.sql.gz';
  $name = 'alma-backup-' . date('Ymd-His') . '.almb'; $final = $dir . '/' . $name; $part = $final . '.part';
  try {
    $rows = bk_dump_db($tmpDump);
    $w = new BkWriter($part);
    $w->addFile('db.sql.gz', $tmpDump);
    $nf = 0;
    foreach (glob(PRIV . '/storage/*.bin') ?: [] as $f) { if (!preg_match('/^[0-9a-f]{32}\.bin$/', basename($f))) continue; $w->addFile('storage/' . basename($f), $f); $nf++; }
    $w->close();
    $names = []; $listed = bk_read($part, function ($n) use (&$names) { $names[] = $n; }); // אימות: פענוח מלא + checksum
    if (count($listed) !== $nf + 1) throw new RuntimeException('verify: entry count mismatch');
    rename($part, $final); @unlink($tmpDump);
    $pruned = bk_prune();
    $st = ['ok' => true, 'time' => time(), 'file' => $name, 'size' => (int)filesize($final), 'rows' => $rows, 'files' => $nf, 'secs' => round(microtime(true) - $t0, 1), 'pruned' => $pruned];
    file_put_contents(bk_status_file(), json_encode($st));
    return [true, "הגיבוי הושלם: $name · " . round($st['size'] / 1048576, 2) . "MB · $rows שורות · $nf קבצים"];
  } catch (Throwable $t) {
    @unlink($tmpDump); @unlink($part);
    error_log('[alma-backup] ' . $t->getMessage());
    file_put_contents(bk_status_file(), json_encode(['ok' => false, 'time' => time(), 'error' => $t->getMessage()]));
    return [false, 'הגיבוי נכשל: ' . $t->getMessage()];
  } finally { flock($lock, LOCK_UN); fclose($lock); }
}
/** שחזור לתיקיה: db.sql.gz ו-storage/*.bin. */
function bk_extract(string $file, string $outDir): array {
  if (!is_dir($outDir) && !@mkdir($outDir, 0700, true)) throw new RuntimeException('cannot create out dir');
  @mkdir($outDir . '/storage', 0700, true);
  return bk_read($file, function ($n, $tmp) use ($outDir) {
    if ($n !== 'db.sql.gz' && !preg_match('#^storage/[0-9a-f]{32}\.bin$#', $n)) return; // לעולם לא כותבים נתיב אחר
    copy($tmp, $outDir . '/' . $n);
  });
}
