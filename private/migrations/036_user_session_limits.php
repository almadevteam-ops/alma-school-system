<?php
// משך חיבור לכל משתמש: ניתוק אחרי חוסר פעילות (בדקות) ומשך מרבי ברצף (בשעות). ריק = ברירת המחדל של המערכת.
return function (PDO $pdo): void {
  $has = fn(string $c) => (bool)$pdo->query("SHOW COLUMNS FROM users LIKE '$c'")->fetch();
  if (!$has('sess_idle')) $pdo->exec('ALTER TABLE users ADD COLUMN sess_idle SMALLINT UNSIGNED NULL');
  if (!$has('sess_max')) $pdo->exec('ALTER TABLE users ADD COLUMN sess_max SMALLINT UNSIGNED NULL');
};
