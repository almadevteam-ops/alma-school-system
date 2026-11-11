-- יומן גיבויים: שורה לכל פעולת גיבוי, העלאה לדרייב, ניקוי ישנים ובדיקת תקינות
CREATE TABLE IF NOT EXISTS backup_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  kind VARCHAR(10) NOT NULL,
  target VARCHAR(10) NOT NULL,
  status VARCHAR(10) NOT NULL,
  file VARCHAR(120) NOT NULL DEFAULT '',
  location VARCHAR(255) NOT NULL DEFAULT '',
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rows_n INT UNSIGNED NOT NULL DEFAULT 0,
  files_n INT UNSIGNED NOT NULL DEFAULT 0,
  secs DECIMAL(8,2) NOT NULL DEFAULT 0,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  KEY ix_started (started_at),
  KEY ix_kind (kind, status)
) DEFAULT CHARSET=utf8mb4;
