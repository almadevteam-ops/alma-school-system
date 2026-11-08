<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'secretary']);
$who = (string)($u['name'] ?: $u['username']);
$err = ''; $msg = ''; $token = ''; $R = null; $rows = []; $hi = 0; $map = []; $mode = 'fill';
function mstaged(string $t): ?string { return preg_match('/^[a-f0-9]{32}$/', $t) ? PRIV . '/storage/moe_' . $t . '.bin' : null; }
foreach (glob(PRIV . '/storage/moe_*.bin') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? '');
  if ($act === 'upload') {
    $f = $_FILES['moe'] ?? null;
    if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
    elseif ($f['size'] > MOE_MAX_BYTES) $err = 'הקובץ גדול מדי (עד 5MB).';
    elseif (!preg_match('/\.(xlsx|csv|txt)$/i', (string)$f['name'])) $err = 'סוגי קבצים נתמכים: xlsx או csv.';
    elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
    else {
      try { $rows = moe_read($f['tmp_name'], (string)$f['name']); } catch (Throwable $t) { $err = $t->getMessage(); }
      if (!$err) {
        $rows = array_map(fn($r) => array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), is_array($r) ? array_slice($r, 0, 60) : []), array_slice($rows, 0, MOE_MAX_ROWS + 20));
        $token = bin2hex(random_bytes(16)); $p = mstaged($token);
        file_put_contents($p, enc_bytes(json_encode($rows, JSON_UNESCAPED_UNICODE), 'moe:' . $token), LOCK_EX); @chmod($p, 0600);
        $hi = moe_header_row($rows); $map = moe_auto($rows[$hi] ?? []);
        audit('moe_preview', 'student', '', count($rows) . ' שורות');
      }
    }
  } elseif (in_array($act, ['remap', 'apply'], true)) {
    $token = (string)($_POST['token'] ?? ''); $p = mstaged($token);
    $raw = ($p && is_file($p)) ? dec_bytes((string)file_get_contents($p), 'moe:' . $token) : null;
    if ($raw === null || $raw === false) { $err = 'פג תוקף התצוגה המקדימה. העלי את הקובץ שוב.'; $token = ''; }
    else {
      $rows = json_decode($raw, true) ?: []; $hi = max(0, min(14, (int)($_POST['hi'] ?? 0))); $map = [];
      foreach ((array)($_POST['map'] ?? []) as $i => $k) if (isset(MOE_FIELDS[(string)$k])) $map[(int)$i] = (string)$k;
      $mode = ($_POST['mode'] ?? 'fill') === 'over' ? 'over' : 'fill';
    }
  } elseif ($act === 'cancel') { $p = mstaged((string)($_POST['token'] ?? '')); if ($p && is_file($p)) @unlink($p); $msg = 'בוטל. לא נשמר דבר.'; }

  if ($token !== '' && !$err && ($act === 'upload' || $act === 'remap' || $act === 'apply')) {
    $R = moe_compute($rows, $hi, $map);
    if (!in_array('name', $map, true) && !in_array('last', $map, true) && !in_array('idn', $map, true)) { $err = 'לא זוהתה עמודת שם או ת.ז. אפשר לתקן בטבלת ההתאמה.'; }
    if ($act === 'apply' && !$err) {
      $added = 0; $upd = 0; $skip = []; $pdo = db(); $S = $R['S'];
      $pdo->beginTransaction();
      try {
        $add = array_map('intval', (array)($_POST['add'] ?? []));
        foreach ($R['only'] as $mi) {
          if (!in_array($mi, $add, true)) continue;
          $x = $R['M'][$mi]; $nm = mb_substr($x['nm'], 0, 100);
          if ($nm === '') { $skip[] = 'שורה ללא שם'; continue; }
          if (!in_array($x['grade'], GRADES, true)) { $skip[] = $nm . ': כיתה לא מוכרת'; continue; }
          if ($x['idn'] !== '' && !valid_il_id($x['idn'])) { $skip[] = $nm . ': ת.ז לא תקינה'; continue; }
          if ($x['idn'] !== '') { $q = $pdo->prepare('SELECT 1 FROM students WHERE idn = ? LIMIT 1'); $q->execute([$x['idn']]); if ($q->fetchColumn()) { $skip[] = $nm . ': ת.ז כבר קיימת'; continue; } }
          [$st, $hn] = split_street_house($x['addr']);
          $pdo->prepare("INSERT INTO students (name, grade, cls, idn, dob, addr, house_no, addr_orig, city, email, phone, home_phone, f_name, f_phone, f_id, m_name, m_phone, m_id, hmo, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'active', ?, ?)")
            ->execute([$nm, $x['grade'], $x['cls'], $x['idn'], $x['dob'], mb_substr($st, 0, 200), $hn, mb_substr($x['addr'], 0, 200), mb_substr($x['city'], 0, 80), mb_substr($x['email'], 0, 150), $x['phone'], $x['home_phone'], mb_substr($x['f_name'], 0, 80), $x['f_phone'], $x['f_id'], mb_substr($x['m_name'], 0, 80), $x['m_phone'], $x['m_id'], $x['hmo'] ?: HMO_UNKNOWN, now(), now()]);
          $nid = (int)$pdo->lastInsertId();
          $pdo->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)')->execute([$nid, 'נוספה מסנכרון משרד החינוך בתאריך ' . date('d/m/Y') . ' ע"י ' . $who, $u['id'], $who, now()]);
          $added++;
        }
        if (!empty($_POST['upd'])) foreach ($R['both'] as [$sid, $mi]) {
          $s = $S[$sid]; $x = $R['M'][$mi]; $df = moe_diffs($s, $x, $mode); if (!$df) continue;
          $sets = []; $vals = [];
          foreach ($df as $k => [$cur, $new]) {
            $sets[] = "$k = ?"; $vals[] = $new;
            if ($k === 'addr') { [$st, $hn] = split_street_house($x['addr']); $sets[] = 'house_no = ?'; $vals[] = $hn; $sets[] = 'addr_orig = ?'; $vals[] = mb_substr($x['addr'], 0, 200); }
          }
          $vals = array_map(fn($v) => is_string($v) ? mb_substr($v, 0, 200) : $v, $vals);
          if (isset($df['addr'])) $vals[array_search('addr = ?', $sets, true)] = mb_substr(split_street_house($x['addr'])[0], 0, 200);
          $pdo->prepare('UPDATE students SET ' . implode(', ', $sets) . ', updated_at = ? WHERE id = ?')->execute([...$vals, now(), $sid]);
          $pdo->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)')->execute([$sid, 'עודכן מסנכרון משרד החינוך (' . implode(', ', array_map(fn($k) => MOE_FIELDS[$k], array_keys($df))) . ')', $u['id'], $who, now()]);
          $upd++;
        }
        $pdo->commit();
      } catch (Throwable $t) { $pdo->rollBack(); $err = 'הסנכרון נכשל ולא נשמר דבר.'; error_log('moe_sync: ' . $t->getMessage()); }
      if (!$err) {
        audit('moe_apply', 'student', '', "נוספו $added · עודכנו $upd · דולגו " . count($skip) . ($mode === 'over' ? ' · החלפה' : ' · מילוי'));
        $p = mstaged($token); if ($p && is_file($p)) @unlink($p);
        $msg = "הסנכרון הושלם: נוספו $added תלמידות, עודכנו $upd תלמידות." . ($skip ? ' דולגו: ' . implode('; ', array_slice($skip, 0, 10)) . (count($skip) > 10 ? '…' : '') : '');
        $R = null; $token = '';
      }
    }
  }
}
page_head('סנכרון משרד החינוך');
?>
<h1>סנכרון מול מצבת משרד החינוך</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <p class="mu">העלי קובץ xlsx או csv מהמשרד. המערכת קוראת את העמודות לפי הכותרות (שם, ת.ז, לידה, כתובת, עיר, טלפונים, מייל, כיתה, הורים). הזיהוי לפי ת.ז, ובהיעדרה לפי שם. שום דבר לא נשמר לפני אישור.</p>
  <form method="post" enctype="multipart/form-data" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="upload"><input type="file" name="moe" accept=".xlsx,.csv,.txt" required><button>טעינה ובדיקה</button></form>
</div>
<?php if ($R): $M = $R['M']; $S = $R['S']; $hd = $rows[$hi] ?? []; $nd = 0; $diffRows = [];
  foreach ($R['both'] as [$sid, $mi]) { $df = moe_diffs($S[$sid], $M[$mi], $mode); if ($df) { $nd++; $diffRows[] = [$sid, $df]; } } ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="hi" value="<?= (int)$hi ?>">
<?php sec_open('map', 'התאמת עמודות', 'זוהו אוטומטית, אפשר לתקן', '🧩', ['map']); ?>
<div class="sc"><table><tr><th>עמודה בקובץ</th><th>דוגמה</th><th>שדה במערכת</th></tr>
<?php foreach ($hd as $i => $h) { if ($h === '') continue; $ex = ''; foreach (array_slice($rows, $hi + 1, 30) as $r) if (($r[$i] ?? '') !== '') { $ex = $r[$i]; break; }
  echo '<tr><td>' . e($h) . '</td><td class="mu">' . e(mb_substr($ex, 0, 40)) . '</td><td><select name="map[' . (int)$i . ']"><option value="">התעלם</option>';
  foreach (MOE_FIELDS as $k => $l) echo '<option value="' . $k . '"' . (($map[$i] ?? '') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
  echo '</select></td></tr>'; } ?></table></div>
<p><button name="act" value="remap" class="sec">חישוב מחדש לפי ההתאמה</button></p>
<?php sec_close(); ?>
<div class="cards"><div class="card">במשרד ולא במצבת שלנו<b><?= count($R['only']) ?></b></div><div class="card">אצלנו ולא במשרד<b><?= count($R['miss']) ?></b></div><div class="card">תואמות<b><?= count($R['both']) ?></b></div><div class="card">עם הבדלים בנתונים<b><?= $nd ?></b></div></div>
<h2>עדכון תלמידות קיימות</h2>
<div class="bar"><label class="chk"><input type="radio" name="mode" value="fill"<?= $mode === 'fill' ? ' checked' : '' ?>> השלמת שדות ריקים בלבד</label><label class="chk"><input type="radio" name="mode" value="over"<?= $mode === 'over' ? ' checked' : '' ?>> החלפת ערכים קיימים בערכי המשרד</label><label class="chk"><input type="checkbox" name="upd" value="1" checked> לבצע עדכון</label><button name="act" value="remap" class="sec">רענון תצוגה</button></div>
<div class="sc"><table><tr><th>תלמידה</th><th>שדה</th><th>אצלנו</th><th>במשרד</th></tr>
<?php $n = 0; foreach ($diffRows as [$sid, $df]) { foreach ($df as $k => [$cur, $new]) { if (++$n > 300) break 2; echo '<tr><td>' . e($S[$sid]['name']) . '</td><td>' . e(MOE_FIELDS[$k]) . '</td><td>' . ($cur === '' ? '<span class="mu">ריק</span>' : '<bdi dir="auto">' . e($cur) . '</bdi>') . '</td><td><bdi dir="auto">' . e($new) . '</bdi></td></tr>'; } }
  if (!$diffRows) echo '<tr><td colspan="4" class="mu">אין הבדלים לעדכון במצב הנבחר.</td></tr>'; elseif ($n > 300) echo '<tr><td colspan="4" class="mu">מוצגים 300 הראשונים; הביצוע חל על כולם.</td></tr>'; ?></table></div>
<h2>במשרד החינוך ולא במצבת</h2>
<div class="bar"><button type="button" class="sec" data-chk="mc" data-on="1">סימון הכול</button><button type="button" class="sec" data-chk="mc" data-on="0">ביטול סימון</button></div>
<div class="sc"><table><tr><th></th><th>שם</th><th>ת.ז</th><th>כיתה</th><th>לידה</th><th>עיר</th><th>טלפון</th><th>אב</th><th>אם</th></tr>
<?php foreach ($R['only'] as $mi) { $x = $M[$mi]; $ok = in_array($x['grade'], GRADES, true) && ($x['idn'] === '' || valid_il_id($x['idn']));
  echo '<tr><td><input type="checkbox" class="mc" name="add[]" value="' . $mi . '" aria-label="הוספה"' . ($ok ? ' checked' : '') . '></td><td>' . e($x['nm']) . '</td><td><bdi dir="ltr">' . e($x['idn']) . '</bdi></td><td>' . e($x['cls'] ?: '?') . '</td><td>' . e($x['dob']) . '</td><td>' . e($x['city']) . '</td><td><bdi dir="ltr">' . e($x['phone']) . '</bdi></td><td>' . e($x['f_name']) . '</td><td>' . e($x['m_name']) . '</td></tr>'; }
  if (!$R['only']) echo '<tr><td colspan="9" class="mu">הכול תואם.</td></tr>'; ?></table></div>
<p class="mu">תלמידות חדשות נוספות כפעילות, בלי תיק גבייה — יש לשייך אותן למשפחה בכרטיס התלמידה. שורות עם כיתה או ת.ז לא תקינה מסומנות כברירת מחדל כלא נבחרות ואם סומנו ידנית יידלגו.</p>
<h2>במערכת ולא במשרד החינוך</h2>
<div class="sc"><table><tr><th>שם</th><th>כיתה</th><th>ת.ז</th></tr>
<?php foreach ($R['miss'] as $sid) echo '<tr><td><a href="student.php?id=' . $sid . '">' . e($S[$sid]['name']) . '</a></td><td></td><td><bdi dir="ltr">' . e($S[$sid]['idn']) . '</bdi></td></tr>'; if (!$R['miss']) echo '<tr><td colspan="3" class="mu">הכול תואם.</td></tr>'; ?></table></div>
<p class="bar"><button name="act" value="apply" data-confirm="לבצע את הסנכרון? ניתן לראות כל שינוי בהערות התלמידה וביומן הפעולות.">ביצוע הסנכרון</button><button name="act" value="cancel" class="sec">ביטול</button></p>
</form>
<?php endif; page_foot();
