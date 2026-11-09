-- דיוור להורים: כל שליחה נשמרת עם רשימת הנמענים, כדי לשלוח בהדרגה ולראות מי קיבל ומי נכשל
CREATE TABLE IF NOT EXISTS mailings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  audience VARCHAR(255) NOT NULL DEFAULT '',
  total INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'ready',
  created_by VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  finished_at DATETIME NULL
) DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mailing_rcpt (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mailing_id INT UNSIGNED NOT NULL,
  email VARCHAR(150) NOT NULL,
  names VARCHAR(255) NOT NULL DEFAULT '',
  balance DECIMAL(10,2) NOT NULL DEFAULT 0,
  status VARCHAR(8) NOT NULL DEFAULT 'pending',
  err VARCHAR(200) NOT NULL DEFAULT '',
  sent_at DATETIME NULL,
  INDEX (mailing_id, status),
  CONSTRAINT fk_mr_m FOREIGN KEY (mailing_id) REFERENCES mailings(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4;
