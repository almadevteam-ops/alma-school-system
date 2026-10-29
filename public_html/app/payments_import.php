<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
if (isset($_GET['template'])) {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="payments-template.csv"');
  echo "\xEF\xBB\xBF" . "תלמידה,חודש,סכום,תאריך,אמצעי,אסמכתא,הערה\nדנה כהן,ספטמבר,500,10/09/2026,מזומן,,\n";
  exit;
}
$err = ''; $msg = ''; $prev = null; $token = '';
function pstaged(string $t): ?string { return preg_match('/^[a-f0-9]{32}$/', $t) ? PRIV . '/storage/pay_' . $t . '.bin' : null; }
foreach (glob(PRIV . '/storage/pay_*.bin') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
function payimp_key(array $r): string { return implode('|', [$r['family_id'], number_format((float)$r['amount'], 2, '.', ''), $r['date'], $r['method'], (int)($r['month'] ?? 0), (int)($r['charge_id'] ?? 0)]); }
/** כמה תשלומים זהים כבר קיימים במערכת (לא מבוטלים) לכל מפתח, כדי לא להכפיל בייבוא חוזר. שורות זהות בתוך הקובץ עצמו (למשל 250 + 250) נספרות בנפרד. */
function payimp_existing(PDO $pdo, array $rows): array {
  $st = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE family_id = ? AND amount = ? AND paid_on = ? AND method = ? AND IFNULL(for_month,0) = ? AND IFNULL(charge_id,0) = ? AND voided_at IS NULL');
  $have = [];
  foreach ($rows as $r) { if ($r['errors']) continue; $k = payimp_key($r); if (isset($have[$k])) continue;
    $st->execute([$r['family_id'], $r['amount'], $r['date'], $r['method'], (int)($r['month'] ?? 0), (int)($r['charge_id'] ?? 0)]); $have[$k] = (int)$st->fetchColumn(); }
  return $have;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'preview') {
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
    elseif ($f['size'] > IMPORT_MAX_BYTES) $err = 'הקובץ גדול מדי.';
    elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
    else {
      $raw = (string)file_get_contents($f['tmp_name']); $prev = payimp_parse($raw);
      if ($prev['fatal']) { $err = $prev['fatal']; $prev = null; }
      else { $token = bin2hex(random_bytes(16)); $p = pstaged($token); file_put_contents($p, enc_bytes($raw, 'payimp:' . $token), LOCK_EX); @chmod($p, 0600); audit('payimport_preview', 'payment', '', count($prev['rows']) . ' שורות'); }
    }
  } elseif ($act === 'confirm') {
    $token = (string)($_POST['token'] ?? ''); $p = pstaged($token);
    if (!$p || !is_file($p)) $err = 'פג תוקף התצוגה המקדימה. העלי את הקובץ שוב.';
    else {
      $raw = dec_bytes((string)file_get_contents($p), 'payimp:' . $token);
      if ($raw === null || $raw === false) $err = 'הקובץ הזמני פגום. העלי שוב.';
      else {
        $res = payimp_parse($raw); $voided = 0; $n = 0; $skip = 0; $dup = 0; $pdo = db(); $batch = bin2hex(random_bytes(8)); $pdo->beginTransaction();
        try {
          if (!empty($_POST['replace'])) { $vs = $pdo->prepare("UPDATE payments SET voided_at = ?, voided_by = ? WHERE batch LIKE 'imp%' AND payer_id IS NULL AND voided_at IS NULL"); $vs->execute([now(), $u['id']]); $voided = $vs->rowCount(); }
          $have = payimp_existing($pdo, $res['rows']);
          $ins = $pdo->prepare('INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, batch, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
          foreach ($res['rows'] as $r) {
            if ($r['errors']) { $skip++; continue; }
            $k = payimp_key($r); if (($have[$k] ?? 0) > 0) { $have[$k]--; $dup++; continue; }
            $ins->execute([$r['family_id'], $r['charge_id'], $r['month'], $r['amount'], $r['date'], $r['method'], $r['ref'], $r['note'], 'imp' . substr($batch, 0, 13), $u['id'], now()]); $n++;
          }
          $pdo->commit();
        } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
        @unlink($p); audit('payimport', 'payment', '', "נרשמו $n, כפולות $dup, שגויות $skip, בוטלו מייבוא קודם $voided");
        $msg = "נרשמו $n תשלומים. דולגו $dup כפולים ו-$skip שורות עם שגיאות." . ($voided ? " בוטלו $voided תשלומים מייבוא קודם." : ''); $token = '';
      }
    }
  } elseif ($act === 'cancel') { $p = pstaged((string)($_POST['token'] ?? '')); if ($p && is_file($p)) @unlink($p); $msg = 'בוטל. לא נשמר דבר.'; }
}
page_head('ייבוא תשלומים');
?>
<h1>ייבוא תשלומים</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . ' <a href="collection.php">לגבייה</a></p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<?php if (!$prev): ?>
<div class="card">
  <p>קובץ CSV (UTF-8), שורה לכל תשלום. חובה: <b>תלמידה</b> (אחת מהתלמידות בתיק), <b>סכום</b>, וגם <b>חודש</b> או <b>תאריך</b>. אופציונלי: אמצעי, אסמכתא, הערה, <b>עבור</b> (תחילת שם חיוב קיים בתיק, למשל "ספרי לימוד", כדי לשייך את התשלום אליו).</p>
  <p class="mu">אם אין תאריך, יירשם ראשון החודש כתאריך משוער. אותו תשלום (תיק, סכום, תאריך, אמצעי, חודש) לא ייכנס פעמיים. לא נשמרים מספרי כרטיס.</p>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="preview">
    <div class="bar"><label>קובץ CSV<input type="file" name="csv" required accept=".csv,text/csv,text/plain"></label><button>תצוגה מקדימה</button><a class="btn sec" href="payments_import.php?template=1">תבנית לדוגמה</a></div></form>
</div>
<?php else: $ok = 0; $bad = 0; $tot = 0.0; foreach ($prev['rows'] as $r) { if ($r['errors']) $bad++; else { $ok++; $tot += $r['amount']; } } ?>
<div class="card">
  <h2>תצוגה מקדימה</h2>
  <p><?= count($prev['rows']) ?> שורות: <b><?= $ok ?></b> יירשמו (סה״כ <?= e(money($tot)) ?>), <b><?= $bad ?></b> עם שגיאה וידולגו.</p>
  <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <label class="chk"><input type="checkbox" name="replace" value="1"> להחליף ייבוא קודם: לבטל קודם את כל התשלומים שנוצרו בייבואי תשלומים קודמים (תשלומים שהוזנו ידנית לא ייגעו)</label>
    <button name="act" value="confirm"<?= $ok ? '' : ' disabled' ?>>אישור וייבוא <?= $ok ?> תשלומים</button><button name="act" value="cancel" class="sec">ביטול</button></form>
  <div class="sc"><table><tr><th>שורה</th><th>תלמידה</th><th>חודש</th><th>סכום</th><th>תאריך</th><th>אמצעי</th><th>הערה</th><th>מצב</th></tr>
  <?php foreach ($prev['rows'] as $r): ?><tr><td><?= (int)$r['line'] ?></td><td><?= e($r['student']) ?></td><td><?= e($r['month'] ? SCHOOL_MONTHS[$r['month']] : '') ?></td><td><?= e(money($r['amount'])) ?></td><td><?= e(date_out($r['date'])) ?></td><td><?= e(method_label($r['method'])) ?></td><td><?= e($r['note']) ?></td>
    <td><?= $r['errors'] ? '<span class="er">' . e(implode(' | ', $r['errors'])) . '</span>' : 'תקין' ?></td></tr><?php endforeach; ?></table></div>
</div>
<?php endif; page_foot();
