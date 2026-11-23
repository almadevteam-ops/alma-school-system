<?php
// תזכורות גבייה: טבלת תזכורות לכל תיק + מייל למשתמש (אליו נשלחות התזכורות).
return function (PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    kind VARCHAR(8) NOT NULL DEFAULT 'once',
    day_of_month TINYINT UNSIGNED NULL,
    next_at DATE NULL,
    until_at DATE NULL,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(8) NOT NULL DEFAULT 'active',
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NULL,
    last_err VARCHAR(200) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    KEY k_due (status, next_at),
    KEY k_fam (family_id)
  ) DEFAULT CHARSET=utf8mb4");
  $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
  if (!$has) $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) NOT NULL DEFAULT ''");
};
