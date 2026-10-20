<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
redirect(user() ? 'students.php' : 'login.php');
