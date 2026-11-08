<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'secretary', 'finance']);
$ids = can_see_ids(); $col = in_array($u['role'], COLLECTION_ROLES, true);
function csv_cell($v): string { $v = (string)$v; return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v; }
function csv_out(string $file, array $head, array $rows): void {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $file . '"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, array_map('csv_cell', $head), ',', '"', '');
  foreach ($rows as $r) fputcsv($o, array_map('csv_cell', $r), ',', '"', ''); exit;
}
$ST = ['paid' => 'שולם', 'part' => 'חלקי', 'none' => 'לא שולם', 'na' => '–', 'wip' => 'בטיפול', 'ref' => 'החזר'];
function exp_students(bool $ids): array {
  $S = db()->query("SELECT * FROM students WHERE archived_at IS NULL AND status <> 'pending' ORDER BY FIELD(grade,'ז','ח','ט','י','יא','יב'), cls, name")->fetchAll(); $st = ['active' => 'פעילה', 'left' => 'עזבה'];
  $head = ['שם', 'כיתה', 'סטטוס', 'שם אב', 'טלפון אב', 'שם אם', 'טלפון אם', 'טלפון תלמידה', 'טלפון בית', 'כתובת', 'עיר', 'אימייל', 'קופת חולים', 'מצב הורים', 'בדירה', 'ועדה', '07', 'רגישות', 'הנחה (₪)', 'ספרים'];
  if ($ids) array_splice($head, 2, 0, ['ת.ז', 'תאריך לידה']);
  $rows = [];
  foreach ($S as $s) { $r = [$s['name'], $s['cls'] ?: $s['grade'], $st[$s['status']] ?? $s['status'], $s['f_name'], $s['f_phone'], $s['m_name'], $s['m_phone'], $s['phone'], $s['home_phone'], trim($s['addr'] . ' ' . $s['house_no']), $s['city'], $s['email'], $s['hmo'], $s['marital'] === 'divorced' ? 'גרושים' : 'נשואים', $s['is_apt'] ? 'כן' : '', $s['vaada'] ? 'כן' : '', $s['s07'] ? 'כן' : '', $s['allergy'] ? trim($s['allergy_list'] . ' ' . $s['allergy_other']) : '', $s['disc_on'] ? $s['disc_amt'] : '', $s['books']];
    if ($ids) array_splice($r, 2, 0, [$s['idn'], $s['dob']]); $rows[] = $r; }
  return [$head, $rows];
}
function exp_collection(array $ST): array {
  $F = db()->query('SELECT f.id, f.name, f.monthly, f.months, f.method, f.collect_day, p.name pn, (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR " / ") FROM students s WHERE s.family_id = f.id AND s.archived_at IS NULL) sn FROM families f LEFT JOIN payers p ON p.id = f.payer_id ORDER BY f.name')->fetchAll();
  $D = collection_data(array_map(fn($f) => (int)$f['id'], $F)); $head = ['תיק', 'תלמידות', 'תעריף לחודש', 'תשלומים', 'אמצעי תשלום', 'משלם']; foreach (SCHOOL_MONTHS as $m => $n) $head[] = $n; array_push($head, 'שולם (שכ״ל)', 'חיובים חד-פעמיים', 'שולם (חד-פעמי)', 'יתרה'); $rows = [];
  foreach ($F as $f) { $d = $D[(int)$f['id']] ?? null; if (!$d || !$f['sn']) continue; $r = [$f['name'], $f['sn'], $d['monthly'], $f['months'], method_label((string)$f['method']), $f['pn'] ?? ''];
    foreach (SCHOOL_MONTHS as $m => $n) $r[] = ($ST[$d['status'][$m] ?? 'na'] ?? '') . (($d['status'][$m] ?? 'na') === 'part' ? ' (' . $d['cov'][$m] . ')' : ''); array_push($r, round($d['paid'], 2), round($d['charges'], 2), round($d['cpaid'], 2), round($d['balance'], 2)); $rows[] = $r; }
  return [$head, $rows];
}
function exp_charges(): array {
  $R = db()->query("SELECT f.name fam, c.label, c.kind, c.amount, c.disc_amt, c.mark, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL), 0) paid FROM charges c JOIN families f ON f.id = c.family_id WHERE c.voided_at IS NULL ORDER BY c.label, f.name")->fetchAll();
  $mk = ['' => '', 'w' => 'בטיפול', 'r' => 'החזר', 'n' => 'לא רלוונטי']; $rows = [];
  foreach ($R as $r) $rows[] = [$r['fam'], $r['label'], $r['amount'], $r['disc_amt'], round((float)$r['amount'] - (float)$r['disc_amt'], 2), $r['paid'], round(max(0, (float)$r['amount'] - (float)$r['disc_amt'] - (float)$r['paid']), 2), $mk[$r['mark']] ?? ''];
  return [['תיק', 'חיוב', 'סכום', 'הנחה', 'נטו', 'שולם', 'חסר', 'סימון'], $rows];
}
$v = (string)($_GET['v'] ?? ''); $fmt = (string)($_GET['f'] ?? 'csv');
if ($v !== '') {
  if (!in_array($v, ['students', 'collection', 'charges'], true) || !in_array($fmt, ['csv', 'print'], true)) fail_page(404, 'לא נמצא.');
  if ($v !== 'students' && !$col) fail_page(403, 'אין לך הרשאה לייצוא גבייה.');
  if ($v === 'students' && $u['role'] === 'finance') $ids = false;
  [$head, $rows] = $v === 'students' ? exp_students($ids) : ($v === 'collection' ? exp_collection($ST) : exp_charges());
  audit('export', 'system', '', "$v ($fmt) · " . count($rows) . ' שורות');
  $names = ['students' => 'תלמידות', 'collection' => 'גבייה-לפי-חודשים', 'charges' => 'חיובים-חד-פעמיים'];
  if ($fmt === 'csv') csv_out($names[$v] . '-' . date('Y-m-d') . '.csv', $head, $rows);
  page_head($names[$v]);
  echo '<h1 class="np">' . e(str_replace('-', ' ', $names[$v])) . ' <span class="mu">' . e(date('d/m/Y')) . '</span></h1><p class="np"><button type="button" data-print="1">🖨 הדפסה / שמירה כ-PDF</button> <a class="btn sec" href="exports.php">חזרה</a></p><div class="sc prt"><table><tr>';
  foreach ($head as $h) echo '<th>' . e($h) . '</th>'; echo '</tr>';
  foreach ($rows as $r) { echo '<tr>'; foreach ($r as $c) echo '<td>' . e($c) . '</td>'; echo '</tr>'; }
  echo '</table></div>'; page_foot(); exit;
}
page_head('ייצוא נתונים');
?>
<h1>ייצוא נתונים</h1>
<p class="mu">קובצי CSV נפתחים באקסל. תצוגת הדפסה מאפשרת להדפיס או לשמור כ-PDF מהדפדפן. כל ייצוא נרשם ביומן הפעולות.</p>
<div class="cards">
  <div class="card"><b>רשימת תלמידות</b><p class="mu"><?= $ids ? 'כולל ת.ז ותאריך לידה.' : 'ללא ת.ז.' ?></p><a class="btn" href="exports.php?v=students&f=csv">CSV</a> <a class="btn sec" href="exports.php?v=students&f=print">הדפסה / PDF</a></div>
<?php if ($col): ?>
  <div class="card"><b>גבייה לפי חודשים</b><p class="mu">מצב כל חודש לכל תיק, שולם ויתרה.</p><a class="btn" href="exports.php?v=collection&f=csv">CSV</a> <a class="btn sec" href="exports.php?v=collection&f=print">הדפסה / PDF</a></div>
  <div class="card"><b>חיובים חד-פעמיים</b><p class="mu">כל חיוב לכל תיק: סכום, הנחה, שולם וחסר.</p><a class="btn" href="exports.php?v=charges&f=csv">CSV</a> <a class="btn sec" href="exports.php?v=charges&f=print">הדפסה / PDF</a></div>
<?php endif; ?>
<?php if (can_edit()): ?><div class="card"><b>תבנית ייבוא תלמידות</b><p class="mu">קובץ לדוגמה עם הכותרות המדויקות.</p><a class="btn sec" href="import.php?template=1">הורדת תבנית</a></div><?php endif; ?>
</div>
<?php page_foot();
