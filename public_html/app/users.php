<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$err = ''; $msg = ''; $errf = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'create') {
    $un = trim((string)($_POST['username'] ?? '')); $nm = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    $role = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'secretary'; $pw = (string)($_POST['pw'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $un)) { $err = 'שם משתמש: 3–60 תווים באנגלית, ספרות או . _ @ -'; $errf = 'username'; }
    elseif ($nm === '') { $err = 'נא למלא שם תצוגה.'; $errf = 'name'; }
    elseif ($e = pw_ok($pw)) { $err = $e; $errf = 'pw'; }
    elseif (($_POST['method'] ?? '') === 'email' && !filter_var(trim((string)($_POST['mail'] ?? '')), FILTER_VALIDATE_EMAIL)) { $err = 'לאימות במייל נדרשת כתובת מייל תקינה.'; $errf = 'mail'; }
    else {
      $byMail = ($_POST['method'] ?? '') === 'email'; $mail = $byMail ? mb_substr(trim((string)$_POST['mail']), 0, 150) : '';
      try {
        db()->prepare('INSERT INTO users (username, display_name, pass_hash, role, created_at, mfa_method, mfa_email, totp_enabled) VALUES (?,?,?,?,?,?,?,?)')->execute([$un, $nm, hash_pw($pw), $role, now(), $byMail ? 'email' : 'app', $mail, $byMail ? 1 : 0]);
        audit('user_create', 'user', $un, 'תפקיד: ' . $role . ', אימות: ' . ($byMail ? 'מייל' : 'אפליקציה')); $msg = 'המשתמשת נוצרה.' . ($byMail ? ' בכניסה היא תקבל קוד במייל.' : ' בכניסה הראשונה היא תגדיר אפליקציית אימות.');
      } catch (PDOException $x) { $err = 'שם המשתמש כבר קיים.'; $errf = 'username'; }
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
$kp = ($err !== '' && ($_POST['act'] ?? '') === 'create') ? $_POST : [];
$kv = fn(string $k) => e((string)($kp[$k] ?? ''));
$bad = fn(string $k) => $errf === $k ? ' class="bad" aria-invalid="true"' : '';
$short = fn(string $l): string => trim((string)preg_replace('/\s*\(.*$/u', '', $l));
$users = db()->query('SELECT id, username, display_name, role, active, last_login, totp_enabled, mfa_method, mfa_email FROM users ORDER BY id')->fetchAll();
page_head('משתמשים');
?>
<h1>משתמשים <span class="mu">(<?= count($users) ?>)</span></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="ut">
  <div class="ur uth" aria-hidden="true"><span>שם</span><span>משתמש</span><span>תפקיד</span><span>אימות דו-שלבי</span><span>כניסה אחרונה</span><span>מצב</span><span></span></div>
<?php foreach ($users as $x): $self = (int)$x['id'] === (int)$me['id']; $uid = (int)$x['id'];
  $hid = fn(string $act) => csrf_field() . '<input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="uid" value="' . $uid . '">'; ?>
  <details class="uw<?= $x['active'] ? '' : ' off' ?>">
    <summary class="ur">
      <span class="c nm" data-l="שם"><b><?= e($x['display_name']) ?></b><?= $self ? ' <span class="pill me">אני</span>' : '' ?></span>
      <span class="c" data-l="משתמש"><bdi dir="ltr"><?= e($x['username']) ?></bdi></span>
      <span class="c" data-l="תפקיד"><span class="role r-<?= e($x['role']) ?>" title="<?= e(ROLES[$x['role']] ?? '') ?>"><?= e($short(ROLES[$x['role']] ?? $x['role'])) ?></span></span>
      <span class="c" data-l="אימות"><?php if ($x['totp_enabled']): ?><span class="ok">✓ <?= $x['mfa_method'] === 'email' ? 'קוד במייל' : 'אפליקציה' ?></span><?php else: ?><span class="er">לא מוגדר</span><?php endif; ?></span>
      <span class="c" data-l="כניסה אחרונה"><?= $x['last_login'] ? '<bdi dir="ltr">' . e(date('d/m/Y H:i', strtotime((string)$x['last_login']))) . '</bdi>' : '<span class="mu">עוד לא נכנסה</span>' ?></span>
      <span class="c" data-l="מצב"><span class="st <?= $x['active'] ? 'on' : 'off' ?>"><?= $x['active'] ? 'פעילה' : 'מושבתת' ?></span></span>
      <span class="chev" aria-hidden="true">⌄</span>
    </summary>
    <div class="mgb">
      <?php if ($x['mfa_method'] === 'email' && $x['mfa_email']): ?><p class="mu hint2">מייל לקבלת הקוד: <bdi dir="ltr"><?= e(mfa_mask($x['mfa_email'])) ?></bdi></p><?php endif; ?>
      <?php if (!$self): ?>
      <form method="post" class="mrow"><?= $hid('role') ?><label>תפקיד<select name="role"><?php foreach (ROLES as $k => $v) echo '<option value="' . $k . '"' . ($x['role'] === $k ? ' selected' : '') . '>' . e($short($v)) . '</option>'; ?></select></label><button class="sec">עדכון תפקיד</button></form>
      <form method="post" class="mrow"><?= $hid('mfa') ?>
        <label>שיטת אימות<select name="method"><option value="app"<?= $x['mfa_method'] === 'app' ? ' selected' : '' ?>>אפליקציה בטלפון</option><option value="email"<?= $x['mfa_method'] === 'email' ? ' selected' : '' ?>>קוד במייל</option></select></label>
        <label>מייל לקוד<input type="email" name="mail" dir="ltr" placeholder="name@gmail.com" value="<?= e($x['mfa_email']) ?>"></label>
        <button class="sec">עדכון אימות</button></form>
      <?php if ($x['totp_enabled']): ?><form method="post" class="mrow"><?= $hid('reset2fa') ?><span class="mu hint">מאפס את האימות; בכניסה הבאה תוגדר שיטה מחדש.</span><button class="sec">איפוס אימות</button></form><?php endif; ?>
      <?php endif; ?>
      <form method="post" class="mrow"><?= $hid('reset') ?><label>סיסמה חדשה<input type="password" name="pw" autocomplete="new-password" placeholder="10+ תווים, אותיות וספרות"></label><button class="sec">איפוס סיסמה</button></form>
      <?php if (!$self): ?><form method="post" class="mrow end"><?= $hid('toggle') ?><span class="mu hint"><?= $x['active'] ? 'השבתה מנתקת אותה מיד מכל המכשירים.' : 'הפעלה מחזירה לה אפשרות כניסה.' ?></span><button class="<?= $x['active'] ? 'dg' : '' ?>"><?= $x['active'] ? 'השבתת משתמשת' : 'הפעלת משתמשת' ?></button></form>
      <?php else: ?><p class="mu hint">זה החשבון שלך: תפקיד, אימות והשבתה לא ניתנים לשינוי כאן, כדי שהגישה שלך לא תיחסם בטעות.</p><?php endif; ?>
    </div>
  </details>
<?php endforeach; ?>
</div>
<?php sec_open('new', 'הוספת משתמשת', 'שם, סיסמה ושיטת אימות', '＋', ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'create' && $err) ? ['new'] : []); ?>
<form method="post" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="act" value="create">
  <div class="fg">
    <label>שם תצוגה<input name="name" required value="<?= $kv('name') ?>"<?= $bad('name') ?>></label>
    <label>שם משתמש (אנגלית)<input name="username" required dir="ltr" value="<?= $kv('username') ?>"<?= $bad('username') ?>></label>
    <label<?= $errf === 'pw' ? ' class="bad-l"' : '' ?>>סיסמה (10+ תווים, אותיות וספרות)<input name="pw" type="password" required autocomplete="new-password"<?= $bad('pw') ?>></label>
    <label>תפקיד<select name="role"><?php foreach (ROLES as $k => $v) echo '<option value="' . $k . '"' . (($kp['role'] ?? '') === $k ? ' selected' : '') . '>' . e($short($v)) . '</option>'; ?></select></label>
    <label>שיטת אימות בכניסה<select name="method"><option value="app">אפליקציית אימות בטלפון</option><option value="email"<?= ($kp['method'] ?? '') === 'email' ? ' selected' : '' ?>>קוד שנשלח למייל</option></select></label>
    <label>כתובת מייל (רק לאימות במייל)<input type="email" name="mail" dir="ltr" placeholder="name@gmail.com" value="<?= $kv('mail') ?>"<?= $bad('mail') ?>></label>
  </div>
  <p><button>הוספת משתמשת</button></p>
</form>
<?php sec_close(); ?>
<?php $roleDesc = ['admin' => 'הכול: משתמשים, ייבוא, יומן פעולות, גיבויים, בדיקת מערכת ומחיקות.', 'secretary' => 'עריכת תלמידות, מסמכים ומספרי זהות, וניהול הגבייה. בלי ניהול משתמשים והגדרות מערכת.', 'finance' => 'עבודה בגבייה. בתלמידות רק צפייה, בלי עריכה, מסמכים או מספרי זהות.']; ?>
<div class="card"><h2>מה כל תפקיד יכול</h2><ul class="legend"><?php foreach (ROLES as $k => $v) echo '<li><span class="role r-' . e($k) . '">' . e($short($v)) . '</span> ' . e($roleDesc[$k] ?? '') . '</li>'; ?></ul></div>
<?php page_foot();
