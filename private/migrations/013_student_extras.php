<?php
// שדות כרטיס תלמידה שהיו במערכת הישנה: רגישויות, ועדה, 07, ספרים, טלפון בית, חודש עזיבה, הנחה, דירה, אחים מהספח.
return function (PDO $pdo): void {
  $have = array_column($pdo->query('SHOW COLUMNS FROM students')->fetchAll(), 'Field');
  $add = [
    'home_phone' => "VARCHAR(30) NOT NULL DEFAULT ''",
    'allergy' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'allergy_list' => "VARCHAR(120) NOT NULL DEFAULT ''",
    'allergy_other' => "VARCHAR(120) NOT NULL DEFAULT ''",
    'vaada' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'vaada_note' => "VARCHAR(255) NOT NULL DEFAULT ''",
    's07' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'books' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'left_month' => 'TINYINT UNSIGNED NULL',
    'is_apt' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'disc_on' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'disc_amt' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    'disc_status' => "VARCHAR(10) NOT NULL DEFAULT 'approved'",
    'disc_by' => "VARCHAR(100) NOT NULL DEFAULT ''",
    'disc_link' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'disc_note' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'old_ref' => "VARCHAR(20) NOT NULL DEFAULT ''",
  ];
  foreach ($add as $c => $def) if (!in_array($c, $have, true)) $pdo->exec("ALTER TABLE students ADD COLUMN $c $def");
  $pdo->exec('CREATE TABLE IF NOT EXISTS student_sibs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    idn VARCHAR(20) NOT NULL DEFAULT \'\',
    dob VARCHAR(12) NOT NULL DEFAULT \'\',
    created_at DATETIME NOT NULL,
    INDEX (student_id),
    CONSTRAINT fk_sib_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  // תלמידות בדירה: מי ששכר הלימוד שלה משולם ע"י גורם (עמותה), או שהכתובת "דירה ב..."
  $pdo->exec("UPDATE students s LEFT JOIN families f ON f.id = s.family_id SET s.is_apt = 1 WHERE f.payer_kind = 'org' OR s.addr LIKE 'דירה%' OR s.city LIKE 'דירה%'");
};
