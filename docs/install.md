מערכת ניהול תלמידות – שלב 1 (כניסה מאובטחת + מסד נתונים + תלמידות + יומן פעולות)

חשוב: לא מעלים נתוני אמת לפני שיש SSL תקין (מנעול ירוק) ורק אחרי בדיקת המערכת.

מבנה החבילה
  private/       -> לעלות אל /home/almascho/private   (מחוץ ל-public_html! אסור שיהיה נגיש מהדפדפן)
  public_html/   -> לעלות אל /home/almascho/public_html

שלבי התקנה (ב-cPanel -> File Manager)
1. העלי את alma-system.zip לתיקייה הראשית /home/almascho (לא ל-public_html) ולחצי Extract.
   אם נוצרה תיקייה alma-system – גרור/י את private ואת תוכן public_html למקום הנכון:
     /home/almascho/private
     /home/almascho/public_html/app   ,  /home/almascho/public_html/setup.php  ,  /home/almascho/public_html/.htaccess
2. פתחי את /home/almascho/private/config.php (להעתיק מ-config.sample.php) (Edit) ועדכני:
     db_pass   = סיסמת המשתמש almascho_app שיצרת
     setup_key = מחרוזת אקראית ארוכה (30+ תווים). אפשר להשתמש במחולל סיסמאות.
   (שם המסד והמשתמש כבר נכונים: almascho_school / almascho_app)
3. הרשאות: לתיקיות private/sessions ו-private/storage לקבוע 700 (קליק ימני -> Change Permissions).
4. בדפדפן: https://alma-school.co.il/setup.php
   מזינים את מפתח ההתקנה, שם, שם משתמש וסיסמה חזקה -> נוצרת משתמשת מנהלת.
   קובץ setup.php נמחק אוטומטית. ודאי ב-File Manager שהוא נעלם.
5. כניסה: https://alma-school.co.il/app/login.php
6. בתפריט "בדיקת מערכת" – כל השורות צריכות להיות ירוקות. אם משהו אדום, שלחי לי צילום מסך.

מה כלול בשלב הזה
- כניסה עם נעילה אחרי 5 ניסיונות כושלים, ניתוק אוטומטי אחרי 30 דקות
- תפקידים: מנהלת / מזכירות / גבייה (בלי ת"ז)
- רשימת תלמידות עם חיפוש וסינון (כיתה, עיר, סטטוס) וכרטיס תלמידה
- יומן פעולות (מי צפתה, מי שינתה)
- ניהול משתמשות

מה עוד לא כלול (שלבים הבאים)
- העלאת קבצים מוצפנת (ת"ז, טפסי גירושין)
- גבייה ושכר לימוד, מורשת גד, יבוא קובץ גבייה
- דף ההרשמה לציבור
- אימות דו-שלבי
- העברת נתוני התלמידות הקיימים
