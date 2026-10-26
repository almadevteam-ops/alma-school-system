<?php
declare(strict_types=1);
// אימות דו-שלבי לפי RFC 6238 (Google Authenticator / Microsoft Authenticator וכו')
function b32enc(string $b): string {
  $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $o = '';
  foreach (str_split($b) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
  foreach (str_split($bits, 5) as $ch) $o .= $a[bindec(str_pad($ch, 5, '0'))];
  return $o;
}
function b32dec(string $s): string {
  $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $o = '';
  foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) { $p = strpos($a, $c); if ($p !== false) $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
  foreach (str_split($bits, 8) as $by) if (strlen($by) === 8) $o .= chr(bindec($by));
  return $o;
}
function totp_at(string $secret, int $step, int $digits = 6): string {
  $h = hash_hmac('sha1', pack('J', $step), $secret, true);
  $o = ord($h[19]) & 15;
  $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
  return str_pad((string)($v % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}
// מחזיר את ה-step שאושר (או null). מונע שימוש חוזר באותו קוד.
function totp_verify(string $secret, string $code, int $lastStep, ?int $now = null): ?int {
  $code = preg_replace('/\D/', '', $code);
  if (strlen($code) !== 6) return null;
  $cur = intdiv($now ?? time(), 30);
  for ($d = -1; $d <= 1; $d++) {
    $s = $cur + $d;
    if ($s > $lastStep && hash_equals(totp_at($secret, $s), $code)) return $s;
  }
  return null;
}
function recovery_hash(string $code): string { return hash_hmac('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)), master_key()); }
function make_recovery_codes(int $uid): array {
  db()->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$uid]);
  $codes = [];
  for ($i = 0; $i < 8; $i++) {
    $raw = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    db()->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?,?)')->execute([$uid, recovery_hash($raw)]);
  }
  return $codes;
}
