<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$by = (string)($me['name'] ?: $me['username']);
$f = budget_filters(); $cats = budget_cats(); $units = budget_units();
$keep = array_filter(['y' => $f['yr'], 'q' => $f['q'], 'cat' => $f['cat'], 'unit' => $f['unit'], 'type' => $f['type'], 'doc' => $f['doc'], 'month' => $f['month'], 's' => (string)($_GET['s'] ?? ''), 'dir' => (string)($_GET['dir'] ?? ''), 'p' => (string)($_GET['p'] ?? '')], fn($v) => $v !== '' && $v !== '1');
$self = 'budget_tx.php' . ($keep ? '?' . http_build_query($keep) : '');
[$wh, $wa] = budget_where($f);
$SORT = ['d' => 'tx_date', 'n' => 'name', 'a' => 'amount', 'c' => 'category', 'u' => 'unit']; $sk = (string)($_GET['s'] ?? 'd'); if (!isset($SORT[$sk])) $sk = 'd'; $sd = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$flash = function (string $m, bool $ok = true) use ($self) { $_SESSION['bmsg'] = [$ok, $m]; redirect($self); };

// ---- ייצוא CSV (באותו פורמט של הייבוא) ----
if (($_GET['csv'] ?? '') === '1') {
  $q = db()->prepare("SELECT * FROM budget_tx $wh ORDER BY tx_date, id"); $q->execute($wa);
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="budget-' . date('Y-m-d') . '.csv"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['תאריך', 'שם', 'תיאור', 'אסמכתה', 'סכום', 'קטגוריה', 'יחידה', 'סוג', 'חשבונית', 'קבלה'], ',', '"', ''); $n = 0;
  foreach ($q as $r) { $n++; fputcsv($o, array_map('csv_safe', [date('d/m/Y', strtotime($r['tx_date'])), $r['name'], $r['descr'], $r['ref'], (string)$r['amount'], $r['category'], $r['unit'], $r['is_income'] ? 'הכנסה' : 'הוצאה', $r['has_invoice'] ? 'כן' : '', $r['has_receipt'] ? 'כן' : '']), ',', '"', ''); }
  audit('budget_export', 'budget', '', "ייצוא $n תנועות"); exit;
}

// ---- פעולות ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? ''); $pdo = db();
  if (isset($_POST['del'])) { $act = 'del'; $_POST['id'] = $_POST['del']; }
  if ($act === 'add' || $act === 'update') {
    $d = budget_date((string)($_POST['d'] ?? '')); $a = budget_amount((string)($_POST['a'] ?? '')); $n = mb_substr(trim((string)($_POST['n'] ?? '')), 0, 150);
    $e2 = mb_substr(trim((string)($_POST['e'] ?? '')), 0, 300); $r = mb_substr(trim((string)($_POST['r'] ?? '')), 0, 100);
    $c = (string)($_POST['c'] ?? ''); $u = (string)($_POST['u'] ?? ''); $inc = ($_POST['t'] ?? '') === 'in' ? 1 : 0;
    if ($d === '' || $a === null || $n === '') $flash('יש למלא תאריך תקין, שם וסכום תקין.', false);
    if ($c !== '' && !in_array($c, $cats, true)) $flash('קטגוריה לא קיימת.', false);
    if ($c === '') $c = budget_auto($n, $e2);
    $u = budget_unit_for($u, $c); $iv = isset($_POST['inv']) ? 1 : 0; $rc = isset($_POST['rec']) ? 1 : 0;
    if ($act === 'add') {
      $pdo->prepare('INSERT INTO budget_tx (tx_date,name,descr,ref,amount,is_income,category,unit,has_invoice,has_receipt,created_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$d, $n, $e2, $r, $a, $inc, $c, $u, $iv, $rc, now(), mb_substr($by, 0, 100)]);
      audit('budget_add', 'budget', (string)$pdo->lastInsertId(), ($inc ? 'הכנסה ' : 'הוצאה ') . $n . ' ' . $a); $flash('התנועה נוספה.');
    } else {
      $id = (int)($_POST['id'] ?? 0);
      $st = $pdo->prepare('UPDATE budget_tx SET tx_date=?,name=?,descr=?,ref=?,amount=?,is_income=?,category=?,unit=?,has_invoice=?,has_receipt=?,updated_at=? WHERE id=?'); $st->execute([$d, $n, $e2, $r, $a, $inc, $c, $u, $iv, $rc, now(), $id]);
      audit('budget_edit', 'budget', (string)$id, $n . ' ' . $a); $_SESSION['bmsg'] = [true, 'התנועה עודכנה.']; redirect($self);
    }
  } elseif ($act === 'del') {
    $id = (int)($_POST['id'] ?? 0); $q = $pdo->prepare('SELECT name, amount FROM budget_tx WHERE id=?'); $q->execute([$id]); $x = $q->fetch();
    if ($x) { $pdo->prepare('DELETE FROM budget_tx WHERE id=?')->execute([$id]); audit('budget_del', 'budget', (string)$id, $x['name'] . ' ' . $x['amount']); $flash('התנועה נמחקה.'); }
    $flash('התנועה לא נמצאה.', false);
  } elseif ($act === 'save') {
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? [])))); $cat = (array)($_POST['cat'] ?? []); $inv = (array)($_POST['inv'] ?? []); $rec = (array)($_POST['rec'] ?? []); $ch = 0;
    if ($ids) {
      $q = $pdo->prepare('SELECT id, category, has_invoice, has_receipt FROM budget_tx WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute($ids);
      $up = $pdo->prepare('UPDATE budget_tx SET category=?, has_invoice=?, has_receipt=?, updated_at=? WHERE id=?'); $learn = []; $nm = $pdo->prepare('SELECT name FROM budget_tx WHERE id=?');
      foreach ($q->fetchAll() as $r) {
        $id = (int)$r['id']; $c = (string)($cat[$id] ?? $r['category']); if ($c !== '' && !in_array($c, $cats, true)) $c = (string)$r['category'];
        $iv = isset($inv[$id]) ? 1 : 0; $rc = isset($rec[$id]) ? 1 : 0;
        if ($c !== $r['category'] || $iv !== (int)$r['has_invoice'] || $rc !== (int)$r['has_receipt']) { $up->execute([$c, $iv, $rc, now(), $id]); $ch++; }
        if ($r['category'] === '' && $c !== '' && !empty($_POST['learn'])) { $nm->execute([$id]); $learn[(string)$nm->fetchColumn()] = $c; }
      }
    }
    $lr = 0; $lx = 0; $lg = 0; foreach ($learn ?? [] as $kw => $c) { $n2 = budget_learn((string)$kw, $c); if ($n2 >= 0) { $lr++; $lx += $n2; } elseif ($n2 === -2) $lg++; }
    if ($ch) audit('budget_save', 'budget', '', "עודכנו $ch תנועות (קטגוריה/חשבונית/קבלה)" . ($lr ? ", נלמדו $lr כללים" : ''));
    $flash($ch ? "נשמרו שינויים ב-$ch תנועות." . ($lr ? " המערכת למדה $lr כללי שיוך חדשים" . ($lx ? " ושייכה אוטומטית עוד $lx תנועות דומות" : '') . '.' : '') . ($lg ? " ($lg שמות כלליים של הבנק, כמו \"העברה באינטרנט\", לא נלמדו כדי לא לשייך בטעות. אפשר להוסיף להם כלל ב\"קטגוריות וכללים\".)" : '') : 'לא היו שינויים.');
  } elseif ($act === 'bulk') {
    $c = (string)($_POST['c'] ?? ''); if ($c === '' || !in_array($c, $cats, true)) $flash('בחרי קטגוריה לשיוך.', false);
    $st = $pdo->prepare("UPDATE budget_tx SET category=?, updated_at=? $wh"); $st->execute(array_merge([$c, now()], $wa)); $n = $st->rowCount();
    audit('budget_bulk', 'budget', '', "שויכו $n תנועות לקטגוריה $c"); $flash("$n תנועות שויכו לקטגוריה \"$c\".");
  } elseif ($act === 'pdf_preview') {
    $j = json_decode((string)($_POST['rows'] ?? ''), true);
    if (!is_array($j) || !$j) $flash('לא התקבלו תנועות מה-PDF.', false);
    if (count($j) > BUDGET_MAX_IMPORT) $flash('יותר מדי שורות בקובץ (מקסימום ' . BUDGET_MAX_IMPORT . ').', false);
    $items = budget_prepare($j);
    if (!$items) $flash('לא נמצאו בקובץ תנועות תקינות (תאריך, שם וסכום).', false);
    $_SESSION['bpdf'] = $items; unset($_SESSION['bpdf_kind']); audit('budget_pdf_preview', 'budget', '', count($items) . ' שורות'); redirect('budget_tx.php?pdf=1#pdfprev');
  } elseif ($act === 'pdf_cancel') { unset($_SESSION['bpdf'], $_SESSION['bpdf_kind']); $flash('הייבוא בוטל. לא נשמר דבר.');
  } elseif ($act === 'pdf_apply') {
    $items = $_SESSION['bpdf'] ?? null; if (!is_array($items)) $flash('פג תוקף התצוגה המקדימה. העלי את הקובץ שוב.', false);
    [$ad, $un, $ud, $rp] = budget_commit($items, array_map('intval', (array)($_POST['pick'] ?? [])), $by); unset($_SESSION['bpdf'], $_SESSION['bpdf_kind']);
    audit('budget_import', 'budget', '', "PDF/אשראי: יובאו $ad, עודכנו $ud שורות העברה באינטרנט, דביטים שפורטו $rp, ללא שיוך $un");
    $flash("יובאו $ad תנועות מה-PDF" . ($ud ? " ופורטו $ud שורות \"העברה באינטרנט\" מדוח הבנק" : '') . ($rp ? ". $rp שורות \"דביט כא\"ל\" מדוח הבנק הוחלפו ברכישות עצמן" : '') . ($un ? ". $un מהן לא שויכו אוטומטית. כדי לשייך אותן ידנית (והמערכת תלמד לפעם הבאה) יש לסנן קטגוריה \"לא משויך\"." : ' ושויכו כולן אוטומטית.'));
  } elseif ($act === 'import') {
    $fl = $_FILES['file'] ?? null;
    if (!$fl || ($fl['error'] ?? 4) !== UPLOAD_ERR_OK) $flash(upload_error_text((int)($fl['error'] ?? UPLOAD_ERR_NO_FILE)), false);
    elseif (!preg_match('/\.(xlsx|csv|xls|htm|html)$/i', (string)$fl['name'])) $flash('אפשר להעלות PDF, xlsx, xls (מהבנק) או csv.', false);
    elseif ((int)$fl['size'] > 10 * 1024 * 1024) $flash('הקובץ גדול מדי (מעל 10MB).', false);
    elseif (!is_uploaded_file($fl['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $flash('קובץ לא תקין.', false);
    if (preg_match('/\.xlsx$/i', (string)$fl['name'])) {   // דוח פירוט עסקאות של חברת האשראי: תצוגה מקדימה והתאמה לדביטים
      try { $card = budget_card_rows(xlsx_rows((string)$fl['tmp_name'])); } catch (Throwable $t) { $card = null; }
      if ($card) {
        if (!budget_has_card()) $flash('כדי לייבא דוח אשראי יש להריץ קודם "עדכון מסד נתונים" (מנהל מערכת).', false);
        $items = budget_card_prepare($card); $_SESSION['bpdf'] = $items; $_SESSION['bpdf_kind'] = 'card';
        audit('budget_card_preview', 'budget', '', 'כרטיס ' . $card['last4'] . ': ' . count($items) . ' עסקאות'); redirect('budget_tx.php?pdf=1#pdfprev');
      }
    }
    try { [$ad, $du, $bd] = budget_import((string)$fl['tmp_name'], (string)$fl['name'], $by); }
    catch (Throwable $t) { $flash('הייבוא נכשל: ' . $t->getMessage(), false); }
    audit('budget_import', 'budget', '', "יובאו $ad, כפולות $du, לא תקינות $bd"); $flash("יובאו $ad תנועות" . ($du ? ", דולגו $du שורות שכבר קיימות" : '') . ($bd ? ", $bd שורות לא תקינות (תאריך או סכום חסרים) לא יובאו" : '') . '.');
  }
  redirect($self);
}

// ---- נתונים לתצוגה ----
$msg = $_SESSION['bmsg'] ?? null; unset($_SESSION['bmsg']);
$q = db()->prepare("SELECT COUNT(*) n, SUM(CASE WHEN is_income=0 THEN amount ELSE 0 END) ex, SUM(CASE WHEN is_income=1 THEN amount ELSE 0 END) inc FROM budget_tx $wh"); $q->execute($wa); $tot = $q->fetch();
$per = 200; $pages = max(1, (int)ceil((int)$tot['n'] / $per)); $p = min($pages, max(1, (int)($_GET['p'] ?? 1)));
$q = db()->prepare("SELECT * FROM budget_tx $wh ORDER BY {$SORT[$sk]} $sd, id $sd LIMIT $per OFFSET " . (($p - 1) * $per)); $q->execute($wa); $rows = $q->fetchAll();
$editId = (int)($_GET['edit'] ?? 0); $ed = null;
if ($editId) { $q = db()->prepare('SELECT * FROM budget_tx WHERE id=?'); $q->execute([$editId]); $ed = $q->fetch() ?: null; }
$showForm = $ed || isset($_GET['add']);
$sortLink = function (string $k, string $l) use ($keep, $sk, $sd) { $o = $keep; unset($o['p']); $o['s'] = $k; $o['dir'] = ($sk === $k && $sd === 'asc') ? 'desc' : 'asc'; return '<a href="budget_tx.php?' . e(http_build_query($o)) . '">' . e($l) . ($sk === $k ? ($sd === 'asc' ? ' ▲' : ' ▼') : '') . '</a>'; };
$pg = function (int $n) use ($keep) { $o = $keep; $o['p'] = $n; return 'budget_tx.php?' . http_build_query($o); };
$opt = fn(array $list, string $sel, string $first = '') => ($first !== '' ? '<option value="">' . e($first) . '</option>' : '') . implode('', array_map(fn($x) => '<option' . ($x === $sel ? ' selected' : '') . '>' . e($x) . '</option>', $list));
$v = fn(string $k, $d = '') => $ed ? $ed[$k] : $d;
page_head('תקציב – תנועות');
?>
<h1>תקציב – תנועות</h1>
<?php if ($msg) echo '<p class="' . ($msg[0] ? 'ok' : 'er') . '">' . e($msg[1]) . '</p>'; ?>
<details class="card"<?= $showForm ? ' open' : '' ?>><summary><b><?= $ed ? 'עריכת תנועה' : 'הוספת הוצאה או הכנסה' ?></b></summary>
  <form method="post" class="bar" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="<?= $ed ? 'update' : 'add' ?>"><?php if ($ed) echo '<input type="hidden" name="id" value="' . (int)$ed['id'] . '">'; ?>
    <label>תאריך<input type="date" name="d" required value="<?= e($v('tx_date', date('Y-m-d'))) ?>"></label>
    <label>שם (ספק)<input name="n" required maxlength="150" value="<?= e($v('name')) ?>"></label>
    <label>תיאור<input name="e" maxlength="300" value="<?= e($v('descr')) ?>"></label>
    <label>אסמכתה<input name="r" maxlength="100" value="<?= e($v('ref')) ?>"></label>
    <label>סכום ₪<input name="a" required inputmode="decimal" value="<?= $ed ? e((string)$ed['amount']) : '' ?>"></label>
    <label>סוג<select name="t"><option value="out">הוצאה</option><option value="in"<?= $ed && $ed['is_income'] ? ' selected' : '' ?>>הכנסה</option></select></label>
    <label>קטגוריה<select name="c"><?= $opt($cats, (string)$v('category'), 'שיוך אוטומטי / ללא') ?></select></label>
    <label>יחידה<select name="u"><?= $opt($units, (string)$v('unit', $units[0])) ?></select></label>
    <label class="chk"><input type="checkbox" name="inv"<?= $ed && $ed['has_invoice'] ? ' checked' : '' ?>> חשבונית</label>
    <label class="chk"><input type="checkbox" name="rec"<?= $ed && $ed['has_receipt'] ? ' checked' : '' ?>> קבלה</label>
    <button><?= $ed ? 'שמירת שינויים' : 'הוספה' ?></button><?php if ($ed) echo '<a class="btn sec" href="' . e($self) . '">ביטול</a>'; ?>
  </form>
  <p class="mu">אם לא נבחרה קטגוריה, המערכת משייכת לפי מילות מפתח בשם ובתיאור (אפשר לערוך את הכללים בדף "קטגוריות").</p>
</details>
<form method="get" class="bar auto card"><?= budget_year_select($f) ?>
  <label>חיפוש<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="שם, תיאור, סכום…"></label>
  <label>קטגוריה<select name="cat"><option value="">הכול</option><option value="__un"<?= $f['cat'] === '__un' ? ' selected' : '' ?>>לא משויך</option><?= $opt($cats, $f['cat']) ?></select></label>
  <label>יחידה<select name="unit"><?= $opt($units, $f['unit'], 'הכול') ?></select></label>
  <label>סוג<select name="type"><option value="">הכול</option><option value="out"<?= $f['type'] === 'out' ? ' selected' : '' ?>>הוצאות</option><option value="in"<?= $f['type'] === 'in' ? ' selected' : '' ?>>הכנסות</option></select></label>
  <label>מסמכים<select name="doc"><?php foreach ([''=>'הכול','ni'=>'חסרה חשבונית','nr'=>'חסרה קבלה','nb'=>'חסרים שניהם','ok'=>'יש חשבונית וקבלה'] as $k => $l) echo '<option value="' . $k . '"' . ($f['doc'] === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></label>
  <?php if ($f['month']) echo '<input type="hidden" name="month" value="' . e($f['month']) . '"><span class="pill">חודש ' . e(substr($f['month'], 5) . '/' . substr($f['month'], 0, 4)) . ' <a href="' . e('budget_tx.php?' . http_build_query(array_diff_key($keep, ['month' => 1, 'p' => 1]))) . '">הסרה</a></span>'; ?>
</form>
<p><b><?= (int)$tot['n'] ?></b> תנועות · הוצאות <b><?= e(budget_money0($tot['ex'])) ?></b> · הכנסות <b class="ok"><?= e(budget_money0($tot['inc'])) ?></b> · <a href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'csv=1') ?>">ייצוא CSV (לפי הסינון)</a></p>
<?php if ($rows): ?>
<form method="post" class="dirty" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="save">
<div class="bar"><button class="sv" disabled>שמירת שינויים בקטגוריות ובמסמכים</button> <label class="inl"><input type="checkbox" name="learn" value="1" checked> זכרי שיוך של תנועה שלא שויכה (לפי שם הספק) גם לפעם הבאה</label></div>
<div class="card sc"><table>
<tr><th><?= $sortLink('d', 'תאריך') ?></th><th><?= $sortLink('n', 'שם') ?></th><th>תיאור</th><th>אסמכתה</th><th><?= $sortLink('a', 'סכום') ?></th><th><?= $sortLink('c', 'קטגוריה') ?></th><th><?= $sortLink('u', 'יחידה') ?></th><th>חשבונית</th><th>קבלה</th><th></th></tr>
<?php foreach ($rows as $r): $id = (int)$r['id']; ?>
<tr<?= $r['category'] === '' ? ' class="unm"' : '' ?>><td class="n"><?= e(date('d/m/Y', strtotime($r['tx_date']))) ?><input type="hidden" name="ids[]" value="<?= $id ?>"></td><td><?= e($r['name']) ?></td><td><?= e($r['descr']) ?></td><td><?= e($r['ref']) ?></td>
<td class="n<?= $r['is_income'] ? ' ok' : '' ?>"><bdi dir="ltr"><?= $r['is_income'] ? '+' : '' ?><?= e(budget_money($r['amount'])) ?></bdi></td>
<td><select name="cat[<?= $id ?>]"><?= $opt($cats, $r['category'], '— לא משויך —') ?></select></td><td><?= e($r['unit']) ?></td>
<td><input type="checkbox" name="inv[<?= $id ?>]" value="1"<?= $r['has_invoice'] ? ' checked' : '' ?>></td><td><input type="checkbox" name="rec[<?= $id ?>]" value="1"<?= $r['has_receipt'] ? ' checked' : '' ?>></td>
<td class="n"><a href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'edit=' . $id) ?>">עריכה</a> <button class="dg" name="del" value="<?= $id ?>" data-confirm="למחוק את התנועה?" formnovalidate>מחיקה</button></td></tr>
<?php endforeach; ?>
</table></div>
</form>
<?php if ($pages > 1): ?><p>עמוד <?= $p ?> מתוך <?= $pages ?> · <?php if ($p > 1) echo '<a href="' . e($pg($p - 1)) . '">הקודם</a> '; if ($p < $pages) echo '<a href="' . e($pg($p + 1)) . '">הבא</a>'; ?></p><?php endif; ?>
<form method="post" class="bar card" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="bulk">
  <label>שיוך כל התנועות שמוצגות בסינון (<?= (int)$tot['n'] ?>) לקטגוריה<select name="c"><?= $opt($cats, '', '— בחרי —') ?></select></label><button class="sec" data-confirm="לשייך את כל <?= (int)$tot['n'] ?> התנועות שבסינון לקטגוריה שנבחרה?">שייכי</button></form>
<?php else: ?><div class="card"><p>לא נמצאו תנועות.</p></div><?php endif; ?>
<?php $pv = $_SESSION['bpdf'] ?? null; if (is_array($pv) && isset($_GET['pdf'])): $nNew = count(array_filter($pv, fn($x) => !$x['dup'])); $nUn = count(array_filter($pv, fn($x) => !$x['dup'] && $x['cat'] === '')); $nUp = count(array_filter($pv, fn($x) => !$x['dup'] && !empty($x['upd']))); $isCard = ($_SESSION['bpdf_kind'] ?? '') === 'card'; $nGr = count(array_filter($pv, fn($x) => !$x['dup'] && !empty($x['grp']))); ?>
<form method="post" class="card" action="budget_tx.php" id="pdfprev"><?= csrf_field() ?>
  <h2>תצוגה מקדימה – <?= $isCard ? 'ייבוא דוח אשראי' : 'ייבוא מ-PDF' ?></h2>
  <?php if ($isCard): ?><p class="mu"><?= $nGr ?> מהרכישות הותאמו לשורות "דביט כא\"ל" מדוח הבנק (הסכום הכולל זהה בדיוק): שורת הדביט תוחלף ברכישות עצמן, כך שלא יהיה כפל. רכישות שלא הותאמו יתווספו כרגיל, ואם דוח הבנק ייובא אחר כך הן יסתדרו מולו.</p><?php endif; ?>
  <p><b><?= count($pv) ?></b> תנועות בקובץ: <b><?= $nNew ?></b> חדשות<?= $nUp ? ' (מהן ' . $nUp . ' מפרטות שורות "העברה באינטרנט" שכבר יובאו מדוח הבנק, ולא יתווספו פעמיים)' : '' ?><?= count($pv) - $nNew ? ', ' . (count($pv) - $nNew) . ' כבר קיימות (ידולגו)' : '' ?>. <b><?= $nNew - $nUn ?></b> שויכו אוטומטית לקטגוריה<?= $nUn ? ', <b>' . $nUn . '</b> ללא שיוך (יישארו בטבלה לשיוך ידני)' : '' ?>.</p>
  <div class="sc"><table><tr><th>ייבוא</th><th>תאריך</th><th>שם</th><th>תיאור</th><th>אסמכתה</th><th>סכום</th><th>קטגוריה</th></tr>
  <?php foreach ($pv as $i => $x): ?>
    <tr<?= $x['dup'] ? ' class="mu"' : ($x['cat'] === '' ? ' class="unm"' : '') ?>><td><input type="checkbox" name="pick[]" value="<?= $i ?>" aria-label="ייבוא"<?= $x['dup'] ? ' disabled' : ' checked' ?>></td>
    <td class="n"><?= e(date('d/m/Y', strtotime($x['d']))) ?></td><td><?= e($x['n']) ?></td><td><?= e($x['e']) ?></td><td><?= e($x['r']) ?></td>
    <td class="n<?= $x['inc'] ? ' ok' : '' ?>"><bdi dir="ltr"><?= $x['inc'] ? '+' : '' ?><?= e(budget_money($x['a'])) ?></bdi></td>
    <td><?= $x['dup'] ? 'כבר קיימת' : ($x['cat'] === '' ? '— לא משויך —' : e($x['cat'])) ?><?= !empty($x['upd']) ? ' <span class="mu">· מפרטת "העברה באינטרנט" מדוח הבנק</span>' : '' ?><?= !empty($x['grp']) ? ' <span class="mu">· מחליפה ' . e($x['gl']) . '</span>' : '' ?></td></tr>
  <?php endforeach; ?></table></div>
  <p class="bar"><button name="act" value="pdf_apply" data-confirm="לייבא את <?= $nNew ?> התנועות שסומנו?">ייבוא התנועות שסומנו</button><button name="act" value="pdf_cancel" class="sec" formnovalidate>ביטול</button></p>
</form>
<?php endif; ?>
<details class="card" id="imp"><summary><b>ייבוא מ-Excel או CSV</b></summary>
  <form method="post" enctype="multipart/form-data" class="bar" action="<?= e($self) ?>" data-bpdf><?= csrf_field() ?><input type="hidden" name="act" value="import"><input type="hidden" name="rows" value=""><label>קובץ (PDF / xlsx / xls / csv)<input type="file" name="file" accept=".pdf,.xlsx,.csv,.xls,.htm,.html" required></label><button>ייבוא</button> <span id="bpdf-msg" class="mu" role="status"></span></form>
  <?php $nIb = (int)db()->query("SELECT COUNT(*) FROM budget_tx WHERE name = '" . BUDGET_IB . "' AND category = ''")->fetchColumn(); if ($nIb > 0): ?><p class="aptn"><b><?= $nIb ?></b> שורות "העברה באינטרנט" מדוח הבנק ממתינות לפירוט. יש להעלות כאן את <b>רשימת ההעברות</b> (PDF) מהבנק, והן יתמלאו בשם הספק, בתיאור ובקטגוריה בלי להוסיף תנועות כפולות.</p><?php endif; ?>
  <p class="mu"><b>קובץ PDF:</b> רשימת העברות או תנועות חשבון מהבנק. המערכת קוראת את התנועות, משייכת לקטגוריות לפי הכללים, ומציגה תצוגה מקדימה לאישור. מה שלא זוהה נשאר "לא משויך" לשיוך ידני.</p>
  <p class="mu">עמודות: תאריך, שם, תיאור, אסמכתה, סכום, קטגוריה, יחידה, סוג (הכנסה / הוצאה), חשבונית, קבלה. נדרשות לפחות "תאריך" ו"סכום". שורות בלי קטגוריה ישויכו אוטומטית לפי מילות מפתח. שורות זהות לתנועה קיימת ידולגו, כך שאפשר לייבא שוב קובץ שיוצא מכאן. עד <?= BUDGET_MAX_IMPORT ?> שורות בקובץ.</p>
</details>
<?php page_foot();
