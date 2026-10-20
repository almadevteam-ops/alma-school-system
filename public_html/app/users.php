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
    else {
      try {
        db()->prepare('INSERT INTO users (username, display_name, pass_hash, role, created_at) VALUES (?,?,?,?,?)')->execute([$un, $nm, hash_pw($pw), $role, now()]);
        audit('user_create', 'user', $un, 'תפקיד: ' . $role); $msg = 'המשתמשת נוצרה.';
      } catch (PDOException $x) { $err = 'שם המשתמש כבר קיים.'; }
    }
  } elseif ($act === 'toggle' || $act === 'reset' || $act === 'role') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid === $me['id'] && $act !== 'reset') { $err = 'אי אפשר לשנות את המשתמשת שלך.'; }
    elseif ($act === 'toggle') {
      db()->prepare('UPDATE users SET active = 1 - active, pw_ver = pw_ver + 1 WHERE id = ?')->execute([$uid]);
      audit('user_toggle', 'user', (string)$uid); $msg = 'עודכן.';
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
$users = db()->query('SELECT id, username, display_name, role, active, last_login FROM users ORDER BY id')->fetchAll();
page_head('משתמשים');
?>
<h1>משתמשים</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card sc"><table>
<tr><th>שם</th><th>משתמש</th><th>תפקיד</th><th>כניסה אחרונה</th><th>פעילה</th><th>איפוס סיסמה</th></tr>
<?php foreach ($users as $x): ?>
<tr>
  <td><?= e($x['display_name']) ?></td><td><?= e($x['username']) ?></td>
  <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="role"><input type="hidden" name="uid" value="<?= (int)$x['id'] ?>">
    <select name="role"><?php foreach (ROLES as $k => $v) echo '<option value="' . $k . '"' . ($x['role'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select> <button class="sec">עדכון</button></form></td>
  <td><?= e($x['last_login'] ?: '—') ?></td>
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
  </div>
  <p><button>הוספה</button></p>
</form>
<?php page_foot();
