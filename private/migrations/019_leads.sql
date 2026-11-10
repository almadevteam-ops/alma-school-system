-- מאגר אחים ואחיות (מהספח של תעודת הזהות): תלמידות פוטנציאליות לרישום בעתיד
CREATE TABLE IF NOT EXISTS sibling_leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idn CHAR(9) NOT NULL,
  last_name VARCHAR(60) NOT NULL DEFAULT '',
  first_name VARCHAR(60) NOT NULL DEFAULT '',
  dob DATE NULL,
  gender CHAR(1) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'new',
  note VARCHAR(500) NOT NULL DEFAULT '',
  src VARCHAR(8) NOT NULL DEFAULT 'ocr',
  created_by VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_lead_idn (idn),
  INDEX (status), INDEX (dob)
) DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sibling_lead_links (
  lead_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (lead_id, student_id),
  INDEX (student_id),
  CONSTRAINT fk_sll_l FOREIGN KEY (lead_id) REFERENCES sibling_leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_sll_s FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4;
