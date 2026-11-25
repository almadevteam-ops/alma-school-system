<?php
declare(strict_types=1);
// תקציב: חשבוניות וקבלות לתנועות, חלונית "לתשלום", תשלום חלקי וקבצי מס"ב (זיכויים).

function bpay_ready(): bool {
  static $ok = null;
  if ($ok === null) { try { $ok = (bool)db()->query("SHOW TABLES LIKE 'budget_payables'")->fetch() && (bool)db()->query("SHOW COLUMNS FROM budget_tx LIKE 'paid'")->fetch(); } catch (Throwable $t) { $ok = false; } }
  return $ok;
}
function bdoc_dir(): string { $d = PRIV . '/storage/budget_docs'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }

/** שמירת קובץ שהועלה (PDF או תמונה) כחשבונית/קבלה של תנועה או של חשבונית לתשלום. מחזירה [id, שגיאה]. */
function bdoc_save(array $f, string $kind, ?int $txId, ?int $payId, string $by): array {
  if ((int)($f['error'] ?? 4) !== UPLOAD_ERR_OK) return [0, upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE))];
  if ((int)$f['size'] > 10 * 1024 * 1024) return [0, 'הקובץ גדול מדי (מעל 10MB).'];
  if (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) return [0, 'קובץ לא תקין.'];
  $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? '';
  if ($ext === '') return [0, 'אפשר להעלות PDF או תמונה (JPG / PNG) בלבד.'];
  $name = bin2hex(random_bytes(16)) . '.' . $ext;
  if (!@move_uploaded_file($f['tmp_name'], bdoc_dir() . '/' . $name) && !@copy($f['tmp_name'], bdoc_dir() . '/' . $name)) return [0, 'שמירת הקובץ נכשלה.'];
  db()->prepare('INSERT INTO budget_docs (tx_id, payable_id, kind, orig_name, stored, mime, size, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$txId, $payId, $kind === 'rec' ? 'rec' : 'inv', mb_substr(preg_replace('/[\r\n]+/', ' ', (string)$f['name']), 0, 200), $name, $mime, (int)$f['size'], mb_substr($by, 0, 100), now()]);
  return [(int)db()->lastInsertId(), ''];
}
function bdoc_delete(int $id): bool {
  $st = db()->prepare('SELECT stored FROM budget_docs WHERE id = ?'); $st->execute([$id]); $r = $st->fetch(); if (!$r) return false;
  @unlink(bdoc_dir() . '/' . basename((string)$r['stored'])); db()->prepare('DELETE FROM budget_docs WHERE id = ?')->execute([$id]); return true;
}
/** קבצים לפי תנועות: [tx_id => [kind => [שורות]]]. */
function bdoc_by_tx(array $ids): array {
  if (!$ids || !bpay_ready()) return [];
  $st = db()->prepare('SELECT id, tx_id, kind, orig_name FROM budget_docs WHERE tx_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id'); $st->execute(array_values($ids)); $o = [];
  foreach ($st->fetchAll() as $r) $o[(int)$r['tx_id']][$r['kind']][] = $r; return $o;
}
function bdoc_html(array $docs, string $self = ''): string {
  $h = ''; foreach ($docs as $d) $h .= '<span class="bdf"><a href="budget_file.php?id=' . (int)$d['id'] . '" target="_blank" rel="noopener" title="' . e($d['orig_name']) . '">📎 ' . e(mb_strimwidth((string)$d['orig_name'], 0, 14, '…')) . '</a> <button type="button" class="bdx" data-doc="' . (int)$d['id'] . '" aria-label="הסרת הקובץ" title="הסרה">×</button></span>';
  return $h;
}

/** הוספת חשבונית לתשלום. מחזירה את המזהה. */
function bpay_add(array $d, string $by): int {
  db()->prepare('INSERT INTO budget_payables (supplier, biz_id, bank, branch, account, ref, inv_date, due_date, descr, category, total, pay_now, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
    mb_substr($d['supplier'], 0, 150), mb_substr($d['biz_id'], 0, 20), mb_substr($d['bank'], 0, 3), mb_substr($d['branch'], 0, 4), mb_substr($d['account'], 0, 12), mb_substr($d['ref'], 0, 60), $d['inv_date'] ?: null, $d['due_date'] ?: null,
    mb_substr($d['descr'], 0, 300), mb_substr($d['category'], 0, 100), $d['total'], $d['total'], mb_substr($by, 0, 100), now()]);
  return (int)db()->lastInsertId();
}
/** רישום תשלום (מלא או חלקי). תשלום מלא מעביר את החשבונית להוצאות (כשולם). מחזירה [הודעה, הועבר להוצאות]. */
function bpay_pay(int $pid, float $amount, string $by, ?string $on = null, string $note = ''): array {
  $pdo = db(); $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM budget_payables WHERE id = ? AND status = 'open' FOR UPDATE"); $st->execute([$pid]); $r = $st->fetch();
    if (!$r) { $pdo->rollBack(); return ['החשבונית לא נמצאה או שכבר שולמה.', false]; }
    $rem = round((float)$r['total'] - (float)$r['paid_amount'], 2); $amount = round(min(max($amount, 0), $rem), 2);
    if ($amount <= 0) { $pdo->rollBack(); return ['יש להזין סכום לתשלום.', false]; }
    $on = ($on && preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) ? $on : date('Y-m-d');
    $pdo->prepare('INSERT INTO budget_pay_log (payable_id, amount, paid_on, note, created_by, created_at) VALUES (?,?,?,?,?,?)')->execute([$pid, $amount, $on, mb_substr($note, 0, 120), mb_substr($by, 0, 100), now()]);
    $paid = round((float)$r['paid_amount'] + $amount, 2); $left = round((float)$r['total'] - $paid, 2);
    if ($left > 0.004) {
      $pdo->prepare('UPDATE budget_payables SET paid_amount = ?, pay_now = ?, masav = 0 WHERE id = ?')->execute([$paid, $left, $pid]); $pdo->commit();
      return ['נרשם תשלום חלקי של ' . budget_money($amount) . '. נשאר לתשלום ' . budget_money($left) . '.', false];
    }
    $cat = (string)$r['category']; if ($cat !== '') budget_cat_ensure($cat);
    $has = (int)$pdo->query('SELECT COUNT(*) FROM budget_docs WHERE payable_id = ' . (int)$pid)->fetchColumn() > 0 ? 1 : 0;
    $pdo->prepare('INSERT INTO budget_tx (tx_date,name,descr,ref,amount,is_income,category,unit,has_invoice,has_receipt,created_at,created_by,paid,payable_id) VALUES (?,?,?,?,?,0,?,?,?,0,?,?,1,?)')->execute([date('Y-m-d'), $r['supplier'], $r['descr'], $r['ref'], $r['total'], $cat, budget_unit_for('', $cat), $has, now(), mb_substr($by, 0, 100), $pid]);
    $tx = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE budget_docs SET tx_id = ? WHERE payable_id = ?')->execute([$tx, $pid]);
    $pdo->prepare("UPDATE budget_payables SET paid_amount = total, pay_now = NULL, masav = 0, status = 'paid', moved_tx_id = ?, paid_at = ? WHERE id = ?")->execute([$tx, now(), $pid]); $pdo->commit();
    return ['שולם במלואו והועבר לרשימת ההוצאות (תנועות).', true];
  } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
}

// ---------- מס"ב זיכויים ----------
function masav_cfg(): array { return ['inst' => preg_replace('/\D/', '', setting_get('masav_inst', '')), 'sender' => preg_replace('/\D/', '', setting_get('masav_sender', '')), 'name' => setting_get('masav_name', ''), 'vis' => setting_get('masav_vis', '1') !== '0']; }
function masav_cfg_ok(array $c): bool { return strlen($c['inst']) >= 1 && strlen($c['inst']) <= 8 && strlen($c['sender']) >= 1 && strlen($c['sender']) <= 5 && trim($c['name']) !== ''; }
/** שדה טקסט בקידוד DOS עברית (862), באורך קבוע. $vis: סדר ויזואלי (עברית הפוכה) כמקובל בקבצי מס"ב. */
function masav_txt(string $s, int $len, bool $vis): string {
  $s = trim((string)preg_replace('/[^\x{05D0}-\x{05EA}A-Za-z0-9 .\-\/&()\'"]/u', ' ', $s)); $s = mb_substr($s, 0, $len); if ($vis) $s = pdf_bidi($s);
  $b = @iconv('UTF-8', 'CP862//TRANSLIT//IGNORE', $s); if ($b === false) $b = @iconv('UTF-8', 'Windows-1255//IGNORE', $s); if ($b === false) $b = preg_replace('/[^\x20-\x7E]/', ' ', $s);
  return str_pad(substr($b, 0, $len), $len, ' ');
}
function masav_lines_check(array $lines): array {
  $err = [];
  foreach ($lines as $l) {
    $n = (string)$l['supplier']; if (!preg_match('/^\d{2,3}$/', (string)$l['bank']) || !preg_match('/^\d{1,3}$/', (string)$l['branch']) || !preg_match('/^\d{4,9}$/', (string)$l['account'])) $err[] = $n . ': חסרים או לא תקינים פרטי בנק (בנק, סניף, חשבון)';
    elseif (strlen(preg_replace('/\D/', '', (string)$l['biz_id'])) < 5) $err[] = $n . ': חסר מספר עוסק / ת"ז';
    if ((float)$l['amount'] <= 0) $err[] = $n . ': סכום לתשלום חסר';
  }
  return $err;
}
/** בניית קובץ מס"ב זיכויים (רשומות באורך 128, שורה לכל רשומה). $lines: supplier, biz_id, bank, branch, account, ref, amount. */
function masav_build(array $lines, string $creditDate, array $c): string {
  $ymd = fn(string $d) => date('ymd', strtotime($d)); $inst = str_pad($c['inst'], 8, '0', STR_PAD_LEFT); $cd = $ymd($creditDate); $vis = $c['vis'];
  $rec = []; $rec[] = 'K' . $inst . '00' . $cd . '0' . '001' . '0' . date('ymd') . str_pad($c['sender'], 5, '0', STR_PAD_LEFT) . '000000' . masav_txt($c['name'], 30, $vis) . str_repeat(' ', 56) . 'KOT';
  $sum = 0; $n = 0;
  foreach ($lines as $l) {
    $ag = (int)round((float)$l['amount'] * 100); $sum += $ag; $n++;
    $acc = str_pad(substr(preg_replace('/\D/', '', (string)$l['account']), -9), 9, '0', STR_PAD_LEFT);
    $rec[] = '1' . $inst . '00' . '000000' . str_pad(substr(preg_replace('/\D/', '', (string)$l['bank']), -2), 2, '0', STR_PAD_LEFT) . str_pad(substr(preg_replace('/\D/', '', (string)$l['branch']), -3), 3, '0', STR_PAD_LEFT) . '0000' . $acc . '0'
      . str_pad(substr(preg_replace('/\D/', '', (string)$l['biz_id']), -9), 9, '0', STR_PAD_LEFT) . masav_txt((string)$l['supplier'], 16, $vis) . str_pad((string)$ag, 13, '0', STR_PAD_LEFT)
      . str_pad(substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)$l['ref']), 0, 20), 20, ' ') . '00000000' . '000' . '006' . str_repeat('0', 18) . '  ';
  }
  $rec[] = '5' . $inst . '00' . $cd . '0' . '001' . str_pad((string)$sum, 15, '0', STR_PAD_LEFT) . str_repeat('0', 15) . str_pad((string)$n, 7, '0', STR_PAD_LEFT) . str_repeat('0', 7) . str_repeat(' ', 63);
  $rec[] = str_repeat('9', 128);
  return implode("\r\n", $rec) . "\r\n";
}
