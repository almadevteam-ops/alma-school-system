<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
require_role(['admin']);
$f = budget_filters(); [$wh, $a] = budget_where(['range' => $f['range'], 'unit' => $f['unit']]); $units = budget_units();
$one = function (string $sql, array $p = []) { $q = db()->prepare($sql); $q->execute($p); return $q->fetchAll(); };
$t = $one("SELECT SUM(CASE WHEN is_income=0 THEN amount ELSE 0 END) ex, SUM(CASE WHEN is_income=1 THEN amount ELSE 0 END) inc, COUNT(*) n, SUM(category='') un_n, SUM(CASE WHEN category='' AND is_income=0 THEN amount ELSE 0 END) un_s, SUM(is_income=0 AND has_invoice=0) ni, SUM(is_income=0 AND has_receipt=0) nr FROM budget_tx $wh", $a)[0];
$ex = (float)$t['ex']; $inc = (float)$t['inc']; $n = (int)$t['n'];
$byUnit = $one("SELECT unit k, SUM(amount) s FROM budget_tx $wh" . ($wh ? ' AND' : ' WHERE') . " is_income=0 GROUP BY unit ORDER BY s DESC", $a);
$byCat = $one("SELECT IF(category='','לא משויך',category) k, SUM(amount) s FROM budget_tx $wh" . ($wh ? ' AND' : ' WHERE') . " is_income=0 GROUP BY category ORDER BY s DESC", $a);
$byInc = $one("SELECT IF(category='','לא משויך',category) k, SUM(amount) s FROM budget_tx $wh" . ($wh ? ' AND' : ' WHERE') . " is_income=1 GROUP BY category ORDER BY s DESC", $a);
$byMon = $one("SELECT DATE_FORMAT(tx_date,'%Y-%m') k, SUM(CASE WHEN is_income=0 THEN amount ELSE 0 END) s, SUM(CASE WHEN is_income=1 THEN amount ELSE 0 END) i FROM budget_tx $wh GROUP BY k ORDER BY k", $a);
$yq = $f['yr'] === 'all' ? ['y' => 'all'] : ['y' => $f['yr']]; if ($f['unit'] !== '') $yq['unit'] = $f['unit'];
$uTab = fn(string $u, string $l) => '<a class="btn' . ($f['unit'] === $u ? '' : ' sec') . '" href="budget.php?' . e(http_build_query(['y' => $f['yr']] + ($u !== '' ? ['unit' => $u] : []))) . '">' . e($l) . '</a>';
$lnk = fn(array $o) => 'budget_tx.php?' . http_build_query($yq + $o);
page_head('תקציב – סיכום');
?>
<h1>תקציב בית הספר – סיכום <?= $f['y'] === null ? '' : e(budget_year_label($f['y'])) ?><?= $f['unit'] !== '' ? ' · ' . e($f['unit']) : '' ?></h1>
<form method="get" class="bar auto"><?= budget_year_select($f) ?><?php if ($f['unit'] !== '') echo '<input type="hidden" name="unit" value="' . e($f['unit']) . '">'; ?></form>
<p class="subnav"><?= $uTab('', 'כל היחידות') ?> <?php foreach ($units as $u) echo $uTab($u, $u) . ' '; ?></p>
<?php if ($n === 0): ?>
<div class="card"><p>אין עדיין תנועות בתקופה הזאת. אפשר <a href="<?= e($lnk(['add' => 1])) ?>">להוסיף הוצאה או הכנסה</a>, או <a href="budget_tx.php?<?= e(http_build_query($yq)) ?>#imp">לייבא קובץ Excel / CSV</a> עם העמודות: תאריך, שם, תיאור, אסמכתה, סכום, קטגוריה, יחידה, סוג.</p></div>
<?php else: ?>
<div class="tiles">
  <div class="tile"><span class="mu">סה"כ הוצאות</span><b><?= e(budget_money0($ex)) ?></b></div>
  <div class="tile"><span class="mu">סה"כ הכנסות</span><b class="ok"><?= e(budget_money0($inc)) ?></b></div>
  <div class="tile"><span class="mu">יתרה (הכנסות פחות הוצאות)</span><b class="<?= $inc - $ex < 0 ? 'er' : 'ok' ?>"><?= e(budget_money0($inc - $ex)) ?></b></div>
  <div class="tile"><span class="mu">מספר תנועות</span><b><?= $n ?></b></div>
  <div class="tile"><span class="mu">קטגוריה מובילה</span><b><?= $byCat ? e($byCat[0]['k']) : '-' ?></b></div>
  <div class="tile"><span class="mu">ממתין לשיוך</span><b><a href="<?= e($lnk(['cat' => '__un'])) ?>"><?= (int)$t['un_n'] ?></a></b><span class="mu"><?= e(budget_money0($t['un_s'])) ?> הוצאות</span></div>
  <div class="tile"><span class="mu">הוצאות בלי חשבונית / בלי קבלה</span><b><a href="<?= e($lnk(['type' => 'out', 'doc' => 'ni'])) ?>"><?= (int)$t['ni'] ?></a> / <a href="<?= e($lnk(['type' => 'out', 'doc' => 'nr'])) ?>"><?= (int)$t['nr'] ?></a></b></div>
</div>
<?php if ($f['unit'] === ''): ?><div class="card"><h2>הוצאות לפי יחידה</h2><?php $mx = $byUnit ? (float)$byUnit[0]['s'] : 0; foreach ($byUnit as $r) echo budget_bar($r['k'], (float)$r['s'], $mx, budget_money0($r['s'])); ?></div><?php endif; ?>
<div class="card"><h2>הוצאות לפי קטגוריה</h2><?php $mx = $byCat ? (float)$byCat[0]['s'] : 0; foreach ($byCat as $r) echo budget_bar($r['k'], (float)$r['s'], $mx, budget_money0($r['s']) . ($ex ? ' (' . number_format($r['s'] / $ex * 100, 1) . '%)' : '')); ?></div>
<div class="card"><h2>הכנסות לפי קטגוריה</h2><?php if (!$byInc) echo '<p class="mu">אין הכנסות רשומות.</p>'; $mx = $byInc ? max(array_map(fn($r) => abs((float)$r['s']), $byInc)) : 0; foreach ($byInc as $r) echo budget_bar($r['k'], (float)$r['s'], $mx, budget_money0($r['s']), true); ?></div>
<div class="card"><h2>לפי חודש</h2><?php $mx = 0; foreach ($byMon as $r) $mx = max($mx, abs((float)$r['s']), abs((float)$r['i']));
  foreach ($byMon as $r) { $lab = substr($r['k'], 5) . '/' . substr($r['k'], 0, 4); echo '<a class="bgl" href="' . e($lnk(['month' => $r['k']])) . '">' . budget_bar($lab . ' · הוצאות', (float)$r['s'], $mx, budget_money0($r['s'])) . ((float)$r['i'] != 0.0 ? budget_bar($lab . ' · הכנסות', (float)$r['i'], $mx, budget_money0($r['i']), true) : '') . '</a>'; } ?></div>
<?php endif; ?>
<?php page_foot();
