<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_login();
$q = trim((string)($_GET['q'] ?? ''));
$g = (string)($_GET['g'] ?? '');
$c = trim((string)($_GET['c'] ?? ''));
$s = (string)($_GET['s'] ?? 'active');
$x = (string)($_GET['x'] ?? '');
const XF = ['apt' => 'בדירה', 'vaada' => 'עברה ועדה', 's07' => 'מוגדרת 07', 'disc' => 'הנחה', 'pend' => 'הנחה ממתינה', 'allergy' => 'יש רגישות', 'divdoc' => 'חסר מסמך גרושים'];
$pg = max(1, (int)($_GET['p'] ?? 1));
$per = 100;
$w = ['archived_at IS NULL']; $a = [];
if ($q !== '') { $w[] = '(name LIKE ? OR idn LIKE ? OR phone LIKE ? OR f_phone LIKE ? OR m_phone LIKE ?)'; $l = '%' . $q . '%'; array_push($a, $l, $l, $l, $l, $l); }
if (in_array($g, GRADES, true)) { $w[] = 'grade = ?'; $a[] = $g; }
if ($c !== '') { $w[] = 'city LIKE ?'; $a[] = '%' . $c . '%'; }
if (isset(STATUS[$s])) { $w[] = 'status = ?'; $a[] = $s; }
if ($x === 'apt') $w[] = '(is_apt = 1 OR family_id IN (SELECT id FROM families WHERE payer_kind = \'org\'))';
elseif ($x === 'vaada') $w[] = 'vaada = 1'; elseif ($x === 's07') $w[] = 's07 = 1'; elseif ($x === 'allergy') $w[] = 'allergy = 1';
elseif ($x === 'divdoc') $w[] = "(marital = 'divorced' AND NOT EXISTS (SELECT 1 FROM student_files sf WHERE sf.student_id = students.id AND sf.kind = 'divorce'))"; elseif ($x === 'disc') $w[] = 'disc_on = 1'; elseif ($x === 'pend') $w[] = "disc_on = 1 AND disc_status = 'pending'";
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$st = db()->prepare("SELECT COUNT(*) FROM students $where"); $st->execute($a); $total = (int)$st->fetchColumn();
$st = db()->prepare("SELECT id, name, grade, cls, is_apt, vaada, s07, allergy, disc_on, disc_status, idn, city, addr, house_no, email, phone, f_name, f_phone, m_name, m_phone, status, family_id, (SELECT f.payer_kind FROM families f WHERE f.id = students.family_id) pk, (SELECT p.name FROM families f JOIN payers p ON p.id = f.payer_id WHERE f.id = students.family_id) payer_name FROM students $where ORDER BY FIELD(grade,'ז','ח','ט','י','יא','יב'), cls, name LIMIT $per OFFSET " . (($pg - 1) * $per));
$st->execute($a); $rows = $st->fetchAll();
function mask(string $v): string { return $v === '' ? '' : str_repeat('•', max(0, mb_strlen($v) - 3)) . mb_substr($v, -3); }
function stu_rows(array $rows, int $off0, bool $coll): void { ?>
<?php foreach ($rows as $i => $r): ?>
  <details class="uw">
    <summary class="ur">
      <span class="c idx"><?= $off0 + $i + 1 ?></span>
      <span class="c nm"><b><?= e($r['name']) ?></b><?= ($r['pk'] ?? '') === 'org' || $r['is_apt'] ? ' <span class="apt" title="' . e('משלם: ' . ($r['payer_name'] ?? 'מורשת גד')) . '">בדירה</span>' : '' ?><?php $fl = []; if ($r['vaada']) $fl[] = 'ועדה'; if ($r['s07']) $fl[] = '07'; if ($r['allergy']) $fl[] = 'רגישות'; if ($r['disc_on']) $fl[] = $r['disc_status'] === 'pending' ? 'הנחה ממתינה' : 'הנחה'; if ($fl) echo '<span class="flags">' . implode('', array_map(fn($z) => '<span class="fl">' . e($z) . '</span>', $fl)) . '</span>'; ?></span>
      <span class="c" data-l="כיתה"><span class="gchip gh<?= (int)array_search($r['grade'], GRADES, true) ?>"><?= e(class_label((string)$r['grade'], (string)$r['cls'])) ?></span></span>
      <span class="c" data-l="עיר"><?= $r['city'] !== '' ? e($r['city']) : '<span class="mu">—</span>' ?></span>
      <span class="c" data-l="טלפון"><?= $r['phone'] !== '' ? '<bdi dir="ltr">' . e(fmt_phone($r['phone'])) . '</bdi>' : '<span class="mu">—</span>' ?></span>
      <span class="c stt"><span class="pill <?= e($r['status']) ?>"><?= e(STATUS[$r['status']] ?? $r['status']) ?></span></span>
      <span class="chev" aria-hidden="true">⌄</span>
    </summary>
    <div class="mgb dt">
      <dl class="kv">
        <div><dt>תעודת זהות</dt><dd><?= can_see_ids() && $r['idn'] !== '' ? '<bdi dir="ltr">' . e(mask($r['idn'])) . '</bdi>' : '<span class="mu">—</span>' ?></dd></div>
        <div><dt>כתובת</dt><dd><?= $r['addr'] !== '' || $r['city'] !== '' ? e(trim(trim($r['addr'] . ' ' . $r['house_no']) . ', ' . $r['city'], ' ,')) : '<span class="mu">—</span>' ?></dd></div>
        <div><dt>מייל</dt><dd><?= $r['email'] !== '' ? '<bdi dir="ltr">' . e($r['email']) . '</bdi>' : '<span class="mu">—</span>' ?></dd></div>
      </dl>
      <?php if (($r['pk'] ?? '') === 'org'): ?><p class="aptn">תלמידה בדירה: התשלום על ידי <?= e($r['payer_name'] ?: 'העמותה') ?>, אין צורך להתקשר להורים בעניין גבייה.</p><?php endif; ?>
      <div class="tels">
        <?php foreach ([['אבא', $r['f_name'], $r['f_phone']], ['אמא', $r['m_name'], $r['m_phone']]] as [$lab, $nm, $ph]) if (trim($ph) !== ''): ?>
          <a class="tel" href="<?= e(tel_href($ph)) ?>"><svg class="ni" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg><span><b><?= e($lab) ?></b><?= trim($nm) !== '' ? ' ' . e($nm) : '' ?></span><bdi dir="ltr"><?= e(fmt_phone($ph)) ?></bdi></a>
        <?php endif; ?>
      </div>
      <div class="acts">
        <a class="btn" href="student.php?id=<?= (int)$r['id'] ?>">כרטיס התלמידה</a>
        <?php if ($coll): if ($r['family_id']): ?><a class="btn sec" href="family.php?id=<?= (int)$r['family_id'] ?>">לתיק הגבייה</a><?php else: ?><form method="post" action="family.php" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="create"><input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>"><button class="sec">פתיחת תיק גבייה</button></form><?php endif; endif; ?>
      </div>
    </div>
  </details>
<?php endforeach; ?>
<?php }
if (($_GET['chunk'] ?? '') === '1') { header('X-More: ' . ($total > $pg * $per ? '1' : '0')); stu_rows($rows, ($pg - 1) * $per, in_array($u['role'], COLLECTION_ROLES, true)); exit; }
page_head('תלמידות');
?>
<h1>תלמידות <span class="mu">(<?= $total ?> תוצאות)</span></h1>
<?php if (can_edit()) { $pc = (int)db()->query("SELECT COUNT(*) FROM students WHERE status = 'pending' AND archived_at IS NULL")->fetchColumn();
  echo '<p class="bar">' . ($pc ? '<a class="btn" href="registrations.php">📝 ' . $pc . ' הרשמות ממתינות לאישור</a>' : '<a class="btn sec" href="registrations.php">הרשמות חדשות</a>') . ' <a class="btn sec" href="moe_sync.php">סנכרון משרד החינוך</a> <a class="btn sec" href="exports.php">ייצוא</a></p>'; } ?>
<form class="bar auto" method="get">
  <label>חיפוש<input name="q" value="<?= e($q) ?>" placeholder="שם / ת״ז / טלפון"></label>
  <label>כיתה<select name="g"><option value="">הכל</option><?php foreach (GRADES as $x) echo '<option' . ($g === $x ? ' selected' : '') . '>' . e($x) . '</option>'; ?></select></label>
  <label>עיר<input name="c" value="<?= e($c) ?>" placeholder="למשל חיפה"></label>
  <label>סטטוס<select name="s"><option value="">הכל</option><?php foreach (STATUS as $k => $v) echo '<option value="' . $k . '"' . ($s === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <label>הצגה<select name="x"><option value="">הכל</option><?php foreach (XF as $k => $v) echo '<option value="' . $k . '"' . ($x === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <button>OK</button>
  <a class="btn sec<?= ($q !== '' || $g !== '' || $c !== '' || $s !== 'active' || $x !== '') ? ' hot' : '' ?>" href="students.php" title="איפוס כל הסינונים">↺ איפוס</a>
  <?php if (can_edit()) echo '<a class="btn sec" href="student.php">+ תלמידה חדשה</a>'; ?>
</form>
<?php sec_open('list', 'רשימת התלמידות', $total . ' תוצאות', '☺', ['list']); ?>
<?php $coll = in_array($u['role'], COLLECTION_ROLES, true); $off0 = ($pg - 1) * $per; ?>
<div class="ut stu">
  <div class="ur uth" aria-hidden="true"><span>#</span><span>שם</span><span>כיתה</span><span>עיר</span><span>טלפון</span><span>סטטוס</span><span></span></div>
<?php stu_rows($rows, $off0, $coll); if (!$rows) echo '<p class="mu emp">אין תוצאות.</p>'; ?>
</div>
<?php
if ($total > $pg * $per) {
  $qs = http_build_query(['q' => $q, 'g' => $g, 'c' => $c, 's' => $s, 'x' => $x, 'chunk' => 1, 'p' => $pg + 1]);
  echo '<div id="more" data-url="?' . e($qs) . '" data-next="' . ($pg + 1) . '"><a class="btn sec" href="?' . e(http_build_query(['q' => $q, 'g' => $g, 'c' => $c, 's' => $s, 'x' => $x, 'p' => $pg + 1])) . '">טעינת עוד תלמידות</a></div>';
}
sec_close();
page_foot();
