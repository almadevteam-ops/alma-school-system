<?php
// מסמכים: תבניות (אישור לימודים ומסמכים בעריכה ידנית) ותור שליחה במייל.
return function (PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS doc_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    align CHAR(1) NOT NULL DEFAULT 'c',
    signed TINYINT(1) NOT NULL DEFAULT 1,
    builtin TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS doc_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch VARCHAR(20) NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    year INT NOT NULL,
    to_email VARCHAR(150) NOT NULL,
    subject VARCHAR(200) NOT NULL DEFAULT '',
    msg TEXT NULL,
    status VARCHAR(8) NOT NULL DEFAULT 'pending',
    err VARCHAR(200) NOT NULL DEFAULT '',
    created_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    KEY k_st (status, id),
    KEY k_batch (batch)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS doc_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    align CHAR(1) NOT NULL DEFAULT 'c',
    signed TINYINT(1) NOT NULL DEFAULT 1,
    year INT NOT NULL,
    doc_date DATE NOT NULL,
    sent_to VARCHAR(400) NOT NULL DEFAULT '',
    created_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    KEY k_stu (student_id, id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  if ((int)$pdo->query("SELECT COUNT(*) FROM doc_templates WHERE builtin = 1")->fetchColumn() === 0) {
    $body = "הרינו לאשר כי התלמידה: {שם}\nת\"ז: {תז}\nלומדת בבית ספרינו בשנת הלימודים {שנה_עברית} ({שנה})\nסמל מוסד: {סמל_מוסד}.";
    $pdo->prepare("INSERT INTO doc_templates (name, title, body, align, signed, builtin, created_at, updated_at) VALUES (?,?,?,?,1,1,NOW(),NOW())")->execute(['אישור לימודים', 'אישור לימודים', $body, 'c']);
  }
};
