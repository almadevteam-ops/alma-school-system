CREATE TABLE IF NOT EXISTS student_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  author_id INT UNSIGNED NULL,
  author_name VARCHAR(100) NOT NULL DEFAULT '',
  legacy TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX (student_id, created_at),
  CONSTRAINT fk_sn_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS note_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id INT UNSIGNED NOT NULL,
  orig_name VARCHAR(200) NOT NULL,
  mime VARCHAR(80) NOT NULL,
  size INT UNSIGNED NOT NULL,
  stored VARCHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  INDEX (note_id),
  CONSTRAINT fk_nf_note FOREIGN KEY (note_id) REFERENCES student_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO student_notes (student_id, body, author_id, author_name, legacy, created_at)
  SELECT id, CONCAT('הועבר מהמערכת הישנה: ', TRIM(notes)), NULL, 'העברה מהמערכת הישנה', 1, NOW() FROM students WHERE notes IS NOT NULL AND TRIM(notes) <> '';
INSERT INTO student_notes (student_id, body, author_id, author_name, legacy, created_at)
  SELECT s.id, CONCAT('הועבר מהמערכת הישנה: ', TRIM(f.notes)), NULL, 'העברה מהמערכת הישנה', 1, NOW()
  FROM families f JOIN students s ON s.family_id = f.id WHERE f.notes IS NOT NULL AND TRIM(f.notes) <> '';
