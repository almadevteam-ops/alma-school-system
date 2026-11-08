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
/** מפצלת כתובת ל-[רחוב, מספר בית]. "הרצל 12א" → ['הרצל','12א']; בלי מספר → [כתובת,'']. */
function geo_split_addr(string $addr): array {
  $a = trim($addr);
  if (preg_match('/^(.*?)[\s,]+(\d+\s?[א-תA-Za-z]?(?:\s?[\/\-]\s?\d+)?)$/u', $a, $m) && trim($m[1]) !== '') return [trim($m[1], " ,"), trim($m[2])];
  return [$a, ''];
}
/** בדיקת עיר וכתובת מול הספרייה. מחזירה הודעת שגיאה או null. פעילה רק אחרי שהספרייה נטענה. */
function geo_check(string $city, string $addr, bool $checkCity, bool $checkAddr): ?string {
  if (!geo_ready()) return null;
  $cid = null;
  if ($checkCity && trim($city) !== '') { $cid = geo_city_id($city); if ($cid === null) return 'העיר "' . $city . '" לא ברשימה. נא לבחור עיר מהרשימה שנפתחת בהקלדה.'; }
  if ($checkAddr && trim($addr) !== '') {
    if (trim($city) === '') return 'נא לבחור עיר לפני הכתובת.';
    $cid ??= geo_city_id($city);
    if ($cid === null) return null;   // עיר ישנה שלא ברשימה: לא חוסמים
    [$street, $num] = geo_split_addr($addr);
    $ok = false; $n = geo_norm($street);
    foreach (geo_streets($cid) as $s) if (geo_norm((string)$s) === $n) { $ok = true; break; }
    if (!$ok) return 'הרחוב "' . $street . '" לא נמצא ב' . $city . '. נא לבחור רחוב מהרשימה.';
    if ($num === '') return 'נא להוסיף מספר בית אחרי שם הרחוב.';
  }
  return null;
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
