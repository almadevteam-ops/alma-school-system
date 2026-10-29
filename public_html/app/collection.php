<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$err = ''; $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'bulk_charge') {
  csrf_check();
  $kind = (string)($_POST['kind'] ?? 'other'); $mode = (string)($_POST['mode'] ?? 'flat');
  $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 100); $amt = money_in((string)($_POST['amount'] ?? ''));
  if (!isset(CHARGE_KINDS[$kind])) $kind = 'other';
  if ($label === '') $err = 'נא למלא תיאור חיוב (למשל "ספרי לימוד תשפז").';
  elseif (empty($_POST['ok'])) $err = 'נא לאשר את ההוספה לכל התיקים.';
  elseif ($mode === 'flat' && ($amt === null || $amt <= 0)) $err = 'סכום לא תקין.';
  else {
    $pdo = db(); $pdo->beginTransaction();
    try {
      $rows = $pdo->query("SELECT f.id fid, s.grade FROM families f JOIN students s ON s.family_id = f.id AND s.status = 'active'")->fetchAll();
      $per = [];
      foreach ($rows as $r) {
        $a = $mode === 'books' ? (BOOKS_BY_GRADE[$r['grade']] ?? 0) : (float)$amt;
        $per[(int)$r['fid']] = ($per[(int)$r['fid']] ?? 0) + $a;
      }
      $has = $pdo->prepare('SELECT 1 FROM charges WHERE family_id = ? AND label = ? AND voided_at IS NULL');
      $ins = $pdo->prepare('INSERT INTO charges (family_id, kind, label, amount, created_by, created_at) VALUES (?,?,?,?,?,?)');
      $n = 0; $skip = 0;
      foreach ($per as $fid => $total) {
        if ($total <= 0) continue;
        $has->execute([$fid, $label]);
        if ($has->fetchColumn()) { $skip++; continue; }
        $ins->execute([$fid, $kind, $label, $total, $u['id'], now()]); $n++;
      }
      $pdo->commit();
      audit('charge_bulk', 'family', '', "$label: נוסף ל-$n תיקים, דולגו $skip");
      $msg = "החיוב \"$label\" נוסף ל-$n תיקים" . ($skip ? " ($skip כבר היה להם)" : '') . '.';
    } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
  }
}
$q = trim((string)($_GET['q'] ?? '')); $f = (string)($_GET['f'] ?? 'all'); $mm = (string)($_GET['m'] ?? '');
$w = []; $a = [];
if ($q !== '') { $w[] = '(f.name LIKE ? OR EXISTS (SELECT 1 FROM students s2 WHERE s2.family_id = f.id AND s2.name LIKE ?))'; $l = '%' . $q . '%'; array_push($a, $l, $l); }
if ($mm !== '' && isset(PAY_METHODS[$mm])) { $w[] = 'f.method = ?'; $a[] = $mm; }
$sql = 'SELECT f.id, f.name, f.monthly, f.months, f.method, f.collect_day, f.discount_note, p.name payer, '
     . "(SELECT GROUP_CONCAT(CONCAT(s.name, ' (', s.grade, ')') ORDER BY s.name SEPARATOR ', ') FROM students s WHERE s.family_id = f.id) names "
     . 'FROM families f LEFT JOIN payers p ON p.id = f.payer_id ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY f.name';
$st = db()->prepare($sql); $st->execute($a); $fams = $st->fetchAll();
$data = collection_data(array_map(fn($r) => (int)$r['id'], $fams));
if ($f === 'debt') $fams = array_values(array_filter($fams, fn($r) => $data[(int)$r['id']]['balance'] > 0.004));
elseif ($f === 'clear') $fams = array_values(array_filter($fams, fn($r) => $data[(int)$r['id']]['balance'] <= 0.004));
$T = ['tuition' => 0, 'charges' => 0, 'paid' => 0, 'balance' => 0];
foreach ($fams as $r) foreach ($T as $k => $_) $T[$k] += $data[(int)$r['id']][$k];
if (isset($_GET['export'])) {
  audit('collection_export', 'family', '', count($fams) . ' שורות');
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="collection-' . date('Y-m-d') . '.csv"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['משפחה', 'תלמידות', 'תעריף חודשי', 'חודשים', 'שכר לימוד', 'חיובים נוספים', 'שולם', 'יתרה', 'צורת תשלום', 'יום גבייה', 'גורם משלם', 'הנחה'], ',', '"', '');
  foreach ($fams as $r) { $d = $data[(int)$r['id']];
    fputcsv($o, [csv_safe($r['name']), csv_safe((string)$r['names']), $r['monthly'], $r['months'], $d['tuition'], $d['charges'], $d['paid'], $d['balance'], method_label($r['method']), $r['collect_day'], csv_safe((string)$r['payer']), csv_safe($r['discount_note'])], ',', '"', ''); }
  exit;
}
$payers = db()->query('SELECT p.id, p.name, (SELECT COUNT(*) FROM families WHERE payer_id = p.id) n FROM payers p ORDER BY p.name')->fetchAll();
$nofile = db()->query("SELECT id, name, grade FROM students WHERE family_id IS NULL AND status = 'active' ORDER BY FIELD(grade,'ז','ח','ט','י','יא','יב'), name")->fetchAll();
page_head('גבייה');
?>
<h1>גבייה <span class="mu">(<?= count($fams) ?> תיקים)</span></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card"><div class="stats">
  <div><span class="mu">שכר לימוד</span><b><?= e(money($T['tuition'])) ?></b></div>
  <div><span class="mu">חיובים נוספים</span><b><?= e(money($T['charges'])) ?></b></div>
  <div><span class="mu">שולם</span><b class="ok"><?= e(money($T['paid'])) ?></b></div>
  <div><span class="mu">יתרה</span><b class="er"><?= e(money($T['balance'])) ?></b></div>
</div></div>
<form class="bar" method="get">
  <label>חיפוש<input name="q" value="<?= e($q) ?>" placeholder="שם משפחה / תלמידה"></label>
  <label>מצב<select name="f"><option value="all">הכל</option><option value="debt"<?= $f === 'debt' ? ' selected' : '' ?>>עם יתרה</option><option value="clear"<?= $f === 'clear' ? ' selected' : '' ?>>ללא יתרה</option></select></label>
  <label>צורת תשלום<select name="m"><option value="">הכל</option><?php foreach (PAY_METHODS as $k => $v) echo '<option value="' . $k . '"' . ($mm === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <button>סינון</button>
  <a class="btn sec" href="collection.php?<?= e(http_build_query(['q' => $q, 'f' => $f, 'm' => $mm, 'export' => 1])) ?>">ייצוא לאקסל (CSV)</a>
  <?php if ($u['role'] === 'admin') echo '<a class="btn sec" href="collection_import.php">ייבוא תיקי גבייה</a>'; ?>
</form>
<div class="card sc"><table>
<tr><th>משפחה</th><th>תלמידות</th><th>תעריף</th><th>חיובים</th><th>שולם</th><th>יתרה</th><th>חודשים</th><th>אמצעי</th><th>יום</th></tr>
<?php foreach ($fams as $r): $d = $data[(int)$r['id']]; ?>
<tr>
  <td><a href="family.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><?= $r['payer'] ? ' <span class="pill">' . e($r['payer']) . '</span>' : '' ?></td>
  <td class="mu"><?= e((string)$r['names']) ?></td>
  <td><?= e(money($r['monthly'])) ?> × <?= (int)$r['months'] ?></td>
  <td><?= e(money($d['charges'])) ?></td><td><?= e(money($d['paid'])) ?></td>
  <td class="<?= $d['balance'] > 0.004 ? 'er' : 'ok' ?>"><?= e(money($d['balance'])) ?></td>
  <td class="mcs"><?= month_cells($d) ?></td>
  <td><?= e(method_label($r['method'])) ?></td><td><?= e((string)($r['collect_day'] ?? '')) ?></td>
</tr>
<?php endforeach; if (!$fams) echo '<tr><td colspan="9" class="mu">אין תיקים להצגה.</td></tr>'; ?>
</table></div>
<div class="card">
  <h2>גורמים משלמים (עמותות)</h2>
  <p><?php foreach ($payers as $p) echo '<a class="btn sec" href="payer.php?id=' . (int)$p['id'] . '">' . e($p['name']) . ' (' . (int)$p['n'] . ' תיקים)</a> '; if (!$payers) echo '<span class="mu">עדיין לא הוגדר גורם משלם.</span> '; ?>
  <a class="btn" href="payer.php?new=1">+ גורם משלם חדש</a></p>
</div>
<div class="card">
  <h2>חיוב לכל התיקים</h2>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="bulk_charge">
    <label>סוג<select name="kind"><?php foreach (CHARGE_KINDS as $k => $v) echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
    <label>תיאור<input name="label" maxlength="100" placeholder="למשל: ספרי לימוד תשפז" required></label>
    <label>אופן חישוב<select name="mode"><option value="flat">סכום קבוע לכל תלמידה</option><option value="books">ספרים לפי כיתה (ז-ח 250, ט-יא 350, יב לפי צורך)</option></select></label>
    <label>סכום לתלמידה<input name="amount" inputmode="decimal"></label>
    <label class="chk"><input type="checkbox" name="ok" value="1"> לאשר הוספה לכל התיקים הפעילים</label>
    <button>הוספה</button>
  </form>
  <p class="mu">חיוב שכבר קיים בתיק עם אותו תיאור לא יתווסף שוב. אפשר לבטל חיוב בתוך התיק.</p>
</div>
<div class="card">
  <h2>תלמידות בלי תיק גבייה (<?= count($nofile) ?>)</h2>
  <?php if (isset($_GET['nofile'])): ?>
  <div class="sc"><table><?php foreach ($nofile as $s): ?>
    <tr><td><?= e($s['name']) ?></td><td><?= e($s['grade']) ?></td><td><form method="post" action="family.php" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="create"><input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>"><button class="sec">פתיחת תיק</button></form></td></tr>
  <?php endforeach; ?></table></div>
  <?php else: ?><p><a class="btn sec" href="collection.php?nofile=1">הצגה</a></p><?php endif; ?>
</div>
<?php page_foot();
