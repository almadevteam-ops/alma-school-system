<?php
// תפקיד חדש "יועצת": צפייה ברשימת התלמידות ובכרטיס התלמידה, בלי עריכה.
return function (PDO $pdo): void {
  $c = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
  if ($c && !str_contains((string)$c['Type'], 'counselor')) $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','secretary','finance','registrar','counselor') NOT NULL DEFAULT 'secretary'");
};
