<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'secretary']);
$isAdmin = $u['role'] === 'admin';
$pdo = db(); $err = ''; $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? ''); $lid = (int)($_POST['id'] ?? 0);
  $q = $pdo->prepare('SELECT * FROM sibling_leads WHERE id = ?'); $q->execute([$lid]); $L = $q->fetch();
  if (!$L) fail_page(404, 'הרשומה לא נמצאה.');
  $back = 'leads.php?' . http_build_query(array_filter((array)($_POST['back'] ?? []), fn($v) => $v !== ''));
  if ($act === 'track') {
    $st = (string)($_POST['status'] ?? 'new'); if (!isset(LEAD_STATUS[$st])) $st = 'new';
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
    $pdo->prepare('UPDATE sibling_leads SET status = ?, note = ?, updated_at = ? WHERE id = ?')->execute([$st, $note, now(), $lid]);
    audit('lead_track', 'lead', (string)$lid, $st);
    redirect($back . '&m=' . rawurlencode('נשמר.'));
  }
  if ($act === 'edit') {
    [$r, $e1] = lead_clean(['idn' => $L['idn'], 'ln' => $_POST['ln'] ?? '', 'fn' => $_POST['fn'] ?? '', 'dob' => $_POST['dob'] ?? '', 'g' => $_POST['g'] ?? ''], 'manual', []);
    if (!$r) redirect($back . '&e=' . rawurlencode($e1));
    $pdo->prepare('UPDATE sibling_leads SET last_name = ?, first_name = ?, dob = ?, gender = ?, updated_at = ? WHERE id = ?')->execute([$r['ln'], $r['fn'], $r['dob'] ?: null, $r['g'], now(), $lid]);
    audit('lead_edit', 'lead', (string)$lid, '');
    redirect($back . '&m=' . rawurlencode('הפרטים עודכנו.'));
  }
  if ($act === 'delete' && $isAdmin) {
    $pdo->prepare('DELETE FROM sibling_leads WHERE id = ?')->execute([$lid]); audit('lead_delete', 'lead', (string)$lid, $L['idn']);
    redirect($back . '&m=' . rawurlencode('נמחק מהמאגר.'));
  }
  redirect($back);
}
// סינון
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'g' => (string)($_GET['g'] ?? ''), 'y' => (string)($_GET['y'] ?? ''), 'st' => (string)($_GET['st'] ?? ''), 'sh' => (string)($_GET['sh'] ?? 'hide')];
$w = []; $p = [];
if ($f['q'] !== '') {
  $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $f['q']) . '%';
  $w[] = "(CONCAT(l.first_name, ' ', l.last_name) LIKE ? OR l.idn LIKE ? OR EXISTS (SELECT 1 FROM sibling_lead_links k JOIN students s ON s.id = k.student_id WHERE k.lead_id = l.id AND s.name LIKE ?))"; array_push($p, $like, $like, $like);
}
if (isset(LEAD_GENDER[$f['g']])) { $w[] = 'l.gender = ?'; $p[] = $f['g']; }
if (preg_match('/^\d{4}$/', $f['y'])) { $w[] = 'YEAR(l.dob) = ?'; $p[] = (int)$f['y'] - 12; }
if (isset(LEAD_STATUS[$f['st']])) { $w[] = 'l.status = ?'; $p[] = $f['st']; }
if ($f['sh'] !== 'show') $w[] = 'NOT EXISTS (SELECT 1 FROM students s2 WHERE s2.idn = l.idn AND s2.archived_at IS NULL)';
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$sql = "SELECT l.*, (SELECT COUNT(*) FROM students s3 WHERE s3.idn = l.idn AND s3.archived_at IS NULL) AS is_student FROM sibling_leads l $where ORDER BY (l.dob IS NULL), l.dob ASC, l.id ASC";
$all = $pdo->prepare($sql); $all->execute($p); $rows = $all->fetchAll();
$ids = array_column($rows, 'id'); $sis = [];
if ($ids) {
  $in = implode(',', array_map('intval', $ids));
  foreach ($pdo->query("SELECT k.lead_id, s.id, s.name, s.f_phone, s.m_phone, s.grade FROM sibling_lead_links k JOIN students s ON s.id = k.student_id WHERE k.lead_id IN ($in) ORDER BY s.name")->fetchAll() as $r) $sis[$r['lead_id']][] = $r;
}
if (!empty($_GET['csv'])) {
  audit('leads_export', 'lead', '', count($rows) . ' שורות');
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="sibling-leads-' . date('Y-m-d') . '.csv"'); header('Cache-Control: no-store');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  $cell = function ($v): string { $v = bidi_fix((string)$v); return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v; };
  fputcsv($o, ['שם פרטי', 'שם משפחה', 'ת״ז', 'תאריך לידה', 'מין', 'היום בכיתה', 'כניסה לז׳', 'אחות של', 'טלפון הורים', 'סטטוס', 'הערה']);
  foreach ($rows as $l) {
    $ey = lead_entry_year((string)$l['dob']); $ss = $sis[$l['id']] ?? [];
    fputcsv($o, array_map($cell, [$l['first_name'], $l['last_name'], $l['idn'], $l['dob'] ? date('d/m/Y', strtotime($l['dob'])) : '', LEAD_GENDER[$l['gender']] ?? '', lead_grade_now((string)$l['dob']), $ey ? heb_school_year($ey) : '', implode(' / ', array_column($ss, 'name')), implode(' / ', array_unique(array_filter([$ss[0]['f_phone'] ?? '', $ss[0]['m_phone'] ?? '']))), LEAD_STATUS[$l['status']] ?? '', $l['note']]));
  }
  fclose($o); exit;
}
// נתונים לסינון ולסיכום
$years = $pdo->query('SELECT DISTINCT YEAR(dob) + 12 y FROM sibling_leads WHERE dob IS NOT NULL ORDER BY y')->fetchAll(PDO::FETCH_COLUMN);
$tot = (int)$pdo->query('SELECT COUNT(*) FROM sibling_leads')->fetchColumn();
$nowY = (int)date('Y') + ((int)date('n') >= 9 ? 1 : 0);   // שנת הלימודים הקרובה (תחילתה)
$soon = (int)$pdo->query("SELECT COUNT(*) FROM sibling_leads WHERE dob IS NOT NULL AND YEAR(dob) + 12 BETWEEN $nowY AND " . ($nowY + 1) . " AND gender <> 'm'")->fetchColumn();
$contacted = (int)$pdo->query("SELECT COUNT(*) FROM sibling_leads WHERE status = 'contacted'")->fetchColumn();
if (!empty($_GET['m'])) $msg = mb_substr((string)$_GET['m'], 0, 300);
if (!empty($_GET['e'])) $err = mb_substr((string)$_GET['e'], 0, 300);
$back = array_filter($f, fn($v) => $v !== '');
page_head('מאגר אחים');
?>
<h1>מאגר אחים ואחיות</h1>
<p class="mu">אחים ואחיות של התלמידות, כפי שנקראו מהספח. מאגר של תלמידות פוטנציאליות לרישום בהמשך. הנתונים רגישים: צפייה לשימוש הצוות בלבד.</p>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="tiles">
  <div class="tile"><span class="mu">במאגר</span><b><?= $tot ?></b></div>
  <div class="tile g"><span class="mu">בנות שנכנסות לז׳ בשנתיים הקרובות</span><b><?= $soon ?></b></div>
  <div class="tile"><span class="mu">נוצר קשר</span><b><?= $contacted ?></b></div>
</div>
<form class="bar auto" method="get" action="leads.php">
  <label>חיפוש<input name="q" value="<?= e($f['q']) ?>" placeholder="שם, ת״ז או שם האחות"></label>
  <label>מין<select name="g"><option value="">הכול</option><?php foreach (LEAD_GENDER as $k => $v) echo '<option value="' . $k . '"' . ($f['g'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <label>כניסה לז׳ בשנת<select name="y"><option value="">הכול</option><?php foreach ($years as $y) echo '<option value="' . (int)$y . '"' . ($f['y'] === (string)$y ? ' selected' : '') . '>' . e(heb_school_year((int)$y)) . ' (' . (int)$y . '/' . substr((string)((int)$y + 1), 2) . ')</option>'; ?></select></label>
  <label>סטטוס<select name="st"><option value="">הכול</option><?php foreach (LEAD_STATUS as $k => $v) echo '<option value="' . $k . '"' . ($f['st'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <label>כבר תלמידות בעלמא<select name="sh"><option value="hide">להסתיר</option><option value="show"<?= $f['sh'] === 'show' ? ' selected' : '' ?>>להציג</option></select></label>
  <button>הצגה</button>
  <a class="btn sec" href="leads.php?<?= e(http_build_query($back + ['csv' => 1])) ?>">ייצוא ל-CSV</a>
</form>
<p class="mu"><?= count($rows) ?> רשומות</p>
<div class="sc"><table class="ltab">
  <tr><th>שם</th><th>ת״ז</th><th>תאריך לידה</th><th>מין</th><th>היום בכיתה</th><th>אחות של</th><th>טלפון הורים</th><th>מעקב</th></tr>
  <?php foreach ($rows as $l):
    $ey = lead_entry_year((string)$l['dob']); $ss = $sis[$l['id']] ?? [];
    $age = $l['dob'] ? (int)floor((time() - strtotime($l['dob'])) / 31557600) : null;
    $ph = array_unique(array_filter([$ss[0]['f_phone'] ?? '', $ss[0]['m_phone'] ?? ''])); ?>
  <tr<?= $l['is_student'] ? ' class="futrow"' : '' ?>>
    <td><b><?= e(trim($l['first_name'] . ' ' . $l['last_name']) ?: 'שם לא נקרא') ?></b><?= $l['is_student'] ? ' <span class="chip">כבר בעלמא</span>' : '' ?></td>
    <td dir="ltr"><?= e($l['idn']) ?></td>
    <td><?= $l['dob'] ? e(date('d/m/Y', strtotime($l['dob']))) . ' <span class="mu">(' . $age . ')</span>' : '<span class="mu">—</span>' ?></td>
    <td><?= e(LEAD_GENDER[$l['gender']] ?? '—') ?></td>
    <td><?= e(lead_grade_now((string)$l['dob']) ?: '—') ?><?= $ey ? '<br><span class="mu">ז׳ ב' . e(heb_school_year($ey)) . '</span>' : '' ?></td>
    <td><?php foreach ($ss as $i => $s) echo ($i ? ', ' : '') . '<a href="student.php?id=' . (int)$s['id'] . '">' . e($s['name']) . '</a>'; ?></td>
    <td dir="ltr"><?= e(implode(' / ', array_map('fmt_phone', $ph))) ?: '—' ?></td>
    <td>
      <form method="post" class="inl lt"><?= csrf_field() ?><input type="hidden" name="act" value="track"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <?php foreach ($back as $k => $v) echo '<input type="hidden" name="back[' . e($k) . ']" value="' . e($v) . '">'; ?>
        <select name="status"><?php foreach (LEAD_STATUS as $k => $v) echo '<option value="' . $k . '"' . ($l['status'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select>
        <input name="note" maxlength="500" value="<?= e($l['note']) ?>" placeholder="הערה"><button class="btn sec">שמירה</button></form>
      <details class="mt"><summary class="mu">עריכת פרטים</summary>
        <form method="post" class="fg"><?= csrf_field() ?><input type="hidden" name="act" value="edit"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <?php foreach ($back as $k => $v) echo '<input type="hidden" name="back[' . e($k) . ']" value="' . e($v) . '">'; ?>
          <label>שם פרטי<input name="fn" maxlength="60" value="<?= e($l['first_name']) ?>"></label><label>שם משפחה<input name="ln" maxlength="60" value="<?= e($l['last_name']) ?>"></label>
          <label>תאריך לידה<input type="date" name="dob" dir="ltr" value="<?= e((string)$l['dob']) ?>"></label>
          <label>מין<select name="g"><option value="">—</option><?php foreach (LEAD_GENDER as $k => $v) echo '<option value="' . $k . '"' . ($l['gender'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
          <div class="acts"><button class="btn">עדכון</button></div></form>
        <?php if ($isAdmin): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><?php foreach ($back as $k => $v) echo '<input type="hidden" name="back[' . e($k) . ']" value="' . e($v) . '">'; ?><button class="btn sec er">מחיקה מהמאגר</button></form><?php endif; ?>
      </details>
    </td>
  </tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="8" class="mu">אין רשומות להצגה. הוספה נעשית מכרטיס תלמידה: "קריאת ספח" בקטע מסמכים, או בהקלדה ידנית.</td></tr>'; ?>
</table></div>
<?php page_foot();
