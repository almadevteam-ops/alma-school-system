<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('students.php');
csrf_check();
$sid = (int)($_POST['student_id'] ?? 0);
$st = db()->prepare('SELECT id, name FROM students WHERE id = ?'); $st->execute([$sid]);
if (!$st->fetch()) fail_page(404, 'התלמידה לא נמצאה.');
$back = (string)($_POST['back'] ?? '');
$back = preg_match('/^(student|family)\.php\?id=\d+$/', $back) ? $back : 'student.php?id=' . $sid;
$body = trim((string)($_POST['body'] ?? ''));
if ($body === '') redirect($back . '&open=notes&e=' . rawurlencode('נא לכתוב את ההערה.'));
$body = mb_substr($body, 0, NOTE_MAX_LEN);
$pdo = db();
$pdo->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)')->execute([$sid, $body, $u['id'], mb_substr((string)($u['name'] ?: $u['username']), 0, 100), now()]);
$nid = (int)$pdo->lastInsertId();
[$ok, $errs] = note_files_store($_FILES['f'] ?? [], $nid);
audit('note_add', 'student', (string)$sid, 'הערה #' . $nid . ($ok ? ", $ok קבצים" : ''));
redirect($back . '&open=notes&' . ($errs ? 'e=' . rawurlencode('ההערה נשמרה, אבל: ' . implode(' | ', $errs)) : 'm=' . rawurlencode('ההערה נשמרה.')));
