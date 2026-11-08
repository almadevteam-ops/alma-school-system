<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
$who = (string)($u['name'] ?: $u['username']); $msg = ''; $err = '';
const RO_CONFIRM = 'מעבר שנה';
function ro_stats(): array {
  $q = fn(string $s) => (int)db()->query($s)->fetchColumn();
  return [
    'adv' => $q("SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND status = 'active' AND grade IN ('ז','ח','ט','י','יא')"),
    'grad' => $q("SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND status = 'active' AND grade = 'יב'"),
    'left' => $q("SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND status = 'left'"),
    'pend' => $q("SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND status = 'pending'"),
    'pay' => $q('SELECT COUNT(*) FROM payments'), 'chg' => $q('SELECT COUNT(*) FROM charges'), 'mk' => $q('SELECT COUNT(*) FROM month_marks'),
    'fam_empty' => $q("SELECT COUNT(*) FROM families f WHERE NOT EXISTS (SELECT 1 FROM students s WHERE s.family_id = f.id AND s.archived_at IS NULL AND s.status = 'active' AND s.grade <> 'יב')"),
  ];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if (trim((string)($_POST['confirm'] ?? '')) !== RO_CONFIRM) $err = 'כדי לבצע, יש להקליד בדיוק: ' . RO_CONFIRM;
  else {
    [$ok, $bm] = bk_run();
    if (!$ok) $err = 'לא בוצע מעבר שנה כי הגיבוי נכשל: ' . $bm;
    else {
      $pdo = db(); $gl = ['ז' => 'ח', 'ח' => 'ט', 'ט' => 'י', 'י' => 'יא', 'יא' => 'יב']; $n = ['adv' => 0, 'arch' => 0];
      $pdo->beginTransaction();
      try {
        $dt = date('d/m/Y');
        $arch = $pdo->prepare('UPDATE students SET archived_at = ?, archived_by = ?, updated_at = ? WHERE id = ?'); $note = $pdo->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)');
        foreach ($pdo->query("SELECT id, status, grade FROM students WHERE archived_at IS NULL AND status IN ('active','left')")->fetchAll() as $s) {
          if ($s['status'] === 'left' || $s['grade'] === 'יב') { $arch->execute([now(), $who, now(), $s['id']]); $note->execute([$s['id'], ($s['status'] === 'left' ? 'הועברה לארכיון במעבר שנה (עזבה)' : 'הועברה לארכיון במעבר שנה (בוגרת)') . " · $dt", $u['id'], $who, now()]); $n['arch']++; continue; }
          $ng = $gl[$s['grade']] ?? null; if (!$ng) continue;
          $pdo->prepare("UPDATE students SET grade = ?, cls = IF(cls LIKE CONCAT(?, '%'), CONCAT(?, SUBSTRING(cls, CHAR_LENGTH(?) + 1)), cls), left_month = NULL, updated_at = ? WHERE id = ?")->execute([$ng, $s['grade'], $ng, $s['grade'], now(), $s['id']]); $n['adv']++;
        }
        foreach (['payments', 'charges', 'month_marks', 'gad_months'] as $t) $pdo->exec("DELETE FROM $t");
        $emp = array_column($pdo->query("SELECT f.id FROM families f WHERE NOT EXISTS (SELECT 1 FROM students s WHERE s.family_id = f.id AND s.archived_at IS NULL)")->fetchAll(), 'id');
        foreach ($emp as $fid) { $pdo->prepare('UPDATE students SET family_id = NULL WHERE family_id = ?')->execute([$fid]); $pdo->prepare('DELETE FROM families WHERE id = ?')->execute([$fid]); }
        $pdo->exec('UPDATE families SET card_ok = card_ok');
        $pdo->commit();
        audit('rollover', 'system', '', "עלו כיתה {$n['adv']} · לארכיון {$n['arch']} · תיקים ריקים שנמחקו " . count($emp) . ' · גיבוי ' . ($bm));
        $msg = "מעבר השנה הושלם: {$n['adv']} תלמידות עלו כיתה, {$n['arch']} (בוגרות ועזבו) הועברו לארכיון, התשלומים והחיובים אופסו. גיבוי המצב הקודם נשמר.";
      } catch (Throwable $t) { $pdo->rollBack(); $err = 'מעבר השנה נכשל ולא שונה דבר: ' . $t->getMessage(); error_log('rollover: ' . $t->getMessage()); }
    }
  }
}
$S = ro_stats(); $age = (bk_status()['time'] ?? 0);
page_head('מעבר שנה');
?>
<h1>מעבר לשנה הבאה</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <h2>מה יקרה</h2>
  <ul>
    <li><b><?= $S['adv'] ?></b> תלמידות פעילות יעלו כיתה (ז←ח … יא←יב), והכיתה המפורטת תתעדכן בהתאם. חודש עזיבה יתאפס.</li>
    <li><b><?= $S['grad'] ?></b> בוגרות (יב) ו-<b><?= $S['left'] ?></b> תלמידות שעזבו יועברו לארכיון עם הערה (אפשר להחזיר מ<a href="archive.php">הארכיון</a>).</li>
    <li>כל התשלומים (<?= $S['pay'] ?>), החיובים החד-פעמיים (<?= $S['chg'] ?>), סימוני החודשים (<?= $S['mk'] ?>) וסטטוס מורשת גד יימחקו, והגבייה תתחיל מאפס. התעריפים, אמצעי התשלום וההנחות נשארים.</li>
    <li>תיקים ללא תלמידה שממשיכה (<?= $S['fam_empty'] ?> כרגע) יימחקו. ממתינות להרשמה (<?= $S['pend'] ?>) לא ישתנו.</li>
    <li><b>לפני הביצוע המערכת מריצה גיבוי מלא ומאומת.</b> אם הגיבוי נכשל – שום דבר לא ישתנה. מומלץ להוריד קודם את <a href="exports.php">ייצוא הגבייה</a> לשמירה בארכיון של השנה שחלפה.</li>
  </ul>
  <p class="mu">גיבוי אחרון: <?= $age ? e(date('d/m/Y H:i', (int)$age)) : 'אין' ?>. פעולה זו אינה הפיכה מתוך המערכת (שחזור רק מהגיבוי).</p>
  <form method="post" class="bar"><?= csrf_field() ?><label>להקליד "<?= e(RO_CONFIRM) ?>" לאישור<input name="confirm" autocomplete="off" required></label><button class="dg" data-confirm="לבצע מעבר שנה? הפעולה מוחקת את נתוני הגבייה של השנה שחלפה.">ביצוע מעבר שנה</button></form>
</div>
<?php page_foot();
