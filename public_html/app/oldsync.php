<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
$err = ''; $msg = ''; $token = ''; $prev = null; $D = null;
$OPTS = ['students' => 'תלמידות ופרטיהן (השלמת שדות ריקים, הוספת חסרות)', 'pay' => 'תשלומי שכר לימוד וסימוני חודשים (בטיפול / החזר / לא רלוונטי)', 'charges' => 'חיובים חד-פעמיים וסטטוס התשלום שלהם', 'gad' => 'תשלומי מורשת גד', 'logs' => 'תיעוד שיחות (רשימת התקשרות)', 'sett' => 'הגדרות: הנחת אחיות, קישור תשלום, נמעני התראות', 'updfam' => 'לעדכן גם תעריף, מספר תשלומים ואמצעי תשלום בתיקים שכבר קיימים'];
function ostaged(string $t): ?string { return preg_match('/^[a-f0-9]{32}$/', $t) ? PRIV . '/storage/olds_' . $t . '.bin' : null; }
foreach (glob(PRIV . '/storage/olds_*.bin') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
function ochosen(): array { $o = []; foreach (['students', 'pay', 'charges', 'gad', 'logs', 'sett', 'updfam'] as $k) $o[$k] = !empty($_POST['o'][$k]) ? 1 : 0; return $o; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? '');
  if ($act === 'upload') {
    $f = $_FILES['old'] ?? null;
    if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
    elseif ($f['size'] > OLDSYNC_MAX) $err = 'הקובץ גדול מדי (עד 15MB).';
    elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
    else {
      try { $D = oldsync_parse((string)file_get_contents($f['tmp_name'])); } catch (Throwable $t) { $err = $t->getMessage(); }
      if (!$err) { $token = bin2hex(random_bytes(16)); $p = ostaged($token); file_put_contents($p, enc_bytes(json_encode($D, JSON_UNESCAPED_UNICODE), 'olds:' . $token), LOCK_EX); @chmod($p, 0600); audit('oldsync_preview', 'system', '', count($D['students']) . ' תלמידות בקובץ'); }
    }
  } elseif (in_array($act, ['preview', 'apply'], true)) {
    $token = (string)($_POST['token'] ?? ''); $p = ostaged($token); $raw = ($p && is_file($p)) ? dec_bytes((string)file_get_contents($p), 'olds:' . $token) : null;
    if ($raw === null || $raw === false) { $err = 'פג תוקף התצוגה המקדימה. העלי את הקובץ שוב.'; $token = ''; } else $D = json_decode($raw, true);
  } elseif ($act === 'cancel') { $p = ostaged((string)($_POST['token'] ?? '')); if ($p && is_file($p)) @unlink($p); $msg = 'בוטל. לא נשמר דבר.'; }
  if ($D && $token !== '' && !$err) {
    $o = ($act === 'upload') ? ['students' => 1, 'pay' => 1, 'charges' => 1, 'gad' => 1, 'logs' => 1, 'sett' => 1, 'updfam' => 0] : ochosen();
    try {
      if ($act === 'apply') {
        $S = oldsync_run($D, $o, $u, false);
        audit('oldsync_apply', 'system', '', 'תלמידות חדשות ' . $S['st_new'] . ' · עודכנו ' . $S['st_upd'] . ' · תיקים חדשים ' . $S['fam_new'] . ' · תשלומים ' . $S['pay_n'] . ' · חיובים ' . $S['chg_n']);
        $msg = 'הסנכרון הושלם. אפשר להריץ אותו שוב בכל עת עם גיבוי עדכני – לא ייווצרו כפילויות.'; $p = ostaged($token); if ($p && is_file($p)) @unlink($p); $prev = $S; $D = null; $token = '';
      } else $prev = oldsync_run($D, $o, $u, true);
    } catch (Throwable $t) { $err = 'הסנכרון נכשל ולא נשמר דבר: ' . $t->getMessage(); error_log('oldsync: ' . $t->getMessage()); $prev = null; }
  }
}
page_head('יישור קו מהמערכת הישנה');
?>
<h1>יישור קו מהמערכת הישנה</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <p class="mu">מעלים את קובץ הגיבוי של המערכת הישנה (<bdi dir="ltr">גיבוי-תשפז.json</bdi>, הכפתור "גיבוי" בלשונית נתונים). קודם מוצגת תצוגה מקדימה של מה יקרה, ורק אחרי אישור נכתב משהו. <b>מומלץ להריץ <a href="backups.php">גיבוי</a> לפני הביצוע.</b></p>
  <p class="mu">תלמידות מזוהות לפי ת.ז, ובהיעדרה לפי שם, ומסומנות כך שבהרצה חוזרת לא ייווצרו כפילויות. אחיות מקובצות לתיק אחד לפי טלפון הורה זהה. תשלומים וחיובים שנוצרו בסנכרון מבוטלים ונוצרים מחדש בכל הרצה, כך שהגבייה תמיד משקפת את הקובץ העדכני.</p>
  <form method="post" enctype="multipart/form-data" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="upload"><input type="file" name="old" accept=".json,application/json" required><button>טעינה ותצוגה מקדימה</button></form>
</div>
<?php if ($prev && $token !== ''): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
<div class="card"><h2>מה לסנכרן</h2>
<?php foreach ($OPTS as $k => $l) echo '<label class="chk"><input type="checkbox" name="o[' . $k . ']" value="1"' . (!empty($o[$k]) ? ' checked' : '') . '> ' . e($l) . '</label><br>'; ?>
<p><button name="act" value="preview" class="sec">רענון תצוגה מקדימה</button></p></div>
<?php endif; if ($prev): ?>
<div class="cards"><div class="card">תלמידות חדשות<b><?= (int)$prev['st_new'] ?></b></div><div class="card">תלמידות שעודכנו<b><?= (int)$prev['st_upd'] ?></b></div><div class="card">תיקים חדשים<b><?= (int)$prev['fam_new'] ?></b></div><div class="card">תיקים קיימים<b><?= (int)$prev['fam_ex'] ?></b></div>
<div class="card">תשלומי שכ״ל<b><?= (int)$prev['pay_n'] ?></b><span class="mu"><?= number_format($prev['pay_sum'], 0) ?> ₪</span></div><div class="card">סימוני חודש<b><?= (int)$prev['mark_n'] ?></b></div><div class="card">חיובים חד-פעמיים<b><?= (int)$prev['chg_n'] ?></b><span class="mu">שולם <?= number_format($prev['chg_paid'], 0) ?> ₪</span></div><div class="card">תשלומי מורשת גד<b><?= (int)$prev['gad_n'] ?></b></div><div class="card">שיחות שתועדו<b><?= (int)$prev['log_n'] ?></b></div><div class="card">נמענים חדשים<b><?= (int)$prev['rec_n'] ?></b></div></div>
<?php if ($prev['warn']): ?><div class="card"><h2>שימו לב (<?= count($prev['warn']) ?>)</h2><ul><?php foreach (array_slice($prev['warn'], 0, 40) as $w) echo '<li>' . e($w) . '</li>'; ?></ul><?= count($prev['warn']) > 40 ? '<p class="mu">ועוד…</p>' : '' ?></div><?php endif; ?>
<?php if ($token !== ''): ?><p class="bar"><button name="act" value="apply" data-confirm="לבצע את הסנכרון? מומלץ שיש גיבוי עדכני.">ביצוע הסנכרון</button><button name="act" value="cancel" class="sec">ביטול</button></p></form><?php endif; endif; page_foot();
