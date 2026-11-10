<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'finance']);
$err = ''; $msg = ''; $prev = null; $token = '';
function istaged(string $t): ?string { return preg_match('/^[a-f0-9]{32}$/', $t) ? PRIV . '/storage/inc_' . $t . '.bin' : null; }
foreach (glob(PRIV . '/storage/inc_*.bin') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
function inc_fams(): array { $o = []; foreach (db()->query("SELECT f.id, f.name, (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM students s WHERE s.family_id = f.id AND s.archived_at IS NULL) names FROM families f ORDER BY f.name")->fetchAll() as $r) $o[(int)$r['id']] = $r; return $o; }
function inc_label(array $f): string { return $f['name'] . ' (#' . $f['id'] . ')'; }
$fams = inc_fams();
$mname = ['hok_card' => 'אשראי', 'hok_bank' => 'בנקאי', 'check' => 'צ׳ק', 'cash' => 'מזומן', 'other' => 'אחר'];
function inc_have(string $key, int $fid): bool { $q = db()->prepare("SELECT 1 FROM payments WHERE ref = ? AND family_id = ? AND batch LIKE 'inc%' AND voided_at IS NULL LIMIT 1"); $q->execute([$key, $fid]); return (bool)$q->fetchColumn(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? '');
  if ($act === 'preview') {
    $f = $_FILES['rep'] ?? null;
    if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
    elseif ($f['size'] > INCOME_MAX_BYTES) $err = 'הקובץ גדול מדי (עד 5MB).';
    elseif (!preg_match('/\.(xlsx|csv|txt)$/i', (string)$f['name'])) $err = 'סוגי קבצים נתמכים: xlsx או csv.';
    elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
    else {
      $res = income_parse($f['tmp_name'], (string)$f['name']);
      if ($res['fatal']) $err = $res['fatal'];
      else {
        $idx = income_index(); $rows = $res['rows'];
        foreach ($rows as $i => &$r) {
          $r['sel'] = income_match($r, $idx); $r['month'] = school_month_of($r['date']);
          $r['kind'] = $r['don'] ? 'x' : ($r['par'] ? 't' : 'c');
          $r['dup'] = false; foreach ($r['sel'] as $fid) if (inc_have($r['key'], $fid)) $r['dup'] = true;
          $r['on'] = count($r['sel']) === 1 && !$r['don'] && !$r['dup'];
        } unset($r);
        $prev = ['rows' => $rows, 'name' => (string)$f['name']]; $token = bin2hex(random_bytes(16)); $p = istaged($token);
        file_put_contents($p, enc_bytes(json_encode($rows, JSON_UNESCAPED_UNICODE), 'income:' . $token), LOCK_EX); @chmod($p, 0600);
        audit('income_preview', 'payment', '', count($rows) . ' שורות');
      }
    }
  } elseif ($act === 'cancel') { $p = istaged((string)($_POST['token'] ?? '')); if ($p && is_file($p)) @unlink($p); $msg = 'בוטל. לא נשמר דבר.'; }
  elseif ($act === 'confirm') {
    $token = (string)($_POST['token'] ?? ''); $p = istaged($token);
    $raw = ($p && is_file($p)) ? dec_bytes((string)file_get_contents($p), 'income:' . $token) : null;
    $rows = $raw ? json_decode($raw, true) : null;
    if (!is_array($rows)) $err = 'פג תוקף התצוגה המקדימה. העלי את הקובץ שוב.';
    else {
      $pdo = db(); $pdo->beginTransaction(); $bad = 0; $parked = 0; $st = ['n' => 0, 'dup' => 0, 'sum' => 0.0, 'newCh' => 0]; $batch = 'inc' . bin2hex(random_bytes(6));
      $med = []; foreach ($rows as $r) if (!$r['par'] && !$r['don']) $med[inc_cat($r['cat'])][] = $r['amt'];
      $medv = fn(string $c) => (function ($v) { sort($v); return $v ? $v[intdiv(count($v), 2)] : 0; })($med[$c] ?? []);
      foreach ($rows as $i => $r) {
        $kind = (string)($_POST['kind'][$i] ?? 't');
        if (!in_array($kind, ['t', 'c'], true)) continue;   // "לא לקלוט": לא נקלט ולא נשמר
        $ids = []; foreach (explode('+', (string)($_POST['fam'][$i] ?? '')) as $part) if (preg_match('/\(#(\d+)\)\s*$/', trim($part), $m) && isset($fams[(int)$m[1]])) $ids[(int)$m[1]] = true;
        $ids = array_keys($ids);
        if (empty($_POST['on'][$i]) || !$ids) {   // לא סומנה או בלי תיק: נשמרת לרשימת ההמתנה לשיוך (אלא אם כבר נקלטה בעבר)
          if (empty($r['dup']) && income_park($pdo, $r, (string)($_POST['rname'] ?? ''), (int)$u['id'])) $parked++;
          if (!empty($_POST['on'][$i]) && !$ids) $bad++;
          continue;
        }
        $mo = (int)($_POST['month'][$i] ?? 0); if ($mo < 1 || $mo > 12) $mo = 0;
        income_place($pdo, $r, $ids, $kind, $mo, $batch, (int)$u['id'], (float)$medv(inc_cat($r['cat'])), $st);
      }
      $n = $st['n']; $dup = $st['dup']; $sum = $st['sum']; $newCh = $st['newCh'];
      $pdo->commit(); @unlink($p);
      audit('income_import', 'payment', '', "נקלטו $n תשלומים ($sum ₪), כפולים $dup, ללא תיק $bad, חיובים חדשים $newCh, נשמרו להמשך שיוך $parked");
      $msg = "נקלטו $n תשלומים בסך " . money($sum) . ($dup ? ". דולגו כבר-נקלטו: $dup" : '') . ($parked ? ". $parked שורות שלא שויכו נשמרו ב\"שורות שממתינות לשיוך\"" : '') . ($newCh ? ". נוצרו $newCh חיובים חד־פעמיים חדשים" : '') . '.';
    }
  }
}
page_head('העלאת דוח הכנסות');
?>
<h1>העלאת דוח הכנסות מתוכנת הגבייה</h1>
<?php $pc = (int)db()->query('SELECT COUNT(*) FROM income_pending')->fetchColumn(); ?>
<p class="subnav"><a class="btn sec" href="collection.php">‹ לגבייה</a> <a class="btn sec" href="income_pending.php">שורות שממתינות לשיוך<?= $pc ? ' (' . $pc . ')' : '' ?></a></p>
<?php if ($msg) echo '<p class="ok">' . e($msg) . ' <a href="collection.php">לגבייה</a></p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<?php if (!$prev): ?>
<div class="card">
  <p>קובץ xlsx או csv של "דוח הכנסות". המערכת מזהה את המשפחה לפי ת.ז. (שלה, של האב או האם), טלפון, מייל, ושם ההורה או התלמידה בהערות. קטגוריה שאינה "תשלומי הורים" (למשל ספרי לימוד או סיור) נקלטת כחיוב חד־פעמי, ו"תרומה" לא נקלטת אלא אם בוחרים אחרת. אפשר לתקן כל התאמה לפני הקליטה, ושורה שכבר נקלטה לא נקלטת פעמיים.</p>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="preview">
    <div class="bar"><label>קובץ דוח<input type="file" name="rep" required accept=".xlsx,.csv,.txt"></label><button>תצוגה מקדימה</button></div></form>
</div>
<?php else:
  $tot = array_sum(array_column($prev['rows'], 'amt')); $ready = array_filter($prev['rows'], fn($r) => $r['on']); $unm = array_filter($prev['rows'], fn($r) => !$r['sel']); ?>
<div class="tiles">
  <div class="tile"><span class="mu">שורות בדוח</span><b><?= count($prev['rows']) ?></b></div>
  <div class="tile"><span class="mu">סכום הדוח</span><b><?= e(money($tot)) ?></b></div>
  <div class="tile g"><span class="mu">מזוהות לקליטה</span><b class="ok"><?= count($ready) ?></b></div>
  <div class="tile g"><span class="mu">סכום לקליטה</span><b class="ok"><?= e(money(array_sum(array_column($ready, 'amt')))) ?></b></div>
  <div class="tile p"><span class="mu">לא מזוהות</span><b class="er"><?= count($unm) ?></b></div>
</div>
<datalist id="fl"><?php foreach ($fams as $f) echo '<option value="' . e(inc_label($f)) . '">'; ?></datalist>
<form method="post"><?= csrf_field() ?> <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="rname" value="<?= e($prev['name'] ?? '') ?>">
  <div class="bar"><button name="act" value="confirm" data-confirm="לקלוט את השורות המסומנות?">קליטת המסומנות</button><button name="act" value="cancel" class="sec">ביטול</button></div>
  <p class="mu">שורות שלא תסומנה או שאין להן תיק נשמרות אוטומטית ב<a href="income_pending.php">"שורות שממתינות לשיוך"</a>, ואפשר לקלוט אותן אחר כך בלי להעלות את הדוח שוב. כדי לא לשמור שורה, בחרי בה "לא לקלוט".</p>
  <p class="mu">בעמודת התיק אפשר לחפש לפי שם משפחה ולבחור מהרשימה. לחלוקה בין כמה תיקים מפרידים ב-+. חודש "אוטומטי" משלים את החודשים הפתוחים לפי הסדר.</p>
  <div class="sc"><table><tr><th>קליטה</th><th>תאריך</th><th>משלם</th><th>הערה</th><th>קטגוריה</th><th>סכום</th><th>אמצעי</th><th>סוג</th><th>חודש</th><th>תיק / תיקים</th></tr>
  <?php foreach ($prev['rows'] as $i => $r): ?>
  <tr<?= $r['sel'] ? '' : ' class="unm"' ?>>
    <td><input type="checkbox" name="on[<?= $i ?>]" value="1"<?= $r['on'] ? ' checked' : '' ?>></td>
    <td><?= e(date_out($r['date'])) ?></td><td><?= e($r['donor']) ?><?= $r['dup'] ? ' <span class="pill">כבר נקלט</span>' : '' ?></td><td><?= e($r['note']) ?></td><td><?= e($r['cat']) ?></td><td><?= e(money($r['amt'])) ?></td><td><?= e($mname[$r['method']] ?? '') ?></td>
    <td><select name="kind[<?= $i ?>]"><option value="t"<?= $r['kind'] === 't' ? ' selected' : '' ?>>שכר לימוד</option><option value="c"<?= $r['kind'] === 'c' ? ' selected' : '' ?>>חיוב חד־פעמי</option><option value="x"<?= $r['kind'] === 'x' ? ' selected' : '' ?>>לא לקלוט</option></select></td>
    <td><select name="month[<?= $i ?>]"><option value="0">אוטומטי</option><?php foreach (SCHOOL_MONTHS as $k => $v) echo '<option value="' . $k . '"' . ($r['month'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></td>
    <td><input name="fam[<?= $i ?>]" list="fl" class="fam" value="<?= e(implode(' + ', array_map(fn($fid) => inc_label($fams[$fid]), array_filter($r['sel'], fn($x) => isset($fams[$x]))))) ?>" placeholder="לא זוהה – בחרי תיק"></td></tr>
  <?php endforeach; ?></table></div>
</form>
<?php endif; page_foot();
