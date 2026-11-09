<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(COLLECTION_ROLES);
$cur = school_month_now();
$ids = array_map('intval', db()->query("SELECT DISTINCT f.id FROM families f JOIN students s ON s.family_id = f.id AND s.archived_at IS NULL")->fetchAll(PDO::FETCH_COLUMN));
$data = collection_data($ids);
$meth = db()->query('SELECT id, method FROM families')->fetchAll(PDO::FETCH_KEY_PAIR);
// בחירת חודשים (קיבוץ חופשי). ברירת מחדל: כל השנה.
$pick = isset($_GET['m']) ? array_values(array_unique(array_filter(array_map('intval', (array)$_GET['m']), fn($x) => $x >= 1 && $x <= 12))) : range(1, 12);
if (!$pick) $pick = range(1, 12);
sort($pick);
$z = fn() => ['ex' => 0.0, 'co' => 0.0, 'miss' => 0.0, 'wip' => 0.0, 'ref' => 0.0, 'fut' => 0.0, 'n' => 0];
$S = []; for ($m = 1; $m <= 12; $m++) $S[$m] = $z();
$fam = ['paid' => 0, 'part' => 0, 'none' => 0, 'fut' => 0]; $byMeth = [];
foreach ($data as $fid => $d) {
  $fx = 0.0; $fc = 0.0; $due = 0; $dueFull = 0; $dueAny = 0;
  foreach ($d['status'] as $m => $st) {
    if ($st === 'na') continue;
    $ex = $d['monthly']; $co = min((float)$d['cov'][$m], $ex); $gap = $ex - $co;
    $S[$m]['n']++; $S[$m]['ex'] += $ex; $S[$m]['co'] += $co;
    if ($st === 'wip') $S[$m]['wip'] += $gap; elseif ($st === 'ref') $S[$m]['ref'] += $gap; elseif ($st === 'fut') $S[$m]['fut'] += $gap; elseif ($st !== 'paid') $S[$m]['miss'] += $gap;
    if (in_array($m, $pick, true)) {
      $fx += $ex; $fc += $co;
      if ($st !== 'fut') { $due++; if ($st === 'paid') $dueFull++; if ($co > 0.004) $dueAny++; }
    }
  }
  if ($fx <= 0.004) continue;
  if ($due === 0) $fam['fut']++; elseif ($dueFull === $due) $fam['paid']++; elseif ($dueAny > 0) $fam['part']++; else $fam['none']++;
  $k = (string)($meth[$fid] ?? ''); $byMeth[$k] ??= ['n' => 0, 'ex' => 0.0, 'co' => 0.0]; $byMeth[$k]['n']++; $byMeth[$k]['ex'] += $fx; $byMeth[$k]['co'] += $fc;
}
$T = $z(); foreach ($pick as $m) foreach ($T as $k => $_) $T[$k] += $S[$m][$k];
$pct = fn(float $a, float $b): int => $b > 0.004 ? (int)round($a / $b * 100) : 0;
$q = fn(array $ms) => 'dashboard.php?' . http_build_query(['m' => $ms]);
$presets = ['כל השנה' => range(1, 12), 'עד היום' => range(1, $cur), 'רבעון 1 (ספט׳–נוב׳)' => [1, 2, 3], 'רבעון 2 (דצמ׳–פבר׳)' => [4, 5, 6], 'רבעון 3 (מרץ–מאי)' => [7, 8, 9], 'רבעון 4 (יוני–אוג׳)' => [10, 11, 12], 'מחצית א׳ (ספט׳–ינו׳)' => [1, 2, 3, 4, 5], 'מחצית ב׳ (פבר׳–אוג׳)' => [6, 7, 8, 9, 10, 11, 12]];
$gl = count($pick) === 12 ? 'כל השנה' : implode(', ', array_map(fn($m) => SCHOOL_MONTHS[$m], $pick));
$bar = fn(float $a, float $b) => '<svg class="pbar" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true"><rect class="bg" width="100" height="8"/><rect class="fg" width="' . min(100, $pct($a, $b)) . '" height="8"/></svg>';
page_head('דשבורד גבייה');
?>
<h1>דשבורד גבייה <span class="mu">(שכר לימוד לאחר הנחות)</span></h1>
<p class="subnav"><a class="btn sec" href="collection.php">‹ לגבייה</a> <a class="btn sec" href="monthly.php">סיכום חודשי</a></p>
<form class="bar auto dashf" method="get" action="dashboard.php">
  <fieldset class="mpick"><legend>חודשים בדשבורד (אפשר לסמן כמה שרוצים)</legend>
  <?php foreach (SCHOOL_MONTHS as $m => $nm) echo '<label class="chk"><input type="checkbox" name="m[]" value="' . $m . '"' . (in_array($m, $pick, true) ? ' checked' : '') . '> ' . e($nm) . ($m === $cur ? ' <span class="mu">(עכשיו)</span>' : '') . '</label>'; ?>
  </fieldset>
  <button>הצגה</button>
</form>
<p class="presets"><?php foreach ($presets as $l => $ms) echo '<a class="btn sec' . ($ms === $pick ? ' hot' : '') . '" href="' . e($q($ms)) . '">' . e($l) . '</a> '; ?></p>
<h2>סך הכול: <?= e($gl) ?></h2>
<div class="tiles">
  <div class="tile"><span class="mu">צפוי</span><b><?= e(money($T['ex'])) ?></b></div>
  <div class="tile g"><span class="mu">נגבה (כולל מראש) · <?= $pct($T['co'], $T['ex']) ?>%</span><b class="ok"><?= e(money($T['co'])) ?></b></div>
  <div class="tile p"><span class="mu">חסר – הגיע זמנו ולא שולם</span><b class="er"><?= e(money($T['miss'])) ?></b></div>
  <div class="tile w"><span class="mu">בטיפול / החזר</span><b><?= e(money($T['wip'])) ?> / <?= e(money($T['ref'])) ?></b></div>
  <div class="tile"><span class="mu">עתידי – עוד לא הגיע זמנו</span><b class="mu"><?= e(money($T['fut'])) ?></b></div>
</div>
<div class="tiles">
  <div class="tile g"><span class="mu">תיקים ששילמו הכול</span><b><?= $fam['paid'] ?></b></div>
  <div class="tile w"><span class="mu">שילמו חלקית</span><b><?= $fam['part'] ?></b></div>
  <div class="tile"><span class="mu">לא שילמו כלל</span><b class="<?= $fam['none'] ? 'er' : '' ?>"><?= $fam['none'] ?></b></div>
  <div class="tile"><span class="mu">עוד לא הגיע זמנם</span><b class="mu"><?= $fam['fut'] ?></b></div>
</div>
<?php sec_open('bym', 'לפי חודש', 'החודשים שנבחרו מסומנים', '▦', ['bym']); ?>
<div class="sc"><table class="dtab"><tr><th>חודש</th><th>צפוי</th><th>נגבה</th><th>חסר</th><th>בטיפול</th><th>החזר</th><th>עתידי</th><th>%</th><th class="wbar"></th></tr>
<?php foreach (SCHOOL_MONTHS as $m => $nm): $r = $S[$m]; $on = in_array($m, $pick, true); ?>
  <tr class="<?= $on ? 'sel' : 'off' ?><?= $m > $cur ? ' futrow' : '' ?>"><td><b><?= e($nm) ?></b><?= $m > $cur ? ' <span class="mu">(עתידי)</span>' : '' ?></td><td><?= e(money($r['ex'])) ?></td><td class="ok"><?= e(money($r['co'])) ?></td><td class="<?= $r['miss'] > 0.004 ? 'er' : '' ?>"><?= e(money($r['miss'])) ?></td><td><?= e(money($r['wip'])) ?></td><td><?= e(money($r['ref'])) ?></td><td class="mu"><?= e(money($r['fut'])) ?></td><td><?= $pct($r['co'], $r['ex']) ?>%</td><td class="wbar"><?= $bar($r['co'], $r['ex']) ?></td></tr>
<?php endforeach; ?>
  <tr class="tot"><td><b>סה״כ נבחר</b></td><td><?= e(money($T['ex'])) ?></td><td class="ok"><?= e(money($T['co'])) ?></td><td class="<?= $T['miss'] > 0.004 ? 'er' : '' ?>"><?= e(money($T['miss'])) ?></td><td><?= e(money($T['wip'])) ?></td><td><?= e(money($T['ref'])) ?></td><td class="mu"><?= e(money($T['fut'])) ?></td><td><?= $pct($T['co'], $T['ex']) ?>%</td><td class="wbar"><?= $bar($T['co'], $T['ex']) ?></td></tr>
</table></div>
<p class="mu">"חסר" כולל רק חודשים שהגיע זמנם. חודש עתידי שכבר שולם מראש נספר תחת "נגבה".</p>
<?php sec_close(); ?>
<?php sec_open('bymeth', 'לפי צורת תשלום', 'בחודשים שנבחרו', '◎'); ?>
<div class="sc"><table class="dtab"><tr><th>צורת תשלום</th><th>תיקים</th><th>צפוי</th><th>נגבה</th><th>%</th><th class="wbar"></th></tr>
<?php uasort($byMeth, fn($a, $b) => $b['ex'] <=> $a['ex']); foreach ($byMeth as $k => $r): ?>
  <tr><td><?= $k !== '' ? e(method_label($k)) : '<span class="mu">לא הוגדר</span>' ?></td><td><?= $r['n'] ?></td><td><?= e(money($r['ex'])) ?></td><td class="ok"><?= e(money($r['co'])) ?></td><td><?= $pct($r['co'], $r['ex']) ?>%</td><td class="wbar"><?= $bar($r['co'], $r['ex']) ?></td></tr>
<?php endforeach; if (!$byMeth) echo '<tr><td class="mu">אין נתונים.</td></tr>'; ?></table></div>
<?php sec_close(); page_foot();
