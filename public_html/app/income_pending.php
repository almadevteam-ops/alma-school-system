<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'finance']);
$pdo = db(); $err = ''; $msg = '';
$fams = []; foreach ($pdo->query("SELECT f.id, f.name FROM families f ORDER BY f.name")->fetchAll() as $r) $fams[(int)$r['id']] = $r;
$mname = ['hok_card' => 'אשראי', 'hok_bank' => 'בנקאי', 'check' => 'צ׳ק', 'cash' => 'מזומן', 'other' => 'אחר'];
function ip_label(array $f): string { return $f['name'] . ' (#' . $f['id'] . ')'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? ''); $sel = array_map('intval', array_keys((array)($_POST['on'] ?? [])));
  if (!$sel) $err = 'לא סומנו שורות.';
  else {
    $in = implode(',', array_fill(0, count($sel), '?')); $q = $pdo->prepare("SELECT * FROM income_pending WHERE id IN ($in)"); $q->execute($sel); $rows = $q->fetchAll();
    if ($act === 'dismiss') {
      $pdo->prepare("DELETE FROM income_pending WHERE id IN ($in)")->execute($sel); audit('income_pending_dismiss', 'payment', '', count($rows) . ' שורות');
      $msg = 'הוסרו ' . count($rows) . ' שורות מהרשימה (לא נקלטו).';
    } elseif ($act === 'place') {
      $st = ['n' => 0, 'dup' => 0, 'sum' => 0.0, 'newCh' => 0]; $batch = 'inc' . bin2hex(random_bytes(6)); $left = 0; $done = [];
      $pdo->beginTransaction();
      foreach ($rows as $p) {
        $id = (int)$p['id']; $r = json_decode((string)$p['data'], true); if (!is_array($r)) continue;
        $kind = (string)($_POST['kind'][$id] ?? 't'); if (!in_array($kind, ['t', 'c'], true)) continue;
        $ids = []; foreach (explode('+', (string)($_POST['fam'][$id] ?? '')) as $part) if (preg_match('/\(#(\d+)\)\s*$/', trim($part), $m) && isset($fams[(int)$m[1]])) $ids[(int)$m[1]] = true;
        $ids = array_keys($ids); if (!$ids) { $left++; continue; }
        $mo = (int)($_POST['month'][$id] ?? 0); if ($mo < 1 || $mo > 12) $mo = 0;
        income_place($pdo, $r, $ids, $kind, $mo, $batch, (int)$u['id'], (float)$r['amt'], $st); $done[] = $id;
      }
      if ($done) $pdo->prepare('DELETE FROM income_pending WHERE id IN (' . implode(',', array_fill(0, count($done), '?')) . ')')->execute($done);
      $pdo->commit();
      audit('income_pending_place', 'payment', '', "נקלטו {$st['n']} תשלומים ({$st['sum']} ₪), כפולים {$st['dup']}, בלי תיק $left");
      $msg = "נקלטו {$st['n']} תשלומים בסך " . money($st['sum']) . ($st['dup'] ? ". דולגו כבר-נקלטו: {$st['dup']}" : '') . ($left ? ". $left שורות נשארו כי לא נבחר להן תיק" : '') . '.';
    }
  }
}
$list = $pdo->query('SELECT * FROM income_pending ORDER BY paid_on DESC, id DESC')->fetchAll();
$idx = $list ? income_index() : []; $sug = []; $tot = 0.0;
foreach ($list as $p) { $r = json_decode((string)$p['data'], true) ?: []; $tot += (float)$p['amount']; $sug[(int)$p['id']] = $r ? array_values(array_filter(income_match($r + ['idn' => '', 'phone' => '', 'email' => '', 'note' => '', 'donor' => ''], $idx), fn($x) => isset($fams[$x]))) : []; }
page_head('שורות שממתינות לשיוך');
?>
<h1>שורות שממתינות לשיוך</h1>
<p class="subnav"><a class="btn sec" href="income_import.php">‹ להעלאת דוח הכנסות</a> <a class="btn sec" href="collection.php">לגבייה</a></p>
<p class="mu">שורות מדוחות הכנסות שלא היה אפשר לשייך לתיק נשמרות כאן. כשמתברר למי הן שייכות, בוחרים תיק וקולטים אותן, בלי להעלות את הדוח מחדש. ההצעות מחושבות מחדש בכל פתיחה, לפי הנתונים העדכניים בתיקים.</p>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="tiles"><div class="tile"><span class="mu">שורות ממתינות</span><b><?= count($list) ?></b></div><div class="tile p"><span class="mu">סכום ממתין</span><b><?= e(money($tot)) ?></b></div></div>
<?php if (!$list): ?><p class="mu">אין שורות שממתינות לשיוך.</p><?php else: ?>
<datalist id="fl"><?php foreach ($fams as $f) echo '<option value="' . e(ip_label($f)) . '">'; ?></datalist>
<form method="post"><?= csrf_field() ?>
  <div class="bar"><button name="act" value="place" data-confirm="לקלוט את השורות המסומנות?">קליטת המסומנות</button><button name="act" value="dismiss" class="sec" data-confirm="להסיר את המסומנות מהרשימה בלי לקלוט?">הסרה מהרשימה</button></div>
  <div class="sc"><table><tr><th>בחירה</th><th>תאריך</th><th>משלם</th><th>הערה</th><th>קטגוריה</th><th>סכום</th><th>אמצעי</th><th>סוג</th><th>חודש</th><th>תיק / תיקים</th><th>מדוח</th></tr>
  <?php foreach ($list as $p): $id = (int)$p['id']; $r = json_decode((string)$p['data'], true) ?: []; $s = $sug[$id] ?? [];
    $kind = !empty($r['par']) ? 't' : 'c'; $mo = $p['paid_on'] ? school_month_of($p['paid_on']) : 0; ?>
  <tr<?= $s ? '' : ' class="unm"' ?>>
    <td><input type="checkbox" name="on[<?= $id ?>]" value="1"<?= count($s) === 1 ? ' checked' : '' ?>></td>
    <td><?= $p['paid_on'] ? e(date_out($p['paid_on'])) : '' ?></td><td><?= e($p['donor']) ?></td><td><?= e($p['note']) ?></td><td><?= e($p['cat']) ?></td><td><?= e(money((float)$p['amount'])) ?></td><td><?= e($mname[$r['method'] ?? ''] ?? '') ?></td>
    <td><select name="kind[<?= $id ?>]"><option value="t"<?= $kind === 't' ? ' selected' : '' ?>>שכר לימוד</option><option value="c"<?= $kind === 'c' ? ' selected' : '' ?>>חיוב חד־פעמי</option></select></td>
    <td><select name="month[<?= $id ?>]"><option value="0">אוטומטי</option><?php foreach (SCHOOL_MONTHS as $k => $v) echo '<option value="' . $k . '"' . ($mo === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></td>
    <td><input name="fam[<?= $id ?>]" list="fl" class="fam" value="<?= e(implode(' + ', array_map(fn($fid) => ip_label($fams[$fid]), $s))) ?>" placeholder="בחרי תיק"></td>
    <td class="mu"><?= e($p['report_name']) ?></td></tr>
  <?php endforeach; ?></table></div>
</form>
<?php endif; page_foot();
