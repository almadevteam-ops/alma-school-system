<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
if (user()) redirect('students.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $un = mb_substr(trim((string)($_POST['u'] ?? '')), 0, 60);
  $pw = (string)($_POST['p'] ?? '');
  $ip = client_ip();
  $since = date('Y-m-d H:i:s', time() - 900);
  $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ok = 0 AND created_at >= ? AND (ip = ? OR username = ?)');
  $st->execute([$since, $ip, $un]);
  if ((int)$st->fetchColumn() >= 5) {
    $err = 'יותר מדי ניסיונות כושלים. נסו שוב בעוד 15 דקות.';
  } else {
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$un]);
    $row = $st->fetch();
    $hash = $row['pass_hash'] ?? '$2y$10$CvqaYFViXNcSszkwddqK1uDHOLcV8pC17BdXkObnOzv3LwIJ/uZ2G'; // השוואה מדומה – מונע גילוי שמות משתמש
    $ok = password_verify($pw, $hash) && $row && (int)$row['active'] === 1;
    db()->prepare('INSERT INTO login_attempts (username, ip, ok, created_at) VALUES (?,?,?,?)')->execute([$un, $ip, $ok ? 1 : 0, now()]);
    if ($ok) {
      if (password_needs_rehash($row['pass_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
        db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([hash_pw($pw), $row['id']]);
      }
      session_regenerate_id(true);
      $_SESSION['uid'] = (int)$row['id'];
      $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'name' => $row['display_name'], 'role' => $row['role']];
      $_SESSION['pwv'] = (int)$row['pw_ver'];
      $_SESSION['t0'] = $_SESSION['last'] = time();
      $_SESSION['ua'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
      $_SESSION['csrf'] = bin2hex(random_bytes(32));
      db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now(), $row['id']]);
      audit('login', 'user', (string)$row['id']);
      redirect('students.php');
    }
    $err = 'שם משתמש או סיסמה שגויים.';
  }
}
page_head('כניסה', false);
?>
<div class="card login">
  <h1>כניסה למערכת</h1>
  <?php if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <p><label>שם משתמש<br><input name="u" required autofocus style="width:100%"></label></p>
    <p><label>סיסמה<br><input name="p" type="password" required style="width:100%"></label></p>
    <button>כניסה</button>
  </form>
</div>
<?php page_foot();
