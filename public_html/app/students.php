<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_login();
$q = trim((string)($_GET['q'] ?? ''));
$g = (string)($_GET['g'] ?? '');
$c = trim((string)($_GET['c'] ?? ''));
$s = (string)($_GET['s'] ?? 'active');
$pg = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$w = []; $a = [];
if ($q !== '') { $w[] = '(name LIKE ? OR idn LIKE ? OR phone LIKE ? OR f_phone LIKE ? OR m_phone LIKE ?)'; $l = '%' . $q . '%'; array_push($a, $l, $l, $l, $l, $l); }
if (in_array($g, GRADES, true)) { $w[] = 'grade = ?'; $a[] = $g; }
if ($c !== '') { $w[] = 'city LIKE ?'; $a[] = '%' . $c . '%'; }
if (isset(STATUS[$s])) { $w[] = 'status = ?'; $a[] = $s; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$st = db()->prepare("SELECT COUNT(*) FROM students $where"); $st->execute($a); $total = (int)$st->fetchColumn();
$st = db()->prepare("SELECT id, name, grade, cls, idn, city, phone, status, family_id FROM students $where ORDER BY FIELD(grade,'ז','ח','ט','י','יא','יב'), cls, name LIMIT $per OFFSET " . (($pg - 1) * $per));
$st->execute($a); $rows = $st->fetchAll();
function mask(string $v): string { return $v === '' ? '' : str_repeat('•', max(0, mb_strlen($v) - 3)) . mb_substr($v, -3); }
page_head('תלמידות');
?>
<h1>תלמידות <span class="mu">(<?= $total ?> תוצאות)</span></h1>
<form class="bar" method="get">
  <label>חיפוש<input name="q" value="<?= e($q) ?>" placeholder="שם / ת״ז / טלפון"></label>
  <label>כיתה<select name="g"><option value="">הכל</option><?php foreach (GRADES as $x) echo '<option' . ($g === $x ? ' selected' : '') . '>' . e($x) . '</option>'; ?></select></label>
  <label>עיר<input name="c" value="<?= e($c) ?>" placeholder="למשל חיפה"></label>
  <label>סטטוס<select name="s"><option value="">הכל</option><?php foreach (STATUS as $k => $v) echo '<option value="' . $k . '"' . ($s === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <button>סינון</button>
  <?php if (can_edit()) echo '<a class="btn sec" href="student.php">+ תלמידה חדשה</a>'; ?>
</form>
<div class="card sc"><table>
<tr><th>#</th><th>שם</th><th>כיתה</th><th>ת״ז</th><th>עיר</th><th>טלפון</th><th>סטטוס</th><?php if (in_array($u['role'], COLLECTION_ROLES, true)) echo '<th>גבייה</th>'; ?></tr>
<?php foreach ($rows as $i => $r): ?>
<tr>
  <td><?= ($pg - 1) * $per + $i + 1 ?></td>
  <td><a href="student.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
  <td><?= e($r['grade'] . ($r['cls'] && $r['cls'] !== $r['grade'] ? ' (' . $r['cls'] . ')' : '')) ?></td>
  <td><?= can_see_ids() ? e(mask($r['idn'])) : '—' ?></td>
  <td><?= e($r['city']) ?></td>
  <td><?= e($r['phone']) ?></td>
  <td><span class="pill <?= e($r['status']) ?>"><?= e(STATUS[$r['status']] ?? $r['status']) ?></span></td>
  <?php if (in_array($u['role'], COLLECTION_ROLES, true)): ?><td><?php if ($r['family_id']): ?><a class="btn sec sm" href="family.php?id=<?= (int)$r['family_id'] ?>">לגבייה</a><?php else: ?><form method="post" action="family.php" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="create"><input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>"><button class="sec sm">פתיחת תיק</button></form><?php endif; ?></td><?php endif; ?>
</tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="8" class="mu">אין תוצאות.</td></tr>'; ?>
</table></div>
<?php
$pages = (int)ceil($total / $per);
if ($pages > 1) {
  echo '<p>';
  for ($i = 1; $i <= $pages; $i++) {
    $qs = http_build_query(['q' => $q, 'g' => $g, 'c' => $c, 's' => $s, 'p' => $i]);
    echo $i === $pg ? '<b>' . $i . '</b> ' : '<a href="?' . e($qs) . '">' . $i . '</a> ';
  }
  echo '</p>';
}
page_foot();
