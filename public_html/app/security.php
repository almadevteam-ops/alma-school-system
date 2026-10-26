<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_login();
$uid = $u['id'];
$msg = ''; $err = ''; $codes = null;
$st = db()->prepare('SELECT pass_hash, totp_enabled, username FROM users WHERE id = ?'); $st->execute([$uid]); $me = $st->fetch();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'enable' && !(int)$me['totp_enabled']) {
    $pend = $_SESSION['totp_pending'] ?? '';
    $secret = b32dec($pend);
    $step = $secret !== '' ? totp_verify($secret, (string)($_POST['code'] ?? ''), 0) : null;
    if ($step === null) { $err = 'הקוד שגוי. ודאו שהשעה בטלפון מדויקת ונסו שוב.'; }
    else {
      db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?')->execute([enc_text($secret, 'totp:' . $uid), $step, $uid]);
      unset($_SESSION['totp_pending']);
      $codes = make_recovery_codes($uid);
      $_SESSION['user']['totp'] = 1; $me['totp_enabled'] = 1;
      audit('2fa_enabled', 'user', (string)$uid);
    }
  } elseif ($act === 'newcodes' && (int)$me['totp_enabled']) {
    $codes = make_recovery_codes($uid);
    audit('2fa_recovery_regenerated', 'user', (string)$uid);
  } elseif ($act === 'chpw') {
    $cur = (string)($_POST['cur'] ?? ''); $n1 = (string)($_POST['n1'] ?? ''); $n2 = (string)($_POST['n2'] ?? '');
    usleep(300000);
    if (!password_verify($cur, $me['pass_hash'])) $err = 'הסיסמה הנוכחית שגויה.';
    elseif ($n1 !== $n2) $err = 'הסיסמאות החדשות אינן זהות.';
    elseif ($e = pw_ok($n1)) $err = $e;
    else {
      db()->prepare('UPDATE users SET pass_hash = ?, pw_ver = pw_ver + 1 WHERE id = ?')->execute([hash_pw($n1), $uid]);
      $v = db()->prepare('SELECT pw_ver FROM users WHERE id = ?'); $v->execute([$uid]); $_SESSION['pwv'] = (int)$v->fetchColumn();
      audit('password_changed', 'user', (string)$uid);
      $msg = 'הסיסמה עודכנה. מכשירים אחרים נותקו.';
    }
  }
}
$enabled = (int)$me['totp_enabled'] === 1;
$uri = ''; $grp = '';
if (!$enabled && $codes === null) {
  if (empty($_SESSION['totp_pending'])) $_SESSION['totp_pending'] = b32enc(random_bytes(20));
  $sec = $_SESSION['totp_pending'];
  $grp = trim(chunk_split($sec, 4, ' '));
  $uri = 'otpauth://totp/' . rawurlencode('Alma:' . $me['username']) . '?secret=' . $sec . '&issuer=Alma&digits=6&period=30';
}
page_head('אבטחה');
?>
<h1>אבטחת החשבון</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>

<?php if ($codes !== null): ?>
<div class="card">
  <h2>האימות הדו-שלבי פעיל</h2>
  <p class="er">שמרו את קודי השחזור במקום בטוח (למשל מודפסים, או במנהל סיסמאות). הם מוצגים רק עכשיו. כל קוד עובד פעם אחת, ומשמש אם הטלפון אבד.</p>
  <p style="font-size:20px;letter-spacing:2px;direction:ltr;text-align:right;line-height:2"><?php foreach ($codes as $c) echo e($c) . '<br>'; ?></p>
  <a class="btn" href="students.php">המשך למערכת</a>
</div>
<?php elseif (!$enabled): ?>
<div class="card">
  <h2>הפעלת אימות דו-שלבי (חובה)</h2>
  <p>כדי להגן על המידע, כל משתמשת נדרשת בקוד נוסף מהטלפון בכל כניסה.</p>
  <ol>
    <li>התקינו בטלפון אפליקציית אימות, למשל <b>Google Authenticator</b> או <b>Microsoft Authenticator</b>.</li>
    <li>באפליקציה בחרו <b>הוספת חשבון</b> ואז <b>הזנת מפתח ידנית</b> (Enter a setup key). שם חשבון: Alma. הזינו את המפתח:</li>
  </ol>
  <p style="font-size:22px;direction:ltr;text-align:right;letter-spacing:2px"><b><?= e($grp) ?></b></p>
  <p class="mu">אם אתם פותחים את הדף ישירות בטלפון: <a href="<?= e($uri) ?>">הוספה אוטומטית לאפליקציה</a></p>
  <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="enable">
    <p><label>הקוד בן 6 הספרות שמופיע באפליקציה<br><input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" style="font-size:20px;letter-spacing:3px;text-align:center"></label></p>
    <button>הפעלה</button>
  </form>
</div>
<?php else: ?>
<div class="card">
  <h2>אימות דו-שלבי</h2>
  <p class="ok">פעיל ✓</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="newcodes">
    <p class="mu">אם איבדתם את קודי השחזור, אפשר להפיק חדשים (הישנים יתבטלו).</p><button class="sec">הפקת קודי שחזור חדשים</button></form>
</div>
<?php endif; ?>

<?php if ($codes === null): ?>
<form method="post" class="card" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="chpw">
  <h2>החלפת סיסמה</h2>
  <div class="fg">
    <label>סיסמה נוכחית<input type="password" name="cur" required autocomplete="current-password"></label>
    <label>סיסמה חדשה (10+ תווים, אותיות וספרות)<input type="password" name="n1" required autocomplete="new-password"></label>
    <label>אימות סיסמה חדשה<input type="password" name="n2" required autocomplete="new-password"></label>
  </div>
  <p><button>עדכון סיסמה</button></p>
</form>
<?php endif;
page_foot();
