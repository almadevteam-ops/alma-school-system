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
    [$ok, $msg] = gd_run(); audit('gdrive_upload', 'system', '', $ok ? 'הצלחה' : $msg);
  } elseif ($act === 'dl') {
    $f = (string)($_POST['f'] ?? '');
    if (!preg_match('/^alma-(backup|delta)-\d{8}-\d{6}\.almb$/', $f) || !is_file(bk_dir() . '/' . $f)) { http_response_code(404); exit; }
    audit('backup_download', 'system', '', $f);
    header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize(bk_dir() . '/' . $f)); readfile(bk_dir() . '/' . $f); exit;
  } elseif ($act === 'kit') {   // ערכת שחזור: המפתח וההגדרות, להורדה ושמירה מחוץ לשרת
    if (($_POST['kit_ok'] ?? '') !== '1') { $ok = false; $msg = 'סמנו שאתם מבינים שהקובץ רגיש.'; }
    else {
      $key = is_file(PRIV . '/app.key') ? trim((string)file_get_contents(PRIV . '/app.key')) : '';
      $cf = is_file(PRIV . '/config.php') ? (string)file_get_contents(PRIV . '/config.php') : '';
      audit('recovery_kit', 'system', '', 'הורדת ערכת שחזור');
      $body = "ערכת שחזור – עלמא – " . date('d/m/Y H:i') . "\n"
        . "!!! קובץ רגיש: מי שמחזיק בו וגם בגיבויים יכול לקרוא הכול. שמרו בכספת/מנהל סיסמאות ולא באותה תיקיה עם הגיבויים. !!!\n\n"
        . "=== קובץ private/app.key (מעתיקים את השורה הבאה בדיוק כפי שהיא לקובץ בשם app.key) ===\n" . $key . "\n\n"
        . "=== קובץ private/config.php (כל התוכן) ===\n" . $cf . "\n";
      header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="alma-recovery-kit-' . date('Ymd') . '.txt"'); header('Content-Length: ' . strlen($body)); echo $body; exit;
    }
  }
}
$st = bk_status(); $list = array_reverse(bk_list_all()); $bs = bk_state_load(); $dst = $bs['drive'] ?? [];
$age = isset($st['time']) ? time() - (int)$st['time'] : null;
$php = defined('PHP_BINARY') && PHP_BINARY && strpos(PHP_BINARY, 'php') !== false && PHP_SAPI !== 'cli' ? '/usr/local/bin/php' : '/usr/local/bin/php';
$cron = $php . ' ' . (realpath(PRIV) ?: PRIV) . '/cron/backup.php';
page_head('גיבויים');
?>
<h1>גיבויים <a class="mu" href="backup_log.php">יומן גיבויים ←</a></h1>
<?php if ($msg) echo '<p class="' . ($ok ? 'ok' : 'er') . '">' . e($msg) . '</p>'; ?>
<div class="card">
  <h2>מצב</h2>
  <?php if (!$st): ?><p class="er">עדיין לא בוצע גיבוי. הריצו גיבוי ידני למטה והגדירו הרצה אוטומטית.</p>
  <?php elseif (empty($st['ok'])): ?><p class="er">הגיבוי האחרון נכשל (<?= e(date('d/m/Y H:i', (int)$st['time'])) ?>): <?= e($st['error'] ?? '') ?></p>
  <?php else: ?><p class="<?= $age < 36 * 3600 ? 'ok' : 'er' ?>">גיבוי אחרון: <?= e(date('d/m/Y H:i', (int)$st['time'])) ?> · <?= round($st['size'] / 1048576, 2) ?>MB · <?= (int)$st['rows'] ?> שורות · <?= (int)$st['files'] ?> קבצים<?= $age >= 36 * 3600 ? ' – ישן מדי, בדקו את ה-cron' : '' ?></p><?php endif; ?>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="run"><button>הרצת גיבוי עכשיו</button></form>
  <p class="mu"><b>כל 10 דקות</b> נוצר גיבוי דלתא (רק מה שהשתנה מאז הגיבוי הקודם), ו<b>פעם ביום</b> גיבוי מלא של הכול. שניהם נשמרים בשרת ומועלים לדרייב. בשרת: 7 מלאים יומיים + 4 שבועיים (ימי ראשון) + דלתאות של 3 ימים. בדרייב: 14 יומיים + 8 שבועיים + 6 חודשיים + דלתאות של 3 ימים. כל קובץ מוצפן ונבדק אחרי היצירה (פענוח מלא וסכומי ביקורת).</p>
</div>
<div class="card sc">
  <h2>קבצים שמורים בשרת (<?= count($list) ?>)</h2>
  <table><tr><th>קובץ</th><th>סוג</th><th>תאריך</th><th>גודל</th><th>דרייב</th><th></th></tr>
  <?php foreach ($list as $b): ?><tr><td dir="ltr"><?= e($b['name']) ?></td><td><?= $b['type'] === 'full' ? 'מלא' : 'דלתא' ?></td><td><?= e(date('d/m/Y H:i', bk_stamp_ts($b['name']))) ?></td><td><bdi dir="ltr"><?= $b['size'] >= 1048576 ? round($b['size'] / 1048576, 2) . ' MB' : max(1, round($b['size'] / 1024)) . ' KB' ?></bdi></td><td><?= isset($bs['uploaded'][$b['name']]) ? '✓' : (gd_ready() ? 'ממתין' : '') ?></td>
  <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="dl"><input type="hidden" name="f" value="<?= e($b['name']) ?>"><button class="lnk">הורדה</button></form></td></tr><?php endforeach; ?>
  <?php if (!$list) echo '<tr><td colspan="6" class="mu">אין גיבויים.</td></tr>'; ?>
  </table>
</div>
<div class="card">
  <h2>עותק בגוגל דרייב</h2>
  <?php if (!empty($dst['time'])): ?><p class="<?= !empty($dst['ok']) ? 'ok' : 'er' ?>">פעילות אחרונה: <?= e(date('d/m/Y H:i', (int)$dst['time'])) ?> · <?= !empty($dst['ok']) ? e((string)($dst['file'] ?? '')) : e($dst['error'] ?? '') ?></p><?php elseif (!gd_ready()): ?><p class="mu">לא הוגדר. הגיבוי מוצפן, ולכן בטוח לשמור אותו בדרייב. אחרי ההגדרה, כל קובץ גיבוי (מלא או דלתא) יועלה לתיקיה אוטומטית.</p><?php endif; ?>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="gd_save">
    <label>Client ID<input name="gd_client" dir="ltr" class="wide" value="<?= e(gd_get('client')) ?>" autocomplete="off"></label>
    <label>Client Secret<input name="gd_secret" type="password" dir="ltr" placeholder="<?= gd_get('secret') !== '' ? 'שמור (השאירו ריק כדי לא לשנות)' : '' ?>" autocomplete="off"></label>
    <label>Refresh Token<input name="gd_refresh" type="password" dir="ltr" placeholder="<?= gd_get('refresh') !== '' ? 'שמור (השאירו ריק כדי לא לשנות)' : '' ?>" autocomplete="off"></label>
    <label>תיקיה (קישור או מזהה)<input name="gd_folder" dir="ltr" class="wide" value="<?= e(gd_get('folder')) ?>" autocomplete="off"></label><button>שמירה</button></form>
  <?php if (gd_ready()): ?><form method="post" class="bar"><?= csrf_field() ?><button name="act" value="gd_check" class="sec">בדיקת חיבור</button><button name="act" value="gd_run">סנכרון לדרייב עכשיו</button></form><?php endif; ?>
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
    <li>בשדות הזמן הקלידו ידנית: Minute <span dir="ltr"><code>*/10</code></span>, ובכל שאר השדות (Hour, Day, Month, Weekday) <span dir="ltr"><code>*</code></span>. אם כבר הוגדרה הרצה יומית, ערכו אותה לכל 10 דקות.</li>
    <li>בשדה Command הדביקו:<br><span dir="ltr"><code><?= e($cron) ?></code></span></li>
    <li>זה הכול: הסקריפט מחליט לבד מתי לעשות גיבוי מלא (פעם ביום אחרי 02:00) ומתי דלתא.</li>
  </ol>
  <p class="mu">אם הנתיב ל-PHP אצלכם שונה, cPanel מציג את הנתיב הנכון בדף ה-Cron Jobs.</p>
</div>
<div class="card">
  <h2>חשוב: שומרים מחוץ לשרת</h2>
  <ul>
    <li><b>ערכת שחזור:</b> בלי <span dir="ltr"><code>app.key</code></span> אי אפשר לפתוח אף גיבוי, וגם ההגדרות (<span dir="ltr"><code>config.php</code></span>) לא נמצאות בגיבוי. הורידו את הערכת פעם אחת, שמרו אותה אצלכם ושלחו אותה לעצמכם במייל (כדאי גם לשמור במנהל סיסמאות). הורידו שוב אחרי כל שינוי בהגדרות.</li>
    <li><b>שחזור:</b> במקרה אסון, ראו את מסמך <span dir="ltr"><code>docs/DISASTER_RECOVERY.md</code></span> במאגר (שלבים מפורטים, כולל שחזור לנקודת זמן).</li>
  </ul>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="kit"><label><input type="checkbox" name="kit_ok" value="1"> אני מבין/ה שהקובץ רגיש ושומר/ת אותו רק במקום בטוח</label><button class="sec">הורדת ערכת שחזור</button></form>
</div>
<?php page_foot();
