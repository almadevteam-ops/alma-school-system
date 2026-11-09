<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$err = ''; $msg = '';

function load_family(int $id): ?array { $st = db()->prepare('SELECT * FROM families WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }

// פתיחת תיק גבייה לתלמידה שאין לה
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'create') {
  csrf_check();
  $sid = (int)($_POST['student_id'] ?? 0);
  $st = db()->prepare('SELECT id, name, family_id FROM students WHERE id = ?'); $st->execute([$sid]); $s = $st->fetch();
  if (!$s) fail_page(404, 'התלמידה לא נמצאה.');
  if ($s['family_id']) redirect('family.php?id=' . (int)$s['family_id']);
  $pdo = db(); $pdo->beginTransaction();
  $pdo->prepare('INSERT INTO families (name, created_at, updated_at) VALUES (?,?,?)')->execute([family_default_name([$s['name']]), now(), now()]);
  $nid = (int)$pdo->lastInsertId();
  $pdo->prepare('UPDATE students SET family_id = ? WHERE id = ?')->execute([$nid, $sid]);
  $pdo->commit();
  audit('family_create', 'family', (string)$nid, $s['name']);
  redirect('family.php?id=' . $nid);
}
$fam = $id ? load_family($id) : null;
if (!$fam) fail_page(404, 'תיק הגבייה לא נמצא.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  $pdo = db();
  if ($act === 'save') {
    $monthly = money_in((string)($_POST['monthly'] ?? '0')); $months = (int)($_POST['months'] ?? 10);
    $day = trim((string)($_POST['collect_day'] ?? '')); $method = (string)($_POST['method'] ?? '');
    $pk = (string)($_POST['payer'] ?? 'parents'); $payer = 0; $kind = 'parents';
    if (preg_match('/^p:(\d+)$/', $pk, $pm)) { $payer = (int)$pm[1]; $kind = 'org'; } elseif (isset(PAYER_KINDS[$pk])) $kind = $pk;
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    if ($name === '') $err = 'נא למלא שם משפחה.';
    elseif ($monthly === null) $err = 'התעריף החודשי לא תקין (מספר חיובי).';
    elseif (!in_array($months, [10, 12], true)) $err = 'מספר החודשים חייב להיות 10 או 12.';
    elseif ($day !== '' && (!ctype_digit($day) || (int)$day < 1 || (int)$day > 31)) $err = 'יום גבייה חייב להיות בין 1 ל-31.';
    elseif ($method !== '' && !isset(PAY_METHODS[$method])) $err = 'צורת תשלום לא תקינה.';
    else {
      if ($payer) { $c = $pdo->prepare('SELECT 1 FROM payers WHERE id = ?'); $c->execute([$payer]); if (!$c->fetchColumn()) { $payer = 0; $kind = 'parents'; } }
      $pdo->prepare('UPDATE families SET name=?, monthly=?, months=?, method=?, collect_day=?, payer_id=?, payer_kind=?, discount_note=?, notes=?, updated_at=? WHERE id=?')
        ->execute([$name, $monthly, $months, $method, $day === '' ? null : (int)$day, $payer ?: null, $kind, mb_substr(trim((string)($_POST['discount_note'] ?? '')), 0, 255), $fam['notes'], now(), $id]);
      audit('family_update', 'family', (string)$id, "תעריף $monthly × $months");
      redirect('family.php?id=' . $id . '&open=set&m=' . rawurlencode('נשמר.'));
    }
  } elseif ($act === 'add_charge') {
    $amt = money_in((string)($_POST['amount'] ?? '')); $kind = (string)($_POST['kind'] ?? 'other'); $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 100);
    if (!isset(CHARGE_KINDS[$kind])) $kind = 'other';
    if ($label === '') $label = CHARGE_KINDS[$kind];
    if ($amt === null || $amt <= 0) $err = 'סכום החיוב לא תקין.';
    else {
      $pdo->prepare('INSERT INTO charges (family_id, kind, label, amount, created_by, created_at) VALUES (?,?,?,?,?,?)')->execute([$id, $kind, $label, $amt, $u['id'], now()]);
      audit('charge_add', 'family', (string)$id, "$label $amt");
      redirect('family.php?id=' . $id . '&open=chg&m=' . rawurlencode('החיוב נוסף.'));
    }
  } elseif ($act === 'void_charge') {
    $cid = (int)($_POST['cid'] ?? 0);
    $pdo->prepare('UPDATE charges SET voided_at = ? WHERE id = ? AND family_id = ? AND voided_at IS NULL')->execute([now(), $cid, $id]);
    audit('charge_void', 'family', (string)$id, 'חיוב ' . $cid);
    redirect('family.php?id=' . $id . '&open=chg&m=' . rawurlencode('החיוב בוטל.'));
  } elseif ($act === 'add_payment') {
    $amt = money_in((string)($_POST['amount'] ?? '')); $date = date_in((string)($_POST['paid_on'] ?? ''));
    $method = (string)($_POST['method'] ?? 'other'); $target = (string)($_POST['target'] ?? 'tuition');
    $ref = mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 60); $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
    $fm = (int)($_POST['for_month'] ?? 0); $chargeId = null;
    if (!isset(PAY_METHODS[$method])) $method = 'other';
    if ($amt === null || $amt <= 0) $err = 'הסכום לא תקין.';
    elseif (!$date) $err = 'התאריך לא תקין (למשל 29/10/2026).';
    elseif (preg_match('/\d{12,}/', preg_replace('/[\s\-]/', '', $ref . $note))) $err = 'נראה שהוקלד מספר ארוך (אולי כרטיס אשראי). אל תכניסי מספרי כרטיס; אפשר רק מספר אסמכתא קצר.';
    else {
      if (str_starts_with($target, 'c')) {
        $chargeId = (int)substr($target, 1);
        $c = $pdo->prepare('SELECT id FROM charges WHERE id = ? AND family_id = ? AND voided_at IS NULL'); $c->execute([$chargeId, $id]);
        if (!$c->fetchColumn()) { $err = 'החיוב לא נמצא.'; }
        $fm = 0;
      }
      if (!$err) {
        $pdo->prepare('INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
          ->execute([$id, $chargeId, ($fm >= 1 && $fm <= 12) ? $fm : null, $amt, $date, $method, $ref, $note, $u['id'], now()]);
        audit('payment_add', 'family', (string)$id, "$amt " . $method . ($fm ? " חודש $fm" : ''));
        redirect('family.php?id=' . $id . '&open=pay&m=' . rawurlencode('התשלום נרשם.'));
      }
    }
  } elseif ($act === 'void_payment') {
    $pid = (int)($_POST['pid'] ?? 0);
    $pdo->prepare('UPDATE payments SET voided_at = ?, voided_by = ? WHERE id = ? AND family_id = ? AND voided_at IS NULL')->execute([now(), $u['id'], $pid, $id]);
    audit('payment_void', 'family', (string)$id, 'תשלום ' . $pid);
    redirect('family.php?id=' . $id . '&open=pay&m=' . rawurlencode('התשלום בוטל (נשמר ביומן).'));
  } elseif ($act === 'month_set') {
    $m = (int)($_POST['month'] ?? 0); $op = (string)($_POST['op'] ?? '');
    if ($m < 1 || $m > (int)$fam['months'] || !in_array($op, ['paid', 'w', 'r', 'n', 'clear'], true)) $err = 'בחירת חודש או פעולה לא תקינה.';
    else {
      $cur = collection_data([$id])[$id];
      if ($op === 'paid') {
        $miss = round($cur['monthly'] - (float)$cur['cov'][$m], 2);
        if ($miss <= 0.004) $err = 'החודש כבר שולם במלואו.';
        else {
          $pdo->prepare('INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, null, $m, $miss, date('Y-m-d'), $fam['method'] !== '' && isset(PAY_METHODS[$fam['method']]) && $fam['method'] !== 'payer' ? $fam['method'] : 'other', '', 'סומן שולם ידנית', $u['id'], now()]);
          $pdo->prepare('DELETE FROM month_marks WHERE family_id = ? AND month = ?')->execute([$id, $m]);
          audit('month_paid', 'family', (string)$id, SCHOOL_MONTHS[$m] . " $miss");
        }
      } elseif ($op === 'clear') { $pdo->prepare('DELETE FROM month_marks WHERE family_id = ? AND month = ?')->execute([$id, $m]); audit('month_mark', 'family', (string)$id, SCHOOL_MONTHS[$m] . ' נוקה'); }
      else { $pdo->prepare('REPLACE INTO month_marks (family_id, month, mark, updated_by, updated_at) VALUES (?,?,?,?,?)')->execute([$id, $m, $op, $u['id'], now()]); audit('month_mark', 'family', (string)$id, SCHOOL_MONTHS[$m] . ' ' . $op); }
      if (!$err) redirect('family.php?id=' . $id . '&m=' . rawurlencode('החודש עודכן.'));
    }
  } elseif ($act === 'card_ok') {
    $pdo->prepare('UPDATE families SET card_ok = ?, updated_at = ? WHERE id = ?')->execute([!empty($_POST['ok']) ? date('Y-m-d') : null, now(), $id]);
    audit('card_ok', 'family', (string)$id, !empty($_POST['ok']) ? 'האשראי הוסדר' : 'בוטל סימון הסדרת אשראי');
    redirect('family.php?id=' . $id . '&m=' . rawurlencode('עודכן.'));
  } elseif ($act === 'charge_edit') {
    $cid = (int)($_POST['cid'] ?? 0); $amt = money_in((string)($_POST['amount'] ?? '')); $da = trim((string)($_POST['disc_amt'] ?? '')) === '' ? 0.0 : money_in((string)$_POST['disc_amt']);
    $mk = (string)($_POST['mark'] ?? ''); if (!in_array($mk, ['', 'w', 'r', 'n'], true)) $mk = '';
    if ($amt === null || $amt <= 0) $err = 'סכום החיוב לא תקין.';
    elseif ($da === null || $da < 0 || $da > $amt) $err = 'ההנחה חייבת להיות בין 0 לסכום החיוב.';
    else {
      $pdo->prepare('UPDATE charges SET amount = ?, disc_amt = ?, disc_by = ?, disc_note = ?, mark = ? WHERE id = ? AND family_id = ? AND voided_at IS NULL')
        ->execute([$amt, $da, mb_substr(trim((string)($_POST['disc_by'] ?? '')), 0, 100), mb_substr(trim((string)($_POST['disc_note'] ?? '')), 0, 255), $mk, $cid, $id]);
      audit('charge_edit', 'family', (string)$id, "חיוב $cid: $amt, הנחה $da, סימון $mk");
      redirect('family.php?id=' . $id . '&open=chg&m=' . rawurlencode('החיוב עודכן.'));
    }
  } elseif ($act === 'add_student') {
    $sid = (int)($_POST['student_id'] ?? 0);
    $pdo->prepare('UPDATE students SET family_id = ? WHERE id = ? AND family_id IS NULL')->execute([$id, $sid]);
    audit('family_student_add', 'family', (string)$id, 'תלמידה ' . $sid);
    redirect('family.php?id=' . $id . '&open=stu&m=' . rawurlencode('התלמידה צורפה.'));
  } elseif ($act === 'remove_student') {
    $sid = (int)($_POST['student_id'] ?? 0);
    $pdo->prepare('UPDATE students SET family_id = NULL WHERE id = ? AND family_id = ?')->execute([$sid, $id]);
    audit('family_student_remove', 'family', (string)$id, 'תלמידה ' . $sid);
    redirect('family.php?id=' . $id . '&open=stu&m=' . rawurlencode('התלמידה הוסרה מהתיק.'));
  }
  $fam = array_merge($fam, array_intersect_key($_POST, $fam));
}
if (!empty($_GET['m'])) $msg = mb_substr((string)$_GET['m'], 0, 200);
if (!empty($_GET['e'])) $err = mb_substr((string)$_GET['e'], 0, 500);
$sum = collection_data([$id])[$id];
if (isset($_GET['pv']) && $fam && $_SERVER['REQUEST_METHOD'] === 'GET') {   // תצוגה מקדימה: איך התשלום יתחלק בין החודשים
  header('Content-Type: application/json; charset=utf-8'); $a = money_in((string)($_GET['amount'] ?? '')); $fm = (int)($_GET['for_month'] ?? 0);
  if ($a === null || $a <= 0) { echo json_encode(['rows' => [], 'credit' => 0]); exit; }
  $aft = collection_data([$id], null, [$id => [[$a, ($fm >= 1 && $fm <= 12) ? $fm : 0]]])[$id]; $rows = [];
  foreach (SCHOOL_MONTHS as $m => $nm) { if ($m > $sum['months']) break; $dlt = round((float)$aft['cov'][$m] - (float)$sum['cov'][$m], 2); if ($dlt > 0.004) $rows[] = ['m' => $nm, 'a' => $dlt, 'f' => $m > $sum['cur']]; }
  echo json_encode(['rows' => $rows, 'credit' => $aft['credit'], 'surplus' => round($a - array_sum(array_column($rows, 'a')), 2)], JSON_UNESCAPED_UNICODE); exit;
}
$studs = family_students($id);
$payers = db()->query('SELECT id, name FROM payers ORDER BY name')->fetchAll();
$divorced = (bool)array_filter($studs, fn($x) => ($x['marital'] ?? '') === 'divorced');
$ch = db()->prepare('SELECT c.*, (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL) paid FROM charges c WHERE c.family_id = ? ORDER BY c.id'); $ch->execute([$id]); $charges = $ch->fetchAll();
$py = db()->prepare('SELECT p.*, u.display_name who FROM payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.family_id = ? ORDER BY p.paid_on DESC, p.id DESC'); $py->execute([$id]); $pays = $py->fetchAll();
$free = db()->query('SELECT id, name, grade FROM students WHERE family_id IS NULL AND status = \'active\' AND archived_at IS NULL ORDER BY name')->fetchAll();
$chargeNames = array_column($charges, 'label', 'id');
if ($err && $_SERVER['REQUEST_METHOD'] === 'POST') $_GET['open'] = ['add_payment' => 'pay', 'void_payment' => 'pay', 'save' => 'set', 'add_charge' => 'chg', 'void_charge' => 'chg', 'add_student' => 'stu', 'remove_student' => 'stu', 'charge_edit' => 'chg'][$_POST['act'] ?? ''] ?? 'notes';
page_head($fam['name']);
?>
<div class="sub"><div class="in"><a href="collection.php">‹ חזרה לגבייה</a><b class="nm"><?= e($fam['name']) ?></b><span class="mu">תיק גבייה</span><span class="gap"></span><span class="mu"><?= $sum['balance'] < -0.004 ? 'זכות' : 'חוב' ?></span><b class="bal <?= $sum['balance'] > 0.004 ? 'er' : 'ok' ?>"><?= e(money(abs($sum['balance']))) ?></b><a class="btn" href="family.php?id=<?= $id ?>&amp;open=pay#s-pay">רישום תשלום</a></div></div>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="hero">
  <div class="stats">
    <div><span class="mu">שכר לימוד לשנה</span><b><?= e(money($sum['tuition'])) ?></b></div>
    <div><span class="mu">חיובים נוספים</span><b><?= e(money($sum['charges'])) ?></b></div>
    <div><span class="mu">שולם</span><b class="ok"><?= e(money($sum['paid'])) ?></b></div>
    <div><span class="mu"><?= $sum['balance'] < -0.004 ? 'יתרת זכות' : 'חוב נוכחי (עד ' . e(SCHOOL_MONTHS[$sum['cur']]) . ')' ?></span><b class="<?= $sum['balance'] > 0.004 ? 'er' : 'ok' ?>"><?= e(money(abs($sum['balance']))) ?></b></div>
  </div>
  <div class="mbs"><?= month_cells($sum, true) ?></div>
  <?php if (in_array($u['role'], ['admin', 'finance'], true) || can_edit()): ?>
  <form method="post" class="bar mset"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="month_set">
    <label>חודש<select name="month"><?php foreach (SCHOOL_MONTHS as $k => $v) if ($k <= (int)$fam['months']) echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
    <label>פעולה<select name="op"><option value="paid">סימון שולם (רושם תשלום לחוסר)</option><option value="w">בטיפול</option><option value="r">החזר</option><option value="n">לא רלוונטי (ללא חיוב)</option><option value="clear">ניקוי סימון</option></select></label>
    <button class="sec">עדכון חודש</button>
    <?php if (pay_url() !== ''): ?><a class="btn" href="<?= e(pay_url()) ?>" target="_blank" rel="noopener">💳 תשלום מאובטח</a><?php endif; ?>
  </form>
  <p class="mu">…=בטיפול · ↺=החזר · –=לא רלוונטי. "בטיפול" ו"החזר" לא נספרים כאיחור; "לא רלוונטי" מוריד את החודש מהחיוב.</p>
  <?php endif; ?>
</div>
<?php if ($fam['method'] === 'hok_card'): $cp = family_contacts([$id])[$id] ?? []; $p1 = ($cp['m'][2] ?? '') ?: ($cp['f'][2] ?? ''); ?>
<div class="card"><h2>הסדרת אשראי</h2>
  <p class="mu">העדכון נעשה באתר המאובטח של חברת הסליקה. אין להקליד או לשמור פרטי אשראי במערכת.</p>
  <div class="bar"><?php if (pay_url() !== ''): ?><a class="btn" href="<?= e(pay_url()) ?>" target="_blank" rel="noopener">💳 עדכון אשראי בקישור המאובטח</a>
    <?php if ($p1 !== ''): ?><a class="btn sec" href="<?= e(wa_url($p1, 'שלום, לעדכון פרטי האשראי לתשלום שכר הלימוד של ' . implode(', ', array_column($studs ?? family_students($id), 'name')) . ' נא להיכנס לקישור המאובטח: ' . pay_url())) ?>" target="_blank" rel="noopener">שליחת הקישור להורה בוואטסאפ</a><?php endif; endif; ?>
    <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="card_ok"><label class="chk"><input type="checkbox" name="ok" value="1"<?= $fam['card_ok'] ? ' checked' : '' ?>> האשראי הוסדר<?= $fam['card_ok'] ? ' (' . e(date_out($fam['card_ok'])) . ')' : '' ?></label> <button class="sec">עדכון</button></form></div>
</div>
<?php endif; ?>
<?php notes_card(array_map(fn($x) => ['id' => $x['id'], 'name' => $x['name']], $studs), 'family.php?id=' . $id); ?>
<?php sec_open('pay', 'רישום תשלום והיסטוריה', count($pays) . ' תשלומים · שולם ' . money($sum['paid']), '₪'); ?>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="add_payment">
    <label>סכום (₪)<input name="amount" inputmode="decimal" required></label>
    <label>תאריך<input name="paid_on" value="<?= e(date('d/m/Y')) ?>" required></label>
    <label>אמצעי<select name="method"><?php foreach (PAY_METHODS as $k => $v) if ($k !== 'payer') echo '<option value="' . $k . '"' . ($fam['method'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
    <label>עבור<select name="target"><option value="tuition">שכר לימוד</option><?php foreach ($charges as $c) if (!$c['voided_at']) echo '<option value="c' . (int)$c['id'] . '">' . e($c['label']) . '</option>'; ?></select></label>
    <label>חודש (לשכר לימוד)<select name="for_month"><option value="0">אוטומטי – לפי הסדר</option><?php foreach (SCHOOL_MONTHS as $k => $v) if ($k <= (int)$fam['months']) echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
    <label>אסמכתא / מס׳ צ׳ק<input name="ref" maxlength="60"></label>
    <label>הערה<input name="note" maxlength="255"></label>
    <button>רישום</button>
  </form>
  <p class="mu" id="pvbox" data-url="family.php?id=<?= $id ?>&pv=1" aria-live="polite"></p>
  <p class="mu">לא מקלידים מספרי כרטיס אשראי. החיוב עצמו מתבצע בנדרים פלוס, ופה נרשם רק שהתשלום התקבל.</p>
  <div class="sc"><table><tr><th>תאריך</th><th>סכום</th><th>אמצעי</th><th>עבור</th><th>אסמכתא</th><th>הערה</th><th>נרשם ע״י</th><th></th></tr>
  <?php foreach ($pays as $p): $v = $p['voided_at'] !== null; ?>
    <tr class="<?= $v ? 'void' : '' ?>"><td><?= e(date_out($p['paid_on'])) ?></td><td><?= e(money($p['amount'])) ?></td><td><?= e(method_label($p['method'])) ?></td>
    <td><?= $p['charge_id'] ? e($chargeNames[$p['charge_id']] ?? 'חיוב') : 'שכר לימוד' . ($p['for_month'] ? ' – ' . e(SCHOOL_MONTHS[(int)$p['for_month']] ?? '') : '') ?></td>
    <td><?= e($p['ref']) ?></td><td><?= e($p['note']) ?></td><td><?= e($p['who'] ?? '') ?></td>
    <td><?php if ($v): ?><span class="mu">בוטל</span><?php else: ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="void_payment"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><button class="sec">ביטול</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$pays) echo '<tr><td colspan="8" class="mu">עדיין לא נרשמו תשלומים.</td></tr>'; ?>
  </table></div>
<?php sec_close(); ?>
<?php sec_open('set', 'הגדרות גבייה', money($fam['monthly']) . ' לחודש · ' . (int)$fam['months'] . ' חודשים · ' . payer_label($fam), '⚙'); ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="save">
  <div class="fg">
    <label>שם משפחה<input name="name" maxlength="120" value="<?= e($fam['name']) ?>" required></label>
    <label>תעריף חודשי למשפחה (₪)<input name="monthly" inputmode="decimal" value="<?= e((float)$fam['monthly'] == 0 ? '0' : rtrim(rtrim((string)$fam['monthly'], '0'), '.')) ?>"></label>
    <label>מספר חודשים<select name="months"><option value="10"<?= (int)$fam['months'] === 10 ? ' selected' : '' ?>>10 (ספטמבר–יוני)</option><option value="12"<?= (int)$fam['months'] === 12 ? ' selected' : '' ?>>12 (ספטמבר–אוגוסט)</option></select></label>
    <label>צורת תשלום<select name="method"><option value="">לא נקבע</option><?php foreach (PAY_METHODS as $k => $v) echo '<option value="' . $k . '"' . ($fam['method'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
    <label>יום גבייה בחודש<input name="collect_day" inputmode="numeric" maxlength="2" value="<?= e((string)($fam['collect_day'] ?? '')) ?>"></label>
    <label>מי משלם<select name="payer"><?php
      $cur = $fam['payer_kind'] === 'org' && $fam['payer_id'] ? 'p:' . (int)$fam['payer_id'] : $fam['payer_kind'];
      if ($divorced) { echo '<option value="parents"' . ($cur === 'parents' ? ' selected' : '') . '>לא נבחר (הורים גרושים)</option>';
        foreach (['father', 'mother'] as $k) echo '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . e(PAYER_KINDS[$k]) . '</option>'; }
      else echo '<option value="parents"' . ($cur === 'parents' || $cur === 'father' || $cur === 'mother' ? ' selected' : '') . '>הורים</option>';
      foreach ($payers as $p) echo '<option value="p:' . (int)$p['id'] . '"' . ($cur === 'p:' . (int)$p['id'] ? ' selected' : '') . '>' . e($p['name']) . '</option>'; ?></select></label>
    <label>הנחה / סיבה<input name="discount_note" maxlength="255" value="<?= e($fam['discount_note']) ?>" placeholder="למשל: הנחת אחיות"></label>
  </div>
  <button>שמירה</button> <a class="btn sec" href="collection.php">חזרה לגבייה</a>
</form>
<?php sec_close(); ?>
<?php sec_open('stu', 'תלמידות בתיק', count($studs) . ' תלמידות', '☺'); ?>
  <div class="sc"><table><tr><th>שם</th><th>כיתה</th><th>אב</th><th>אם</th><th></th></tr>
  <?php foreach ($studs as $s): ?>
    <tr><td><a href="student.php?id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a></td><td><?= e($s['grade']) ?></td>
    <td><?= e(trim($s['f_name'] . ' ' . fmt_phone((string)$s['f_phone']))) ?></td><td><?= e(trim($s['m_name'] . ' ' . fmt_phone((string)$s['m_phone']))) ?></td>
    <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="remove_student"><input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>"><button class="sec">הסרה מהתיק</button></form></td></tr>
  <?php endforeach; if (!$studs) echo '<tr><td colspan="5" class="mu">אין תלמידות בתיק.</td></tr>'; ?>
  </table></div>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="add_student">
    <label>צירוף תלמידה לתיק<select name="student_id" required><option value="">בחרי…</option><?php foreach ($free as $s) echo '<option value="' . (int)$s['id'] . '">' . e($s['name'] . ' (' . $s['grade'] . ')') . '</option>'; ?></select></label>
    <button>צירוף</button></form>
<?php sec_close(); ?>
<?php sec_open('chg', 'חיובים נוספים', 'ספרים, סיור, חוגים · ' . count($charges) . ' חיובים', '＋'); ?>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="add_charge">
    <label>סוג<select name="kind"><?php foreach (CHARGE_KINDS as $k => $v) echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
    <label>תיאור<input name="label" maxlength="100" placeholder="אופציונלי"></label>
    <label>סכום (₪)<input name="amount" inputmode="decimal" required></label>
    <button>הוספת חיוב</button></form>
  <div class="sc"><table><tr><th>חיוב</th><th>סכום</th><th>הנחה</th><th>שולם</th><th>סימון</th><th></th></tr>
  <?php foreach ($charges as $c): $v = $c['voided_at'] !== null; $mkl = ['' => '', 'w' => 'בטיפול', 'r' => 'החזר', 'n' => 'לא רלוונטי'][$c['mark']] ?? ''; ?>
    <tr class="<?= $v ? 'void' : '' ?>"><td><?= e($c['label']) ?></td><td><?= e(money($c['amount'])) ?></td><td><?= (float)$c['disc_amt'] > 0 ? e('−' . money($c['disc_amt'])) . ($c['disc_by'] !== '' || $c['disc_note'] !== '' ? ' <span class="mu">' . e(trim($c['disc_by'] . ' ' . $c['disc_note'])) . '</span>' : '') : '' ?></td><td><?= e(money($c['paid'])) ?></td><td><?= e($mkl) ?></td>
    <td><?php if ($v): ?><span class="mu">בוטל</span><?php else: ?>
      <details class="ced"><summary class="btn sec">עריכה</summary>
      <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="charge_edit"><input type="hidden" name="cid" value="<?= (int)$c['id'] ?>">
        <label>סכום (₪)<input name="amount" inputmode="decimal" value="<?= e(rtrim(rtrim((string)$c['amount'], '0'), '.')) ?>" required></label>
        <label>הנחה (₪)<input name="disc_amt" inputmode="decimal" value="<?= (float)$c['disc_amt'] > 0 ? e(rtrim(rtrim((string)$c['disc_amt'], '0'), '.')) : '' ?>"></label>
        <label>מי אישרה<input name="disc_by" maxlength="100" value="<?= e($c['disc_by']) ?>"></label>
        <label>הערה<input name="disc_note" maxlength="255" value="<?= e($c['disc_note']) ?>"></label>
        <label>סימון<select name="mark"><?php foreach (['' => 'ללא', 'w' => 'בטיפול', 'r' => 'החזר', 'n' => 'לא רלוונטי'] as $k => $t) echo '<option value="' . $k . '"' . ($c['mark'] === $k ? ' selected' : '') . '>' . e($t) . '</option>'; ?></select></label>
        <button>שמירה</button></form></details>
      <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="void_charge"><input type="hidden" name="cid" value="<?= (int)$c['id'] ?>"><button class="dg" data-confirm="לבטל את החיוב?">ביטול חיוב</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$charges) echo '<tr><td colspan="6" class="mu">אין חיובים נוספים.</td></tr>'; ?>
  </table></div>
<?php sec_close(); ?>
<?php page_foot();
