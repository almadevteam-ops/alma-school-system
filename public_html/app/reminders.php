<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$msg = ''; $err = '';
if (!rem_ready()) { page_head('תזכורות'); echo '<h1>תזכורות</h1><p class="aptn">כדי להפעיל תזכורות יש להריץ "עדכון מסד נתונים" (תחזוקה).</p>'; page_foot(); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $rid = (int)($_POST['rid'] ?? 0); $act = (string)($_POST['act'] ?? '');
  $q = db()->prepare('SELECT * FROM reminders WHERE id = ?'); $q->execute([$rid]); $rr = $q->fetch();
  if (!$rr) $err = 'התזכורת לא נמצאה.';
  elseif ($act === 'rem_off') { db()->prepare("UPDATE reminders SET status = 'off', next_at = NULL WHERE id = ?")->execute([$rid]); audit('reminder_off', 'family', (string)$rr['family_id'], (string)$rr['title']); $msg = 'התזכורת בוטלה.'; }
  elseif ($act === 'rem_snooze' && $rr['status'] === 'active') {
    $nx = date('Y-m-d', strtotime('+7 days')); if ($rr['kind'] === 'monthly') $nx = min($nx, (string)($rr['until_at'] ?: $nx));
    db()->prepare('UPDATE reminders SET next_at = ?, last_err = \'\' WHERE id = ?')->execute([$nx, $rid]); audit('reminder_snooze', 'family', (string)$rr['family_id'], (string)$rr['title']); $msg = 'התזכורת נדחתה לשבוע (' . date_out($nx) . ').';
  }
}
rem_lazy();
$rows = db()->query("SELECT r.*, f.name fname, u.display_name who FROM reminders r JOIN families f ON f.id = r.family_id LEFT JOIN users u ON u.id = r.user_id WHERE r.status = 'active' ORDER BY r.next_at, r.id")->fetchAll();
$today = date('Y-m-d');
page_head('תזכורות');
?>
<h1>תזכורות גבייה <span class="mu">(<?= count($rows) ?>)</span></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<p class="mu">תזכורות נוצרות בתיק הגבייה של כל משפחה (אזור "תזכורות"). המייל נשלח בבוקר היום שנקבע, אל מי שיצר את התזכורת.</p>
<?php foreach ($rows as $r): $late = $r['next_at'] !== null && $r['next_at'] < $today; ?>
  <div class="rem<?= $r['last_err'] !== '' ? ' rx' : '' ?>">
    <div class="rt"><span class="rb" aria-hidden="true">!!</span><a href="family.php?id=<?= (int)$r['family_id'] ?>&amp;open=rem"><b>משפחת <?= e($r['fname']) ?></b></a><span><?= e($r['title']) ?></span><span class="rw"><?= e(rem_when($r)) ?></span></div>
    <?php if ($r['body'] !== ''): ?><p class="rbd"><?= nl2br(e($r['body'])) ?></p><?php endif; ?>
    <div class="rm"><span>הבאה: <b><?= e(date_out($r['next_at'])) ?></b><?= $late ? ' (באיחור)' : ($r['next_at'] === $today ? ' (היום)' : '') ?></span><span>נוצרה ע"י <?= e((string)$r['who']) ?><?= (int)$r['user_id'] === (int)$u['id'] ? ' (את)' : '' ?></span>
      <?php if ($r['last_err'] !== ''): ?><span class="er">⚠ לא נשלחה: <?= e($r['last_err']) ?></span><?php endif; ?></div>
    <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
      <button name="act" value="rem_snooze" class="sec">דחייה בשבוע</button> <button name="act" value="rem_off" class="dg" data-confirm="לבטל את התזכורת?">ביטול</button></form>
  </div>
<?php endforeach; if (!$rows) echo '<p class="emp">אין תזכורות פעילות.</p>'; ?>
<?php page_foot();
