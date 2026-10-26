<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
if (user()) redirect('students.php');
$p = $_SESSION['p2'] ?? null;
if (!$p || time() - (int)$p['t'] > 300) { unset($_SESSION['p2']); redirect('login.php'); }
$uid = (int)$p['id'];
$key = 'p2:' . $uid;
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ok = 0 AND created_at >= ? AND (username = ? OR ip = ?)');
  $st->execute([date('Y-m-d H:i:s', time() - 900), $key, client_ip()]);
  if ((int)$st->fetchColumn() >= 5) {
    $err = 'יותר מדי ניסיונות. נסו שוב בעוד 15 דקות.';
  } else {
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $st->execute([$uid]);
    $row = $st->fetch();
    $code = trim((string)($_POST['code'] ?? ''));
    $ok = false;
    if ($row && $row['totp_secret']) {
      $secret = dec_text((string)$row['totp_secret'], 'totp:' . $uid);
      $step = $secret !== null ? totp_verify($secret, $code, (int)$row['totp_last_step']) : null;
      if ($step !== null) {
        $u2 = db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?');
        $u2->execute([$step, $uid, $step]);
        $ok = $u2->rowCount() === 1;
      } elseif (strlen(preg_replace('/[^A-Za-z0-9]/', '', $code)) === 8) {
        $u2 = db()->prepare('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
        $u2->execute([now(), $uid, recovery_hash($code)]);
        if ($u2->rowCount() === 1) { $ok = true; $_SESSION['user'] = ['id' => $uid, 'username' => $row['username']]; audit('recovery_code_used', 'user', (string)$uid); unset($_SESSION['user']); }
      }
    }
    db()->prepare('INSERT INTO login_attempts (username, ip, ok, created_at) VALUES (?,?,?,?)')->execute([$key, client_ip(), $ok ? 1 : 0, now()]);
    if ($ok) finish_login($row);
    $err = 'הקוד שגוי.';
  }
}
page_head('אימות דו-שלבי', false);
?>
<div class="card login">
  <h1>אימות דו-שלבי</h1>
  <p class="mu">הכניסו את הקוד בן 6 הספרות מאפליקציית האימות בטלפון. אם אין לכם גישה לטלפון, הכניסו קוד שחזור (8 תווים).</p>
  <?php if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <p><input name="code" required autofocus inputmode="text" autocomplete="one-time-code" style="width:100%;font-size:22px;text-align:center;letter-spacing:4px" maxlength="12"></p>
    <button>אישור</button> <a class="btn sec" href="login.php">חזרה</a>
  </form>
</div>
<?php page_foot();
