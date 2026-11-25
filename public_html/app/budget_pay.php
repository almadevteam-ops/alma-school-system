<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']); $by = (string)($me['name'] ?: $me['username']);
$self = 'budget_pay.php';
if (!bpay_ready()) { page_head('תקציב – לתשלום'); echo '<h1>תקציב – לתשלום</h1><p class="aptn">כדי להפעיל את החלונית יש להריץ "עדכון מסד נתונים" (תחזוקה).</p>'; page_foot(); exit; }
$cats = budget_cats(); $pdo = db();
$flash = function (string $m, bool $ok = true, string $to = '') use ($self) { $_SESSION['pmsg'] = [$ok, $m]; redirect($to ?: $self); };
$json = function (array $a, int $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$digits = fn(string $s, int $max) => substr(preg_replace('/\D/', '', $s), 0, $max);
$read = function () use ($digits, $cats): array {
  $d = budget_date((string)($_POST['inv_date'] ?? '')); $du = budget_date((string)($_POST['due_date'] ?? '')); $t = budget_amount((string)($_POST['total'] ?? '')); $c = (string)($_POST['category'] ?? '');
  return ['supplier' => mb_substr(trim((string)($_POST['supplier'] ?? '')), 0, 150), 'biz_id' => $digits((string)($_POST['biz_id'] ?? ''), 20), 'bank' => $digits((string)($_POST['bank'] ?? ''), 3), 'branch' => $digits((string)($_POST['branch'] ?? ''), 4), 'account' => $digits((string)($_POST['account'] ?? ''), 12),
    'ref' => mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 60), 'inv_date' => $d, 'due_date' => $du, 'descr' => mb_substr(trim((string)($_POST['descr'] ?? '')), 0, 300), 'category' => in_array($c, $cats, true) ? $c : '', 'total' => $t === null ? 0.0 : abs($t)];
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? '');
  if ($act === 'suggest') {   // השלמה אוטומטית מזיהוי חשבונית: קטגוריה ופרטי בנק מחשבוניות קודמות של אותו ספק
    $n = trim((string)($_POST['supplier'] ?? '')); $b = $digits((string)($_POST['biz_id'] ?? ''), 20); $out = ['category' => '', 'bank' => '', 'branch' => '', 'account' => '', 'supplier' => ''];
    $q = $pdo->prepare("SELECT * FROM budget_payables WHERE (? <> '' AND biz_id = ?) OR (? <> '' AND supplier = ?) ORDER BY id DESC LIMIT 1"); $q->execute([$b, $b, $n, $n]); $r = $q->fetch();
    if ($r) { foreach (['category', 'bank', 'branch', 'account', 'supplier'] as $k) $out[$k] = (string)$r[$k]; }
    if ($out['category'] === '' && $n !== '') $out['category'] = budget_auto($n, '') ?: budget_history_cat($n, '');
    $json(['ok' => true] + $out);
  }
  if ($act === 'qs') {   // שמירה אוטומטית: סימון למס"ב / סכום לתשלום
    $id = (int)($_POST['id'] ?? 0); $f = (string)($_POST['f'] ?? ''); $v = (string)($_POST['v'] ?? '');
    $q = $pdo->prepare("SELECT * FROM budget_payables WHERE id = ? AND status = 'open'"); $q->execute([$id]); $r = $q->fetch(); if (!$r || !in_array($f, ['masav', 'pay_now'], true)) $json(['ok' => false, 'msg' => 'לא נמצא'], 422);
    $rem = round((float)$r['total'] - (float)$r['paid_amount'], 2);
    if ($f === 'masav') { $pdo->prepare('UPDATE budget_payables SET masav = ? WHERE id = ?')->execute([$v === '1' ? 1 : 0, $id]); }
    else { $a = budget_amount($v); if ($a === null || $a <= 0 || $a > $rem + 0.004) $json(['ok' => false, 'msg' => 'הסכום חייב להיות בין 0 ל-' . budget_money($rem)], 422); $pdo->prepare('UPDATE budget_payables SET pay_now = ? WHERE id = ?')->execute([min($a, $rem), $id]); }
    $json(['ok' => true]);
  }
  if ($act === 'add' || $act === 'update') {
    $d = $read(); $id = (int)($_POST['id'] ?? 0); $to = $act === 'update' ? $self . '?edit=' . $id : $self;
    if ($d['supplier'] === '' || $d['total'] <= 0) $flash('יש למלא שם ספק וסכום.', false, $to . '#pform');
    $upl = !empty($_FILES['file']['name']) && (int)($_FILES['file']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;
    if ($act === 'add') {
      $pid = bpay_add($d, $by); $msg = 'החשבונית נוספה לרשימת התשלומים.';
    } else {
      $q = $pdo->prepare("SELECT paid_amount FROM budget_payables WHERE id = ? AND status = 'open'"); $q->execute([$id]); $r = $q->fetch(); if (!$r) $flash('החשבונית לא נמצאה.', false);
      if ($d['total'] < (float)$r['paid_amount']) $flash('הסכום קטן ממה ששולם עד כה.', false, $to);
      $pdo->prepare('UPDATE budget_payables SET supplier=?, biz_id=?, bank=?, branch=?, account=?, ref=?, inv_date=?, due_date=?, descr=?, category=?, total=?, pay_now=LEAST(COALESCE(pay_now, ?), ? - paid_amount) WHERE id=?')->execute([$d['supplier'], $d['biz_id'], $d['bank'], $d['branch'], $d['account'], $d['ref'], $d['inv_date'] ?: null, $d['due_date'] ?: null, $d['descr'], $d['category'], $d['total'], $d['total'], $d['total'], $id]);
      $pid = $id; $msg = 'החשבונית עודכנה.';
    }
    if ($upl) { [$did, $er] = bdoc_save($_FILES['file'], 'inv', null, $pid, $by); if (!$did) $msg .= ' (הקובץ לא נשמר: ' . $er . ')'; }
    audit('budget_pay_' . $act, 'budget', (string)$pid, $d['supplier'] . ' ' . $d['total']);
    if ($act === 'add' && ($_POST['state'] ?? '') === 'paid') { [$m2, $moved] = bpay_pay($pid, $d['total'], $by); $msg = $moved ? 'הוצאה נרשמה כשולמה והועברה לרשימת ההוצאות (תנועות).' : $m2; }
    $flash($msg);
  }
  if ($act === 'pay' || $act === 'payfull') {
    $pid = (int)($_POST['id'] ?? 0); $q = $pdo->prepare('SELECT total, paid_amount FROM budget_payables WHERE id = ?'); $q->execute([$pid]); $r = $q->fetch();
    $amt = $act === 'payfull' ? (float)($r['total'] ?? 0) - (float)($r['paid_amount'] ?? 0) : (float)(budget_amount((string)($_POST['amount'] ?? '')) ?? 0);
    [$m2, $moved] = bpay_pay($pid, $amt, $by); audit('budget_pay_paid', 'budget', (string)$pid, $m2); $flash($m2, !str_starts_with($m2, 'יש ') && !str_contains($m2, 'לא נמצאה'));
  }
  if ($act === 'del') {
    $pid = (int)($_POST['id'] ?? 0); $ds = $pdo->prepare('SELECT id FROM budget_docs WHERE payable_id = ? AND tx_id IS NULL'); $ds->execute([$pid]); foreach ($ds->fetchAll(PDO::FETCH_COLUMN) as $did) bdoc_delete((int)$did);
    $pdo->prepare("DELETE FROM budget_payables WHERE id = ? AND status = 'open'")->execute([$pid]); audit('budget_pay_del', 'budget', (string)$pid, ''); $flash('החשבונית נמחקה.');
  }
  if ($act === 'doc_del') { bdoc_delete((int)($_POST['doc'] ?? 0)); $flash('הקובץ הוסר.', true, $self . '?edit=' . (int)($_POST['id'] ?? 0) . '#pform'); }
  if ($act === 'masav_cfg') {
    setting_set('masav_inst', $digits((string)($_POST['inst'] ?? ''), 8), $by); setting_set('masav_sender', $digits((string)($_POST['sender'] ?? ''), 5), $by); setting_set('masav_name', mb_substr(trim((string)($_POST['mname'] ?? '')), 0, 30), $by); setting_set('masav_vis', isset($_POST['vis']) ? '1' : '0', $by);
    audit('masav_cfg', 'budget', '', ''); $flash('הגדרות מס"ב נשמרו.', true, $self . '#masav');
  }
  if ($act === 'masav_make') {
    $c = masav_cfg(); if (!masav_cfg_ok($c)) $flash('יש להגדיר קודם את פרטי המוסד למס"ב (מספר מוסד, מספר מוסד שולח ושם).', false, $self . '#masav');
    $cd = budget_date((string)($_POST['credit_date'] ?? '')); if ($cd === '' || $cd < date('Y-m-d')) $flash('יש לבחור תאריך זיכוי של היום או מאוחר יותר.', false, $self . '#masav');
    $rs = $pdo->query("SELECT * FROM budget_payables WHERE status = 'open' AND masav = 1 ORDER BY id")->fetchAll(); $lines = [];
    foreach ($rs as $r) { $rem = round((float)$r['total'] - (float)$r['paid_amount'], 2); $amt = min((float)($r['pay_now'] ?? $rem), $rem); $lines[] = ['pid' => (int)$r['id'], 'supplier' => $r['supplier'], 'biz_id' => $r['biz_id'], 'bank' => $r['bank'], 'branch' => $r['branch'], 'account' => $r['account'], 'ref' => $r['ref'] !== '' ? $r['ref'] : (string)$r['id'], 'amount' => $amt]; }
    if (!$lines) $flash('לא סומנו חשבוניות לתשלום במס"ב.', false, $self . '#masav');
    $er = masav_lines_check($lines); if ($er) $flash('לא ניתן ליצור את הקובץ: ' . implode(' · ', array_slice($er, 0, 4)), false, $self . '#masav');
    $tot = array_sum(array_column($lines, 'amount'));
    $pdo->prepare('INSERT INTO budget_masav (credit_date, cnt, total, payload, created_by, created_at) VALUES (?,?,?,?,?,?)')->execute([$cd, count($lines), $tot, json_encode(['cfg' => json_encode($c), 'rows' => $lines], JSON_UNESCAPED_UNICODE), mb_substr($by, 0, 100), now()]); $mid = (int)$pdo->lastInsertId();
    $pdo->exec("UPDATE budget_payables SET masav = 0 WHERE id IN (" . implode(',', array_column($lines, 'pid')) . ')'); audit('masav_make', 'budget', (string)$mid, count($lines) . ' תשלומים, ' . $tot);
    $flash('נוצר קובץ מס"ב עם ' . count($lines) . ' תשלומים בסך ' . budget_money($tot) . '. אפשר להוריד אותו מההיסטוריה למטה.', true, $self . '#masav');
  }
  if ($act === 'masav_apply') {
    $q = $pdo->prepare('SELECT * FROM budget_masav WHERE id = ? AND applied = 0'); $q->execute([(int)($_POST['id'] ?? 0)]); $m = $q->fetch(); if (!$m) $flash('הקובץ לא נמצא או שהתשלומים כבר נרשמו.', false, $self . '#masav');
    $n = 0; $mv = 0; foreach (json_decode((string)$m['payload'], true)['rows'] ?? [] as $l) { [$t, $moved] = bpay_pay((int)$l['pid'], (float)$l['amount'], $by); if (!str_starts_with($t, 'החשבונית לא')) { $n++; if ($moved) $mv++; } }
    $pdo->prepare('UPDATE budget_masav SET applied = 1 WHERE id = ?')->execute([$m['id']]); audit('masav_apply', 'budget', (string)$m['id'], "$n תשלומים"); $flash("נרשמו $n תשלומים כשולמו" . ($mv ? ", מהם $mv שולמו במלואם והועברו להוצאות" : '') . '.', true, $self . '#masav');
  }
  if ($act === 'masav_del') { $pdo->prepare('DELETE FROM budget_masav WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]); audit('masav_del', 'budget', (string)(int)($_POST['id'] ?? 0), ''); $flash('הקובץ נמחק מההיסטוריה.', true, $self . '#masav'); }
  redirect($self);
}
$msg = $_SESSION['pmsg'] ?? null; unset($_SESSION['pmsg']);
$rows = $pdo->query("SELECT * FROM budget_payables WHERE status = 'open' ORDER BY COALESCE(due_date, inv_date, DATE(created_at)), id")->fetchAll();
$editId = (int)($_GET['edit'] ?? 0); $ed = null; foreach ($rows as $r) if ((int)$r['id'] === $editId) $ed = $r;
$pdocs = []; $ids = array_map('intval', array_column($rows, 'id'));
if ($ids) foreach ($pdo->query('SELECT id, payable_id, orig_name FROM budget_docs WHERE tx_id IS NULL AND payable_id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll() as $d) $pdocs[(int)$d['payable_id']][] = $d;
$mc = masav_cfg(); $mhist = $pdo->query('SELECT * FROM budget_masav ORDER BY id DESC LIMIT 50')->fetchAll();
$remSum = 0.0; $selSum = 0.0; $selN = 0; foreach ($rows as $r) { $rem = round((float)$r['total'] - (float)$r['paid_amount'], 2); $remSum += $rem; if ($r['masav']) { $selN++; $selSum += min((float)($r['pay_now'] ?? $rem), $rem); } }
$v = fn(string $k, $d = '') => $ed ? $ed[$k] : $d; $opt = fn(string $sel) => '<option value="">— לפי שיוך אוטומטי —</option>' . implode('', array_map(fn($x) => '<option' . ($x === $sel ? ' selected' : '') . '>' . e($x) . '</option>', $cats));
page_head('תקציב – לתשלום');
?>
<h1>תקציב – לתשלום <span class="mu">(<?= count($rows) ?>)</span></h1>
<?php if ($msg) echo '<p class="' . ($msg[0] ? 'ok' : 'er') . '">' . e($msg[1]) . '</p>'; ?>
<p class="mu">חשבוניות והוצאות שעדיין לא שולמו. כשמסמנים שהחשבונית שולמה במלואה היא עוברת אוטומטית לרשימת ההוצאות (תקציב › תנועות). תשלום חלקי נרשם כ"שולם חלקית" והחשבונית נשארת כאן עם הסכום שנותר. אפשר לסמן חשבוניות לתשלום ולהפיק קובץ מס"ב זיכויים.</p>

<details class="card" id="pform"<?= $ed || isset($_GET['add']) ? ' open' : '' ?>><summary><b><?= $ed ? 'עריכת חשבונית' : 'הוספת חשבונית / הוצאה לתשלום' ?></b></summary>
  <form method="post" enctype="multipart/form-data" class="bar docf" action="<?= $self ?>" data-inv><?= csrf_field() ?><input type="hidden" name="act" value="<?= $ed ? 'update' : 'add' ?>"><?php if ($ed) echo '<input type="hidden" name="id" value="' . (int)$ed['id'] . '">'; ?>
    <label>קובץ חשבונית (PDF או תמונה) – המערכת תנסה לזהות את הפרטים לבד<input type="file" name="file" id="inv-file" accept="application/pdf,image/jpeg,image/png,image/webp"></label>
    <span id="inv-msg" class="mu" role="status" aria-live="polite"></span>
    <label>שם מלא של הספק<input name="supplier" required maxlength="150" value="<?= e($v('supplier')) ?>"></label>
    <label>מספר עוסק / ח"פ / ת"ז<input name="biz_id" maxlength="20" inputmode="numeric" dir="ltr" value="<?= e($v('biz_id')) ?>"></label>
    <label>מספר חשבונית<input name="ref" maxlength="60" dir="ltr" value="<?= e($v('ref')) ?>"></label>
    <label>תאריך חשבונית<input type="date" name="inv_date" value="<?= e((string)$v('inv_date')) ?>"></label>
    <label>לתשלום עד<input type="date" name="due_date" value="<?= e((string)$v('due_date')) ?>"></label>
    <label>סכום כולל ₪<input name="total" required inputmode="decimal" value="<?= $ed ? e((string)$ed['total']) : '' ?>"></label>
    <label>קטגוריה<select name="category"><?= $opt((string)$v('category')) ?></select></label>
    <label>תיאור<input name="descr" maxlength="300" value="<?= e($v('descr')) ?>"></label>
    <fieldset class="bkd"><legend>פרטי בנק להעברה</legend>
      <label>בנק (קוד)<input name="bank" maxlength="3" inputmode="numeric" dir="ltr" class="pay-in" value="<?= e($v('bank')) ?>"></label>
      <label>סניף<input name="branch" maxlength="4" inputmode="numeric" dir="ltr" class="pay-in" value="<?= e($v('branch')) ?>"></label>
      <label>מספר חשבון<input name="account" maxlength="12" inputmode="numeric" dir="ltr" value="<?= e($v('account')) ?>"></label></fieldset>
    <?php if ($ed): foreach ($pdocs[(int)$ed['id']] ?? [] as $d): ?><span class="bdf"><a href="budget_file.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">📎 <?= e($d['orig_name']) ?></a></span><?php endforeach; endif; ?>
    <?php if (!$ed): ?><fieldset class="bkd"><legend>האם החשבונית שולמה?</legend>
      <label class="chk"><input type="radio" name="state" value="unpaid" checked> לא שולמה – תישאר ב"לתשלום"</label>
      <label class="chk"><input type="radio" name="state" value="paid"> שולמה – תעבור ישר לרשימת ההוצאות</label></fieldset><?php endif; ?>
    <button><?= $ed ? 'שמירת שינויים' : 'הוספה' ?></button><?php if ($ed) echo '<a class="btn sec" href="' . $self . '">ביטול</a>'; ?>
  </form>
</details>

<?php if ($rows): ?>
<div class="pay-sum"><span>סה"כ לתשלום: <b><?= e(budget_money($remSum)) ?></b></span><span>סומנו למס"ב: <b><?= $selN ?></b> · <b><?= e(budget_money($selSum)) ?></b></span></div>
<form class="payq card sc"><?= csrf_field() ?><span class="asv mu" role="status" aria-live="polite">סימון למס"ב וסכום לתשלום נשמרים אוטומטית.</span>
<table><tr><th>ספק</th><th>חשבונית</th><th>לתשלום עד</th><th>קטגוריה</th><th>סה"כ</th><th>שולם</th><th>נותר</th><th>סכום לתשלום עכשיו</th><th>במס"ב</th><th>פרטי בנק</th><th></th></tr>
<?php $today = date('Y-m-d'); foreach ($rows as $r): $id = (int)$r['id']; $rem = round((float)$r['total'] - (float)$r['paid_amount'], 2); $bk = $r['bank'] !== '' && $r['account'] !== ''; ?>
<tr<?= $r['due_date'] && $r['due_date'] < $today ? ' class="unm"' : '' ?>>
  <td><b><?= e($r['supplier']) ?></b><?= $r['biz_id'] !== '' ? '<br><span class="mu">עוסק ' . e($r['biz_id']) . '</span>' : '' ?><?php if ($r['descr'] !== ''): ?><br><span class="mu"><?= e($r['descr']) ?></span><?php endif; ?></td>
  <td><?= e($r['ref']) ?><?= $r['inv_date'] ? '<br><span class="mu">' . e(date('d/m/Y', strtotime($r['inv_date']))) . '</span>' : '' ?><?php foreach ($pdocs[$id] ?? [] as $d): ?><br><a href="budget_file.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">📎 קובץ</a><?php endforeach; ?></td>
  <td class="n"><?= $r['due_date'] ? e(date('d/m/Y', strtotime($r['due_date']))) : '—' ?></td><td><?= $r['category'] !== '' ? e($r['category']) : '<span class="mu">— לא משויך —</span>' ?></td>
  <td class="n"><?= e(budget_money($r['total'])) ?></td><td class="n"><?= (float)$r['paid_amount'] > 0 ? e(budget_money($r['paid_amount'])) . '<br><span class="mu">שולם חלקית</span>' : '—' ?></td><td class="n"><b><?= e(budget_money($rem)) ?></b></td>
  <td><input class="pay-in" name="pn[<?= $id ?>]" value="<?= e(number_format((float)($r['pay_now'] ?? $rem), 2, '.', '')) ?>" inputmode="decimal" dir="ltr" aria-label="סכום לתשלום"></td>
  <td><input type="checkbox" name="mv[<?= $id ?>]" value="1"<?= $r['masav'] ? ' checked' : '' ?> aria-label="לתשלום במס&quot;ב"></td>
  <td><?= $bk ? e($r['bank'] . '-' . $r['branch'] . '-' . $r['account']) : '<span class="pay-miss">חסרים</span>' ?></td>
  <td class="n"><div class="inl"><button type="button" class="payb" data-id="<?= $id ?>" data-act="pay">רישום תשלום</button> <button type="button" class="sec payb" data-id="<?= $id ?>" data-act="payfull" data-confirm="לרשום את החשבונית כשולמה במלואה ולהעביר להוצאות?">שולם במלואו</button>
    <a class="btn sec" href="<?= $self ?>?edit=<?= $id ?>#pform">עריכה</a> <button type="button" class="dg payb" data-id="<?= $id ?>" data-act="del" data-confirm="למחוק את החשבונית?">מחיקה</button></div></td></tr>
<?php endforeach; ?></table></form>
<form method="post" id="payact" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value=""><input type="hidden" name="id" value=""><input type="hidden" name="amount" value=""></form>
<?php else: ?><div class="card"><p class="emp">אין חשבוניות שממתינות לתשלום.</p></div><?php endif; ?>

<div class="card" id="masav"><h2>קובץ מס"ב (זיכויים)</h2>
  <?php if (!masav_cfg_ok($mc)): ?><p class="aptn">כדי ליצור קובץ יש להגדיר פעם אחת את פרטי המוסד שלך במס"ב (מופיעים בהסכם עם מס"ב / הבנק).</p><?php endif; ?>
  <details<?= masav_cfg_ok($mc) ? '' : ' open' ?>><summary>פרטי המוסד למס"ב</summary>
    <form method="post" class="bar docf"><?= csrf_field() ?><input type="hidden" name="act" value="masav_cfg">
      <label>מספר מוסד / נושא (עד 8 ספרות)<input name="inst" value="<?= e($mc['inst']) ?>" inputmode="numeric" maxlength="8" dir="ltr"></label>
      <label>מספר מוסד שולח (עד 5 ספרות)<input name="sender" value="<?= e($mc['sender']) ?>" inputmode="numeric" maxlength="5" dir="ltr"></label>
      <label>שם המוסד (עד 30 תווים)<input name="mname" value="<?= e($mc['name']) ?>" maxlength="30"></label>
      <label class="chk"><input type="checkbox" name="vis" value="1"<?= $mc['vis'] ? ' checked' : '' ?>> כתיבת עברית בסדר ויזואלי (DOS) – כמקובל בקבצי מס"ב. אם אתר מס"ב מציג שמות הפוכים, יש לבטל סימון זה</label>
      <button class="sec">שמירה</button></form></details>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="masav_make">
    <span>מסומנים: <b><?= $selN ?></b> חשבוניות, סה"כ <b><?= e(budget_money($selSum)) ?></b></span>
    <label>תאריך זיכוי<input type="date" name="credit_date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 weekday')) ?>"></label>
    <button<?= $selN ? '' : ' disabled' ?>>יצירת קובץ מס"ב</button></form>
  <p class="mu">הקובץ כולל את כל החשבוניות המסומנות "במס"ב" בסכום שבעמודה "סכום לתשלום עכשיו" (אפשר חלקי). אחרי שמעלים אותו לאתר מס"ב, יש ללחוץ בהיסטוריה על "רישום כשולם" כדי שהתשלומים יירשמו: שולם במלואו עובר להוצאות, ושולם חלקית נשאר כאן עם יתרה. מומלץ להריץ את הקובץ בבדיקת הקבצים של אתר מס"ב לפני השליחה.</p>
  <h3>היסטוריית קבצים</h3>
  <?php if ($mhist): ?><div class="sc"><table><tr><th>נוצר</th><th>תאריך זיכוי</th><th>תשלומים</th><th>סה"כ</th><th>מי יצר</th><th>מצב</th><th></th></tr>
  <?php foreach ($mhist as $m): $ml = json_decode((string)$m['payload'], true)['rows'] ?? []; ?><tr><td class="n"><?= e(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td><td class="n"><?= e(date('d/m/Y', strtotime($m['credit_date']))) ?></td><td><?= (int)$m['cnt'] ?><br><span class="mu"><?= e(implode(', ', array_slice(array_column($ml, 'supplier'), 0, 3))) ?><?= count($ml) > 3 ? '…' : '' ?></span></td><td class="n"><?= e(budget_money($m['total'])) ?></td><td><?= e($m['created_by']) ?></td><td><?= $m['applied'] ? 'התשלומים נרשמו' : 'ממתין לרישום' ?></td>
    <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><a class="btn sec" href="budget_file.php?masav=<?= (int)$m['id'] ?>">הורדה</a>
      <?php if (!$m['applied']): ?><button name="act" value="masav_apply" data-confirm="לרשום את כל התשלומים בקובץ כשולמו?">רישום כשולם</button><?php endif; ?> <button name="act" value="masav_del" class="dg" data-confirm="למחוק את הקובץ מההיסטוריה?">מחיקה</button></form></td></tr><?php endforeach; ?></table></div>
  <?php else: ?><p class="emp">עוד לא נוצרו קבצים.</p><?php endif; ?>
</div>
<?php page_foot();
