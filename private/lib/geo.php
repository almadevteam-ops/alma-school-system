<?php
declare(strict_types=1);
// ספריית ערים ורחובות (מקובץ הרחובות של data.gov.il): השלמה אוטומטית ובדיקת כתובת.
const GEO_MAX_BYTES = 12 * 1024 * 1024;

function geo_norm(string $s): string {
  $s = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', '', $s) ?? '';
  $s = str_replace(['״', '”', '“', '׳', '’', '‘', '`'], ['"', '"', '"', "'", "'", "'", "'"], $s);
  return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? ''));
}
function geo_ready(): bool {
  static $r = null; if ($r !== null) return $r;
  try { return $r = ((int)db()->query('SELECT COUNT(*) FROM geo_streets')->fetchColumn()) > 0; } catch (Throwable $t) { return $r = false; }
}
function geo_stats(): array {
  try { return [(int)db()->query('SELECT COUNT(*) FROM geo_cities')->fetchColumn(), (int)db()->query('SELECT COUNT(*) FROM geo_streets')->fetchColumn()]; } catch (Throwable $t) { return [0, 0]; }
}
function geo_cities(): array { return array_column(db()->query('SELECT name FROM geo_cities ORDER BY name')->fetchAll(), 'name'); }
function geo_city_id(string $city): ?int {
  $n = geo_norm($city); if ($n === '') return null;
  foreach (db()->query('SELECT id, name FROM geo_cities')->fetchAll() as $r) if (geo_norm((string)$r['name']) === $n) return (int)$r['id'];
  return null;
}
function geo_streets(int $cityId): array {
  $q = db()->prepare('SELECT name FROM geo_streets WHERE city_id = ? ORDER BY name'); $q->execute([$cityId]); return array_column($q->fetchAll(), 'name');
}
/** מפתח השוואה לשם רחוב: בלי פיסוק וקידומות (רחוב/רח/שד), ומילים ממוינות, כך ש"בן ציון ישראלי" = "ישראלי בן ציון". */
function geo_street_key(string $s): string {
  $n = geo_norm($s); $n = preg_replace('/["\'.,()\-]/u', ' ', $n) ?? $n;
  $w = array_values(array_filter(explode(' ', $n), fn($x) => $x !== ''));
  if ($w && in_array($w[0], ['רחוב', 'רח', 'שדרות', 'שד', 'שדרת'], true) && count($w) > 1) array_shift($w);
  sort($w); return implode(' ', $w);
}
/** שם רחוב רשמי מהספרייה שמתאים (בדיוק אחד) לטקסט חופשי. */
function geo_match_street(int $cityId, string $street): ?string {
  $k = geo_street_key($street); if ($k === '') return null; $hit = [];
  foreach (geo_streets($cityId) as $s) if (geo_street_key((string)$s) === $k) $hit[] = (string)$s;
  return count($hit) === 1 ? $hit[0] : null;
}
/** שם עיר רשמי שמתאים לטקסט חופשי: התאמה מלאה, או עיר אחת בלבד שהשם שלה מתחיל בטקסט ("תל אביב" → "תל אביב - יפו"). */
function geo_match_city(string $city): ?string {
  $n = geo_norm($city); if ($n === '') return null; $pre = [];
  foreach (geo_cities() as $c) { $cn = geo_norm((string)$c); if ($cn === $n) return (string)$c; if (str_starts_with($cn, $n . ' ')) $pre[] = (string)$c; }
  return count($pre) === 1 ? $pre[0] : null;
}
/** כתובות חריגות שמותר להקליד בשדה הרחוב בלי שיופיעו ברשימה (למשל "דירה בנשר"): לא נבדקות מול הספרייה ולא מסומנות כשגויות. */
function geo_is_exception(string $street): bool { return (bool)preg_match('/^\s*(דירה|פנימיה|פנימייה)(\s|$)/u', $street); }
const HOUSE_RE = '/^\d{1,4}\s?[א-תA-Za-z]?(?:\s?[\/\-]\s?\d{1,4})?$/u';
/** בדיקת עיר, רחוב ומספר בית מול הספרייה. מחזירה הודעת שגיאה או null. פעילה רק אחרי שהספרייה נטענה. */
function geo_check(string $city, string $street, string $house, bool $chkCity, bool $chkStreet, bool $chkHouse): ?string {
  if (!geo_ready()) { if ($chkHouse && $house !== '' && !preg_match(HOUSE_RE, $house)) return 'מספר בית לא תקין (למשל 12 או 12א).'; return null; }
  $cid = null;
  if (geo_is_exception($city)) return null;   // "דירה בנשר" בשדה העיר
  if ($chkCity && trim($city) !== '') { $cid = geo_city_id($city); if ($cid === null) return 'העיר "' . $city . '" לא ברשימה. נא לבחור עיר מהרשימה שנפתחת בהקלדה.'; }
  if (geo_is_exception($street)) return null;
  if ($chkStreet && trim($street) !== '') {
    if (trim($city) === '') return 'נא לבחור עיר לפני הרחוב.';
    $cid ??= geo_city_id($city);
    if ($cid !== null) {
      $n = geo_norm($street); $ok = false;
      foreach (geo_streets($cid) as $s) if (geo_norm((string)$s) === $n) { $ok = true; break; }
      if (!$ok) return 'הרחוב "' . $street . '" לא נמצא ב' . $city . '. נא לבחור רחוב מהרשימה.';
    }
  }
  if ($chkHouse && trim($street) !== '' && $house === '') return 'נא להוסיף מספר בית.';
  if ($chkHouse && $house !== '' && !preg_match(HOUSE_RE, $house)) return 'מספר בית לא תקין (למשל 12 או 12א).';
  return null;
}
/** מתאימה את העיר והרחוב של תלמידות קיימות לשמות הרשמיים בספרייה. מחזירה [עודכנו, [רשימת תלמידות שלא הותאמו]]. */
function geo_rematch_students(): array {
  if (!geo_ready()) return [0, []];
  $upd = 0; $bad = [];
  $up = db()->prepare('UPDATE students SET city = ?, addr = ? WHERE id = ?');
  foreach (db()->query("SELECT id, name, city, addr FROM students WHERE archived_at IS NULL AND (city <> '' OR addr <> '')")->fetchAll() as $r) {
    $city = (string)$r['city']; $addr = (string)$r['addr']; $why = [];
    if ($city !== '' && geo_is_exception($city)) continue;   // כתובת חריגה בשדה העיר: לא נוגעים ולא מסמנים
    if ($city !== '') { $m = geo_match_city($city); if ($m !== null) $city = $m; else $why[] = 'עיר "' . $city . '"'; }
    if ($addr !== '' && geo_is_exception($addr)) { /* כתובת חריגה: נשארת כמו שהיא */ }
    elseif ($addr !== '' && !$why) { $cid = geo_city_id($city); $m = $cid ? geo_match_street($cid, $addr) : null; if ($m !== null) $addr = $m; else $why[] = 'רחוב "' . $addr . '"'; }
    elseif ($addr !== '' && $why) $why[] = 'רחוב "' . $addr . '" (העיר לא זוהתה)';
    if ($city !== (string)$r['city'] || $addr !== (string)$r['addr']) { $up->execute([$city, $addr, $r['id']]); $upd++; }
    if ($why) $bad[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'why' => implode(', ', $why)];
  }
  return [$upd, $bad];
}
/** טוענת קובץ CSV של רחובות (שם ישוב + שם רחוב) ומחליפה את הספרייה. מחזירה [ערים, רחובות] או מחרוזת שגיאה. */
function geo_import(string $path) {
  @set_time_limit(300); @ini_set('memory_limit', '512M');
  $raw = (string)file_get_contents($path);
  if ($raw === '') return 'הקובץ ריק.';
  if (!mb_check_encoding($raw, 'UTF-8')) $raw = (string)@iconv('Windows-1255', 'UTF-8//IGNORE', $raw);
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
  $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); unset($raw); rewind($fh);
  $first = (string)fgets($fh); rewind($fh);
  $delim = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
  $head = fgetcsv($fh, 0, $delim, '"', '');
  if (!$head) return 'לא נמצאה שורת כותרות.';
  $ci = $si = $sti = null;
  foreach ($head as $i => $h) {
    $n = str_replace(['_', '-', ' '], '', geo_norm((string)$h));
    if (str_contains($n, 'status') || str_contains($n, 'סטטוס')) { $sti ??= $i; continue; }
    if (str_contains($n, 'סמל') || str_contains($n, 'קוד') || str_contains($n, 'code')) continue;
    if ($ci === null && (in_array($n, ['cityname', 'city'], true) || str_contains($n, 'ישוב') || str_contains($n, 'יישוב') || str_contains($n, 'עיר'))) $ci = $i;
    elseif ($si === null && (in_array($n, ['streetname', 'street'], true) || str_contains($n, 'רחוב'))) $si = $i;
  }
  if ($ci === null || $si === null) return 'לא זוהו עמודות "שם ישוב" ו"שם רחוב" בשורת הכותרות (' . implode(', ', array_map('trim', $head)) . ').';
  $pairs = [];
  while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
    if ($sti !== null) { $stt = geo_norm((string)($r[$sti] ?? '')); if ($stt !== '' && $stt !== 'official' && $stt !== 'רשמי') continue; }   // שמות נרדפים (synonym) לא נטענים
    $c = trim(preg_replace('/\s+/u', ' ', (string)($r[$ci] ?? '')) ?? ''); $s = trim(preg_replace('/\s+/u', ' ', (string)($r[$si] ?? '')) ?? '');
    if ($c === '' || $s === '' || mb_strlen($c) > 80 || mb_strlen($s) > 100) continue;
    $pairs[$c][$s] = true;
  }
  fclose($fh);
  if (!$pairs) return 'לא נמצאו שורות תקינות בקובץ.';
  $pdo = db(); $pdo->beginTransaction();
  try {
    $pdo->exec('DELETE FROM geo_streets'); $pdo->exec('DELETE FROM geo_cities');
    $ic = $pdo->prepare('INSERT INTO geo_cities (name) VALUES (?)');
    $nc = 0; $ns = 0;
    foreach ($pairs as $c => $ss) {
      $ic->execute([$c]); $cid = (int)$pdo->lastInsertId(); $nc++;
      foreach (array_chunk(array_keys($ss), 400) as $chunk) {
        $pdo->prepare('INSERT IGNORE INTO geo_streets (city_id, name) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?)')))
            ->execute(array_merge(...array_map(fn($x) => [$cid, (string)$x], $chunk)));
        $ns += count($chunk);
      }
    }
    $pdo->commit();
  } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
  return [$nc, $ns];
}
