<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if (($_POST['act'] ?? '') === 'void_all') {
    if ($u['role'] !== 'admin') fail_page(403, 'פעולה למנהלת בלבד.');
    $label = (string)($_POST['label'] ?? '');
    $c = db()->prepare('UPDATE charges SET voided_at = ? WHERE label = ? AND voided_at IS NULL'); $c->execute([now(), $label]);
    audit('charge_void_all', 'family', '', $label . ': ' . $c->rowCount()); $msg = 'בוטלו ' . $c->rowCount() . ' חיובים "' . $label . '" (התשלומים נשמרים).';
  }
}
$G = db()->query("SELECT c.label, COUNT(*) n, SUM(CASE WHEN c.mark='n' THEN 0 ELSE c.amount - c.disc_amt END) ex,
  SUM(CASE WHEN c.mark='w' THEN GREATEST(0, c.amount - c.disc_amt - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0)) ELSE 0 END) wip,
  SUM(COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0)) paid, MAX(c.amount) mx
  FROM charges c WHERE c.voided_at IS NULL GROUP BY c.label ORDER BY MIN(c.id)")->fetchAll();
$sel = (string)($_GET['l'] ?? '');
page_head('גביות חד־פעמיות');
?>
<h1>גביות חד־פעמיות</h1>
<p class="subnav"><a class="btn sec" href="collection.php">‹ לגבייה</a> <a class="btn sec" href="collection.php?open=bulk#s-bulk">הוספת חיוב לכל התיקים</a></p>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="sc"><table><tr><th>גבייה</th><th>תיקים</th><th>צפוי</th><th>נגבה</th><th>בטיפול</th><th>חסר</th><th></th></tr>
<?php foreach ($G as $g): $miss = max(0, (float)$g['ex'] - (float)$g['paid']); ?>
<tr><td><b><?= e($g['label']) ?></b></td><td><?= (int)$g['n'] ?></td><td><?= e(money($g['ex'])) ?></td><td><?= e(money($g['paid'])) ?></td><td><?= e(money($g['wip'])) ?></td><td><b class="<?= $miss > 0.004 ? 'er' : 'ok' ?>"><?= e(money($miss)) ?></b></td>
<td><a class="btn sec" href="charges.php?l=<?= e(rawurlencode($g['label'])) ?>">פירוט</a>
<?php if ($u['role'] === 'admin'): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="void_all"><input type="hidden" name="label" value="<?= e($g['label']) ?>"><button class="dg" data-confirm="לבטל את כל החיובים &quot;<?= e($g['label']) ?>&quot;?">מחיקה</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$G) echo '<tr><td colspan="7" class="mu">אין עדיין גביות חד־פעמיות.</td></tr>'; ?></table></div>
<p class="mu">כל גבייה מופיעה גם בכרטיס הגבייה של כל משפחה, ושם אפשר לערוך סכום והנחה לתלמידה/משפחה ולסמן בטיפול / החזר / לא רלוונטי.</p>
<?php if ($sel !== ''):
  $q = db()->prepare("SELECT c.id, c.family_id, f.name, c.amount, c.disc_amt, c.mark, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0) paid FROM charges c JOIN families f ON f.id = c.family_id WHERE c.label = ? AND c.voided_at IS NULL ORDER BY f.name"); $q->execute([$sel]); $rows = $q->fetchAll(); ?>
<h2><?= e($sel) ?> – פירוט לפי משפחה</h2>
<div class="sc"><table><tr><th>משפחה</th><th>סכום</th><th>הנחה</th><th>שולם</th><th>מצב</th></tr>
<?php foreach ($rows as $r): $net = (float)$r['amount'] - (float)$r['disc_amt']; $st = $r['mark'] === 'n' ? 'לא רלוונטי' : ($r['paid'] >= $net - 0.004 ? 'שולם' : ($r['mark'] === 'r' ? 'החזר' : ($r['mark'] === 'w' || $r['paid'] > 0 ? 'בטיפול' : 'לא שולם'))); ?>
<tr><td><a href="family.php?id=<?= (int)$r['family_id'] ?>&open=chg"><?= e($r['name']) ?></a></td><td><?= e(money($r['amount'])) ?></td><td><?= (float)$r['disc_amt'] > 0 ? e(money($r['disc_amt'])) : '' ?></td><td><?= e(money($r['paid'])) ?></td><td><?= e($st) ?></td></tr>
<?php endforeach; ?></table></div>
<?php endif; page_foot();
