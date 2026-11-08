<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
$err = ''; $msg = '';
$who = (string)($u['name'] ?: $u['username']);
const MAX_RECIPIENTS = 15;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  $addr = strtolower(trim((string)($_POST['email'] ?? ''))); $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 100);
  $rid = (int)($_POST['rid'] ?? 0);
  if ($act === 'fees') {
    $apt = max(0, (float)str_replace(',', '.', (string)($_POST['apt_tuition'] ?? '0'))); $sib = max(0, (float)str_replace(',', '.', (string)($_POST['sib_discount'] ?? '0')));
    setting_set('apt_tuition', (string)$apt, $who); setting_set('sib_discount', (string)$sib, $who); audit('settings', 'settings', '', "דירה $apt · אחיות $sib"); $msg = 'נשמר.';
  } elseif ($act === 'sib_apply') {
    $n = sib_apply($who); audit('sib_apply', 'settings', '', "הנחת אחיות הוחלה על $n"); $msg = "הנחת אחיות הוחלה על $n תלמידות.";
  } elseif ($act === 'add' || $act === 'edit') {
    if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || mb_strlen($addr) > 150 || preg_match('/[\r\n]/', $addr)) $err = 'כתובת המייל לא תקינה.';
    elseif ($act === 'add' && count(notify_recipients()) >= MAX_RECIPIENTS) $err = 'אפשר להגדיר עד ' . MAX_RECIPIENTS . ' נמענים.';
    else {
      try {
        if ($act === 'add') { db()->prepare('INSERT INTO notify_recipients (email, label, created_at, created_by) VALUES (?,?,?,?)')->execute([$addr, $label, now(), mb_substr($who, 0, 100)]); audit('recipient_add', 'setting', $addr); $msg = 'הנמען נוסף.'; }
        else { $old = db()->prepare('SELECT email FROM notify_recipients WHERE id = ?'); $old->execute([$rid]); $o = $old->fetchColumn(); if ($o === false) fail_page(404, 'הנמען לא נמצא.'); db()->prepare('UPDATE notify_recipients SET email = ?, label = ? WHERE id = ?')->execute([$addr, $label, $rid]); audit('recipient_edit', 'setting', (string)$rid, $o . ' → ' . $addr); $msg = 'הנמען עודכן.'; }
      } catch (PDOException $x) { $err = 'הכתובת כבר ברשימה.'; }
    }
  } elseif ($act === 'delete') {
    $old = db()->prepare('SELECT email FROM notify_recipients WHERE id = ?'); $old->execute([$rid]); $o = $old->fetchColumn();
    if ($o === false) fail_page(404, 'הנמען לא נמצא.');
    db()->prepare('DELETE FROM notify_recipients WHERE id = ?')->execute([$rid]); audit('recipient_delete', 'setting', (string)$rid, (string)$o); $msg = 'הנמען נמחק מהרשימה.';
  } elseif ($act === 'test') {
    $to = notify_recipients();
    if (!$to) $err = 'אין נמענים ברשימה.';
    else {
      $ok = 0; $fail = [];
      foreach ($to as $r) { if (send_mail((string)$r['email'], 'בדיקת מייל ממערכת עלמא', "זהו מייל בדיקה ממערכת עלמא.\nאם הגיע אליך, כך יגיעו אליך ההודעות על הרשמת תלמידה חדשה.\nנשלח על ידי: $who")) $ok++; else $fail[] = $r['email'] . ' (' . mail_last_error() . ')'; }
      audit($fail ? 'test_mail_failed' : 'test_mail', 'setting', '', "נשלח $ok" . ($fail ? '; נכשל: ' . implode(', ', $fail) : ''));
      if ($fail) $err = 'השליחה נכשלה: ' . implode(' | ', $fail); else $msg = 'נשלח מייל בדיקה ל-' . $ok . ' נמענים. כדאי לבדוק גם בתיבת הספאם.';
    }
  }
}
$rows = notify_recipients();
page_head('הגדרות');
?>
<h1>הגדרות</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <h2>תעריפים והנחות</h2>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="fees">
    <label>שכר לימוד חודשי לתלמידה בדירה (₪)<input name="apt_tuition" inputmode="decimal" value="<?= e((string)(float)setting_get('apt_tuition', '300')) ?>"></label>
    <label>הנחת אחיות לחודש (₪), מהאחות השנייה בתיק<input name="sib_discount" inputmode="decimal" value="<?= e((string)(float)setting_get('sib_discount', '50')) ?>"></label>
    <button>שמירה</button></form>
  <form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="act" value="sib_apply"><button class="sec" data-confirm="להחיל הנחת אחיות על כל התיקים עם יותר מתלמידה אחת? הנחות קיימות לא יוחלפו.">החלת הנחת אחיות עכשיו</button></form>
  <p class="mu">ההחלה מוסיפה את ההנחה לכל אחות החל מהשנייה בכל תיק (הבוגרת ביותר ללא הנחה), בלי לשנות הנחות שכבר הוגדרו. אפשר לשנות כל הנחה בכרטיס התלמידה.</p>
</div>
<div class="card">
  <h2>נמענים להודעה על הרשמת תלמידה חדשה</h2>
  <p class="mu">בכל פעם שתלמידה חדשה נרשמת במערכת, נשלח מייל קצר עם שם התלמידה, מספר הזהות וקישור לאתר, לכל הכתובות ברשימה. אפשר להוסיף, לערוך ולמחוק נמענים. שימו לב: מספר הזהות נשלח במייל לכל מי שברשימה.</p>
  <div class="ut rcp">
    <div class="ur uth" aria-hidden="true"><span>כתובת מייל</span><span>שם / תפקיד</span><span></span></div>
    <?php if (!$rows) echo '<p class="emp">אין נמענים. לא יישלח מייל על תלמידה חדשה.</p>'; foreach ($rows as $r): ?>
    <form method="post" class="ur rrow"><?= csrf_field() ?><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
      <input type="email" name="email" dir="ltr" value="<?= e($r['email']) ?>" required maxlength="150" aria-label="כתובת מייל">
      <input name="label" value="<?= e($r['label']) ?>" maxlength="100" placeholder="למשל: מזכירות" aria-label="שם או תפקיד">
      <span class="racts"><button name="act" value="edit" class="sec">שמירה</button> <button name="act" value="delete" class="dg" data-confirm="למחוק את <?= e($r['email']) ?> מהרשימה?">מחיקה</button></span>
    </form>
    <?php endforeach; ?>
  </div>
  <h2>הוספת נמען</h2>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="add">
    <label>כתובת מייל<input type="email" name="email" dir="ltr" required maxlength="150" placeholder="name@gmail.com"></label>
    <label>שם / תפקיד (לא חובה)<input name="label" maxlength="100"></label>
    <button>הוספה</button>
  </form>
  <form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="act" value="test"><button class="sec"<?= $rows ? '' : ' disabled' ?>>שליחת מייל בדיקה לכל הנמענים</button></form>
  <p class="mu">המייל יוצא מהכתובת <bdi dir="ltr"><?= e((string)cfg('mail_from', 'noreply@' . (string)cfg('host', 'localhost'))) ?></bdi>. אם משהו נכשל, הסיבה נרשמת ביומן הפעולות.</p>
</div>
<?php page_foot();
