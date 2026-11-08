<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
$err = ''; $msg = '';
$who = (string)($u['name'] ?: $u['username']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'save') {
    $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/u', (string)($_POST['new_student_notify'] ?? '')) ?: []))));
    $badAddr = array_values(array_filter($list, fn($a) => !filter_var($a, FILTER_VALIDATE_EMAIL) || mb_strlen($a) > 150));
    if (count($list) > 10) $err = 'עד 10 כתובות.';
    elseif ($badAddr) $err = 'כתובת לא תקינה: ' . implode(', ', $badAddr);
    else {
      setting_set('new_student_notify', implode(', ', $list), $who);
      audit('settings', 'setting', 'new_student_notify', count($list) . ' נמענים');
      $msg = $list ? 'נשמר. בעת יצירת תלמידה חדשה יישלח מייל ל-' . count($list) . ' נמענים.' : 'נשמר. לא יישלח מייל על תלמידה חדשה.';
    }
  } elseif ($act === 'test') {
    $to = new_student_recipients();
    if (!$to) $err = 'אין נמענים מוגדרים.';
    else {
      $ok = 0; $fail = [];
      foreach ($to as $a) { if (send_mail($a, 'בדיקת מייל ממערכת עלמא', "זהו מייל בדיקה.\nאם הגיע אליך, כך ייראו ההודעות על תלמידות חדשות.\nנשלח על ידי: $who")) $ok++; else $fail[] = $a . ' (' . mail_last_error() . ')'; }
      audit($fail ? 'test_mail_failed' : 'test_mail', 'setting', 'new_student_notify', "נשלח $ok" . ($fail ? '; נכשל: ' . implode(', ', $fail) : ''));
      if ($fail) $err = 'השליחה נכשלה: ' . implode(' | ', $fail); else $msg = 'נשלח מייל בדיקה ל-' . $ok . ' נמענים. כדאי לבדוק גם בתיבת הספאם.';
    }
  }
}
$cur = implode(', ', new_student_recipients());
page_head('הגדרות');
?>
<h1>הגדרות</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <h2>מייל על תלמידה חדשה</h2>
  <p class="mu">כשמסיימים ליצור תלמידה חדשה, נשלח מייל עם הפרטים (בלי מספרי זהות) לכתובות שיוגדרו כאן. כמה כתובות מפרידים בפסיק. אם השדה ריק, לא נשלח מייל.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <label>נמענים<textarea name="new_student_notify" rows="2" class="w100" dir="ltr" placeholder="name@gmail.com, other@gmail.com"><?= e($cur) ?></textarea></label>
    <div class="acts"><button>שמירה</button></div>
  </form>
  <form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="act" value="test"><button class="sec"<?= $cur === '' ? ' disabled' : '' ?>>שליחת מייל בדיקה לנמענים</button></form>
  <p class="mu">המייל יוצא מהכתובת <bdi dir="ltr"><?= e((string)cfg('mail_from', 'noreply@' . (string)cfg('host', 'localhost'))) ?></bdi>. אם משהו נכשל, הסיבה נרשמת ביומן הפעולות.</p>
</div>
<?php page_foot();
