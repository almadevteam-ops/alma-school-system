<?php
// חיוב מותאם לחודש מסוים (למשל תלמידה שהצטרפה באמצע חודש: חצי חודש)
return function (PDO $pdo): void {
  $cols = array_column($pdo->query('SHOW COLUMNS FROM month_marks')->fetchAll(), 'Field');
  if (!in_array('amount', $cols, true)) $pdo->exec('ALTER TABLE month_marks ADD COLUMN amount DECIMAL(10,2) NULL');
};
