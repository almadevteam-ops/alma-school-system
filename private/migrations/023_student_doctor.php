<?php
// שם הרופא המטפל בכרטיס תלמידה (שדה רשות).
return function (PDO $pdo): void {
  $have = array_column($pdo->query('SHOW COLUMNS FROM students')->fetchAll(), 'Field');
  if (!in_array('doctor', $have, true)) $pdo->exec("ALTER TABLE students ADD COLUMN doctor VARCHAR(100) NOT NULL DEFAULT ''");
};
