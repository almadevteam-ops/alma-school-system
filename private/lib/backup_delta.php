<?php
declare(strict_types=1);
// גיבוי דלתא כל 10 דקות + גיבוי מלא יומי. מוצפן באותו פורמט (ALMB1) ובאותו מפתח.
// דלתא = רק שורות שנוספו/השתנו/נמחקו מאז הריצה הקודמת (השוואת טביעות-אצבע של שורות), וקבצים מוצפנים חדשים.
// שחזור = גיבוי מלא אחרון + כל הדלתאות שאחריו לפי הסדר (אפשר עד דקה מסוימת).
const BK_SKIP_TABLES = ['backup_log'];   // היומן עצמו לא נכנס לגיבוי (אחרת כל דלתא הייתה מכילה את הרישום של הקודמת)

// ---------- יומן ----------
function bk_log(array $r): void {
  $r += ['kind' => '', 'target' => 'server', 'status' => 'ok', 'file' => '', 'location' => '', 'size' => 0, 'rows' => 0, 'files' => 0, 'secs' => 0, 'detail' => '', 'started' => time(), 'finished' => time()];
  try {
    db()->prepare('INSERT INTO backup_log (started_at, finished_at, kind, target, status, file, location, size_bytes, rows_n, files_n, secs, detail) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([date('Y-m-d H:i:s', (int)$r['started']), date('Y-m-d H:i:s', (int)$r['finished']), $r['kind'], $r['target'], $r['status'], mb_substr((string)$r['file'], 0, 120), mb_substr((string)$r['location'], 0, 255), (int)$r['size'], (int)$r['rows'], (int)$r['files'], round((float)$r['secs'], 2), mb_substr((string)$r['detail'], 0, 500)]);
  } catch (Throwable $t) {   // אם המסד לא זמין, הרישום נופל לקובץ
    @file_put_contents(bk_dir() . '/log-fallback.jsonl', json_encode($r + ['err' => $t->getMessage()], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
  }
}

// ---------- מצב ----------
function bk_state_file(): string { return bk_dir() . '/state.json'; }
function bk_state_load(): array { $f = bk_state_file(); $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
function bk_state_save(array $s): void {
  $tmp = bk_state_file() . '.tmp'; file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX); @chmod($tmp, 0600); rename($tmp, bk_state_file());
}
function bk_storage_files(): array {
  $o = []; foreach (glob(PRIV . '/storage/*.bin') ?: [] as $f) { $n = basename($f); if (preg_match('/^[0-9a-f]{32}\.bin$/', $n) && is_file($f)) $o[$n] = (int)filesize($f); }
  return $o;
}

// ---------- SQL ----------
function bk_qid(string $n): string { return '`' . str_replace('`', '``', $n) . '`'; }
function bk_qv(PDO $pdo, $v): string { return $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $pdo->quote((string)$v)); }
function bk_rowhash(array $r): string { return substr(md5(implode("\x1f", array_map(fn($v) => $v === null ? "\0N" : (string)$v, $r))), 0, 16); }
/**
 * כותבת SQL לקובץ gz. $prev === null: גיבוי מלא. אחרת דלתא מול המצב הקודם.
 * מחזירה ['rows' => שורות שנכתבו, 'changes' => מספר שינויים, 'tables' => המצב החדש].
 */
function bk_dump_sql(PDO $pdo, string $gzPath, ?array $prev): array {
  $full = $prev === null; $gz = gzopen($gzPath, 'wb6'); if (!$gz) throw new RuntimeException('cannot write dump');
  gzwrite($gz, '-- Alma ' . ($full ? 'full' : 'delta') . ' ' . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
  $names = array_map(fn($r) => (string)$r[0], $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM));
  // checksum נלקח *לפני* הצילום: אם משהו משתנה ביניהם, הריצה הבאה תראה checksum שונה ותסרוק שוב (לא מפספסים שינוי)
  $cks = []; foreach ($names as $t) { if (in_array($t, BK_SKIP_TABLES, true)) continue; $c = $pdo->query('CHECKSUM TABLE ' . bk_qid($t))->fetchAll(PDO::FETCH_NUM); $cks[$t] = (string)($c[0][1] ?? ''); }
  $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
  $new = []; $rows = 0; $changes = 0;
  try {
    foreach ($names as $t) {
      $q = bk_qid($t);
      $cr = $pdo->query('SHOW CREATE TABLE ' . $q)->fetchAll(PDO::FETCH_NUM); $create = (string)$cr[0][1];
      $sch = md5(preg_replace('/ AUTO_INCREMENT=\d+/', '', $create) ?? $create);
      $cols = array_map(fn($r) => (string)$r['Field'], $pdo->query('SHOW COLUMNS FROM ' . $q)->fetchAll(PDO::FETCH_ASSOC));
      $pkc = array_map(fn($r) => (string)$r['Column_name'], $pdo->query("SHOW KEYS FROM $q WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC));
      $pkc = $pkc ?: $cols; $pki = array_map(fn($c) => (int)array_search($c, $cols, true), $pkc);
      $pt = $prev['tables'][$t] ?? null; $rebuild = $full || !$pt || ($pt['sch'] ?? '') !== $sch;
      if (in_array($t, BK_SKIP_TABLES, true)) {   // מבנה בלבד
        if ($rebuild) { gzwrite($gz, "DROP TABLE IF EXISTS $q;\n$create;\n\n"); if (!$full) $changes++; }
        $new[$t] = ['sch' => $sch, 'ck' => '', 'pk' => $pkc, 'rows' => []]; continue;
      }
      $ck = $cks[$t] ?? '';
      if (!$rebuild && $ck !== '' && ($pt['ck'] ?? null) === $ck) { $new[$t] = $pt; continue; }   // לא השתנתה
      if ($rebuild) { gzwrite($gz, "DROP TABLE IF EXISTS $q;\n$create;\n\n"); if (!$full) $changes++; }
      $map = []; $batch = []; $verb = $rebuild ? 'INSERT INTO' : 'REPLACE INTO';
      $flush = function () use (&$batch, $gz, $q, $verb) { if ($batch) { gzwrite($gz, "$verb $q VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; } };
      $st = $pdo->query('SELECT * FROM ' . $q);
      while ($r = $st->fetch(PDO::FETCH_NUM)) {
        $key = json_encode(array_map(fn($i) => $r[$i] === null ? null : (string)$r[$i], $pki)); $h = bk_rowhash($r); $map[$key] = $h;
        if ($rebuild || ($pt['rows'][$key] ?? null) !== $h) {
          $batch[] = '(' . implode(',', array_map(fn($v) => bk_qv($pdo, $v), $r)) . ')'; $rows++; if (!$rebuild || !$full) $changes++;
          if (count($batch) >= 200) $flush();
        } elseif ($full) $rows++;
      }
      $st->closeCursor(); $flush();
      if (!$rebuild) foreach (array_diff_key($pt['rows'] ?? [], $map) as $key => $_) {   // שורות שנמחקו
        $vals = json_decode((string)$key, true) ?: []; $w = [];
        foreach ($pkc as $i => $cn) $w[] = bk_qid($cn) . (($vals[$i] ?? null) === null ? ' IS NULL' : ' = ' . $pdo->quote((string)$vals[$i]));
        gzwrite($gz, "DELETE FROM $q WHERE " . implode(' AND ', $w) . ";\n"); $changes++;
      }
      gzwrite($gz, "\n"); $new[$t] = ['sch' => $sch, 'ck' => $ck, 'pk' => $pkc, 'rows' => $map];
    }
    if (!$full) foreach (array_diff(array_keys($prev['tables'] ?? []), $names) as $gone) { gzwrite($gz, 'DROP TABLE IF EXISTS ' . bk_qid((string)$gone) . ";\n"); $changes++; }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
  } finally { try { $pdo->exec('COMMIT'); } catch (Throwable $t) {} gzclose($gz); }
  return ['rows' => $rows, 'changes' => $changes, 'tables' => $new];
}
/** מריצה קובץ SQL (gz) על חיבור נתון. מפצלת הצהרות לפי ; מחוץ למחרוזות. מחזירה מספר הצהרות. */
function bk_sql_load(PDO $pdo, string $gzPath): int {
  $gz = gzopen($gzPath, 'rb'); if (!$gz) throw new RuntimeException('cannot read sql'); $n = 0; $buf = ''; $inStr = false; $carry = '';
  $exec = function (string $s) use ($pdo, &$n) { $s = trim($s); if ($s === '') return; $pdo->exec($s); $n++; };
  while (!gzeof($gz)) {
    $chunk = $carry . (string)gzread($gz, 65536); $carry = ''; $p = 0; $len = strlen($chunk);
    while ($p < $len) {
      if ($inStr) {
        $e = strcspn($chunk, "'\\", $p); $buf .= substr($chunk, $p, $e + 1); $p += $e;
        if ($p >= $len) break;
        $ch = $chunk[$p]; $p++;
        if ($ch === '\\') { if ($p >= $len) { $carry = ''; $buf .= ''; $pending = true; } else { $buf .= $chunk[$p]; $p++; } }
        else $inStr = false;
        continue;
      }
      if (trim($buf) === '' && substr($chunk, $p, 2) === '--') { $nl = strpos($chunk, "\n", $p); if ($nl === false) { $p = $len; break; } $p = $nl + 1; $buf = ''; continue; }
      $e = strcspn($chunk, "';", $p); $buf .= substr($chunk, $p, $e); $p += $e;
      if ($p >= $len) break;
      if ($chunk[$p] === "'") { $buf .= "'"; $p++; $inStr = true; }
      else { $p++; $exec($buf); $buf = ''; }
    }
  }
  gzclose($gz); if (trim($buf) !== '') $exec($buf);
  return $n;
}

// ---------- שימור ----------
function bk_stamp_ts(string $name): int { $s = bk_stamp($name); return $s === '' ? 0 : (int)strtotime(substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2) . ' ' . substr($s, 9, 2) . ':' . substr($s, 11, 2) . ':' . substr($s, 13, 2)); }
/** אילו קבצים נשארים: מלאים – האחרון בכל יום ($daily ימים אחרונים) + עוד $weekly של ימי ראשון + עוד $monthly חודשיים; דלתאות – $deltaDays ימים. */
function bk_keep_set(array $names, int $daily, int $weekly, int $monthly, int $deltaDays): array {
  $keep = []; $byDay = [];
  foreach ($names as $n) {
    if (strpos($n, 'alma-delta-') === 0) { if (bk_stamp_ts($n) >= time() - $deltaDays * 86400) $keep[$n] = 1; continue; }
    $st = bk_stamp($n); if ($st === '') { $keep[$n] = 1; continue; }
    $d = substr($st, 0, 8); if (!isset($byDay[$d]) || strcmp($byDay[$d], $n) < 0) $byDay[$d] = $n;
  }
  krsort($byDay, SORT_STRING); $i = 0; foreach ($byDay as $d => $n) { if ($i++ < $daily) $keep[$n] = 1; }
  $w = 0; foreach ($byDay as $d => $n) { $d = (string)$d; if ($w >= $weekly) break; if (isset($keep[$n])) continue; if ((int)date('w', (int)strtotime($d)) === 0) { $keep[$n] = 1; $w++; } }
  $m = 0; $seen = []; foreach ($byDay as $d => $n) { $ym = substr((string)$d, 0, 6); if (isset($seen[$ym])) continue; $seen[$ym] = 1; if ($m >= $monthly) break; if (isset($keep[$n])) continue; $keep[$n] = 1; $m++; }
  return array_keys($keep);
}
/** שימור בשרת. מחזיר כמה נמחקו. */
function bk_prune(): int {
  $all = bk_list_all(); $st = bk_state_load(); $keep = array_flip(bk_keep_set(array_column($all, 'name'), BK_KEEP_DAILY, BK_KEEP_WEEKLY, 0, BK_DELTA_DAYS));
  if (!empty($st['base'])) $keep[$st['base']] = 1;
  if (!empty($st['last'])) $keep[$st['last']] = 1;
  $n = 0; foreach ($all as $f) if (!isset($keep[$f['name']]) && @unlink(bk_dir() . '/' . $f['name'])) $n++;
  return $n;
}

// ---------- יצירת גיבוי ----------
/** יוצר גיבוי מלא או דלתא. מחזיר ['file' => ?string, ...]. file=null: אין שינויים (דלתא ריקה). זורק חריג בכשל. */
function bk_make(string $type, array &$state): array {
  $t0 = microtime(true); $dir = bk_dir(); $full = $type === 'full';
  $last = $state['last'] ?? ''; $stamp = date('Ymd-His'); if ($last !== '' && strcmp(bk_stamp($last), $stamp) >= 0) $stamp = date('Ymd-His', bk_stamp_ts($last) + 1);   // שם קובץ חייב להיות עולה ממש
  $name = ($full ? 'alma-backup-' : 'alma-delta-') . $stamp . '.almb'; $final = $dir . '/' . $name; $part = $final . '.part'; $gz = $dir . '/tmp-dump-' . bin2hex(random_bytes(4)) . '.sql.gz';
  try {
    $pdo = bk_pdo(); $d = bk_dump_sql($pdo, $gz, $full ? null : $state);
    $cur = bk_storage_files(); $prevFiles = $full ? [] : ($state['files'] ?? []);
    $add = array_values(array_filter(array_keys($full ? $cur : array_diff_key($cur, $prevFiles)), fn($n) => is_file(PRIV . '/storage/' . $n)));
    $gone = $full ? [] : array_values(array_diff(array_keys($prevFiles), array_keys($cur)));
    if (!$full && $d['changes'] === 0 && !$add && !$gone) { @unlink($gz); $state['tables'] = $d['tables']; $state['beat'] = time(); return ['file' => null, 'rows' => 0, 'files' => 0, 'secs' => microtime(true) - $t0]; }
    $meta = ['v' => 1, 'type' => $type, 'time' => date('c'), 'base' => $full ? $name : (string)($state['base'] ?? ''), 'prev' => $full ? '' : (string)($state['last'] ?? ''), 'seq' => $full ? 0 : (int)($state['seq'] ?? 0) + 1,
      'deleted_files' => $gone, 'rows' => $d['rows'], 'tables_changed' => $d['changes'], 'files_added' => count($add)];
    $w = new BkWriter($part); $w->addString('meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    $w->addFile($full ? 'db.sql.gz' : 'delta.sql.gz', $gz);
    foreach ($add as $n) $w->addFile('storage/' . $n, PRIV . '/storage/' . $n);
    $w->close();
    $listed = bk_read($part); if (count($listed) !== count($add) + 2) throw new RuntimeException('verify: entry count mismatch');   // אימות: פענוח מלא + checksum
    rename($part, $final); @unlink($gz);
    $newFiles = $full ? array_intersect_key($cur, array_flip($add)) : array_merge($prevFiles, array_intersect_key($cur, array_flip($add)));
    if (!$full) foreach ($gone as $g) unset($newFiles[$g]);
    $state = ['base' => $meta['base'], 'last' => $name, 'seq' => $meta['seq'], 'time' => time(), 'tables' => $d['tables'], 'files' => $newFiles, 'uploaded' => $state['uploaded'] ?? [], 'alerts' => $state['alerts'] ?? [], 'drive' => $state['drive'] ?? [], 'beat' => time(), 'verify_ts' => $state['verify_ts'] ?? 0];
    return ['file' => $name, 'size' => (int)filesize($final), 'rows' => $d['rows'], 'files' => count($add), 'secs' => microtime(true) - $t0, 'type' => $type];
  } catch (Throwable $t) { @unlink($gz); @unlink($part); throw $t; }
}

// ---------- דרייב ----------
function bk_drive_sync(array &$state, int $max = 12): array {
  $out = [];
  if (!function_exists('gd_ready') || !gd_ready()) return $out;
  $up = $state['uploaded'] ?? []; $pending = array_values(array_filter(bk_list_all(), fn($f) => !isset($up[$f['name']]))); $uploaded = 0;
  foreach (array_slice($pending, 0, $max) as $f) {
    $t0 = microtime(true); $path = bk_dir() . '/' . $f['name'];
    try { [$id] = gd_upload_file($path); $state['uploaded'][$f['name']] = time(); $state['drive'] = ['ok' => true, 'time' => time(), 'file' => $f['name'], 'fails' => 0]; $uploaded++;
      bk_log(['kind' => $f['type'] === 'delta' ? 'delta' : 'full', 'target' => 'drive', 'status' => 'ok', 'file' => $f['name'], 'location' => 'Google Drive', 'size' => $f['size'], 'secs' => microtime(true) - $t0, 'detail' => 'הועלה'] );
    } catch (Throwable $t) {
      $fails = (int)($state['drive']['fails'] ?? 0) + 1; $state['drive'] = ['ok' => false, 'time' => time(), 'error' => $t->getMessage(), 'fails' => $fails, 'file' => $f['name']];
      bk_log(['kind' => $f['type'] === 'delta' ? 'delta' : 'full', 'target' => 'drive', 'status' => 'fail', 'file' => $f['name'], 'location' => 'Google Drive', 'size' => $f['size'], 'secs' => microtime(true) - $t0, 'detail' => $t->getMessage()]);
      $out[] = 'דרייב: ' . $t->getMessage(); return $out;
    }
  }
  if ($uploaded) $out[] = "הועלו לדרייב: $uploaded";
  if (!$pending) { $state['drive']['pending'] = 0; }
  else $state['drive']['pending'] = max(0, count($pending) - $uploaded);
  if (($uploaded || ($state['drive_prune_ts'] ?? 0) < time() - 3600) && (!$pending || count($pending) <= $max)) {
    try {
      $t0 = microtime(true); $files = gd_list(); $keep = array_flip(bk_keep_set(array_column($files, 'name'), 14, 8, 6, BK_DELTA_DAYS)); $del = 0;
      foreach ($files as $f) if (!isset($keep[$f['name']]) && gd_delete($f['id'])) $del++;
      $state['drive_prune_ts'] = time();
      if ($del) { bk_log(['kind' => 'prune', 'target' => 'drive', 'location' => 'Google Drive', 'detail' => "נמחקו $del קבצים ישנים", 'secs' => microtime(true) - $t0]); $out[] = "נוקו בדרייב: $del"; }
    } catch (Throwable $t) { bk_log(['kind' => 'prune', 'target' => 'drive', 'status' => 'fail', 'detail' => $t->getMessage()]); }
  }
  return $out;
}

// ---------- בדיקת תקינות שבועית ----------
function bk_verify(array &$state): string {
  $t0 = microtime(true); $full = null; foreach (array_reverse(bk_list_all()) as $f) if ($f['type'] === 'full') { $full = $f; break; }
  if (!$full) return '';
  try {
    $meta = null; $n = 0; $sqlOk = false;
    bk_read(bk_dir() . '/' . $full['name'], function ($nm, $tmp) use (&$meta, &$n, &$sqlOk) {
      if ($nm === 'meta.json') $meta = json_decode((string)file_get_contents($tmp), true);
      elseif (strpos($nm, 'storage/') === 0) $n++;
      elseif ($nm === 'db.sql.gz') { $g = gzopen($tmp, 'rb'); while (!gzeof($g)) { if (gzread($g, 1048576) === false) throw new RuntimeException('dump corrupt'); } gzclose($g); $sqlOk = true; }
    });
    if (!$sqlOk) throw new RuntimeException('db.sql.gz חסר');
    if (is_array($meta) && (int)$meta['files_added'] !== $n) throw new RuntimeException("מספר קבצים לא תואם ($n מול {$meta['files_added']})");
    $detail = 'פענוח, checksum ודאמפ תקינים';
    if (function_exists('gd_ready') && gd_ready() && isset($state['uploaded'][$full['name']])) {
      $found = null; foreach (gd_list() as $g) if ($g['name'] === $full['name']) $found = $g;
      if (!$found) throw new RuntimeException('העותק בדרייב לא נמצא');
      if ($found['size'] !== $full['size']) throw new RuntimeException("גודל בדרייב {$found['size']} שונה מהשרת {$full['size']}");
      $detail .= '; העותק בדרייב זהה בגודלו';
    }
    $state['verify_ts'] = time();
    bk_log(['kind' => 'verify', 'target' => 'server', 'status' => 'ok', 'file' => $full['name'], 'location' => 'שרת' . (strpos($detail, 'דרייב') ? ' + דרייב' : ''), 'size' => $full['size'], 'secs' => microtime(true) - $t0, 'detail' => $detail]);
    return '';
  } catch (Throwable $t) {
    bk_log(['kind' => 'verify', 'target' => 'server', 'status' => 'fail', 'file' => $full['name'], 'detail' => $t->getMessage(), 'secs' => microtime(true) - $t0]);
    return 'בדיקת תקינות נכשלה: ' . $t->getMessage();
  }
}

// ---------- התראות ----------
function bk_alert_cfg(): array {
  $to = array_values(array_filter(array_map('trim', explode(',', setting_get('bk_alert_to', '')))));
  return ['on' => setting_get('bk_alert_on', '1') === '1', 'delta_min' => max(11, (int)setting_get('bk_alert_delta_min', '30')), 'full_hours' => max(2, (int)setting_get('bk_alert_full_hours', '26')), 'to' => $to];
}
function bk_alert_check(array &$state, array $errs): void {
  $c = bk_alert_cfg(); if (!$c['on']) return; $now = time(); $problems = [];
  if ($errs) $problems['fail'] = 'הגיבוי נכשל: ' . implode(' | ', $errs);
  $beat = (int)($state['beat'] ?? 0); if ($beat && $now - $beat > $c['delta_min'] * 60) $problems['stale'] = 'לא הצליח גיבוי דלתא כבר ' . round(($now - $beat) / 60) . ' דקות.';
  $lastFull = 0; foreach (array_reverse(bk_list_all()) as $f) if ($f['type'] === 'full') { $lastFull = bk_stamp_ts($f['name']); break; }
  if ($now - $lastFull > $c['full_hours'] * 3600) $problems['full'] = 'הגיבוי המלא האחרון ישן מ-' . $c['full_hours'] . ' שעות.';
  if (function_exists('gd_ready') && gd_ready()) {
    $dr = $state['drive'] ?? []; if ((int)($dr['fails'] ?? 0) >= 3) $problems['drive'] = 'ההעלאה לגוגל דרייב נכשלה ' . (int)$dr['fails'] . ' פעמים ברצף: ' . ($dr['error'] ?? '');
    else { $old = null; $up = $state['uploaded'] ?? []; foreach (bk_list_all() as $f) if (!isset($up[$f['name']])) { $old = $f; break; } if ($old && $now - $old['time'] > 3 * 3600) $problems['drivepend'] = 'קבצים לא הועלו לדרייב כבר מעל 3 שעות (הישן: ' . $old['name'] . ').'; }
  }
  foreach ($problems as $k => $msg) {
    if ($now - (int)($state['alerts'][$k] ?? 0) < 6 * 3600) continue;
    $to = $c['to'] ?: array_column(notify_recipients(), 'email'); if (!$to) { bk_log(['kind' => 'alert', 'status' => 'fail', 'detail' => 'התראה לא נשלחה: אין נמענים']); continue; }
    $sent = 0; foreach ($to as $e) { try { if (send_mail($e, 'עלמא – התראת גיבוי', $msg . "\n\nראו: דף יומן הגיבויים במערכת (תחזוקה). ההתראה לא תישלח שוב על אותה בעיה במשך 6 שעות.")) $sent++; } catch (Throwable $t) {} }
    $state['alerts'][$k] = $now; bk_log(['kind' => 'alert', 'status' => $sent ? 'ok' : 'fail', 'detail' => 'התראה: ' . $msg . " (נשלחה ל-$sent)"]);
  }
}

// ---------- מחזור ----------
/** $force: '' (החלטה אוטומטית), 'full', 'delta' או 'drive' (רק סנכרון דרייב). מחזיר [ok, שורות]. */
function bk_cycle(string $force = ''): array {
  $dir = bk_dir(); $lock = fopen($dir . '/.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $GLOBALS['BK_SERVER_OK'] = false; return [false, ['גיבוי אחר רץ כרגע.']]; }
  @set_time_limit(0); $lines = []; $errs = []; $ok = true; $serverOk = true;
  try {
    $fresh = !is_file(bk_state_file()); $state = bk_state_load(); $legacy = [];
    if ($fresh) foreach (bk_list_all() as $f) $legacy[$f['name']] = time();
    $todayFull = false; foreach (bk_list_all() as $f) if ($f['type'] === 'full' && substr(bk_stamp($f['name']), 0, 8) === date('Ymd')) $todayFull = true;
    $broken = empty($state['base']) || empty($state['tables']) || !is_file($dir . '/' . $state['base']) || empty($state['last']) || !is_file($dir . '/' . $state['last']);
    $type = $force === 'drive' ? '' : ($force === 'full' || $broken ? 'full' : ($force === 'delta' ? 'delta' : ((!$todayFull && (int)date('G') >= (int)cfg('bk_full_hour', 2) && (int)($state['full_fail'] ?? 0) < time() - 3600) ? 'full' : 'delta')));
    if ($type) {
      $t0 = time();
      try {
        $r = bk_make($type, $state);
        if ($fresh) $state['uploaded'] = ($state['uploaded'] ?? []) + $legacy;
        if ($r['file'] === null) { /* אין שינויים */ }
        else {
          bk_log(['kind' => $type, 'target' => 'server', 'status' => 'ok', 'file' => $r['file'], 'location' => 'שרת: private/backups', 'size' => $r['size'], 'rows' => $r['rows'], 'files' => $r['files'], 'secs' => $r['secs'], 'started' => $t0,
            'detail' => $type === 'full' ? 'גיבוי מלא' : 'שינויים מאז הגיבוי הקודם']);
          $lines[] = ($type === 'full' ? 'גיבוי מלא: ' : 'דלתא: ') . $r['file'] . ' · ' . round($r['size'] / 1048576, 2) . 'MB · ' . $r['rows'] . ' שורות · ' . $r['files'] . ' קבצים';
          if ($type === 'full') {
            $st = ['ok' => true, 'time' => time(), 'file' => $r['file'], 'size' => $r['size'], 'rows' => $r['rows'], 'files' => $r['files'], 'secs' => round($r['secs'], 1)]; file_put_contents(bk_status_file(), json_encode($st));
            unset($state['full_fail']);
          }
        }
        if ($r['file'] === null) $lines[] = 'אין שינויים מאז הגיבוי הקודם';
      } catch (Throwable $t) {
        $ok = false; $serverOk = false; $errs[] = $t->getMessage(); $lines[] = 'הגיבוי נכשל: ' . $t->getMessage(); error_log('[alma-backup] ' . $t->getMessage());
        if ($type === 'full') { $state['full_fail'] = time(); file_put_contents(bk_status_file(), json_encode(['ok' => false, 'time' => time(), 'error' => $t->getMessage()])); }
        bk_log(['kind' => $type, 'target' => 'server', 'status' => 'fail', 'location' => 'שרת: private/backups', 'detail' => $t->getMessage(), 'started' => $t0]);
      }
      if ($ok) {
        try { $p = bk_prune(); if ($p) bk_log(['kind' => 'prune', 'target' => 'server', 'location' => 'שרת: private/backups', 'detail' => "נמחקו $p קבצים ישנים"]); } catch (Throwable $t) {}
      }
    }
    try { foreach (bk_drive_sync($state) as $l) { $lines[] = $l; if (strpos($l, 'דרייב:') === 0) { $errs[] = $l; $ok = false; } } } catch (Throwable $t) { $errs[] = $t->getMessage(); $ok = false; }
    if ($type === 'full' && $ok && (int)($state['verify_ts'] ?? 0) < time() - 6 * 86400) { $v = bk_verify($state); if ($v) { $errs[] = $v; $lines[] = $v; } }
    try { bk_log_prune(); } catch (Throwable $t) {}
    bk_alert_check($state, $errs);
    bk_state_save($state);
  } catch (Throwable $t) {
    $ok = false; $serverOk = false; $lines[] = 'שגיאה: ' . $t->getMessage(); error_log('[alma-backup] ' . $t->getMessage());
  } finally { flock($lock, LOCK_UN); fclose($lock); }
  $GLOBALS['BK_SERVER_OK'] = $serverOk;
  return [$ok, $lines];
}
function bk_log_prune(): void {
  db()->prepare('DELETE FROM backup_log WHERE started_at < ?')->execute([date('Y-m-d H:i:s', time() - 90 * 86400)]);
}
/** תאימות: גיבוי מלא מיידי (רולאובר, דף הגיבויים). */
/** הצלחה = הגיבוי בשרת הצליח (תקלה בהעלאה לדרייב לא חוסמת, והיא מדווחת בהודעה וביומן). */
function bk_run(): array { [$ok, $l] = bk_cycle('full'); $sv = (bool)($GLOBALS['BK_SERVER_OK'] ?? $ok); return [$sv, ($sv ? 'הגיבוי הושלם: ' : '') . implode(' · ', $l)]; }

// ---------- שחזור ----------
/** מוצא בתיקיה את הרצף: מלא אחרון עד $to + הדלתאות שאחריו (בדיקת meta.prev). מחזיר ['files'=>[...], 'warn'=>?string]. */
function bk_chain(string $src, ?int $to = null): array {
  $all = []; foreach (array_merge(glob($src . '/alma-backup-*.almb') ?: [], glob($src . '/alma-delta-*.almb') ?: []) as $f) { $n = basename($f); $ts = bk_stamp_ts($n); if ($ts && ($to === null || $ts <= $to)) $all[$n] = $ts; }
  asort($all); $full = null; foreach ($all as $n => $ts) if (strpos($n, 'alma-backup-') === 0) $full = $n;
  if (!$full) throw new RuntimeException('לא נמצא גיבוי מלא' . ($to ? ' לפני הזמן המבוקש' : '') . ' בתיקיה');
  $chain = [$full]; $prev = $full; $warn = null;
  foreach ($all as $n => $ts) {
    if (strpos($n, 'alma-delta-') !== 0 || strcmp(bk_stamp($n), bk_stamp($full)) <= 0) continue;
    $meta = null; bk_read($src . '/' . $n, function ($nm, $tmp) use (&$meta) { if ($nm === 'meta.json') $meta = json_decode((string)file_get_contents($tmp), true); });
    if (!is_array($meta) || ($meta['prev'] ?? '') !== $prev) { $warn = "חוסר ברצף: $n מצפה ל-" . ($meta['prev'] ?? '?') . " אבל הקודם הוא $prev. השחזור נעצר לפניו."; break; }
    $chain[] = $n; $prev = $n;
  }
  return ['files' => $chain, 'warn' => $warn];
}
/** משחזר רצף לבסיס נתונים ריק (או $wipe) ולתיקיית storage. מחזיר שורות דיווח. */
function bk_restore(string $src, PDO $pdo, string $storageDir, ?int $to = null, bool $wipe = false): array {
  $c = bk_chain($src, $to); $log = []; $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
  $tabs = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
  if ($tabs) { if (!$wipe) throw new RuntimeException('בסיס הנתונים אינו ריק (' . count($tabs) . ' טבלאות). הוסיפו --wipe כדי למחוק אותן קודם.'); foreach ($tabs as $t) $pdo->exec('DROP TABLE IF EXISTS ' . bk_qid((string)$t[0])); }
  if (!is_dir($storageDir) && !@mkdir($storageDir, 0700, true)) throw new RuntimeException('לא ניתן ליצור תיקיית storage');
  foreach ($c['files'] as $n) {
    $meta = null; $stm = 0;
    bk_read($src . '/' . $n, function ($nm, $tmp) use ($pdo, $storageDir, &$meta, &$stm) {
      if ($nm === 'meta.json') $meta = json_decode((string)file_get_contents($tmp), true);
      elseif ($nm === 'db.sql.gz' || $nm === 'delta.sql.gz') $stm = bk_sql_load($pdo, $tmp);
      elseif (preg_match('#^storage/([0-9a-f]{32}\.bin)$#', $nm, $m)) copy($tmp, $storageDir . '/' . $m[1]);
    });
    foreach ($meta['deleted_files'] ?? [] as $g) if (preg_match('/^[0-9a-f]{32}\.bin$/', (string)$g)) @unlink($storageDir . '/' . $g);
    $log[] = "$n: $stm הצהרות SQL, " . ($meta['files_added'] ?? 0) . ' קבצים' . (!empty($meta['deleted_files']) ? ', ' . count($meta['deleted_files']) . ' נמחקו' : '');
  }
  $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
  if ($c['warn']) $log[] = 'אזהרה: ' . $c['warn'];
  return ['log' => $log, 'warn' => $c['warn'], 'last' => end($c['files'])];
}
