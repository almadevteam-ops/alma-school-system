<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if (($_POST['act'] ?? '') === 'void_all') {
    if ($u['role'] !== 'admin') fail_page(403, 'פעולה למנהלת בלבד.');
    $label = (string)($_POST['label'] ?? '');
    $c = db()->prepare('UPDATE charges SET voided_at = ? WHERE label = ? AND voided_at IS NULL'); $c->execute([now(), $label]);
    audit('charge_void_all', 'family', '', $label . ': ' . $c->rowCount()); $msg = 'בוטלו ' . $c->rowCount() . ' חיובים "' . $label . '" (התשלומים נשמרים).';
  }
  if (($_POST['act'] ?? '') === 'pay_charges') {
    $label = (string)($_POST['label'] ?? ''); $date = date_in((string)($_POST['paid_on'] ?? '')); $m = (string)($_POST['method'] ?? '');
    if ($date === null) $err = 'התאריך לא תקין (למשל 12/11/2026).';
    elseif ($m !== '' && !isset(PAY_METHODS[$m])) $err = 'אמצעי תשלום לא תקין.';
    else {
      $pdo = db(); $pdo->beginTransaction(); $n = 0; $sum = 0.0; $bad = 0;
      try {
        $sel1 = $pdo->prepare("SELECT c.id, c.family_id, c.amount, c.disc_amt, c.mark, f.method fm, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0) paid FROM charges c JOIN families f ON f.id = c.family_id WHERE c.id = ? AND c.label = ? AND c.voided_at IS NULL FOR UPDATE");
        $ins = $pdo->prepare('INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, created_by, created_at) VALUES (?,?,NULL,?,?,?,\'\',?,?,?)');
        $ids = array_unique(array_merge(array_keys((array)($_POST['pay'] ?? [])), array_keys(array_filter((array)($_POST['amt'] ?? []), fn($x) => trim((string)$x) !== ''))));
        foreach ($ids as $cid) {
          $cid = (int)$cid; $sel1->execute([$cid, $label]); $r = $sel1->fetch(); if (!$r || $r['mark'] === 'n') { $bad++; continue; }
          $typed = trim((string)($_POST['amt'][$cid] ?? ''));
          if ($typed !== '') { $a = money_in($typed); if ($a === null || $a <= 0 || $a > 1000000) { $bad++; continue; } }
          else { $a = round((float)$r['amount'] - (float)$r['disc_amt'] - (float)$r['paid'], 2); if ($a <= 0.004) continue; }      // סימון "שולם" = משלימים את היתרה
          $method = $m !== '' ? $m : (isset(PAY_METHODS[$r['fm']]) ? $r['fm'] : 'other');
          $ins->execute([(int)$r['family_id'], $cid, $a, $date, $method, 'סומן בגבייה חד־פעמית', $u['id'], now()]); $n++; $sum += $a;
        }
        $pdo->commit();
      } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
      audit('charge_pay_bulk', 'family', '', "$label: $n תשלומים, " . money($sum));
      redirect('charges.php?l=' . rawurlencode($label) . '&m=' . rawurlencode($n ? "נרשמו $n תשלומים בסך " . money($sum) . '.' : 'לא נרשם שום תשלום (לא סומנו שורות עם יתרה או סכום).') . ($bad ? '&b=' . $bad : ''));
    }
  }
}

$CI = null;   // תצוגה מקדימה של עדכון מקובץ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['act'] ?? ''), ['ci_preview', 'ci_apply'], true)) {
  csrf_check(); $clabel = (string)($_POST['label'] ?? '');
  $ck = db()->prepare('SELECT 1 FROM charges WHERE label = ? AND voided_at IS NULL LIMIT 1'); $ck->execute([$clabel]);
  if (!$ck->fetchColumn()) { $err = 'הגבייה לא נמצאה.'; }
  elseif ($_POST['act'] === 'ci_preview') {
    $fl = $_FILES['file'] ?? null;
    if (!$fl || ($fl['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($fl['error'] ?? UPLOAD_ERR_NO_FILE));
    elseif (!preg_match('/\.(xlsx|csv)$/i', (string)$fl['name'])) $err = 'אפשר להעלות רק xlsx או csv.';
    elseif ((int)$fl['size'] > INCOME_MAX_BYTES) $err = 'הקובץ גדול מדי.';
    else {
      try {
        $fr = ci_read((string)$fl['tmp_name'], (string)$fl['name']); $idx = ci_index($clabel); $used = []; $CI = ['label' => $clabel, 'rows' => [], 'maps' => []];
        foreach ($fr as $i => [$nm, $st, $mt]) {
          $r = ['i' => $i, 'name' => $nm, 'st' => ci_status($st), 'stx' => $st, 'mt' => $mt, 'kind' => '', 'cands' => [], 'sid' => 0, 'amt' => '', 'chk' => false, 'note' => ''];
          if ($r['st'] === 'unpaid') $r['kind'] = 'unpaid';
          elseif ($r['st'] === '') { $r['kind'] = 'unknown'; $r['note'] = 'סטטוס לא מובן: "' . $st . '"'; }
          else {
            if ($mt !== '' && !isset($CI['maps'][$mt])) $CI['maps'][$mt] = ci_method_guess($mt);
            if (ci_is_summary($nm)) { $r['kind'] = 'summary'; $r['note'] = 'שורה מסכמת או אנונימית: לא ניתן לשייך לתלמידה, יש לסמן ידנית בטבלה למטה.'; }
            else {
              [$tier, $c] = ci_match($nm, $idx);
              if (!$c) { $r['kind'] = 'none'; $r['note'] = 'לא נמצאה תלמידה פעילה בשם הזה.'; }
              else {
                $ok = array_values(array_filter($c, fn($x) => $x['cid'] !== null && $x['mark'] !== 'n'));
                $r['cands'] = $c;
                if (count($c) > 1) { $r['kind'] = 'ambig'; $r['note'] = 'כמה תלמידות מתאימות. יש לבחור.'; }
                elseif (!$ok) { $r['kind'] = 'nocharge'; $r['note'] = 'לתלמידה ' . $c[0]['name'] . ' אין חיוב "' . $clabel . '" בתיק (או שהוא "לא רלוונטי").'; }
                else {
                  $x = $ok[0]; $r['sid'] = (int)$x['sid']; $fid = (int)$x['fid']; $left = round((float)$x['amount'] - (float)$x['disc_amt'] - (float)$x['paid'] - ($used[$fid] ?? 0), 2);
                  if ($left <= 0.004) { $r['kind'] = 'done'; $r['note'] = 'החיוב בתיק כבר שולם במלואו.'; }
                  else { $a = ci_default_amount($x, $used[$fid] ?? 0.0); $used[$fid] = ($used[$fid] ?? 0) + $a; $r['kind'] = $tier === 3 ? 'ok' : 'partial'; $r['amt'] = rtrim(rtrim(number_format($a, 2, '.', ''), '0'), '.'); $r['chk'] = $tier === 3; $r['note'] = $tier === 3 ? '' : 'התאמה חלקית של השם. לבדוק.'; }
                }
              }
            }
          }
          $CI['rows'][] = $r;
        }
      } catch (Throwable $t) { $err = 'הקריאה נכשלה: ' . $t->getMessage(); $CI = null; }
    }
    $_GET['l'] = $clabel;
  } else {   // ci_apply
    $date = date_in((string)($_POST['paid_on'] ?? '')); $maps = (array)($_POST['map'] ?? []);
    if ($date === null) { $err = 'התאריך לא תקין (למשל 12/11/2026).'; $_GET['l'] = $clabel; }
    else {
      $idx = []; foreach (ci_index($clabel) as $x) $idx[(int)$x['sid']] = $x;
      $pdo = db(); $pdo->beginTransaction(); $n = 0; $sum = 0.0; $rep = []; $usedF = []; $seen = [];
      $dupq = $pdo->prepare('SELECT 1 FROM payments WHERE charge_id = ? AND note = ? AND voided_at IS NULL LIMIT 1');
      $ins = $pdo->prepare('INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, created_by, created_at) VALUES (?,?,NULL,?,?,?,\'\',?,?,?)');
      try {
        foreach ((array)($_POST['do'] ?? []) as $i => $_) {
          $nm = mb_substr((string)($_POST['n'][$i] ?? ''), 0, 100); $sid = (int)($_POST['c'][$i] ?? 0); $x = $idx[$sid] ?? null;
          if (!$x || $x['cid'] === null || $x['mark'] === 'n') { $rep[] = "$nm: דולגה (לא נבחרה תלמידה עם חיוב בגבייה הזאת)."; continue; }
          if (isset($seen[$sid])) { $rep[] = "$nm: דולגה (התלמידה " . $x['name'] . ' כבר נרשמה בשורה אחרת).'; continue; } $seen[$sid] = 1;
          $note = 'עדכון מקובץ: ' . $nm; $dupq->execute([(int)$x['cid'], $note]); if ($dupq->fetchColumn()) { $rep[] = "$nm: דולגה (כבר נרשם תשלום מהקובץ הזה)."; continue; }
          $fid = (int)$x['fid']; $left = round((float)$x['amount'] - (float)$x['disc_amt'] - (float)$x['paid'] - ($usedF[$fid] ?? 0), 2);
          $typed = trim((string)($_POST['a'][$i] ?? ''));
          if ($typed !== '') { $a = money_in($typed); if ($a === null || $a <= 0 || $a > 1000000) { $rep[] = "$nm: דולגה (סכום לא תקין)."; continue; } }
          else $a = ci_default_amount($x, $usedF[$fid] ?? 0.0);
          if ($left <= 0.004) { $rep[] = "$nm: דולגה (החיוב בתיק כבר שולם במלואו)."; continue; }
          if ($a <= 0.004) { $rep[] = "$nm: דולגה (אין סכום לרישום)."; continue; }
          if ($a > $left + 0.004) { $rep[] = "$nm: דולגה (הסכום " . money($a) . ' גבוה מהיתרה ' . money($left) . ').'; continue; }
          $raw = (string)($_POST['fm'][$i] ?? ''); $mk = (string)($maps[$raw] ?? ''); if (!isset(PAY_METHODS[$mk])) $mk = 'other';
          $ins->execute([$fid, (int)$x['cid'], $a, $date, $mk, $note, $u['id'], now()]); $usedF[$fid] = ($usedF[$fid] ?? 0) + $a; $n++; $sum += $a;
        }
        $pdo->commit();
      } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
      audit('charge_pay_file', 'family', '', "$clabel: $n תשלומים מקובץ, " . money($sum) . ($rep ? ', דולגו ' . count($rep) : ''));
      $_SESSION['ci_rep'] = $rep;
      redirect('charges.php?l=' . rawurlencode($clabel) . '&m=' . rawurlencode($n ? "נרשמו $n תשלומים מהקובץ בסך " . money($sum) . '.' : 'לא נרשם שום תשלום.'));
    }
  }
}
$msg = $msg ?: (string)($_GET['m'] ?? ''); if (!empty($_GET['b'])) $err = $err ?: ((int)$_GET['b'] . ' שורות דולגו (סכום לא תקין או חיוב שאינו רלוונטי).');
$G = db()->query("SELECT c.label, COUNT(*) n, SUM(CASE WHEN c.mark='n' THEN 0 ELSE c.amount - c.disc_amt END) ex,
  SUM(CASE WHEN c.mark='w' THEN GREATEST(0, c.amount - c.disc_amt - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0)) ELSE 0 END) wip,
  SUM(COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0)) paid, MAX(c.amount) mx
  FROM charges c WHERE c.voided_at IS NULL GROUP BY c.label ORDER BY MIN(c.id)")->fetchAll();
$sel = (string)($_GET['l'] ?? '');
page_head('גביות חד־פעמיות');
?>
<h1>גביות חד־פעמיות</h1>
<p class="subnav"><a class="btn sec" href="collection.php">‹ לגבייה</a> <a class="btn sec" href="collection.php?open=bulk#s-bulk">הוספת חיוב לכל התיקים</a></p>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="sc"><table><tr><th>גבייה</th><th>תיקים</th><th>צפוי</th><th>נגבה</th><th>בטיפול</th><th>חסר</th><th></th></tr>
<?php foreach ($G as $g): $miss = max(0, (float)$g['ex'] - (float)$g['paid']); ?>
<tr><td><b><?= e($g['label']) ?></b></td><td><?= (int)$g['n'] ?></td><td><?= e(money($g['ex'])) ?></td><td><?= e(money($g['paid'])) ?></td><td><?= e(money($g['wip'])) ?></td><td><b class="<?= $miss > 0.004 ? 'er' : 'ok' ?>"><?= e(money($miss)) ?></b></td>
<td><a class="btn sec" href="charges.php?l=<?= e(rawurlencode($g['label'])) ?>">פירוט</a>
<?php if ($u['role'] === 'admin'): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="void_all"><input type="hidden" name="label" value="<?= e($g['label']) ?>"><button class="dg" data-confirm="לבטל את כל החיובים &quot;<?= e($g['label']) ?>&quot;?">מחיקה</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$G) echo '<tr><td colspan="7" class="mu">אין עדיין גביות חד־פעמיות.</td></tr>'; ?></table></div>
<p class="mu">כל גבייה מופיעה גם בכרטיס הגבייה של כל משפחה, ושם אפשר לערוך סכום והנחה לתלמידה/משפחה ולסמן בטיפול / החזר / לא רלוונטי.</p>
<?php if ($sel !== ''):
  $q = db()->prepare("SELECT c.id, c.family_id, f.name, (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM students s WHERE s.family_id = f.id AND s.archived_at IS NULL) studs, c.amount, c.disc_amt, c.mark, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.charge_id = c.id AND p.voided_at IS NULL),0) paid FROM charges c JOIN families f ON f.id = c.family_id WHERE c.label = ? AND c.voided_at IS NULL ORDER BY f.name"); $q->execute([$sel]); $rows = $q->fetchAll(); ?>
<?php $ciRep = $_SESSION['ci_rep'] ?? []; unset($_SESSION['ci_rep']); if ($ciRep): ?><div class="card"><b>שורות שלא נרשמו (<?= count($ciRep) ?>):</b><ul><?php foreach ($ciRep as $t) echo '<li>' . e($t) . '</li>'; ?></ul></div><?php endif; ?>
<details class="card"<?= $CI ? ' open' : '' ?>><summary><b>עדכון תשלומים מקובץ (xlsx / csv)</b></summary>
  <form method="post" enctype="multipart/form-data" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="ci_preview"><input type="hidden" name="label" value="<?= e($sel) ?>">
    <label>קובץ עם העמודות: שם התלמידה, סטטוס (שולם / לא שולם), צורת תשלום<input type="file" name="file" accept=".xlsx,.csv" required></label><button>תצוגה מקדימה</button></form>
  <p class="mu">הקובץ לא נרשם עד שמאשרים. המערכת מתאימה כל שם לתלמידה (בכל סדר של שם פרטי ומשפחה, ותוך סבלנות לשגיאת כתיב קטנה), ובשורות לא ברורות מבקשת בחירה. רק שורות "שולם" נרשמות, וכל תשלום נרשם בתיק של התלמידה ונראה בכרטיס הגבייה שלו.</p>
<?php if ($CI): $cnt = array_count_values(array_column($CI['rows'], 'kind')); ?>
  <p><b><?= count($CI['rows']) ?></b> שורות בקובץ: <?= (int)(($cnt['ok'] ?? 0) + ($cnt['partial'] ?? 0)) ?> זוהו, <?= (int)($cnt['ambig'] ?? 0) ?> דורשות בחירה, <?= (int)(($cnt['none'] ?? 0) + ($cnt['summary'] ?? 0) + ($cnt['nocharge'] ?? 0)) ?> לא זוהו או בלי חיוב, <?= (int)($cnt['done'] ?? 0) ?> כבר שולמו, <?= (int)(($cnt['unpaid'] ?? 0) + ($cnt['unknown'] ?? 0)) ?> לא שולמו (לא יירשמו).</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ci_apply"><input type="hidden" name="label" value="<?= e($sel) ?>">
  <div class="bar"><label>תאריך התשלום<input name="paid_on" value="<?= e(date('d/m/Y')) ?>" required></label>
  <?php foreach ($CI['maps'] as $raw => $g): ?><label>"<?= e((string)$raw) ?>" נרשם כ<select name="map[<?= e((string)$raw) ?>]"><?php foreach (PAY_METHODS as $k => $v) echo '<option value="' . $k . '"' . ($k === $g ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label><?php endforeach; ?>
  <button data-confirm="לרשום את התשלומים שסומנו?">רישום התשלומים שסומנו</button></div>
  <div class="sc"><table><tr><th>רישום</th><th>שם בקובץ</th><th>צורת תשלום</th><th>התלמידה במערכת</th><th>סכום</th><th>הערה</th></tr>
  <?php foreach ($CI['rows'] as $r): if (in_array($r['kind'], ['unpaid'], true)) continue; $i = (int)$r['i']; ?>
  <tr<?= in_array($r['kind'], ['none', 'summary', 'ambig', 'nocharge', 'unknown'], true) ? ' class="unm"' : '' ?>>
    <td><?php if (in_array($r['kind'], ['ok', 'partial', 'ambig'], true)): ?><input type="checkbox" name="do[<?= $i ?>]" value="1"<?= $r['chk'] ? ' checked' : '' ?>><input type="hidden" name="n[<?= $i ?>]" value="<?= e($r['name']) ?>"><input type="hidden" name="fm[<?= $i ?>]" value="<?= e($r['mt']) ?>"><?php endif; ?></td>
    <td><?= e($r['name']) ?></td><td><?= e($r['mt']) ?></td>
    <td><?php if ($r['kind'] === 'ambig'): ?><select name="c[<?= $i ?>]"><option value="">— בחרי —</option><?php foreach ($r['cands'] as $c) echo '<option value="' . (int)$c['sid'] . '">' . e($c['name'] . ' · ' . $c['grade'] . ' · משפחת ' . $c['fname'] . ($c['cid'] === null ? ' (אין חיוב)' : '')) . '</option>'; ?></select>
      <?php elseif ($r['sid']): ?><input type="hidden" name="c[<?= $i ?>]" value="<?= (int)$r['sid'] ?>"><?= e($r['cands'][0]['name']) ?> · <?= e($r['cands'][0]['grade']) ?> · משפחת <?= e($r['cands'][0]['fname']) ?><?php endif; ?></td>
    <td><?php if (in_array($r['kind'], ['ok', 'partial', 'ambig'], true)): ?><input name="a[<?= $i ?>]" value="<?= e($r['amt']) ?>" inputmode="decimal" size="6" aria-label="סכום"><?php endif; ?></td>
    <td class="mu"><?= e($r['note']) ?></td></tr>
  <?php endforeach; ?></table></div>
  <div class="bar"><button data-confirm="לרשום את התשלומים שסומנו?">רישום התשלומים שסומנו</button></div>
  <p class="mu">הסכום המוצע לתלמידה הוא חלקה בחיוב של התיק (חיוב לכל תלמידה), לא יותר מהיתרה. אפשר לשנות. שורה שכבר נרשמה מאותו קובץ לא נרשמת פעמיים.</p>
  </form>
<?php endif; ?>
</details>
<h2><?= e($sel) ?> – פירוט לפי משפחה</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="pay_charges"><input type="hidden" name="label" value="<?= e($sel) ?>">
<div class="bar">
  <label>תאריך התשלום<input name="paid_on" value="<?= e(date('d/m/Y')) ?>" required></label>
  <label>אמצעי תשלום<select name="method"><option value="">לפי אמצעי התשלום של התיק</option><?php foreach (PAY_METHODS as $k => $v) if ($k !== 'payer') echo '<option value="' . $k . '">' . e($v) . '</option>'; ?></select></label>
  <button>רישום התשלומים שסומנו</button>
</div>
<?php $stBy = []; if ($rows) { $in = implode(',', array_map('intval', array_unique(array_column($rows, 'family_id')))); foreach (db()->query("SELECT id, family_id, name FROM students WHERE archived_at IS NULL AND family_id IN ($in) ORDER BY name")->fetchAll() as $sr) $stBy[(int)$sr['family_id']][] = $sr; }
  $bk = rawurlencode('charges.php?l=' . rawurlencode($sel)); ?>
<div class="sc"><table><tr><th>משפחה</th><th>תלמידות</th><th>סכום</th><th>הנחה</th><th>שולם</th><th>יתרה</th><th>מצב</th><th>שולם ✓</th><th>סכום ששולם (אם שונה מהיתרה)</th></tr>
<?php foreach ($rows as $r): $net = (float)$r['amount'] - (float)$r['disc_amt']; $left = max(0, round($net - (float)$r['paid'], 2)); $st = $r['mark'] === 'n' ? 'לא רלוונטי' : ($r['paid'] >= $net - 0.004 ? 'שולם' : ($r['mark'] === 'r' ? 'החזר' : ($r['mark'] === 'w' || $r['paid'] > 0 ? 'בטיפול' : 'לא שולם'))); $cid = (int)$r['id']; ?>
<tr><td><a href="family.php?id=<?= (int)$r['family_id'] ?>&open=chg"><?= e($r['name']) ?></a></td><td class="mu"><?php foreach ($stBy[(int)$r['family_id']] ?? [] as $k => $sr) echo ($k ? ', ' : '') . '<a href="student.php?id=' . (int)$sr['id'] . '&amp;back=' . $bk . '">' . e($sr['name']) . '</a>'; ?></td><td><?= e(money($r['amount'])) ?></td><td><?= (float)$r['disc_amt'] > 0 ? e(money($r['disc_amt'])) : '' ?></td><td><?= e(money($r['paid'])) ?></td><td><?= $left > 0.004 ? e(money($left)) : '' ?></td><td><?= e($st) ?></td>
<?php if ($r['mark'] === 'n'): ?><td></td><td></td>
<?php else: ?><td><?php if ($left > 0.004): ?><input type="checkbox" name="pay[<?= $cid ?>]" value="1" aria-label="שולם"><?php else: ?><span class="ok">✓</span><?php endif; ?></td>
<td><input name="amt[<?= $cid ?>]" inputmode="decimal" size="7" placeholder="<?= $left > 0.004 ? e(rtrim(rtrim(number_format($left, 2, '.', ''), '0'), '.')) : '' ?>" aria-label="סכום ששולם"></td><?php endif; ?></tr>
<?php endforeach; ?></table></div>
<div class="bar"><button>רישום התשלומים שסומנו</button></div>
<p class="mu">סימון "שולם ✓" רושם תשלום בגובה היתרה. אם שולם סכום אחר (חלקי או יותר), מקלידים אותו בעמודה האחרונה ואין צורך לסמן. אפשר לסמן ולהזין כמה שורות ולשמור בבת אחת. התשלום נרשם גם בכרטיס הגבייה של המשפחה, ושם אפשר לבטל אותו.</p>
</form>
<?php endif; page_foot();
