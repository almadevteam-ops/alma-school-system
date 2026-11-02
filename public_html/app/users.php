<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$err = ''; $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'create') {
    $un = trim((string)($_POST['username'] ?? '')); $nm = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    $role = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'secretary'; $pw = (string)($_POST['pw'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $un)) $err = 'שם משתמש: 3–60 תווים באנגלית, ספרות או . _ @ -';
    elseif ($nm === '') $err = 'נא למלא שם תצוגה.';
    elseif ($e = pw_ok($pw)) $err = $e;
    elseif (($_POST['method'] ?? '') === 'email' && !filter_var(trim((string)($_POST['mail'] ?? '')), FILTER_VALIDATE_EMAIL)) $err = 'לאימות במייל נדרשת כתובת מייל תקינה.';
    else {
      $byMail = ($_POST['method'] ?? '') === 'email'; $mail = $byMail ? mb_substr(trim((string)$_POST['mail']), 0, 150) : '';
      try {
        db()->prepare('INSERT INTO users (username, display_name, pass_hash, role, created_at, mfa_method, mfa_email, totp_enabled) VALUES (?,?,?,?,?,?,?,?)')->execute([$un, $nm, hash_pw($pw), $role, now(), $byMail ? 'email' : 'app', $mail, $byMail ? 1 : 0]);
        audit('user_create', 'user', $un, 'תפקיד: ' . $role . ', אימות: ' . ($byMail ? 'מייל' : 'אפליקציה')); $msg = 'המשתמשת נוצרה.' . ($byMail ? ' בכניסה היא תקבל קוד במייל.' : ' בכניסה הראשונה היא תגדיר אפליקציית אימות.');
      } catch (PDOException $x) { $err = 'שם המשתמש כבר קיים.'; }
    }
  } elseif ($act === 'toggle' || $act === 'reset' || $act === 'role' || $act === 'reset2fa' || $act === 'mfa') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid === $me['id'] && $act !== 'reset') { $err = 'אי אפשר לשנות את המשתמשת שלך.'; }
    elseif ($act === 'mfa') {
      $method = ($_POST['method'] ?? '') === 'email' ? 'email' : 'app'; $mail = mb_substr(trim((string)($_POST['mail'] ?? '')), 0, 150);
      if ($method === 'email' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $err = 'נא להזין כתובת מייל תקינה.';
      elseif ($method === 'email') {
        db()->prepare("UPDATE users SET mfa_method = 'email', mfa_email = ?, totp_enabled = 1, mfa_code_hash = NULL, pw_ver = pw_ver + 1 WHERE id = ?")->execute([$mail, $uid]);
        audit('mfa_method', 'user', (string)$uid, 'email'); $msg = 'האימות הוחלף לקוד במייל. המשתמשת נותקה מכל המכשירים.';
      } else {
        db()->prepare("UPDATE users SET mfa_method = 'app', mfa_code_hash = NULL, totp_enabled = IF(totp_secret IS NULL, 0, 1), pw_ver = pw_ver + 1 WHERE id = ?")->execute([$uid]);
        audit('mfa_method', 'user', (string)$uid, 'app'); $msg = 'האימות הוחלף לאפליקציה. אם לא הוגדרה אפליקציה, המשתמשת תגדיר אותה בכניסה הבאה.';
      }
    } elseif ($act === 'toggle') {
      db()->prepare('UPDATE users SET active = 1 - active, pw_ver = pw_ver + 1 WHERE id = ?')->execute([$uid]);
      audit('user_toggle', 'user', (string)$uid); $msg = 'עודכן.';
    } elseif ($act === 'reset2fa') {
      db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, mfa_method = \'app\', mfa_code_hash = NULL, pw_ver = pw_ver + 1 WHERE id = ?')->execute([$uid]);
      db()->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$uid]);
      audit('2fa_reset', 'user', (string)$uid); $msg = 'האימות הדו-שלבי אופס. בכניסה הבאה המשתמשת תגדיר אותו מחדש.';
    } elseif ($act === 'role') {
      $role = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'secretary';
      db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $uid]);
      audit('user_role', 'user', (string)$uid, $role); $msg = 'התפקיד עודכן.';
    } else {
      $pw = (string)($_POST['pw'] ?? '');
      if ($e = pw_ok($pw)) $err = $e;
      else {
        db()->prepare('UPDATE users SET pass_hash = ?, pw_ver = pw_ver + 1 WHERE id = ?')->execute([hash_pw($pw), $uid]);
        if ($uid === $me['id']) { $_SESSION['pwv'] = (int)db()->query('SELECT pw_ver FROM users WHERE id = ' . $uid)->fetchColumn(); }
        audit('user_pw_reset', 'user', (string)$uid); $msg = 'הסיסמה אופסה, והמשתמשת נותקה מכל המכשירים.';
      }
    }
  }
}
$users = db()->query('SELECT id, username, display_name, role, active, last_login, totp_enabled, mfa_method, mfa_email FROM users ORDER BY id')->fetchAll();
page_head('משתמשים');
?>
<h1>משתמשים</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card sc"><table>
<tr><th>שם</th><th>משתמש</th><th>תפקיד</th><th>כניסה אחרונה</th><th>אימות דו-שלבי</th><th>פעילה</th><th>איפוס סיסמה</th></tr>
<?php foreach ($users as $x): ?>
<tr>
  <td><?= e($x['display_name']) ?></td><td><?= e($x['username']) ?></td>
  <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="role"><input type="hidden" name="uid" value="<?= (int)$x['id'] ?>">
    <select name="role"><?php foreach (ROLES as $k => $v) echo '<option value="' . $k . '"' . ($x['role'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select> <button class="sec">עדכון</button></form></td>
  <td><?= e($x['last_login'] ?: '—') ?></td>
  <td><?php if ($x['totp_enabled']): ?><span class="ok"><?= $x['mfa_method'] === 'email' ? 'מייל ✓' : 'אפליקציה ✓' ?></span><?php else: ?><span class="er">לא מוגדר</span><?php endif; ?>
    <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="mfa"><input type="hidden" name="uid" value="<?= (int)$x['id'] ?>">
      <select name="method"><option value="app"<?= $x['mfa_method'] === 'app' ? ' selected' : '' ?>>אפליקציה</option><option value="email"<?= $x['mfa_method'] === 'email' ? ' selected' : '' ?>>קוד במייל</option></select>
      <input type="email" name="mail" placeholder="כתובת מייל" value="<?= e($x['mfa_email']) ?>" dir="ltr"> <button class="sec">עדכון</button></form>
    <?php if ($x['totp_enabled']): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="reset2fa"><input type="hidden" name="uid" value="<?= (int)$x['id'] ?>"><button class="sec">איפוס</button></form><?php endif; ?></td>
  <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="uid" value="<?= (int)$x['id'] ?>"><button class="sec"><?= $x['active'] ? 'פעילה – השבתה' : 'מושבתת – הפעלה' ?></button></form></td>
  <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="reset"><input type="hidden" name="uid" value="<?= (int)$x['id'] ?>"><input type="password" name="pw" placeholder="סיסמה חדשה" autocomplete="new-password"> <button class="sec">איפוס</button></form></td>
</tr>
<?php endforeach; ?>
</table></div>
<form method="post" class="card" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="act" value="create">
  <h2>הוספת משתמשת</h2>
  <div class="fg">
    <label>שם תצוגה<input name="name" required></label>
    <label>שם משתמש (אנגלית)<input name="username" required></label>
    <label>סיסמה (10+ תווים, אותיות וספרות)<input name="pw" type="password" required autocomplete="new-password"></label>
    <label>תפקיד<select name="role"><?php foreach (ROLES as $k => $v) echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
    <label>שיטת אימות בכניסה<select name="method"><option value="app">אפליקציית אימות בטלפון</option><option value="email">קוד שנשלח למייל</option></select></label>
    <label>כתובת מייל (לאימות במייל)<input type="email" name="mail" dir="ltr" placeholder="name@gmail.com"></label>
  </div>
  <p><button>הוספה</button></p>
</form>
<?php page_foot();
