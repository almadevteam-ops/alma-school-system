<?php
declare(strict_types=1);
// לוגיקה משותפת לשדות הנוספים בכרטיס תלמידה: דירה, הנחות, אחיות, אחים מהספח.
const ALLERGY_OPTS = ['חלב', 'בוטנים', 'אגוזים', 'אחר'];
const SCHOOL_MONTHS = ['ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר', 'ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט'];
const DISC_STATUS = ['approved' => 'מאושרת', 'pending' => 'ממתינה לאישור'];

function apt_tuition(): float { return (float)setting_get('apt_tuition', '300'); }
function sib_discount(): float { return (float)setting_get('sib_discount', '50'); }

/** מזהה גורם משלם "מורשת גד"; נוצר אם חסר. */
function gad_payer_id(): int {
  $pdo = db();
  $q = $pdo->prepare("SELECT id FROM payers WHERE name LIKE 'מורשת גד%' ORDER BY id LIMIT 1"); $q->execute();
  $id = $q->fetchColumn();
  if ($id) return (int)$id;
  $pdo->prepare('INSERT INTO payers (name, notes, created_at) VALUES (?,?,?)')->execute(['מורשת גד', 'משלמת עבור תלמידות הדירה', now()]);
  return (int)$pdo->lastInsertId();
}

/** סימון "בדירה": תיק גבייה של תלמידה בודדת עובר לתעריף הדירה ולתשלום ע"י מורשת גד. */
function apt_apply(int $sid): void {
  $pdo = db();
  $f = $pdo->prepare('SELECT family_id FROM students WHERE id = ?'); $f->execute([$sid]); $fid = (int)$f->fetchColumn();
  if (!$fid) return;
  $c = $pdo->prepare('SELECT COUNT(*) FROM students WHERE family_id = ? AND archived_at IS NULL'); $c->execute([$fid]);
  if ((int)$c->fetchColumn() !== 1) return;
  $pdo->prepare("UPDATE families SET monthly = ?, payer_kind = 'org', payer_id = ?, updated_at = ? WHERE id = ?")->execute([apt_tuition(), gad_payer_id(), now(), $fid]);
}

/** שכבת הלימוד לפי תאריך לידה (לאחים מהספח). */
function grade_from_dob(string $dob, int $year = 0): string {
  $iso = dob_iso($dob); if ($iso === '') return '';
  $year = $year ?: ((int)date('n') >= 9 ? (int)date('Y') + 1 : (int)date('Y'));   // שנת הלימודים הנוכחית (מסתיימת ביוני)
  $n = $year - 5 - (int)substr($iso, 0, 4);
  $gl = ['א','ב','ג','ד','ה','ו','ז','ח','ט','י','יא','יב'];
  return $n < 1 ? 'לפני כיתה א׳' : ($n > 12 ? 'בוגרת' : $gl[$n - 1]);
}

/** הנחת אחיות: מהאחות השנייה בתיק ואילך (הבוגרת ביותר ללא הנחה). מחזיר כמה הונחו. */
function sib_apply(string $by): int {
  $pdo = db(); $amt = sib_discount(); $n = 0;
  $fams = $pdo->query("SELECT family_id FROM students WHERE family_id IS NOT NULL AND archived_at IS NULL AND status <> 'left' GROUP BY family_id HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);
  $ord = ['יב' => 12, 'יא' => 11, 'י' => 10, 'ט' => 9, 'ח' => 8, 'ז' => 7];
  foreach ($fams as $fid) {
    $q = $pdo->prepare("SELECT id, grade, disc_on, is_apt FROM students WHERE family_id = ? AND archived_at IS NULL AND status <> 'left'"); $q->execute([$fid]);
    $rows = $q->fetchAll(); usort($rows, fn($a, $b) => ($ord[$b['grade']] ?? 0) <=> ($ord[$a['grade']] ?? 0) ?: $a['id'] <=> $b['id']);
    foreach (array_slice($rows, 1) as $r) if (!$r['disc_on'] && !$r['is_apt']) {
      $pdo->prepare("UPDATE students SET disc_on = 1, disc_amt = ?, disc_status = 'approved', disc_by = ?, disc_note = 'הנחת אחיות', updated_at = ? WHERE id = ?")->execute([$amt, $by, now(), $r['id']]);
      $n++;
    }
  }
  return $n;
}

/** אחיות במערכת: אותו תיק גבייה, או טלפון הורים זהה. */
function sisters_of(array $s): array {
  $w = []; $a = [(int)$s['id']];
  if (!empty($s['family_id'])) { $w[] = 'family_id = ?'; $a[] = (int)$s['family_id']; }
  foreach (['f_phone', 'm_phone'] as $k) if (trim((string)$s[$k]) !== '') { $w[] = '(f_phone = ? OR m_phone = ?)'; $a[] = $s[$k]; $a[] = $s[$k]; }
  if (!$w) return [];
  $q = db()->prepare('SELECT id, name, grade FROM students WHERE id <> ? AND archived_at IS NULL AND (' . implode(' OR ', $w) . ') ORDER BY name'); $q->execute($a);
  return $q->fetchAll();
}
