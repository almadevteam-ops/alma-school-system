<?php
// תקציב מתוכנן לפי שנה / יחידה / קטגוריה; "לא נדרש מסמך" בתנועה; יחידה בחשבונית לתשלום
return function (PDO $pdo): void {
  $pdo->exec('CREATE TABLE IF NOT EXISTS budget_plan (
    year_start SMALLINT UNSIGNED NOT NULL,
    unit VARCHAR(60) NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (year_start, unit, category)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $col = fn(string $t) => array_column($pdo->query("SHOW COLUMNS FROM $t")->fetchAll(), 'Field');
  if (!in_array('doc_na', $col('budget_tx'), true)) $pdo->exec('ALTER TABLE budget_tx ADD COLUMN doc_na TINYINT(1) NOT NULL DEFAULT 0');
  if (!in_array('unit', $col('budget_payables'), true)) $pdo->exec("ALTER TABLE budget_payables ADD COLUMN unit VARCHAR(60) NOT NULL DEFAULT ''");
};
