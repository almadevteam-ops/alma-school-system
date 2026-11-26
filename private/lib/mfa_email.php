<?php
declare(strict_types=1);
// אימות דו-שלבי חלופי: קוד חד-פעמי במייל (למי שאין לה אפליקציית אימות).
const MFA_CODE_TTL = 600;      // תוקף הקוד: 10 דקות
const MFA_RESEND_GAP = 60;     // לפחות דקה בין שליחות
const MFA_MAX_TRIES = 5;       // טעויות מותרות לכל קוד

function mfa_mask(string $email): string {
  [$a, $d] = array_pad(explode('@', $email, 2), 2, '');
  return mb_substr($a, 0, 2) . '***@' . $d;
}
function mfa_code_hash(string $code): string { return hash_hmac('sha256', $code, master_key()); }
$GLOBALS['MAIL_ERR'] = '';
function mail_last_error(): string { return (string)$GLOBALS['MAIL_ERR']; }
/** בניית הודעת MIME מלאה (כולל קבצים מצורפים: [['name'=>, 'type'=>, 'data'=>]]). */
function mail_build(string $from, string $to, string $subject, string $body, array $attach = []): string {
  $h = "From: =?UTF-8?B?" . base64_encode('עלמא') . "?= <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(10)) . '@' . (string)cfg('host', 'localhost') . ">\r\nMIME-Version: 1.0\r\n";
  if (!$attach) return $h . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
  $b = 'alma_' . bin2hex(random_bytes(8));
  $m = $h . "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
  foreach ($attach as $a) { $n = '=?UTF-8?B?' . base64_encode((string)$a['name']) . '?='; $m .= "--$b\r\nContent-Type: " . ($a['type'] ?? 'application/octet-stream') . "; name=\"$n\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$n\"\r\n\r\n" . chunk_split(base64_encode((string)$a['data'])); }
  return $m . "--$b--\r\n";
}
/** שליחת SMTP פשוטה (Gmail: smtp.gmail.com, סיסמת אפליקציה). smtp_secure: ssl (465) | tls (587) | none. */
function smtp_send(string $to, string $subject, string $body, array $attach = []): bool {
  if (is_test_env()) { $GLOBALS['MAIL_ERR'] = 'חסום בסביבת בדיקות'; return false; }
  $host = (string)cfg('smtp_host'); $secure = (string)cfg('smtp_secure', 'ssl'); $port = (int)cfg('smtp_port', $secure === 'tls' ? 587 : 465);
  $user = (string)cfg('smtp_user', ''); $pass = (string)cfg('smtp_pass', ''); $from = (string)cfg('mail_from', $user);
  $fail = function (string $m) use (&$fp): bool { $GLOBALS['MAIL_ERR'] = $m; if (is_resource($fp)) @fclose($fp); return false; };
  $fp = null; $errno = 0; $errstr = '';
  $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 12);
  if (!$fp) return $fail("חיבור ל-$host:$port נכשל ($errstr)");
  stream_set_timeout($fp, 12);
  $rd = function () use (&$fp): array { $all = ''; while (($l = fgets($fp, 1024)) !== false) { $all .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return [(int)substr($all, 0, 3), trim($all)]; };
  $cmd = function (string $c, array $ok) use (&$fp, $rd): array { fwrite($fp, $c . "\r\n"); [$code, $txt] = $rd(); return [in_array($code, $ok, true), $code . ' ' . mb_substr($txt, 0, 120)]; };
  [$c, ] = $rd(); if ($c !== 220) return $fail('השרת לא ענה כראוי');
  $ehlo = $cmd('EHLO ' . (string)cfg('host', 'localhost'), [250]); if (!$ehlo[0]) return $fail('EHLO: ' . $ehlo[1]);
  if ($secure === 'tls') {
    $r = $cmd('STARTTLS', [220]); if (!$r[0]) return $fail('STARTTLS: ' . $r[1]);
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $fail('הצפנת TLS נכשלה');
    $ehlo = $cmd('EHLO ' . (string)cfg('host', 'localhost'), [250]); if (!$ehlo[0]) return $fail('EHLO: ' . $ehlo[1]);
  }
  if ($user !== '') {
    $r = $cmd('AUTH LOGIN', [334]); if (!$r[0]) return $fail('AUTH: ' . $r[1]);
    $r = $cmd(base64_encode($user), [334]); if (!$r[0]) return $fail('שם משתמש: ' . $r[1]);
    $r = $cmd(base64_encode($pass), [235]); if (!$r[0]) return $fail('ההתחברות לחשבון נכשלה (סיסמת אפליקציה שגויה?): ' . $r[1]);
  }
  $r = $cmd('MAIL FROM:<' . $from . '>', [250]); if (!$r[0]) return $fail('MAIL FROM: ' . $r[1]);
  $r = $cmd('RCPT TO:<' . $to . '>', [250, 251]); if (!$r[0]) return $fail('RCPT: ' . $r[1]);
  $r = $cmd('DATA', [354]); if (!$r[0]) return $fail('DATA: ' . $r[1]);
  $msg = mail_build($from, $to, $subject, $body, $attach);
  fwrite($fp, $msg . "\r\n.\r\n"); [$code, $txt] = $rd();
  if ($code !== 250) return $fail('השליחה נדחתה: ' . $code . ' ' . mb_substr($txt, 0, 120));
  $cmd('QUIT', [221]); @fclose($fp); return true;
}
function send_mail(string $to, string $subject, string $body, array $attach = []): bool {
  $GLOBALS['MAIL_ERR'] = '';
  if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) { $GLOBALS['MAIL_ERR'] = 'כתובת לא תקינה'; return false; }
  if (is_test_env()) {   // סביבת בדיקות: שום מייל לא יוצא. ההודעה נשמרת ונראית ב"תיבת דואר בדיקות"
    @mkdir(PRIV . '/storage', 0700, true);
    return file_put_contents(PRIV . '/storage/test_mail.log', json_encode(['at' => date('Y-m-d H:i:s'), 'to' => $to, 'subject' => $subject, 'body' => $body, 'att' => array_map(fn($a) => (string)($a['name'] ?? ''), $attach)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
  }
  if (cfg('mail_transport', 'mail') === 'file') { foreach ($attach as $a) { @mkdir(PRIV . '/storage/mail_att', 0700, true); @file_put_contents(PRIV . '/storage/mail_att/' . preg_replace('/[^\w.\-]/u', '_', $to) . '_' . preg_replace('/[^\w.\-]/', '_', (string)$a['name']), (string)$a['data']); } return file_put_contents(PRIV . '/storage/mail.log', json_encode(['to' => $to, 'subject' => $subject, 'body' => $body, 'attach' => array_map(fn($a) => $a['name'] . ' (' . strlen((string)$a['data']) . ')', $attach)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false; }
  if (cfg('smtp_host')) return smtp_send($to, $subject, $body, $attach);
  $from = (string)cfg('mail_from', 'noreply@' . (string)cfg('host', 'localhost'));
  if ($attach) { $full = mail_build($from, $to, $subject, $body, $attach); [$hd, $bd] = explode("\r\n\r\n", $full, 2); $hd = preg_replace('/^(To|Subject): .*\r\n/m', '', $hd . "\r\n"); $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $bd, rtrim($hd), '-f' . $from); if (!$ok) $GLOBALS['MAIL_ERR'] = 'שרת הדואר של האחסון סירב.'; return $ok; }
  $h = "From: עלמא <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nX-Auto-Response-Suppress: All";
  $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h, '-f' . $from);
  if (!$ok) $GLOBALS['MAIL_ERR'] = 'שרת הדואר של האחסון סירב.';
  return $ok;
}
/** שולחת קוד חדש. מחזירה [ok, הודעה]. */
function mfa_send_code(array $row, bool $force = false): array {
  $uid = (int)$row['id']; $to = (string)$row['mfa_email'];
  if ($to === '') return [false, 'לא הוגדרה כתובת מייל לחשבון. פני למנהלת.'];
  if (!$force && !empty($row['mfa_code_sent']) && time() - strtotime((string)$row['mfa_code_sent']) < MFA_RESEND_GAP) return [false, 'קוד נשלח זה עתה. אפשר לבקש חדש בעוד דקה.'];
  $day = db()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ? AND created_at >= ?'); $day->execute(['mfa_email_sent', (string)$uid, date('Y-m-d H:i:s', time() - 3600)]);
  if ((int)$day->fetchColumn() >= 8) return [false, 'נשלחו יותר מדי קודים בשעה האחרונה. נסי שוב מאוחר יותר.'];
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  db()->prepare('UPDATE users SET mfa_code_hash = ?, mfa_code_exp = ?, mfa_code_tries = 0, mfa_code_sent = ? WHERE id = ?')->execute([mfa_code_hash($code), date('Y-m-d H:i:s', time() + MFA_CODE_TTL), now(), $uid]);
  $ok = send_mail($to, 'קוד כניסה למערכת עלמא', "קוד הכניסה שלך: $code\n\nהקוד תקף ל-10 דקות ולשימוש חד-פעמי.\nאם לא ניסית להיכנס למערכת, אל תמסרי את הקוד לאף אחד והודיעי למנהלת.");
  db()->prepare('INSERT INTO audit_log (created_at, user_id, username, action, entity, entity_id, detail, ip) VALUES (?,?,?,?,?,?,?,?)')->execute([now(), $uid, (string)$row['username'], $ok ? 'mfa_email_sent' : 'mfa_email_failed', 'user', (string)$uid, mfa_mask($to), client_ip()]);
  return $ok ? [true, ''] : [false, 'שליחת המייל נכשלה. פני למנהלת.'];
}
function mfa_check_code(int $uid, string $code): bool {
  $code = preg_replace('/\D/', '', $code);
  if (strlen($code) !== 6) return false;
  $st = db()->prepare('SELECT mfa_code_hash, mfa_code_exp, mfa_code_tries FROM users WHERE id = ?'); $st->execute([$uid]); $r = $st->fetch();
  if (!$r || !$r['mfa_code_hash'] || !$r['mfa_code_exp'] || strtotime((string)$r['mfa_code_exp']) < time() || (int)$r['mfa_code_tries'] >= MFA_MAX_TRIES) return false;
  if (hash_equals((string)$r['mfa_code_hash'], mfa_code_hash($code))) {
    $u = db()->prepare('UPDATE users SET mfa_code_hash = NULL, mfa_code_exp = NULL WHERE id = ? AND mfa_code_hash = ?'); $u->execute([$uid, $r['mfa_code_hash']]);
    return $u->rowCount() === 1;
  }
  db()->prepare('UPDATE users SET mfa_code_tries = mfa_code_tries + 1 WHERE id = ?')->execute([$uid]);
  return false;
}
