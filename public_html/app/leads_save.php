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
$o = leads_store($sid, (array)($_POST['sp'] ?? []), $src, $who);
$msg = leads_msg($o);
if (!empty($_POST['json'])) {
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  echo json_encode(['new' => $o['new'], 'linked' => $o['linked'], 'bad' => $o['bad'], 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit;
}
redirect('student.php?id=' . $sid . '&open=leads&' . ($o['bad'] ? 'e=' : 'm=') . rawurlencode($msg));
