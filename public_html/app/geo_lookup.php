<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
require_role(['admin', 'secretary']);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, max-age=300');
$t = (string)($_GET['t'] ?? '');
if (!geo_ready()) { echo '[]'; exit; }
if ($t === 'cities') { echo json_encode(geo_cities(), JSON_UNESCAPED_UNICODE); exit; }
if ($t === 'streets') { $id = geo_city_id((string)($_GET['city'] ?? '')); echo json_encode($id ? geo_streets($id) : [], JSON_UNESCAPED_UNICODE); exit; }
echo '[]';
