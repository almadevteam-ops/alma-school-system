<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_login();
$isAdmin = $u['role'] === 'admin';
// key => [label, maxlen, sensitive]
$F = [
  'name' => ['שם מלא', 100, 0], 'grade' => ['כיתה', 4, 0], 'cls' => ['מחזור/כיתה מפורטת', 8, 0],
  'idn' => ['תעודת זהות', 9, 1], 'dob' => ['תאריך לידה', 12, 0], 'city' => ['עיר', 80, 0], 'addr' => ['רחוב', 200, 0], 'house_no' => ['מספר בית', 12, 0],
  'email' => ['אימייל', 150, 0], 'phone' => ['טלפון התלמידה', 30, 0],
  'f_name' => ['שם האב', 80, 0], 'f_phone' => ['טלפון האב', 30, 0], 'f_id' => ['ת״ז האב', 9, 1],
  'm_name' => ['שם האם', 80, 0], 'm_phone' => ['טלפון האם', 30, 0], 'm_id' => ['ת״ז האם', 9, 1],
  'hmo' => ['קופת חולים', 30, 0],
];
const ID_FIELDS = ['idn', 'f_id', 'm_id'];
const PHONE_FIELDS = ['phone', 'f_phone', 'm_phone'];
/** שדות שרק מנהלת מערכת יכולה לערוך בכרטיס של תלמידה קיימת. */
const REQUIRED = ['name', 'grade'];
const ADMIN_ONLY = ['name', 'idn', 'f_name', 'm_name', 'f_id', 'm_id'];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$err = ''; $msg = '';
$row = null;
if ($id) {
  $st = db()->prepare('SELECT * FROM students WHERE id = ?'); $st->execute([$id]); $row = $st->fetch();
  if (!$row) fail_page(404, 'התלמידה לא נמצאה.');
}
/** שולחת מייל לצוות כשנוצרת תלמידה חדשה. הנמענים ב-config: new_student_notify (כתובת או כמה כתובות מופרדות בפסיק). */
function notify_new_student(array $d, int $nid, array $by): void {
  $to = array_filter(array_map('trim', explode(',', (string)cfg('new_student_notify', ''))));
  if (!$to) return;
  $link = 'https://' . (string)cfg('host', 'alma-school.co.il') . '/app/student.php?id=' . $nid;
  $lines = ['נוספה תלמידה חדשה למערכת.', '', 'שם: ' . $d['name'], 'כיתה: ' . $d['grade'] . ($d['cls'] !== '' ? ' (' . $d['cls'] . ')' : ''),
    'כתובת: ' . trim(trim($d['addr'] . ' ' . $d['house_no']) . ', ' . $d['city'], ' ,'), 'אב: ' . trim($d['f_name'] . ' ' . fmt_phone($d['f_phone'])), 'אם: ' . trim($d['m_name'] . ' ' . fmt_phone($d['m_phone'])),
    'נוצרה על ידי: ' . ($by['name'] ?: $by['username']), '', 'לכרטיס התלמידה (נדרשת התחברות): ' . $link];
  $sent = 0; $fail = [];
  foreach ($to as $addr) { if (send_mail($addr, 'תלמידה חדשה נוספה: ' . $d['name'], implode("\n", $lines))) $sent++; else $fail[] = $addr . ' (' . mail_last_error() . ')'; }
  audit($fail ? 'new_student_mail_failed' : 'new_student_mail', 'student', (string)$nid, "נשלח $sent" . ($fail ? '; נכשל: ' . implode(', ', $fail) : ''));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? 'save');
  if ($act === 'archive') {
    require_role(['admin']);
    if (!$row || empty($_POST['ok'])) fail_page(400, 'נדרש אישור להעברה לארכיון.');
    $who = mb_substr((string)($u['name'] ?: $u['username']), 0, 100);
    db()->prepare('UPDATE students SET archived_at = ?, archived_by = ? WHERE id = ?')->execute([now(), $who, $id]);
    db()->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)')->execute([$id, 'הועברה לארכיון בתאריך ' . date('d/m/Y') . ' ע"י ' . $who, $u['id'], $who, now()]);
    audit('archive', 'student', (string)$id, $row['name']);
    redirect('students.php');
  }
  if (!can_edit()) fail_page(403, 'אין לך הרשאת עריכה.');
  $d = [];
  foreach ($F as $k => [$lbl, $max]) $d[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
  // שדות נעולים לא-מנהלת: הערך הקיים נשמר, גם אם נשלח משהו אחר
  if ($row && !$isAdmin) foreach (ADMIN_ONLY as $k) $d[$k] = (string)$row[$k];
  if (!can_see_ids()) foreach (ID_FIELDS as $k) $d[$k] = (string)($row[$k] ?? '');
  foreach (ID_FIELDS as $k) $d[$k] = norm_id($d[$k]);
  $dobIn = $d['dob'];
  $d['dob'] = dob_iso($dobIn);
  if ($d['dob'] === '' && $dobIn === '' && $row && $row['dob'] !== '' && dob_iso((string)$row['dob']) === '') $d['dob'] = (string)$row['dob'];   // תאריך ישן שלא ניתן לפענוח לא נמחק
  $d['house_no'] = preg_replace('/\s+/u', '', $d['house_no']) ?? $d['house_no'];
  foreach (PHONE_FIELDS as $k) $d[$k] = norm_phone($d[$k]);
  $d['hmo'] = $d['hmo'] === '' ? HMO_UNKNOWN : $d['hmo'];
  $oldStatus = $row['status'] ?? 'active';
  $d['status'] = ($isAdmin && isset(STATUS[$_POST['status'] ?? ''])) ? $_POST['status'] : $oldStatus;
  $statusNote = mb_substr(trim((string)($_POST['status_note'] ?? '')), 0, NOTE_MAX_LEN);
  $d['marital'] = ($_POST['marital'] ?? '') === 'divorced' ? 'divorced' : 'married';
  $chg = fn(string $k) => !$row || (string)$row[$k] !== (string)$d[$k];   // בדיקות תקינות רק על מה ששונה (נתונים ישנים לא חוסמים שמירה)
  if ($d['name'] === '') $err = 'נא למלא שם.';
  elseif (!in_array($d['grade'], GRADES, true)) $err = 'נא לבחור כיתה.';
  elseif ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err = 'כתובת האימייל לא תקינה.';
  elseif ($dobIn !== '' && dob_iso($dobIn) === '') $err = 'תאריך הלידה לא תקין.';
  elseif ($d['dob'] !== '' && dob_iso($d['dob']) !== '' && $d['dob'] > date('Y-m-d')) $err = 'תאריך הלידה לא יכול להיות בעתיד.';
  else {
    foreach (ID_FIELDS as $k) if ($d[$k] !== '' && $chg($k) && (!preg_match('/^\d{9}$/', $d[$k]) || !valid_il_id($d[$k]))) { $err = $F[$k][0] . ': יש להקליד מספר זהות תקין.'; break; }
    if (!$err) foreach (PHONE_FIELDS as $k) if ($d[$k] !== '' && $chg($k) && !phone_ok($d[$k])) { $err = $F[$k][0] . ': מספר טלפון לא תקין (לדוגמה 050-1234567 או 04-1234567).'; break; }
    if (!$err) $err = geo_check($d['city'], $d['addr'], $d['house_no'], $chg('city'), $chg('addr') || $chg('city'), $chg('house_no') || $chg('addr')) ?? '';
    if (!$err && $chg('hmo') && !in_array($d['hmo'], [...HMO_LIST, HMO_UNKNOWN], true)) $err = 'נא לבחור קופת חולים מהרשימה.';
    if (!$err && $row && $d['status'] !== $oldStatus && $statusNote === '') $err = 'שינוי סטטוס מחייב הערה: נא לכתוב את הסיבה.';
  }
  if (!$err) {
    if ($id) {
      $changed = [];
      foreach ($d as $k => $v) if ((string)($row[$k] ?? '') !== (string)$v) $changed[] = $k;
      $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($d)));
      db()->prepare("UPDATE students SET $set, updated_at = ? WHERE id = ?")->execute([...array_values($d), now(), $id]);
      if ($d['status'] !== $oldStatus) {
        db()->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)')->execute([$id, 'שינוי סטטוס: ' . (STATUS[$oldStatus] ?? $oldStatus) . ' ← ' . (STATUS[$d['status']] ?? $d['status']) . "\nסיבה: " . $statusNote, $u['id'], mb_substr((string)($u['name'] ?: $u['username']), 0, 100), now()]);
        audit('status_change', 'student', (string)$id, $oldStatus . '→' . $d['status']);
      }
      audit('update', 'student', (string)$id, 'שדות: ' . implode(',', $changed));
      redirect('student.php?id=' . $id . '&saved=1');
    } else {
      $d['status'] = 'active';
      $cols = implode(',', array_keys($d));
      $ph = implode(',', array_fill(0, count($d), '?'));
      db()->prepare("INSERT INTO students ($cols, created_at, updated_at) VALUES ($ph, ?, ?)")->execute([...array_values($d), now(), now()]);
      $nid = (int)db()->lastInsertId();
      audit('create', 'student', (string)$nid, $d['name']);
      try { notify_new_student($d, $nid, $u); } catch (Throwable $t) { audit('new_student_mail_failed', 'student', (string)$nid, mb_substr($t->getMessage(), 0, 200)); }
      redirect('student.php?id=' . $nid . '&saved=1');
    }
  }
  $row = array_merge($row ?: [], $d);   // keep typed values on error
  $row['status_note'] = $statusNote;
}
if (!empty($_GET['saved'])) $msg = 'נשמר.';
if (!empty($_GET['m'])) $msg = mb_substr((string)$_GET['m'], 0, 300);
if (!empty($_GET['e'])) $err = mb_substr((string)$_GET['e'], 0, 500);
if ($row && $_SERVER['REQUEST_METHOD'] === 'GET' && can_see_ids()) audit('view', 'student', (string)$id, 'צפייה בכרטיס');
$ro = !can_edit();
$sfiles = [];
if ($id && can_see_ids()) { $q = db()->prepare('SELECT id, kind, orig_name, mime, size, created_at FROM student_files WHERE student_id = ? ORDER BY id'); $q->execute([$id]); $sfiles = $q->fetchAll(); }
$val = fn($k) => $row[$k] ?? '';
page_head($row ? $row['name'] : 'תלמידה חדשה');
?>
<?php if ($row && $id): ?><div class="sub"><div class="in"><a href="students.php">‹ חזרה לתלמידות</a><b class="nm"><?= e($row['name']) ?></b><span class="mu">כיתה <?= e(trim($row['grade'] . ' ' . ($row['cls'] ?? ''))) ?></span><span class="gap"></span><span class="pill <?= e($row['status']) ?>"><?= e(STATUS[$row['status']] ?? $row['status']) ?></span></div></div><?php endif; ?>
<h1><?= $row ? e($row['name']) : 'תלמידה חדשה' ?></h1>
<?php if (!empty($row['archived_at'])) echo '<p class="aptn">התלמידה בארכיון (מאז ' . e(date('d/m/Y', strtotime((string)$row['archived_at']))) . '). כל הרשומות שלה נשמרו.' . ($isAdmin ? ' <a href="archive.php">לדף הארכיון</a>' : '') . '</p>'; ?>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<?php sec_open('details', 'פרטי תלמידה', trim(($row['grade'] ?? '') . ' ' . ($row['cls'] ?? '')) !== '' ? 'כיתה ' . trim(($row['grade'] ?? '') . ' ' . ($row['cls'] ?? '')) : '', '☺', ['details']); ?>
<form method="post" class="dirty" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="fg">
  <?php foreach ($F as $k => [$lbl, $max, $sens]):
    if ($sens && !can_see_ids()) continue;
    $lock = $ro || ($id && !$isAdmin && in_array($k, ADMIN_ONLY, true));
    $dis = $lock ? ' disabled' : '';
    $req = in_array($k, REQUIRED, true) && !$lock; $star = in_array($k, REQUIRED, true) ? ' <span class="req" title="שדה חובה">*</span>' : '';
    $reqa = $req ? ' required' : '';
    if ($k === 'grade') {
      echo '<label><span>' . e($lbl) . $star . '</span><select name="grade"' . ($ro ? ' disabled' : '') . $reqa . '><option value=""></option>';
      foreach (GRADES as $x) echo '<option' . ($val('grade') === $x ? ' selected' : '') . '>' . e($x) . '</option>';
      echo '</select></label>'; continue;
    }
    if ($k === 'hmo') {
      $cur = (string)($val('hmo') ?: HMO_UNKNOWN);
      $opts = [...HMO_LIST, HMO_UNKNOWN]; if (!in_array($cur, $opts, true)) $opts[] = $cur;
      echo '<label>' . e($lbl) . '<select name="hmo"' . ($ro ? ' disabled' : '') . '>';
      foreach ($opts as $x) echo '<option' . ($cur === $x ? ' selected' : '') . '>' . e($x) . '</option>';
      echo '</select></label>'; continue;
    }
    $v = (string)$val($k); $attr = '';
    $geo = geo_ready() && !$ro;
    if (in_array($k, ID_FIELDS, true)) $attr = ' inputmode="numeric" data-digits="1" data-init="' . e($v) . '" dir="ltr"';
    elseif (in_array($k, PHONE_FIELDS, true)) { $v = fmt_phone($v); $attr = ' type="tel" inputmode="tel" dir="ltr"'; }
    elseif ($k === 'dob') {
      $iso = dob_iso($v); $attr = ' type="date" min="1990-01-01" max="' . date('Y-m-d') . '" dir="ltr"';
      if ($v !== '' && $iso === '') $star .= ' <span class="mu">(קיים: ' . e($v) . ', יש לבחור תאריך)</span>';
      $v = $iso;
    }
    elseif ($k === 'city' && $geo) $attr = ' data-geo="city" list="geo-cities" placeholder="הקלידי ובחרי מהרשימה"';
    elseif ($k === 'addr' && $geo) $attr = ' data-geo="street" list="geo-streets" placeholder="בחרי רחוב מהרשימה"';
    elseif ($k === 'house_no') $attr = ($geo ? ' data-geo="house"' : '') . ' placeholder="למשל 12 או 12א" dir="ltr"';
    echo '<label><span>' . e($lbl) . $star . '</span><input name="' . $k . '" maxlength="' . ($max > 9 && in_array($k, ID_FIELDS, true) ? 9 : $max) . '" value="' . e($v) . '"' . $attr . $dis . $reqa . '></label>';
  endforeach; ?>
  <label>מצב הורים<select name="marital"<?= $ro ? ' disabled' : '' ?>><option value="married">נשואים</option><option value="divorced"<?= $val('marital') === 'divorced' ? ' selected' : '' ?>>גרושים</option></select></label>
  <label>סטטוס<select name="status" data-orig="<?= e($val('status') ?: 'active') ?>"<?= ($ro || !$isAdmin || !$id) ? ' disabled' : '' ?>><?php foreach (STATUS as $k => $v) echo '<option value="' . $k . '"' . (($val('status') ?: 'active') === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <?php if ($isAdmin && $id): ?><label class="wide" id="stnote"<?= (($val('status_note') ?? '') === '') ? ' hidden' : '' ?>>סיבת שינוי הסטטוס (חובה)<input name="status_note" maxlength="500" value="<?= e((string)($row['status_note'] ?? '')) ?>" placeholder="למשל: עברה למוסד אחר"></label><?php endif; ?>
  </div>
  <?php if (geo_ready() && !$ro): ?><datalist id="geo-cities"></datalist><datalist id="geo-streets"></datalist><p class="mu">כתובת: קודם בוחרים עיר מהרשימה, אחר כך רחוב מהרשימה, ורק אז מקלידים מספר בית.</p><?php endif; ?>
  <?php if (!$ro): ?><p class="mu"><span class="req">*</span> שדה חובה</p><?php endif; ?>
  <div class="acts">
  <?php if (!$ro): ?><button class="sv" disabled>שמירה</button><?php endif; ?>
  <a class="btn sec" href="<?= $id ? 'student.php?id=' . (int)$id : 'students.php' ?>">ביטול</a>
  </div>
  <?php if ($id && $val('idn') !== '' && !valid_il_id((string)$val('idn')) && can_see_ids()) echo '<p class="mu">שימו לב: מספר הזהות שהוזן לא עובר בדיקת תקינות.</p>'; ?>
</form>
<?php sec_close(); ?>
<?php if ($id && in_array($u['role'], COLLECTION_ROLES, true)) notes_card([['id' => $id, 'name' => $row['name']]], 'student.php?id=' . $id); ?>
<?php if ($id && in_array($u['role'], COLLECTION_ROLES, true)):
  $fid = $row['family_id'] ?? null; ?>
<?php sec_open('fee', 'גבייה', $fid ? 'יש תיק גבייה' : 'אין תיק גבייה', '₪'); ?>
  <?php if ($fid): ?><a class="btn sec" href="family.php?id=<?= (int)$fid ?>">פתיחת תיק הגבייה</a>
  <?php else: ?><form method="post" action="family.php" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="create"><input type="hidden" name="student_id" value="<?= (int)$id ?>"><button class="sec">פתיחת תיק גבייה</button></form>
  <?php endif; ?>
<?php sec_close(); ?>
<?php endif; ?>
<?php if ($id && can_see_ids()): ?>
<?php sec_open('docs', 'מסמכים', count($sfiles) . ' קבצים', '▤'); ?>
  <?php if ($sfiles): ?>
  <div class="sc"><table>
    <tr><th>סוג</th><th>קובץ</th><th>גודל</th><th>הועלה</th><th></th></tr>
    <?php foreach ($sfiles as $f): ?>
    <tr>
      <td><?= e(FILE_KINDS[$f['kind']] ?? $f['kind']) ?></td>
      <td><?= e($f['orig_name']) ?></td>
      <td><?= e(number_format($f['size'] / 1024, 0)) ?> KB</td>
      <td><?= e($f['created_at']) ?></td>
      <td>
        <a class="btn sec" href="file.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">צפייה</a>
        <a class="btn sec" href="file.php?id=<?= (int)$f['id'] ?>&dl=1">הורדה</a>
        <?php if ($u['role'] === 'admin'): ?>
        <form method="post" action="file_upload.php" class="inl"><?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="fid" value="<?= (int)$f['id'] ?>"><button class="dg">מחיקה</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?><p class="mu">עדיין לא הועלו מסמכים.</p><?php endif; ?>
  <?php if (can_edit()): ?>
  <form method="post" action="file_upload.php" enctype="multipart/form-data" class="mt">
    <?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$id ?>">
    <div class="bar">
      <label>סוג מסמך<select name="kind"><?php foreach (FILE_KINDS as $k => $v) echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
      <label>קבצים (JPG / PNG / PDF, עד 10MB)<input type="file" name="f[]" multiple required accept="image/jpeg,image/png,image/webp,application/pdf"></label>
      <button>העלאה</button>
    </div>
    <p class="mu">הקבצים נשמרים מוצפנים. כל צפייה והורדה נרשמות ביומן הפעולות.</p>
  </form>
  <?php endif; ?>
<?php sec_close(); ?>
<?php endif; ?>
<?php if ($id && $isAdmin): ?>
<form method="post" class="card arch">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="archive">
  <h2>העברה לארכיון</h2>
  <p class="mu">התלמידה תוסר מהרשימות, אבל כל הרשומות המקושרות (חיובים, תשלומים, הערות ומסמכים) נשמרות. אפשר להחזיר אותה מדף הארכיון.</p>
  <label><input type="checkbox" name="ok" value="1"> אני מאשרת להעביר את התלמידה לארכיון</label>
  <p><button class="dg" disabled>העברה לארכיון</button></p>
</form>
<?php endif;
page_foot();
