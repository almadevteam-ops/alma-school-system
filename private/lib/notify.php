<?php
declare(strict_types=1);
// הודעות מייל לצוות על אירועים במערכת.
function notify_recipients(): array {
  try { return db()->query('SELECT id, email, label FROM notify_recipients ORDER BY id')->fetchAll(); } catch (Throwable $t) { return []; }
}
/** נשלח לנמענים כשנרשמת תלמידה חדשה. נכשל בשקט (נרשם ביומן) כדי לא לעצור את ההרשמה. */
function notify_new_student(array $d, int $nid): void {
  $to = array_column(notify_recipients(), 'email');
  if (!$to) return;
  $link = 'https://' . (string)cfg('host', 'alma-school.co.il') . '/app/';
  $idn = trim((string)($d['idn'] ?? '')); $idTxt = $idn !== '' ? $idn : 'לא הוזן';
  $body = "שלום רב,\n\nזוהי הודעה ממערכת האתר של עלמא,\nבוצעה כעת הרשמה ל" . $d['name'] . " - מספר זהות " . $idTxt . " - לפרטים נוספים ניתן להכנס לאתר " . $link . "\n";
  $sent = 0; $fail = [];
  foreach ($to as $addr) { if (send_mail((string)$addr, 'הרשמה חדשה במערכת עלמא: ' . $d['name'], $body)) $sent++; else $fail[] = $addr . ' (' . mail_last_error() . ')'; }
  audit($fail ? 'new_student_mail_failed' : 'new_student_mail', 'student', (string)$nid, "נשלח $sent" . ($fail ? '; נכשל: ' . implode(', ', $fail) : ''));
}
