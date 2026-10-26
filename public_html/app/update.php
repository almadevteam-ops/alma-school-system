<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
// עדכון מסד נתונים. מינימלי בכוונה: עובד גם לפני שהעדכון הושלם.
$u = user();
if (!$u || $u['role'] !== 'admin') redirect('login.php');
$st = db()->prepare('SELECT active, pw_ver FROM users WHERE id = ?'); $st->execute([$u['id']]); $r = $st->fetch();
if (!$r || !(int)$r['active'] || (int)$r['pw_ver'] !== (int)($_SESSION['pwv'] ?? -1)) { session_wipe(); redirect('login.php'); }
$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $done = migrate();
  foreach ($done as $n) audit('db_migration', 'system', $n);
}
page_head('עדכון מערכת');
?>
<h1>עדכון מסד נתונים</h1>
<div class="card">
<?php if ($done !== null): ?>
  <p class="ok">העדכון הושלם<?= $done ? ': ' . e(implode(', ', $done)) : '' ?>.</p>
  <a class="btn" href="students.php">המשך למערכת</a>
<?php elseif (migrations_pending()): ?>
  <p>יש עדכון מסד נתונים שממתין. מומלץ לגבות לפני כן (cPanel, Backup).</p>
  <form method="post"><?= csrf_field() ?><button>הרצת העדכון</button></form>
<?php else: ?>
  <p class="ok">אין עדכונים ממתינים.</p><a class="btn" href="students.php">חזרה</a>
<?php endif; ?>
</div>
<?php page_foot();
