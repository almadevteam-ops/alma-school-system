<?php
declare(strict_types=1);
// מסמכים אוטומטיים לתלמידות: תבניות, יצירת PDF (עם חתימה אוטומטית) ושליחה במייל (גם לפי כיתות / כל התלמידות).
require_once __DIR__ . '/pdfdoc.php';
const DOC_PLACEHOLDERS = ['{שם}' => 'שם התלמידה', '{תז}' => 'תעודת זהות', '{כיתה}' => 'כיתה (למשל י2)', '{שכבה}' => 'שכבה', '{שנה_עברית}' => 'שנת הלימודים בעברית (תשפ"ז)', '{שנה}' => 'שנת הלימודים (2026-2027)', '{תאריך}' => 'תאריך ההפקה', '{סמל_מוסד}' => 'סמל מוסד', '{שם_האב}' => 'שם האב', '{שם_האם}' => 'שם האם', '{כתובת}' => 'כתובת', '{עיר}' => 'עיר'];

function docs_ready(): bool {
  static $ok = null;
  if ($ok === null) { try { $ok = (bool)db()->query("SHOW TABLES LIKE 'doc_templates'")->fetch() && (bool)db()->query("SHOW TABLES LIKE 'doc_jobs'")->fetch() && (bool)db()->query("SHOW TABLES LIKE 'doc_history'")->fetch(); } catch (Throwable $t) { $ok = false; } }
  return $ok;
}
/** שנת הלימודים הנוכחית (שנה לועזית שבה היא מתחילה): מספטמבר. */
function doc_year_start(?int $ts = null): int { $ts ??= time(); $y = (int)date('Y', $ts); return (int)date('n', $ts) >= 9 ? $y : $y - 1; }
/** שנה עברית (למשל 5787) באותיות: תשפ"ז. */
function doc_heb_year(int $startYear): string {
  $n = ($startYear + 1 + 3760) % 1000; $L = [[400, 'ת'], [300, 'ש'], [200, 'ר'], [100, 'ק'], [90, 'צ'], [80, 'פ'], [70, 'ע'], [60, 'ס'], [50, 'נ'], [40, 'מ'], [30, 'ל'], [20, 'כ'], [10, 'י'], [9, 'ט'], [8, 'ח'], [7, 'ז'], [6, 'ו'], [5, 'ה'], [4, 'ד'], [3, 'ג'], [2, 'ב'], [1, 'א']];
  $out = ''; foreach ($L as [$v, $c]) while ($n >= $v) { if ($n === 15) { $out .= 'טו'; $n = 0; break; } if ($n === 16) { $out .= 'טז'; $n = 0; break; } $out .= $c; $n -= $v; }
  $chars = mb_str_split($out); return count($chars) > 1 ? implode('', array_slice($chars, 0, -1)) . '"' . end($chars) : $out . "'";
}
function doc_fill(string $t, array $s, int $ys, string $date): string {
  $map = ['{שם}' => (string)$s['name'], '{תז}' => (string)($s['idn'] ?? ''), '{כיתה}' => class_label((string)$s['grade'], (string)($s['cls'] ?? '')), '{שכבה}' => (string)$s['grade'], '{שנה_עברית}' => doc_heb_year($ys), '{שנה}' => $ys . '-' . ($ys + 1), '{תאריך}' => $date, '{סמל_מוסד}' => setting_get('inst_symbol', '344580'), '{שם_האב}' => (string)($s['f_name'] ?? ''), '{שם_האם}' => (string)($s['m_name'] ?? ''), '{כתובת}' => (string)($s['addr'] ?? ''), '{עיר}' => (string)($s['city'] ?? '')];
  return strtr($t, $map);
}
function doc_templates(): array { return docs_ready() ? db()->query('SELECT * FROM doc_templates ORDER BY builtin DESC, name')->fetchAll() : []; }
function doc_template(int $id): ?array { $st = db()->prepare('SELECT * FROM doc_templates WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }
function doc_student(int $id): ?array { $st = db()->prepare('SELECT * FROM students WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }

/** תלמידה "ריקה" למסמך כללי (בלי שיוך לתלמידה). */
function doc_blank_student(): array { return ['id' => 0, 'name' => '', 'idn' => '', 'grade' => '', 'cls' => '', 'f_name' => '', 'm_name' => '', 'addr' => '', 'city' => '', 'email' => '']; }
/** ציור מסמך אחד (עמוד או יותר) על PdfDoc קיים: כותרת, תוכן התבנית עם פרטי התלמידה, "בברכה / ההנהלה" וחתימה אוטומטית. */
function doc_render(PdfDoc $d, array $tpl, array $stu, int $ys, string $date): void {
  $center = ($tpl['align'] ?? 'c') !== 'r'; $size = $center ? 20.0 : 15.0; $lh = $center ? 26.0 : 22.0; $cx = 297.64; $rx = 540.0; $maxW = 460.0; $bottom = 700.0;
  $d->addPage(!empty($tpl['signed'])); $d->text($date, 90, 47, 11, 'b', 'l'); $d->text('בס"ד', $cx, 47, 11, 'b', 'c');
  $y = 241.0;
  foreach ($d->wrap(doc_fill((string)$tpl['title'], $stu, $ys, $date), 20, $maxW, 'b') as $ln) { $d->text($ln, $cx, $y, 20, 'b', 'c', true); $y += 28; }
  $y += $center ? 26 : 14;
  foreach (preg_split('/\R/u', doc_fill((string)$tpl['body'], $stu, $ys, $date)) ?: [] as $para) {
    if (trim($para) === '') { $y += $lh * 0.6; continue; }
    foreach ($d->wrap($para, $size, $maxW, 'r') as $ln) {
      if ($y > $bottom) { $d->addPage(!empty($tpl['signed'])); $y = 200.0; }
      $center ? $d->text($ln, $cx, $y, $size, 'r', 'c') : $d->text($ln, $rx, $y, $size, 'r', 'r'); $y += $lh;
    }
    $y += $center ? 28 : 8;
  }
  $y += 28; if ($y + 100 > $bottom + 20) { $d->addPage(!empty($tpl['signed'])); $y = 200.0; }
  $d->text('בברכה', $cx, $y, 20, 'r', 'c'); $d->text('ההנהלה', $cx, $y + 29, 20, 'r', 'c');
  if (!empty($tpl['signed'])) $d->signature($cx + 2, $y + 37, 118);
}
function doc_newpdf(): PdfDoc { $root = dirname(__DIR__); return new PdfDoc($root . '/fonts', $root . '/docs/bg.jpg', $root . '/docs/sig.png'); }
function doc_pdf(array $tpl, array $stu, int $ys, ?string $date = null): string { $d = doc_newpdf(); doc_render($d, $tpl, $stu, $ys, $date ?? date('d/m/Y')); return $d->output(); }
/** PDF אחד עם מסמך לכל תלמידה ברשימה (עמוד או יותר לכל אחת). */
function doc_pdf_multi(array $tpl, array $students, int $ys, ?string $date = null): string { $d = doc_newpdf(); $date ??= date('d/m/Y'); foreach ($students as $s) doc_render($d, $tpl, $s, $ys, $date); return $d->output(); }
function doc_filename(array $tpl, array $stu): string { return preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', trim((string)$tpl['name'] . ((string)$stu['name'] !== '' ? '_' . (string)$stu['name'] : ''))) . '.pdf'; }
function doc_default_subject(array $tpl, array $stu): string { return mb_substr((string)$tpl['name'] . ((string)$stu['name'] !== '' ? ' – ' . (string)$stu['name'] : ''), 0, 190); }
function doc_default_msg(array $tpl, array $stu): string { return "שלום רב,\nמצורף " . $tpl['name'] . ((string)$stu['name'] !== '' ? ' עבור ' . $stu['name'] : '') . ".\n\nבברכה,\nהנהלת תיכון עלמא"; }

/** רישום מסמך בהיסטוריית התלמידה (תמונת מצב של הטקסט כפי שהופק). */
function doc_record(array $stu, array $tpl, int $ys, string $by, string $sentTo = '', ?string $date = null): int {
  $date ??= date('d/m/Y'); $dt = DateTime::createFromFormat('d/m/Y', $date) ?: new DateTime();
  db()->prepare('INSERT INTO doc_history (student_id, template_id, name, title, body, align, signed, year, doc_date, sent_to, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
    (int)($stu['id'] ?? 0), !empty($tpl['id']) ? (int)$tpl['id'] : null, mb_substr((string)$tpl['name'], 0, 100), mb_substr(doc_fill((string)$tpl['title'], $stu, $ys, $date), 0, 150), doc_fill((string)$tpl['body'], $stu, $ys, $date),
    ($tpl['align'] ?? 'c') === 'r' ? 'r' : 'c', !empty($tpl['signed']) ? 1 : 0, $ys, $dt->format('Y-m-d'), mb_substr($sentTo, 0, 400), mb_substr($by, 0, 100), now()]);
  return (int)db()->lastInsertId();
}
/** מסמכים כלליים (ללא שיוך לתלמידה). */
function doc_general(int $limit = 40): array { return doc_history(0, $limit); }
function doc_history(int $sid, int $limit = 40): array { $st = db()->prepare('SELECT * FROM doc_history WHERE student_id = ? ORDER BY id DESC LIMIT ' . (int)$limit); $st->execute([$sid]); return $st->fetchAll(); }
function doc_hist_get(int $id): ?array { $st = db()->prepare('SELECT * FROM doc_history WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }
/** מסמך מההיסטוריה בצורת תבנית (הטקסט כבר מולא). */
function doc_hist_tpl(array $h): array { return ['id' => $h['template_id'], 'name' => $h['name'], 'title' => $h['title'], 'body' => $h['body'], 'align' => $h['align'], 'signed' => $h['signed']]; }
function doc_hist_date(array $h): string { return date('d/m/Y', strtotime((string)$h['doc_date'])); }

/** שליחת מסמך אחד במייל. $hist>0: שליחה חוזרת של מסמך מההיסטוריה (מעדכנת את "נשלח אל"); אחרת נרשם מסמך חדש בהיסטוריה. מחזירה [ok, שגיאה]. */
function doc_send(array $tpl, array $stu, int $ys, string $to, string $subject = '', string $msg = '', string $by = '', int $hist = 0, ?string $date = null): array {
  $to = trim($to); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'כתובת מייל לא תקינה'];
  $date ??= date('d/m/Y'); $pdf = doc_pdf($tpl, $stu, $ys, $date);
  $subject = $subject !== '' ? doc_fill($subject, $stu, $ys, $date) : doc_default_subject($tpl, $stu); $msg = $msg !== '' ? doc_fill($msg, $stu, $ys, $date) : doc_default_msg($tpl, $stu);
  $ok = send_mail($to, $subject, $msg, [['name' => doc_filename($tpl, $stu), 'type' => 'application/pdf', 'data' => $pdf]]);
  if (!$ok) return [false, mb_substr(mail_last_error() ?: 'השליחה נכשלה', 0, 190)];
  if ($hist > 0) db()->prepare("UPDATE doc_history SET sent_to = LEFT(CONCAT(IF(sent_to = '', '', CONCAT(sent_to, ', ')), ?), 400) WHERE id = ?")->execute([$to, $hist]);
  else doc_record($stu, $tpl, $ys, $by, $to, $date);
  return [true, ''];
}
/** תלמידות לפי סינון: 'all' או 'g:<שכבה>' או 'c:<שכבה>|<כיתה>'. פעילות בלבד. */
function doc_audience(string $sel): array {
  $q = "SELECT * FROM students WHERE status = 'active' AND archived_at IS NULL"; $a = [];
  if (preg_match('/^g:(.+)$/u', $sel, $m)) { $q .= ' AND grade = ?'; $a[] = $m[1]; }
  elseif (preg_match('/^c:(.+)\|(.*)$/u', $sel, $m)) { $q .= ' AND grade = ? AND cls = ?'; $a[] = $m[1]; $a[] = $m[2]; }
  elseif ($sel !== 'all') return [];
  $st = db()->prepare($q . ' ORDER BY grade, cls, name'); $st->execute($a); return $st->fetchAll();
}
/** הכנסת תלמידות לתור שליחה. מחזירה [batch, בתור, ללא מייל]. */
function doc_enqueue(array $tpl, array $students, int $ys, string $subject, string $msg, string $by): array {
  $batch = date('ymdHis') . random_int(10, 99); $n = 0; $skip = [];
  $ins = db()->prepare("INSERT INTO doc_jobs (batch, template_id, student_id, year, to_email, subject, msg, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,'pending',?,?)");
  foreach ($students as $s) {
    $em = trim((string)$s['email']); if (!filter_var($em, FILTER_VALIDATE_EMAIL)) { $skip[] = (string)$s['name']; continue; }
    $ins->execute([$batch, (int)$tpl['id'], (int)$s['id'], $ys, $em, mb_substr($subject, 0, 190), $msg, mb_substr($by, 0, 100), now()]); $n++;
  }
  return [$batch, $n, $skip];
}
/** שליחת משימות ממתינות בתוך תקציב זמן. מחזירה כמה נשלחו. */
function doc_process(int $seconds = 20): int {
  if (!docs_ready()) return 0; $t0 = time(); $sent = 0; $pdo = db(); $tpls = [];
  while (time() - $t0 < $seconds) {
    $j = $pdo->query("SELECT * FROM doc_jobs WHERE status = 'pending' ORDER BY id LIMIT 1")->fetch(); if (!$j) break;
    $c = $pdo->prepare("UPDATE doc_jobs SET status = 'sending' WHERE id = ? AND status = 'pending'"); $c->execute([$j['id']]); if ($c->rowCount() !== 1) continue;
    $tpl = $tpls[$j['template_id']] ??= doc_template((int)$j['template_id']); $stu = doc_student((int)$j['student_id']);
    if (!$tpl || !$stu) { $pdo->prepare("UPDATE doc_jobs SET status = 'failed', err = ? WHERE id = ?")->execute(['תבנית או תלמידה לא נמצאו', $j['id']]); continue; }
    try { [$ok, $err] = doc_send($tpl, $stu, (int)$j['year'], (string)$j['to_email'], (string)$j['subject'], (string)$j['msg'], (string)$j['created_by']); } catch (Throwable $t) { $ok = false; $err = mb_substr($t->getMessage(), 0, 190); }
    $pdo->prepare('UPDATE doc_jobs SET status = ?, err = ?, sent_at = ? WHERE id = ?')->execute([$ok ? 'sent' : 'failed', $err, now(), $j['id']]); if ($ok) $sent++;
  }
  $pdo->exec("UPDATE doc_jobs SET status = 'pending' WHERE status = 'sending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
  return $sent;
}
function doc_lazy(): void {
  if (!docs_ready()) return; $k = setting_get('doc_last_run', ''); if ($k !== '' && time() - (int)$k < 60) return;
  if (!(int)db()->query("SELECT COUNT(*) FROM doc_jobs WHERE status = 'pending'")->fetchColumn()) return;
  setting_set('doc_last_run', (string)time(), 'system'); try { doc_process(10); } catch (Throwable $t) {}
}
