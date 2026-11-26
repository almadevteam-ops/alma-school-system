<?php
// תפקיד חדש "רשמת": רואה רק את מאגר האחים (בצפייה בלבד).
return function (PDO $pdo): void {
  $c = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
  if ($c && !str_contains((string)$c['Type'], 'registrar')) $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','secretary','finance','registrar') NOT NULL DEFAULT 'secretary'");
};
