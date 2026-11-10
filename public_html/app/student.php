<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_login();
$isAdmin = $u['role'] === 'admin';
// key => [label, maxlen, sensitive]
$F = [
  'name' => ['שם מלא', 100, 0], 'grade' => ['כיתה', 4, 0], 'cls' => ['כיתה מפורטת', 8, 0],
  'idn' => ['תעודת זהות', 9, 1], 'dob' => ['תאריך לידה', 12, 0], 'city' => ['עיר', 80, 0], 'addr' => ['רחוב', 200, 0], 'house_no' => ['מספר בית', 12, 0],
  'email' => ['אימייל', 150, 0], 'phone' => ['טלפון התלמידה', 30, 0],
  'f_name' => ['שם האב', 80, 0], 'f_phone' => ['טלפון האב', 30, 0], 'f_id' => ['ת״ז האב', 9, 1],
  'm_name' => ['שם האם', 80, 0], 'm_phone' => ['טלפון האם', 30, 0], 'm_id' => ['ת״ז האם', 9, 1],
  'hmo' => ['קופת חולים', 30, 0], 'home_phone' => ['טלפון בית', 30, 0], 'books' => ['ספרים והשאלות', 255, 0],
];
const ID_FIELDS = ['idn', 'f_id', 'm_id'];
const PHONE_FIELDS = ['phone', 'f_phone', 'm_phone', 'home_phone'];
/** שדות שרק מנהלת מערכת יכולה לערוך בכרטיס של תלמידה קיימת. */
const REQUIRED = ['name', 'grade'];
/** שדות חובה נוספים ביצירת תלמידה חדשה (כמו בטופס ההרשמה). */
const REQUIRED_NEW = ['idn'];
const ADMIN_ONLY = ['name', 'idn', 'f_name', 'm_name', 'f_id', 'm_id'];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$err = ''; $msg = '';
$row = null;
if ($id) {
  $st = db()->prepare('SELECT * FROM students WHERE id = ?'); $st->execute([$id]); $row = $st->fetch();
  if (!$row) fail_page(404, 'התלמידה לא נמצאה.');
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
  if ($act === 'sib_add' || $act === 'sib_del') {
    if (!can_edit() || !$row || !can_see_ids()) fail_page(403, 'אין לך הרשאה.');
    if ($act === 'sib_del') { db()->prepare('DELETE FROM student_sibs WHERE id = ? AND student_id = ?')->execute([(int)($_POST['sib'] ?? 0), $id]); audit('sib_del', 'student', (string)$id, ''); redirect('student.php?id=' . $id . '&m=' . rawurlencode('הוסר.') . '#sibs'); }
    $nm = mb_substr(trim((string)($_POST['sname'] ?? '')), 0, 100); $si = norm_id((string)($_POST['sidn'] ?? '')); $sd = dob_iso((string)($_POST['sdob'] ?? ''));
    if ($nm === '') redirect('student.php?id=' . $id . '&e=' . rawurlencode('נא למלא שם.') . '#sibs');
    if ($si !== '' && (!preg_match('/^\d{9}$/', $si) || !valid_il_id($si))) redirect('student.php?id=' . $id . '&e=' . rawurlencode('יש להקליד מספר זהות תקין.') . '#sibs');
    db()->prepare('INSERT INTO student_sibs (student_id, name, idn, dob, created_at) VALUES (?,?,?,?,?)')->execute([$id, $nm, $si, $sd, now()]);
    audit('sib_add', 'student', (string)$id, $nm); redirect('student.php?id=' . $id . '&m=' . rawurlencode('נוסף.') . '#sibs');
  }
  if (!can_edit()) fail_page(403, 'אין לך הרשאת עריכה.');
  $d = [];
  foreach ($F as $k => [$lbl, $max]) $d[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
  $d['cls'] = cls_norm($d['grade'], $d['cls']);
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
  $d['allergy'] = !empty($_POST['allergy']) ? 1 : 0;
  $al = array_values(array_intersect(ALLERGY_OPTS, (array)($_POST['allergy_list'] ?? [])));
  $d['allergy_list'] = $d['allergy'] ? implode(',', $al) : '';
  $d['allergy_other'] = ($d['allergy'] && in_array('אחר', $al, true)) ? mb_substr(trim((string)($_POST['allergy_other'] ?? '')), 0, 120) : '';
  $d['vaada'] = !empty($_POST['vaada']) ? 1 : 0;
  $d['vaada_note'] = $d['vaada'] ? mb_substr(trim((string)($_POST['vaada_note'] ?? '')), 0, 255) : '';
  $d['s07'] = !empty($_POST['s07']) ? 1 : 0;
  $d['is_apt'] = !empty($_POST['is_apt']) ? 1 : 0;
  $lm = (int)($_POST['left_month'] ?? 0);
  $d['left_month'] = ($d['status'] === 'left' && $lm >= 1 && $lm <= 12) ? $lm : null;
  if ($isAdmin && $d['status'] === 'left' && $d['left_month'] === null && $row && $row['status'] !== 'left') $d['left_month'] = null;
  $d['disc_on'] = !empty($_POST['disc_on']) ? 1 : 0;
  $d['disc_amt'] = $d['disc_on'] ? max(0, round((float)str_replace(',', '.', (string)($_POST['disc_amt'] ?? '0')), 2)) : 0;
  $d['disc_by'] = $d['disc_on'] ? mb_substr(trim((string)($_POST['disc_by'] ?? '')), 0, 100) : '';
  $d['disc_link'] = $d['disc_on'] ? mb_substr(trim((string)($_POST['disc_link'] ?? '')), 0, 255) : '';
  $d['disc_note'] = $d['disc_on'] ? mb_substr(trim((string)($_POST['disc_note'] ?? '')), 0, 255) : '';
  $d['disc_status'] = $d['disc_on'] && $isAdmin && ($_POST['disc_status'] ?? '') === 'approved' ? 'approved' : 'pending';
  if ($row && !$isAdmin && (int)$row['disc_on'] === $d['disc_on'] && (float)$row['disc_amt'] === (float)$d['disc_amt']) $d['disc_status'] = (string)$row['disc_status'];   // לא שונה: האישור נשמר
  if (!$d['disc_on']) $d['disc_status'] = 'approved';
  $chg = fn(string $k) => !$row || (string)$row[$k] !== (string)$d[$k];   // בדיקות תקינות רק על מה ששונה (נתונים ישנים לא חוסמים שמירה)
  if ($d['name'] === '') $err = 'נא למלא שם.';
  elseif (!in_array($d['grade'], GRADES, true)) $err = 'נא לבחור כיתה.';
  elseif (!$id && can_see_ids() && ($d['idn'] === '' || !preg_match('/^\d{9}$/', $d['idn']) || !valid_il_id($d['idn']))) $err = 'תלמידה חדשה: יש להקליד מספר זהות תקין של התלמידה.';
  elseif (!$id && $d['f_phone'] === '' && $d['m_phone'] === '') $err = 'תלמידה חדשה: נא למלא לפחות טלפון אחד של אחד ההורים.';
  elseif (!$id && $d['idn'] !== '' && ($dup = db()->prepare('SELECT name FROM students WHERE idn = ? LIMIT 1')) && $dup->execute([$d['idn']]) && ($dn = $dup->fetchColumn()) !== false) $err = 'כבר קיימת תלמידה עם מספר הזהות הזה (' . $dn . ').';
  elseif ($d['disc_on'] && $d['disc_amt'] <= 0) $err = 'הנחה: יש להזין סכום גדול מאפס.';
  elseif ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err = 'כתובת האימייל לא תקינה.';
  elseif ($dobIn !== '' && dob_iso($dobIn) === '') $err = 'תאריך הלידה לא תקין.';
  elseif ($d['dob'] !== '' && dob_iso($d['dob']) !== '' && $d['dob'] > date('Y-m-d')) $err = 'תאריך הלידה לא יכול להיות בעתיד.';
  else {
    foreach (ID_FIELDS as $k) if ($d[$k] !== '' && $chg($k) && (!preg_match('/^\d{9}$/', $d[$k]) || preg_match('/^0+$/', $d[$k]) || !valid_il_id($d[$k]))) { $err = $F[$k][0] . ': יש להקליד מספר זהות תקין.'; break; }
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
      if ($d['is_apt'] && !(int)$row['is_apt']) apt_apply($id);
      if ($d['disc_on'] && ((float)$row['disc_amt'] !== (float)$d['disc_amt'] || !(int)$row['disc_on'])) audit('discount', 'student', (string)$id, $d['disc_amt'] . ' ₪ · ' . $d['disc_status'] . ' · ' . $d['disc_by']);
      audit('update', 'student', (string)$id, 'שדות: ' . implode(',', $changed));
      redirect('student.php?id=' . $id . '&saved=1');
    } else {
      $d['status'] = 'active';
      $cols = implode(',', array_keys($d));
      $ph = implode(',', array_fill(0, count($d), '?'));
      db()->prepare("INSERT INTO students ($cols, created_at, updated_at) VALUES ($ph, ?, ?)")->execute([...array_values($d), now(), now()]);
      $nid = (int)db()->lastInsertId();
      if ($d['is_apt']) apt_apply($nid);
      audit('create', 'student', (string)$nid, $d['name']);
      try { notify_new_student($d, $nid); } catch (Throwable $t) { audit('new_student_mail_failed', 'student', (string)$nid, mb_substr($t->getMessage(), 0, 200)); }
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
<?php if ($row && $id): ?><div class="sub"><div class="in"><a href="students.php">‹ חזרה לתלמידות</a><b class="nm"><?= e($row['name']) ?></b><span class="mu">כיתה <?= e(class_label((string)$row['grade'], (string)($row['cls'] ?? ''))) ?></span><span class="gap"></span><span class="pill <?= e($row['status']) ?>"><?= e(STATUS[$row['status']] ?? $row['status']) ?></span></div></div><?php endif; ?>
<h1><?= $row ? e($row['name']) : 'תלמידה חדשה' ?></h1>
<?php if (!empty($row['archived_at'])) echo '<p class="aptn">התלמידה בארכיון (מאז ' . e(date('d/m/Y', strtotime((string)$row['archived_at']))) . '). כל הרשומות שלה נשמרו.' . ($isAdmin ? ' <a href="archive.php">לדף הארכיון</a>' : '') . '</p>'; ?>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<?php if ($row && $id && ($row['marital'] ?? '') === 'divorced' && !has_divorce_doc($id)): ?><p class="er alertbar">⚠ הורים גרושים: חסר מסמך חתום על ידי שני ההורים. <a href="?id=<?= (int)$id ?>&open=docs#s-docs">להעלאת המסמך</a></p><?php endif; ?>
<?php sec_open('details', 'פרטי תלמידה', ($row['grade'] ?? '') !== '' ? 'כיתה ' . class_label((string)$row['grade'], (string)($row['cls'] ?? '')) : '', '☺', ['details']); ?>
<form method="post" class="dirty" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="fg">
  <?php foreach ($F as $k => [$lbl, $max, $sens]):
    if ($sens && !can_see_ids()) continue;
    $lock = $ro || ($id && !$isAdmin && in_array($k, ADMIN_ONLY, true));
    $dis = $lock ? ' disabled' : '';
    $isReq = in_array($k, REQUIRED, true) || (!$id && in_array($k, REQUIRED_NEW, true)); $req = $isReq && !$lock; $star = $isReq ? ' <span class="req" title="שדה חובה">*</span>' : '';
    if (!$id && in_array($k, ['f_phone', 'm_phone'], true)) $star = ' <span class="req" title="נדרש טלפון של לפחות אחד ההורים">*</span>';
    $reqa = $req ? ' required' : '';
    if ($k === 'grade') {
      echo '<label><span>' . e($lbl) . $star . '</span><select name="grade"' . ($ro ? ' disabled' : '') . $reqa . '><option value=""></option>';
      foreach (GRADES as $x) echo '<option' . ($val('grade') === $x ? ' selected' : '') . '>' . e($x) . '</option>';
      echo '</select></label>'; continue;
    }
    if ($k === 'cls') {
      echo '<label><span>' . e($lbl) . '</span><select name="cls"' . ($ro ? ' disabled' : '') . '><option value="">—</option>';
      foreach (['1', '2', '3'] as $x) echo '<option' . (cls_digit((string)$val('cls')) === $x ? ' selected' : '') . '>' . $x . '</option>';
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
  <label class="chk"><input type="checkbox" name="is_apt" value="1"<?= $val('is_apt') ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>> גרה בדירה (שכר הלימוד משולם ע"י מורשת גד)</label>
  <label class="chk"><input type="checkbox" name="vaada" value="1" data-tg="vaada"<?= $val('vaada') ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>> עברה ועדה</label>
  <label class="wide" data-g="vaada"<?= $val('vaada') ? '' : ' hidden' ?>>פירוט הוועדה (תאריך / החלטה)<input name="vaada_note" maxlength="255" value="<?= e((string)$val('vaada_note')) ?>"<?= $ro ? ' disabled' : '' ?>></label>
  <label class="chk"><input type="checkbox" name="s07" value="1"<?= $val('s07') ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>> מוגדרת 07</label>
  <label class="chk"><input type="checkbox" name="allergy" value="1" data-tg="allergy"<?= $val('allergy') ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>> יש רגישות</label>
  <div class="wide chkrow" data-g="allergy"<?= $val('allergy') ? '' : ' hidden' ?>><span class="mu">במה:</span>
    <?php $alc = explode(',', (string)$val('allergy_list')); foreach (ALLERGY_OPTS as $x): ?><label class="chk"><input type="checkbox" name="allergy_list[]" value="<?= e($x) ?>"<?= in_array($x, $alc, true) ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>> <?= e($x) ?></label><?php endforeach; ?>
    <input name="allergy_other" maxlength="120" placeholder="פירוט (אחר)" value="<?= e((string)$val('allergy_other')) ?>"<?= $ro ? ' disabled' : '' ?>></div>
  <div class="wide dsc"><label class="chk"><input type="checkbox" name="disc_on" value="1" data-tg="disc"<?= $val('disc_on') ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>> יש הנחה <?php if ($val('disc_on') && ($val('disc_status') ?: 'approved') === 'pending') echo '<span class="pill pending">ממתינה לאישור</span>'; ?></label>
    <div class="fg" data-g="disc"<?= $val('disc_on') ? '' : ' hidden' ?>>
      <label>הנחה לחודש (₪)<input name="disc_amt" inputmode="decimal" value="<?= e($val('disc_amt') ? rtrim(rtrim((string)$val('disc_amt'), '0'), '.') : '') ?>"<?= $ro ? ' disabled' : '' ?>></label>
      <label>סטטוס ההנחה<select name="disc_status"<?= ($ro || !$isAdmin) ? ' disabled' : '' ?>><?php foreach (DISC_STATUS as $k => $v) echo '<option value="' . $k . '"' . ((($val('disc_status') ?: 'approved') === $k) ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
      <label>מי אישרה<input name="disc_by" maxlength="100" value="<?= e((string)$val('disc_by')) ?>"<?= $ro ? ' disabled' : '' ?>></label>
      <label>קישור לאישור<input name="disc_link" maxlength="255" dir="ltr" value="<?= e((string)$val('disc_link')) ?>"<?= $ro ? ' disabled' : '' ?>></label>
      <label class="wide">הערות להנחה<input name="disc_note" maxlength="255" value="<?= e((string)$val('disc_note')) ?>"<?= $ro ? ' disabled' : '' ?>></label>
    </div>
    <?php if (!$isAdmin): ?><p class="mu" data-g="disc"<?= $val('disc_on') ? '' : ' hidden' ?>>הנחה שנקבעה או שונתה על ידך תמתין לאישור מנהלת.</p><?php endif; ?>
  </div>
  <label>סטטוס<select name="status" data-orig="<?= e($val('status') ?: 'active') ?>"<?= ($ro || !$isAdmin || !$id) ? ' disabled' : '' ?>><?php foreach (STATUS as $k => $v) echo '<option value="' . $k . '"' . (($val('status') ?: 'active') === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
  <label id="lmwrap"<?= $val('status') === 'left' ? '' : ' hidden' ?>>עזבה מחודש<select name="left_month"<?= ($ro || !$isAdmin) ? ' disabled' : '' ?>><option value="">—</option><?php foreach (SCHOOL_MONTHS as $i => $mn) echo '<option value="' . ($i + 1) . '"' . ((int)$val('left_month') === $i + 1 ? ' selected' : '') . '>' . e($mn) . '</option>'; ?></select></label>
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
<?php if ($id && can_see_ids()): ?>
<?php
$sibs = db()->prepare('SELECT id, name, idn, dob FROM student_sibs WHERE student_id = ? ORDER BY dob, id'); $sibs->execute([$id]); $sibs = $sibs->fetchAll();
$sis = sisters_of($row);
sec_open('sibs', 'אחים ואחיות', (count($sibs) + count($sis)) . ' רשומות', '♥'); ?>
  <?php if ($sis): ?><p><b>אחיות במערכת</b> (אותו תיק גבייה או טלפון הורים זהה):
    <?php foreach ($sis as $x) echo '<a class="btn sec" href="student.php?id=' . (int)$x['id'] . '">' . e($x['name']) . ' · ' . e($x['grade']) . '</a> '; ?></p><?php endif; ?>
  <?php if ($sibs): ?><div class="sc"><table>
    <tr><th>שם</th><th>ת.ז</th><th>תאריך לידה</th><th>שכבה</th><th></th></tr>
    <?php foreach ($sibs as $b): $in = $b['idn'] !== '' ? db()->prepare('SELECT id FROM students WHERE idn = ? AND archived_at IS NULL LIMIT 1') : null; if ($in) { $in->execute([$b['idn']]); $in = $in->fetchColumn(); } ?>
    <tr><td><?= e($b['name']) ?></td><td><bdi dir="ltr"><?= e($b['idn']) ?></bdi></td><td><?= e($b['dob'] !== '' ? (dob_iso($b['dob']) ? date_out(dob_iso($b['dob'])) : $b['dob']) : '') ?></td><td><?= e(grade_from_dob((string)$b['dob'])) ?><?= $in ? ' · <a href="student.php?id=' . (int)$in . '">לומדת אצלנו</a>' : '' ?></td>
      <td><?php if (can_edit()): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="sib_del"><input type="hidden" name="sib" value="<?= (int)$b['id'] ?>"><button class="sec" data-confirm="להסיר?">הסרה</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table></div>
  <?php else: ?><p class="mu">לא הוזנו אחים ואחיות מהספח.</p><?php endif; ?>
  <?php if (can_edit()): ?><form method="post" class="bar mt"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="sib_add">
    <label>שם<input name="sname" required maxlength="100"></label><label>ת.ז<input name="sidn" maxlength="9" inputmode="numeric" data-digits="1" dir="ltr"></label><label>תאריך לידה<input name="sdob" type="date" min="1990-01-01" max="<?= date('Y-m-d') ?>" dir="ltr"></label><button>הוספה</button></form><?php endif; ?>
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
