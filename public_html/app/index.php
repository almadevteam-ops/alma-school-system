<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
redirect(user() ? home_page() : 'login.php');
