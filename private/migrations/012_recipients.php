<?php
// נמעני מייל "תלמידה חדשה": טבלה שמנהלת המערכת עורכת בדף ההגדרות. ברירת מחדל: שני הנמענים שהוגדרו.
return function (PDO $pdo): void {
  $pdo->exec('CREATE TABLE IF NOT EXISTS notify_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT \'\',
    created_at DATETIME NOT NULL,
    created_by VARCHAR(100) NOT NULL DEFAULT \'\',
    UNIQUE KEY uq_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  if ((int)$pdo->query('SELECT COUNT(*) FROM notify_recipients')->fetchColumn() > 0) return;
  $old = '';
  try { $old = (string)$pdo->query("SELECT v FROM app_settings WHERE k = 'new_student_notify'")->fetchColumn(); } catch (Throwable $t) {}
  $list = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/u', $old) ?: []), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
  $rows = $list ? array_map(fn($a) => [$a, ''], $list) : [['af0573134741@gmail.com', 'אהובה פרידלמן'], ['beyahad.hativa@gmail.com', 'מזכירות']];
  $ins = $pdo->prepare('INSERT IGNORE INTO notify_recipients (email, label, created_at, created_by) VALUES (?,?,NOW(),?)');
  foreach ($rows as [$a, $l]) $ins->execute([strtolower($a), $l, 'מערכת']);
};
