<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT nf.*, n.student_id FROM note_files nf JOIN student_notes n ON n.id = nf.note_id WHERE nf.id = ?'); $st->execute([$id]);
$r = $st->fetch();
if (!$r || !preg_match('/^[0-9a-f]{32}$/', $r['stored']) || !isset(FILE_MIMES[$r['mime']])) fail_page(404, 'הקובץ לא נמצא.');
$path = PRIV . '/storage/' . $r['stored'] . '.bin';
$blob = is_file($path) ? (string)file_get_contents($path) : '';
$plain = $blob !== '' ? dec_bytes($blob, 'note:' . $r['stored']) : null;
if ($plain === null || $plain === false) { error_log('[alma] cannot decrypt note file ' . $id); fail_page(500, 'לא ניתן לפתוח את הקובץ.'); }
audit('file_view', 'student', (string)$r['student_id'], 'הערה: ' . clean_name($r['orig_name']));
$fn = clean_name($r['orig_name']);
header('Content-Type: ' . $r['mime']); header('Content-Length: ' . strlen($plain));
header('Content-Disposition: ' . ($r['mime'] === 'application/pdf' ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($fn));
header("Content-Security-Policy: default-src 'none'; sandbox"); header('X-Content-Type-Options: nosniff');
echo $plain;
