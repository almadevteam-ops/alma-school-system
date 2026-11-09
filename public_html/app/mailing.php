<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin', 'secretary']);
$sel = function (): array {
  $R = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
  $g = array_values(array_intersect(array_map('strval', (array)($R['g'] ?? [])), MAIL_GRADES));
  $c = array_values(array_filter(array_map('strval', (array)($R['c'] ?? [])), fn($x) => preg_match('/^(ז|ח|ט|י|יא|יב)[123]$/u', $x)));
  $d = (string)($R['debt'] ?? 'all'); if (!in_array($d, ['all', 'debt', 'clear'], true)) $d = 'all';
  return [$g, $c, $d];
};
// ספירת נמענים חיה בטופס
if (isset($_GET['pv'])) {
  [$g, $c, $d] = $sel(); $r = mail_recipients($g, $c, $d);
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  echo json_encode(['n' => count($r['rows']), 'nomail' => $r['nomail']], JSON_UNESCAPED_UNICODE); exit;
}
$err = ''; $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = (string)($_POST['act'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  if ($act === 'step') {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    [$s, $f, $p] = mail_step($id); echo json_encode(['sent' => $s, 'failed' => $f, 'pending' => $p]); exit;
  }
  if ($act === 'stop') { db()->prepare("UPDATE mailings SET status = 'stopped' WHERE id = ? AND status IN ('ready','sending')")->execute([$id]); audit('mailing_stop', 'mailing', (string)$id, ''); redirect('mailing.php?id=' . $id); }
  if ($act === 'resume') { db()->prepare("UPDATE mailings SET status = 'ready' WHERE id = ? AND status = 'stopped'")->execute([$id]); redirect('mailing.php?id=' . $id . '&go=1'); }
  if ($act === 'retry') {
    db()->prepare("UPDATE mailing_rcpt SET status = 'pending', err = '' WHERE mailing_id = ? AND status = 'failed'")->execute([$id]);
    db()->prepare("UPDATE mailings SET status = 'ready', finished_at = NULL WHERE id = ?")->execute([$id]);
    audit('mailing_retry', 'mailing', (string)$id, ''); redirect('mailing.php?id=' . $id . '&go=1');
  }
  if ($act === 'test' || $act === 'create') {
    [$g, $c, $d] = $sel();
    $subj = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200); $body = trim((string)($_POST['body'] ?? ''));
    $to = trim((string)($_POST['to'] ?? ''));
    if ($subj === '' || $body === '') $err = 'נא למלא נושא ותוכן.';
    elseif (mb_strlen($body) > 20000) $err = 'התוכן ארוך מדי.';
    elseif (preg_match('/[\r\n]/', $subj)) $err = 'הנושא חייב להיות שורה אחת.';
    elseif ($act === 'test') {
      if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $err = 'נא להזין כתובת מייל תקינה לבדיקה.';
      elseif (send_mail($to, '[בדיקה] ' . mail_fill($subj, 'שם תלמידה לדוגמה', 450.0), mail_fill($body, 'שם תלמידה לדוגמה', 450.0))) { audit('mailing_test', 'mailing', '', mfa_mask($to)); $msg = 'מייל בדיקה נשלח אל ' . $to . ' (כדאי לבדוק גם בספאם).'; }
      else $err = 'השליחה נכשלה: ' . mail_last_error();
    } else {
      $r = mail_recipients($g, $c, $d);
      if (!$r['rows']) $err = 'אין נמענים עם כתובת מייל תקינה בבחירה הזו.';
      elseif (empty($_POST['ok'])) $err = 'נא לאשר את השליחה.';
      else {
        $pdo = db(); $pdo->beginTransaction();
        try {
          $who = mb_substr((string)($u['name'] ?: $u['username']), 0, 100);
          $pdo->prepare('INSERT INTO mailings (subject, body, audience, total, status, created_by, created_at) VALUES (?,?,?,?,?,?,?)')->execute([$subj, $body, mb_substr(mail_audience_label($g, $c, $d), 0, 250), count($r['rows']), 'ready', $who, now()]);
          $mid = (int)$pdo->lastInsertId();
          $ins = $pdo->prepare('INSERT INTO mailing_rcpt (mailing_id, email, names, balance) VALUES (?,?,?,?)');
          foreach ($r['rows'] as [$a, $n, $b]) $ins->execute([$mid, $a, mb_substr($n, 0, 250), $b]);
          $pdo->commit();
        } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
        audit('mailing_create', 'mailing', (string)$mid, mail_audience_label($g, $c, $d) . ' · ' . count($r['rows']) . ' נמענים');
        redirect('mailing.php?id=' . $mid . '&go=1');
      }
    }
  }
}
$mid = (int)($_GET['id'] ?? 0); $M = null;
if ($mid) { $q = db()->prepare('SELECT * FROM mailings WHERE id = ?'); $q->execute([$mid]); $M = $q->fetch() ?: null; if (!$M) fail_page(404, 'הדיוור לא נמצא.'); }
page_head('דיוור');
if ($M):
  [$s, $f, $p] = mail_counts($mid); $done = $s + $f; $tot = max(1, (int)$M['total']);
  $stl = ['ready' => 'מוכן לשליחה', 'sending' => 'בשליחה', 'done' => 'הסתיים', 'stopped' => 'נעצר'][$M['status']] ?? $M['status'];
  $fl = db()->prepare("SELECT email, names, err FROM mailing_rcpt WHERE mailing_id = ? AND status = 'failed' ORDER BY id LIMIT 200"); $fl->execute([$mid]); $fails = $fl->fetchAll();
?>
<h1>דיוור: <?= e($M['subject']) ?></h1>
<p class="subnav"><a class="btn sec" href="mailing.php">‹ לדיוור</a></p>
<p class="mu">נמענים: <?= e($M['audience']) ?> · נוצר ע"י <?= e($M['created_by']) ?> ב-<?= e(date('d/m/Y H:i', strtotime($M['created_at']))) ?></p>
<div id="mailrun" data-id="<?= (int)$mid ?>" data-t="<?= e(csrf_token()) ?>" data-go="<?= isset($_GET['go']) && $M['status'] !== 'done' && $M['status'] !== 'stopped' ? '1' : '' ?>" data-status="<?= e($M['status']) ?>">
  <div class="tiles">
    <div class="tile g"><span class="mu">נשלחו</span><b class="ok" data-k="sent"><?= $s ?></b></div>
    <div class="tile w"><span class="mu">ממתינים</span><b data-k="pending"><?= $p ?></b></div>
    <div class="tile"><span class="mu">נכשלו</span><b class="<?= $f ? 'er' : '' ?>" data-k="failed"><?= $f ?></b></div>
    <div class="tile"><span class="mu">מצב</span><b data-k="st"><?= e($stl) ?></b></div>
  </div>
  <progress max="<?= (int)$M['total'] ?>" value="<?= $done ?>" class="mailpg"></progress>
  <p class="mu" data-k="note"><?= $p > 0 && $M['status'] !== 'stopped' ? 'השליחה מתבצעת בהדרגה. אפשר להשאיר את הדף פתוח עד הסיום; אם הוא נסגר, אפשר להמשיך מכאן בכל רגע.' : '' ?></p>
  <div class="acts">
    <?php if ($p > 0 && $M['status'] !== 'stopped'): ?><button type="button" class="btn" data-a="start">▶ התחלת / המשך שליחה</button><button type="button" class="btn sec" data-a="stop">עצירה</button><?php endif; ?>
    <?php if ($p > 0 && $M['status'] === 'stopped'): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="resume"><input type="hidden" name="id" value="<?= (int)$mid ?>"><button class="btn">המשך שליחה</button></form><?php endif; ?>
    <?php if ($f > 0 && $p === 0): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="retry"><input type="hidden" name="id" value="<?= (int)$mid ?>"><button class="btn sec">ניסיון חוזר לכישלונות (<?= $f ?>)</button></form><?php endif; ?>
  </div>
</div>
<?php if ($fails): sec_open('fails', 'כתובות שנכשלו', count($fails) . ' כתובות', '!', ['fails']); ?>
  <div class="sc"><table><?php foreach ($fails as $x): ?><tr><td dir="ltr"><?= e($x['email']) ?></td><td><?= e($x['names']) ?></td><td class="mu"><?= e($x['err']) ?></td></tr><?php endforeach; ?></table></div>
<?php sec_close(); endif; ?>
<?php sec_open('txt', 'תוכן ההודעה', '', '✉'); ?><p><b><?= e($M['subject']) ?></b></p><pre class="mailtxt"><?= e($M['body']) ?></pre><?php sec_close(); ?>
<?php else:
  $cls = mail_classes(); $hist = db()->query('SELECT id, subject, audience, total, status, created_at FROM mailings ORDER BY id DESC LIMIT 30')->fetchAll();
  $pg = (array)($_POST['g'] ?? []); $pc = (array)($_POST['c'] ?? []);
?>
<h1>דיוור להורים</h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<form method="post" class="fg mailform" id="mailform" data-url="mailing.php?pv=1"><?= csrf_field() ?>
  <fieldset><legend>למי לשלוח</legend>
    <p class="mu">בלי לסמן כלום – נשלח לכל ההורים. אפשר לסמן שכבה שלמה או כיתות מסוימות. הכתובת נלקחת משדה "אימייל" בכרטיס התלמידה; אחיות עם אותה כתובת מקבלות הודעה אחת.</p>
    <?php foreach (MAIL_GRADES as $gr): ?>
      <div class="chkrow"><label class="chk"><input type="checkbox" name="g[]" value="<?= e($gr) ?>"<?= in_array($gr, $pg, true) ? ' checked' : '' ?>> <b>שכבה <?= e($gr) ?></b></label>
      <?php foreach (array_keys($cls[$gr] ?? []) as $cl) if ($cl !== $gr): ?><label class="chk"><input type="checkbox" name="c[]" value="<?= e($cl) ?>"<?= in_array($cl, $pc, true) ? ' checked' : '' ?>> <?= e($cl) ?> <span class="mu">(<?= (int)$cls[$gr][$cl] ?>)</span></label><?php endif; ?>
      <?php if (!empty($cls[$gr][$gr])) echo '<span class="mu">' . (int)$cls[$gr][$gr] . ' בלי כיתה מפורטת</span>'; ?></div>
    <?php endforeach; ?>
    <label>מצב גבייה<select name="debt"><?php foreach (['all' => 'כולם', 'debt' => 'רק עם חוב (תזכורת תשלום)', 'clear' => 'רק בלי חוב'] as $k => $v) echo '<option value="' . $k . '"' . (($_POST['debt'] ?? 'all') === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></label>
    <p class="mailn" id="mailn" aria-live="polite"></p>
  </fieldset>
  <label>נושא<input name="subject" maxlength="200" value="<?= e((string)($_POST['subject'] ?? '')) ?>" required></label>
  <label>תוכן ההודעה<textarea name="body" rows="10" maxlength="20000" required><?= e((string)($_POST['body'] ?? '')) ?></textarea></label>
  <p class="mu">אפשר לשלב בתוך הנושא או התוכן: <b>{שם}</b> – שם התלמידה, <b>{חוב}</b> – החוב הנוכחי של התיק (למשל "450 ₪").</p>
  <div class="bar"><label>מייל לבדיקה<input name="to" type="email" dir="ltr" value="<?= e((string)($_POST['to'] ?? '')) ?>" placeholder="your@email.com"></label><button name="act" value="test" class="btn sec" formnovalidate>שליחת בדיקה אליי</button></div>
  <label class="chk"><input type="checkbox" name="ok" value="1"> אני מאשרת שליחה לכל הנמענים שנבחרו</label>
  <div class="acts"><button name="act" value="create" class="btn">יצירת דיוור והתחלת שליחה</button></div>
</form>
<?php sec_open('hist', 'דיוורים קודמים', count($hist) . ' אחרונים', '☰', $hist ? ['hist'] : []); ?>
  <div class="sc"><table><tr><th>תאריך</th><th>נושא</th><th>נמענים</th><th>סטטוס</th><th></th></tr>
  <?php foreach ($hist as $h): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($h['created_at']))) ?></td><td><?= e($h['subject']) ?></td><td><?= e($h['audience']) ?> <span class="mu">(<?= (int)$h['total'] ?>)</span></td><td><?= e(['ready' => 'מוכן', 'sending' => 'בשליחה', 'done' => 'הסתיים', 'stopped' => 'נעצר'][$h['status']] ?? $h['status']) ?></td><td><a href="mailing.php?id=<?= (int)$h['id'] ?>">פתיחה</a></td></tr><?php endforeach; if (!$hist) echo '<tr><td class="mu">עדיין לא נשלח דיוור.</td></tr>'; ?></table></div>
<?php sec_close(); endif; page_foot();
