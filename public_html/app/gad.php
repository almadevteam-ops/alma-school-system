<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$canAct = in_array($u['role'], ['admin', 'finance'], true);
$msg = ''; $err = '';
$gid = gad_payer_id(); $fams = gad_families();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); if (!$canAct) fail_page(403, 'אין לך הרשאה לפעולה זו.');
  $m = (int)($_POST['month'] ?? 0); $act = (string)($_POST['act'] ?? '');
  if ($m >= 1 && $m <= 12) {
    $cur = db()->prepare('SELECT paid FROM gad_months WHERE month = ?'); $cur->execute([$m]); $was = (int)$cur->fetchColumn();
    if ($act === 'toggle') {
      if ($was) { $v = db()->prepare("UPDATE payments SET voided_at = ?, voided_by = ? WHERE batch = ? AND voided_at IS NULL"); $v->execute([now(), $u['id'], 'gad' . $m]); $msg = 'סימון התשלום בוטל (' . $v->rowCount() . ' תשלומים בוטלו).'; }
      else { $n = gad_mark($m, $fams, $gid, (int)$u['id']); $msg = 'נרשם תשלום של מורשת גד ל-' . $n . ' תיקים.'; }
      db()->prepare('INSERT INTO gad_months (month, paid, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE paid = VALUES(paid), updated_at = VALUES(updated_at)')->execute([$m, $was ? 0 : 1, now()]);
      audit('gad_toggle', 'family', '', SCHOOL_MONTHS[$m] . ($was ? ' בוטל' : ' שולם'));
    } elseif ($act === 'sync' && $was) { $n = gad_mark($m, $fams, $gid, (int)$u['id']); $msg = 'עודכנו ' . $n . ' תיקים שחסר בהם תשלום לחודש זה.'; }
    elseif ($act === 'ref') {
      db()->prepare('INSERT INTO gad_months (month, paid, ref, updated_at) VALUES (?,0,?,?) ON DUPLICATE KEY UPDATE ref = VALUES(ref), updated_at = VALUES(updated_at)')->execute([$m, mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 100), now()]); $msg = 'נשמר.';
    }
  }
}
$gm = []; foreach (db()->query('SELECT month, paid, ref FROM gad_months')->fetchAll() as $r) $gm[(int)$r['month']] = $r;
$data = collection_data(array_keys($fams));
$sm = array_fill(1, 12, 0.0); $cnt = array_fill(1, 12, 0); $tot = 0.0; $paidSum = 0.0;
foreach ($data as $d) foreach ($d['status'] as $m => $st) if ($st !== 'na') { $sm[$m] += $d['monthly']; $cnt[$m]++; $tot += $d['monthly']; $paidSum += min((float)$d['cov'][$m], $d['monthly']); }
page_head('מורשת גד');
?>
<h1>מורשת גד – תלמידות בדירה <span class="mu">(<?= count($fams) ?> תיקים)</span></h1>
<p class="subnav"><a class="btn sec" href="collection.php">‹ לגבייה</a></p>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="tiles">
  <div class="tile"><span class="mu">תיקים בדירה</span><b><?= count($fams) ?></b></div>
  <div class="tile"><span class="mu">סה״כ לשנה</span><b><?= e(money($tot)) ?></b></div>
  <div class="tile g"><span class="mu">שולם עד כה</span><b class="ok"><?= e(money($paidSum)) ?></b></div>
  <div class="tile p"><span class="mu">טרם שולם</span><b class="er"><?= e(money($tot - $paidSum)) ?></b></div>
</div>
<p class="mu">לחיצה על "סימון שולם" רושמת תשלום (באמצעות הגורם המשלם "מורשת גד") לכל תלמידות הדירה בחודש הזה. ביטול הסימון מבטל את אותם תשלומים בלבד.</p>
<div class="sc"><table><tr><th>חודש</th><th>תיקים</th><th>סכום</th><th>סטטוס</th><th>תאריך / אסמכתא</th></tr>
<?php foreach (SCHOOL_MONTHS as $m => $nm): $paid = !empty($gm[$m]['paid']); ?>
<tr><td><b><?= e($nm) ?></b></td><td><?= $cnt[$m] ?></td><td><?= e(money($sm[$m])) ?></td>
<td><?php if ($canAct): ?>
  <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="month" value="<?= $m ?>"><input type="hidden" name="act" value="toggle"><button class="<?= $paid ? '' : 'sec' ?>"><?= $paid ? '✓ שולם (לביטול)' : 'סימון שולם' ?></button></form>
  <?php if ($paid): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="month" value="<?= $m ?>"><input type="hidden" name="act" value="sync"><button class="sec" title="תלמידה שנוספה אחרי הסימון">השלמה</button></form><?php endif; ?>
  <?php else: ?><?= $paid ? '✓ שולם' : 'לא שולם' ?><?php endif; ?></td>
<td><?php if ($canAct): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="month" value="<?= $m ?>"><input type="hidden" name="act" value="ref"><input name="ref" maxlength="100" value="<?= e((string)($gm[$m]['ref'] ?? '')) ?>" placeholder="תאריך / אסמכתא"> <button class="sec">שמירה</button></form><?php else: echo e((string)($gm[$m]['ref'] ?? '')); endif; ?></td></tr>
<?php endforeach; ?></table></div>
<h2>פירוט לפי תלמידה וחודש</h2>
<div class="sc"><table><tr><th>תיק</th><th>תלמידות</th><th>לחודש</th><th>חודשים</th></tr>
<?php foreach ($fams as $fid => $f): $d = $data[$fid] ?? null; if (!$d) continue; ?>
<tr><td><a href="family.php?id=<?= $fid ?>"><?= e($f['name']) ?></a></td><td><?= e((string)$f['names']) ?></td><td><?= e(money($d['monthly'])) ?></td><td class="mcs"><?= month_cells($d) ?></td></tr>
<?php endforeach; if (!$fams) echo '<tr><td colspan="4" class="mu">אין תיקים של מורשת גד. סמני תלמידה "גרה בדירה" בכרטיס שלה.</td></tr>'; ?></table></div>
<?php page_foot();
