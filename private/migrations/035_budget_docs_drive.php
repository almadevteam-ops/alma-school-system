<?php
// גיבוי חשבוניות לדרייב: מעקב אחרי מה שכבר הועלה.
return function (PDO $pdo): void {
  $has = fn(string $c) => (bool)$pdo->query("SHOW COLUMNS FROM budget_docs LIKE '$c'")->fetch();
  if (!$has('drive_id')) $pdo->exec("ALTER TABLE budget_docs ADD COLUMN drive_id VARCHAR(80) NOT NULL DEFAULT ''");
  if (!$has('drive_at')) $pdo->exec("ALTER TABLE budget_docs ADD COLUMN drive_at DATETIME NULL");
  if (!$has('drive_err')) $pdo->exec("ALTER TABLE budget_docs ADD COLUMN drive_err VARCHAR(200) NOT NULL DEFAULT ''");
};
