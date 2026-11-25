<?php
// הורדה / צפייה בחשבונית או קבלה (מנהלת בלבד) וקובץ מס"ב מההיסטוריה.
require __DIR__ . '/../../private/lib/bootstrap.php';
require_role(['admin']);
if (isset($_GET['masav'])) {
  $st = db()->prepare('SELECT * FROM budget_masav WHERE id = ?'); $st->execute([(int)$_GET['masav']]); $m = $st->fetch(); if (!$m) { http_response_code(404); exit('לא נמצא'); }
  $lines = json_decode((string)$m['payload'], true) ?: []; $c = masav_cfg(); $c = array_merge($c, json_decode((string)($lines['cfg'] ?? '{}'), true) ?: []); $ls = $lines['rows'] ?? $lines;
  $data = masav_build($ls, (string)$m['credit_date'], $c); audit('masav_download', 'budget', (string)$m['id'], '');
  header('Content-Type: text/plain; charset=ibm862'); header('Content-Disposition: attachment; filename="MASAV_' . date('Ymd', strtotime((string)$m['created_at'])) . '_' . (int)$m['id'] . '.txt"'); header('Content-Length: ' . strlen($data)); header('X-Content-Type-Options: nosniff'); echo $data; exit;
}
$st = db()->prepare('SELECT * FROM budget_docs WHERE id = ?'); $st->execute([(int)($_GET['id'] ?? 0)]); $d = $st->fetch();
$p = $d ? bdoc_dir() . '/' . basename((string)$d['stored']) : ''; if (!$d || !is_file($p)) { http_response_code(404); exit('לא נמצא'); }
header('Content-Type: ' . $d['mime']); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; sandbox");
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode((string)$d['orig_name'])); header('Content-Length: ' . filesize($p)); readfile($p);
