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
$st = db()->prepare("SELECT id, name, grade, cls, idn, city, addr, email, phone, f_name, f_phone, m_name, m_phone, status, family_id FROM students $where ORDER BY FIELD(grade,'ז','ח','ט','י','יא','יב'), cls, name LIMIT $per OFFSET " . (($pg - 1) * $per));
$st->execute($a); $rows = $st->fetchAll();
function mask(string $v): string { return $v === '' ? '' : str_repeat('•', max(0, mb_strlen($v) - 3)) . mb_substr($v, -3); }
page_head('תלמידות');
?>
<h1>תלמידות <span class="mu">(<?= $total ?> תוצאות)</span></h1>
<form class="bar auto" method="get">
  <label>חיפוש<input name="q" value="<?= e($q) ?>" placeholder="שם / ת״ז / טלפון"></label>
  <label>כיתה<select name="g"><option value="">הכל</option><?php foreach (GRADES as $x) echo '<option' . ($g === $x ? ' selected' : '') . '>' . e($x) . '</option>'; ?></select></label>
  <label>עיר<input name="c" value="<?= e($c) ?>" placeholder="למשל חיפה"></label>
  <label>סטטוס<select name="s"><option value="">הכל</option><?php foreach (STATUS as $k => $v) echo '<option value="' . $k . '"' . ($s === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <button>OK</button>
  <a class="btn sec<?= ($q !== '' || $g !== '' || $c !== '' || $s !== 'active') ? ' hot' : '' ?>" href="students.php" title="איפוס כל הסינונים">↺ איפוס</a>
  <?php if (can_edit()) echo '<a class="btn sec" href="student.php">+ תלמידה חדשה</a>'; ?>
</form>
<?php sec_open('list', 'רשימת התלמידות', $total . ' תוצאות', '☺', ['list']); ?>
<?php $coll = in_array($u['role'], COLLECTION_ROLES, true); $off0 = ($pg - 1) * $per; ?>
<div class="ut stu">
  <div class="ur uth" aria-hidden="true"><span>#</span><span>שם</span><span>כיתה</span><span>עיר</span><span>טלפון</span><span>סטטוס</span><span></span></div>
<?php foreach ($rows as $i => $r): ?>
  <details class="uw">
    <summary class="ur">
      <span class="c idx"><?= $off0 + $i + 1 ?></span>
      <span class="c nm"><b><?= e($r['name']) ?></b></span>
      <span class="c" data-l="כיתה"><?= e($r['grade'] . ($r['cls'] && $r['cls'] !== $r['grade'] ? ' (' . $r['cls'] . ')' : '')) ?></span>
      <span class="c" data-l="עיר"><?= $r['city'] !== '' ? e($r['city']) : '<span class="mu">—</span>' ?></span>
      <span class="c" data-l="טלפון"><?= $r['phone'] !== '' ? '<bdi dir="ltr">' . e($r['phone']) . '</bdi>' : '<span class="mu">—</span>' ?></span>
      <span class="c stt"><span class="pill <?= e($r['status']) ?>"><?= e(STATUS[$r['status']] ?? $r['status']) ?></span></span>
      <span class="chev" aria-hidden="true">⌄</span>
    </summary>
    <div class="mgb dt">
      <dl class="kv">
        <div><dt>תעודת זהות</dt><dd><?= can_see_ids() && $r['idn'] !== '' ? '<bdi dir="ltr">' . e(mask($r['idn'])) . '</bdi>' : '<span class="mu">—</span>' ?></dd></div>
        <div><dt>כתובת</dt><dd><?= $r['addr'] !== '' || $r['city'] !== '' ? e(trim($r['addr'] . ', ' . $r['city'], ' ,')) : '<span class="mu">—</span>' ?></dd></div>
        <div><dt>מייל</dt><dd><?= $r['email'] !== '' ? '<bdi dir="ltr">' . e($r['email']) . '</bdi>' : '<span class="mu">—</span>' ?></dd></div>
      </dl>
      <div class="tels">
        <?php foreach ([['אבא', $r['f_name'], $r['f_phone']], ['אמא', $r['m_name'], $r['m_phone']]] as [$lab, $nm, $ph]) if (trim($ph) !== ''): ?>
          <a class="tel" href="<?= e(tel_href($ph)) ?>"><svg class="ni" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg><span><b><?= e($lab) ?></b><?= trim($nm) !== '' ? ' ' . e($nm) : '' ?></span><bdi dir="ltr"><?= e($ph) ?></bdi></a>
        <?php endif; ?>
      </div>
      <div class="acts">
        <a class="btn" href="student.php?id=<?= (int)$r['id'] ?>">כרטיס התלמידה</a>
        <?php if ($coll): if ($r['family_id']): ?><a class="btn sec" href="family.php?id=<?= (int)$r['family_id'] ?>">לתיק הגבייה</a><?php else: ?><form method="post" action="family.php" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="create"><input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>"><button class="sec">פתיחת תיק גבייה</button></form><?php endif; endif; ?>
      </div>
    </div>
  </details>
<?php endforeach; if (!$rows) echo '<p class="mu emp">אין תוצאות.</p>'; ?>
</div>
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
sec_close();
page_foot();
