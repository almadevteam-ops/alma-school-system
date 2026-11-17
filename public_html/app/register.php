<?php
// עמוד הרשמה ציבורי להורים (ללא התחברות). לא חושף נתונים: רק יוצר רשומה ממתינה לאישור המזכירות.
require __DIR__ . '/../../private/lib/bootstrap.php';
$err = ''; $done = false; $d = [];
$FIELDS = ['name' => 100, 'grade' => 4, 'idn' => 9, 'dob' => 12, 'city' => 80, 'addr' => 200, 'house_no' => 12, 'email' => 150, 'phone' => 30,
  'f_name' => 80, 'f_phone' => 30, 'f_id' => 9, 'm_name' => 80, 'm_phone' => 30, 'm_id' => 9, 'hmo' => 30, 'doctor' => 100];
if (!students_has_col('doctor')) unset($FIELDS['doctor']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  foreach ($FIELDS as $k => $max) $d[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
  $mIn = (string)($_POST['marital'] ?? ''); $d['marital'] = isset(MARITAL[$mIn]) ? $mIn : '';
  $pp = (string)($_POST['pay_pref'] ?? ''); $d['pay_pref'] = ($pp === 'hok_card') ? 'hok_card' : '';
  foreach (['idn', 'f_id', 'm_id'] as $k) $d[$k] = norm_id($d[$k]);
  foreach (['phone', 'f_phone', 'm_phone'] as $k) $d[$k] = norm_phone($d[$k]);
  $dobIn = $d['dob']; $d['dob'] = dob_iso($dobIn); $d['hmo'] = hmo_norm($d['hmo']); $d['house_no'] = preg_replace('/\s+/u', '', $d['house_no']) ?? '';
  if (trim((string)($_POST['website'] ?? '')) !== '') { $done = true; }   // שדה פיתיון לרובוטים: מתעלמים בשקט
  elseif (!reg_rate_ok()) $err = 'נשלחו יותר מדי הרשמות מהכתובת הזו. נסו שוב מאוחר יותר או פנו למזכירות.';
  elseif ($d['name'] === '') $err = 'נא למלא שם התלמידה.';
  elseif (!in_array($d['grade'], GRADES, true)) $err = 'נא לבחור כיתה.';
  elseif ($d['idn'] === '' || !preg_match('/^\d{9}$/', $d['idn']) || !valid_il_id($d['idn'])) $err = 'יש להקליד מספר זהות תקין של התלמידה.';
  elseif ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err = 'כתובת המייל לא תקינה.';
  elseif ($dobIn !== '' && ($d['dob'] === '' || $d['dob'] > date('Y-m-d'))) $err = 'תאריך הלידה לא תקין.';
  elseif ($d['f_name'] === '' || $d['f_phone'] === '' || $d['f_id'] === '') $err = 'נא למלא את פרטי האב: שם, טלפון ות״ז.';
  elseif ($d['m_name'] === '' || $d['m_phone'] === '' || $d['m_id'] === '') $err = 'נא למלא את פרטי האם: שם, טלפון ות״ז.';
  elseif (!in_array($d['hmo'], HMO_LIST, true)) $err = 'נא לבחור קופת חולים.';
  elseif ($d['marital'] === '') $err = 'נא לבחור מצב הורים.';
  elseif ($d['marital'] === 'divorced' && !reg_divorce_ok()) $err = 'בהורים גרושים חובה לצרף טופס חתום על ידי שני ההורים (JPG / PNG / PDF עד 10MB).';
  else {
    foreach (['phone' => 'טלפון התלמידה', 'f_phone' => 'טלפון האב', 'm_phone' => 'טלפון האם'] as $k => $l) if ($d[$k] !== '' && !phone_ok($d[$k])) { $err = $l . ': מספר טלפון לא תקין.'; break; }
    if (!$err) foreach (['f_id' => 'ת״ז האב', 'm_id' => 'ת״ז האם'] as $k => $l) if ($d[$k] !== '' && (!preg_match('/^\d{9}$/', $d[$k]) || !valid_il_id($d[$k]))) { $err = $l . ': יש להקליד מספר זהות תקין.'; break; }
  }
  if (!$err && !$done) {
    $ex = db()->prepare('SELECT 1 FROM students WHERE idn = ? LIMIT 1'); $ex->execute([$d['idn']]);
    if ($ex->fetchColumn()) { audit('register_dup', 'student', '', 'ניסיון הרשמה כפולה'); $done = true; }   // לא חושפים אם קיימת: אותה הודעת תודה
    else {
      $cols = array_keys($FIELDS); $vals = array_map(fn($k) => $d[$k], $cols);
      db()->prepare('INSERT INTO students (' . implode(',', $cols) . ', marital, status, pay_pref, reg_at, reg_ip, created_at, updated_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ", ?, 'pending', ?, ?, ?, ?, ?)")
        ->execute([...$vals, $d['marital'], $d['pay_pref'], now(), client_ip(), now(), now()]);
      $nid = (int)db()->lastInsertId(); reg_rate_hit();
      db()->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)')->execute([$nid, 'נרשמה דרך עמוד ההרשמה להורים בתאריך ' . date('d/m/Y') . ($d['pay_pref'] === 'hok_card' ? '. ההורים ביקשו להסדיר תשלום באשראי בקישור המאובטח.' : '.'), null, 'עמוד ההרשמה', now()]);
      $fok = 0;
      foreach (['f' => 'id', 'fd' => 'divorce'] as $inp => $kind) {
        $files = $_FILES[$inp] ?? null;
        if ($files && is_array($files['name'])) foreach ($files['name'] as $i => $n) {
          if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue; if ($fok >= 6) break;
          [$good] = store_student_file(['name' => $n, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i]], $nid, $kind, 0);
          if ($good) $fok++;
        }
      }
      audit('register', 'student', (string)$nid, $d['name'] . ' · קבצים: ' . $fok);
      try { notify_new_student($d + ['name' => $d['name']], $nid); } catch (Throwable $t) { audit('new_student_mail_failed', 'student', (string)$nid, mb_substr($t->getMessage(), 0, 200)); }
      $done = true;
    }
  }
}
$cp = setting_get('reg_phone', ''); $ce = setting_get('reg_email', '');
page_head('הרשמה לעלמא', false);
?>
<div class="reg"><div class="card regc">
  <img class="rlogo" src="/app/assets/logo-mark.png" alt="" width="64" height="64">
  <h1>הרשמה למשפחת עלמא</h1>
<?php if ($done): ?>
  <p class="ok">תודה! הפרטים התקבלו והועברו למזכירות. נחזור אליכם בהקדם.</p>
  <?php if (($d['pay_pref'] ?? '') === 'hok_card' && pay_url() !== ''): ?><p>להסדרת התשלום באשראי: <a class="btn" href="<?= e(pay_url()) ?>" target="_blank" rel="noopener">💳 לתשלום מאובטח</a></p><?php endif; ?>
  <?php if ($cp !== '' || $ce !== ''): ?><p class="mu">לשאלות: <?= $cp !== '' ? '<bdi dir="ltr">' . e($cp) . '</bdi>' : '' ?> <?= $ce !== '' ? '<bdi dir="ltr">' . e($ce) . '</bdi>' : '' ?></p><?php endif; ?>
<?php else: ?>
  <p class="mu">ברכות על קבלתך למקום הטוב ביותר. נא למלא את הפרטים ולצרף צילום תעודת זהות וספח. <span class="req">*</span> שדה חובה.</p>
  <?php if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
  <form method="post" enctype="multipart/form-data" class="dirty" autocomplete="off"><?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>אל תמלאו<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="fg">
      <label><span>שם התלמידה <span class="req">*</span></span><input name="name" maxlength="100" required value="<?= e($d['name'] ?? '') ?>"></label>
      <label><span>כיתה <span class="req">*</span></span><select name="grade" required><option value=""></option><?php foreach (GRADES as $x) echo '<option' . (($d['grade'] ?? '') === $x ? ' selected' : '') . '>' . e($x) . '</option>'; ?></select></label>
      <label><span>תעודת זהות <span class="req">*</span></span><input name="idn" inputmode="numeric" data-digits="1" dir="ltr" maxlength="9" required value="<?= e($d['idn'] ?? '') ?>"></label>
      <label>תאריך לידה<input name="dob" type="date" min="1990-01-01" max="<?= date('Y-m-d') ?>" dir="ltr" value="<?= e($d['dob'] ?? '') ?>"></label>
      <label>עיר<input name="city" maxlength="80" value="<?= e($d['city'] ?? '') ?>"></label>
      <label>רחוב<input name="addr" maxlength="200" value="<?= e($d['addr'] ?? '') ?>"></label>
      <label>מספר בית<input name="house_no" maxlength="12" dir="ltr" value="<?= e($d['house_no'] ?? '') ?>"></label>
      <label>אימייל<input name="email" type="email" maxlength="150" dir="ltr" value="<?= e($d['email'] ?? '') ?>"></label>
      <label>טלפון התלמידה<input name="phone" type="tel" dir="ltr" maxlength="30" value="<?= e($d['phone'] ?? '') ?>"></label>
      <label><span>שם האב <span class="req">*</span></span><input name="f_name" required maxlength="80" value="<?= e($d['f_name'] ?? '') ?>"></label>
      <label><span>טלפון האב <span class="req">*</span></span><input name="f_phone" required type="tel" dir="ltr" maxlength="30" value="<?= e($d['f_phone'] ?? '') ?>"></label>
      <label><span>ת״ז האב <span class="req">*</span></span><input name="f_id" required inputmode="numeric" data-digits="1" dir="ltr" maxlength="9" value="<?= e($d['f_id'] ?? '') ?>"></label>
      <label><span>שם האם <span class="req">*</span></span><input name="m_name" required maxlength="80" value="<?= e($d['m_name'] ?? '') ?>"></label>
      <label><span>טלפון האם <span class="req">*</span></span><input name="m_phone" required type="tel" dir="ltr" maxlength="30" value="<?= e($d['m_phone'] ?? '') ?>"></label>
      <label><span>ת״ז האם <span class="req">*</span></span><input name="m_id" required inputmode="numeric" data-digits="1" dir="ltr" maxlength="9" value="<?= e($d['m_id'] ?? '') ?>"></label>
      <label><span>קופת חולים <span class="req">*</span></span><select name="hmo" required><option value="">בחרי קופת חולים</option><?php foreach (HMO_LIST as $x) echo '<option' . (($d['hmo'] ?? '') === $x ? ' selected' : '') . '>' . e($x) . '</option>'; ?></select></label>
      <?php if (isset($FIELDS['doctor'])): ?><label>שם הרופא המטפל<input name="doctor" maxlength="100" value="<?= e($d['doctor'] ?? '') ?>"></label><?php endif; ?>
      <label><span>מצב הורים <span class="req">*</span></span><select name="marital" required><option value="">בחרי</option><?php foreach (MARITAL as $mk => $ml) echo '<option value="' . $mk . '"' . (($d['marital'] ?? '') === $mk ? ' selected' : '') . '>' . e($ml) . '</option>'; ?></select></label>
      <label>אופן תשלום שכר הלימוד<select name="pay_pref"><option value="">אסדיר מול המזכירות</option><option value="hok_card"<?= ($d['pay_pref'] ?? '') === 'hok_card' ? ' selected' : '' ?>>הוראת קבע באשראי (קישור מאובטח)</option></select></label>
      <label class="wide">צילום תעודת זהות וספח (JPG / PNG / PDF, כל קובץ עד 10MB)<input type="file" name="f[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf"></label>
      <label class="wide" data-g="div" hidden>טופס חתום על ידי שני ההורים (חובה בהורים גרושים) <span class="req">*</span><input type="file" name="fd[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf"></label>
    </div>
    <p class="mu">הפרטים נשמרים מוצפנים ומשמשים את המזכירות בלבד. לא נשמרים כאן פרטי אשראי.</p>
    <div class="acts"><button class="sv" disabled>שליחת ההרשמה</button></div>
  </form>
<?php endif; ?>
</div></div>
<?php page_foot();
