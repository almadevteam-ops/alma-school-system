<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'secretary']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('leads.php');
csrf_check();
$sid = (int)($_POST['student_id'] ?? 0);
$st = db()->prepare('SELECT id FROM students WHERE id = ?'); $st->execute([$sid]);
if (!$st->fetch()) fail_page(404, 'התלמידה לא נמצאה.');
$src = ($_POST['src'] ?? '') === 'manual' ? 'manual' : 'ocr';
$who = (string)($u['name'] ?: $u['username']);
$act = (string)($_POST['act'] ?? 'save');
if ($act === 'unlink') {
  $lid = (int)($_POST['lead'] ?? 0);
  db()->prepare('DELETE FROM sibling_lead_links WHERE lead_id = ? AND student_id = ?')->execute([$lid, $sid]);
  db()->prepare('DELETE FROM sibling_leads WHERE id = ? AND NOT EXISTS (SELECT 1 FROM sibling_lead_links WHERE lead_id = sibling_leads.id)')->execute([$lid]);
  audit('lead_unlink', 'student', (string)$sid, (string)$lid);
  redirect('student.php?id=' . $sid . '&open=leads&m=' . rawurlencode('הקישור הוסר.'));
}
if ($act === 'edit_lead') {
  $lid = (int)($_POST['lead'] ?? 0);
  $q = db()->prepare('SELECT l.* FROM sibling_leads l JOIN sibling_lead_links k ON k.lead_id = l.id WHERE l.id = ? AND k.student_id = ?'); $q->execute([$lid, $sid]); $L = $q->fetch();
  if (!$L) fail_page(404, 'הרשומה לא נמצאה.');
  [$r, $e1] = lead_clean(['idn' => $L['idn'], 'ln' => $_POST['ln'] ?? '', 'fn' => $_POST['fn'] ?? '', 'dob' => $_POST['dob'] ?? '', 'g' => $_POST['g'] ?? ''], 'manual', []);
  if (!$r) redirect('student.php?id=' . $sid . '&open=leads&e=' . rawurlencode($e1));
  db()->prepare('UPDATE sibling_leads SET last_name = ?, first_name = ?, dob = ?, gender = ?, updated_at = ? WHERE id = ?')->execute([$r['ln'], $r['fn'], $r['dob'] ?: null, $r['g'], now(), $lid]);
  audit('lead_edit', 'student', (string)$sid, (string)$lid);
  redirect('student.php?id=' . $sid . '&open=leads&m=' . rawurlencode('הפרטים עודכנו.'));
}
$o = leads_store($sid, (array)($_POST['sp'] ?? []), $src, $who);
$msg = leads_msg($o);
if (!empty($_POST['json'])) {
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  echo json_encode(['new' => $o['new'], 'linked' => $o['linked'], 'bad' => $o['bad'], 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit;
}
redirect('student.php?id=' . $sid . '&open=leads&' . ($o['bad'] ? 'e=' : 'm=') . rawurlencode($msg));
