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
  if (($_POST['act'] ?? '') === 'pay_charges') {
    $label = (string)($_POST['label'] ?? ''); $date = date_in((string)($_POST['paid_on'] ?? '')); $m = (string)($_POST['method'] ?? '');
    if ($date === null) $err = 'התאריך לא תקין (למשל 12/11/2026).';
    elseif ($m !== '' && !isset(PAY_METHODS[$m])) $err = 'אמצעי תשלום לא תקין.';
    else {
      $pdo = db(); $pdo->beginTransaction(); $n = 0; $sum = 0.0; $bad = 0;
      try {
        $sel1 = $pdo->prepare("SELECT c.id, c.family_id, c.amount, c.disc_amt, c.mark, f.method fm, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0) paid FROM charges c JOIN families f ON f.id = c.family_id WHERE c.id = ? AND c.label = ? AND c.voided_at IS NULL FOR UPDATE");
        $ins = $pdo->prepare('INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, created_by, created_at) VALUES (?,?,NULL,?,?,?,\'\',?,?,?)');
        $ids = array_unique(array_merge(array_keys((array)($_POST['pay'] ?? [])), array_keys(array_filter((array)($_POST['amt'] ?? []), fn($x) => trim((string)$x) !== ''))));
        foreach ($ids as $cid) {
          $cid = (int)$cid; $sel1->execute([$cid, $label]); $r = $sel1->fetch(); if (!$r || $r['mark'] === 'n') { $bad++; continue; }
          $typed = trim((string)($_POST['amt'][$cid] ?? ''));
          if ($typed !== '') { $a = money_in($typed); if ($a === null || $a <= 0 || $a > 1000000) { $bad++; continue; } }
          else { $a = round((float)$r['amount'] - (float)$r['disc_amt'] - (float)$r['paid'], 2); if ($a <= 0.004) continue; }      // סימון "שולם" = משלימים את היתרה
          $method = $m !== '' ? $m : (isset(PAY_METHODS[$r['fm']]) ? $r['fm'] : 'other');
          $ins->execute([(int)$r['family_id'], $cid, $a, $date, $method, 'סומן בגבייה חד־פעמית', $u['id'], now()]); $n++; $sum += $a;
        }
        $pdo->commit();
      } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
      audit('charge_pay_bulk', 'family', '', "$label: $n תשלומים, " . money($sum));
      redirect('charges.php?l=' . rawurlencode($label) . '&m=' . rawurlencode($n ? "נרשמו $n תשלומים בסך " . money($sum) . '.' : 'לא נרשם שום תשלום (לא סומנו שורות עם יתרה או סכום).') . ($bad ? '&b=' . $bad : ''));
    }
  }
}
$msg = $msg ?: (string)($_GET['m'] ?? ''); if (!empty($_GET['b'])) $err = $err ?: ((int)$_GET['b'] . ' שורות דולגו (סכום לא תקין או חיוב שאינו רלוונטי).');
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
  $q = db()->prepare("SELECT c.id, c.family_id, f.name, (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM students s WHERE s.family_id = f.id AND s.archived_at IS NULL) studs, c.amount, c.disc_amt, c.mark, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0) paid FROM charges c JOIN families f ON f.id = c.family_id WHERE c.label = ? AND c.voided_at IS NULL ORDER BY f.name"); $q->execute([$sel]); $rows = $q->fetchAll(); ?>
<h2><?= e($sel) ?> – פירוט לפי משפחה</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="pay_charges"><input type="hidden" name="label" value="<?= e($sel) ?>">
<div class="bar">
  <label>תאריך התשלום<input name="paid_on" value="<?= e(date('d/m/Y')) ?>" required></label>
  <label>אמצעי תשלום<select name="method"><option value="">לפי אמצעי התשלום של התיק</option><?php foreach (PAY_METHODS as $k => $v) if ($k !== 'payer') echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
  <button>רישום התשלומים שסומנו</button>
</div>
<div class="sc"><table><tr><th>משפחה</th><th>תלמידות</th><th>סכום</th><th>הנחה</th><th>שולם</th><th>יתרה</th><th>מצב</th><th>שולם ✓</th><th>סכום ששולם (אם שונה מהיתרה)</th></tr>
<?php foreach ($rows as $r): $net = (float)$r['amount'] - (float)$r['disc_amt']; $left = max(0, round($net - (float)$r['paid'], 2)); $st = $r['mark'] === 'n' ? 'לא רלוונטי' : ($r['paid'] >= $net - 0.004 ? 'שולם' : ($r['mark'] === 'r' ? 'החזר' : ($r['mark'] === 'w' || $r['paid'] > 0 ? 'בטיפול' : 'לא שולם'))); $cid = (int)$r['id']; ?>
<tr><td><a href="family.php?id=<?= (int)$r['family_id'] ?>&open=chg"><?= e($r['name']) ?></a></td><td class="mu"><?= e((string)$r['studs']) ?></td><td><?= e(money($r['amount'])) ?></td><td><?= (float)$r['disc_amt'] > 0 ? e(money($r['disc_amt'])) : '' ?></td><td><?= e(money($r['paid'])) ?></td><td><?= $left > 0.004 ? e(money($left)) : '' ?></td><td><?= e($st) ?></td>
<?php if ($r['mark'] === 'n'): ?><td></td><td></td>
<?php else: ?><td><?php if ($left > 0.004): ?><input type="checkbox" name="pay[<?= $cid ?>]" value="1" aria-label="שולם"><?php else: ?><span class="ok">✓</span><?php endif; ?></td>
<td><input name="amt[<?= $cid ?>]" inputmode="decimal" size="7" placeholder="<?= $left > 0.004 ? e(rtrim(rtrim(number_format($left, 2, '.', ''), '0'), '.')) : '' ?>" aria-label="סכום ששולם"></td><?php endif; ?></tr>
<?php endforeach; ?></table></div>
<div class="bar"><button>רישום התשלומים שסומנו</button></div>
<p class="mu">סימון "שולם ✓" רושם תשלום בגובה היתרה. אם שולם סכום אחר (חלקי או יותר), מקלידים אותו בעמודה האחרונה ואין צורך לסמן. אפשר לסמן ולהזין כמה שורות ולשמור בבת אחת. התשלום נרשם גם בכרטיס הגבייה של המשפחה, ושם אפשר לבטל אותו.</p>
</form>
<?php endif; page_foot();
