<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'secretary']); $by = (string)$u['name'];
if (!docs_ready()) { page_head('מסמכים'); echo '<h1>מסמכים</h1><p class="aptn">כדי להפעיל את המסמכים יש להריץ "עדכון מסד נתונים" (תחזוקה).</p>'; page_foot(); exit; }
$msg = ''; $err = ''; $self = 'documents.php';
$back = (string)($_POST['back'] ?? ''); $back = preg_match('~^student\.php\?id=\d+(&open=[a-z,]+)?$~', $back) ? $back : '';
$done = function (bool $ok, string $t) use (&$msg, &$err, $back) { if ($back !== '') { $_SESSION['docmsg'] = [$ok, $t]; redirect($back); } if ($ok) $msg = $t; else $err = $t; };
$years = function (): array { $y = doc_year_start(); return [$y - 1, $y, $y + 1]; };
$pdfOut = function (string $pdf, string $name, bool $inline) { header('Content-Type: application/pdf'); header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name)); header('Content-Length: ' . strlen($pdf)); echo $pdf; exit; };
$ysel = function () use ($years): int { $y = (int)($_POST['year'] ?? 0); return in_array($y, $years(), true) ? $y : doc_year_start(); };
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? ''); $sid = (int)($_POST['student_id'] ?? 0);
  if ($act === 'tpl_save') {
    $id = (int)($_POST['tid'] ?? 0); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100); $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150); $body = trim((string)($_POST['body'] ?? ''));
    if ($name === '' || $title === '' || $body === '') $err = 'יש למלא שם תבנית, כותרת ותוכן.';
    else {
      $al = ($_POST['align'] ?? 'c') === 'r' ? 'r' : 'c'; $sg = isset($_POST['signed']) ? 1 : 0;
      if ($id > 0 && doc_template($id)) { db()->prepare('UPDATE doc_templates SET name=?, title=?, body=?, align=?, signed=?, updated_at=? WHERE id=?')->execute([$name, $title, $body, $al, $sg, now(), $id]); audit('doc_tpl_edit', 'doc', (string)$id, $name); }
      else { db()->prepare('INSERT INTO doc_templates (name,title,body,align,signed,builtin,created_at,updated_at) VALUES (?,?,?,?,?,0,?,?)')->execute([$name, $title, $body, $al, $sg, now(), now()]); $id = (int)db()->lastInsertId(); audit('doc_tpl_add', 'doc', (string)$id, $name); }
      $msg = 'התבנית "' . $name . '" נשמרה.';
    }
  } elseif ($act === 'tpl_del') {
    $t = doc_template((int)($_POST['tid'] ?? 0)); if ($t && !$t['builtin']) { db()->prepare('DELETE FROM doc_templates WHERE id = ?')->execute([$t['id']]); audit('doc_tpl_del', 'doc', (string)$t['id'], (string)$t['name']); $msg = 'התבנית נמחקה.'; } else $err = 'לא ניתן למחוק תבנית מובנית.';
  } elseif ($act === 'manual') {   // מסמך ידני לתלמידה: נשמר בהיסטוריה שלה
    $stu = doc_student($sid); $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150); $body = trim((string)($_POST['body'] ?? ''));
    if (!$stu) $done(false, 'התלמידה לא נמצאה.'); elseif ($title === '' || $body === '') $done(false, 'יש למלא כותרת ותוכן למסמך.');
    else {
      $tpl = ['id' => 0, 'name' => $title, 'title' => $title, 'body' => $body, 'align' => ($_POST['align'] ?? 'r') === 'c' ? 'c' : 'r', 'signed' => isset($_POST['signed']) ? 1 : 0];
      if (isset($_POST['save_tpl'])) { db()->prepare('INSERT INTO doc_templates (name,title,body,align,signed,builtin,created_at,updated_at) VALUES (?,?,?,?,?,0,?,?)')->execute([mb_substr($title, 0, 100), $title, $body, $tpl['align'], $tpl['signed'], now(), now()]); }
      $hid = doc_record($stu, $tpl, $ysel(), $by); audit('doc_manual', 'student', (string)$sid, $title);
      $done(true, 'המסמך "' . $title . '" נוצר ונשמר בהיסטוריה של התלמידה' . (isset($_POST['save_tpl']) ? ', וגם כתבנית' : '') . '.');
    }
  } elseif (in_array($act, ['h_view', 'h_dl', 'h_send'], true)) {   // מסמך מההיסטוריה
    $h = doc_hist_get((int)($_POST['hid'] ?? 0)); $stu = $h ? doc_student((int)$h['student_id']) : null;
    if (!$h || !$stu) $done(false, 'המסמך לא נמצא.');
    elseif ($act === 'h_send') { [$ok, $er] = doc_send(doc_hist_tpl($h), $stu, (int)$h['year'], (string)($_POST['email'] ?? ''), '', '', $by, (int)$h['id'], doc_hist_date($h)); audit('doc_send', 'student', (string)$stu['id'], $h['name'] . ' → ' . ($_POST['email'] ?? '')); $done($ok, $ok ? 'המסמך נשלח אל ' . trim((string)$_POST['email']) . '.' : 'השליחה נכשלה: ' . $er); }
    else $pdfOut(doc_pdf(doc_hist_tpl($h), $stu, (int)$h['year'], doc_hist_date($h)), doc_filename(doc_hist_tpl($h), $stu), $act === 'h_view');
  } elseif (in_array($act, ['view', 'dl', 'send'], true)) {   // הפקה מתבנית: תלמידה בודדת / כיתה / שכבה / כל התלמידות
    $tpl = doc_template((int)($_POST['tid'] ?? 0)); $aud = (string)($_POST['aud'] ?? ''); if (preg_match('/^s:(\d+)$/', $aud, $m)) { $sid = (int)$m[1]; }
    $list = $sid > 0 && ($aud === '' || $aud[0] === 's') ? array_filter([doc_student($sid)]) : doc_audience($aud); $ys = $ysel();
    if (!$tpl) $done(false, 'בחרי תבנית.'); elseif (!$list) $done(false, 'לא נמצאו תלמידות לבחירה.');
    elseif ($act === 'send') {
      $sub = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 190); $tx = trim((string)($_POST['msg'] ?? ''));
      if (count($list) === 1) {
        $s0 = reset($list); $to = trim((string)($_POST['email'] ?? '')) !== '' ? (string)$_POST['email'] : (string)$s0['email'];
        [$ok, $er] = doc_send($tpl, $s0, $ys, $to, $sub, $tx, $by); audit('doc_send', 'student', (string)$s0['id'], $tpl['name'] . ' → ' . $to);
        $done($ok, $ok ? '"' . $tpl['name'] . '" נשלח אל ' . trim($to) . '.' : 'השליחה נכשלה: ' . $er);
      } else {
        [$batch, $n, $skip] = doc_enqueue($tpl, $list, $ys, $sub, $tx, $by); audit('doc_send_batch', 'doc', $batch, $tpl['name'] . ": $n בתור, " . count($skip) . ' ללא מייל');
        $sent = doc_process(15); $left = (int)db()->query("SELECT COUNT(*) FROM doc_jobs WHERE status = 'pending'")->fetchColumn();
        $msg = "$n מסמכים הוכנסו לתור שליחה, נשלחו עד כה $sent" . ($left ? ". השאר ($left) יישלחו אוטומטית ברקע בדקות הקרובות" : '') . '.' . ($skip ? ' ללא כתובת מייל בכרטיס (' . count($skip) . '): ' . implode(', ', array_slice($skip, 0, 15)) . (count($skip) > 15 ? '…' : '') : '');
      }
    } elseif ($act === 'view' || count($list) === 1) {
      $s0 = reset($list); $pdf = doc_pdf($tpl, $s0, $ys);
      if ($act === 'dl') { doc_record($s0, $tpl, $ys, $by); audit('doc_download', 'student', (string)$s0['id'], (string)$tpl['name']); }
      $pdfOut($pdf, doc_filename($tpl, $s0), $act === 'view');
    } else {
      if (count($list) > 400) { $err = 'יותר מדי תלמידות להורדה אחת.'; }
      else {
        $zf = tempnam(sys_get_temp_dir(), 'dz'); $z = new ZipArchive(); $z->open($zf, ZipArchive::OVERWRITE);
        foreach ($list as $s) { $z->addFromString(doc_filename($tpl, $s), doc_pdf($tpl, $s, $ys)); doc_record($s, $tpl, $ys, $by); }
        $z->close(); audit('doc_download', 'doc', '', $tpl['name'] . ': ' . count($list) . ' תלמידות');
        header('Content-Type: application/zip'); header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($tpl['name'] . '.zip')); header('Content-Length: ' . filesize($zf)); readfile($zf); @unlink($zf); exit;
      }
    }
  } elseif ($act === 'process') { $n = doc_process(20); $msg = "נשלחו $n מסמכים מהתור."; }
}
doc_lazy();
$tpls = doc_templates(); $edit = isset($_GET['edit']) ? (int)$_GET['edit'] : -1; $et = $edit > 0 ? doc_template($edit) : null;
$classes = db()->query("SELECT grade, cls, COUNT(*) n FROM students WHERE status = 'active' AND archived_at IS NULL GROUP BY grade, cls ORDER BY grade, cls")->fetchAll();
$studs = db()->query("SELECT id, name, grade, cls FROM students WHERE status = 'active' AND archived_at IS NULL ORDER BY name")->fetchAll();
$q = db()->query("SELECT status, COUNT(*) n FROM doc_jobs GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR); $pend = (int)($q['pending'] ?? 0);
$jobs = db()->query("SELECT j.*, s.name sname, t.name tname FROM doc_jobs j LEFT JOIN students s ON s.id = j.student_id LEFT JOIN doc_templates t ON t.id = j.template_id ORDER BY j.id DESC LIMIT 40")->fetchAll();
page_head('מסמכים');
?>
<h1>מסמכים</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<p class="mu">מסמכים רשמיים לתלמידות על נייר המכתבים של עלמא, חתומים אוטומטית. אפשר להפיק לתלמידה אחת (גם מכרטיס התלמידה), לכיתה, לשכבה או לכל התלמידות, להוריד כ-PDF (לכמה תלמידות: קובץ ZIP) או לשלוח במייל לכתובת שבכרטיס התלמידה.</p>

<form method="post" class="card docf" id="gen"><?= csrf_field() ?>
  <h2>הפקה ושליחה</h2>
  <div class="fg">
    <label>מסמך<select name="tid" required><?php foreach ($tpls as $t) echo '<option value="' . (int)$t['id'] . '">' . e($t['name']) . '</option>'; ?></select></label>
    <label>שנת לימודים<select name="year"><?php foreach ($years() as $y) echo '<option value="' . $y . '"' . ($y === doc_year_start() ? ' selected' : '') . '>' . doc_heb_year($y) . ' (' . $y . '-' . ($y + 1) . ')</option>'; ?></select></label>
    <label>עבור<select name="aud" required>
      <option value="all">כל התלמידות (<?= count($studs) ?>)</option>
      <optgroup label="שכבה"><?php foreach (GRADES as $g) { $n = array_sum(array_map(fn($c) => $c['grade'] === $g ? (int)$c['n'] : 0, $classes)); if ($n) echo '<option value="g:' . e($g) . '">שכבה ' . e($g) . ' (' . $n . ')</option>'; } ?></optgroup>
      <optgroup label="כיתה"><?php foreach ($classes as $c) echo '<option value="c:' . e($c['grade']) . '|' . e($c['cls']) . '">כיתה ' . e(class_label((string)$c['grade'], (string)$c['cls'])) . ' (' . (int)$c['n'] . ')</option>'; ?></optgroup>
      <optgroup label="תלמידה בודדת"><?php foreach ($studs as $s) echo '<option value="s:' . (int)$s['id'] . '">' . e($s['name']) . ' – ' . e(class_label((string)$s['grade'], (string)$s['cls'])) . '</option>'; ?></optgroup>
    </select></label>
    <label>מייל לשליחה (אופציונלי, לתלמידה בודדת בלבד; אחרת נשלח לכתובת שבכרטיס)<input type="email" name="email" maxlength="150" dir="ltr"></label>
  </div>
  <details><summary>נוסח המייל (אופציונלי)</summary>
    <label>נושא<input name="subject" maxlength="190" placeholder="ברירת מחדל: שם המסמך – שם התלמידה"></label>
    <label>תוכן<textarea name="msg" rows="4" placeholder="ברירת מחדל: מצורף {שם המסמך} עבור שם התלמידה. אפשר להשתמש ב-{שם}, {כיתה}, {שנה_עברית}"></textarea></label>
  </details>
  <p class="bar"><button name="act" value="view" class="sec" formtarget="_blank">תצוגה מקדימה</button> <button name="act" value="dl" class="sec">הורדה (PDF / ZIP)</button> <button name="act" value="send" data-confirm="לשלוח את המסמך במייל לתלמידות שנבחרו?">שליחה במייל</button></p>
  <p class="mu">שליחה לכיתה / שכבה / כולן יוצאת ברקע, מסמך אחד לכל תלמידה, לכתובת המייל שבכרטיס שלה. תלמידות בלי כתובת מייל מדולגות ומוצגות ברשימה.</p>
</form>

<div class="card"><h2>תבניות מסמכים</h2>
  <table><tr><th>שם</th><th>כותרת</th><th>חתימה</th><th></th></tr>
  <?php foreach ($tpls as $t): ?><tr><td><?= e($t['name']) ?><?= $t['builtin'] ? ' <span class="mu">(מובנה)</span>' : '' ?></td><td><?= e($t['title']) ?></td><td><?= $t['signed'] ? 'אוטומטית' : '—' ?></td>
    <td><a class="btn sec" href="documents.php?edit=<?= (int)$t['id'] ?>#tpl">עריכה</a>
    <?php if (!$t['builtin']): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="tpl_del"><input type="hidden" name="tid" value="<?= (int)$t['id'] ?>"><button class="dg" data-confirm="למחוק את התבנית?">מחיקה</button></form><?php endif; ?></td></tr><?php endforeach; ?></table>
  <p><a class="btn" href="documents.php?edit=0#tpl">+ מסמך חדש (עריכה ידנית)</a></p>
</div>
<?php if ($edit >= 0 && ($edit === 0 || $et)): $t = $et ?: ['id' => 0, 'name' => '', 'title' => '', 'body' => '', 'align' => 'r', 'signed' => 1]; ?>
<form method="post" class="card docf" id="tpl"><?= csrf_field() ?><input type="hidden" name="act" value="tpl_save"><input type="hidden" name="tid" value="<?= (int)$t['id'] ?>">
  <h2><?= $t['id'] ? 'עריכת תבנית' : 'מסמך חדש' ?></h2>
  <div class="fg"><label>שם התבנית (לרשימה)<input name="name" required maxlength="100" value="<?= e($t['name']) ?>"></label>
  <label>כותרת המסמך<input name="title" required maxlength="150" value="<?= e($t['title']) ?>"></label>
  <label>יישור הטקסט<select name="align"><option value="r"<?= $t['align'] === 'r' ? ' selected' : '' ?>>לימין (מסמך ארוך)</option><option value="c"<?= $t['align'] === 'c' ? ' selected' : '' ?>>למרכז (כמו אישור לימודים)</option></select></label></div>
  <label>תוכן המסמך (כל שורה היא פסקה)<textarea name="body" rows="9" required><?= e($t['body']) ?></textarea></label>
  <p class="mu">אפשר להכניס פרטי תלמידה אוטומטית: <?php foreach (DOC_PLACEHOLDERS as $k => $v) echo '<bdi dir="ltr"><code>' . e($k) . '</code></bdi> ' . e($v) . ' · '; ?></p>
  <label class="chk"><input type="checkbox" name="signed" value="1"<?= $t['signed'] ? ' checked' : '' ?>> חתימה וחותמת אוטומטית של ההנהלה</label>
  <p class="bar"><button>שמירה</button> <a class="btn sec" href="documents.php">ביטול</a></p>
</form>
<?php endif; ?>

<div class="card"><h2>שליחות אחרונות</h2>
  <?php if ($pend): ?><p class="aptn"><?= $pend ?> מסמכים ממתינים לשליחה (נשלחים ברקע). <form method="post" class="inl"><?= csrf_field() ?><button name="act" value="process" class="sec">שליחה עכשיו</button></form></p><?php endif; ?>
  <?php if ($jobs): ?><div class="sc"><table><tr><th>תאריך</th><th>תלמידה</th><th>מסמך</th><th>אל</th><th>מצב</th></tr>
  <?php $L = ['pending' => 'ממתין', 'sending' => 'נשלח…', 'sent' => 'נשלח', 'failed' => 'נכשל']; foreach ($jobs as $j): ?><tr><td class="n"><?= e(date('d/m/Y H:i', strtotime((string)$j['created_at']))) ?></td><td><?= e((string)$j['sname']) ?></td><td><?= e((string)$j['tname']) ?></td><td dir="ltr"><?= e($j['to_email']) ?></td><td><?= e($L[$j['status']] ?? $j['status']) ?><?= $j['err'] !== '' ? ' <span class="er">' . e($j['err']) . '</span>' : '' ?></td></tr><?php endforeach; ?></table></div>
  <?php else: ?><p class="emp">עוד לא נשלחו מסמכים בשליחה קבוצתית.</p><?php endif; ?>
</div>
<?php page_foot();
