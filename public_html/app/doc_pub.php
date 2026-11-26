<?php
// הורדת מסמך בקישור זמני (נשלח בוואטסאפ). בלי התחברות: האסימון אקראי באורך 40 תווים ובעל תוקף.
require __DIR__ . '/../../private/lib/bootstrap.php';
$t = (string)($_GET['t'] ?? ''); $gone = function (): never { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'הקישור לא תקף או שפג תוקפו.'; exit; };
if (!preg_match('/^[a-f0-9]{40}$/', $t) || !docs_ready() || !doc_links_ready()) $gone();
$st = db()->prepare('SELECT * FROM doc_links WHERE token = ? AND expires_at > ?'); $st->execute([$t, now()]); $l = $st->fetch(); if (!$l) $gone();
$h = doc_hist_get((int)$l['hist_id']); if (!$h) $gone();
$stu = (int)$h['student_id'] ? doc_student((int)$h['student_id']) : doc_blank_student(); if (!$stu) $gone();
db()->prepare('UPDATE doc_links SET hits = hits + 1 WHERE token = ?')->execute([$t]);
$pdf = doc_pdf(doc_hist_tpl($h), $stu, (int)$h['year'], doc_hist_date($h)); $name = doc_filename(doc_hist_tpl($h), $stu);
header('Content-Type: application/pdf'); header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name)); header('Content-Length: ' . strlen($pdf)); header('Cache-Control: private, no-store'); header('X-Robots-Tag: noindex'); echo $pdf;
