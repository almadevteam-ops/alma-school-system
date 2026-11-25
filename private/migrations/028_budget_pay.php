<?php
// תקציב: סימון "שולם" להוצאות, קבצי חשבונית/קבלה, חלונית "לתשלום" (חשבוניות ממתינות, תשלום חלקי) והיסטוריית קבצי מס"ב.
return function (PDO $pdo): void {
  if (!$pdo->query("SHOW COLUMNS FROM budget_tx LIKE 'paid'")->fetch()) $pdo->exec("ALTER TABLE budget_tx ADD COLUMN paid TINYINT(1) NOT NULL DEFAULT 1");   // כל מה שכבר קיים (נשאב מהבנק/אשראי) נחשב שולם
  if (!$pdo->query("SHOW COLUMNS FROM budget_tx LIKE 'payable_id'")->fetch()) $pdo->exec("ALTER TABLE budget_tx ADD COLUMN payable_id INT UNSIGNED NULL");
  $pdo->exec("CREATE TABLE IF NOT EXISTS budget_payables (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier VARCHAR(150) NOT NULL,
    biz_id VARCHAR(20) NOT NULL DEFAULT '',
    bank VARCHAR(3) NOT NULL DEFAULT '',
    branch VARCHAR(4) NOT NULL DEFAULT '',
    account VARCHAR(12) NOT NULL DEFAULT '',
    ref VARCHAR(60) NOT NULL DEFAULT '',
    inv_date DATE NULL,
    due_date DATE NULL,
    descr VARCHAR(300) NOT NULL DEFAULT '',
    category VARCHAR(100) NOT NULL DEFAULT '',
    total DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    pay_now DECIMAL(12,2) NULL,
    masav TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(8) NOT NULL DEFAULT 'open',
    moved_tx_id INT UNSIGNED NULL,
    created_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    KEY k_st (status, id),
    KEY k_biz (biz_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS budget_docs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tx_id INT UNSIGNED NULL,
    payable_id INT UNSIGNED NULL,
    kind VARCHAR(4) NOT NULL DEFAULT 'inv',
    orig_name VARCHAR(200) NOT NULL,
    stored VARCHAR(80) NOT NULL,
    mime VARCHAR(60) NOT NULL,
    size INT UNSIGNED NOT NULL DEFAULT 0,
    created_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    KEY k_tx (tx_id), KEY k_pay (payable_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS budget_masav (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_date DATE NOT NULL,
    cnt INT UNSIGNED NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    applied TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
