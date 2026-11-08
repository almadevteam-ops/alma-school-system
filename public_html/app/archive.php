<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $sid = (int)($_POST['sid'] ?? 0);
  $st = db()->prepare('SELECT name FROM students WHERE id = ? AND archived_at IS NOT NULL'); $st->execute([$sid]); $nm = $st->fetchColumn();
  if ($nm === false) fail_page(404, 'התלמידה לא נמצאה בארכיון.');
  db()->prepare("UPDATE students SET archived_at = NULL, archived_by = '' WHERE id = ?")->execute([$sid]);
  audit('unarchive', 'student', (string)$sid, (string)$nm);
  $msg = 'התלמידה ' . $nm . ' הוחזרה לרשימת התלמידות.';
}
$rows = db()->query("SELECT id, name, grade, cls, status, archived_at, archived_by, (SELECT COUNT(*) FROM student_notes n WHERE n.student_id = students.id) notes FROM students WHERE archived_at IS NOT NULL ORDER BY archived_at DESC")->fetchAll();
page_head('ארכיון');
?>
<h1>ארכיון תלמידות <span class="mu">(<?= count($rows) ?>)</span></h1>
<p class="mu">תלמידות שהועברו לארכיון לא מופיעות ברשימות ובגבייה, אבל כל הרשומות שלהן (חיובים, תשלומים, הערות ומסמכים) נשמרות. אפשר להחזיר אותן בכל עת.</p>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; ?>
<?php if (!$rows): ?><p class="emp">הארכיון ריק.</p><?php else: ?>
<div class="sc"><table>
  <tr><th>שם</th><th>כיתה</th><th>סטטוס בעת ההעברה</th><th>הועברה</th><th>על ידי</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a href="student.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
    <td><?= e(trim($r['grade'] . ' ' . $r['cls'])) ?></td>
    <td><?= e(STATUS[$r['status']] ?? $r['status']) ?></td>
    <td><bdi dir="ltr"><?= e(date('d/m/Y H:i', strtotime((string)$r['archived_at']))) ?></bdi></td>
    <td><?= e($r['archived_by']) ?></td>
    <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="sid" value="<?= (int)$r['id'] ?>"><button class="sec">החזרה מהארכיון</button></form></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; page_foot();
