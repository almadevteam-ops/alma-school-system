<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$err = ''; $msg = '';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'create') {
  csrf_check();
  $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
  if ($name === '') $err = 'נא למלא שם.';
  else {
    db()->prepare('INSERT INTO payers (name, notes, created_at) VALUES (?,?,?)')->execute([$name, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255), now()]);
    $nid = (int)db()->lastInsertId(); audit('payer_create', 'payer', (string)$nid, $name);
    redirect('payer.php?id=' . $nid);
  }
}
if (!$id) {
  page_head('גורם משלם חדש');
  echo '<h1>גורם משלם חדש</h1>'; if ($err) echo '<p class="er">' . e($err) . '</p>';
  echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="act" value="create"><div class="fg"><label>שם (למשל: מורשת גד)<input name="name" maxlength="100" required></label><label>הערות<input name="notes" maxlength="255"></label></div><p><button>יצירה</button> <a class="btn sec" href="collection.php">חזרה</a></p></form>';
  page_foot(); exit;
}
$st = db()->prepare('SELECT * FROM payers WHERE id = ?'); $st->execute([$id]); $payer = $st->fetch();
if (!$payer) fail_page(404, 'הגורם המשלם לא נמצא.');
$mem = db()->prepare("SELECT f.id, f.name, f.monthly, f.months, (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM students s WHERE s.family_id = f.id) names FROM families f WHERE f.payer_id = ? ORDER BY f.name");
$mem->execute([$id]); $members = $mem->fetchAll();
$expected = round(array_sum(array_map(fn($m) => (float)$m['monthly'], $members)), 2);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  $pdo = db();
  if ($act === 'pay') {
    $fm = (int)($_POST['for_month'] ?? 0); $amt = money_in((string)($_POST['amount'] ?? '')); $date = date_in((string)($_POST['paid_on'] ?? ''));
    $ref = mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 60); $force = !empty($_POST['force']); $dupok = !empty($_POST['dupok']);
    if (!$members) $err = 'אין תיקים משויכים לגורם הזה.';
    elseif ($fm < 1 || $fm > 12) $err = 'נא לבחור חודש.';
    elseif ($amt === null || $amt <= 0) $err = 'סכום לא תקין.';
    elseif (!$date) $err = 'התאריך לא תקין (למשל 29/10/2026).';
    else {
      $exists = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payer_id = ? AND for_month = ? AND voided_at IS NULL'); $exists->execute([$id, $fm]);
      if ($exists->fetchColumn() && !$dupok) $err = 'כבר נרשם תשלום מהגורם הזה לחודש ' . SCHOOL_MONTHS[$fm] . '. אם זה תשלום נוסף, סמני "תשלום נוסף לאותו חודש".';
      elseif (abs($amt - $expected) > 0.004 && !$force) $err = 'הסכום (' . money($amt) . ') שונה מסכום התעריפים של התיקים (' . money($expected) . '). אם זה נכון, סמני "לחלק לפי יחס התעריפים".';
      else {
        // חלוקה: אם הסכום זהה – לכל תיק לפי התעריף; אחרת – יחסית, והשארית לתיק הגדול
        $parts = []; $sumM = $expected ?: 1;
        foreach ($members as $m) $parts[(int)$m['id']] = round($amt * (float)$m['monthly'] / $sumM, 2);
        $diff = round($amt - array_sum($parts), 2);
        if (abs($diff) > 0.004) { arsort($parts); $k = array_key_first($parts); $parts[$k] = round($parts[$k] + $diff, 2); }
        $batch = bin2hex(random_bytes(8));
        $pdo->beginTransaction();
        try {
          $ins = $pdo->prepare("INSERT INTO payments (family_id, for_month, amount, paid_on, method, ref, note, payer_id, batch, created_by, created_at) VALUES (?,?,?,?,'payer',?,?,?,?,?,?)");
          foreach ($parts as $fid => $part) if ($part > 0) $ins->execute([$fid, $fm, $part, $date, $ref, $payer['name'] . ' – ' . SCHOOL_MONTHS[$fm], $id, $batch, $u['id'], now()]);
          $pdo->commit();
        } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
        audit('payer_payment', 'payer', (string)$id, SCHOOL_MONTHS[$fm] . ' ' . $amt . ' ל-' . count($parts) . ' תיקים');
        redirect('payer.php?id=' . $id . '&m=' . rawurlencode('התשלום נרשם ל-' . count($parts) . ' תיקים לחודש ' . SCHOOL_MONTHS[$fm] . '.'));
      }
    }
  } elseif ($act === 'void_batch') {
    $b = (string)($_POST['batch'] ?? '');
    if (preg_match('/^[a-f0-9]{16}$/', $b)) {
      $pdo->prepare('UPDATE payments SET voided_at = ?, voided_by = ? WHERE batch = ? AND payer_id = ? AND voided_at IS NULL')->execute([now(), $u['id'], $b, $id]);
      audit('payer_payment_void', 'payer', (string)$id, 'קבוצה ' . $b);
    }
    redirect('payer.php?id=' . $id . '&m=' . rawurlencode('התשלום בוטל בכל התיקים (נשמר ביומן).'));
  }
}
if (!empty($_GET['m'])) $msg = mb_substr((string)$_GET['m'], 0, 200);
$bt = db()->prepare('SELECT batch, for_month, MIN(paid_on) paid_on, SUM(amount) total, COUNT(*) n, MAX(ref) ref, MAX(voided_at) voided FROM payments WHERE payer_id = ? GROUP BY batch, for_month ORDER BY MIN(paid_on) DESC, batch DESC');
$bt->execute([$id]); $batches = $bt->fetchAll();
page_head($payer['name']);
?>
<h1><?= e($payer['name']) ?> <span class="mu">גורם משלם</span></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <h2>רישום תשלום מהגורם</h2>
  <p class="mu">התשלום ירשם אוטומטית בכל התיקים המשויכים (<?= count($members) ?>), והחודש יסומן כשולם. סכום צפוי לחודש: <b><?= e(money($expected)) ?></b></p>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="pay">
    <label>חודש<select name="for_month" required><option value="">בחרי…</option><?php foreach (SCHOOL_MONTHS as $k => $v) if ($k <= 10) echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
    <label>סכום (₪)<input name="amount" inputmode="decimal" value="<?= e((string)$expected) ?>" required></label>
    <label>תאריך<input name="paid_on" value="<?= e(date('d/m/Y')) ?>" required></label>
    <label>אסמכתא<input name="ref" maxlength="60"></label>
    <label class="chk"><input type="checkbox" name="force" value="1"> לחלק לפי יחס התעריפים אם הסכום שונה</label>
    <label class="chk"><input type="checkbox" name="dupok" value="1"> תשלום נוסף לאותו חודש</label>
    <button>רישום</button>
  </form>
</div>
<div class="card">
  <h2>תשלומים שנרשמו</h2>
  <div class="sc"><table><tr><th>חודש</th><th>תאריך</th><th>סכום</th><th>תיקים</th><th>אסמכתא</th><th></th></tr>
  <?php foreach ($batches as $b): ?>
    <tr class="<?= $b['voided'] ? 'void' : '' ?>"><td><?= e(SCHOOL_MONTHS[(int)$b['for_month']] ?? '') ?></td><td><?= e(date_out($b['paid_on'])) ?></td><td><?= e(money($b['total'])) ?></td><td><?= (int)$b['n'] ?></td><td><?= e($b['ref']) ?></td>
    <td><?php if ($b['voided']): ?><span class="mu">בוטל</span><?php else: ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="void_batch"><input type="hidden" name="batch" value="<?= e($b['batch']) ?>"><button class="sec">ביטול</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$batches) echo '<tr><td colspan="6" class="mu">עדיין לא נרשמו תשלומים.</td></tr>'; ?>
  </table></div>
</div>
<div class="card">
  <h2>תיקים משויכים (<?= count($members) ?>)</h2>
  <div class="sc"><table><tr><th>תיק</th><th>תלמידות</th><th>תעריף חודשי</th></tr>
  <?php foreach ($members as $m): ?><tr><td><a href="family.php?id=<?= (int)$m['id'] ?>"><?= e($m['name']) ?></a></td><td class="mu"><?= e((string)$m['names']) ?></td><td><?= e(money($m['monthly'])) ?></td></tr><?php endforeach; ?>
  </table></div>
  <p class="mu">שיוך תיק לגורם משלם נעשה בהגדרות הגבייה של התיק. תלמידה שהוסרה מהדירה פשוט מוסרת מהשיוך שם.</p>
  <a class="btn sec" href="collection.php">חזרה לגבייה</a>
</div>
<?php page_foot();
