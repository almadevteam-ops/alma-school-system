<?php
// שלב 2: סימוני חודש (בטיפול/החזר/לא רלוונטי), הנחה וסימון לחיוב, הסדרת אשראי, תשלום חודשי של מורשת גד.
return function (PDO $pdo): void {
  $cols = fn(string $t) => array_column($pdo->query("SHOW COLUMNS FROM $t")->fetchAll(), 'Field');
  if (!in_array('card_ok', $cols('families'), true)) $pdo->exec('ALTER TABLE families ADD COLUMN card_ok DATE NULL');
  $c = $cols('charges');
  foreach (['disc_amt' => 'DECIMAL(10,2) NOT NULL DEFAULT 0', 'disc_by' => "VARCHAR(100) NOT NULL DEFAULT ''", 'disc_note' => "VARCHAR(255) NOT NULL DEFAULT ''", 'mark' => "VARCHAR(1) NOT NULL DEFAULT ''"] as $k => $d)
    if (!in_array($k, $c, true)) $pdo->exec("ALTER TABLE charges ADD COLUMN $k $d");
  $pdo->exec('CREATE TABLE IF NOT EXISTS month_marks (
    family_id INT UNSIGNED NOT NULL, month TINYINT UNSIGNED NOT NULL, mark VARCHAR(1) NOT NULL,
    updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL,
    PRIMARY KEY (family_id, month),
    CONSTRAINT fk_mm_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec("CREATE TABLE IF NOT EXISTS gad_months (
    month TINYINT UNSIGNED NOT NULL PRIMARY KEY, paid TINYINT(1) NOT NULL DEFAULT 0, ref VARCHAR(100) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
