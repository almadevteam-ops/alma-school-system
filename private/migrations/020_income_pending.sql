-- שורות מדוח ההכנסות שלא שויכו לתיק: נשמרות עד שיימצא למי הן שייכות (בלי לקלוט את הדוח מחדש)
CREATE TABLE IF NOT EXISTS income_pending (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rkey VARCHAR(60) NOT NULL,
  paid_on DATE NULL,
  donor VARCHAR(120) NOT NULL DEFAULT '',
  amount DECIMAL(10,2) NOT NULL,
  cat VARCHAR(100) NOT NULL DEFAULT '',
  note VARCHAR(200) NOT NULL DEFAULT '',
  data MEDIUMTEXT NOT NULL,
  report_name VARCHAR(120) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_rkey (rkey)
) DEFAULT CHARSET=utf8mb4;
