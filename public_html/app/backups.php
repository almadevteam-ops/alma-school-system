<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$msg = ''; $ok = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'run') { [$ok, $msg] = bk_run(); audit('backup_run', 'system', '', $ok ? 'ידני: הצלחה' : 'ידני: ' . $msg); }
  elseif ($act === 'dl') {
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
