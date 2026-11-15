<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$by = (string)($me['name'] ?: $me['username']); $pdo = db();
$flash = function (string $m, bool $ok = true) { $_SESSION['bmsg'] = [$ok, $m]; redirect('budget_cats.php'); };
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? ''); $cats = budget_cats();
  if (isset($_POST['delrule'])) { $pdo->prepare('DELETE FROM budget_rules WHERE id=?')->execute([(int)$_POST['delrule']]); audit('budget_rule_del', 'budget', (string)(int)$_POST['delrule'], 'נמחק כלל שיוך'); $flash('הכלל נמחק.'); }
  if (isset($_POST['delcat'])) {
    $c = (string)$_POST['delcat'];
    $q = $pdo->prepare('SELECT COUNT(*) FROM budget_tx WHERE category=?'); $q->execute([$c]); $n = (int)$q->fetchColumn();
    $q = $pdo->prepare('SELECT COUNT(*) FROM budget_rules WHERE category=?'); $q->execute([$c]); $r = (int)$q->fetchColumn();
    if ($n) $flash("אי אפשר למחוק את \"$c\": יש בה $n תנועות. אפשר לשנות לה שם (גם לשם של קטגוריה אחרת כדי לאחד) או לשייך את התנועות מחדש.", false);
    if ($r) $flash("אי אפשר למחוק את \"$c\": יש $r כללי שיוך שמפנים אליה. מחקי או שני אותם קודם.", false);
    $pdo->prepare('DELETE FROM budget_cats WHERE name=?')->execute([$c]); audit('budget_cat_del', 'budget', '', $c); $flash("הקטגוריה \"$c\" נמחקה.");
  }
  if ($act === 'cat_add') {
    $c = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    if ($c === '') $flash('כתבי שם קטגוריה.', false); if (in_array($c, $cats, true)) $flash('הקטגוריה כבר קיימת.', false);
    budget_cat_ensure($c); audit('budget_cat_add', 'budget', '', $c); $flash("הקטגוריה \"$c\" נוספה.");
  } elseif ($act === 'cat_ren') {
    $old = (string)($_POST['old'] ?? ''); $new = mb_substr(trim((string)($_POST['new'] ?? '')), 0, 100);
    if (!in_array($old, $cats, true) || $new === '') $flash('שם לא תקין.', false); if ($new === $old) $flash('השם לא השתנה.', false);
    $merge = in_array($new, $cats, true);
    $pdo->beginTransaction();
    try {
      $pdo->prepare('UPDATE budget_tx SET category=? WHERE category=?')->execute([$new, $old]); $pdo->prepare('UPDATE budget_rules SET category=? WHERE category=?')->execute([$new, $old]);
      if ($merge) $pdo->prepare('DELETE FROM budget_cats WHERE name=?')->execute([$old]); else $pdo->prepare('UPDATE budget_cats SET name=? WHERE name=?')->execute([$new, $old]);
      $pdo->commit();
    } catch (Throwable $t) { $pdo->rollBack(); $flash('השינוי נכשל.', false); }
    audit('budget_cat_ren', 'budget', '', "$old → $new" . ($merge ? ' (איחוד)' : '')); $flash($merge ? "\"$old\" אוחדה לתוך \"$new\"." : "השם שונה ל\"$new\".");
  } elseif ($act === 'units') {
    $u = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 60), explode("\n", str_replace("\r", '', (string)($_POST['units'] ?? ''))))))); if (!$u) $flash('חייבת להישאר לפחות יחידה אחת.', false);
    $in = implode(',', array_fill(0, count($u), '?')); $q = $pdo->prepare("SELECT unit, COUNT(*) n FROM budget_tx WHERE unit NOT IN ($in) GROUP BY unit"); $q->execute($u); $miss = $q->fetchAll();
    if ($miss) $flash('אי אפשר להסיר יחידה שיש בה תנועות: ' . implode(', ', array_map(fn($m) => $m['unit'] . ' (' . $m['n'] . ')', $miss)), false);
    setting_set('budget_units', implode("\n", $u), $by); audit('budget_units', 'budget', '', implode(', ', $u)); $flash('היחידות נשמרו.');
  } elseif ($act === 'rule_add') {
    $k = mb_substr(trim((string)($_POST['kw'] ?? '')), 0, 100); $c = (string)($_POST['c'] ?? '');
    if ($k === '' || !in_array($c, $cats, true)) $flash('יש למלא מילת מפתח ולבחור קטגוריה.', false);
    $o = (int)$pdo->query('SELECT COALESCE(MAX(ord),0) FROM budget_rules')->fetchColumn() + 10;
    $pdo->prepare('INSERT INTO budget_rules (ord, kw, category) VALUES (?,?,?)')->execute([$o, $k, $c]); audit('budget_rule_add', 'budget', '', "$k → $c"); $flash('הכלל נוסף (בסוף הרשימה).');
  } elseif ($act === 'rules_save') {
    $ids = array_map('intval', (array)($_POST['ids'] ?? [])); $ord = (array)($_POST['ord'] ?? []); $kw = (array)($_POST['kw'] ?? []); $cc = (array)($_POST['c'] ?? []); $n = 0;
    $up = $pdo->prepare('UPDATE budget_rules SET ord=?, kw=?, category=? WHERE id=?');
    foreach ($ids as $id) { $k = mb_substr(trim((string)($kw[$id] ?? '')), 0, 100); $c = (string)($cc[$id] ?? ''); if ($k === '' || !in_array($c, $cats, true)) continue; $up->execute([max(0, min(1000000, (int)($ord[$id] ?? 0))), $k, $c, $id]); $n++; }
    audit('budget_rules_save', 'budget', '', "נשמרו $n כללים"); $flash('הכללים נשמרו.');
  } elseif ($act === 'rules_apply') {
    $rules = null; $q = $pdo->query("SELECT id, name, descr FROM budget_tx WHERE category=''"); $up = $pdo->prepare('UPDATE budget_tx SET category=?, updated_at=? WHERE id=?'); $n = 0; $tot = 0;
    foreach ($q->fetchAll() as $r) { $tot++; $c = budget_auto($r['name'], $r['descr'], $rules); if ($c !== '') { $up->execute([$c, now(), $r['id']]); $n++; } }
    audit('budget_rules_apply', 'budget', '', "שויכו $n מתוך $tot"); $flash("שויכו $n מתוך $tot תנועות שלא היו משויכות.");
  }
  redirect('budget_cats.php');
}
$msg = $_SESSION['bmsg'] ?? null; unset($_SESSION['bmsg']);
$cats = budget_cats(); $units = budget_units();
$use = []; foreach ($pdo->query("SELECT category k, COUNT(*) n FROM budget_tx GROUP BY category")->fetchAll() as $r) $use[$r['k']] = (int)$r['n'];
$rules = $pdo->query('SELECT * FROM budget_rules ORDER BY ord, id')->fetchAll();
$opt = fn(string $sel) => implode('', array_map(fn($x) => '<option' . ($x === $sel ? ' selected' : '') . '>' . e($x) . '</option>', $cats));
page_head('תקציב – קטגוריות');
?>
<h1>תקציב – קטגוריות, יחידות וכללי שיוך</h1>
<?php if ($msg) echo '<p class="' . ($msg[0] ? 'ok' : 'er') . '">' . e($msg[1]) . '</p>'; ?>
<div class="card"><h2>קטגוריות</h2>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="cat_add"><label>קטגוריה חדשה<input name="name" maxlength="100" required></label><button>הוספה</button></form>
  <div class="sc"><table><tr><th>קטגוריה</th><th>תנועות</th><th>שינוי שם / איחוד</th><th></th></tr>
  <?php if (($use[''] ?? 0) > 0) echo '<tr class="unm"><td>לא משויך</td><td>' . $use[''] . '</td><td colspan="2"><a href="budget_tx.php?y=all&amp;cat=__un">לצפייה</a></td></tr>'; ?>
  <?php foreach ($cats as $c): ?><tr><td><?= e($c) ?></td><td><?= $use[$c] ?? 0 ?></td>
    <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="cat_ren"><input type="hidden" name="old" value="<?= e($c) ?>"><input name="new" maxlength="100" placeholder="שם חדש (או שם קיים לאיחוד)" required> <button class="sec">שינוי</button></form></td>
    <td><?php if (empty($use[$c])): ?><form method="post" class="inl"><?= csrf_field() ?><button class="dg" name="delcat" value="<?= e($c) ?>" data-confirm="למחוק את הקטגוריה?">מחיקה</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div>
</div>
<div class="card"><h2>יחידות</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="units"><label>יחידה בכל שורה<textarea name="units" rows="4" cols="30"><?= e(implode("\n", $units)) ?></textarea></label><p class="mu">אי אפשר להסיר יחידה שכבר יש בה תנועות.</p><button>שמירה</button></form>
</div>
<div class="card"><h2>כללי שיוך אוטומטי</h2>
  <p class="mu">כשמוסיפים או מייבאים תנועה בלי קטגוריה, המערכת בודקת אם מילת המפתח מופיעה בשם או בתיאור. הכלל הראשון שמתאים (לפי הסדר, מהמספר הקטן) קובע.</p>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="rule_add"><label>מילת מפתח<input name="kw" maxlength="100" required></label><label>קטגוריה<select name="c"><?= $opt('') ?></select></label><button>הוספת כלל</button></form>
  <form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="rules_apply"><button class="sec" data-confirm="להפעיל את הכללים על כל התנועות שאינן משויכות?">הפעלת הכללים על תנועות שאינן משויכות</button></form>
  <form method="post" class="dirty"><?= csrf_field() ?><input type="hidden" name="act" value="rules_save">
  <div class="bar"><button class="sv" disabled>שמירת שינויים בכללים</button></div>
  <div class="sc"><table><tr><th>סדר</th><th>מילת מפתח</th><th>קטגוריה</th><th></th></tr>
  <?php foreach ($rules as $r): $id = (int)$r['id']; ?><tr><td><input type="hidden" name="ids[]" value="<?= $id ?>"><input name="ord[<?= $id ?>]" value="<?= (int)$r['ord'] ?>" inputmode="numeric" size="5"></td><td><input name="kw[<?= $id ?>]" value="<?= e($r['kw']) ?>" maxlength="100"></td><td><select name="c[<?= $id ?>]"><?= $opt($r['category']) ?></select></td>
    <td><button class="dg" name="delrule" value="<?= $id ?>" data-confirm="למחוק את הכלל?" formnovalidate>מחיקה</button></td></tr><?php endforeach; ?>
  </table></div></form>
</div>
<?php page_foot();
