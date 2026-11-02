<?php
// קובץ הגדרות – נמצא מחוץ לתיקיית האתר (public_html). לעולם לא להעלות אותו ל-public_html!
return [
  'db_host'     => 'localhost',
  'db_name'     => 'almascho_school',
  'db_user'     => 'almascho_app',
  'db_pass'     => 'PUT_DB_PASSWORD_HERE',          // להכניס כאן את סיסמת המסד
  'setup_key'   => 'CHANGE_ME_LONG_RANDOM_KEY_40+', // מפתח חד-פעמי להתקנה: לפחות 30 תווים אקראיים
  'host'        => 'alma-school.co.il',
  'force_https' => true,
  'idle'        => 1800,   // ניתוק אחרי 30 דקות חוסר פעילות
  'maxage'      => 28800,  // ניתוק מוחלט אחרי 8 שעות
  // 'mail_from' => 'noreply@alma-school.co.il', // כתובת השולח של קודי הכניסה במייל (ברירת מחדל: noreply@ + host)
  // שליחת מייל דרך Gmail (סיסמת אפליקציה): להסיר את // בשורות הבאות ולמלא
  // 'smtp_host'   => 'smtp.gmail.com',
  // 'smtp_secure' => 'ssl',            // ssl (פורט 465) או tls (פורט 587)
  // 'smtp_user'   => 'ADDRESS@gmail.com',
  // 'smtp_pass'   => 'סיסמת האפליקציה (16 תווים)',
  // 'mail_from'   => 'ADDRESS@gmail.com', // חייב להיות כתובת החשבון
];
