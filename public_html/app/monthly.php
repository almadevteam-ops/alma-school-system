<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$ids = array_map('intval', db()->query("SELECT DISTINCT f.id FROM families f JOIN students s ON s.family_id = f.id AND s.archived_at IS NULL")->fetchAll(PDO::FETCH_COLUMN));
$data = collection_data($ids);
$ex = array_fill(1, 12, 0.0); $co = $ex; $wp = $ex; $n = array_fill(1, 12, 0);
$gad = gad_families(); $gadPaid = 0.0; $lateN = 0; $total = ['ex' => 0.0, 'co' => 0.0];
foreach ($data as $fid => $d) {
  foreach ($d['status'] as $m => $st) {
    if ($st === 'na') continue;
    $n[$m]++; $ex[$m] += $d['monthly']; $c = min((float)$d['cov'][$m], $d['monthly']); $co[$m] += $c;
    if ($st === 'wip') $wp[$m] += $d['monthly'] - $c;
  }
  if (isset($gad[$fid])) $gadPaid += array_sum(array_map(fn($m) => min((float)$d['cov'][$m], $d['monthly']), array_keys(array_filter($d['status'], fn($x) => $x !== 'na'))));
}
foreach ($data as $fid => $d) { $r = db()->prepare('SELECT collect_day FROM families WHERE id = ?'); $r->execute([$fid]); if (collection_arrears($d, $r->fetchColumn())['late'] > 0.004) $lateN++; }
$cur = school_month_now(); $missNow = 0.0; foreach (SCHOOL_MONTHS as $mm => $_) if ($mm <= $cur) $missNow += $ex[$mm] - $co[$mm];
$sumEx = array_sum($ex); $sumCo = array_sum($co); $sumWp = array_sum($wp);
$pend = (int)db()->query("SELECT COUNT(*) FROM students WHERE disc_on = 1 AND disc_status = 'pending' AND archived_at IS NULL")->fetchColumn();
$chg = db()->query("SELECT COALESCE(SUM(amount - disc_amt),0) FROM charges WHERE voided_at IS NULL AND mark <> 'n'")->fetchColumn();
$chp = db()->query("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN charges c ON c.id = p.charge_id WHERE p.voided_at IS NULL AND c.voided_at IS NULL")->fetchColumn();
page_head('סיכום חודשי');
?>
<h1>סיכום חודשי <span class="mu">(שכר לימוד לאחר הנחות)</span></h1>
<p class="subnav"><a class="btn sec" href="collection.php">‹ לגבייה</a></p>
<div class="tiles">
  <div class="tile"><span class="mu">צפוי לשנה</span><b><?= e(money($sumEx)) ?></b></div>
  <div class="tile g"><span class="mu">נגבה (כולל מראש)</span><b class="ok"><?= e(money($sumCo)) ?></b></div>
  <div class="tile w"><span class="mu">בטיפול</span><b><?= e(money($sumWp)) ?></b></div>
  <div class="tile p"><span class="mu">חסר עד היום (עד <?= e(SCHOOL_MONTHS[$cur]) ?> כולל)</span><b class="er"><?= e(money($missNow)) ?></b></div>
  <div class="tile"><span class="mu">אחוז גבייה</span><b><?= $sumEx > 0 ? (int)round($sumCo / $sumEx * 100) : 0 ?>%</b></div>
  <div class="tile"><span class="mu">שולם ע״י מורשת גד (דירות)</span><b><?= e(money($gadPaid)) ?></b></div>
  <div class="tile"><span class="mu">הנחות ממתינות לאישור</span><b><?= $pend ?></b></div>
  <div class="tile"><span class="mu">תיקים בפיגור היום</span><b><?= $lateN ?></b></div>
</div>
<div class="sc"><table><tr><th>חודש</th><th>תיקים</th><th>צפוי</th><th>נגבה</th><th>בטיפול</th><th>חסר</th><th>אחוז</th></tr>
<?php foreach (SCHOOL_MONTHS as $m => $nm): ?>
<tr<?= $m > $cur ? ' class="futrow"' : '' ?>><td><b><?= e($nm) ?></b><?= $m > $cur ? ' <span class="mu">(עתידי)</span>' : '' ?></td><td><?= $n[$m] ?></td><td><?= e(money($ex[$m])) ?></td><td><?= e(money($co[$m])) ?></td><td><?= e(money($wp[$m])) ?></td><td><?= $m > $cur ? '<span class="mu">–</span>' : e(money($ex[$m] - $co[$m])) ?></td><td><?= $ex[$m] > 0 ? (int)round($co[$m] / $ex[$m] * 100) . '%' : '–' ?></td></tr>
<?php endforeach; ?></table></div>
<p class="mu">חודשים שעוד לא הגיעו (באפור) אינם נספרים כחוסר; חודש נכנס לחוב ב-1 בחודש בחצות. הסיכום כולל שכר לימוד בלבד, אחרי הנחות מאושרות ובלי חודשים שסומנו "לא רלוונטי" או אחרי עזיבה. חיובים נוספים: חויב <?= e(money($chg)) ?>, שולם <?= e(money($chp)) ?> (פירוט בעמוד <a href="charges.php">גביות חד־פעמיות</a>).</p>
<?php page_foot();
