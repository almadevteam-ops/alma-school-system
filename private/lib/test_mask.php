<?php
declare(strict_types=1);
// טשטוש נתונים לסביבת הבדיקות בלבד. רץ על עותק של הנתונים, מחליף פרטים מזהים בפרטים בדויים ועקביים, ומוחק טקסט חופשי וקבצים.
// ההחלפות נגזרות ממספר השורה, ולכן הרצה חוזרת נותנת את אותה תוצאה. הקובץ מסרב לרוץ מחוץ לסביבת בדיקות.
const TM_GIRLS = ['שרה','רבקה','רחל','לאה','מרים','חנה','אסתר','דבורה','יעל','תמר','נעמי','רות','אביגיל','הדסה','טליה','שירה','מיכל','ציפורה','חיה','פנינה','אורטל','שולמית','ליאת','נורית','אפרת','גילה','הילה','מורן','עדי','רינת','שני','ענבל','אושרה','בת-שבע','יהודית','מלכה','צופיה','ברכה','אילנה','דנה','נועה','מאיה','לירון','ספיר','אור','שיר','יובל','רוני','קרן','סיון','הודיה','אילת','מיטל','גאולה','זהבה','כרמית','עטרה','שושנה','פרחיה'];
const TM_MEN = ['משה','אהרן','יוסף','דוד','שלמה','יעקב','יצחק','אברהם','מרדכי','אליהו','שמעון','ראובן','יהודה','בנימין','נתן','חיים','צבי','אריה','מאיר','עמרם','אלעזר','יונתן','שמואל','גדעון','אורי','רפאל','אבי','אילן','דניאל','עומר','נועם','איתן','ליאור','רועי','יניב','אלון','גלעד','עידן','שחר','תומר'];
const TM_SUR = ['אברהמי','אדרי','אזולאי','אילוז','אלקיים','אמסלם','אסולין','בוזגלו','בן חמו','בן שושן','ברדה','גבאי','גולן','גרוס','דהן','הרוש','וקנין','זוהר','חדד','חיון','טובי','יאיר','כהנא','לביא','לוי','מזרחי','מלכה','נחמיאס','סבג','סויסה','עמר','פרץ','צדוק','קורן','רוזנברג','שושן','שמעוני','תורגמן','אדלר','בלום','גולדשטיין','דויטש','הורוביץ','וייס','זילברמן','כץ','לנדאו','מילר','נחשון','סגל','פרידמן','קליין','רבינוביץ','שטרן','שפירא','אלמוג','ביטון','דיין','חזן','טייב','מימון','נפתלי','סמואל','עטיה','פינטו','צרפתי','קדוש','רחמים','שרעבי','תמם','אוחנה','בנימיני','גואטה','דוידוב','חמו','ירוש','כרמלי','לוגסי','מרציאנו','סרוסי'];
function tm_pick(array $l, int $seed, string $salt): string { return $l[(int)(crc32($salt . '|' . $seed) % count($l))]; }
function tm_id9(int $n): string { $b = '99' . sprintf('%06d', $n); $s = 0; foreach (str_split($b) as $i => $d) { $x = (int)$d * ($i % 2 ? 2 : 1); $s += $x > 9 ? $x - 9 : $x; } return $b . ((10 - $s % 10) % 10); }
function tm_phone(int $n): string { return '05' . [0, 2, 3, 4, 5, 8][$n % 6] . sprintf('%03d', intdiv($n, 10000)) . sprintf('%04d', $n % 10000); }
function tm_guard(): ?string {
  if (!is_test_env()) return 'הכלי הזה רץ רק בסביבת הבדיקות.';
  if (!preg_match('/test/i', (string)cfg('db_name', ''))) return 'שם מסד הנתונים אינו מסד בדיקות (חייב להכיל test). מסרב לרוץ.';
  return null;
}
function tm_try(string $sql): void { try { db()->exec($sql); } catch (Throwable $t) {} }
/** מטשטש את כל הנתונים. מחזיר דוח: [תיאור => מספר]. */
function tm_run(): array {
  if ($e = tm_guard()) throw new RuntimeException($e);
  $pdo = db(); $rep = []; $nPh = 0; $nId = 0; $nMl = 0;
  $ph = function (string $v) use (&$nPh): string { if (trim($v) === '') return ''; if ($nPh > 69999) throw new RuntimeException('יותר מדי טלפונים.'); return tm_phone(++$nPh); };
  $idf = function (string $v) use (&$nId): string { return trim($v) === '' ? '' : tm_id9(++$nId); };
  $ml = function (string $v) use (&$nMl): string { return trim($v) === '' ? '' : 'user' . (++$nMl) . '@example.com'; };
  $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
  // ---- משפחות ----
  $up = $pdo->prepare("UPDATE families SET name = ?, discount_note = '', notes = NULL WHERE id = ?"); $n = 0;
  foreach ($pdo->query('SELECT id FROM families')->fetchAll() as $r) { $up->execute([tm_pick(TM_SUR, (int)$r['id'], 'fam'), $r['id']]); $n++; }
  $rep['משפחות'] = $n;
  // ---- תלמידות ----
  $up = $pdo->prepare("UPDATE students SET name=?, idn=?, f_id=?, m_id=?, phone=?, home_phone=?, f_phone=?, m_phone=?, email=?, f_name=?, m_name=?, addr=?, house_no=?, addr_orig='', allergy_other='', vaada_note='', disc_by='', disc_note='', disc_link='', old_ref='', reg_ip='', doctor='', archived_by=IF(archived_by='','','מערכת') WHERE id=?"); $n = 0;
  foreach ($pdo->query('SELECT id, family_id, idn, f_id, m_id, phone, home_phone, f_phone, m_phone, email, f_name, m_name, addr, house_no FROM students ORDER BY id')->fetchAll() as $r) {
    $id = (int)$r['id']; $fam = $r['family_id'] !== null ? (int)$r['family_id'] : -$id; $sur = tm_pick(TM_SUR, $fam, 'fam');
    $addr = (string)$r['addr']; $hn = (string)$r['house_no'];
    if ($hn !== '') $hn = (string)(1 + crc32("hn|$id") % 120);
    if ($addr !== '' && preg_match('/\d/', $addr)) $addr = trim((string)preg_replace('/\s*\d+.*$/u', '', $addr)) . ' ' . ($hn !== '' ? $hn : (1 + crc32("ad|$id") % 120));
    $up->execute([$sur . ' ' . tm_pick(TM_GIRLS, $id, 'girl'), $idf((string)$r['idn']), $idf((string)$r['f_id']), $idf((string)$r['m_id']), $ph((string)$r['phone']), $ph((string)$r['home_phone']), $ph((string)$r['f_phone']), $ph((string)$r['m_phone']), $ml((string)$r['email']),
      trim((string)$r['f_name']) === '' ? '' : tm_pick(TM_MEN, $fam, 'dad'), trim((string)$r['m_name']) === '' ? '' : tm_pick(TM_GIRLS, $fam, 'mom'), $addr, $hn, $id]); $n++;
  }
  $rep['תלמידות'] = $n;
  try { $up = $pdo->prepare('UPDATE student_sibs SET name=?, idn=? WHERE id=?'); $n = 0;
    foreach ($pdo->query('SELECT id, name, idn FROM student_sibs')->fetchAll() as $r) { $f = tm_pick(TM_GIRLS, (int)$r['id'] + 7000, 'sib'); $up->execute([str_contains(trim((string)$r['name']), ' ') ? tm_pick(TM_SUR, (int)$r['id'], 'sibs') . ' ' . $f : $f, $idf((string)$r['idn']), $r['id']]); $n++; }
    $rep['אחיות'] = $n; } catch (Throwable $t) {}
  // ---- חיובים ותשלומים ----
  tm_try("UPDATE charges SET disc_by='', disc_note=''"); tm_try("UPDATE payments SET ref='', note=''");
  // ---- תקציב: ספקים ושמות ----
  $names = []; foreach ([['budget_tx', 'name'], ['budget_payables', 'supplier'], ['budget_supplier_alias', 'alias'], ['budget_supplier_alias', 'supplier'], ['budget_tomb', 'name']] as [$t, $c]) {
    try { foreach ($pdo->query("SELECT DISTINCT `$c` v FROM `$t`")->fetchAll(PDO::FETCH_COLUMN) as $v) if ((string)$v !== '') $names[(string)$v] = 1; } catch (Throwable $x) {} }
  $max = 0; foreach (array_keys($names) as $v) if (preg_match('/^ספק (\d+)$/u', (string)$v, $m)) $max = max($max, (int)$m[1]);
  $todo = array_values(array_filter(array_map('strval', array_keys($names)), fn($v) => !preg_match('/^ספק \d+$/u', $v))); sort($todo); $map = [];
  foreach ($todo as $v) $map[$v] = 'ספק ' . sprintf('%03d', ++$max);
  foreach ([['budget_tx', 'name'], ['budget_payables', 'supplier'], ['budget_supplier_alias', 'alias'], ['budget_supplier_alias', 'supplier'], ['budget_tomb', 'name']] as [$t, $c]) {
    try { $u = $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = BINARY ?"); foreach ($map as $old => $new) $u->execute([$new, $old]); } catch (Throwable $x) {} }
  $rep['שמות ספקים/מקבלים שהוחלפו'] = count($map);
  tm_try("UPDATE budget_tx SET descr='', ref='', created_by='מערכת'"); tm_try("UPDATE budget_tomb SET ref=''"); tm_try("UPDATE budget_supplier_alias SET created_by='מערכת'");
  try { $u = $pdo->prepare("UPDATE budget_payables SET biz_id=?, branch=?, account=?, ref='', descr='', created_by='מערכת' WHERE id=?"); $n = 0;
    foreach ($pdo->query('SELECT id, biz_id, branch, account FROM budget_payables')->fetchAll() as $r) { $u->execute([trim((string)$r['biz_id']) === '' ? '' : sprintf('%09d', 500000000 + $r['id']), trim((string)$r['branch']) === '' ? '' : '600', trim((string)$r['account']) === '' ? '' : sprintf('%07d', 1000000 + $r['id']), $r['id']]); $n++; }
    $rep['חשבוניות לתשלום'] = $n; } catch (Throwable $x) {}
  // ---- מחיקת טקסט חופשי, קבצים ויומנים ----
  $del = 0; foreach (['student_files', 'note_files', 'doc_history', 'doc_jobs', 'doc_links', 'mailings', 'mailing_rcpt', 'reminders', 'notify_recipients', 'budget_docs', 'budget_masav', 'budget_pay_log', 'income_pending', 'sibling_leads', 'recovery_codes', 'login_attempts', 'backup_log'] as $t) {
    try { $del += (int)$pdo->exec("DELETE FROM `$t`"); } catch (Throwable $x) {} }
  $rep['שורות שנמחקו (הערות, קבצים, מסמכים, דיוור, תזכורות, יומנים)'] = $del;
  try { $pdo->exec("DELETE FROM app_settings WHERE k REGEXP 'gd_|bdrive|smtp|mail|pass|secret|token|key|notify|backup|sskey'"); } catch (Throwable $x) {}
  $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
  setting_set('test_masked_at', now(), 'system'); audit('test_mask', 'system', '', json_encode($rep, JSON_UNESCAPED_UNICODE));
  return $rep;
}
/** בדיקת שארית: מחזיר [תיאור, מספר חריגים, תקין?] לכל בדיקה. */
function tm_verify(): array {
  $pdo = db(); $o = []; $one = fn(string $s) => (int)$pdo->query($s)->fetchColumn();
  $fake = "'^05[0-9]000[0-9]{4}$'"; $set = implode(',', array_map(fn($x) => $pdo->quote($x), array_merge(TM_SUR, TM_GIRLS)));
  $o[] = ['טלפונים שלא בדויים (תלמידות)', $one("SELECT COUNT(*) FROM students WHERE (phone<>'' AND phone NOT REGEXP $fake) OR (home_phone<>'' AND home_phone NOT REGEXP $fake) OR (f_phone<>'' AND f_phone NOT REGEXP $fake) OR (m_phone<>'' AND m_phone NOT REGEXP $fake)")];
  $o[] = ['מיילים שלא בדומיין הבדוי', $one("SELECT COUNT(*) FROM students WHERE email<>'' AND email NOT LIKE '%@example.com'")];
  $o[] = ['ת"ז שלא בדויות', $one("SELECT COUNT(*) FROM students WHERE (idn<>'' AND idn NOT REGEXP '^99[0-9]{7}$') OR (f_id<>'' AND f_id NOT REGEXP '^99[0-9]{7}$') OR (m_id<>'' AND m_id NOT REGEXP '^99[0-9]{7}$')")];
  $names = 0; foreach ($pdo->query('SELECT name FROM students')->fetchAll(PDO::FETCH_COLUMN) as $nm) { $set2 = array_merge(TM_SUR, TM_GIRLS); foreach (preg_split('/\s+/u', trim((string)$nm)) ?: [] as $tk) if ($tk !== '' && !in_array($tk, $set2, true)) { $names++; break; } }
  $o[] = ['שמות תלמידות שאינם מהרשימה הבדויה', $names];
  $o[] = ['טקסט חופשי שנשאר בתלמידות (בלי הערות הכרטיס)', $one("SELECT COUNT(*) FROM students WHERE vaada_note<>'' OR allergy_other<>'' OR doctor<>'' OR disc_note<>'' OR addr_orig<>''")];
  $o[] = ['הערות / קבצים / מסמכים שנשארו', $one("SELECT (SELECT COUNT(*) FROM student_files)+(SELECT COUNT(*) FROM doc_history)+(SELECT COUNT(*) FROM mailings)")];
  $o[] = ['שמות בתנועות תקציב שאינם "ספק NNN"', $one("SELECT COUNT(*) FROM budget_tx WHERE name NOT REGEXP '^ספק [0-9]+\$'")];
  $o[] = ['משתמשים (חייבים להיות רק משתמשי הבדיקה)', $one('SELECT COUNT(*) FROM users')];
  return $o;
}
