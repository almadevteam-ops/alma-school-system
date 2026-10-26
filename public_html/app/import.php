<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
if (isset($_GET['template'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="students-template.csv"');
  echo "\xEF\xBB\xBF" . implode(',', array_values(IMPORT_FIELDS)) . "\n";
  echo 'דנה כהן,ט,,000000018,,הרצל 1,חיפה,,050-0000000,יוסי כהן,050-0000001,,רחל כהן,050-0000002,,כללית,נשואים,פעילה,' . "\n";
  exit;
}
$err = ''; $msg = ''; $prev = null; $token = '';
function staged_path(string $t): ?string { return preg_match('/^[a-f0-9]{32}$/', $t) ? PRIV . '/storage/imp_' . $t . '.bin' : null; }
function existing_students(): array { return db()->query('SELECT name, grade, idn FROM students')->fetchAll(); }
// ניקוי קבצי ייבוא ישנים (מעל שעה)
foreach (glob(PRIV . '/storage/imp_*.bin') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'preview') {
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
    elseif ($f['size'] > IMPORT_MAX_BYTES) $err = 'הקובץ גדול מדי.';
    elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
    else {
      $raw = (string)file_get_contents($f['tmp_name']);
      $prev = import_parse($raw, existing_students());
      if ($prev['fatal']) { $err = $prev['fatal']; $prev = null; }
      else {
        $token = bin2hex(random_bytes(16));
        $p = staged_path($token);
        file_put_contents($p, enc_bytes($raw, 'import:' . $token), LOCK_EX); @chmod($p, 0600);
        audit('import_preview', 'student', '', count($prev['rows']) . ' שורות');
      }
    }
  } elseif ($act === 'confirm') {
    $token = (string)($_POST['token'] ?? ''); $p = staged_path($token);
    if (!$p || !is_file($p)) $err = 'פג תוקף התצוגה המקדימה. העלי את הקובץ שוב.';
    else {
      $raw = dec_bytes((string)file_get_contents($p), 'import:' . $token);
      if ($raw === null || $raw === false) $err = 'הקובץ הזמני פגום. העלי שוב.';
      else {
        $res = import_parse($raw, existing_students());
        $n = 0; $skipDup = 0; $skipErr = 0; $now = now();
        $pdo = db(); $pdo->beginTransaction();
        try {
          $cols = array_keys(IMPORT_FIELDS); $ph = implode(',', array_fill(0, count($cols), '?'));
          $ins = $pdo->prepare('INSERT INTO students (' . implode(',', $cols) . ', created_at, updated_at) VALUES (' . $ph . ', ?, ?)');
          foreach ($res['rows'] as $r) {
            if ($r['errors']) { $skipErr++; continue; }
            if ($r['dup']) { $skipDup++; continue; }
            $ins->execute([...array_values(array_map(fn($k) => mb_substr($r['d'][$k], 0, IMPORT_MAXLEN[$k]), $cols)), $now, $now]); $n++;
          }
          $pdo->commit();
        } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
        @unlink($p);
        audit('import', 'student', '', "נוספו $n, דולגו כפולות $skipDup, דולגו שגויות $skipErr");
        $msg = "הייבוא הסתיים: נוספו $n תלמידות. דולגו $skipDup כפולות ו-$skipErr שורות עם שגיאות.";
        $token = '';
      }
    }
  } elseif ($act === 'cancel') {
    $p = staged_path((string)($_POST['token'] ?? '')); if ($p && is_file($p)) @unlink($p);
    $msg = 'הייבוא בוטל. לא נשמר דבר.';
  }
}
page_head('ייבוא תלמידות');
?>
<h1>ייבוא תלמידות מקובץ</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . ' <a href="students.php">לרשימת התלמידות</a></p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<?php if (!$prev): ?>
<div class="card">
  <p>מעלים קובץ <b>CSV</b> (באקסל: שמירה בשם ← CSV UTF-8). שורה ראשונה היא כותרות. חובה: <b>שם</b> ו<b>כיתה</b>. כל השאר אופציונלי.</p>
  <p class="mu">קודם תוצג תצוגה מקדימה, ורק אחרי אישור שלך יישמר משהו. תלמידה שכבר קיימת (לפי ת״ז, או שם וכיתה כשאין ת״ז) לא תוחלף אלא תדולג.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="act" value="preview">
    <div class="bar"><label>קובץ CSV<input type="file" name="csv" required accept=".csv,text/csv,text/plain"></label><button>תצוגה מקדימה</button>
    <a class="btn sec" href="import.php?template=1">הורדת תבנית לדוגמה</a></div>
  </form>
</div>
<?php else:
  $ok = 0; $dup = 0; $bad = 0; $warn = 0;
  foreach ($prev['rows'] as $r) { if ($r['errors']) $bad++; elseif ($r['dup']) $dup++; else { $ok++; if ($r['warns']) $warn++; } }
?>
<div class="card">
  <h2>תצוגה מקדימה</h2>
  <p>סה״כ <?= count($prev['rows']) ?> שורות: <b><?= $ok ?></b> ייובאו (מתוכן <?= $warn ?> עם אזהרה), <b><?= $dup ?></b> יידולגו ככפולות, <b><?= $bad ?></b> עם שגיאה ויידולגו.</p>
  <?php if ($prev['unknown']): ?><p class="mu">עמודות שלא זוהו ולא ייובאו: <?= e(implode(', ', $prev['unknown'])) ?></p><?php endif; ?>
  <div class="sc"><table>
    <tr><th>שורה</th><th>שם</th><th>כיתה</th><th>עיר</th><th>ת״ז</th><th>מצב</th></tr>
    <?php foreach ($prev['rows'] as $r): $d = $r['d'];
      $state = $r['errors'] ? '<span class="er">שגיאה: ' . e(implode(', ', $r['errors'])) . '</span>' : ($r['dup'] ? '<span class="mu">דילוג: ' . e($r['dup']) . '</span>' : ($r['warns'] ? '<span class="mu">אזהרה: ' . e(implode(', ', $r['warns'])) . '</span>' : 'תקין')); ?>
    <tr><td><?= (int)$r['line'] ?></td><td><?= e($d['name']) ?></td><td><?= e($d['grade']) ?></td><td><?= e($d['city']) ?></td><td><?= $d['idn'] === '' ? '' : e(str_repeat('•', max(0, strlen($d['idn']) - 3)) . substr($d['idn'], -3)) ?></td><td><?= $state ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <form method="post" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <button name="act" value="confirm"<?= $ok ? '' : ' disabled' ?>>אישור וייבוא <?= $ok ?> תלמידות</button>
    <button name="act" value="cancel" class="sec">ביטול</button>
  </form>
</div>
<?php endif;
page_foot();
