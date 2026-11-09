<?php
declare(strict_types=1);
// דיוור להורים: בחירת נמענים לפי שכבה/כיתה/מצב גבייה, ושליחה בהדרגה (קבוצות קטנות) עם מעקב.
const MAIL_GRADES = ['ז', 'ח', 'ט', 'י', 'יא', 'יב'];
const MAIL_CHUNK = 8;

/** כיתות קיימות, לפי שכבה: ['ט' => ['ט1','ט2'], ...] */
function mail_classes(): array {
  $out = [];
  foreach (db()->query("SELECT grade, cls, COUNT(*) n FROM students WHERE status = 'active' AND archived_at IS NULL GROUP BY grade, cls")->fetchAll() as $r) {
    $l = class_label((string)$r['grade'], (string)$r['cls']);
    $out[$r['grade']][$l] = ($out[$r['grade']][$l] ?? 0) + (int)$r['n'];
  }
  return $out;
}
/**
 * נמענים לפי סינון: $grades (שכבות שלמות), $classes (כיתות כמו "ט2"), $debt: all|debt|clear.
 * מחזיר ['rows' => [[email, names, balance]], 'nomail' => מספר תלמידות בלי מייל תקין]
 */
function mail_recipients(array $grades, array $classes, string $debt): array {
  $st = db()->query("SELECT id, name, grade, cls, email, family_id FROM students WHERE status = 'active' AND archived_at IS NULL ORDER BY FIELD(grade,'ז','ח','ט','י','יא','יב'), cls, name");
  $all = $st->fetchAll(); $sel = [];
  foreach ($all as $s) {
    $cl = class_label((string)$s['grade'], (string)$s['cls']);
    if (!$grades && !$classes) { $sel[] = $s; continue; }
    if (in_array($s['grade'], $grades, true) || ($cl !== '' && in_array($cl, $classes, true))) $sel[] = $s;
  }
  $fids = array_values(array_unique(array_map('intval', array_filter(array_column($sel, 'family_id')))));
  $bal = $fids ? collection_data($fids) : [];
  $by = []; $nomail = 0;
  foreach ($sel as $s) {
    $b = $s['family_id'] ? (float)($bal[(int)$s['family_id']]['balance'] ?? 0) : 0.0;
    $found = false;
    foreach (preg_split('/[\s,;]+/u', trim((string)$s['email'])) ?: [] as $a) {
      $a = strtolower(trim($a));
      if ($a === '' || !filter_var($a, FILTER_VALIDATE_EMAIL)) continue;
      $found = true;
      $by[$a]['names'][$s['name']] = 1; $by[$a]['fam'][(int)$s['family_id'] ?: -(int)$s['id']] = $b;
    }
    if (!$found) $nomail++;
  }
  $rows = [];
  foreach ($by as $a => $x) {
    $b = round(array_sum($x['fam']), 2);
    if ($debt === 'debt' && $b <= 0.004) continue;
    if ($debt === 'clear' && $b > 0.004) continue;
    $rows[] = [$a, implode(', ', array_keys($x['names'])), $b];
  }
  return ['rows' => $rows, 'nomail' => $nomail];
}
function mail_fill(string $t, string $names, float $bal): string {
  return strtr($t, ['{שם}' => $names, '{חוב}' => $bal > 0.004 ? money($bal) : '0 ₪']);
}
function mail_audience_label(array $grades, array $classes, string $debt): string {
  $p = ($grades || $classes) ? implode(', ', array_merge($grades, $classes)) : 'כל ההורים';
  return $p . ($debt === 'debt' ? ' · רק עם חוב' : ($debt === 'clear' ? ' · רק בלי חוב' : ''));
}
/** שולחת קבוצה אחת. מחזירה [sent, failed, pending]. */
function mail_step(int $id): array {
  $pdo = db();
  $m = $pdo->prepare('SELECT * FROM mailings WHERE id = ?'); $m->execute([$id]); $m = $m->fetch();
  if (!$m) return [0, 0, 0];
  if ($m['status'] === 'stopped') return mail_counts($id);
  $lk = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lk->execute(['alma_mail_' . $id]);
  if ((int)$lk->fetchColumn() !== 1) return mail_counts($id);   // שליחה אחרת של אותו דיוור כבר רצה
  try {
  $pdo->prepare("UPDATE mailings SET status = 'sending' WHERE id = ? AND status = 'ready'")->execute([$id]);
  $q = $pdo->prepare("SELECT * FROM mailing_rcpt WHERE mailing_id = ? AND status = 'pending' ORDER BY id LIMIT " . MAIL_CHUNK); $q->execute([$id]);
  $ok = $pdo->prepare("UPDATE mailing_rcpt SET status = 'sent', sent_at = ?, err = '' WHERE id = ? AND status = 'pending'");
  $bad = $pdo->prepare("UPDATE mailing_rcpt SET status = 'failed', err = ? WHERE id = ? AND status = 'pending'");
  $t0 = time();
  foreach ($q->fetchAll() as $r) {
    if (time() - $t0 > 20) break;
    $good = send_mail((string)$r['email'], mail_fill((string)$m['subject'], (string)$r['names'], (float)$r['balance']), mail_fill((string)$m['body'], (string)$r['names'], (float)$r['balance']));
    if ($good) $ok->execute([now(), $r['id']]); else $bad->execute([mb_substr(mail_last_error(), 0, 190), $r['id']]);
    usleep(150000);
  }
  } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['alma_mail_' . $id]); }
  $c = mail_counts($id);
  if ($c[2] === 0) $pdo->prepare("UPDATE mailings SET status = 'done', finished_at = ? WHERE id = ? AND status <> 'stopped'")->execute([now(), $id]);
  return $c;
}
/** [sent, failed, pending] */
function mail_counts(int $id): array {
  $q = db()->prepare("SELECT status, COUNT(*) n FROM mailing_rcpt WHERE mailing_id = ? GROUP BY status"); $q->execute([$id]);
  $c = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'sending' => 0];
  foreach ($q->fetchAll() as $r) $c[$r['status']] = (int)$r['n'];
  return [$c['sent'], $c['failed'], $c['pending'] + $c['sending']];
}
