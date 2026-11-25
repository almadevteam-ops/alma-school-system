<?php
// יומן תשלומים לחשבוניות "לתשלום" – מקור לכרטסת ספק (כל תשלום, מלא או חלקי, נרשם בנפרד)
return function (PDO $pdo): void {
  $pdo->exec('CREATE TABLE IF NOT EXISTS budget_pay_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payable_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paid_on DATE NOT NULL,
    note VARCHAR(120) NOT NULL DEFAULT "",
    created_by VARCHAR(100) NOT NULL DEFAULT "",
    created_at DATETIME NOT NULL,
    KEY k_pl_pay (payable_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  // השלמה לאחור: תשלומים שנרשמו לפני היומן
  $pdo->exec("INSERT INTO budget_pay_log (payable_id, amount, paid_on, note, created_by, created_at)
    SELECT p.id, p.paid_amount, DATE(COALESCE(p.paid_at, p.created_at)), 'תשלום (לפני יומן התשלומים)', 'מערכת', NOW() FROM budget_payables p
    WHERE p.paid_amount > 0 AND NOT EXISTS (SELECT 1 FROM budget_pay_log l WHERE l.payable_id = p.id)");
};
