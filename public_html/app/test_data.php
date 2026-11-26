<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
if (!is_test_env()) { http_response_code(404); exit; }
$me = require_role(['admin']);
$msg = ''; $err = ''; $rep = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if ((string)($_POST['act'] ?? '') === 'mask') {
    if (trim((string)($_POST['sure'] ?? '')) !== 'טשטוש') $err = 'להרצה יש להקליד את המילה "טשטוש" בתיבה.';
    else { try { $rep = tm_run(); $msg = 'הטשטוש הסתיים.'; } catch (Throwable $t) { $err = $t->getMessage(); } }
  }
}
$when = setting_get('test_masked_at', ''); $guard = tm_guard();
$cnt = []; foreach (['students' => 'תלמידות', 'families' => 'משפחות', 'payments' => 'תשלומים', 'charges' => 'חיובים', 'budget_tx' => 'תנועות תקציב', 'budget_payables' => 'חשבוניות לתשלום', 'users' => 'משתמשים'] as $t => $l) { try { $cnt[$l] = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $x) { $cnt[$l] = '—'; } }
page_head('נתוני בדיקות');
?>
<h1>נתוני בדיקות</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="tiles"><?php foreach ($cnt as $l => $n) echo '<div class="tile"><span class="mu">' . e($l) . '</span><b>' . e((string)$n) . '</b></div>'; ?>
  <div class="tile"><span class="mu">טשטוש אחרון</span><b class="<?= $when ? 'ok' : 'er' ?>"><?= $when ? e(date('d/m/Y H:i', strtotime($when))) : 'עוד לא בוצע' ?></b></div></div>
<?php if ($rep): ?><div class="card"><h2>מה בוצע</h2><ul><?php foreach ($rep as $k => $v) echo '<li>' . e($k) . ': <b>' . (int)$v . '</b></li>'; ?></ul></div><?php endif; ?>
<div class="card"><h2>טשטוש הנתונים</h2>
<?php if ($guard): ?><p class="er"><?= e($guard) ?></p><?php else: ?>
<p>מחליף שמות (תלמידות, אבות, אימהות, אחיות, משפחות), טלפונים, ת"ז ומיילים בבדויים ועקביים (אותה משפחה מקבלת אותו שם משפחה), מחליף מספרי בתים (שמות הרחובות והערים נשארים), משאיר את הערות הכרטיס כמות שהן, מוחק מסמכים, דיוור, תזכורות וקבצים, ומחליף שמות ספקים ומקבלים בתקציב ל"ספק NNN". ההרצה חוזרת על עצמה בבטחה.</p>
<form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="act" value="mask"><label>להרצה, הקלידי: טשטוש<input name="sure" autocomplete="off" placeholder="טשטוש"></label><button data-confirm="לטשטש את כל הנתונים בסביבת הבדיקות?">הרצת טשטוש</button></form>
<?php endif; ?></div>
<div class="card"><h2>בדיקת שארית</h2><p class="mu">אחרי הטשטוש, כל השורות צריכות להראות 0 (חוץ ממשתמשים).</p>
<table><tr><th>בדיקה</th><th>חריגים</th></tr><?php foreach (tm_verify() as $i => [$l, $n]): $isU = str_starts_with($l, 'משתמשים'); ?><tr><td><?= e($l) ?></td><td class="n <?= $isU ? '' : ($n === 0 ? 'ok' : 'er') ?>"><?= (int)$n ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>איך מעתיקים נתונים לסביבת הבדיקות (פעם אחת)</h2>
<ol><li>באתר האמיתי: cPanel ← phpMyAdmin ← בוחרים את המסד האמיתי ← Export ← Custom.</li>
<li>מסמנים רק את הטבלאות: <span dir="ltr"><code>students, student_sibs, families, payers, charges, payments, month_marks, gad_months, budget_cats, budget_rules, budget_plan, budget_tx, budget_payables, budget_supplier_alias, budget_tomb, geo_cities, geo_streets</code></span>. אין לסמן משתמשים, הגדרות, יומנים וקבצים.</li>
<li>ב-Object creation options מבטלים את "Add CREATE TABLE" (משאירים Data בלבד), ומסמנים "Complete inserts".</li>
<li>בסביבת הבדיקות: phpMyAdmin ← המסד almascho_test ← Import ← הקובץ. אם מופיעה שגיאה על כפילות, מרוקנים קודם את הטבלה שנכשלה.</li>
<li>חוזרים לכאן, מריצים טשטוש, ומוודאים שבבדיקת השארית הכול 0. מוחקים את קובץ הייצוא מהמחשב.</li></ol></div>
<?php page_foot();
