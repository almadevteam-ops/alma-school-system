<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && user()) { csrf_check(); audit('logout'); }
session_wipe();
redirect('login.php');
