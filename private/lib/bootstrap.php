<?php
declare(strict_types=1);
define('PRIV', dirname(__DIR__));
$CFG = require PRIV . '/config.php';
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Jerusalem');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function cfg(string $k, $d = null) { global $CFG; return $CFG[$k] ?? $d; }
function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443'); }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function now(): string { return date('Y-m-d H:i:s'); }
function redirect(string $to): never { header('Location: ' . $to); exit; }

function fail_page(int $code, string $msg): never {
  http_response_code($code);
  echo '<!doctype html><meta charset="utf-8"><body dir="rtl" style="font-family:sans-serif;padding:30px">' . e($msg) . '</body>';
  exit;
}
set_exception_handler(function (Throwable $t) {
  error_log('[alma] ' . get_class($t) . ': ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
  fail_page(500, 'אירעה שגיאה. נסו שוב מאוחר יותר.');
});

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/collection.php';
require_once __DIR__ . '/notes.php';
require_once __DIR__ . '/mfa_email.php';

// ---- HTTPS ----
if (cfg('force_https', true) && !is_https()) {
  $h = cfg('host') ?: ($_SERVER['HTTP_HOST'] ?? '');
  redirect('https://' . $h . ($_SERVER['REQUEST_URI'] ?? '/'));
}

// ---- headers ----
header_remove('X-Powered-By');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store');
if (is_https()) header('Strict-Transport-Security: max-age=15552000');

// ---- DB ----
function db(): PDO {
  static $pdo = null;
  if (!$pdo) {
    $pdo = new PDO('mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4',
      cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);
  }
  return $pdo;
}

// ---- session ----
function session_boot(): void {
  $p = PRIV . '/sessions';
  if (!is_dir($p)) @mkdir($p, 0700, true);
  session_save_path($p);
  session_name('ALMASESS');
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  ini_set('session.gc_probability', '1');
  ini_set('session.gc_divisor', '50');
  ini_set('session.gc_maxlifetime', '36000');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
  session_start();
  if (isset($_SESSION['uid'])) {
    $ua = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $idle = time() - (int)($_SESSION['last'] ?? 0);
    $age = time() - (int)($_SESSION['t0'] ?? 0);
    if ($idle > (int)cfg('idle', 1800) || $age > (int)cfg('maxage', 28800) || ($_SESSION['ua'] ?? '') !== $ua) {
      session_wipe();
    } else {
      $_SESSION['last'] = time();
    }
  }
}
function session_wipe(): void {
  $_SESSION = [];
  if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
  session_boot_fresh();
}
function session_boot_fresh(): void {
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
  session_start();
  session_regenerate_id(true);
}
session_boot();

// ---- CSRF ----
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_t" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
  if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['_t'] ?? ''))) {
    fail_page(403, 'הבקשה לא תקינה או שהדף פג תוקף. רעננו את הדף ונסו שוב.');
  }
}

// ---- auth ----
function user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array {
  $u = user();
  if (!$u) redirect('login.php');
  $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if (migrations_pending()) {
    if (($u['role'] ?? '') === 'admin' && $page !== 'update.php') redirect('update.php');
    if (($u['role'] ?? '') !== 'admin') fail_page(503, 'המערכת בעדכון. נסו שוב בעוד כמה דקות.');
  }
  $st = db()->prepare('SELECT id, username, display_name, role, active, pw_ver, totp_enabled FROM users WHERE id = ?');
  $st->execute([$u['id']]);
  $row = $st->fetch();
  if (!$row || !(int)$row['active'] || (int)$row['pw_ver'] !== (int)($_SESSION['pwv'] ?? -1)) {
    session_wipe();
    redirect('login.php');
  }
  $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'name' => $row['display_name'], 'role' => $row['role'], 'totp' => (int)$row['totp_enabled']];
  if (cfg('require_2fa', true) && !(int)$row['totp_enabled'] && !in_array($page, ['security.php', 'logout.php'], true)) redirect('security.php');
  return $_SESSION['user'];
}
function finish_login(array $row): never {
  session_regenerate_id(true);
  unset($_SESSION['p2']);
  $_SESSION['uid'] = (int)$row['id'];
  $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'name' => $row['display_name'], 'role' => $row['role']];
  $_SESSION['pwv'] = (int)$row['pw_ver'];
  $_SESSION['t0'] = $_SESSION['last'] = time();
  $_SESSION['ua'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
  db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now(), $row['id']]);
  audit('login', 'user', (string)$row['id']);
  redirect('students.php');
}
function require_role(array $roles): array {
  $u = require_login();
  if (!in_array($u['role'], $roles, true)) fail_page(403, 'אין לך הרשאה לדף זה.');
  return $u;
}
function can_see_ids(): bool { return in_array(user()['role'] ?? '', ['admin', 'secretary'], true); }
function can_edit(): bool { return in_array(user()['role'] ?? '', ['admin', 'secretary'], true); }
function hash_pw(string $pw): string { return password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT); }
function pw_ok(string $pw): ?string {
  if (mb_strlen($pw) < 10) return 'הסיסמה חייבת להכיל לפחות 10 תווים.';
  if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'הסיסמה חייבת לכלול אותיות באנגלית וגם ספרות.';
  return null;
}

function audit(string $action, string $entity = '', string $id = '', string $detail = ''): void {
  $u = user();
  db()->prepare('INSERT INTO audit_log (created_at, user_id, username, action, entity, entity_id, detail, ip) VALUES (?,?,?,?,?,?,?,?)')
    ->execute([now(), $u['id'] ?? null, $u['username'] ?? '-', $action, $entity, $id, mb_substr($detail, 0, 500), client_ip()]);
}

// ---- layout ----
/** גרסת קובץ סטטי לפי זמן שינוי, כדי שהדפדפן יטען עיצוב חדש אחרי כל עדכון. */
function asset_v(string $f): string { $p = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/app/assets/' . $f; return is_file($p) ? (string)filemtime($p) : '1'; }
function page_head(string $title, bool $nav = true): void {
  echo '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
     . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' – עלמא</title><link rel="stylesheet" href="/app/assets/style.css?v=' . asset_v('style.css') . '"></head><body>';
  if ($nav && ($u = user())) {
    $cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $alias = ['student.php' => 'students.php', 'family.php' => 'collection.php', 'payer.php' => 'collection.php', 'collection_import.php' => 'collection.php', 'payments_import.php' => 'collection.php'];
    $on = $alias[$cur] ?? $cur;
    $ic = fn(string $d) => '<svg class="ni" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    $icons = [
      'students.php' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2 .7 3.2 2.4 3.5 5.2"/>',
      'collection.php' => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M16.5 15h1.5"/>',
      'security.php' => '<path d="M12 3l7.5 3v5.5c0 4.5-3 8-7.5 9.5-4.5-1.5-7.5-5-7.5-9.5V6z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
      'admin' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1"/>',
    ];
    echo '<header class="top"><a class="brand" href="students.php"><img src="/app/assets/logo-mark.png" alt="" width="30" height="30"><b>עלמא</b></a><nav>';
    foreach (['students.php' => 'תלמידות', 'collection.php' => 'גבייה', 'security.php' => 'אבטחה'] as $f => $l)
      echo '<a href="' . $f . '"' . ($f === $on ? ' class="on"' : '') . ' title="' . e($l) . '">' . $ic($icons[$f]) . '<span>' . e($l) . '</span></a>';
    if ($u['role'] === 'admin') {
      $adm = ['import.php' => 'ייבוא', 'users.php' => 'משתמשים', 'audit.php' => 'יומן פעולות', 'health.php' => 'בדיקת מערכת'];
      echo '<details class="menu' . (isset($adm[$on]) ? ' on' : '') . '"><summary title="ניהול">' . $ic($icons['admin']) . '<span>ניהול</span></summary><div class="dd">';
      foreach ($adm as $f => $l) echo '<a href="' . $f . '"' . ($f === $on ? ' class="on"' : '') . '>' . e($l) . '</a>';
      echo '</div></details>';
    }
    echo '</nav><form method="post" action="logout.php" class="inl">' . csrf_field() . '<span class="mu">' . e($u['name']) . '</span> <button class="lnk">יציאה</button></form></header>';
  }
  echo '<main>';
}
/** סקשן מתקפל. פתוח אם הוא ב-?open=a,b בכתובת, ואם אין פרמטר – לפי $defaults. */
function sec_open(string $key, string $title, string $summary = '', string $ico = '•', array $defaults = []): void {
  $open = isset($_GET['open']) ? in_array($key, explode(',', (string)$_GET['open']), true) : in_array($key, $defaults, true);
  echo '<details class="fsec" id="s-' . e($key) . '"' . ($open ? ' open' : '') . '><summary><span class="ico">' . e($ico) . '</span>' . e($title) . '<span class="sm2">' . e($summary) . '</span></summary><div class="bd">';
}
function sec_close(): void { echo '</div></details>'; }
function page_foot(): void { echo '</main></body></html>'; }

const GRADES = ['ז', 'ח', 'ט', 'י', 'יא', 'יב'];
const STATUS = ['active' => 'פעילה', 'left' => 'עזבה', 'pending' => 'ממתינה לאישור'];
const ROLES = ['admin' => 'מנהלת מערכת', 'secretary' => 'מזכירות', 'finance' => 'גבייה (צפייה בלבד, בלי מסמכים וזהויות)'];
