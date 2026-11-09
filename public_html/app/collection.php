<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$err = ''; $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'bulk_charge') {
  csrf_check();
  $mode = (string)($_POST['mode'] ?? 'flat');
  $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 100); $amt = money_in((string)($_POST['amount'] ?? ''));
  $kind = preg_match('/ספר/u', $label) ? 'books' : (preg_match('/סיור|טיול/u', $label) ? 'tour' : (preg_match('/חוג/u', $label) ? 'activities' : 'other'));
  if ($label === '') $err = 'נא למלא קטגוריה (למשל "ספרי לימוד" או "סיור שנתי").';
  elseif (empty($_POST['ok'])) $err = 'נא לאשר את ההוספה לכל התיקים.';
  elseif ($mode === 'flat' && ($amt === null || $amt <= 0)) $err = 'סכום לא תקין.';
  else {
    $pdo = db(); $pdo->beginTransaction();
    try {
      $rows = $pdo->query("SELECT f.id fid, s.grade FROM families f JOIN students s ON s.family_id = f.id AND s.status = 'active' AND s.archived_at IS NULL")->fetchAll();
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'create_all') {
  csrf_check();
  if (empty($_POST['ok'])) $err = 'נא לאשר פתיחת תיקים לכל התלמידות.';
  else {
    $pdo = db(); $pdo->beginTransaction();
    try {
      $rows = $pdo->query("SELECT id, name FROM students WHERE family_id IS NULL AND status = 'active' AND archived_at IS NULL ORDER BY name")->fetchAll();
      $fi = $pdo->prepare('INSERT INTO families (name, created_at, updated_at) VALUES (?,?,?)'); $su = $pdo->prepare('UPDATE students SET family_id = ? WHERE id = ? AND family_id IS NULL');
      foreach ($rows as $r) { $fi->execute([mb_substr($r['name'], 0, 120), now(), now()]); $su->execute([(int)$pdo->lastInsertId(), $r['id']]); }
      $pdo->commit();
    } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
    audit('family_create_all', 'family', '', count($rows) . ' תיקים');
    $msg = 'נפתחו ' . count($rows) . ' תיקי גבייה (תעריף 0, אפשר להשלים בתוך כל תיק).';
  }
}
$q = trim((string)($_GET['q'] ?? '')); $f = (string)($_GET['f'] ?? 'all'); $mm = (string)($_GET['m'] ?? '');
$view = ($_GET['v'] ?? '') === 'calls' ? 'calls' : 'table'; $kind = (string)($_GET['k'] ?? ''); $min = max(0, (float)str_replace(',', '', (string)($_GET['min'] ?? '')));
$sorts = ['name' => 'לפי שם', 'bal' => 'יתרה (מהגבוהה)', 'late' => 'פיגור היום (מהגבוה)', 'behind' => 'חודשי פיגור (מהרב)']; $sort = (string)($_GET['s'] ?? ($view === 'calls' ? 'late' : 'name')); if (!isset($sorts[$sort])) $sort = 'name';
$w = []; $a = [];
if ($q !== '') { $w[] = '(f.name LIKE ? OR EXISTS (SELECT 1 FROM students s2 WHERE s2.family_id = f.id AND s2.name LIKE ?))'; $l = '%' . $q . '%'; array_push($a, $l, $l); }
if ($mm !== '' && isset(PAY_METHODS[$mm])) { $w[] = 'f.method = ?'; $a[] = $mm; }
if ($kind === 'parents') $w[] = "f.payer_kind IN ('parents','father','mother')"; elseif ($kind === 'org') $w[] = "f.payer_kind NOT IN ('parents','father','mother')";
$sql = 'SELECT f.id, f.name, f.monthly, f.months, f.method, f.collect_day, f.discount_note, f.payer_kind, p.name payer, '
     . "(SELECT GROUP_CONCAT(CONCAT(s.name, ' (', s.grade, ')') ORDER BY s.name SEPARATOR ', ') FROM students s WHERE s.family_id = f.id AND s.archived_at IS NULL) names "
     . 'FROM families f LEFT JOIN payers p ON p.id = f.payer_id ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY f.name';
$st = db()->prepare($sql); $st->execute($a); $fams = $st->fetchAll();
$data = collection_data(array_map(fn($r) => (int)$r['id'], $fams));
if ($f === 'debt') $fams = array_values(array_filter($fams, fn($r) => $data[(int)$r['id']]['balance'] > 0.004));
elseif ($f === 'clear') $fams = array_values(array_filter($fams, fn($r) => $data[(int)$r['id']]['balance'] <= 0.004));
$arr = []; foreach ($fams as $r) $arr[(int)$r['id']] = collection_arrears($data[(int)$r['id']], $r['collect_day']);
if ($f === 'late') $fams = array_values(array_filter($fams, fn($r) => $arr[(int)$r['id']]['late'] > 0.004));
if ($min > 0) $fams = array_values(array_filter($fams, fn($r) => $data[(int)$r['id']]['balance'] >= $min));
if ($sort !== 'name') usort($fams, function ($x, $y) use ($sort, $data, $arr) {
  $ix = (int)$x['id']; $iy = (int)$y['id'];
  $c = match ($sort) { 'bal' => $data[$iy]['balance'] <=> $data[$ix]['balance'], 'late' => $arr[$iy]['late'] <=> $arr[$ix]['late'], default => [$arr[$iy]['behind'], $arr[$iy]['late']] <=> [$arr[$ix]['behind'], $arr[$ix]['late']] };
  return $c ?: strcmp((string)$x['name'], (string)$y['name']);
});
$contacts = family_contacts(array_map(fn($r) => (int)$r['id'], $fams));
$late = ['n' => 0, 'sum' => 0.0]; foreach ($fams as $r) { $x = $arr[(int)$r['id']]; if ($x['late'] > 0.004) { $late['n']++; $late['sum'] += $x['late']; } }
$T = ['tuition' => 0, 'charges' => 0, 'paid' => 0, 'balance' => 0];
foreach ($fams as $r) foreach ($T as $k => $_) $T[$k] += $data[(int)$r['id']][$k];
if (isset($_GET['export'])) {
  audit('collection_export', 'family', '', count($fams) . ' שורות');
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="collection-' . date('Y-m-d') . '.csv"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['משפחה', 'תלמידות', 'תעריף חודשי', 'חודשים', 'שכר לימוד', 'חיובים נוספים', 'שולם', 'יתרה', 'צורת תשלום', 'יום גבייה', 'גורם משלם', 'הנחה', 'חודשי פיגור', 'פיגור (₪)', 'יתרת שכר לימוד', 'יתרת חיובים נוספים', 'אבא', 'טלפון אבא', 'אמא', 'טלפון אמא'], ',', '"', '');
  foreach ($fams as $r) { $d = $data[(int)$r['id']]; $ct = $contacts[(int)$r['id']] ?? [];
    fputcsv($o, [csv_safe($r['name']), csv_safe((string)$r['names']), $r['monthly'], $r['months'], $d['tuition'], $d['charges'], $d['paid'], $d['balance'], method_label($r['method']), $r['collect_day'], csv_safe(payer_label($r)), csv_safe($r['discount_note']),
      $arr[(int)$r['id']]['behind'], $arr[(int)$r['id']]['late'], round($d['tuition'] - ($d['paid'] - $d['cpaid']), 2), round($d['charges'] - $d['cpaid'], 2),
      csv_safe($ct['f'][1] ?? ''), csv_safe(fmt_phone((string)($ct['f'][2] ?? ''))), csv_safe($ct['m'][1] ?? ''), csv_safe(fmt_phone((string)($ct['m'][2] ?? '')))], ',', '"', ''); }
  exit;
}
$payers = db()->query('SELECT p.id, p.name, (SELECT COUNT(*) FROM families WHERE payer_id = p.id) n FROM payers p ORDER BY p.name')->fetchAll();
$nofile = db()->query("SELECT id, name, grade FROM students WHERE family_id IS NULL AND status = 'active' AND archived_at IS NULL ORDER BY FIELD(grade,'ז','ח','ט','י','יא','יב'), name")->fetchAll();
page_head('גבייה');
?>
<h1>גבייה <span class="mu">(<?= count($fams) ?> תיקים)</span></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="tiles">
  <div class="tile"><span class="mu">שכר לימוד לשנה</span><b><?= e(money($T['tuition'])) ?></b></div>
  <div class="tile"><span class="mu">חיובים נוספים</span><b><?= e(money($T['charges'])) ?></b></div>
  <div class="tile g"><span class="mu">שולם</span><b class="ok"><?= e(money($T['paid'])) ?></b></div>
  <div class="tile w"><span class="mu">בפיגור נכון להיום (<?= (int)$late['n'] ?> תיקים)</span><b><?= e(money($late['sum'])) ?></b></div>
  <div class="tile p"><span class="mu">חוב נוכחי (עד <?= e(SCHOOL_MONTHS[school_month_now()]) ?> כולל, בניכוי תשלומים מראש)</span><b class="er"><?= e(money($T['balance'])) ?></b></div>
</div>
<?php $qs = fn(array $o) => http_build_query(array_merge(['q' => $q, 'f' => $f, 'm' => $mm, 'k' => $kind, 'min' => $min ?: '', 's' => $sort, 'v' => $view], $o)); ?>
<p class="subnav"><a class="btn sec" href="dashboard.php">דשבורד</a> <a class="btn sec" href="monthly.php">סיכום חודשי</a> <a class="btn sec" href="charges.php">גביות חד־פעמיות</a> <a class="btn sec" href="gad.php">מורשת גד (דירות)</a> <?php if (in_array($u['role'], ['admin', 'finance'], true)) echo '<a class="btn sec" href="exports.php">ייצוא</a> <a class="btn sec" href="income_import.php">העלאת דוח הכנסות</a>'; ?><?php if (pay_url() !== '') echo ' <a class="btn" href="' . e(pay_url()) . '" target="_blank" rel="noopener">💳 תשלום מאובטח</a>'; ?></p>
<div class="seg" role="tablist">
  <a href="collection.php?<?= e($qs(['v' => 'table'])) ?>" class="<?= $view === 'table' ? 'on' : '' ?>">☰ טבלה</a>
  <a href="collection.php?<?= e($qs(['v' => 'calls', 's' => $view === 'calls' ? $sort : 'late', 'f' => $f === 'all' ? 'late' : $f])) ?>" class="<?= $view === 'calls' ? 'on' : '' ?>">☎ רשימת התקשרות</a>
</div>
<form class="bar auto" method="get">
  <input type="hidden" name="v" value="<?= e($view) ?>">
  <label>חיפוש<input name="q" value="<?= e($q) ?>" placeholder="שם משפחה / תלמידה"></label>
  <label>מצב<select name="f"><option value="all">הכל</option><option value="late"<?= $f === 'late' ? ' selected' : '' ?>>בפיגור היום</option><option value="debt"<?= $f === 'debt' ? ' selected' : '' ?>>עם יתרה</option><option value="clear"<?= $f === 'clear' ? ' selected' : '' ?>>ללא יתרה</option></select></label>
  <label>מיון<select name="s"><?php foreach ($sorts as $k => $v) echo '<option value="' . $k . '"' . ($sort === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <label>יתרה מעל (₪)<input name="min" inputmode="numeric" value="<?= $min ? e((string)$min) : '' ?>" placeholder="0" size="6"></label>
  <label>גורם משלם<select name="k"><option value="">הכל</option><option value="parents"<?= $kind === 'parents' ? ' selected' : '' ?>>הורים</option><option value="org"<?= $kind === 'org' ? ' selected' : '' ?>>עמותה / גורם חיצוני</option></select></label>
  <label>צורת תשלום<select name="m"><option value="">הכל</option><?php foreach (PAY_METHODS as $k => $v) echo '<option value="' . $k . '"' . ($mm === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <button>OK</button>
  <a class="btn sec<?= ($q !== '' || $f !== 'all' || $mm !== '' || $kind !== '' || $min > 0 || $sort !== ($view === 'calls' ? 'late' : 'name')) ? ' hot' : '' ?>" href="collection.php?v=<?= e($view) ?>" title="איפוס כל הסינונים והמיונים">↺ איפוס</a>
  <a class="btn sec" href="collection.php?<?= e(http_build_query(['q' => $q, 'f' => $f, 'm' => $mm, 'k' => $kind, 'min' => $min ?: '', 's' => $sort, 'export' => 1])) ?>">ייצוא לאקסל (CSV)</a>
  <?php if ($u['role'] === 'admin') echo '<a class="btn sec" href="collection_import.php">ייבוא תיקי גבייה</a><a class="btn sec" href="payments_import.php">ייבוא תשלומים</a>'; ?>
</form>
<?php
$phoneIco = '<svg class="ni" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>';
$badge = function (array $x): string {
  if ($x['late'] <= 0.004) return '<span class="late ok0">✓ מעודכן</span>';
  $t = ($x['behind'] ? $x['behind'] . ' ' . ($x['behind'] === 1 ? 'חודש' : 'חודשים') . ' · ' : '') . money($x['late']);
  return '<span class="late ' . ($x['behind'] >= 2 ? 'hi' : 'lo') . '" title="' . e('שכר לימוד חסר: ' . money($x['gap']) . ($x['cgap'] ? ' · חיובים נוספים שלא שולמו: ' . money($x['cgap']) : '')) . '">' . e($t) . '</span>';
};
?>
<?php sec_open('list', 'רשימת התלמידות', count($fams) . ' תיקים' . ($view === 'calls' ? ' · רשימת התקשרות' : ''), '☺', ['list']); ?>
<?php if ($view === 'calls'): $fams = array_values(array_filter($fams, fn($r) => $r['payer_kind'] !== 'org')); ?>
<div class="calls">
<?php foreach ($fams as $r): $d = $data[(int)$r['id']]; $x = $arr[(int)$r['id']]; $ct = $contacts[(int)$r['id']] ?? []; ?>
  <div class="cc<?= $x['late'] > 0.004 ? ($x['behind'] >= 2 ? ' hi' : ' lo') : '' ?>">
    <div class="ch"><a class="nm" href="family.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><?= $badge($x) ?></div>
    <div class="mu"><?= e((string)$r['names']) ?></div>
    <div class="cn"><span class="mu"><?= $d['balance'] < -0.004 ? 'יתרת זכות' : 'חוב נוכחי' ?></span> <b class="<?= $d['balance'] > 0.004 ? 'er' : 'ok' ?>"><?= e(money(abs($d['balance']))) ?></b><?php if ($r['payer_kind'] !== 'parents') echo ' <span class="pill">' . e(payer_label($r)) . '</span>'; ?></div>
    <div class="mcs"><?= month_cells($d) ?></div>
    <div class="tels">
      <?php foreach (['f', 'm'] as $k) if (isset($ct[$k])): [$lab, $nm, $ph] = $ct[$k]; ?>
        <a class="tel" href="<?= e(tel_href($ph)) ?>"><?= $phoneIco ?><span><b><?= e($lab) ?></b><?= $nm !== '' ? ' ' . e($nm) : '' ?></span><bdi dir="ltr"><?= e(fmt_phone($ph)) ?></bdi></a>
        <a class="tel wa" href="<?= e(wa_url($ph, wa_reminder((string)$r['names'], $x, $d['balance']))) ?>" target="_blank" rel="noopener">וואטסאפ</a>
      <?php endif; if (!$ct) echo '<span class="mu">אין מספר טלפון של ההורים בכרטיס התלמידה.</span>'; ?>
    </div>
  </div>
<?php endforeach; if (!$fams) echo '<p class="mu">אין תיקים להצגה.</p>'; ?>
</div>
<?php else: ?>
<div class="ut col">
  <div class="ur uth" aria-hidden="true"><span>משפחה</span><span>תלמידות</span><span>יתרה</span><span>פיגור היום</span><span>חודשים</span><span></span></div>
<?php foreach ($fams as $r): $d = $data[(int)$r['id']]; $ct = $contacts[(int)$r['id']] ?? []; ?>
  <details class="uw">
    <summary class="ur">
      <span class="c nm"><b><?= e($r['name']) ?></b><?php if ($r['payer_kind'] !== 'parents') echo ' <span class="pill">' . e(payer_label($r)) . '</span>'; ?></span>
      <span class="c mu" data-l="תלמידות"><?= e((string)$r['names']) ?></span>
      <span class="c bal <?= $d['balance'] > 0.004 ? 'er' : 'ok' ?>" data-l="יתרה"><?= $d['balance'] < -0.004 ? 'זכות ' : '' ?><?= e(money(abs($d['balance']))) ?></span>
      <span class="c stt" data-l="פיגור"><?= $badge($arr[(int)$r['id']]) ?></span>
      <span class="c mcs" data-l="חודשים"><?= month_cells($d) ?></span>
      <span class="chev" aria-hidden="true">⌄</span>
    </summary>
    <div class="mgb dt">
      <div class="mbs full"><?= month_cells($d, true) ?></div>
      <dl class="kv">
        <div><dt>תעריף</dt><dd><?= e(money($d['monthly'])) ?> × <?= (int)$d['billed'] ?> חודשים = <?= e(money($d['tuition'])) ?><?= $d['disc'] > 0 ? ' <span class="mu">(תעריף ' . e(money($d['base'])) . ' פחות הנחות ' . e(money($d['disc'])) . ')</span>' : '' ?></dd></div>
        <div><dt>חיובים נוספים</dt><dd><?= e(money($d['charges'])) ?></dd></div>
        <div><dt>שולם</dt><dd class="ok"><?= e(money($d['paid'])) ?></dd></div>
        <div><dt>צורת תשלום</dt><dd><?= $r['method'] !== '' ? e(method_label($r['method'])) : '<span class="mu">—</span>' ?><?= $r['collect_day'] ? ' · יום ' . (int)$r['collect_day'] : '' ?></dd></div>
        <?php if ($r['discount_note'] !== null && $r['discount_note'] !== ''): ?><div><dt>הנחה</dt><dd><?= e($r['discount_note']) ?></dd></div><?php endif; ?>
      </dl>
      <div class="tels">
        <?php foreach (['f', 'm'] as $k) if (isset($ct[$k])): [$lab, $nm, $ph] = $ct[$k]; ?>
          <a class="tel" href="<?= e(tel_href($ph)) ?>"><?= $phoneIco ?><span><b><?= e($lab) ?></b><?= $nm !== '' ? ' ' . e($nm) : '' ?></span><bdi dir="ltr"><?= e(fmt_phone($ph)) ?></bdi></a>
        <?php endif; ?>
      </div>
      <div class="acts"><a class="btn" href="family.php?id=<?= (int)$r['id'] ?>">לתיק הגבייה</a><a class="btn sec" href="family.php?id=<?= (int)$r['id'] ?>&open=pay#s-pay">רישום תשלום</a></div>
    </div>
  </details>
<?php endforeach; if (!$fams) echo '<p class="mu emp">אין תיקים להצגה.</p>'; ?>
</div>
<?php endif; ?>
<?php sec_close(); ?>
<?php sec_open('payers', 'גורמים משלמים (עמותות)', count($payers) . ' גורמים', '◎'); ?>
  <p><?php foreach ($payers as $p) echo '<a class="btn sec" href="payer.php?id=' . (int)$p['id'] . '">' . e($p['name']) . ' (' . (int)$p['n'] . ' תיקים)</a> '; if (!$payers) echo '<span class="mu">עדיין לא הוגדר גורם משלם.</span> '; ?>
  <a class="btn" href="payer.php?new=1">+ גורם משלם חדש</a></p>
<?php sec_close(); ?>
<?php sec_open('bulk', 'חיוב לכל התיקים', 'ספרי לימוד, סיור, חוגים', '＋', $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'bulk_charge' ? ['bulk'] : []); ?>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="bulk_charge">
    <label>קטגוריה<input name="label" maxlength="100" list="chg-cats" placeholder="למשל: ספרי לימוד" required></label>
    <datalist id="chg-cats"><?php foreach (array_unique([...array_values(CHARGE_KINDS), ...array_column(db()->query("SELECT DISTINCT label FROM charges WHERE voided_at IS NULL ORDER BY label LIMIT 50")->fetchAll(), 'label')]) as $cv) if ($cv !== 'אחר') echo '<option value="' . e($cv) . '">'; ?></datalist>
    <label>אופן חישוב<select name="mode"><option value="flat">סכום קבוע לכל תלמידה</option><option value="books">ספרים לפי כיתה (ז, ח, יב 250 · ט, י 350 · יא 450)</option></select></label>
    <label>סכום לתלמידה<input name="amount" inputmode="decimal"></label>
    <label class="chk"><input type="checkbox" name="ok" value="1"> לאשר הוספה לכל התיקים הפעילים</label>
    <button>הוספה</button>
  </form>
  <p class="mu">חיוב שכבר קיים בתיק באותה קטגוריה לא יתווסף שוב. אפשר לבטל חיוב בתוך התיק.</p>
<?php sec_close(); ?>
<?php sec_open('nofile', 'תלמידות בלי תיק גבייה', count($nofile) . ' תלמידות', '☺', isset($_GET['nofile']) || ($_POST['act'] ?? '') === 'create_all' ? ['nofile'] : []); ?>
  <?php if (isset($_GET['nofile'])): ?>
  <div class="sc"><table><?php foreach ($nofile as $s): ?>
    <tr><td><?= e($s['name']) ?></td><td><?= e($s['grade']) ?></td><td><form method="post" action="family.php" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="create"><input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>"><button class="sec">פתיחת תיק</button></form></td></tr>
  <?php endforeach; ?></table></div>
  <?php else: ?><p><a class="btn sec" href="collection.php?nofile=1">הצגה</a></p><?php endif; ?>
  <?php if ($nofile): ?>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="create_all">
    <label class="chk"><input type="checkbox" name="ok" value="1"> לפתוח תיק נפרד (בתעריף 0) לכל אחת מ-<?= count($nofile) ?> התלמידות הפעילות שאין להן תיק</label>
    <button>פתיחת תיקים לכולן</button></form>
  <p class="mu">אחיות אפשר לאחד אחר כך: בתיק של אחת מהן, "צירוף תלמידה לתיק".</p>
  <?php endif; ?>
<?php sec_close(); ?>
<?php page_foot();
