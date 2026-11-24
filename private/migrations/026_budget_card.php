<?php
// פירוט רכישות אשראי: עמודת מקור בתנועה (card = רכישה שממתינה לדביט בבנק, cardok = רכישה שהותאמה לדביט)
// וטבלת "קברים" של דביטים שפורטו, כדי שייבוא חוזר של דוח הבנק לא יחזיר אותם.
return function (PDO $pdo): void {
  if (!$pdo->query("SHOW COLUMNS FROM budget_tx LIKE 'src'")->fetch()) $pdo->exec("ALTER TABLE budget_tx ADD COLUMN src VARCHAR(8) NOT NULL DEFAULT ''");
  $pdo->exec('CREATE TABLE IF NOT EXISTS budget_tomb (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tx_date DATE NOT NULL,
    ref VARCHAR(100) NOT NULL DEFAULT \'\',
    amount DECIMAL(12,2) NOT NULL,
    name VARCHAR(150) NOT NULL DEFAULT \'\',
    created_at DATETIME NOT NULL,
    KEY ix_k (tx_date, ref, amount)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
};
