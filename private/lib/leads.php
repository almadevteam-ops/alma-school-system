<?php
declare(strict_types=1);
// מאגר אחים ואחיות של תלמידות (נקרא מהספח של תעודת הזהות, או מוקלד ידנית): תלמידות פוטנציאליות לרישום.
const LEAD_STATUS = ['new' => 'חדש', 'contacted' => 'נוצר קשר', 'registered' => 'נרשמה', 'declined' => 'לא מעוניינת'];
const LEAD_GENDER = ['f' => 'נקבה', 'm' => 'זכר'];

/** שם בעברית בלבד (אותיות, רווח, גרש, מקף). */
function lead_name_clean(string $s): ?string {
  $s = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $s) ?? '';
  $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
  if ($s === '') return '';
  return preg_match('/^[\x{05D0}-\x{05EA}\x{05F3}\x{05F4}\'"\- ]{1,60}$/u', $s) ? $s : null;
}
/**
 * מנקה ומאמתת שורת אח/אחות. מחזירה [שורה, ''] או [null, הודעת שגיאה].
 * ת״ז חייבת להיות תקינה (ספרת ביקורת); שם ותאריך שלא נקראו נשמרים ריקים כשהמקור הוא קריאה אוטומטית.
 */
function lead_clean(array $r, string $src, array $exclude): array {
  $idn = preg_replace('/\D/', '', (string)($r['idn'] ?? '')) ?? '';
  if (strlen($idn) !== 9 || preg_match('/^0+$/', $idn) || !valid_il_id($idn)) return [null, 'מספר זהות לא תקין'];
  if (in_array($idn, $exclude, true)) return [null, 'זו הת״ז של התלמידה או של ההורה'];
  $ln = lead_name_clean((string)($r['ln'] ?? '')); $fn = lead_name_clean((string)($r['fn'] ?? ''));
  if ($ln === null || $fn === null) { if ($src === 'manual') return [null, 'שם בעברית בלבד']; $ln = $ln ?? ''; $fn = $fn ?? ''; }
  if ($src === 'manual' && $fn === '') return [null, 'חסר שם פרטי'];
  $dobIn = trim((string)($r['dob'] ?? '')); $dob = dob_iso($dobIn);
  if ($dob !== '' && ($dob > date('Y-m-d') || $dob < '1985-01-01')) $dob = '';
  if ($src === 'manual' && $dobIn !== '' && $dob === '') return [null, 'תאריך לידה לא תקין'];
  $g = (string)($r['g'] ?? ''); if (!isset(LEAD_GENDER[$g])) $g = '';
  return [['idn' => $idn, 'ln' => $ln, 'fn' => $fn, 'dob' => $dob, 'g' => $g], ''];
}
/** שומרת שורות למאגר ומקשרת לתלמידה. מחזירה ['new' => חדשים, 'linked' => קיימים שקושרו, 'bad' => [[ת״ז, סיבה]]]. */
function leads_store(int $studentId, array $rows, string $src, string $who): array {
  $pdo = db(); $out = ['new' => 0, 'linked' => 0, 'bad' => []];
  $st = $pdo->prepare('SELECT idn, f_id, m_id FROM students WHERE id = ?'); $st->execute([$studentId]); $s = $st->fetch();
  if (!$s) return $out;
  $exclude = array_values(array_filter(array_map(fn($v) => preg_replace('/\D/', '', (string)$v), [$s['idn'], $s['f_id'], $s['m_id']]), fn($v) => $v !== ''));
  $seen = [];
  foreach (array_slice($rows, 0, 20) as $raw) {
    if (!is_array($raw)) continue;
    [$r, $err] = lead_clean($raw, $src, $exclude);
    if (!$r) { $out['bad'][] = [preg_replace('/\D/', '', (string)($raw['idn'] ?? '')), $err]; continue; }
    if (isset($seen[$r['idn']])) continue; $seen[$r['idn']] = 1;
    $ex = $pdo->prepare('SELECT id FROM sibling_leads WHERE idn = ?'); $ex->execute([$r['idn']]); $lid = $ex->fetchColumn();
    $isNew = ($lid === false);
    if ($isNew) {
      $pdo->prepare('INSERT INTO sibling_leads (idn, last_name, first_name, dob, gender, src, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$r['idn'], $r['ln'], $r['fn'], $r['dob'] ?: null, $r['g'], $src === 'manual' ? 'manual' : 'ocr', mb_substr($who, 0, 100), now(), now()]);
      $lid = (int)$pdo->lastInsertId(); $out['new']++;
    } else {
      $pdo->prepare("UPDATE sibling_leads SET last_name = IF(last_name = '', ?, last_name), first_name = IF(first_name = '', ?, first_name), dob = IFNULL(dob, ?), gender = IF(gender = '', ?, gender), updated_at = ? WHERE id = ?")
        ->execute([$r['ln'], $r['fn'], $r['dob'] ?: null, $r['g'], now(), $lid]);
    }
    $ln = $pdo->prepare('INSERT IGNORE INTO sibling_lead_links (lead_id, student_id, created_at) VALUES (?,?,?)'); $ln->execute([$lid, $studentId, now()]);
    if ($ln->rowCount() && !$isNew) $out['linked']++;
  }
  if ($out['new'] || $out['linked']) audit('leads_add', 'student', (string)$studentId, $src . ': ' . $out['new'] . ' חדשים, ' . $out['linked'] . ' קיימים');
  return $out;
}
/** הודעה קצרה למשתמשת על תוצאת שמירה. */
function leads_msg(array $o): string {
  $t = [];
  if ($o['new']) $t[] = $o['new'] . ' אחים/אחיות נוספו למאגר';
  if ($o['linked']) $t[] = $o['linked'] . ' קיימים במאגר קושרו לתלמידה';
  if (!$t && !$o['bad']) $t[] = 'לא נוספו אחים';
  if ($o['bad']) $t[] = count($o['bad']) . ' שורות לא נשמרו (' . implode('; ', array_unique(array_column($o['bad'], 1))) . ')';
  return implode('. ', $t) . '.';
}
/** אותיות עבריות לשנת לימודים (למשל 2026 → תשפ״ז). */
function heb_school_year(int $startYear): string {
  $n = $startYear + 3761 - 5000; $s = '';
  foreach ([400 => 'ת', 300 => 'ש', 200 => 'ר', 100 => 'ק'] as $v => $l) while ($n >= $v) { $s .= $l; $n -= $v; }
  if ($n === 15) { $s .= 'טו'; $n = 0; } elseif ($n === 16) { $s .= 'טז'; $n = 0; }
  foreach ([90 => 'צ', 80 => 'פ', 70 => 'ע', 60 => 'ס', 50 => 'נ', 40 => 'מ', 30 => 'ל', 20 => 'כ', 10 => 'י'] as $v => $l) if ($n >= $v) { $s .= $l; $n -= $v; }
  if ($n > 0) $s .= ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'][$n];
  return mb_strlen($s) > 1 ? mb_substr($s, 0, -1) . '״' . mb_substr($s, -1) : $s;
}
/** שנת הלימודים (שנה לועזית שבה היא מתחילה) שבה ילד שנולד ב-$dob נכנס לכיתה ז׳. */
function lead_entry_year(string $dobIso): ?int { return preg_match('/^(\d{4})-/', $dobIso, $m) ? (int)$m[1] + 12 : null; }

/** הכיתה המשוערת היום לפי שנת הלידה (ז = נולדה 12 שנים לפני תחילת שנת הלימודים). */
function lead_grade_now(?string $dobIso): string {
  if (!$dobIso || !preg_match('/^(\d{4})-/', $dobIso, $m)) return '';
  $y = (int)date('Y') - ((int)date('n') < 9 ? 1 : 0);   // שנת הלימודים הנוכחית (תחילתה בספטמבר)
  $g = $y - ((int)$m[1] + 12) + 7;
  $names = [1 => 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט', 'י', 'יא', 'יב'];
  if ($g < 1) return $g >= -3 ? 'גן / לפני כיתה א׳' : 'עדיין לא בגיל בית ספר';
  if ($g > 12) return 'סיימה תיכון';
  return 'כיתה ' . $names[$g];
}
