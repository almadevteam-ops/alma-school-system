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
/** סביבת בדיקות: מוגדרת ב-config.php ('env' => 'test'), או אוטומטית כשהמערכת יושבת בתיקייה test_env. בסביבה כזו שום חיבור החוצה לא פועל. */
function is_test_env(): bool { return cfg('env', 'prod') === 'test' || str_contains(str_replace('\\', '/', PRIV), '/test_env/') || str_ends_with(str_replace('\\', '/', PRIV), '/test_env/private'); }
function env_other_url(): string {
  if (is_test_env()) return (string)cfg('prod_url', 'https://' . preg_replace('~^test\.~', '', (string)cfg('host', 'alma-school.co.il')) . '/app/');
  return (string)cfg('test_url', 'https://test.' . (string)cfg('host', 'alma-school.co.il') . '/app/');
}
/** נתונים מבחוץ (משרד החינוך, אקסל) מגיעים לעיתים בסדר חזותי הפוך: ")אסייה(" במקום "(אסייה)". מחליף רק תבנית הפוכה בבירור. */
function bidi_fix($v): string {
  $v = (string)$v;
  return (strpos($v, ')') !== false && strpos($v, '(') !== false) ? (string)preg_replace_callback('/\)([^()]*)\(/u', fn($m) => '(' . $m[1] . ')', $v) : $v;
}
function e($v): string { return htmlspecialchars(bidi_fix($v), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
// ---- סביבת בדיקות: סיסמת גישה נוספת (נכשל סגור: בלי סיסמה מוגדרת, הגישה חסומה) ----
if (is_test_env() && PHP_SAPI !== 'cli') {
  header('X-Robots-Tag: noindex, nofollow');
  $gp = (string)cfg('test_gate', ''); $gc = $gp === '' ? '' : hash_hmac('sha256', 'alma-test-gate', 'gate|' . $gp);
  $okc = $gc !== '' && isset($_COOKIE['almagate']) && hash_equals($gc, (string)$_COOKIE['almagate']);
  $gerr = '';
  if (!$okc && $gp !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['_gate'])) {
    if (hash_equals($gp, (string)$_POST['_gate'])) { setcookie('almagate', $gc, ['expires' => time() + 43200, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']); header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?')); exit; }
    sleep(1); $gerr = 'הסיסמה שגויה.';
  }
  if (!$okc) {
    http_response_code($gp === '' ? 503 : 401);
    echo '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>סביבת בדיקות</title><link rel="stylesheet" href="/app/assets/style.css"></head><body class="envtest"><main><div class="card login"><h1>סביבת בדיקות</h1>'
       . ($gp === '' ? '<p class="er">הסביבה לא הוגדרה: חסר <code>test_gate</code> בקובץ ההגדרות.</p>' : '<p class="mu">הכניסה לסביבת הבדיקות מוגנת בסיסמה נוספת.</p>' . ($gerr !== '' ? '<p class="er">' . htmlspecialchars($gerr, ENT_QUOTES, 'UTF-8') . '</p>' : '') . '<form method="post"><label>סיסמת גישה<input type="password" name="_gate" autocomplete="off" autofocus required></label><button>המשך</button></form>')
       . '</div></main></body></html>';
    exit;
  }
}

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
require_once __DIR__ . '/valid.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/stu_extra.php';
require_once __DIR__ . '/income.php';
require_once __DIR__ . '/register.php';
require_once __DIR__ . '/moe.php';
require_once __DIR__ . '/oldsync.php';
require_once __DIR__ . '/gdrive.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/collection.php';
require_once __DIR__ . '/mailing.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/notes.php';
require_once __DIR__ . '/mfa_email.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/backup_delta.php';
require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/budget_pay.php';
require_once __DIR__ . '/budget_ledger.php';
require_once __DIR__ . '/budget_plan.php';
require_once __DIR__ . '/budget_drive.php';
require_once __DIR__ . '/test_mask.php';
require_once __DIR__ . '/charge_import.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/docs.php';

// ---- HTTPS ----
if (PHP_SAPI !== 'cli' && cfg('force_https', true) && !is_https()) {
  $h = cfg('host') ?: ($_SERVER['HTTP_HOST'] ?? '');
  redirect('https://' . $h . ($_SERVER['REQUEST_URI'] ?? '/'));
}

// ---- headers ----
header_remove('X-Powered-By');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; worker-src 'self'; manifest-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
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
  ini_set('session.gc_maxlifetime', '100000');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
  session_start();
  if (isset($_SESSION['uid'])) {
    $ua = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $idle = time() - (int)($_SESSION['last'] ?? 0);
    $age = time() - (int)($_SESSION['t0'] ?? 0);
    if ($idle > (int)($_SESSION['idle_s'] ?? cfg('idle', 1800)) || $age > (int)($_SESSION['max_s'] ?? cfg('maxage', 28800)) || ($_SESSION['ua'] ?? '') !== $ua) {
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
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
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
    if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['login.php', 'login2.php'], true) && empty($_SESSION['uid'])) { try { audit('login_csrf_bounce', 'system', '', basename((string)$_SERVER['SCRIPT_NAME']) . '; cookie=' . (isset($_COOKIE['ALMASESS']) ? 'yes' : 'no')); } catch (Throwable $t) {} header('Location: /app/login.php'); exit; }   // דף כניסה ישן (למשל באפליקציה שנפתחה מחדש): טוענים דף חדש במקום שגיאה
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
  if (isset(ROLE_PAGES[$row['role']]) && !in_array($page, array_merge(ROLE_PAGES[$row['role']], ['security.php', 'logout.php']), true)) redirect(home_page($_SESSION['user']));   // תפקידים מוגבלים: רק הדפים שלהם (ואבטחת החשבון)
  if (cfg('require_2fa', true) && !(int)$row['totp_enabled'] && !in_array($page, ['security.php', 'logout.php'], true)) redirect('security.php');
  return $_SESSION['user'];
}
function finish_login(array $row): never {
  session_regenerate_id(true);
  unset($_SESSION['p2']);
  $_SESSION['uid'] = (int)$row['id'];
  $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'name' => $row['display_name'], 'role' => $row['role']];
  $_SESSION['pwv'] = (int)$row['pw_ver'];
  if (!empty($row['sess_idle'])) $_SESSION['idle_s'] = max(300, min(43200, (int)$row['sess_idle'] * 60));
  if (!empty($row['sess_max'])) $_SESSION['max_s'] = max(3600, min(86400, (int)$row['sess_max'] * 3600));
  $_SESSION['t0'] = $_SESSION['last'] = time();
  $_SESSION['ua'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
  db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now(), $row['id']]);
  audit('login', 'user', (string)$row['id']);
  redirect(home_page($_SESSION['user']));
}
function require_role(array $roles): array {
  $u = require_login();
  if (!in_array($u['role'], $roles, true)) fail_page(403, 'אין לך הרשאה לדף זה.');
  return $u;
}
function can_see_ids(): bool { return in_array(user()['role'] ?? '', ['admin', 'secretary'], true); }
/** צפייה במספרי זהות (בלי גישה לקבצי הזהות עצמם). */
function can_see_idnum(): bool { return in_array(user()['role'] ?? '', ['admin', 'secretary', 'counselor'], true); }
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
     . '<meta name="robots" content="noindex,nofollow"><title>' . (is_test_env() ? '[בדיקות] ' : '') . e($title) . ' – עלמא</title><link rel="icon" href="/app/assets/favicon.ico?v=' . asset_v('favicon.ico') . '" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/app/assets/icon-32.png?v=' . asset_v('icon-32.png') . '"><link rel="icon" type="image/png" sizes="192x192" href="/app/assets/icon-192.png?v=' . asset_v('icon-192.png') . '"><link rel="apple-touch-icon" href="/app/assets/apple-touch-icon.png?v=' . asset_v('apple-touch-icon.png') . '"><link rel="manifest" href="/app/assets/manifest.json?v=' . asset_v('manifest.json') . '"><meta name="theme-color" content="#7ba23a"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="עלמא"><link rel="stylesheet" href="/app/assets/style.css?v=' . asset_v('style.css') . '"><script src="/app/assets/app.js?v=' . asset_v('app.js') . '" defer></script></head><body' . (is_test_env() ? ' class="envtest"' : '') . '>' . (is_test_env() ? '<div class="envbar">סביבת בדיקות · נתונים מומצאים · לא נשלחות הודעות אמיתיות · <a href="' . e(env_other_url()) . '">מעבר לאתר האמיתי</a></div>' : '');
  if ($nav && ($u = user())) {
    $cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $alias = ['student.php' => 'students.php', 'family.php' => 'collection.php', 'payer.php' => 'collection.php', 'collection_import.php' => 'collection.php', 'income_pending.php' => 'collection.php', 'payments_import.php' => 'collection.php', 'monthly.php' => 'collection.php', 'dashboard.php' => 'collection.php', 'charges.php' => 'collection.php', 'gad.php' => 'collection.php', 'income_import.php' => 'collection.php', 'registrations.php' => 'students.php', 'moe_sync.php' => 'students.php', 'phones_import.php' => 'students.php', 'oldsync.php' => 'import.php', 'rollover.php' => 'import.php', 'exports.php' => 'import.php'];
    $on = $alias[$cur] ?? $cur;
    $ic = fn(string $d) => '<svg class="ni" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    $icons = [
      'students.php' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2 .7 3.2 2.4 3.5 5.2"/>',
      'collection.php' => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M16.5 15h1.5"/>',
      'leads.php' => '<circle cx="9" cy="8" r="3.2"/><circle cx="17" cy="9.5" r="2.4"/><path d="M3 19c.6-3.4 3-5 6-5s5.4 1.6 6 5M15.5 14.2c2.6-.3 4.6 1 5.5 4"/>',
      'reminders.php' => '<path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
      'documents.php' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4M9.5 12h5M9.5 15.5h5"/>',
      'mailing.php' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 7l8.5 6 8.5-6"/>',
      'security.php' => '<path d="M12 3l7.5 3v5.5c0 4.5-3 8-7.5 9.5-4.5-1.5-7.5-5-7.5-9.5V6z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
      'maint' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3.5 17.5a1.8 1.8 0 0 0 2.5 2.5l5.8-5.8a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.2-.6-.6-2.2z"/>',
      'budget' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
      'admin' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1"/>',
    ];
    echo '<header class="top"><a class="brand" href="' . home_page($u) . '"><img src="/app/assets/logo-mark.png" alt="" width="30" height="30"><b>עלמא</b></a><nav>';
    $main = ['students.php' => 'תלמידות', 'collection.php' => 'גבייה']; if ($u['role'] === 'registrar') $main = ['leads.php' => 'מאגר אחים']; if ($u['role'] === 'counselor') $main = ['students.php' => 'תלמידות']; if (in_array($u['role'], ['admin', 'secretary'], true)) { $main['mailing.php'] = 'דיוור'; $main['documents.php'] = 'מסמכים'; $main['leads.php'] = 'מאגר אחים'; } if (in_array($u['role'], COLLECTION_ROLES, true)) $main['reminders.php'] = 'תזכורות'; $main['security.php'] = 'אבטחה';
    foreach ($main as $f => $l)
      echo '<a href="' . $f . '"' . ($f === $on ? ' class="on"' : '') . ' title="' . e($l) . '">' . $ic($icons[$f]) . '<span>' . e($l) . '</span></a>';
    if ($u['role'] === 'admin') {
      $adm = ['import.php' => 'ייבוא', 'users.php' => 'משתמשים', 'audit.php' => 'יומן פעולות', 'archive.php' => 'ארכיון', 'geo.php' => 'ערים ורחובות', 'settings.php' => 'הגדרות', 'release_notes.php' => 'מה חדש'];
      $mnt = ['backups.php' => 'גיבויים', 'backup_log.php' => 'יומן גיבויים', 'health.php' => 'בדיקת מערכת'];
      $bud = ['budget.php' => 'סיכום', 'budget_tx.php' => 'תנועות', 'budget_pay.php' => 'לתשלום', 'budget_suppliers.php' => 'ספקים', 'budget_plan.php' => 'תקציב מול ביצוע', 'budget_recon.php' => 'התאמת בנק', 'budget_cats.php' => 'קטגוריות וכללים'];
      foreach ([['תקציב', 'budget', $bud], ['ניהול', 'admin', $adm], ['תחזוקה', 'maint', $mnt]] as [$tt, $ik, $set]) {
        echo '<details class="menu' . (isset($set[$on]) ? ' on' : '') . '"><summary title="' . $tt . '">' . $ic($icons[$ik]) . '<span>' . $tt . '</span></summary><div class="dd">';
        foreach ($set as $f => $l) echo '<a href="' . $f . '"' . ($f === $on ? ' class="on"' : '') . '>' . e($l) . '</a>';
        if ($ik === 'maint') echo '<a href="' . e(env_other_url()) . '" class="envlnk">' . (is_test_env() ? 'חזרה לאתר האמיתי' : 'סביבת בדיקות') . '</a>' . (is_test_env() ? '<a href="testmail.php"' . ($on === 'testmail.php' ? ' class="on"' : '') . '>תיבת דואר בדיקות</a><a href="test_data.php"' . ($on === 'test_data.php' ? ' class="on"' : '') . '>נתוני בדיקות</a>' : '');
        echo '</div></details>';
      }
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
/** מספר הגרסה (למשל 1.0.31), נכתב ל-private/VERSION בכל פריסה. */
function app_version(): string {
  $f = PRIV . '/VERSION'; $v = is_file($f) ? trim((string)file_get_contents($f)) : '';
  return preg_match('/^\d+\.\d+\.\d+/', $v, $m) ? $m[0] : '';
}
function page_foot(): void { $v = app_version(); echo '</main>' . ($v !== '' && user() ? '<footer class="ver">' . (($u0 = user()) && $u0['role'] === 'admin' ? '<a href="release_notes.php" title="מה חדש">גרסה <bdi dir="ltr">' . e($v) . '</bdi></a>' : 'גרסה <bdi dir="ltr">' . e($v) . '</bdi>') . '</footer>' : '') . '</body></html>'; }

const GRADES = ['ז', 'ח', 'ט', 'י', 'יא', 'יב'];
const STATUS = ['active' => 'פעילה', 'left' => 'עזבה', 'pending' => 'ממתינה לאישור'];
const ROLES = ['admin' => 'מנהלת מערכת', 'secretary' => 'מזכירות', 'finance' => 'גבייה (צפייה בלבד, בלי מסמכים וזהויות)', 'registrar' => 'רשמת (מאגר אחים בצפייה בלבד)', 'counselor' => 'יועצת (תלמידות בצפייה בלבד)'];
/** תפקידים מוגבלים: הדפים היחידים שמותרים להם (בנוסף לאבטחה ויציאה). */
const ROLE_PAGES = ['registrar' => ['leads.php'], 'counselor' => ['students.php', 'student.php']];
/** דף הבית לפי תפקיד. */
function home_page(?array $u = null): string { $u = $u ?? user(); return ($u['role'] ?? '') === 'registrar' ? 'leads.php' : 'students.php'; }
