<?php
// איחוד שמות ספקים: כמה שמות (למשל בבנק ובחשבונית) מוצגים ככרטסת אחת
return function (PDO $pdo): void {
  $pdo->exec('CREATE TABLE IF NOT EXISTS budget_supplier_alias (
    alias VARCHAR(190) NOT NULL PRIMARY KEY,
    supplier VARCHAR(190) NOT NULL,
    created_by VARCHAR(100) NOT NULL DEFAULT "",
    created_at DATETIME NOT NULL,
    KEY k_sa_sup (supplier)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
};
