<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
require_role(['admin']);
$rows = db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 300')->fetchAll();
page_head('יומן פעולות');
?>
<h1>יומן פעולות <span class="mu">(300 אחרונות)</span></h1>
<div class="card sc"><table>
<tr><th>זמן</th><th>משתמשת</th><th>פעולה</th><th>פריט</th><th>פירוט</th><th>IP</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['created_at']) ?></td><td><?= e($r['username']) ?></td><td><?= e($r['action']) ?></td>
<td><?= e($r['entity'] . ' ' . $r['entity_id']) ?></td><td><?= e($r['detail']) ?></td><td><?= e($r['ip']) ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php page_foot();
