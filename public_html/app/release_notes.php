<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
require_role(['admin']);
$f = PRIV . '/RELEASE_NOTES.md';
$raw = is_file($f) ? (string)file_get_contents($f) : '';
/** ממיר את תת-הקבוצה הפשוטה של Markdown שבמסמך: כותרות, רשימות, **מודגש** ו-`קוד`. הכול עובר escape. */
function rn_inline(string $s): string {
  $s = e($s);
  $s = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);
  return preg_replace('/`([^`]+)`/u', '<code dir="ltr">$1</code>', $s);
}
$intro = []; $vers = []; $cur = null; $sec = null;
foreach (preg_split('/\R/u', $raw) as $ln) {
  if (preg_match('/^## (.+?) – (\d{2}\/\d{2}\/\d{4})\s*$/u', $ln, $m)) { $cur = count($vers); $vers[] = ['v' => trim($m[1]), 'd' => $m[2], 'secs' => []]; $sec = null; continue; }
  if ($cur === null) { if (trim($ln) !== '' && $ln[0] !== '#') $intro[] = $ln; continue; }
  if (preg_match('/^### (.+)$/u', $ln, $m)) { $sec = count($vers[$cur]['secs']); $vers[$cur]['secs'][] = ['t' => trim($m[1]), 'items' => []]; continue; }
  if (preg_match('/^- (.+)$/u', $ln, $m) && $sec !== null) $vers[$cur]['secs'][$sec]['items'][] = $m[1];
}
$tagc = ['חדש' => 'new', 'שיפורים' => 'imp', 'תיקונים' => 'fix', 'אבטחה' => 'sec', 'חשוב' => 'sec'];
page_head('מה חדש');
?>
<h1>מה חדש <span class="mu">(הערות גרסה)</span></h1>
<?php if ($intro) echo '<p class="mu rnintro">' . implode('<br>', array_map('rn_inline', $intro)) . '</p>'; ?>
<?php if (!$vers) echo '<p class="er">קובץ הערות הגרסה לא נמצא בשרת.</p>'; ?>
<div class="rn">
<?php foreach ($vers as $i => $v): ?>
  <details class="fsec"<?= $i === 0 ? ' open' : '' ?>>
    <summary><span class="ico">v</span><bdi dir="ltr"><?= e($v['v']) ?></bdi><span class="sm2"><?= e($v['d']) ?></span></summary>
    <div class="bd">
    <?php foreach ($v['secs'] as $s): $cls = 'tg-' . ($tagc[trim(explode('(', $s['t'])[0])] ?? 'imp'); ?>
      <h3 class="tg <?= e($cls) ?>"><?= e($s['t']) ?></h3>
      <ul><?php foreach ($s['items'] as $it) echo '<li>' . rn_inline($it) . '</li>'; ?></ul>
    <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>
</div>
<?php page_foot();
