<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'secretary']);
$who = (string)($u['name'] ?: $u['username']);
$err = ''; $msg = ''; $token = ''; $res = null;
function pstaged(string $t): ?string { return preg_match('/^[a-f0-9]{32}$/', $t) ? PRIV . '/storage/ph_' . $t . '.bin' : null; }
foreach (glob(PRIV . '/storage/ph_*.bin') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
/** מנתח את שורות האקסל: [שם, שם אב, טלפון אב, שם אם, טלפון אם] ומשווה לתלמידות במערכת. */
function ph_compute(array $rows): array {
  $hi = -1; $c = ['n' => 0, 'fn' => 1, 'fp' => 2, 'mn' => 3, 'mp' => 4];
  foreach ($rows as $i => $r) { $j = implode('|', array_map('strval', $r)); if (str_contains($j, 'טלפון')) { $hi = $i; foreach ($r as $k => $h) { $h = trim((string)$h);
      if ($h === 'שם האב') $c['fn'] = $k; elseif ($h === 'שם האם') $c['mn'] = $k; elseif (preg_match('/טלפון.*אב/u', $h)) $c['fp'] = $k; elseif (preg_match('/טלפון.*אם/u', $h)) $c['mp'] = $k; elseif (preg_match('/^(עמודה 1|שם|שם התלמידה|שם תלמידה)$/u', $h)) $c['n'] = $k; } break; } }
  $stu = db()->query('SELECT id, name, grade, cls, f_name, f_phone, m_name, m_phone FROM students WHERE archived_at IS NULL')->fetchAll();
  $key = fn(string $s) => implode(' ', array_slice((function ($t) { sort($t); return $t; })(moe_tk($s)), 0, 8));
  $byKey = []; foreach ($stu as $s) $byKey[$key($s['name'])][] = $s;
  $out = ['rows' => [], 'same' => 0, 'nf' => [], 'amb' => [], 'bad' => 0, 'seen' => []];
  foreach ($rows as $i => $r) {
    if ($i <= $hi) continue; $nm = trim((string)($r[$c['n']] ?? '')); if ($nm === '') continue;
    $ph = fn(string $k) => ($v = trim((string)($r[$c[$k]] ?? ''))) === '' ? '' : (is_numeric($v) ? norm_phone((string)(int)$v) : norm_phone($v));
    $fp = $ph('fp'); $mp = $ph('mp'); $fn = trim((string)($r[$c['fn']] ?? '')); $mn = trim((string)($r[$c['mn']] ?? ''));
    $cand = $byKey[$key($nm)] ?? [];
    if (!$cand) { foreach ($stu as $s) if (moe_same_name($nm, $s['name']) || moe_same_name($s['name'], $nm)) $cand[] = $s; }
    if (count($cand) > 1 && $fn !== '') { $f2 = array_values(array_filter($cand, fn($s) => moe_same_name($fn, (string)$s['f_name']) || moe_same_name((string)$s['f_name'], $fn))); if (count($f2) === 1) $cand = $f2; }
    if (!$cand) { $out['nf'][] = $nm; continue; }
    if (count($cand) > 1) { $out['amb'][] = $nm; continue; }
    $s = $cand[0]; $ch = [];
    foreach (['f_phone' => [$fp, 'אב'], 'm_phone' => [$mp, 'אם']] as $col => [$new, $lb]) {
      if ($new === '') continue; if (!phone_ok($new)) { $out['bad']++; continue; }
      $old = norm_phone((string)$s[$col]); if ($old === $new) continue;
      $ch[] = ['col' => $col, 'lb' => $lb, 'old' => (string)$s[$col], 'new' => $new, 'empty' => trim((string)$s[$col]) === ''];
    }
    $out['seen'][$s['id']] = 1;
    if (!$ch) { $out['same']++; continue; }
    $out['rows'][] = ['id' => (int)$s['id'], 'name' => $s['name'], 'cls' => $s['grade'] . ($s['cls'] !== '' ? "'" . $s['cls'] : ''), 'ch' => $ch, 'xn' => $nm];
  }
  return $out;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? '');
  if ($act === 'upload') {
    $f = $_FILES['xl'] ?? null;
    if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
    elseif ($f['size'] > 5 * 1024 * 1024) $err = 'הקובץ גדול מדי (עד 5MB).';
    elseif (!preg_match('/\.(xlsx|csv|txt)$/i', (string)$f['name'])) $err = 'סוגי קבצים נתמכים: xlsx או csv.';
    elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
    else {
      try { $rows = moe_read($f['tmp_name'], (string)$f['name']); } catch (Throwable $t) { $err = $t->getMessage(); $rows = []; }
      if (!$err) {
        $rows = array_map(fn($r) => array_map(fn($x) => mb_substr(trim((string)$x), 0, 200), is_array($r) ? array_slice($r, 0, 12) : []), array_slice($rows, 0, 5000));
        $token = bin2hex(random_bytes(16)); $p = pstaged($token);
        file_put_contents($p, enc_bytes(json_encode($rows, JSON_UNESCAPED_UNICODE), 'ph:' . $token), LOCK_EX); @chmod($p, 0600);
        $res = ph_compute($rows); audit('phones_preview', 'student', '', count($res['rows']) . ' אי-התאמות');
      }
    }
  } elseif ($act === 'apply') {
    $token = (string)($_POST['token'] ?? ''); $p = pstaged($token);
    $raw = $p && is_file($p) ? dec_bytes((string)file_get_contents($p), 'ph:' . $token) : null;
    if (!$raw) $err = 'פג תוקף התצוגה המקדימה. יש להעלות את הקובץ שוב.';
    else {
      $res = ph_compute(json_decode($raw, true) ?: []); $pick = (array)($_POST['pick'] ?? []); $n = 0; $ns = 0; $pdo = db(); $pdo->beginTransaction();
      foreach ($res['rows'] as $r) { $did = []; foreach ($r['ch'] as $c) { if (!isset($pick[$r['id'] . ':' . $c['col']])) continue;
          $pdo->prepare("UPDATE students SET {$c['col']} = ?, updated_at = ? WHERE id = ?")->execute([$c['new'], now(), $r['id']]); $did[] = $c['lb'] . ': ' . ($c['old'] === '' ? '(ריק)' : $c['old']) . ' ← ' . $c['new']; $n++; }
        if ($did) { $ns++; audit('student_phones', 'student', (string)$r['id'], mb_substr(implode('; ', $did), 0, 480)); } }
      $pdo->commit(); @unlink($p); $res = null; $token = ''; $msg = $n ? "עודכנו $n מספרי טלפון אצל $ns תלמידות." : 'לא נבחר דבר לעדכון.';
    }
  }
}
page_head('ייבוא טלפונים מאקסל');
?>
<h1>ייבוא טלפונים מאקסל</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<p class="subnav"><a class="btn sec" href="students.php">‹ לתלמידות</a> <a class="btn sec" href="moe_sync.php">סנכרון משרד החינוך</a></p>
<?php if (!$res): ?>
<div class="card"><p>העלי קובץ אקסל עם העמודות: <b>שם התלמידה</b> (שם משפחה ושם פרטי), <b>שם האב</b>, <b>טלפון אב</b>, <b>שם האם</b>, <b>טלפון אם</b>. המערכת תשווה לטלפונים השמורים, תציג רק את מי שהמספר שלו לא תואם, ותעדכן רק את מה שתאשרי.</p>
<form method="post" enctype="multipart/form-data" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="upload"><label>קובץ אקסל<input type="file" name="xl" required accept=".xlsx,.csv,text/csv"></label><button>תצוגה מקדימה</button></form>
<p class="mu">מספרים בפורמטים שונים (עם מקף, בלי אפס מוביל) מנורמלים לפני ההשוואה. מספר ריק בקובץ לא מוחק מספר קיים. כל עדכון נרשם ביומן הפעולות עם הערך הקודם.</p></div>
<?php else: $rs = $res['rows']; ?>
<div class="tiles">
  <div class="tile"><span class="mu">עם אי-התאמה</span><b class="<?= $rs ? 'er' : 'ok' ?>"><?= count($rs) ?></b></div>
  <div class="tile"><span class="mu">מספרים תואמים</span><b class="ok"><?= (int)$res['same'] ?></b></div>
  <div class="tile"><span class="mu">בקובץ ולא במערכת</span><b><?= count($res['nf']) ?></b></div>
  <div class="tile"><span class="mu">שם לא חד-משמעי</span><b><?= count($res['amb']) ?></b></div>
  <?php if ($res['bad']): ?><div class="tile"><span class="mu">מספר לא תקין בקובץ (דולג)</span><b><?= (int)$res['bad'] ?></b></div><?php endif; ?>
</div>
<?php if ($rs): ?>
<form method="post" class="autosave-off"><?= csrf_field() ?><input type="hidden" name="act" value="apply"><input type="hidden" name="token" value="<?= e($token) ?>">
<div class="bar"><label class="inl"><input type="checkbox" id="phall" checked> סימון הכול</label> <button data-confirm="לעדכן את המספרים המסומנים?">עדכון המסומנים</button> <a class="btn sec" href="phones_import.php">ביטול</a></div>
<div class="card sc"><table><tr><th></th><th>תלמידה</th><th>כיתה</th><th>טלפון</th><th>במערכת</th><th>בקובץ</th></tr>
<?php foreach ($rs as $r): foreach ($r['ch'] as $k => $c): ?>
<tr><td><input type="checkbox" name="pick[<?= $r['id'] . ':' . $c['col'] ?>]" value="1" class="phc" checked aria-label="עדכון"></td>
<td><?= $k === 0 ? '<a href="student.php?id=' . $r['id'] . '">' . e($r['name']) . '</a>' : '' ?></td><td><?= $k === 0 ? e($r['cls']) : '' ?></td><td><?= e($c['lb']) ?></td>
<td class="n"><bdi dir="ltr"><?= $c['empty'] ? '<span class="mu">ריק</span>' : e($c['old']) ?></bdi></td><td class="n ok"><bdi dir="ltr"><?= e(fmt_phone($c['new'])) ?></bdi></td></tr>
<?php endforeach; endforeach; ?></table></div></form>
<?php else: ?><p class="ok">כל המספרים בקובץ תואמים למה ששמור במערכת.</p><a class="btn sec" href="phones_import.php">העלאת קובץ אחר</a><?php endif; ?>
<?php if ($res['nf']): ?><details class="card"><summary>בקובץ ולא נמצאו במערכת (<?= count($res['nf']) ?>)</summary><p><?= e(implode(' · ', array_slice($res['nf'], 0, 300))) ?></p></details><?php endif; ?>
<?php if ($res['amb']): ?><details class="card"><summary>שם שמתאים ליותר מתלמידה אחת, לא עודכנו (<?= count($res['amb']) ?>)</summary><p><?= e(implode(' · ', $res['amb'])) ?></p></details><?php endif; ?>
<?php endif; ?>
<?php page_foot();
