<?php
declare(strict_types=1);
// תקציב פנימי של בית הספר – לוגיקה משותפת (הדפים: budget.php, budget_tx.php, budget_cats.php). מנהלת מערכת בלבד.

const BUDGET_UNITS_DEFAULT = ['עלמא', 'חטיבת בנים', 'מדרשת עילא'];
const BUDGET_MAX_IMPORT = 3000;

function budget_units(): array {
  $v = array_values(array_unique(array_filter(array_map('trim', explode("\n", setting_get('budget_units', ''))), fn($x) => $x !== '')));
  return $v ?: BUDGET_UNITS_DEFAULT;
}
function budget_cats(): array { return array_column(db()->query('SELECT name FROM budget_cats ORDER BY name')->fetchAll(), 'name'); }

/** שנת לימודים לפי שנת ההתחלה הלועזית (1.9.S עד 31.8.S+1) – שם עברי, למשל 2026 => תשפ"ז. */
function budget_year_label(int $s): string {
  $h = ($s + 3761) % 1000; $L = [400 => 'ת', 300 => 'ש', 200 => 'ר', 100 => 'ק', 90 => 'צ', 80 => 'פ', 70 => 'ע', 60 => 'ס', 50 => 'נ', 40 => 'מ', 30 => 'ל', 20 => 'כ', 10 => 'י', 9 => 'ט', 8 => 'ח', 7 => 'ז', 6 => 'ו', 5 => 'ה', 4 => 'ד', 3 => 'ג', 2 => 'ב', 1 => 'א'];
  $out = '';
  foreach ([400, 300, 200, 100] as $v) while ($h >= $v) { $out .= $L[$v]; $h -= $v; }
  if ($h === 15) { $out .= 'טו'; $h = 0; } elseif ($h === 16) { $out .= 'טז'; $h = 0; }
  foreach ([90, 80, 70, 60, 50, 40, 30, 20, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1] as $v) if ($h >= $v) { $out .= $L[$v]; $h -= $v; }
  $n = mb_strlen($out); return $n > 1 ? mb_substr($out, 0, $n - 1) . '"' . mb_substr($out, -1) : $out;
}
function budget_year_of(string $iso): int { $y = (int)substr($iso, 0, 4); return (int)substr($iso, 5, 2) >= 9 ? $y : $y - 1; }
function budget_year_current(): int { return budget_year_of(date('Y-m-d')); }
/** רשימת שנות לימודים להצגה: כל שנה שיש בה תנועות, והשנה הנוכחית. */
function budget_years(): array {
  $ys = [budget_year_current()];
  foreach (db()->query('SELECT DISTINCT tx_date FROM budget_tx')->fetchAll() as $r) $ys[] = budget_year_of((string)$r['tx_date']);
  $ys = array_values(array_unique($ys)); rsort($ys); return $ys;
}
/** טווח תאריכים לשנה (S), או null לכל התקופה. */
function budget_range(?int $s): ?array { return $s === null ? null : [sprintf('%04d-09-01', $s), sprintf('%04d-08-31', $s + 1)]; }

function budget_money($n): string { return '₪' . number_format((float)$n, 2, '.', ',') . ''; }
function budget_money0($n): string { $f = (float)$n; return '₪' . number_format($f, abs($f - round($f)) < 0.005 ? 0 : 2, '.', ','); }

/** שיוך אוטומטי לקטגוריה לפי מילות מפתח (הראשון שמתאים). */
function budget_auto(string $name, string $descr, ?array &$rules = null): string {
  if ($rules === null) $rules = db()->query('SELECT kw, category FROM budget_rules ORDER BY ord, id')->fetchAll();
  $t = $name . ' ' . $descr;
  foreach ($rules as $r) if ($r['kw'] !== '' && mb_strpos($t, $r['kw']) !== false) return (string)$r['category'];
  return '';
}
function budget_amount(string $v): ?float {
  $v = trim(str_replace(['₪', ',', ' ', "\u{A0}"], '', $v));
  if ($v === '') return null;
  $neg = false; if (preg_match('/^\((.*)\)$/', $v, $m)) { $neg = true; $v = $m[1]; }
  if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) return null;
  $f = (float)$v; if ($neg) $f = -$f;
  return abs($f) > 9999999999 ? null : round($f, 2);
}
function budget_date(string $v): string {
  $v = trim($v); if ($v === '') return '';
  if (is_numeric($v) && (float)$v > 30000 && (float)$v < 80000) return gmdate('Y-m-d', (int)(((float)$v - 25569) * 86400));
  if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})~', $v, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
  elseif (preg_match('~^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4}|\d{2})~', $v, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; if ($y < 100) $y += 2000; }
  else return '';
  return checkdate($mo, $d, $y) && $y >= 2000 && $y <= 2100 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
}
function budget_unit_for(string $unit, string $cat): string {
  $units = budget_units(); if ($unit !== '' && in_array($unit, $units, true)) return $unit;
  if ($cat !== '' && in_array($cat, $units, true)) return $cat;
  return $units[0];
}
function budget_cat_ensure(string $c): void { if ($c !== '') db()->prepare('INSERT IGNORE INTO budget_cats (name) VALUES (?)')->execute([mb_substr($c, 0, 100)]); }

/** ייבוא מ-xlsx/csv. עמודות: תאריך, שם, תיאור, אסמכתה, סכום, קטגוריה, יחידה, סוג (הכנסה/הוצאה), חשבונית, קבלה. מדלג על שורות זהות לקיימות. */
function budget_import(string $path, string $name, string $by): array {
  if (preg_match('/\.xlsx$/i', $name)) $rows = xlsx_rows($path);
  else {
    $fh = fopen($path, 'rb'); if (!$fh) throw new RuntimeException('לא ניתן לקרוא את הקובץ.');
    $first = (string)fgets($fh); rewind($fh); $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $bom = fread($fh, 3); if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    $rows = []; while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) { $rows[] = $r; if (count($rows) > BUDGET_MAX_IMPORT + 20) break; } fclose($fh);
  }
  $hi = -1; $col = [];
  $alias = ['תאריך' => 'd', 'שם' => 'n', 'ספק' => 'n', 'תיאור' => 'e', 'פירוט' => 'e', 'אסמכתה' => 'r', 'אסמכתא' => 'r', 'סכום' => 'a', 'קטגוריה' => 'c', 'יחידה' => 'u', 'סוג' => 't', 'חשבונית' => 'inv', 'קבלה' => 'rec'];
  foreach ($rows as $i => $r) { $m = []; foreach ($r as $j => $v) { $k = $alias[trim((string)$v)] ?? null; if ($k && !isset($m[$k])) $m[$k] = $j; } if (isset($m['d'], $m['a'])) { $hi = $i; $col = $m; break; } if ($i > 15) break; }
  if ($hi < 0) throw new RuntimeException('לא נמצאה שורת כותרות. נדרשות לפחות העמודות "תאריך" ו"סכום".');
  $rows = array_slice($rows, $hi + 1);
  if (count($rows) > BUDGET_MAX_IMPORT) throw new RuntimeException('יותר מדי שורות בקובץ (מקסימום ' . BUDGET_MAX_IMPORT . ').');
  $g = fn(array $r, string $k) => isset($col[$k]) ? trim((string)($r[$col[$k]] ?? '')) : '';
  $rules = null; $units = budget_units(); $pdo = db();
  $ex = $pdo->prepare('SELECT 1 FROM budget_tx WHERE tx_date=? AND name=? AND descr=? AND ref=? AND amount=? AND is_income=? AND unit=? LIMIT 1');
  $ins = $pdo->prepare('INSERT INTO budget_tx (tx_date,name,descr,ref,amount,is_income,category,unit,has_invoice,has_receipt,created_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
  $added = 0; $dup = 0; $bad = 0; $pdo->beginTransaction();
  try {
    foreach ($rows as $r) {
      if (!array_filter($r, fn($v) => trim((string)$v) !== '')) continue;
      $d = budget_date($g($r, 'd')); $a = budget_amount($g($r, 'a'));
      if ($d === '' || $a === null) { $bad++; continue; }
      $n = mb_substr($g($r, 'n'), 0, 150); $e = mb_substr($g($r, 'e'), 0, 300); $ref = mb_substr($g($r, 'r'), 0, 100);
      $c = mb_substr($g($r, 'c'), 0, 100); if ($c === '') $c = budget_auto($n, $e, $rules);
      $inc = $g($r, 't') === 'הכנסה' ? 1 : 0; $u = budget_unit_for($g($r, 'u'), $c);
      $ex->execute([$d, $n, $e, $ref, $a, $inc, $u]); if ($ex->fetchColumn()) { $dup++; continue; }
      budget_cat_ensure($c);
      $ins->execute([$d, $n, $e, $ref, $a, $inc, $c, $u, $g($r, 'inv') === 'כן' ? 1 : 0, $g($r, 'rec') === 'כן' ? 1 : 0, now(), mb_substr($by, 0, 100)]); $added++;
    }
    $pdo->commit();
  } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
  return [$added, $dup, $bad];
}

/** תנאי WHERE ופרמטרים לפי מסנני הדף. */
function budget_where(array $f): array {
  $w = []; $a = [];
  if (!empty($f['range'])) { $w[] = 'tx_date BETWEEN ? AND ?'; $a[] = $f['range'][0]; $a[] = $f['range'][1]; }
  if (($f['cat'] ?? '') === '__un') $w[] = "category = ''"; elseif (($f['cat'] ?? '') !== '') { $w[] = 'category = ?'; $a[] = $f['cat']; }
  if (($f['unit'] ?? '') !== '') { $w[] = 'unit = ?'; $a[] = $f['unit']; }
  if (($f['type'] ?? '') === 'in') $w[] = 'is_income = 1'; elseif (($f['type'] ?? '') === 'out') $w[] = 'is_income = 0';
  switch ($f['doc'] ?? '') {
    case 'ni': $w[] = 'has_invoice = 0'; break; case 'nr': $w[] = 'has_receipt = 0'; break;
    case 'nb': $w[] = 'has_invoice = 0 AND has_receipt = 0'; break; case 'ok': $w[] = 'has_invoice = 1 AND has_receipt = 1'; break;
  }
  if (($f['month'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}$/', (string)$f['month'])) { $w[] = "DATE_FORMAT(tx_date,'%Y-%m') = ?"; $a[] = $f['month']; }
  $q = trim((string)($f['q'] ?? ''));
  if ($q !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'; $w[] = "(name LIKE ? OR descr LIKE ? OR ref LIKE ? OR category LIKE ? OR unit LIKE ? OR CAST(amount AS CHAR) LIKE ? OR DATE_FORMAT(tx_date,'%d/%m/%Y') LIKE ?)"; array_push($a, $like, $like, $like, $like, $like, $like, $like); }
  return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}
/** עמודת עמלה/גרף: אחוז רוחב (0..100) לשימוש במחלקת CSS wNN (בלי style בתוך ה-HTML בגלל CSP). */
function budget_bar(string $label, float $val, float $max, string $text, bool $inc = false): string {
  $p = $max > 0 ? (int)max(1, min(100, round(abs($val) / $max * 100))) : 1;
  return '<div class="bgb"><span>' . e($label) . '</span><i class="w' . $p . ($inc ? ' inc' : '') . '"></i><span class="n">' . e($text) . '</span></div>';
}

/** מסנני הדף מתוך GET: שנה (y=שנת התחלה או all), קטגוריה, יחידה, סוג, מסמכים, חודש, חיפוש. */
function budget_filters(): array {
  $ys = budget_years(); $yr = (string)($_GET['y'] ?? ''); $y = $yr === 'all' ? null : (ctype_digit($yr) && in_array((int)$yr, $ys, true) ? (int)$yr : budget_year_current());
  $cat = (string)($_GET['cat'] ?? ''); $unit = (string)($_GET['unit'] ?? ''); if ($unit !== '' && !in_array($unit, budget_units(), true)) $unit = '';
  $type = (string)($_GET['type'] ?? ''); if (!in_array($type, ['in', 'out'], true)) $type = '';
  $doc = (string)($_GET['doc'] ?? ''); if (!in_array($doc, ['ni', 'nr', 'nb', 'ok'], true)) $doc = '';
  $mo = (string)($_GET['month'] ?? ''); if (!preg_match('/^\d{4}-\d{2}$/', $mo)) $mo = '';
  return ['y' => $y, 'yr' => $y === null ? 'all' : (string)$y, 'years' => $ys, 'range' => budget_range($y), 'cat' => mb_substr($cat, 0, 100), 'unit' => $unit, 'type' => $type, 'doc' => $doc, 'month' => $mo, 'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100)];
}
function budget_year_select(array $f): string {
  $o = '<option value="all"' . ($f['y'] === null ? ' selected' : '') . '>כל התקופה</option>';
  foreach ($f['years'] as $y) $o .= '<option value="' . $y . '"' . ($f['y'] === $y ? ' selected' : '') . '>שנת ' . e(budget_year_label($y)) . ' (' . $y . '–' . ($y + 1) . ')</option>';
  return '<label>שנה<select name="y">' . $o . '</select></label>';
}
