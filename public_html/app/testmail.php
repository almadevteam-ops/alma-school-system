<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
if (!is_test_env()) { http_response_code(404); exit; }
$me = require_role(['admin']); $f = PRIV . '/storage/test_mail.log';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); if ((string)($_POST['act'] ?? '') === 'clear') { @unlink($f); } redirect('testmail.php'); }
$rows = []; if (is_file($f)) foreach (array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -100) as $l) { $j = json_decode($l, true); if (is_array($j)) $rows[] = $j; }
$rows = array_reverse($rows);
page_head('תיבת דואר בדיקות');
?>
<h1>תיבת דואר בדיקות <span class="mu">(<?= count($rows) ?>)</span></h1>
<p class="mu">בסביבת הבדיקות שום מייל לא נשלח באמת. כל הודעה שהמערכת מנסה לשלוח (קודי כניסה, תזכורות, דיוור, מסמכים) נשמרת כאן, כדי שאפשר לבדוק את התוכן.</p>
<?php if ($rows): ?><form method="post" class="bar"><?= csrf_field() ?><button class="sec" name="act" value="clear">ניקוי התיבה</button></form><?php endif; ?>
<?php foreach ($rows as $r): ?>
<div class="card"><p class="mu"><?= e((string)($r['at'] ?? '')) ?> · אל: <bdi dir="ltr"><?= e((string)($r['to'] ?? '')) ?></bdi></p><h2><?= e((string)($r['subject'] ?? '')) ?></h2>
<pre class="pre"><?= e((string)($r['body'] ?? '')) ?></pre><?php if (!empty($r['att'])): ?><p class="mu">קבצים מצורפים: <?= e(implode(', ', (array)$r['att'])) ?></p><?php endif; ?></div>
<?php endforeach; if (!$rows) echo '<p class="mu">אין הודעות.</p>'; ?>
<?php page_foot();
