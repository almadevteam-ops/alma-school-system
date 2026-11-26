#!/bin/bash
# מופעל ע"י cPanel בכל "Deploy HEAD Commit". לא נוגע ב-config.php, בסשנים ובקבצים המאוחסנים.
set -e
# סביבת בדיקות: אם תיקיית המאגר ב-cPanel (Git Version Control) שמה מכיל "test", הפריסה נכנסת לתיקייה נפרדת לגמרי
case "$(basename "$PWD")" in *test*) DEPLOY_HOME="${DEPLOY_HOME:-$HOME/test_env}";; esac
H="${DEPLOY_HOME:-$HOME}"
PUB="$H/public_html"
PRIV="$H/private"
mkdir -p "$PUB" "$PRIV/lib" "$PRIV/fonts" "$PRIV/docs" "$PRIV/cron" "$PRIV/migrations" "$PRIV/sessions" "$PRIV/storage" "$PRIV/backups"
cp -R public_html/. "$PUB/"
cp -R private/lib/. "$PRIV/lib/"
cp -R private/cron/. "$PRIV/cron/"
cp -R private/migrations/. "$PRIV/migrations/"
cp -R private/fonts/. "$PRIV/fonts/"
cp -R private/docs/. "$PRIV/docs/"
cp private/schema.sql private/config.sample.php "$PRIV/"
[ -f RELEASE_NOTES.md ] && cp RELEASE_NOTES.md "$PRIV/RELEASE_NOTES.md"
[ -f "$PRIV/.htaccess" ] || cp private/.htaccess "$PRIV/.htaccess"
[ -f "$PRIV/sessions/.htaccess" ] || cp private/sessions/.htaccess "$PRIV/sessions/.htaccess"
[ -f "$PRIV/storage/.htaccess" ] || cp private/storage/.htaccess "$PRIV/storage/.htaccess"
[ -f "$PRIV/backups/.htaccess" ] || echo "Require all denied" > "$PRIV/backups/.htaccess"
chmod 700 "$PRIV/sessions" "$PRIV/storage" "$PRIV/backups"
# אחרי ההתקנה הראשונה – לא להחזיר את setup.php לשרת
if [ -f "$PRIV/installed.flag" ]; then rm -f "$PUB/setup.php"; fi
# גרסה: MAJOR.MINOR מהקובץ VERSION_BASE, ומספר הבנייה = מספר הקומיטים (עולה אוטומטית בכל עדכון)
BASE="$(tr -d ' \r\n' < VERSION_BASE 2>/dev/null || echo 1.0)"
COUNT="$(git rev-list --count HEAD 2>/dev/null || echo 0)"
FLOOR="$(tr -d ' \r\n' < VERSION_FLOOR 2>/dev/null || echo 0)"   # גרסה 1.1.1 = הקומיט ה-100 (ספירה אמיתית); מספר הבנייה מתחיל מחדש אחרי כל העלאת MINOR
BUILD=$((COUNT - FLOOR))
echo "$BASE.$BUILD ($(git rev-parse --short HEAD 2>/dev/null), $(date '+%Y-%m-%d %H:%M'))" > "$PRIV/VERSION"
echo "deployed to $PUB"
