<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_login();
if (!can_see_ids()) fail_page(403, 'אין לך הרשאה למסמכים.');
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM student_files WHERE id = ?'); $st->execute([$id]);
$r = $st->fetch();
if (!$r || !preg_match('/^[0-9a-f]{32}$/', $r['stored']) || !isset(FILE_MIMES[$r['mime']])) fail_page(404, 'המסמך לא נמצא.');
$path = PRIV . '/storage/' . $r['stored'] . '.bin';
$blob = is_file($path) ? (string)file_get_contents($path) : '';
$plain = $blob !== '' ? dec_bytes($blob, 'file:' . $r['stored']) : null;
if ($plain === null) { error_log('[alma] cannot decrypt file ' . $id); fail_page(500, 'לא ניתן לפתוח את המסמך.'); }
audit('file_view', 'student', (string)$r['student_id'], clean_name($r['orig_name']));
$dl = !empty($_GET['dl']) || $r['mime'] === 'application/pdf';
$fn = clean_name($r['orig_name']);
header('Content-Type: ' . $r['mime']);
header('Content-Length: ' . strlen($plain));
header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($fn));
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $plain;
