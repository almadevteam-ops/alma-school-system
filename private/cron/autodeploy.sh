#!/bin/bash
# פריסה אוטומטית: כל כמה דקות בודק אם נדחף commit חדש ל-GitHub, ואם כן מושך ומריץ את deploy.sh (בדיוק מה ש-cPanel עושה ב"Update from Remote" + "Deploy HEAD Commit").
# cPanel ← Cron Jobs, כל 5 דקות (Minute: */5, שאר השדות *), Command:
#   /bin/bash /home/almascho/repositories/alma-school-system/private/cron/autodeploy.sh
# לא מריץ מיגרציות מסד נתונים: אחרי גרסה שמוסיפה מיגרציה "בדיקת מערכת" תציג שיש עדכון ממתין, והמנהלת לוחצת "עדכון מסד נתונים".
# משתני סביבה (לא חובה): ALMA_REPO (נתיב הקלון), ALMA_BRANCH (ברירת מחדל main), DEPLOY_HOME (ברירת מחדל HOME).
main() {
  local REPO H PRIV LOG BRANCH
  REPO="${ALMA_REPO:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"; BRANCH="${ALMA_BRANCH:-main}"
  H="${DEPLOY_HOME:-$HOME}"; PRIV="$H/private"; LOG="$H/autodeploy.log"
  mkdir -p "$PRIV"
  exec 9>"$H/.autodeploy.lock"; flock -n 9 || return 0          # ריצה אחת בכל רגע
  [ -f "$LOG" ] && [ "$(stat -c %s "$LOG" 2>/dev/null || echo 0)" -gt 1048576 ] && tail -n 300 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
  fail() { echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | tee -a "$LOG" > "$PRIV/deploy_error.txt"; }
  cd "$REPO" || { fail "תיקיית המאגר לא נמצאה: $REPO"; return 1; }
  git fetch --quiet origin "$BRANCH" >>"$LOG" 2>&1 || { fail "git fetch נכשל (רשת או הרשאות). פרטים ב-autodeploy.log"; return 1; }
  local FULL DONE; FULL="$(git rev-parse FETCH_HEAD)"; DONE="$(cat "$PRIV/autodeploy_done" 2>/dev/null)"
  [ "$FULL" = "$DONE" ] && return 0                                           # כבר נפרס, אין חדש (פריסה שנכשלה תנסה שוב בריצה הבאה)
  local OLD NEW; OLD="$(git rev-parse --short HEAD)"; NEW="$(git rev-parse --short FETCH_HEAD)"
  echo "$(date '+%Y-%m-%d %H:%M:%S') פריסה: $OLD → $NEW" >>"$LOG"
  [ "$(git rev-parse HEAD)" = "$FULL" ] || git merge --ff-only FETCH_HEAD >>"$LOG" 2>&1 || { fail "משיכה נכשלה: יש שינויים מקומיים בשרת או היסטוריה שונה ($OLD → $NEW). יש לפרוס ידנית דרך cPanel"; return 1; }
  DEPLOY_HOME="$H" /bin/bash deploy.sh >>"$LOG" 2>&1 || { fail "deploy.sh נכשל ($NEW). פרטים ב-autodeploy.log"; return 1; }
  rm -f "$PRIV/deploy_error.txt"; echo "$FULL" > "$PRIV/autodeploy_done"
  echo "$(date '+%Y-%m-%d %H:%M:%S') $NEW $(git log -1 --format=%s)" > "$PRIV/last_autodeploy.txt"
  echo "$(date '+%Y-%m-%d %H:%M:%S') הסתיים בהצלחה" >>"$LOG"
}
main "$@"; exit $?
