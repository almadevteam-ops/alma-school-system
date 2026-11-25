<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$f = budget_filters(); $range = $f['range']; $per = $f['y'] === null ? 'כל התקופה' : 'שנת ' . budget_year_label($f['y']) . ' (' . $f['y'] . '–' . ($f['y'] + 1) . ')';
$by = (string)($me['name'] ?: $me['username']); $name = bl_canon((string)($_GET['s'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? ''); $back = 'budget_suppliers.php?' . http_build_query(['y' => $f['yr']]);
  if ($act === 'merge') {
    $sel = array_values(array_filter(array_map('strval', (array)($_POST['sel'] ?? [])))); $tg = bl_norm((string)($_POST['target'] ?? ''));
    if (count($sel) < 2 && !($tg !== '' && count($sel) >= 1)) $_SESSION['smsg'] = [false, 'יש לסמן לפחות שני ספקים לאיחוד (או ספק אחד ולהקליד שם מאוחד).'];
    else {
      if ($tg === '') { $all = bl_suppliers($range); $tg = $sel[0]; $best = -1.0; foreach ($sel as $n) { $v = $all[bl_canon($n)]['charge'] ?? 0; if ($v > $best) { $best = $v; $tg = bl_canon($n); } } }
      $n = bl_merge($sel, $tg, $by); audit('supplier_merge', 'budget', '', implode(' + ', $sel) . ' => ' . $tg);
      $_SESSION['smsg'] = [true, "אוחדו $n שמות תחת \"$tg\"."]; $back = 'budget_suppliers.php?' . http_build_query(['y' => $f['yr'], 's' => $tg]);
    }
  } elseif ($act === 'unmerge') {
    $al = bl_norm((string)($_POST['alias'] ?? '')); $main = bl_canon($al); db()->prepare('DELETE FROM budget_supplier_alias WHERE alias = ?')->execute([$al]); audit('supplier_unmerge', 'budget', '', $al);
    $_SESSION['smsg'] = [true, 'האיחוד בוטל.']; $back = 'budget_suppliers.php?' . http_build_query(['y' => $f['yr'], 's' => $main]);
  }
  redirect($back);
}
$smsg = $_SESSION['smsg'] ?? null; unset($_SESSION['smsg']);
if ($name !== '') {
  $L = bl_ledger($name, $range); $fmt = (string)($_GET['fmt'] ?? ''); $fn = preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', 'כרטסת_' . $name) . '_' . date('Y-m-d');
  if ($fmt === 'pdf') { audit('supplier_export', 'budget', '', 'PDF ' . $name); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($fn . '.pdf')); header('X-Content-Type-Options: nosniff'); echo bl_pdf($name, $L, $per); exit; }
  if ($fmt === 'xlsx') {
    $x = xlsx_build(bl_export_rows($name, $L, $per), [12, 24, 50, 14, 14, 16], [0, 3, count(bl_export_rows($name, $L, $per)) - 1], 'כרטסת');
    if ($x === null) { http_response_code(501); echo 'שרת האחסון לא תומך ביצירת Excel. אפשר לייצא PDF.'; exit; }
    audit('supplier_export', 'budget', '', 'Excel ' . $name); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($fn . '.xlsx')); header('X-Content-Type-Options: nosniff'); echo $x; exit;
  }
}
$yq = ['y' => $f['yr']];
page_head('תקציב – ספקים');
if ($smsg) echo '<p class="' . ($smsg[0] ? 'ok' : 'er') . '">' . e($smsg[1]) . '</p>';
if ($name !== ''):
  $qs = fn(array $o) => 'budget_suppliers.php?' . http_build_query($yq + ['s' => $name] + $o);
  $openInv = array_filter($L['rows'], fn($r) => $r['open']);
?>
<h1>כרטסת ספק: <?= e($name) ?></h1>
<p class="subnav"><a class="btn sec" href="budget_suppliers.php?<?= e(http_build_query($yq)) ?>">‹ לכל הספקים</a>
  <a class="btn" href="<?= e($qs(['fmt' => 'pdf'])) ?>">ייצוא PDF</a> <a class="btn" href="<?= e($qs(['fmt' => 'xlsx'])) ?>">ייצוא Excel</a>
  <a class="btn sec" href="budget_pay.php?add=1#pform">הוספת חשבונית</a></p>
<form method="get" class="bar auto card"><input type="hidden" name="s" value="<?= e($name) ?>"><?= budget_year_select($f) ?>
  <?php if ($L['info']['biz'] !== '' || $L['info']['account'] !== ''): ?><span class="mu"><?= $L['info']['biz'] !== '' ? 'עוסק ' . e($L['info']['biz']) . ' · ' : '' ?><?= $L['info']['account'] !== '' ? 'בנק ' . e($L['info']['bank'] . '-' . $L['info']['branch'] . '-' . $L['info']['account']) : '' ?></span><?php endif; ?></form>
<div class="tiles"><div class="tile"><span class="mu">סה"כ חיובים</span><b><?= e(budget_money($L['totals']['c'])) ?></b></div><div class="tile g"><span class="mu">סה"כ שולם</span><b class="ok"><?= e(budget_money($L['totals']['p'])) ?></b></div><div class="tile p"><span class="mu">יתרה לתשלום</span><b class="<?= $L['totals']['bal'] > 0.004 ? 'er' : '' ?>"><?= e(budget_money($L['totals']['bal'])) ?></b></div></div>
<?php $al = array_values(array_filter(bl_names($name), fn($x) => $x !== $name)); if ($al): ?><div class="card"><b>שמות שאוחדו לספק הזה:</b>
  <?php foreach ($al as $a): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="unmerge"><input type="hidden" name="alias" value="<?= e($a) ?>"><input type="hidden" name="y" value="<?= e($f['yr']) ?>"> <span class="pill"><?= e($a) ?></span> <button class="sec" data-confirm="לבטל את האיחוד של &quot;<?= e($a) ?>&quot;?">ביטול איחוד</button></form> <?php endforeach; ?></div><?php endif; ?>
<?php if ($L['rows']): ?><div class="card sc"><table><tr><th>תאריך</th><th>מסמך</th><th>פרטים</th><th>חיוב</th><th>תשלום</th><th>יתרה לתשלום</th></tr>
<?php if (abs($L['opening']) > 0.004): ?><tr><td></td><td><b>יתרת פתיחה</b></td><td></td><td></td><td></td><td class="n"><?= e(budget_money($L['opening'])) ?></td></tr><?php endif; ?>
<?php foreach ($L['rows'] as $r): ?><tr<?= $r['open'] ? ' class="unm"' : '' ?>><td class="n"><?= e(date('d/m/Y', strtotime($r['d']))) ?></td><td><?= e($r['doc']) ?></td><td><?= e($r['txt']) ?></td><td class="n"><?= $r['c'] > 0 ? e(budget_money($r['c'])) : '' ?></td><td class="n ok"><?= $r['p'] > 0 ? e(budget_money($r['p'])) : '' ?></td><td class="n"><b><?= e(budget_money($r['bal'])) ?></b></td></tr><?php endforeach; ?>
<tr><td></td><td><b>סה"כ</b></td><td></td><td class="n"><b><?= e(budget_money($L['totals']['c'])) ?></b></td><td class="n"><b><?= e(budget_money($L['totals']['p'])) ?></b></td><td class="n"><b><?= e(budget_money($L['totals']['bal'])) ?></b></td></tr></table></div>
<p class="mu">חיוב = חשבונית או הוצאה; תשלום = כל תשלום (מלא או חלקי) שנרשם; היתרה היא מה שנשאר לשלם לספק. שורות מסומנות באדום הן חשבוניות או הוצאות שעדיין לא שולמו.</p>
<?php else: ?><div class="card"><p class="emp">אין תנועות לספק זה בתקופה שנבחרה.</p></div><?php endif; ?>
<?php else:
  $S = bl_suppliers($range); $q = mb_strtolower((string)($f['q'] ?? '')); $show = (string)($_GET['show'] ?? '');
  $S = array_filter($S, function ($v, $k) use ($q, $show) { if ($q !== '' && mb_strpos(mb_strtolower($k), $q) === false) return false; $bal = $v['charge'] - $v['paid']; if ($show === 'open' && $bal < 0.005) return false; if ($show === 'inv' && $v['inv'] === 0) return false; return true; }, ARRAY_FILTER_USE_BOTH);
  uksort($S, fn($a, $b) => (($S[$b]['charge'] - $S[$b]['paid']) <=> ($S[$a]['charge'] - $S[$a]['paid'])) ?: strcmp($a, $b));
  $sum = ['c' => 0, 'p' => 0]; foreach ($S as $v) { $sum['c'] += $v['charge']; $sum['p'] += $v['paid']; }
?>
<h1>תקציב – ספקים <span class="mu">(<?= count($S) ?>)</span></h1>
<p class="mu">לכל ספק כרטסת עם כל החשבוניות והתשלומים והיתרה לתשלום, עם ייצוא ל-PDF ול-Excel. ספקים נלקחים מהחשבוניות ב"לתשלום" ומההוצאות בתנועות (לפי שם זהה).</p>
<form method="get" class="bar auto card"><?= budget_year_select($f) ?>
  <label>חיפוש ספק<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="שם ספק…"></label>
  <label>הצגה<select name="show"><?php foreach ([''=>'כל הספקים', 'open'=>'עם יתרה לתשלום', 'inv'=>'עם חשבוניות'] as $k => $l) echo '<option value="' . $k . '"' . ($show === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></label></form>
<?php if ($S): ?><form method="post" class="card sc"><?= csrf_field() ?><input type="hidden" name="act" value="merge"><input type="hidden" name="y" value="<?= e($f['yr']) ?>">
<div class="bar"><span class="mu">לאיחוד שמות של אותו ספק: מסמנים את השמות ולוחצים "איחוד". כל השמות יוצגו ככרטסת אחת.</span> <label>שם הספק המאוחד (לא חובה)<input name="target" maxlength="150" placeholder="ברירת מחדל: השם עם הסכום הגדול ביותר"></label> <button class="sec">איחוד הספקים המסומנים</button></div>
<table><tr><th></th><th>ספק</th><th>חשבוניות</th><th>חיובים</th><th>שולם</th><th>יתרה לתשלום</th></tr>
<?php foreach ($S as $n => $v): $bal = round($v['charge'] - $v['paid'], 2); ?><tr><td><input type="checkbox" name="sel[]" value="<?= e($n) ?>" aria-label="סימון לאיחוד"></td><td><a href="budget_suppliers.php?<?= e(http_build_query($yq + ['s' => $n])) ?>"><b><?= e($n) ?></b></a></td><td><?= $v['inv'] ? (int)$v['inv'] . ($v['open'] ? ' <span class="mu">(' . (int)$v['open'] . ' פתוחות)</span>' : '') : '—' ?></td><td class="n"><?= e(budget_money($v['charge'])) ?></td><td class="n"><?= e(budget_money($v['paid'])) ?></td><td class="n <?= $bal > 0.004 ? 'er' : '' ?>"><b><?= e(budget_money($bal)) ?></b></td></tr><?php endforeach; ?>
<tr><td></td><td><b>סה"כ</b></td><td></td><td class="n"><b><?= e(budget_money($sum['c'])) ?></b></td><td class="n"><b><?= e(budget_money($sum['p'])) ?></b></td><td class="n"><b><?= e(budget_money($sum['c'] - $sum['p'])) ?></b></td></tr></table></form>
<?php else: ?><div class="card"><p class="emp">לא נמצאו ספקים.</p></div><?php endif; ?>
<?php endif; page_foot();
