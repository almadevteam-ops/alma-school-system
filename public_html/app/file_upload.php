<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_login();
if (!can_see_ids()) fail_page(403, 'אין לך הרשאה למסמכים.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('students.php');
csrf_check();
$sid = (int)($_POST['student_id'] ?? 0);
$st = db()->prepare('SELECT id, name FROM students WHERE id = ?'); $st->execute([$sid]);
if (!$st->fetch()) fail_page(404, 'התלמידה לא נמצאה.');
$back = 'student.php?id=' . $sid;
$act = (string)($_POST['act'] ?? 'upload');
if ($act === 'delete') {
  if ($u['role'] !== 'admin') fail_page(403, 'מחיקת מסמכים מותרת למנהלת מערכת בלבד.');
  $fid = (int)($_POST['fid'] ?? 0);
  $q = db()->prepare('SELECT stored, orig_name FROM student_files WHERE id = ? AND student_id = ?'); $q->execute([$fid, $sid]);
  if ($r = $q->fetch()) {
    db()->prepare('DELETE FROM student_files WHERE id = ?')->execute([$fid]);
    remove_stored_file($r['stored']);
    audit('file_delete', 'student', (string)$sid, $r['orig_name']);
  }
  redirect($back . '&m=' . rawurlencode('המסמך נמחק.'));
}
$kind = (string)($_POST['kind'] ?? 'id');
$ok = 0; $errs = [];
$files = $_FILES['f'] ?? null;
if ($files && is_array($files['name'])) {
  foreach ($files['name'] as $i => $n) {
    if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
    [$good, $info] = store_student_file(['name' => $n, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i]], $sid, $kind, $u['id']);
    if ($good) { $ok++; audit('file_upload', 'student', (string)$sid, FILE_KINDS[$kind] . ': ' . clean_name($n)); }
    else $errs[] = clean_name($n) . ': ' . $info;
  }
} else { $errs[] = 'לא נבחר קובץ.'; }
$m = ($ok ? 'הועלו ' . $ok . ' מסמכים. ' : '') . implode(' | ', $errs);
redirect($back . '&' . ($errs ? 'e=' : 'm=') . rawurlencode($m));
