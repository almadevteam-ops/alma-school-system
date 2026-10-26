<?php
// התקנה חד-פעמית. אחרי שהיא מסתיימת – למחוק את הקובץ הזה מהשרת (הוא ינסה למחוק את עצמו).
require __DIR__ . '/../private/lib/bootstrap.php';
if (file_exists(PRIV . '/installed.flag')) fail_page(403, 'המערכת כבר הותקנה.');
$key = (string)cfg('setup_key', '');
if (strlen($key) < 30 || str_starts_with($key, 'CHANGE_ME')) fail_page(500, 'יש להגדיר setup_key ארוך ואקראי בקובץ config.php.');
$msg = ''; $err = ''; $done = false;
try { $n = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(); } catch (Throwable $t) { $n = 0; }
if ($n > 0) fail_page(403, 'המערכת כבר הותקנה. יש למחוק את setup.php.');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $un = trim((string)($_POST['username'] ?? '')); $nm = trim((string)($_POST['name'] ?? '')); $pw = (string)($_POST['pw'] ?? '');
  usleep(400000);
  if (!hash_equals($key, (string)($_POST['key'] ?? ''))) $err = 'מפתח ההתקנה שגוי.';
  elseif (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $un)) $err = 'שם משתמש: 3–60 תווים באנגלית, ספרות או . _ @ -';
  elseif ($nm === '') $err = 'נא למלא שם תצוגה.';
  elseif ($pw !== (string)($_POST['pw2'] ?? '')) $err = 'הסיסמאות אינן זהות.';
  elseif ($e = pw_ok($pw)) $err = $e;
  else {
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(PRIV . '/schema.sql')))) as $sql) db()->exec($sql);
    migrate();
    db()->prepare('INSERT INTO users (username, display_name, pass_hash, role, created_at) VALUES (?,?,?,?,?)')->execute([$un, $nm, hash_pw($pw), 'admin', now()]);
    db()->prepare('INSERT INTO audit_log (created_at, user_id, username, action, entity, detail, ip) VALUES (?,?,?,?,?,?,?)')
      ->execute([now(), null, $un, 'install', 'system', 'התקנה ראשונית', client_ip()]);
    @file_put_contents(PRIV . '/installed.flag', now());
    $done = true; $deleted = @unlink(__FILE__);
  }
}
page_head('התקנה', false);
if ($done) {
  echo '<div class="card login"><h1>ההתקנה הושלמה</h1><p class="ok">נוצרה משתמשת מנהלת.</p>'
     . ($deleted ? '<p>קובץ ההתקנה נמחק אוטומטית.</p>' : '<p class="er">חשוב: מחקי עכשיו את setup.php ב-File Manager.</p>')
     . '<p><a class="btn" href="app/login.php">לכניסה</a></p></div>';
} else { ?>
<div class="card login">
  <h1>התקנת המערכת</h1>
  <?php if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
  <form method="post" autocomplete="off"><?= csrf_field() ?>
    <p><label>מפתח התקנה (מתוך config.php)<br><input name="key" type="password" required style="width:100%"></label></p>
    <p><label>שם תצוגה<br><input name="name" required style="width:100%"></label></p>
    <p><label>שם משתמש (אנגלית)<br><input name="username" required style="width:100%"></label></p>
    <p><label>סיסמה (10+ תווים, אותיות וספרות)<br><input name="pw" type="password" required autocomplete="new-password" style="width:100%"></label></p>
    <p><label>אימות סיסמה<br><input name="pw2" type="password" required autocomplete="new-password" style="width:100%"></label></p>
    <button>התקנה</button>
  </form>
</div>
<?php } page_foot();
