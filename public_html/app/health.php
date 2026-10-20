<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
require_role(['admin']);
$checks = [];
$checks['גרסת PHP'] = [PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];
foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'gd', 'zip', 'intl', 'sodium'] as $x) $checks['תוסף ' . $x] = [extension_loaded($x) ? 'קיים' : 'חסר', extension_loaded($x)];
$checks['גרסת המערכת'] = [is_file(PRIV . '/VERSION') ? trim((string)file_get_contents(PRIV . '/VERSION')) : 'לא ידועה', true];
$checks['חיבור HTTPS'] = [is_https() ? 'כן' : 'לא', is_https()];
$checks['גרסת מסד נתונים'] = [(string)db()->query('SELECT VERSION()')->fetchColumn(), true];
$checks['תיקיית סשנים כתיבה'] = [is_writable(PRIV . '/sessions') ? 'כן' : 'לא', is_writable(PRIV . '/sessions')];
$checks['תיקיית קבצים (private/storage) כתיבה'] = [is_writable(PRIV . '/storage') ? 'כן' : 'לא', is_writable(PRIV . '/storage')];
$checks['תיקיית private מחוץ ל-public_html'] = [realpath(PRIV) ?: '?', strpos((string)realpath(PRIV), (string)realpath($_SERVER['DOCUMENT_ROOT'])) !== 0];
$checks['setup.php נמחק'] = [file_exists(__DIR__ . '/../setup.php') ? 'לא – יש למחוק!' : 'כן', !file_exists(__DIR__ . '/../setup.php')];
$checks['display_errors כבוי'] = [ini_get('display_errors') ?: 'off', !in_array(strtolower((string)ini_get('display_errors')), ['1', 'on', 'true'], true)];
page_head('בדיקת מערכת');
?>
<h1>בדיקת מערכת</h1>
<div class="card"><table>
<?php foreach ($checks as $k => [$v, $ok]) echo '<tr><td>' . e($k) . '</td><td>' . e($v) . '</td><td class="' . ($ok ? 'ok' : 'er') . '">' . ($ok ? '✓ תקין' : '✗ לטיפול') . '</td></tr>'; ?>
</table></div>
<?php page_foot();
