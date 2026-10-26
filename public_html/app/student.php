<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_login();
// key => [label, maxlen, sensitive]
$F = [
  'name' => ['שם מלא', 100, 0], 'grade' => ['כיתה', 4, 0], 'cls' => ['מחזור/כיתה מפורטת', 8, 0],
  'idn' => ['תעודת זהות', 20, 1], 'dob' => ['תאריך לידה', 12, 0], 'addr' => ['כתובת', 200, 0], 'city' => ['עיר', 80, 0],
  'email' => ['אימייל', 150, 0], 'phone' => ['טלפון התלמידה', 30, 0],
  'f_name' => ['שם האב', 80, 0], 'f_phone' => ['טלפון האב', 30, 0], 'f_id' => ['ת״ז האב', 20, 1],
  'm_name' => ['שם האם', 80, 0], 'm_phone' => ['טלפון האם', 30, 0], 'm_id' => ['ת״ז האם', 20, 1],
  'hmo' => ['קופת חולים', 30, 0],
];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$err = ''; $msg = '';
$row = null;
if ($id) {
  $st = db()->prepare('SELECT * FROM students WHERE id = ?'); $st->execute([$id]); $row = $st->fetch();
  if (!$row) fail_page(404, 'התלמידה לא נמצאה.');
}
function valid_il_id(string $v): bool {
  $v = preg_replace('/\D/', '', $v); if ($v === '' || strlen($v) > 9) return false;
  $v = str_pad($v, 9, '0', STR_PAD_LEFT); $s = 0;
  for ($i = 0; $i < 9; $i++) { $n = (int)$v[$i] * (($i % 2) + 1); $s += $n > 9 ? $n - 9 : $n; }
  return $s % 10 === 0;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? 'save');
  if ($act === 'delete') {
    require_role(['admin']);
    if (!$row || empty($_POST['ok'])) fail_page(400, 'נדרש אישור מחיקה.');
    $fs = db()->prepare('SELECT stored FROM student_files WHERE student_id = ?'); $fs->execute([$id]);
    $storedList = array_column($fs->fetchAll(), 'stored');
    db()->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
    foreach ($storedList as $sn) remove_stored_file($sn);
    audit('delete', 'student', (string)$id, $row['name']);
    redirect('students.php');
  }
  if (!can_edit()) fail_page(403, 'אין לך הרשאת עריכה.');
  $d = [];
  foreach ($F as $k => [$lbl, $max]) $d[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
  $d['status'] = isset(STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'active';
  $d['marital'] = ($_POST['marital'] ?? '') === 'divorced' ? 'divorced' : 'married';
  $d['notes'] = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000);
  if ($d['name'] === '') $err = 'נא למלא שם.';
  elseif (!in_array($d['grade'], GRADES, true)) $err = 'נא לבחור כיתה.';
  elseif ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err = 'כתובת האימייל לא תקינה.';
  if (!$err) {
    if ($id) {
      $changed = [];
      foreach ($d as $k => $v) if ((string)($row[$k] ?? '') !== (string)$v) $changed[] = $k;
      $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($d)));
      db()->prepare("UPDATE students SET $set, updated_at = ? WHERE id = ?")->execute([...array_values($d), now(), $id]);
      audit('update', 'student', (string)$id, 'שדות: ' . implode(',', $changed));
      redirect('student.php?id=' . $id . '&saved=1');
    } else {
      $cols = implode(',', array_keys($d));
      $ph = implode(',', array_fill(0, count($d), '?'));
      db()->prepare("INSERT INTO students ($cols, created_at, updated_at) VALUES ($ph, ?, ?)")->execute([...array_values($d), now(), now()]);
      $nid = (int)db()->lastInsertId();
      audit('create', 'student', (string)$nid, $d['name']);
      redirect('student.php?id=' . $nid . '&saved=1');
    }
  }
  $row = array_merge($row ?: [], $d);   // keep typed values on error
}
if (!empty($_GET['saved'])) $msg = 'נשמר.';
if (!empty($_GET['m'])) $msg = mb_substr((string)$_GET['m'], 0, 300);
if (!empty($_GET['e'])) $err = mb_substr((string)$_GET['e'], 0, 500);
if ($row && $_SERVER['REQUEST_METHOD'] === 'GET' && can_see_ids()) audit('view', 'student', (string)$id, 'צפייה בכרטיס');
$ro = !can_edit();
$sfiles = [];
if ($id && can_see_ids()) { $q = db()->prepare('SELECT id, kind, orig_name, mime, size, created_at FROM student_files WHERE student_id = ? ORDER BY id'); $q->execute([$id]); $sfiles = $q->fetchAll(); }
$val = fn($k) => $row[$k] ?? '';
page_head($row ? $row['name'] : 'תלמידה חדשה');
?>
<h1><?= $row ? e($row['name']) : 'תלמידה חדשה' ?></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="fg">
  <?php foreach ($F as $k => [$lbl, $max, $sens]):
    if ($sens && !can_see_ids()) continue;
    if ($k === 'grade') {
      echo '<label>' . e($lbl) . '<select name="grade"' . ($ro ? ' disabled' : '') . '><option value=""></option>';
      foreach (GRADES as $x) echo '<option' . ($val('grade') === $x ? ' selected' : '') . '>' . e($x) . '</option>';
      echo '</select></label>'; continue;
    }
    echo '<label>' . e($lbl) . '<input name="' . $k . '" maxlength="' . $max . '" value="' . e($val($k)) . '"' . ($ro ? ' readonly' : '') . '></label>';
  endforeach; ?>
  <label>מצב הורים<select name="marital"<?= $ro ? ' disabled' : '' ?>><option value="married">נשואים</option><option value="divorced"<?= $val('marital') === 'divorced' ? ' selected' : '' ?>>גרושים</option></select></label>
  <label>סטטוס<select name="status"<?= $ro ? ' disabled' : '' ?>><?php foreach (STATUS as $k => $v) echo '<option value="' . $k . '"' . (($val('status') ?: 'active') === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  </div>
  <p><label class="mu">הערות<br><textarea name="notes" rows="3" style="width:100%"<?= $ro ? ' readonly' : '' ?>><?= e($val('notes')) ?></textarea></label></p>
  <?php if (!$ro): ?><button>שמירה</button><?php endif; ?>
  <a class="btn sec" href="students.php">חזרה</a>
  <?php if ($id && !valid_il_id((string)$val('idn')) && $val('idn') !== '' && can_see_ids()) echo '<p class="mu">שימו לב: מספר הזהות שהוזן לא עובר בדיקת תקינות.</p>'; ?>
</form>
<?php if ($id && can_see_ids()): ?>
<div class="card">
  <h2>מסמכים</h2>
  <?php if ($sfiles): ?>
  <div class="sc"><table>
    <tr><th>סוג</th><th>קובץ</th><th>גודל</th><th>הועלה</th><th></th></tr>
    <?php foreach ($sfiles as $f): ?>
    <tr>
      <td><?= e(FILE_KINDS[$f['kind']] ?? $f['kind']) ?></td>
      <td><?= e($f['orig_name']) ?></td>
      <td><?= e(number_format($f['size'] / 1024, 0)) ?> KB</td>
      <td><?= e($f['created_at']) ?></td>
      <td>
        <a class="btn sec" href="file.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">צפייה</a>
        <a class="btn sec" href="file.php?id=<?= (int)$f['id'] ?>&dl=1">הורדה</a>
        <?php if ($u['role'] === 'admin'): ?>
        <form method="post" action="file_upload.php" class="inl"><?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="fid" value="<?= (int)$f['id'] ?>"><button class="dg">מחיקה</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?><p class="mu">עדיין לא הועלו מסמכים.</p><?php endif; ?>
  <?php if (can_edit()): ?>
  <form method="post" action="file_upload.php" enctype="multipart/form-data" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$id ?>">
    <div class="bar">
      <label>סוג מסמך<select name="kind"><?php foreach (FILE_KINDS as $k => $v) echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
      <label>קבצים (JPG / PNG / PDF, עד 10MB)<input type="file" name="f[]" multiple required accept="image/jpeg,image/png,image/webp,application/pdf"></label>
      <button>העלאה</button>
    </div>
    <p class="mu">הקבצים נשמרים מוצפנים. כל צפייה והורדה נרשמות ביומן הפעולות.</p>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($id && $u['role'] === 'admin'): ?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="delete">
  <h2>מחיקה</h2>
  <label><input type="checkbox" name="ok" value="1"> אני מאשרת למחוק את התלמידה לצמיתות</label>
  <p><button class="dg">מחיקה</button></p>
</form>
<?php endif;
page_foot();
