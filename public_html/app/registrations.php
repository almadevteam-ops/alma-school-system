<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'secretary']);
$isAdmin = $u['role'] === 'admin'; $who = (string)($u['name'] ?: $u['username']);
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? ''); $sid = (int)($_POST['sid'] ?? 0);
  $st = db()->prepare("SELECT * FROM students WHERE id = ? AND status = 'pending' AND archived_at IS NULL"); $st->execute([$sid]); $s = $st->fetch();
  if ($act === 'pay' && $s) {
    $pp = (string)($_POST['pay_pref'] ?? ''); if ($pp !== '' && !isset(PAY_METHODS[$pp])) $pp = '';
    db()->prepare('UPDATE students SET pay_pref = ?, card_ok = ?, updated_at = ? WHERE id = ?')->execute([$pp, ($pp === 'hok_card' && !empty($_POST['card_ok'])) ? ($s['card_ok'] ?: date('Y-m-d')) : null, now(), $sid]);
    audit('reg_pay', 'student', (string)$sid, $pp . (!empty($_POST['card_ok']) ? ' · אשראי הוסדר' : '')); $msg = 'עודכן.';
  } elseif ($act === 'approve' && $s) {
    $pdo = db(); $pdo->beginTransaction();
    $pdo->prepare("UPDATE students SET status = 'active', updated_at = ? WHERE id = ?")->execute([now(), $sid]);
    if (!$s['family_id']) {
      $pdo->prepare('INSERT INTO families (name, method, card_ok, created_at, updated_at) VALUES (?,?,?,?,?)')->execute([family_default_name([$s['name']]), $s['pay_pref'], $s['card_ok'], now(), now()]);
      $pdo->prepare('UPDATE students SET family_id = ? WHERE id = ?')->execute([(int)$pdo->lastInsertId(), $sid]);
    }
    $pdo->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)')->execute([$sid, 'אושרה כניסה למערכת בתאריך ' . date('d/m/Y') . ' ע"י ' . $who, $u['id'], $who, now()]);
    $pdo->commit(); audit('reg_approve', 'student', (string)$sid, $s['name']); $msg = 'התלמידה אושרה ונפתח לה תיק גבייה (יש להגדיר תעריף).';
  } elseif ($act === 'reject' && $s) {
    if (!$isAdmin) fail_page(403, 'מחיקת הרשמה מותרת למנהלת בלבד.');
    db()->prepare('UPDATE students SET archived_at = ?, archived_by = ?, updated_at = ? WHERE id = ?')->execute([now(), $who, now(), $sid]);
    db()->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, created_at) VALUES (?,?,?,?,?)')->execute([$sid, 'הרשמה נדחתה והועברה לארכיון בתאריך ' . date('d/m/Y') . ' ע"י ' . $who, $u['id'], $who, now()]);
    audit('reg_reject', 'student', (string)$sid, $s['name']); $msg = 'ההרשמה הועברה לארכיון.';
  }
}
$P = db()->query("SELECT s.*, (SELECT COUNT(*) FROM student_files f WHERE f.student_id = s.id) nf FROM students s WHERE s.status = 'pending' AND s.archived_at IS NULL ORDER BY s.created_at DESC")->fetchAll();
$msgTxt = reg_message();
page_head('הרשמות חדשות');
?>
<h1>הרשמות חדשות <span class="mu">(<?= count($P) ?> ממתינות לאישור)</span></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <h2>קישור הרשמה לתלמידה חדשה</h2>
  <p class="mu">שלחו להורים את הקישור. ההורים ממלאים פרטים ומצרפים תעודת זהות וספח, והתלמידה נכנסת לרשימה למטה עד לאישור המזכירות.</p>
  <div class="bar"><input readonly class="wide" dir="ltr" value="<?= e(reg_url()) ?>" id="reglink" aria-label="קישור הרשמה"><button type="button" class="sec" data-copy="reglink">העתקת הקישור</button></div>
  <div class="bar"><textarea readonly rows="3" id="regmsg" class="wide"><?= e($msgTxt) ?></textarea><button type="button" class="sec" data-copy="regmsg">📋 העתקת הודעת קבלה</button><a class="btn sec" href="/app/assets/logo-mark.png" download="alma-logo.png">⬇ הורדת הלוגו</a></div>
  <form class="bar" id="regsend"><label>טלפון ההורה (וואטסאפ)<input id="rp" dir="ltr" inputmode="tel"></label><a class="btn" id="rwa" data-wa="<?= e(rawurlencode($msgTxt)) ?>" href="#" target="_blank" rel="noopener">שליחה בוואטסאפ</a>
    <label>מייל ההורה<input id="rm" type="email" dir="ltr"></label><a class="btn sec" id="rma" data-sub="<?= e(rawurlencode('ברוכים הבאים למשפחת עלמא')) ?>" data-body="<?= e(rawurlencode($msgTxt)) ?>" href="#">שליחה במייל</a></form>
  <?php if ($isAdmin): ?><p class="mu">פרטי המזכירות שמופיעים בעמוד ההרשמה נקבעים ב<a href="settings.php">הגדרות</a>.</p><?php endif; ?>
</div>
<h2>ממתינות לאישור</h2>
<div class="sc"><table><tr><th>שם</th><th>כיתה</th><th>ת.ז</th><th>טלפון אב</th><th>טלפון אם</th><th>מייל</th><th>מסמכים</th><th>אופן תשלום</th><th>הסדרת אשראי</th><th></th></tr>
<?php foreach ($P as $s): $tel = fn($x) => $x !== '' ? '<bdi dir="ltr">' . e(fmt_phone($x)) . '</bdi>' : ''; ?>
<tr class="pendrow"><td><b><?= e($s['name']) ?></b><br><span class="mu"><?= e($s['reg_at'] ? date('d/m/Y', strtotime($s['reg_at'])) : '') ?></span></td><td><?= e($s['grade']) ?></td><td><bdi dir="ltr"><?= e($s['idn']) ?></bdi></td><td><?= $tel((string)$s['f_phone']) ?></td><td><?= $tel((string)$s['m_phone']) ?></td><td><bdi dir="ltr"><?= e($s['email']) ?></bdi></td>
  <td><a href="student.php?id=<?= (int)$s['id'] ?>&open=docs#s-docs"><?= (int)$s['nf'] ?> קבצים</a></td>
  <td colspan="2"><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="pay"><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
    <select name="pay_pref"><option value="">לא נקבע</option><?php foreach (PAY_METHODS as $k => $v) if ($k !== 'payer') echo '<option value="' . $k . '"' . ($s['pay_pref'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select>
    <?php if ($s['pay_pref'] === 'hok_card'): $p1 = ($s['m_phone'] ?: $s['f_phone']); ?>
      <?php if (pay_url() !== ''): ?><a class="btn sec" href="<?= e(pay_url()) ?>" target="_blank" rel="noopener">💳 עדכון אשראי</a><?php if ($p1): ?> <a class="btn sec" href="<?= e(wa_url($p1, 'שלום, לעדכון פרטי האשראי לתשלום שכר הלימוד של ' . $s['name'] . ' נא להיכנס לקישור המאובטח: ' . pay_url())) ?>" target="_blank" rel="noopener">שליחה להורה</a><?php endif; endif; ?>
      <label class="chk"><input type="checkbox" name="card_ok" value="1"<?= $s['card_ok'] ? ' checked' : '' ?>> הוסדר<?= $s['card_ok'] ? ' (' . e(date('d/m/Y', strtotime($s['card_ok']))) . ')' : '' ?></label>
    <?php endif; ?> <button class="sec">עדכון</button></form></td>
  <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="approve"><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>"><button data-confirm="לאשר כניסה למערכת?">אישור כניסה</button></form>
    <a class="btn sec" href="student.php?id=<?= (int)$s['id'] ?>">עריכה</a>
    <?php if ($isAdmin): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="reject"><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>"><button class="dg" data-confirm="להעביר את ההרשמה לארכיון?">מחיקה</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$P) echo '<tr><td colspan="10" class="mu">אין הרשמות ממתינות.</td></tr>'; ?></table></div>
<?php page_foot();
