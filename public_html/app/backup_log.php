<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$msg = ''; $ok = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? ''); $by = (string)($me['name'] ?: $me['username']);
  if ($act === 'alerts') {
    $to = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['to'] ?? '')) ?: []))));
    $badMail = array_filter($to, fn($x) => !filter_var($x, FILTER_VALIDATE_EMAIL));
    if ($badMail) { $ok = false; $msg = 'כתובת מייל לא תקינה: ' . implode(', ', $badMail); }
    else {
      setting_set('bk_alert_on', isset($_POST['on']) ? '1' : '0', $by);
      setting_set('bk_alert_delta_min', (string)max(11, min(1440, (int)($_POST['delta_min'] ?? 30))), $by);
      setting_set('bk_alert_full_hours', (string)max(2, min(168, (int)($_POST['full_hours'] ?? 26))), $by);
      setting_set('bk_alert_to', implode(',', $to), $by); audit('backup_alert_settings', 'system', '', 'הגדרות התראות גיבוי עודכנו'); $ok = true; $msg = 'ההגדרות נשמרו.';
    }
  } elseif ($act === 'alert_test') {
    $c = bk_alert_cfg(); $to = $c['to'] ?: array_column(notify_recipients(), 'email'); $n = 0;
    foreach ($to as $x) { try { if (send_mail($x, 'עלמא – בדיקת התראת גיבוי', 'זו הודעת בדיקה. כך תגיעו התראות כשגיבוי נכשל.')) $n++; } catch (Throwable $t) {} }
    $ok = $n > 0; $msg = $n ? "נשלחה הודעת בדיקה ל-$n כתובות." : 'לא נשלח (אין נמענים או שהשליחה נכשלה).';
  } elseif ($act === 'delta' || $act === 'full') {
    [$ok, $l] = bk_cycle($act); $msg = implode(' · ', $l); audit('backup_run', 'system', '', 'ידני (' . $act . '): ' . ($ok ? 'הצלחה' : $msg));
  }
}
$st = bk_state_load(); $cfg = bk_alert_cfg(); $now = time();
$beat = (int)($st['beat'] ?? 0); $beatAge = $beat ? $now - $beat : null;
$lastFull = null; foreach (array_reverse(bk_list_all()) as $f) if ($f['type'] === 'full') { $lastFull = $f; break; }
$up = $st['uploaded'] ?? []; $pending = 0; foreach (bk_list_all() as $f) if (!isset($up[$f['name']])) $pending++;
$dr = $st['drive'] ?? [];
// סינון ועימוד
$K = ['' => 'הכול', 'full' => 'מלא', 'delta' => 'דלתא', 'prune' => 'ניקוי ישנים', 'verify' => 'בדיקת תקינות', 'alert' => 'התראות'];
$fk = (string)($_GET['k'] ?? ''); if (!isset($K[$fk])) $fk = ''; $ft = (string)($_GET['t'] ?? ''); if (!in_array($ft, ['server', 'drive'], true)) $ft = ''; $fs = (string)($_GET['s'] ?? ''); if (!in_array($fs, ['ok', 'fail'], true)) $fs = '';
$w = []; $a = []; if ($fk) { $w[] = 'kind = ?'; $a[] = $fk; } if ($ft) { $w[] = 'target = ?'; $a[] = $ft; } if ($fs) { $w[] = 'status = ?'; $a[] = $fs; }
$wh = $w ? 'WHERE ' . implode(' AND ', $w) : ''; $per = 100; $p = max(1, (int)($_GET['p'] ?? 1));
$q = db()->prepare("SELECT COUNT(*) FROM backup_log $wh"); $q->execute($a); $total = (int)$q->fetchColumn(); $pages = max(1, (int)ceil($total / $per)); $p = min($p, $pages);
$q = db()->prepare("SELECT * FROM backup_log $wh ORDER BY id DESC LIMIT $per OFFSET " . (($p - 1) * $per)); $q->execute($a); $rows = $q->fetchAll();
$fails24 = (int)db()->query("SELECT COUNT(*) FROM backup_log WHERE status='fail' AND started_at > '" . date('Y-m-d H:i:s', $now - 86400) . "'")->fetchColumn();
$KL = ['full' => 'גיבוי מלא', 'delta' => 'דלתא', 'prune' => 'ניקוי ישנים', 'verify' => 'בדיקת תקינות', 'alert' => 'התראה', 'drive' => 'דרייב'];
$fmt = fn(int $b) => $b >= 1048576 ? round($b / 1048576, 2) . ' MB' : ($b > 0 ? max(1, round($b / 1024)) . ' KB' : '');
$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['k' => $fk, 't' => $ft, 's' => $fs, 'p' => $p], $o), fn($v) => $v !== '' && $v !== 1));
page_head('יומן גיבויים');
?>
<h1>יומן גיבויים</h1>
<?php if ($msg) echo '<p class="' . ($ok ? 'ok' : 'er') . '">' . e($msg) . '</p>'; ?>
<div class="card">
  <h2>מצב</h2>
  <?php if ($beatAge === null): ?><p class="er">עדיין לא רץ גיבוי אוטומטי. הגדירו את ה-cron כל 10 דקות (ראו דף "גיבויים").</p>
  <?php elseif ($beatAge > 25 * 60): ?><p class="er">הגיבוי האוטומטי לא הצליח כבר <?= (int)round($beatAge / 60) ?> דקות (בפעם האחרונה: <?= e(date('d/m/Y H:i', $beat)) ?>). בדקו שה-cron פעיל בכל 10 דקות. אם ה-cron עצמו נעצר, לא יישלח מייל, ולכן ההודעה הזאת היא ההתרעה.</p>
  <?php else: ?><p class="ok">הגיבוי האוטומטי תקין. בדיקה אחרונה: <?= e(date('d/m/Y H:i', $beat)) ?> (לפני <?= (int)round($beatAge / 60) ?> דקות).</p><?php endif; ?>
  <p>גיבוי מלא אחרון: <b><?= $lastFull ? e(date('d/m/Y H:i', bk_stamp_ts($lastFull['name']))) . ' · <bdi dir="ltr">' . $fmt($lastFull['size']) . '</bdi>' : 'אין' ?></b>
    · דרייב: <b><?= !gd_ready() ? 'לא הוגדר' : ($pending ? $pending . ' קבצים ממתינים להעלאה' : 'מסונכרן') ?></b>
    <?php if (gd_ready() && !empty($dr['time'])): ?>(העלאה אחרונה: <?= e(date('d/m H:i', (int)$dr['time'])) ?>)<?php endif; ?>
    · כשלונות ב-24 שעות אחרונות: <b class="<?= $fails24 ? 'er' : '' ?>"><?= $fails24 ?></b></p>
  <?php if (gd_ready() && empty($dr['ok']) && !empty($dr['error'])): ?><p class="er">שגיאת דרייב אחרונה: <?= e($dr['error']) ?></p><?php endif; ?>
  <form method="post" class="bar"><?= csrf_field() ?><button name="act" value="delta" class="sec">גיבוי דלתא עכשיו</button><button name="act" value="full" class="sec">גיבוי מלא עכשיו</button></form>
</div>
<div class="card">
  <h2>התראות במייל</h2>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="alerts">
    <label><input type="checkbox" name="on" value="1" <?= $cfg['on'] ? 'checked' : '' ?>> לשלוח התראות כשיש תקלה</label>
    <label>דלתא לא הצליחה במשך (דקות)<input type="number" name="delta_min" min="11" max="1440" value="<?= (int)$cfg['delta_min'] ?>"></label>
    <label>גיבוי מלא ישן מ-(שעות)<input type="number" name="full_hours" min="2" max="168" value="<?= (int)$cfg['full_hours'] ?>"></label>
    <label>נמענים (מופרדים בפסיק; ריק = נמעני ההתראות של המערכת)<input name="to" dir="ltr" class="wide" value="<?= e(implode(', ', $cfg['to'])) ?>"></label>
    <button>שמירה</button></form>
  <form method="post" class="bar"><?= csrf_field() ?><button name="act" value="alert_test" class="sec">שליחת הודעת בדיקה</button></form>
  <p class="mu">נשלחת התראה כשגיבוי נכשל, כשלא הצליחה דלתא מעבר לזמן שהוגדר, כשהגיבוי המלא ישן מדי, וכשההעלאה לדרייב נכשלת שוב ושוב או תקועה. אותה בעיה לא תישלח שוב במשך 6 שעות.</p>
</div>
<div class="card sc">
  <h2>יומן (<?= $total ?>)</h2>
  <form method="get" class="bar">
    <label>סוג<select name="k"><?php foreach ($K as $v => $l) echo '<option value="' . e($v) . '"' . ($v === $fk ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></label>
    <label>יעד<select name="t"><option value="">הכול</option><option value="server"<?= $ft === 'server' ? ' selected' : '' ?>>שרת</option><option value="drive"<?= $ft === 'drive' ? ' selected' : '' ?>>דרייב</option></select></label>
    <label>תוצאה<select name="s"><option value="">הכול</option><option value="ok"<?= $fs === 'ok' ? ' selected' : '' ?>>הצלחה</option><option value="fail"<?= $fs === 'fail' ? ' selected' : '' ?>>כשלון</option></select></label>
    <button class="sec">סינון</button></form>
  <table><tr><th>תאריך ושעה</th><th>פעולה</th><th>יעד</th><th>תוצאה</th><th>קובץ</th><th>מיקום</th><th>גודל</th><th>שורות / קבצים</th><th>משך</th><th>פירוט</th></tr>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= e(date('d/m/Y H:i:s', (int)strtotime($r['started_at']))) ?></td>
    <td><?= e($KL[$r['kind']] ?? $r['kind']) ?></td><td><?= $r['target'] === 'drive' ? 'דרייב' : 'שרת' ?></td>
    <td class="<?= $r['status'] === 'ok' ? 'ok' : 'er' ?>"><?= $r['status'] === 'ok' ? 'הצליח' : 'נכשל' ?></td>
    <td dir="ltr"><?= e($r['file']) ?></td><td><?= e($r['location']) ?></td><td><bdi dir="ltr"><?= e($fmt((int)$r['size_bytes'])) ?></bdi></td>
    <td><?= $r['rows_n'] || $r['files_n'] ? (int)$r['rows_n'] . ' / ' . (int)$r['files_n'] : '' ?></td><td><?= $r['secs'] > 0 ? e((string)round((float)$r['secs'], 1)) . ' ש׳' : '' ?></td><td><?= e($r['detail']) ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="10" class="mu">אין רשומות.</td></tr>'; ?>
  </table>
  <?php if ($pages > 1): ?><p class="bar"><?php if ($p > 1) echo '<a href="' . e($qs(['p' => $p - 1])) . '">הקודם</a> '; ?>עמוד <?= $p ?> מתוך <?= $pages ?><?php if ($p < $pages) echo ' <a href="' . e($qs(['p' => $p + 1])) . '">הבא</a>'; ?></p><?php endif; ?>
  <p class="mu">דלתא שבה לא השתנה דבר לא יוצרת קובץ ולא נרשמת כאן; הבדיקה האחרונה מופיעה למעלה. הרשומות נשמרות 90 יום.</p>
</div>
<?php page_foot();
