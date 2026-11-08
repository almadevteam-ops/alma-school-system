<?php
declare(strict_types=1);
// הגדרות שמנהלת מערכת עורכת בתוך המערכת (בלי לגעת בקובץ ההגדרות בשרת).
function setting_get(string $k, string $default = ''): string {
  try { $q = db()->prepare('SELECT v FROM app_settings WHERE k = ?'); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? $default : (string)$v; }
  catch (Throwable $t) { return $default; }
}
function setting_set(string $k, string $v, string $by): void {
  db()->prepare('INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)')->execute([$k, $v, now(), mb_substr($by, 0, 100)]);
}
/** נמעני מייל "תלמידה חדשה": קודם ההגדרה במערכת, ואם אין – new_student_notify מקובץ ההגדרות. */
function new_student_recipients(): array {
  $raw = setting_get('new_student_notify', ''); if (trim($raw) === '') $raw = (string)cfg('new_student_notify', '');
  return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/u', $raw) ?: [])));
}
