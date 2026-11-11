<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$msg = ''; $ok = null;
/** סיסמה משותפת בין המערכת לסקריפט: נוצרת פעם אחת ונשמרת מוצפנת עד לשמירת החיבור. */
function gds_suggest(): string {
  $v = setting_get('gd_sskey_sugg', ''); $k = $v !== '' ? dec_bytes((string)base64_decode($v, true), 'gdrive') : null;
  if ($k) return $k; $k = bin2hex(random_bytes(20)); setting_set('gd_sskey_sugg', base64_encode(enc_bytes($k, 'gdrive')), 'system'); return $k;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if (in_array($act, ['gd_save', 'gds_save'], true) && gd_ready() && ($_POST['gd_confirm'] ?? '') !== '1') { $ok = false; $msg = 'כדי לשנות חיבור קיים סמנו את תיבת האישור.'; $act = 'noop'; $_GET['edit'] = '1'; }
  if ($act === 'run') { [$ok, $msg] = bk_run(); audit('backup_run', 'system', '', $ok ? 'ידני: הצלחה' : 'ידני: ' . $msg); }
  elseif ($act === 'gd_save') {
    $cl = trim((string)($_POST['gd_client'] ?? '')); $fo = trim((string)($_POST['gd_folder'] ?? ''));
    if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $fo, $mm)) $fo = $mm[1];
    if ($cl === '' || !preg_match('/^[A-Za-z0-9_-]{10,}$/', $fo)) { $ok = false; $msg = 'נא למלא Client ID ומזהה תיקיה (אפשר להדביק את קישור התיקיה).'; }
    else { setting_set('gd_mode', 'oauth', (string)($me['name'] ?: $me['username'])); gd_save($cl, trim((string)($_POST['gd_secret'] ?? '')), trim((string)($_POST['gd_refresh'] ?? '')), $fo, (string)($me['name'] ?: $me['username'])); audit('gdrive_save', 'system', '', 'הגדרות דרייב עודכנו'); $ok = true; $msg = 'נשמר. לחצו "בדיקת חיבור".'; }
  } elseif ($act === 'gds_save') {
    $u = trim((string)($_POST['gds_url'] ?? ''));
    if (!preg_match('~^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$~', $u)) { $ok = false; $msg = 'הדביקו את כתובת ה-Web App המלאה (נגמרת ב-/exec).'; }
    else { $key = gd_get('sskey') !== '' ? '' : gds_suggest(); gd_save_script($u, $key, (string)($me['name'] ?: $me['username'])); setting_set('gd_sskey_sugg', '', 'system'); audit('gdrive_save', 'system', '', 'חיבור דרייב דרך Apps Script עודכן'); $ok = true; $msg = 'נשמר. לחצו "בדיקת חיבור".'; }
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
$edit = isset($_GET['edit']) || !gd_ready(); $showCode = !gd_ready() || isset($_GET['code']);
$bb = (int)(bk_state_load()['beat'] ?? 0); $healthy = $bb && time() - $bb < 25 * 60;
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
  <?php if (!$edit): ?>
    <p>מחובר <b>✓</b> דרך <?= gd_mode() === 'script' ? 'Google Apps Script' : 'Google Cloud (OAuth)' ?>. הפרטים מוסתרים כדי שלא ישתנו בטעות.</p>
    <form method="post" class="bar"><?= csrf_field() ?><button name="act" value="gd_check" class="sec">בדיקת חיבור</button><button name="act" value="gd_run">סנכרון לדרייב עכשיו</button><a href="?edit=1">עריכת חיבור</a></form>
  <?php else: ?>
  <?php if (gd_ready()): ?><p class="er">מצב עריכה: שינוי הפרטים עלול לנתק את הגיבוי לדרייב. <a href="backups.php">ביטול וחזרה</a></p><?php endif; ?>
  <?php if ($showCode) { $sk = gd_get('sskey') !== '' ? gd_get('sskey') : gds_suggest(); } $code = str_replace('הדביקו כאן סיסמה ארוכה שהמערכת הציעה', $sk, (string)(require PRIV . '/lib/gdrive_script.php')); ?>
  <details<?= gd_mode() === 'script' || !gd_ready() ? ' open' : '' ?>><summary><b>חיבור פשוט: Google Apps Script</b> (מומלץ, בלי Google Cloud ובלי כרטיס אשראי)</summary>
    <ol>
      <li>בדרייב של החשבון שיחזיק את הגיבויים: יוצרים תיקיה "גיבויי עלמא", פותחים אותה, ומעתיקים מהכתובת את המזהה (הטקסט שאחרי <span dir="ltr"><code>/folders/</code></span>).</li>
      <li>נכנסים ל-<span dir="ltr">script.google.com</span> (עם אותו חשבון) ← <b>New project</b>. מוחקים את הקוד שיש ומדביקים את הקוד שלמטה. בשורה <span dir="ltr"><code>FOLDER_ID</code></span> מחליפים את הטקסט במזהה התיקיה. הסיסמה כבר מולאה.</li>
      <li>לוחצים <b>Deploy ← New deployment</b>, בגלגל בוחרים <b>Web app</b>. Execute as: <b>Me</b>. Who has access: <b>Anyone</b>. לוחצים Deploy ומאשרים הרשאות (Advanced ← Go to project ← Allow).</li>
      <li>מעתיקים את ה-<b>Web app URL</b> (נגמר ב-<span dir="ltr"><code>/exec</code></span>) ומדביקים כאן.</li>
    </ol>
    <?php if (!$showCode): ?><p><a href="?edit=1&amp;code=1">הצגת קוד הסקריפט (כולל הסיסמה המשותפת)</a></p><?php else: ?>
    <textarea readonly rows="10" dir="ltr" class="wide"><?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea><?php endif; ?>
    <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="gds_save">
      <label>כתובת ה-Web App<input name="gds_url" dir="ltr" class="wide" value="<?= e(gd_get('url')) ?>" placeholder="https://script.google.com/macros/s/.../exec" autocomplete="off"></label><?php if (gd_ready()): ?><label><input type="checkbox" name="gd_confirm" value="1"> אני מבין/ה ששינוי עלול לנתק את הגיבוי לדרייב</label><?php endif; ?><button>שמירה</button></form>
    <p class="mu">הסקריפט יכול לגעת רק בתיקיה שהגדרתם, ורק בקבצים ששמם הוא של גיבוי עלמא. הכתובת לבדה לא מספיקה לאף אחד: בלי הסיסמה המשותפת הסקריפט מסרב. אם תחליפו את הקוד בסקריפט, צריך Deploy ← Manage deployments ← עריכה ← New version כדי שהשינוי ייכנס לתוקף.</p>
  </details>
  <details<?= gd_mode() === 'oauth' && gd_ready() ? ' open' : '' ?>><summary>חיבור חלופי: OAuth דרך Google Cloud</summary>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="gd_save">
    <label>Client ID<input name="gd_client" dir="ltr" class="wide" value="<?= e(gd_get('client')) ?>" autocomplete="off"></label>
    <label>Client Secret<input name="gd_secret" type="password" dir="ltr" placeholder="<?= gd_get('secret') !== '' ? 'שמור (השאירו ריק כדי לא לשנות)' : '' ?>" autocomplete="off"></label>
    <label>Refresh Token<input name="gd_refresh" type="password" dir="ltr" placeholder="<?= gd_get('refresh') !== '' ? 'שמור (השאירו ריק כדי לא לשנות)' : '' ?>" autocomplete="off"></label>
    <label>תיקיה (קישור או מזהה)<input name="gd_folder" dir="ltr" class="wide" value="<?= e(gd_get('folder')) ?>" autocomplete="off"></label><?php if (gd_ready()): ?><label><input type="checkbox" name="gd_confirm" value="1"> אני מבין/ה ששינוי עלול לנתק את הגיבוי לדרייב</label><?php endif; ?><button>שמירה</button></form>
  <details><summary>איך מקבלים את הפרטים (פעם אחת, כ-10 דקות)</summary><ol>
    <li>ב-<span dir="ltr">console.cloud.google.com</span> יוצרים פרויקט, מפעילים <b>Google Drive API</b>, ובמסך OAuth consent מוסיפים את המייל שלכם כ-Test user.</li>
    <li>ב-Credentials יוצרים <b>OAuth client ID</b> מסוג Web application, ומוסיפים Authorized redirect URI: <span dir="ltr"><code>https://developers.google.com/oauthplayground</code></span>. מעתיקים את ה-Client ID וה-Secret.</li>
    <li>נכנסים ל-<span dir="ltr">developers.google.com/oauthplayground</span>, בגלגל השיניים מסמנים Use your own OAuth credentials ומדביקים אותם, בוחרים את ההרשאה <span dir="ltr"><code>https://www.googleapis.com/auth/drive</code></span>, מאשרים, ולוחצים Exchange authorization code. מעתיקים את <b>Refresh token</b>.</li>
    <li>יוצרים בדרייב תיקיה ייעודית (למשל "גיבויי עלמא") ומדביקים כאן את הקישור שלה.</li>
  </ol><p class="mu">הקובץ שעולה מוצפן במפתח המערכת, כך שגם מי שיקבל גישה לדרייב לא יוכל לקרוא אותו. את מפתח ההצפנה (<span dir="ltr">private/app.key</span>) שומרים בנפרד.</p></details>
  </details>
  <?php if (gd_ready()): ?><form method="post" class="bar"><?= csrf_field() ?><button name="act" value="gd_check" class="sec">בדיקת חיבור</button><button name="act" value="gd_run">סנכרון לדרייב עכשיו</button></form><?php endif; ?>
  <?php endif; ?>
</div>
<div class="card">
  <details<?= $healthy ? '' : ' open' ?>><summary><h2 class="inl">הפעלה אוטומטית (cron)<?= $healthy ? ' ✓ פועל' : '' ?></h2></summary>
  <ol>
    <li>ב-cPanel פתחו <b>Cron Jobs</b>.</li>
    <li>בשדות הזמן הקלידו ידנית: Minute <span dir="ltr"><code>*/10</code></span>, ובכל שאר השדות (Hour, Day, Month, Weekday) <span dir="ltr"><code>*</code></span>. אם כבר הוגדרה הרצה יומית, ערכו אותה לכל 10 דקות.</li>
    <li>בשדה Command הדביקו:<br><span dir="ltr"><code><?= e($cron) ?></code></span></li>
    <li>זה הכול: הסקריפט מחליט לבד מתי לעשות גיבוי מלא (פעם ביום אחרי 02:00) ומתי דלתא.</li>
  </ol>
  <p class="mu">אם הנתיב ל-PHP אצלכם שונה, cPanel מציג את הנתיב הנכון בדף ה-Cron Jobs.</p>
</details>
</div>
<div class="card">
  <details<?= $healthy && gd_ready() ? '' : ' open' ?>><summary><h2 class="inl">חשוב: שומרים מחוץ לשרת (ערכת שחזור)</h2></summary>
  <ul>
    <li><b>ערכת שחזור:</b> בלי <span dir="ltr"><code>app.key</code></span> אי אפשר לפתוח אף גיבוי, וגם ההגדרות (<span dir="ltr"><code>config.php</code></span>) לא נמצאות בגיבוי. הורידו את הערכת פעם אחת, שמרו אותה אצלכם ושלחו אותה לעצמכם במייל (כדאי גם לשמור במנהל סיסמאות). הורידו שוב אחרי כל שינוי בהגדרות.</li>
    <li><b>שחזור:</b> במקרה אסון, ראו את מסמך <span dir="ltr"><code>docs/DISASTER_RECOVERY.md</code></span> במאגר (שלבים מפורטים, כולל שחזור לנקודת זמן).</li>
  </ul>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="kit"><label><input type="checkbox" name="kit_ok" value="1"> אני מבין/ה שהקובץ רגיש ושומר/ת אותו רק במקום בטוח</label><button class="sec">הורדת ערכת שחזור</button></form>
</details>
</div>
<?php page_foot();
