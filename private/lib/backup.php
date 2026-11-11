<?php
declare(strict_types=1);
// גיבוי: קובץ אחד מוצפן (AES-256-GCM במנות) שמכיל את מסד הנתונים (SQL דחוס) ואת כל הקבצים המוצפנים של private/storage.
// הפענוח דורש את private/app.key – חובה לשמור אותו בנפרד (מחוץ לשרת)!
const BK_MAGIC = 'ALMB1';
const BK_CHUNK = 1048576;
const BK_KEEP_DAILY = 7;     // גיבויים מלאים יומיים בשרת
const BK_KEEP_WEEKLY = 4;    // ועוד מלאים של ימי ראשון
const BK_DELTA_DAYS = 3;     // דלתאות נשמרות 3 ימים

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

/** כל הקבצים (מלאים ודלתאות), מהישן לחדש. */
function bk_stamp(string $name): string { return preg_match('/(\d{8}-\d{6})/', $name, $m) ? $m[1] : ''; }
function bk_list_all(): array {
  $out = [];
  foreach (array_merge(glob(bk_dir() . '/alma-backup-*.almb') ?: [], glob(bk_dir() . '/alma-delta-*.almb') ?: []) as $f) { $n = basename($f); $out[] = ['name' => $n, 'type' => strpos($n, 'alma-delta-') === 0 ? 'delta' : 'full', 'size' => (int)filesize($f), 'time' => (int)filemtime($f)]; }
  usort($out, fn($a, $b) => strcmp(bk_stamp($a['name']), bk_stamp($b['name'])));
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
  public function addString(string $name, string $content): void {
    $this->write(pack('n', strlen($name)) . $name . pack('J', strlen($content)) . $content . hash('sha256', $content, true));
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
/** שחזור לתיקיה: db.sql.gz ו-storage/*.bin. */
function bk_extract(string $file, string $outDir): array {
  if (!is_dir($outDir) && !@mkdir($outDir, 0700, true)) throw new RuntimeException('cannot create out dir');
  @mkdir($outDir . '/storage', 0700, true);
  return bk_read($file, function ($n, $tmp) use ($outDir) {
    if (!in_array($n, ['db.sql.gz', 'delta.sql.gz', 'meta.json'], true) && !preg_match('#^storage/[0-9a-f]{32}\.bin$#', $n)) return; // לעולם לא כותבים נתיב אחר
    copy($tmp, $outDir . '/' . $n);
  });
}
