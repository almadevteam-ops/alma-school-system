<?php
declare(strict_types=1);
// קבצים מוצפנים של תלמידות: נשמרים ב-private/storage כקבצי ALM1 מוצפנים (AES-256-GCM), בשם אקראי.
const FILE_KINDS = ['id' => 'תעודת זהות וספח', 'divorce' => 'טופס הורים גרושים', 'other' => 'אחר'];
const FILE_MAX = 10 * 1024 * 1024;
const FILE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
const FILE_LIMIT_PER_STUDENT = 20;

function upload_error_text(int $code): string {
  return match ($code) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'הקובץ גדול מדי (עד 10MB).',
    UPLOAD_ERR_PARTIAL => 'ההעלאה נקטעה. נסו שוב.',
    UPLOAD_ERR_NO_FILE => 'לא נבחר קובץ.',
    default => 'ההעלאה נכשלה. נסו שוב.',
  };
}
function clean_name(string $n): string {
  $n = basename(str_replace('\\', '/', $n));
  $n = preg_replace('/[\x00-\x1F\x7F<>:"|?*\/\\\\]/u', '', $n) ?? '';
  $n = trim($n);
  return mb_substr($n === '' ? 'file' : $n, 0, 150);
}
// מחזיר [true, '']  או [false, 'הודעת שגיאה']
function store_student_file(array $f, int $studentId, string $kind, int $userId): array {
  if (!isset(FILE_KINDS[$kind])) return [false, 'סוג מסמך לא תקין.'];
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [false, upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE))];
  if (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) return [false, 'קובץ לא תקין.'];
  $size = (int)filesize($f['tmp_name']);
  if ($size <= 0) return [false, 'הקובץ ריק.'];
  if ($size > FILE_MAX) return [false, 'הקובץ גדול מדי (עד 10MB).'];
  $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  if (!isset(FILE_MIMES[$mime])) return [false, 'סוג הקובץ לא נתמך. אפשר להעלות תמונה (JPG/PNG) או PDF.'];
  $c = db()->prepare('SELECT COUNT(*) FROM student_files WHERE student_id = ?'); $c->execute([$studentId]);
  if ((int)$c->fetchColumn() >= FILE_LIMIT_PER_STUDENT) return [false, 'הגעתם למספר המסמכים המרבי לתלמידה.'];
  $plain = (string)file_get_contents($f['tmp_name']);
  $stored = bin2hex(random_bytes(16));
  $path = PRIV . '/storage/' . $stored . '.bin';
  if (file_put_contents($path, enc_bytes($plain, 'file:' . $stored), LOCK_EX) === false) return [false, 'שמירת הקובץ נכשלה.'];
  @chmod($path, 0600);
  try {
    db()->prepare('INSERT INTO student_files (student_id, kind, orig_name, mime, size, stored, uploaded_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$studentId, $kind, clean_name((string)$f['name']), $mime, $size, $stored, $userId, now()]);
  } catch (Throwable $t) { @unlink($path); throw $t; }
  return [true, (string)$stored];
}
function remove_stored_file(string $stored): void {
  if (preg_match('/^[0-9a-f]{32}$/', $stored)) @unlink(PRIV . '/storage/' . $stored . '.bin');
}
