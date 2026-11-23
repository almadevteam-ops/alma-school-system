<?php
declare(strict_types=1);
// תזכורות גבייה: חד-פעמיות או חודשיות, נשלחות במייל למשתמש שיצר אותן.

function rem_ready(): bool {
  static $ok = null;
  if ($ok === null) { try { $ok = (bool)db()->query("SHOW TABLES LIKE 'reminders'")->fetch(); } catch (Throwable $t) { $ok = false; } }
  return $ok;
}
/** כתובת המייל של משתמש: מייל לתזכורות, ואם אין – מייל האימות הדו-שלבי. */
function rem_email(int $uid): string {
  try { $r = db()->prepare('SELECT email, mfa_email FROM users WHERE id = ?'); $r->execute([$uid]); $x = $r->fetch(); } catch (Throwable $t) { return ''; }
  if (!$x) return '';
  foreach ([(string)($x['email'] ?? ''), (string)($x['mfa_email'] ?? '')] as $m) if (filter_var($m, FILTER_VALIDATE_EMAIL)) return $m;
  return '';
}
/** התאריך הבא ביום $dom (1-31, מקוצר לסוף החודש) שאינו לפני $from. */
function rem_next_dom(int $dom, string $from): string {
  $t = strtotime($from); $y = (int)date('Y', $t); $m = (int)date('n', $t);
  for ($i = 0; $i < 3; $i++) {
    $d = min($dom, (int)date('t', mktime(0, 0, 0, $m, 1, $y))); $c = sprintf('%04d-%02d-%02d', $y, $m, $d);
    if ($c >= $from) return $c;
    if (++$m > 12) { $m = 1; $y++; }
  }
  return $from;
}
function rem_family_rows(int $fid): array {
  if (!rem_ready()) return [];
  $q = db()->prepare("SELECT r.*, u.display_name who FROM reminders r LEFT JOIN users u ON u.id = r.user_id WHERE r.family_id = ? AND (r.status = 'active' OR r.last_sent_at >= ?) ORDER BY r.status, r.next_at, r.id DESC");
  $q->execute([$fid, date('Y-m-d', strtotime('-60 days'))]); return $q->fetchAll();
}
function rem_when(array $r): string {
  if ($r['kind'] === 'monthly') return 'כל חודש ב-' . (int)$r['day_of_month'] . ($r['until_at'] ? ' · עד ' . date_out($r['until_at']) : '');
  return 'חד-פעמית';
}
function rem_compose(array $r): array {
  $f = db()->prepare('SELECT name FROM families WHERE id = ?'); $f->execute([$r['family_id']]); $fn = (string)$f->fetchColumn();
  $sum = collection_data([(int)$r['family_id']])[(int)$r['family_id']] ?? null;
  $host = (string)cfg('host', 'localhost'); $link = 'https://' . $host . '/app/family.php?id=' . (int)$r['family_id'];
  $body = (string)$r['body'] . "\n\n—\nמשפחת $fn" . ($sum ? ' · ' . ($sum['balance'] < -0.004 ? 'זכות ' : 'יתרת חוב ') . money(abs($sum['balance'])) : '') . "\nפתיחת תיק הגבייה: $link\nתזכורת זו נוצרה במערכת עלמא (" . rem_when($r) . ').';
  return ['❗❗ ' . (string)$r['title'], $body];
}
/** שולחת תזכורת אחת למשתמש שיצר אותה. מחזירה [ok, הודעה]. */
function rem_send(array $r): array {
  $to = rem_email((int)$r['user_id']);
  if ($to === '') return [false, 'אין כתובת מייל למשתמש (אפשר להגדיר ב"אבטחה")'];
  [$s, $b] = rem_compose($r);
  return send_mail($to, $s, $b) ? [true, $to] : [false, (string)($GLOBALS['MAIL_ERR'] ?? 'השליחה נכשלה')];
}
/** מריצה את כל התזכורות שהגיע זמנן. בטוחה להרצה חוזרת (תופסת את התזכורת לפני השליחה). */
function rem_cycle(): int {
  if (!rem_ready() || (int)date('G') < 7) return 0;
  $today = date('Y-m-d'); $n = 0; $pdo = db();
  $rows = $pdo->prepare("SELECT * FROM reminders WHERE status = 'active' AND next_at IS NOT NULL AND next_at <= ? ORDER BY next_at, id LIMIT 50"); $rows->execute([$today]);
  foreach ($rows->fetchAll() as $r) {
    $nx = null; $st = 'done';
    if ($r['kind'] === 'monthly') {
      $nx = rem_next_dom((int)$r['day_of_month'], date('Y-m-d', strtotime('+1 day', strtotime($today))));
      if ($r['until_at'] && $nx > $r['until_at']) $nx = null; else $st = 'active';
    }
    $c = $pdo->prepare("UPDATE reminders SET next_at = ?, status = ?, last_sent_at = ? WHERE id = ? AND status = 'active' AND next_at <= ?");
    $c->execute([$nx, $st, now(), $r['id'], $today]);
    if ($c->rowCount() !== 1) continue;
    [$ok, $info] = rem_send($r);
    if ($ok) { $pdo->prepare('UPDATE reminders SET sent_count = sent_count + 1, last_err = ? WHERE id = ?')->execute(['', $r['id']]); $n++; }
    else $pdo->prepare('UPDATE reminders SET next_at = ?, status = \'active\', last_sent_at = NULL, last_err = ? WHERE id = ?')->execute([$today, mb_substr($info, 0, 200), $r['id']]);
  }
  return $n;
}
/** הרצה עצלה: לכל היותר פעם בכמה דקות, גם אם משימת ה-cron לא הוגדרה. */
function rem_lazy(): void {
  if (!rem_ready()) return;
  $k = (string)setting_get('rem_last_run', ''); if ($k !== '' && time() - (int)$k < 300) return;
  setting_set('rem_last_run', (string)time(), 'system'); try { rem_cycle(); } catch (Throwable $t) {}
}
