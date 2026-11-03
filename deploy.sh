#!/bin/bash
# מופעל ע"י cPanel בכל "Deploy HEAD Commit". לא נוגע ב-config.php, בסשנים ובקבצים המאוחסנים.
set -e
H="${DEPLOY_HOME:-$HOME}"
PUB="$H/public_html"
PRIV="$H/private"
mkdir -p "$PUB" "$PRIV/lib" "$PRIV/cron" "$PRIV/migrations" "$PRIV/sessions" "$PRIV/storage" "$PRIV/backups"
cp -R public_html/. "$PUB/"
cp -R private/lib/. "$PRIV/lib/"
cp -R private/cron/. "$PRIV/cron/"
cp -R private/migrations/. "$PRIV/migrations/"
cp private/schema.sql private/config.sample.php "$PRIV/"
[ -f "$PRIV/.htaccess" ] || cp private/.htaccess "$PRIV/.htaccess"
[ -f "$PRIV/sessions/.htaccess" ] || cp private/sessions/.htaccess "$PRIV/sessions/.htaccess"
[ -f "$PRIV/storage/.htaccess" ] || cp private/storage/.htaccess "$PRIV/storage/.htaccess"
[ -f "$PRIV/backups/.htaccess" ] || echo "Require all denied" > "$PRIV/backups/.htaccess"
chmod 700 "$PRIV/sessions" "$PRIV/storage" "$PRIV/backups"
# אחרי ההתקנה הראשונה – לא להחזיר את setup.php לשרת
if [ -f "$PRIV/installed.flag" ]; then rm -f "$PUB/setup.php"; fi
echo "$(git rev-parse --short HEAD 2>/dev/null) $(date '+%Y-%m-%d %H:%M')" > "$PRIV/VERSION"
echo "deployed to $PUB"
