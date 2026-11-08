<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$msg = ''; $ok = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'run') { [$ok, $msg] = bk_run(); audit('backup_run', 'system', '', $ok ? 'ידני: הצלחה' : 'ידני: ' . $msg); }
  elseif ($act === 'gd_save') {
    $cl = trim((string)($_POST['gd_client'] ?? '')); $fo = trim((string)($_POST['gd_folder'] ?? ''));
    if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $fo, $mm)) $fo = $mm[1];
    if ($cl === '' || !preg_match('/^[A-Za-z0-9_-]{10,}$/', $fo)) { $ok = false; $msg = 'נא למלא Client ID ומזהה תיקיה (אפשר להדביק את קישור התיקיה).'; }
    else { gd_save($cl, trim((string)($_POST['gd_secret'] ?? '')), trim((string)($_POST['gd_refresh'] ?? '')), $fo, (string)($me['name'] ?: $me['username'])); audit('gdrive_save', 'system', '', 'הגדרות דרייב עודכנו'); $ok = true; $msg = 'נשמר. לחצו "בדיקת חיבור".'; }
  } elseif ($act === 'gd_check') {
    try { $n = gd_check(); $ok = true; $msg = 'החיבור תקין. תיקיית היעד: ' . $n; } catch (Throwable $t) { $ok = false; $msg = $t->getMessage(); }
  } elseif ($act === 'gd_run') {
    if (!bk_list()) [$ok, $msg] = bk_run(); [$ok2, $m2] = gd_run(); $ok = $ok2; $msg = $m2; audit('gdrive_upload', 'system', '', $ok ? 'הצלחה' : $m2);
  } elseif ($act === 'dl') {
    $f = (string)($_POST['f'] ?? '');
    if (!preg_match('/^alma-backup-\d{8}-\d{6}\.almb$/', $f) || !is_file(bk_dir() . '/' . $f)) { http_response_code(404); exit; }
    audit('backup_download', 'system', '', $f);
    header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize(bk_dir() . '/' . $f)); readfile(bk_dir() . '/' . $f); exit;
  }
}
$st = bk_status(); $list = bk_list();
$age = isset($st['time']) ? time() - (int)$st['time'] : null;
$php = defined('PHP_BINARY') && PHP_BINARY && strpos(PHP_BINARY, 'php') !== false && PHP_SAPI !== 'cli' ? '/usr/local/bin/php' : '/usr/local/bin/php';
$cron = $php . ' ' . (realpath(PRIV) ?: PRIV) . '/cron/backup.php';
page_head('גיבויים');
?>
<h1>גיבויים</h1>
<?php if ($msg) echo '<p class="' . ($ok ? 'ok' : 'er') . '">' . e($msg) . '</p>'; ?>
<div class="card">
  <h2>מצב</h2>
  <?php if (!$st): ?><p class="er">עדיין לא בוצע גיבוי. הריצו גיבוי ידני למטה והגדירו הרצה אוטומטית.</p>
  <?php elseif (empty($st['ok'])): ?><p class="er">הגיבוי האחרון נכשל (<?= e(date('d/m/Y H:i', (int)$st['time'])) ?>): <?= e($st['error'] ?? '') ?></p>
  <?php else: ?><p class="<?= $age < 36 * 3600 ? 'ok' : 'er' ?>">גיבוי אחרון: <?= e(date('d/m/Y H:i', (int)$st['time'])) ?> · <?= round($st['size'] / 1048576, 2) ?>MB · <?= (int)$st['rows'] ?> שורות · <?= (int)$st['files'] ?> קבצים<?= $age >= 36 * 3600 ? ' – ישן מדי, בדקו את ה-cron' : '' ?></p><?php endif; ?>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="run"><button>הרצת גיבוי עכשיו</button></form>
  <p class="mu">נשמרים 14 גיבויים אחרונים ועוד 8 שבועיים (יום ראשון). כל גיבוי כולל את כל המסד ואת כל המסמכים וההערות המצורפים, מוצפן, ונבדק אחרי היצירה (פענוח מלא וסכומי ביקורת).</p>
</div>
<div class="card sc">
  <h2>גיבויים שמורים (<?= count($list) ?>)</h2>
  <table><tr><th>קובץ</th><th>תאריך</th><th>גודל</th><th></th></tr>
  <?php foreach ($list as $b): ?><tr><td dir="ltr"><?= e($b['name']) ?></td><td><?= e(date('d/m/Y H:i', $b['time'])) ?></td><td><?= round($b['size'] / 1048576, 2) ?>MB</td>
  <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="dl"><input type="hidden" name="f" value="<?= e($b['name']) ?>"><button class="lnk">הורדה</button></form></td></tr><?php endforeach; ?>
  <?php if (!$list) echo '<tr><td colspan="4" class="mu">אין גיבויים.</td></tr>'; ?>
  </table>
</div>
<?php $gl = json_decode(setting_get('gd_last', ''), true) ?: []; ?>
<div class="card">
  <h2>עותק בגוגל דרייב</h2>
  <?php if ($gl): ?><p class="<?= !empty($gl['ok']) ? 'ok' : 'er' ?>">העלאה אחרונה: <?= e(date('d/m/Y H:i', (int)$gl['time'])) ?> · <?= !empty($gl['ok']) ? e($gl['file']) : e($gl['error'] ?? '') ?></p><?php elseif (!gd_ready()): ?><p class="mu">לא הוגדר. הגיבוי מוצפן, ולכן בטוח לשמור אותו בדרייב. אחרי ההגדרה, כל גיבוי יומי (מה-cron) יועלה לתיקיה ויישמרו בה <?= GD_KEEP ?> האחרונים.</p><?php endif; ?>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="gd_save">
    <label>Client ID<input name="gd_client" dir="ltr" class="wide" value="<?= e(gd_get('client')) ?>" autocomplete="off"></label>
    <label>Client Secret<input name="gd_secret" type="password" dir="ltr" placeholder="<?= gd_get('secret') !== '' ? 'שמור (השאירו ריק כדי לא לשנות)' : '' ?>" autocomplete="off"></label>
    <label>Refresh Token<input name="gd_refresh" type="password" dir="ltr" placeholder="<?= gd_get('refresh') !== '' ? 'שמור (השאירו ריק כדי לא לשנות)' : '' ?>" autocomplete="off"></label>
    <label>תיקיה (קישור או מזהה)<input name="gd_folder" dir="ltr" class="wide" value="<?= e(gd_get('folder')) ?>" autocomplete="off"></label><button>שמירה</button></form>
  <?php if (gd_ready()): ?><form method="post" class="bar"><?= csrf_field() ?><button name="act" value="gd_check" class="sec">בדיקת חיבור</button><button name="act" value="gd_run">העלאת הגיבוי האחרון עכשיו</button></form><?php endif; ?>
  <details><summary>איך מקבלים את הפרטים (פעם אחת, כ-10 דקות)</summary><ol>
    <li>ב-<span dir="ltr">console.cloud.google.com</span> יוצרים פרויקט, מפעילים <b>Google Drive API</b>, ובמסך OAuth consent מוסיפים את המייל שלכם כ-Test user.</li>
    <li>ב-Credentials יוצרים <b>OAuth client ID</b> מסוג Web application, ומוסיפים Authorized redirect URI: <span dir="ltr"><code>https://developers.google.com/oauthplayground</code></span>. מעתיקים את ה-Client ID וה-Secret.</li>
    <li>נכנסים ל-<span dir="ltr">developers.google.com/oauthplayground</span>, בגלגל השיניים מסמנים Use your own OAuth credentials ומדביקים אותם, בוחרים את ההרשאה <span dir="ltr"><code>https://www.googleapis.com/auth/drive</code></span>, מאשרים, ולוחצים Exchange authorization code. מעתיקים את <b>Refresh token</b>.</li>
    <li>יוצרים בדרייב תיקיה ייעודית (למשל "גיבויי עלמא") ומדביקים כאן את הקישור שלה.</li>
  </ol><p class="mu">הקובץ שעולה מוצפן במפתח המערכת, כך שגם מי שיקבל גישה לדרייב לא יוכל לקרוא אותו. את מפתח ההצפנה (<span dir="ltr">private/app.key</span>) שומרים בנפרד.</p></details>
</div>
<div class="card">
  <h2>הפעלה אוטומטית (פעם אחת)</h2>
  <ol>
    <li>ב-cPanel פתחו <b>Cron Jobs</b>.</li>
    <li>ב-"Common Settings" בחרו <b>Once Per Day</b> (או הגדירו שעה בלילה, למשל 02:30).</li>
    <li>בשדה Command הדביקו:<br><span dir="ltr"><code><?= e($cron) ?></code></span></li>
    <li>לחצו Add New Cron Job. למחרת הגיבוי יופיע כאן ובבדיקת המערכת.</li>
  </ol>
  <p class="mu">אם הנתיב ל-PHP אצלכם שונה, cPanel מציג את הנתיב הנכון בדף ה-Cron Jobs.</p>
</div>
<div class="card">
  <h2>חשוב: שומרים מחוץ לשרת</h2>
  <ul>
    <li><b>מפתח ההצפנה:</b> הקובץ <span dir="ltr"><code>private/app.key</code></span> ב-File Manager. בלעדיו אי אפשר לפתוח אף גיבוי. הורידו אותו פעם אחת ושמרו במקום בטוח (מנהל סיסמאות / כספת), לא באותה תיקייה עם הגיבויים.</li>
    <li><b>עותק מחוץ לשרת:</b> גיבוי שנמצא רק על אותו שרת לא יעזור אם החשבון באחסון ייפגע. הורידו גיבוי אחד בשבוע (כפתור "הורדה") ושמרו אותו במקום אחר. הקובץ מוצפן.</li>
    <li><b>שחזור:</b> דורש גישה למסוף/הפעלת סקריפט: <span dir="ltr"><code>php private/cron/restore.php BACKUP.almb OUTDIR</code></span>, ואחר כך טעינת <span dir="ltr">db.sql.gz</span> במסד. בכל מקרה של צורך, פנו אלי עם הקובץ והמפתח.</li>
  </ul>
</div>
<?php page_foot();
