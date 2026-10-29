<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
if (isset($_GET['template'])) {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="collection-template.csv"');
  echo "\xEF\xBB\xBF" . "תלמידות,שם משפחה,תעריף חודשי,חודשים,צורת תשלום,יום גבייה,גורם משלם,הנחה,הערה\n";
  echo "דנה כהן; רונית כהן,משפחת כהן,900,10,אשראי,10,,הנחת אחיות,\n";
  exit;
}
$err = ''; $msg = ''; $prev = null; $token = '';
function cstaged(string $t): ?string { return preg_match('/^[a-f0-9]{32}$/', $t) ? PRIV . '/storage/col_' . $t . '.bin' : null; }
foreach (glob(PRIV . '/storage/col_*.bin') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'preview') {
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
    elseif ($f['size'] > IMPORT_MAX_BYTES) $err = 'הקובץ גדול מדי.';
    elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
    else {
      $raw = (string)file_get_contents($f['tmp_name']); $prev = colimp_parse($raw);
      if ($prev['fatal']) { $err = $prev['fatal']; $prev = null; }
      else { $token = bin2hex(random_bytes(16)); $p = cstaged($token); file_put_contents($p, enc_bytes($raw, 'colimp:' . $token), LOCK_EX); @chmod($p, 0600); audit('colimport_preview', 'family', '', count($prev['rows']) . ' שורות'); }
    }
  } elseif ($act === 'confirm') {
    $token = (string)($_POST['token'] ?? ''); $p = cstaged($token);
    if (!$p || !is_file($p)) $err = 'פג תוקף התצוגה המקדימה. העלי את הקובץ שוב.';
    else {
      $raw = dec_bytes((string)file_get_contents($p), 'colimp:' . $token);
      if ($raw === null || $raw === false) $err = 'הקובץ הזמני פגום. העלי שוב.';
      else {
        $res = colimp_parse($raw); $n = 0; $skip = 0; $pdo = db(); $pdo->beginTransaction();
        try {
          $pf = $pdo->prepare('SELECT id FROM payers WHERE name = ?'); $pi = $pdo->prepare('INSERT INTO payers (name, notes, created_at) VALUES (?,?,?)');
          $fi = $pdo->prepare('INSERT INTO families (name, monthly, months, method, collect_day, payer_id, payer_kind, discount_note, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
          $su = $pdo->prepare('UPDATE students SET family_id = ? WHERE id = ? AND family_id IS NULL');
          foreach ($res['rows'] as $r) {
            if ($r['errors']) { $skip++; continue; }
            $pid = null;
            if ($r['payer'] !== '') { $pf->execute([$r['payer']]); $pid = $pf->fetchColumn() ?: null; if (!$pid) { $pi->execute([mb_substr($r['payer'], 0, 100), '', now()]); $pid = (int)$pdo->lastInsertId(); } }
            $fi->execute([mb_substr($r['name'], 0, 120), $r['monthly'], $r['months'], $r['method'], $r['day'], $pid, $pid ? 'org' : 'parents', $r['discount'], $r['notes'] === '' ? null : $r['notes'], now(), now()]);
            $fid = (int)$pdo->lastInsertId();
            foreach ($r['students'] as $sid) $su->execute([$fid, $sid]);
            $n++;
          }
          $pdo->commit();
        } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
        @unlink($p); audit('colimport', 'family', '', "נוצרו $n תיקים, דולגו $skip");
        $msg = "נוצרו $n תיקי גבייה. דולגו $skip שורות עם שגיאות."; $token = '';
      }
    }
  } elseif ($act === 'cancel') { $p = cstaged((string)($_POST['token'] ?? '')); if ($p && is_file($p)) @unlink($p); $msg = 'בוטל. לא נשמר דבר.'; }
}
page_head('ייבוא תיקי גבייה');
?>
<h1>ייבוא תיקי גבייה</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . ' <a href="collection.php">לגבייה</a></p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<?php if (!$prev): ?>
<div class="card">
  <p>מעלים קובץ CSV (UTF-8). כל שורה היא תיק משפחה: התלמידות מופרדות ב-<b>;</b>, ותעריף חודשי למשפחה. חובה: <b>תלמידות</b>, <b>תעריף חודשי</b>. אופציונלי: שם משפחה, חודשים (10/12), צורת תשלום, יום גבייה, גורם משלם, הנחה, הערה.</p>
  <p class="mu">קודם תוצג תצוגה מקדימה. תלמידה שכבר בתיק גבייה לא תועבר. לא נשמר שום מספר כרטיס.</p>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="preview">
    <div class="bar"><label>קובץ CSV<input type="file" name="csv" required accept=".csv,text/csv,text/plain"></label><button>תצוגה מקדימה</button><a class="btn sec" href="collection_import.php?template=1">תבנית לדוגמה</a></div></form>
</div>
<?php else: $ok = 0; $bad = 0; foreach ($prev['rows'] as $r) { if ($r['errors']) $bad++; else $ok++; } ?>
<div class="card">
  <h2>תצוגה מקדימה</h2>
  <p><?= count($prev['rows']) ?> שורות: <b><?= $ok ?></b> ייווצרו, <b><?= $bad ?></b> עם שגיאה וידולגו.</p>
  <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <button name="act" value="confirm"<?= $ok ? '' : ' disabled' ?>>אישור וייבוא <?= $ok ?> תיקים</button><button name="act" value="cancel" class="sec">ביטול</button></form>
  <div class="sc"><table><tr><th>שורה</th><th>משפחה</th><th>תלמידות</th><th>תעריף</th><th>אמצעי</th><th>יום</th><th>גורם משלם</th><th>מצב</th></tr>
  <?php foreach ($prev['rows'] as $r): ?><tr><td><?= (int)$r['line'] ?></td><td><?= e($r['name']) ?></td><td><?= e(implode(', ', $r['student_names'])) ?></td><td><?= e(money($r['monthly'])) ?> × <?= (int)$r['months'] ?></td><td><?= e(method_label($r['method'])) ?></td><td><?= e((string)($r['day'] ?? '')) ?></td><td><?= e($r['payer']) ?></td>
    <td><?= $r['errors'] ? '<span class="er">' . e(implode(' | ', $r['errors'])) . '</span>' : 'תקין' ?></td></tr><?php endforeach; ?></table></div>
</div>
<?php endif; page_foot();
