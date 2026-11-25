<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']); $by = (string)($me['name'] ?: $me['username']);
if (!bplan_ready()) { page_head('תקציב – מול ביצוע'); echo '<h1>תקציב מול ביצוע</h1><p class="aptn">כדי להפעיל את הדף יש להריץ "עדכון מסד נתונים" (תחזוקה).</p>'; page_foot(); exit; }
$units = budget_units(); $cur = budget_year_current();
$years = array_values(array_unique(array_merge(budget_years(), [$cur, $cur + 1]))); rsort($years);
$y = isset($_REQUEST['y']) && ctype_digit((string)$_REQUEST['y']) && in_array((int)$_REQUEST['y'], $years, true) ? (int)$_REQUEST['y'] : $cur;
$unit = (string)($_REQUEST['unit'] ?? ''); if ($unit !== '' && !in_array($unit, $units, true)) $unit = '';
$self = 'budget_plan.php?' . http_build_query(['y' => $y] + ($unit !== '' ? ['unit' => $unit] : []));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? '');
  if ($act === 'set') {   // שמירה אוטומטית של תקציב לשורה (JSON)
    header('Content-Type: application/json; charset=utf-8'); $cat = (string)($_POST['cat'] ?? ''); $a = budget_amount((string)($_POST['amount'] ?? '0'));
    if ($unit === '' || $a === null || $a < 0 || ($cat !== '' && !in_array($cat, budget_cats(), true))) { http_response_code(422); echo json_encode(['ok' => false, 'msg' => 'יש לבחור יחידה ולהזין סכום תקין']); exit; }
    bplan_set($y, $unit, $cat, (float)$a); audit('budget_plan', 'budget', (string)$y, "$unit / $cat = $a"); echo json_encode(['ok' => true]); exit;
  }
  if ($act === 'copy') { $n = bplan_copy($y, $unit, (float)str_replace(',', '.', (string)($_POST['pct'] ?? '0'))); audit('budget_plan_copy', 'budget', (string)$y, "$n שורות"); $_SESSION['plmsg'] = [true, $n ? "הועתקו $n שורות תקציב משנת " . budget_year_label($y - 1) . '.' : 'אין שורות להעתקה (או שהשורות כבר מולאו).']; }
  redirect($self);
}
$msg = $_SESSION['plmsg'] ?? null; unset($_SESSION['plmsg']);
$rows = bplan_rows($y, $unit); $tot = bplan_totals($rows); $pt = bplan_pct($tot['plan'], $tot['spent_planned']);
$tab = fn(string $u, string $l) => '<a class="btn' . ($unit === $u ? '' : ' sec') . '" href="budget_plan.php?' . e(http_build_query(['y' => $y] + ($u !== '' ? ['unit' => $u] : []))) . '">' . e($l) . '</a>';
page_head('תקציב – מול ביצוע');
?>
<h1>תקציב מול ביצוע – <?= e(budget_year_label($y)) ?><?= $unit !== '' ? ' · ' . e($unit) : '' ?></h1>
<?= bpay_banner() ?>
<?php if ($msg) echo '<p class="' . ($msg[0] ? 'ok' : 'er') . '">' . e($msg[1]) . '</p>'; ?>
<form method="get" class="bar auto card"><label>שנה<select name="y"><?php foreach ($years as $yy) echo '<option value="' . $yy . '"' . ($yy === $y ? ' selected' : '') . '>' . e(budget_year_label($yy)) . ' (' . $yy . '–' . ($yy + 1) . ')</option>'; ?></select></label><?php if ($unit !== '') echo '<input type="hidden" name="unit" value="' . e($unit) . '">'; ?></form>
<p class="subnav"><?= $tab('', 'כל היחידות') ?> <?php foreach ($units as $u) echo $tab($u, $u) . ' '; ?></p>
<p class="mu"><?= $unit === '' ? 'זהו סיכום של התקציבים שהוגדרו לכל היחידות. כדי להגדיר או לשנות תקציב, בוחרים יחידה למעלה.' : 'מקלידים תקציב שנתי לכל קטגוריה, והוא נשמר אוטומטית. ההוצאות נספרות מתנועות היחידה בשנה שנבחרה. מ-80% הניצול השורה מסומנת בכתום, ומ-100% באדום.' ?></p>
<div class="tiles"><div class="tile"><span class="mu">תקציב מתוכנן</span><b><?= e(budget_money0($tot['plan'])) ?></b></div><div class="tile"><span class="mu">הוצא בקטגוריות עם תקציב</span><b><?= e(budget_money0($tot['spent_planned'])) ?></b></div><div class="tile"><span class="mu">נשאר</span><b class="<?= $tot['plan'] - $tot['spent_planned'] < 0 ? 'er' : 'ok' ?>"><?= e(budget_money0($tot['plan'] - $tot['spent_planned'])) ?></b></div><div class="tile"><span class="mu">ניצול</span><b class="<?= $pt !== null && $pt >= 100 ? 'er' : '' ?>"><?= $pt === null ? '—' : number_format($pt, 0) . '%' ?></b></div></div>
<form method="post" class="card sc planf" data-y="<?= $y ?>" data-unit="<?= e($unit) ?>"><?= csrf_field() ?><span class="asv mu" role="status" aria-live="polite"></span>
<table><tr><th>קטגוריה</th><th>תקציב שנתי ₪</th><th>הוצא</th><th>נשאר</th><th>ניצול</th></tr>
<?php foreach ($rows as $r): $pc = bplan_pct($r['plan'], $r['spent']); $left = $r['plan'] - $r['spent']; ?>
<tr class="plr<?= bplan_class($pc) ?>" data-spent="<?= e((string)$r['spent']) ?>"><td><?= $r['cat'] === '' ? '<span class="mu">לא משויך</span>' : e($r['cat']) ?></td>
<td><?php if ($unit !== '' && $r['cat'] !== ''): ?><input class="pay-in" name="pl" data-cat="<?= e($r['cat']) ?>" value="<?= $r['plan'] > 0 ? e(rtrim(rtrim(number_format($r['plan'], 2, '.', ''), '0'), '.')) : '' ?>" inputmode="decimal" dir="ltr" aria-label="תקציב שנתי"><?php else: ?><?= $r['plan'] > 0 ? e(budget_money0($r['plan'])) : '—' ?><?php endif; ?></td>
<td class="n"><a href="budget_tx.php?<?= e(http_build_query(['y' => $y, 'type' => 'out', 'cat' => $r['cat'] === '' ? '__un' : $r['cat']] + ($unit !== '' ? ['unit' => $unit] : []))) ?>"><?= e(budget_money0($r['spent'])) ?></a></td>
<td class="n pl-left"><?= $r['plan'] > 0 ? e(budget_money0($left)) : '—' ?></td>
<td class="pl-bar"><?php if ($pc !== null): ?><div class="bgb pl"><i class="w<?= (int)max(1, min(100, round($pc))) ?><?= bplan_class($pc) ?>"></i><span class="n"><?= number_format($pc, 0) ?>%</span></div><?php endif; ?></td></tr>
<?php endforeach; ?>
<tr><td><b>סה"כ</b></td><td class="n"><b><?= e(budget_money0($tot['plan'])) ?></b></td><td class="n"><b><?= e(budget_money0($tot['spent'])) ?></b></td><td></td><td></td></tr></table></form>
<form method="post" class="bar card"><?= csrf_field() ?><input type="hidden" name="act" value="copy"><input type="hidden" name="y" value="<?= $y ?>"><input type="hidden" name="unit" value="<?= e($unit) ?>">
  <label>העתקה משנת <?= e(budget_year_label($y - 1)) ?> (לשורות ריקות בלבד), עם שינוי באחוזים<input name="pct" value="0" inputmode="decimal" dir="ltr" class="pay-in"></label><button class="sec">העתקת תקציב משנה קודמת<?= $unit === '' ? ' (לכל היחידות)' : '' ?></button></form>
<?php page_foot();
