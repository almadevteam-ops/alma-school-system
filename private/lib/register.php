<?php
declare(strict_types=1);
// עמוד הרשמה ציבורי להורים: הגבלת קצב, ניקוי קלט והודעת הקבלה.
const REG_RATE_MAX = 6;       // הרשמות לשעה לכל כתובת IP
const REG_RATE_WINDOW = 3600;
function reg_rate_file(): string { return PRIV . '/storage/regrate_' . substr(hash('sha256', client_ip() . '|' . (string)cfg('app_key_salt', 'x')), 0, 24) . '.json'; }
function reg_rate_hits(): array {
  $f = reg_rate_file(); $a = is_file($f) ? json_decode((string)file_get_contents($f), true) : []; if (!is_array($a)) $a = [];
  return array_values(array_filter($a, fn($t) => is_int($t) && $t > time() - REG_RATE_WINDOW));
}
function reg_rate_ok(): bool { return count(reg_rate_hits()) < REG_RATE_MAX; }
function reg_rate_hit(): void { $a = reg_rate_hits(); $a[] = time(); @file_put_contents(reg_rate_file(), json_encode($a), LOCK_EX); }
function reg_url(): string { return 'https://' . (string)cfg('host', 'alma-school.co.il') . '/app/register.php'; }
function reg_message(): string {
  return 'ברוכים הבאים למשפחת עלמא! ברכות על קבלתך למקום הטוב ביותר. נא למלא את הפרטים בקישור, ולצרף צילום תעודת זהות וספח: ' . reg_url();
}

/** האם צורף לפחות קובץ תקין אחד בשדה טופס הגרושים (fd[]). */
function reg_divorce_ok(): bool {
  $f = $_FILES['fd'] ?? null; if (!$f || !is_array($f['name'])) return false;
  foreach ($f['name'] as $i => $_) {
    if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    $t = (string)$f['tmp_name'][$i]; $sz = (int)@filesize($t);
    if ($sz > 0 && $sz <= FILE_MAX && isset(FILE_MIMES[(string)(new finfo(FILEINFO_MIME_TYPE))->file($t)])) return true;
  }
  return false;
}
