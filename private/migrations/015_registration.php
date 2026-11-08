<?php
// שלב 4: הרשמה (העדפת תשלום והסדרת אשראי לממתינות, מקור ההרשמה).
return function (PDO $pdo): void {
  $have = array_column($pdo->query('SHOW COLUMNS FROM students')->fetchAll(), 'Field');
  foreach (['pay_pref' => "VARCHAR(20) NOT NULL DEFAULT ''", 'card_ok' => 'DATE NULL', 'reg_at' => 'DATETIME NULL', 'reg_ip' => "VARCHAR(45) NOT NULL DEFAULT ''"] as $c => $d)
    if (!in_array($c, $have, true)) $pdo->exec("ALTER TABLE students ADD COLUMN $c $d");
};
