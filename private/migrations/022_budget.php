<?php
// תקציב פנימי של בית הספר (מנהלת מערכת בלבד): תנועות, קטגוריות וכללי שיוך אוטומטי לפי מילות מפתח.
return function (PDO $pdo): void {
  $pdo->exec('CREATE TABLE IF NOT EXISTS budget_cats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec('CREATE TABLE IF NOT EXISTS budget_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ord INT UNSIGNED NOT NULL DEFAULT 0,
    kw VARCHAR(100) NOT NULL,
    category VARCHAR(100) NOT NULL,
    KEY ix_ord (ord)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec('CREATE TABLE IF NOT EXISTS budget_tx (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tx_date DATE NOT NULL,
    name VARCHAR(150) NOT NULL,
    descr VARCHAR(300) NOT NULL DEFAULT \'\',
    ref VARCHAR(100) NOT NULL DEFAULT \'\',
    amount DECIMAL(12,2) NOT NULL,
    is_income TINYINT(1) NOT NULL DEFAULT 0,
    category VARCHAR(100) NOT NULL DEFAULT \'\',
    unit VARCHAR(60) NOT NULL DEFAULT \'עלמא\',
    has_invoice TINYINT(1) NOT NULL DEFAULT 0,
    has_receipt TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    created_by VARCHAR(100) NOT NULL DEFAULT \'\',
    updated_at DATETIME NULL,
    KEY ix_date (tx_date),
    KEY ix_cat (category),
    KEY ix_unit (unit)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  if ((int)$pdo->query('SELECT COUNT(*) FROM budget_cats')->fetchColumn() === 0) {
    $cats = ['ביטוחים ואישורים', 'נקיון', 'אחזקה', 'ציוד', 'חוגים', 'חשמל ומים', 'הסעות', 'ארועים', 'טיולים', 'גינון', 'משכורות', 'קרן ההסעות', 'קופה קטנה (קק)', 'אינטרנט וטלפון', 'מכונות צילום', 'ספרים', 'פירסום ורישום', 'שיפוצים', 'הוצאות משפטיות', 'הוצאות משרד', 'חטיבת בנים', 'פרויקט מעבר למבנה חדש', 'עמלות בנקים', 'הנהלת חשבונות ורואי חשבון', 'הוצאות גפן', 'הכנסת גפן', 'סייעות', 'רכישות באשראי', 'שכר לימוד', 'החזר שכר לימוד'];
    $ins = $pdo->prepare('INSERT IGNORE INTO budget_cats (name) VALUES (?)');
    foreach ($cats as $c) $ins->execute([$c]);
  }
  if ((int)$pdo->query('SELECT COUNT(*) FROM budget_rules')->fetchColumn() === 0) {
    $FEE = 'עמלות בנקים'; $ACC = 'הנהלת חשבונות ורואי חשבון'; $BN = 'חטיבת בנים'; $MV = 'פרויקט מעבר למבנה חדש';
    $rules = [['עמל', $FEE], ['כרטיס אשראי (כאל)', 'רכישות באשראי'], ['רכישה בכרטיס דביט', 'רכישות באשראי'], ['ישראכרט', 'שכר לימוד'], ['זיכוי מוסד ממס"ב', 'שכר לימוד'], ['זיכוי מבנק', 'שכר לימוד'], ['החזר שיק', 'החזר שכר לימוד'], ['החזרת מ.ס.ב', 'החזר שכר לימוד'], ['החזרת זיכוי מסב', 'החזר שכר לימוד'],
      ['ענן המידע', 'אינטרנט וטלפון'], ['וויקום', 'אינטרנט וטלפון'], ['המרת מטח', $BN], ['עיריית חיפה', 'סייעות'], ['עיריית נשר', 'סייעות'], ['עיריית קרית אתא', 'סייעות'], ['קרית אתא', 'סייעות'],
      ['אפרת ונשטיין', $ACC], ['אפרת וינשטיין', $ACC], ['אפרת ווינשטיין', $ACC], ['מטרה', $FEE], ['הנהלת חשבונות', $ACC], ['רו"ח', $ACC], ['רואה חשבון', $ACC], ['רואי חשבון', $ACC],
      ['הובלות', $MV], ['מצוינות ביחד נשר', $BN], ['משכורת', $BN], ['המרה', $BN], ['חו"ל', $BN], ['בנים', $BN],
      ['הכשרה ביטוח', 'ביטוחים ואישורים'], ['איילון', 'ביטוחים ואישורים'], ['ליברמן', 'ביטוחים ואישורים'], ['קופיטק', 'מכונות צילום'], ['פנאי פלאי', 'הוצאות משרד'],
      ['019', 'אינטרנט וטלפון'], ['פלאפון', 'אינטרנט וטלפון'], ['בזק', 'אינטרנט וטלפון'], ['רימון', 'אינטרנט וטלפון'], ['מולי את לירן', 'הסעות'], ['קרן הסעה', 'קרן ההסעות'],
      ['ק"ק', 'קופה קטנה (קק)'], ['עורך דין', 'הוצאות משפטיות'], ['חדשות דתי', 'פירסום ורישום'], ['פייסבוק', 'פירסום ורישום'], ['דינה פרץ', 'פירסום ורישום'], ['אור ספרים', 'ספרים'], ['ספרים', 'ספרים'], ['מי כרמל', 'חשמל ומים']];
    $ins = $pdo->prepare('INSERT INTO budget_rules (ord, kw, category) VALUES (?,?,?)');
    foreach ($rules as $i => [$k, $c]) $ins->execute([($i + 1) * 10, $k, $c]);
  }
};
