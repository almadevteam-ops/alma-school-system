<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']); $by = (string)($me['name'] ?: $me['username']); $pdo = db(); $self = 'budget_recon.php';
if (!bpay_ready() || !bplan_ready()) { page_head('תקציב – התאמת בנק'); echo '<h1>התאמת בנק</h1><p class="aptn">כדי להפעיל את הדף יש להריץ "עדכון מסד נתונים" (תחזוקה).</p>'; page_foot(); exit; }
$flash = function (string $m, bool $ok = true) use ($self) { $_SESSION['rcmsg'] = [$ok, $m]; redirect($self); };
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? ''); $id = (int)($_POST['id'] ?? 0);
  if ($act === 'na' || $act === 'has') {
    $pdo->prepare($act === 'na' ? 'UPDATE budget_tx SET doc_na = 1, updated_at = ? WHERE id = ? AND is_income = 0' : 'UPDATE budget_tx SET has_invoice = 1, updated_at = ? WHERE id = ? AND is_income = 0')->execute([now(), $id]);
    audit('budget_recon_' . $act, 'budget', (string)$id, ''); $flash($act === 'na' ? 'סומן שאין צורך במסמך.' : 'סומן שהחשבונית קיימת.');
  }
  if ($act === 'up') {
    $q = $pdo->prepare('SELECT id FROM budget_tx WHERE id = ? AND is_income = 0'); $q->execute([$id]);
    if (!$q->fetch() || empty($_FILES['file'])) $flash('התנועה או הקובץ לא נמצאו.', false);
    [$did, $er] = bdoc_save($_FILES['file'], 'inv', $id, null, $by); if (!$did) $flash($er, false);
    $pdo->prepare('UPDATE budget_tx SET has_invoice = 1, updated_at = ? WHERE id = ?')->execute([now(), $id]); audit('budget_doc_up', 'budget', (string)$id, 'inv'); $flash('החשבונית צורפה לתנועה.');
  }
  if ($act === 'match') {
    $pid = (int)($_POST['pid'] ?? 0); $q = $pdo->prepare('SELECT * FROM budget_tx WHERE id = ? AND is_income = 0 AND payable_id IS NULL'); $q->execute([$id]); $tx = $q->fetch();
    $q = $pdo->prepare("SELECT * FROM budget_payables WHERE id = ? AND status = 'open'"); $q->execute([$pid]); $p = $q->fetch();
    if (!$tx || !$p) $flash('התנועה או החשבונית כבר לא זמינות להתאמה.', false);
    $rem = round((float)$p['total'] - (float)$p['paid_amount'], 2);
    [$m, $ok] = bpay_pay($pid, min((float)$tx['amount'], $rem), $by, (string)$tx['tx_date'], 'הותאם לתנועת בנק', $id); audit('budget_recon_match', 'budget', (string)$id, "payable $pid"); $flash($m, (bool)$ok);
  }
  redirect($self);
}
$msg = $_SESSION['rcmsg'] ?? null; unset($_SESSION['rcmsg']);
$limit = 300;
$miss = $pdo->query("SELECT t.* FROM budget_tx t WHERE t.is_income = 0 AND t.doc_na = 0 AND t.has_invoice = 0 AND t.has_receipt = 0 AND t.payable_id IS NULL AND NOT EXISTS (SELECT 1 FROM budget_docs d WHERE d.tx_id = t.id) ORDER BY t.tx_date DESC, t.id DESC LIMIT $limit")->fetchAll();
$missN = (int)$pdo->query("SELECT COUNT(*) FROM budget_tx t WHERE t.is_income = 0 AND t.doc_na = 0 AND t.has_invoice = 0 AND t.has_receipt = 0 AND t.payable_id IS NULL AND NOT EXISTS (SELECT 1 FROM budget_docs d WHERE d.tx_id = t.id)")->fetchColumn();
$missSum = (float)$pdo->query("SELECT COALESCE(SUM(t.amount),0) FROM budget_tx t WHERE t.is_income = 0 AND t.doc_na = 0 AND t.has_invoice = 0 AND t.has_receipt = 0 AND t.payable_id IS NULL AND NOT EXISTS (SELECT 1 FROM budget_docs d WHERE d.tx_id = t.id)")->fetchColumn();
$pays = $pdo->query("SELECT * FROM budget_payables WHERE status = 'open' ORDER BY COALESCE(inv_date, DATE(created_at)), id")->fetchAll();
$cand = $pdo->query("SELECT * FROM budget_tx WHERE is_income = 0 AND payable_id IS NULL AND tx_date >= DATE_SUB(CURDATE(), INTERVAL 18 MONTH)")->fetchAll();
$tok = function (string $s): array { $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)); return array_values(array_filter(explode(' ', $s), fn($w) => mb_strlen($w) >= 3)); };
$match = []; $matchedN = 0;
foreach ($pays as $p) {
  $rem = round((float)$p['total'] - (float)$p['paid_amount'], 2); $base = $p['inv_date'] ?: substr((string)$p['created_at'], 0, 10); $pt = $tok((string)$p['supplier']); $c = [];
  foreach ($cand as $t) {
    $amt = (float)$t['amount']; if ($amt > $rem + 0.009 || $amt < 1) continue;
    $days = abs((strtotime((string)$t['tx_date']) - strtotime($base)) / 86400); if ($days > 120) continue;
    $sc = 0; if (abs($amt - $rem) < 0.01) $sc += 60; elseif ($amt >= $rem * 0.5) $sc += 15; else continue;
    $common = array_intersect($pt, $tok((string)$t['name'] . ' ' . (string)$t['descr'])); $sc += min(40, count($common) * 25); $sc += max(0, 20 - (int)($days / 6));
    if ($sc >= 55) $c[] = ['t' => $t, 'sc' => $sc];
  }
  usort($c, fn($a, $b) => $b['sc'] <=> $a['sc']); if ($c) { $match[(int)$p['id']] = array_slice($c, 0, 3); $matchedN++; }
}
page_head('תקציב – התאמת בנק');
?>
<h1>התאמת בנק</h1>
<?= bpay_banner() ?>
<?php if ($msg) echo '<p class="' . ($msg[0] ? 'ok' : 'er') . '">' . e($msg[1]) . '</p>'; ?>
<div class="tiles"><div class="tile"><span class="mu">הוצאות בלי חשבונית או קבלה</span><b class="<?= $missN ? 'er' : 'ok' ?>"><?= $missN ?></b><span class="mu"><?= e(budget_money0($missSum)) ?></span></div><div class="tile"><span class="mu">חשבוניות פתוחות</span><b><?= count($pays) ?></b></div><div class="tile"><span class="mu">חשבוניות עם תנועה מתאימה</span><b class="<?= $matchedN ? 'ok' : '' ?>"><?= $matchedN ?></b></div></div>

<div class="card"><h2>א. חשבוניות פתוחות שנראה שכבר שולמו</h2>
<p class="mu">הצעות התאמה בין חשבונית לתשלום לבין תנועת בנק או אשראי (לפי סכום, תאריך ושם הספק). לחיצה על "התאמה" תסמן את החשבונית כשולמה, תצמיד אותה לתנועה, ולא תיצור תנועה כפולה.</p>
<?php if (!$match): ?><p class="ok">אין כרגע הצעות התאמה.</p><?php else: ?>
<div class="sc"><table><tr><th>חשבונית</th><th>יתרה</th><th>תנועה מוצעת</th><th></th></tr>
<?php foreach ($pays as $p): if (empty($match[(int)$p['id']])) continue; $rem = round((float)$p['total'] - (float)$p['paid_amount'], 2); $first = true;
  foreach ($match[(int)$p['id']] as $m): $t = $m['t']; ?>
<tr><?php if ($first): ?><td rowspan="<?= count($match[(int)$p['id']]) ?>"><b><?= e($p['supplier']) ?></b><br><span class="mu"><?= $p['ref'] !== '' ? 'חשבונית ' . e($p['ref']) . ' · ' : '' ?><?= $p['inv_date'] ? e(date('d/m/Y', strtotime($p['inv_date']))) : '' ?></span></td><td class="n" rowspan="<?= count($match[(int)$p['id']]) ?>"><?= e(budget_money($rem)) ?></td><?php $first = false; endif; ?>
<td><?= e(date('d/m/Y', strtotime($t['tx_date']))) ?> · <?= e($t['name']) ?> · <b><?= e(budget_money((float)$t['amount'])) ?></b></td>
<td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="match"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><button class="sec">התאמה</button></form></td></tr>
<?php endforeach; endforeach; ?></table></div><?php endif; ?></div>

<div class="card"><h2>ב. הוצאות בלי חשבונית או קבלה <span class="mu">(<?= $missN ?><?= $missN > $limit ? ', מוצגות ' . $limit . ' האחרונות' : '' ?>)</span></h2>
<p class="mu">אפשר לצרף חשבונית, לסמן שהיא קיימת (נשמרת במקום אחר), או לסמן שאין צורך במסמך (למשל עמלת בנק).</p>
<?php if (!$miss): ?><p class="ok">לכל ההוצאות יש מסמך. כל הכבוד!</p><?php else: ?>
<div class="sc"><table><tr><th>תאריך</th><th>שם</th><th>קטגוריה</th><th>סכום</th><th>פעולה</th></tr>
<?php foreach ($miss as $t): ?>
<tr><td><?= e(date('d/m/Y', strtotime($t['tx_date']))) ?></td><td><?= e($t['name']) ?></td><td><?= $t['category'] === '' ? '<span class="mu">לא משויך</span>' : e($t['category']) ?></td><td class="n"><?= e(budget_money((float)$t['amount'])) ?></td>
<td><form method="post" enctype="multipart/form-data" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="act" value="up"><input type="file" name="file" accept="application/pdf,image/*" required aria-label="קובץ חשבונית"><button class="sec">צירוף</button></form>
<form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="sec" name="act" value="has">החשבונית קיימת</button> <button class="sec" name="act" value="na">לא נדרש מסמך</button></form></td></tr>
<?php endforeach; ?></table></div><?php endif; ?></div>
<?php page_foot();
