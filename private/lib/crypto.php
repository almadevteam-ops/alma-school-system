<?php
declare(strict_types=1);
// הצפנה: AES-256-GCM. מפתח ראשי: private/app.key (נוצר אוטומטית פעם אחת, מחוץ לאתר) או app_key ב-config.php.
// חשוב: לגבות את app.key! בלעדיו אי אפשר לפענח קבצים ששמורים.

function master_key(): string {
  static $k = null;
  if ($k !== null) return $k;
  $c = cfg('app_key');
  if (is_string($c) && $c !== '') {
    $raw = base64_decode($c, true);
    if ($raw !== false && strlen($raw) >= 32) return $k = substr($raw, 0, 32);
  }
  $f = PRIV . '/app.key';
  if (!is_file($f)) {
    $tmp = $f . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, base64_encode(random_bytes(32)), LOCK_EX) === false) throw new RuntimeException('cannot write app.key');
    @chmod($tmp, 0600);
    @link($tmp, $f);          // יצירה אטומית: אם כבר קיים – לא דורס
    @unlink($tmp);
  }
  $raw = base64_decode(trim((string)file_get_contents($f)), true);
  if ($raw === false || strlen($raw) < 32) throw new RuntimeException('bad app.key');
  return $k = substr($raw, 0, 32);
}
function derive_key(string $ctx): string { return hash_hkdf('sha256', master_key(), 32, $ctx); }

function enc_bytes(string $plain, string $ctx): string {
  $iv = random_bytes(12);
  $c = openssl_encrypt($plain, 'aes-256-gcm', derive_key($ctx), OPENSSL_RAW_DATA, $iv, $tag, $ctx, 16);
  if ($c === false) throw new RuntimeException('encrypt failed');
  return 'ALM1' . $iv . $tag . $c;
}
function dec_bytes(string $blob, string $ctx): ?string {
  if (strlen($blob) < 32 || substr($blob, 0, 4) !== 'ALM1') return null;
  $p = openssl_decrypt(substr($blob, 32), 'aes-256-gcm', derive_key($ctx), OPENSSL_RAW_DATA, substr($blob, 4, 12), substr($blob, 16, 16), $ctx);
  return $p === false ? null : $p;
}
function enc_text(string $s, string $ctx): string { return base64_encode(enc_bytes($s, $ctx)); }
function dec_text(string $s, string $ctx): ?string { $b = base64_decode($s, true); return $b === false ? null : dec_bytes($b, $ctx); }
