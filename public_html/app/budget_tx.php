<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$me = require_role(['admin']);
$by = (string)($me['name'] ?: $me['username']);
$f = budget_filters(); $cats = budget_cats(); $units = budget_units();
$keep = array_filter(['y' => $f['yr'], 'q' => $f['q'], 'cat' => $f['cat'], 'unit' => $f['unit'], 'type' => $f['type'], 'doc' => $f['doc'], 'month' => $f['month'], 's' => (string)($_GET['s'] ?? ''), 'dir' => (string)($_GET['dir'] ?? ''), 'p' => (string)($_GET['p'] ?? '')], fn($v) => $v !== '' && $v !== '1');
$self = 'budget_tx.php' . ($keep ? '?' . http_build_query($keep) : '');
[$wh, $wa] = budget_where($f);
$SORT = ['d' => 'tx_date', 'n' => 'name', 'a' => 'amount', 'c' => 'category', 'u' => 'unit']; $sk = (string)($_GET['s'] ?? 'd'); if (!isset($SORT[$sk])) $sk = 'd'; $sd = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$flash = function (string $m, bool $ok = true) use ($self) { $_SESSION['bmsg'] = [$ok, $m]; redirect($self); };

// ---- ייצוא CSV (באותו פורמט של הייבוא) ----
if (($_GET['csv'] ?? '') === '1') {
  $q = db()->prepare("SELECT * FROM budget_tx $wh ORDER BY tx_date, id"); $q->execute($wa);
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="budget-' . date('Y-m-d') . '.csv"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['תאריך', 'שם', 'תיאור', 'אסמכתה', 'סכום', 'קטגוריה', 'יחידה', 'סוג', 'חשבונית', 'קבלה'], ',', '"', ''); $n = 0;
  foreach ($q as $r) { $n++; fputcsv($o, array_map('csv_safe', [date('d/m/Y', strtotime($r['tx_date'])), $r['name'], $r['descr'], $r['ref'], (string)$r['amount'], $r['category'], $r['unit'], $r['is_income'] ? 'הכנסה' : 'הוצאה', $r['has_invoice'] ? 'כן' : '', $r['has_receipt'] ? 'כן' : '']), ',', '"', ''); }
  audit('budget_export', 'budget', '', "ייצוא $n תנועות"); exit;
}

if (($_GET['pdf'] ?? '') === '1') {   // ייצוא PDF של התנועות לפי הסינון
  $q = db()->prepare("SELECT * FROM budget_tx $wh ORDER BY tx_date, id LIMIT 3000"); $q->execute($wa); $all = $q->fetchAll();
  $d = new PdfDoc(dirname(__DIR__, 2) . '/private/fonts');
  $fit = function (string $t, float $sz, float $mw) use ($d): string { $t = trim(preg_replace('/\s+/u', ' ', $t)); if ($d->width($t, $sz) <= $mw) return $t; while ($t !== '' && $d->width($t . '…', $sz) > $mw) $t = mb_substr($t, 0, -1); return $t . '…'; };
  $cols = [['תאריך', 50, 52], ['שם', 110, 112], ['תיאור', 110, 112], ['סכום', 62, 64], ['קטגוריה', 100, 102], ['יחידה', 40, 42]];   // [כותרת, רוחב טקסט, רוחב עמודה]
  $R = 555.0; $y = 0.0; $pg = 0; $inc = 0.0; $exp = 0.0; foreach ($all as $r) { if ($r['is_income']) $inc += (float)$r['amount']; else $exp += (float)$r['amount']; }
  $head = function () use ($d, &$y, &$pg, $cols, $R, $all, $inc, $exp) {
    $d->addPage(); $pg++; $y = 46.0;
    $d->text('תקציב – תנועות', $R, $y, 16, 'b', 'r'); $d->text(date('d/m/Y'), 40, $y, 9, 'r', 'l'); $y += 18;
    if ($pg === 1) { $d->text(count($all) . ' תנועות · הוצאות ' . number_format($exp, 2) . ' · הכנסות ' . number_format($inc, 2), $R, $y, 10, 'r', 'r'); $y += 18; }
    $x = $R; foreach ($cols as $c) { $d->text($c[0], $x, $y, 9, 'b', 'r'); $x -= $c[2]; } $y += 5; $y += 10;
  };
  $head();
  foreach ($all as $r) {
    if ($y > 800) { $head(); }
    $vals = [date('d/m/Y', strtotime($r['tx_date'])), $r['name'], $r['descr'], ($r['is_income'] ? '+' : '') . number_format((float)$r['amount'], 2), $r['category'], $r['unit']];
    $x = $R; foreach ($cols as $i => $c) { $d->text($fit((string)$vals[$i], 8, (float)$c[1]), $x, $y, 8, 'r', 'r'); $x -= $c[2]; } $y += 13;
  }
  audit('budget_export', 'budget', '', 'PDF ' . count($all) . ' תנועות');
  header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="budget-' . date('Y-m-d') . '.pdf"'); header('X-Content-Type-Options: nosniff'); echo $d->output(); exit;
}

// ---- פעולות ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $act = (string)($_POST['act'] ?? ''); $pdo = db();
  if (isset($_POST['del'])) { $act = 'del'; $_POST['id'] = $_POST['del']; }
  if ($act === 'add' || $act === 'update') {
    $d = budget_date((string)($_POST['d'] ?? '')); $a = budget_amount((string)($_POST['a'] ?? '')); $n = mb_substr(trim((string)($_POST['n'] ?? '')), 0, 150);
    $e2 = mb_substr(trim((string)($_POST['e'] ?? '')), 0, 300); $r = mb_substr(trim((string)($_POST['r'] ?? '')), 0, 100);
    $c = (string)($_POST['c'] ?? ''); $u = (string)($_POST['u'] ?? ''); $inc = ($_POST['t'] ?? '') === 'in' ? 1 : 0;
    if ($d === '' || $a === null || $n === '') $flash('יש למלא תאריך תקין, שם וסכום תקין.', false);
    if ($c !== '' && !in_array($c, $cats, true)) $flash('קטגוריה לא קיימת.', false);
    if ($c === '') $c = budget_auto($n, $e2);
    $u = budget_unit_for($u, $c); $iv = isset($_POST['inv']) ? 1 : 0; $rc = isset($_POST['rec']) ? 1 : 0; $pd = $inc ? 1 : (isset($_POST['paid']) ? 1 : 0); $hp = bpay_ready();
    if ($act === 'add') {
      $pdo->prepare('INSERT INTO budget_tx (tx_date,name,descr,ref,amount,is_income,category,unit,has_invoice,has_receipt,created_at,created_by' . ($hp ? ',paid' : '') . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?' . ($hp ? ',?' : '') . ')')->execute(array_merge([$d, $n, $e2, $r, $a, $inc, $c, $u, $iv, $rc, now(), mb_substr($by, 0, 100)], $hp ? [$pd] : []));
      audit('budget_add', 'budget', (string)$pdo->lastInsertId(), ($inc ? 'הכנסה ' : 'הוצאה ') . $n . ' ' . $a); $flash('התנועה נוספה.');
    } else {
      $id = (int)($_POST['id'] ?? 0);
      $st = $pdo->prepare('UPDATE budget_tx SET tx_date=?,name=?,descr=?,ref=?,amount=?,is_income=?,category=?,unit=?,has_invoice=?,has_receipt=?' . ($hp ? ',paid=' . (int)$pd : '') . ',updated_at=? WHERE id=?'); $st->execute([$d, $n, $e2, $r, $a, $inc, $c, $u, $iv, $rc, now(), $id]);
      audit('budget_edit', 'budget', (string)$id, $n . ' ' . $a); $_SESSION['bmsg'] = [true, 'התנועה עודכנה.']; redirect($self);
    }
  } elseif ($act === 'topay') {   // העברת הוצאה (שטרם שולמה) לחלונית "לתשלום"
    $id = (int)($_POST['id'] ?? 0); $q = $pdo->prepare('SELECT * FROM budget_tx WHERE id=? AND is_income=0'); $q->execute([$id]); $x = $q->fetch();
    if (!$x || !bpay_ready()) $flash('התנועה לא נמצאה (אפשר להעביר לתשלום רק הוצאה).', false);
    $pid = bpay_add(['supplier' => (string)$x['name'], 'biz_id' => '', 'bank' => '', 'branch' => '', 'account' => '', 'ref' => (string)$x['ref'], 'inv_date' => (string)$x['tx_date'], 'due_date' => '', 'descr' => (string)$x['descr'], 'category' => (string)$x['category'], 'total' => (float)$x['amount']], $by);
    $pdo->prepare('UPDATE budget_docs SET payable_id = ?, tx_id = NULL WHERE tx_id = ?')->execute([$pid, $id]);
    $pdo->prepare('DELETE FROM budget_tx WHERE id = ?')->execute([$id]); audit('budget_topay', 'budget', (string)$id, $x['name'] . ' ' . $x['amount']);
    $_SESSION['bmsg'] = [true, 'ההוצאה הועברה לחלונית "לתשלום". יש להשלים שם פרטי בנק ולסמן תשלום.']; redirect($self);
  } elseif ($act === 'del') {
    $id = (int)($_POST['id'] ?? 0); $q = $pdo->prepare('SELECT name, amount FROM budget_tx WHERE id=?'); $q->execute([$id]); $x = $q->fetch();
    if ($x) { $pdo->prepare('DELETE FROM budget_tx WHERE id=?')->execute([$id]); audit('budget_del', 'budget', (string)$id, $x['name'] . ' ' . $x['amount']); $flash('התנועה נמחקה.'); }
    $flash('התנועה לא נמצאה.', false);
  } elseif ($act === 'doc_up' || $act === 'doc_del') {   // העלאה / הסרה של חשבונית או קבלה לתנועה (נקרא מהדפדפן)
    header('Content-Type: application/json; charset=utf-8'); if (!bpay_ready()) { http_response_code(422); echo json_encode(['ok' => false, 'msg' => 'יש להריץ עדכון מסד נתונים']); exit; }
    if ($act === 'doc_del') { $ok = bdoc_delete((int)($_POST['doc'] ?? 0)); audit('budget_doc_del', 'budget', (string)(int)($_POST['doc'] ?? 0), ''); echo json_encode(['ok' => $ok]); exit; }
    $id = (int)($_POST['id'] ?? 0); $k = ($_POST['k'] ?? '') === 'rec' ? 'rec' : 'inv'; $q = $pdo->prepare('SELECT id FROM budget_tx WHERE id = ?'); $q->execute([$id]);
    if (!$q->fetch() || empty($_FILES['file'])) { http_response_code(422); echo json_encode(['ok' => false, 'msg' => 'התנועה או הקובץ לא נמצאו']); exit; }
    [$did, $er] = bdoc_save($_FILES['file'], $k, $id, null, $by); if (!$did) { http_response_code(422); echo json_encode(['ok' => false, 'msg' => $er]); exit; }
    $pdo->prepare('UPDATE budget_tx SET ' . ($k === 'rec' ? 'has_receipt' : 'has_invoice') . ' = 1, updated_at = ? WHERE id = ?')->execute([now(), $id]); audit('budget_doc_up', 'budget', (string)$id, $k);
    $d = $pdo->prepare('SELECT id, orig_name FROM budget_docs WHERE id = ?'); $d->execute([$did]); echo json_encode(['ok' => true, 'html' => bdoc_html([$d->fetch()])], JSON_UNESCAPED_UNICODE); exit;
  } elseif ($act === 'qsave') {   // שמירה אוטומטית של שורה אחת (נקרא מהדפדפן בכל שינוי בקטגוריה / חשבונית / קבלה)
    header('Content-Type: application/json; charset=utf-8'); $id = (int)($_POST['id'] ?? 0); $fld = (string)($_POST['f'] ?? ''); $val = (string)($_POST['v'] ?? '');
    $q = $pdo->prepare('SELECT id, name, category, has_invoice, has_receipt FROM budget_tx WHERE id = ?'); $q->execute([$id]); $r = $q->fetch();
    if (!$r || !in_array($fld, ['c', 'inv', 'rec', 'paid'], true) || ($fld === 'c' && $val !== '' && !in_array($val, $cats, true))) { http_response_code(422); echo json_encode(['ok' => false, 'msg' => 'התנועה או הערך לא תקינים']); exit; }
    $out = ['ok' => true, 'learned' => 0, 'applied' => 0, 'generic' => false, 'msg' => 'נשמר'];
    if ($fld === 'c') {
      $pdo->prepare('UPDATE budget_tx SET category = ?, updated_at = ? WHERE id = ?')->execute([$val, now(), $id]); audit('budget_save', 'budget', (string)$id, 'קטגוריה: ' . $val);
      if ($r['category'] === '' && $val !== '' && !empty($_POST['learn'])) { $n2 = budget_learn((string)$r['name'], $val); if ($n2 >= 0) { $out['learned'] = 1; $out['applied'] = $n2; } elseif ($n2 === -2) $out['generic'] = true; }
      $out['msg'] = $out['learned'] ? 'נשמר, והמערכת למדה כלל חדש לפי שם הספק' . ($out['applied'] ? ' ושייכה עוד ' . $out['applied'] . ' תנועות דומות' : '') : ($out['generic'] ? 'נשמר (שם כללי של הבנק, לא נלמד ככלל)' : 'נשמר');
    } else {
      $col = ['inv' => 'has_invoice', 'rec' => 'has_receipt', 'paid' => 'paid'][$fld]; $pdo->prepare("UPDATE budget_tx SET $col = ?, updated_at = ? WHERE id = ?")->execute([$val === '1' ? 1 : 0, now(), $id]);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
  } elseif ($act === 'save') {
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? [])))); $cat = (array)($_POST['cat'] ?? []); $inv = (array)($_POST['inv'] ?? []); $rec = (array)($_POST['rec'] ?? []); $ch = 0;
    if ($ids) {
      $q = $pdo->prepare('SELECT id, category, has_invoice, has_receipt FROM budget_tx WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute($ids);
      $up = $pdo->prepare('UPDATE budget_tx SET category=?, has_invoice=?, has_receipt=?, updated_at=? WHERE id=?'); $learn = []; $nm = $pdo->prepare('SELECT name FROM budget_tx WHERE id=?');
      foreach ($q->fetchAll() as $r) {
        $id = (int)$r['id']; $c = (string)($cat[$id] ?? $r['category']); if ($c !== '' && !in_array($c, $cats, true)) $c = (string)$r['category'];
        $iv = isset($inv[$id]) ? 1 : 0; $rc = isset($rec[$id]) ? 1 : 0;
        if ($c !== $r['category'] || $iv !== (int)$r['has_invoice'] || $rc !== (int)$r['has_receipt']) { $up->execute([$c, $iv, $rc, now(), $id]); $ch++; }
        if ($r['category'] === '' && $c !== '' && !empty($_POST['learn'])) { $nm->execute([$id]); $learn[(string)$nm->fetchColumn()] = $c; }
      }
    }
    $lr = 0; $lx = 0; $lg = 0; foreach ($learn ?? [] as $kw => $c) { $n2 = budget_learn((string)$kw, $c); if ($n2 >= 0) { $lr++; $lx += $n2; } elseif ($n2 === -2) $lg++; }
    if ($ch) audit('budget_save', 'budget', '', "עודכנו $ch תנועות (קטגוריה/חשבונית/קבלה)" . ($lr ? ", נלמדו $lr כללים" : ''));
    $flash($ch ? "נשמרו שינויים ב-$ch תנועות." . ($lr ? " המערכת למדה $lr כללי שיוך חדשים" . ($lx ? " ושייכה אוטומטית עוד $lx תנועות דומות" : '') . '.' : '') . ($lg ? " ($lg שמות כלליים של הבנק, כמו \"העברה באינטרנט\", לא נלמדו כדי לא לשייך בטעות. אפשר להוסיף להם כלל ב\"קטגוריות וכללים\".)" : '') : 'לא היו שינויים.');
  } elseif ($act === 'bulk') {
    $c = (string)($_POST['c'] ?? ''); if ($c === '' || !in_array($c, $cats, true)) $flash('בחרי קטגוריה לשיוך.', false);
    $st = $pdo->prepare("UPDATE budget_tx SET category=?, updated_at=? $wh"); $st->execute(array_merge([$c, now()], $wa)); $n = $st->rowCount();
    audit('budget_bulk', 'budget', '', "שויכו $n תנועות לקטגוריה $c"); $flash("$n תנועות שויכו לקטגוריה \"$c\".");
  } elseif ($act === 'pdf_preview') {
    $j = json_decode((string)($_POST['rows'] ?? ''), true);
    if (!is_array($j) || !$j) $flash('לא התקבלו תנועות מה-PDF.', false);
    if (count($j) > BUDGET_MAX_IMPORT) $flash('יותר מדי שורות בקובץ (מקסימום ' . BUDGET_MAX_IMPORT . ').', false);
    $items = budget_prepare($j);
    if (!$items) $flash('לא נמצאו בקובץ תנועות תקינות (תאריך, שם וסכום).', false);
    $_SESSION['bpdf'] = $items; unset($_SESSION['bpdf_kind']); audit('budget_pdf_preview', 'budget', '', count($items) . ' שורות'); redirect('budget_tx.php?pdf=1#pdfprev');
  } elseif ($act === 'pdf_cancel') { unset($_SESSION['bpdf'], $_SESSION['bpdf_kind']); $flash('הייבוא בוטל. לא נשמר דבר.');
  } elseif ($act === 'pdf_apply') {
    $items = $_SESSION['bpdf'] ?? null; if (!is_array($items)) $flash('פג תוקף התצוגה המקדימה. העלי את הקובץ שוב.', false);
    [$ad, $un, $ud, $rp] = budget_commit($items, array_map('intval', (array)($_POST['pick'] ?? [])), $by); unset($_SESSION['bpdf'], $_SESSION['bpdf_kind']);
    audit('budget_import', 'budget', '', "PDF/אשראי: יובאו $ad, עודכנו $ud שורות העברה באינטרנט, דביטים שפורטו $rp, ללא שיוך $un");
    $flash("יובאו $ad תנועות מה-PDF" . ($ud ? " ופורטו $ud שורות \"העברה באינטרנט\" מדוח הבנק" : '') . ($rp ? ". $rp שורות \"דביט כא\"ל\" מדוח הבנק הוחלפו ברכישות עצמן" : '') . ($un ? ". $un מהן לא שויכו אוטומטית. כדי לשייך אותן ידנית (והמערכת תלמד לפעם הבאה) יש לסנן קטגוריה \"לא משויך\"." : ' ושויכו כולן אוטומטית.'));
  } elseif ($act === 'import') {   // כמה קבצים יחד: דוחות בנק / אשראי (xlsx, csv, xls) ו-PDF (נקראים בדפדפן ומגיעים ב-rows)
    $F = $_FILES['file'] ?? null; $fls = [];
    if ($F && is_array($F['name'])) foreach ($F['name'] as $k => $nm) $fls[] = ['name' => (string)$nm, 'tmp_name' => (string)$F['tmp_name'][$k], 'error' => (int)$F['error'][$k], 'size' => (int)$F['size'][$k]];
    elseif ($F) $fls[] = $F;
    $fls = array_values(array_filter($fls, fn($f) => (int)($f['error'] ?? 4) !== UPLOAD_ERR_NO_FILE));
    $rowsJ = json_decode((string)($_POST['rows'] ?? ''), true); $rowsJ = is_array($rowsJ) ? $rowsJ : [];
    if (!$fls && !$rowsJ) $flash('בחרי קובץ לייבוא.', false);
    if (count($fls) > 20) $flash('אפשר לייבא עד 20 קבצים בכל פעם.', false);
    if (count($rowsJ) > BUDGET_MAX_IMPORT) $flash('יותר מדי שורות ב-PDF (מקסימום ' . BUDGET_MAX_IMPORT . ').', false);
    $ad = $du = $bd = 0; $nf = 0; $errs = []; $cards = [];
    foreach ($fls as $fl) {
      $nm = (string)$fl['name'];
      if ((int)$fl['error'] !== UPLOAD_ERR_OK) { $errs[] = $nm . ': ' . upload_error_text((int)$fl['error']); continue; }
      if (!preg_match('/\.(xlsx|csv|xls|htm|html)$/i', $nm)) { $errs[] = $nm . ': סוג קובץ לא נתמך'; continue; }
      if ((int)$fl['size'] > 10 * 1024 * 1024) { $errs[] = $nm . ': הקובץ גדול מדי (מעל 10MB)'; continue; }
      if (!is_uploaded_file($fl['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) { $errs[] = $nm . ': קובץ לא תקין'; continue; }
      if (preg_match('/\.xlsx$/i', $nm)) {   // דוח פירוט עסקאות של חברת האשראי: ממתין לתצוגה מקדימה והתאמה לדביטים
        try { $card = budget_card_rows(xlsx_rows((string)$fl['tmp_name'])); } catch (Throwable $t) { $card = null; }
        if ($card) {
          if (!budget_has_card()) { $errs[] = $nm . ': כדי לייבא דוח אשראי יש להריץ קודם "עדכון מסד נתונים" (מנהל מערכת)'; continue; }
          if (isset($cards[$card['last4']])) $cards[$card['last4']]['tx'] = array_merge($cards[$card['last4']]['tx'], $card['tx']); else $cards[$card['last4']] = $card;
          $nf++; continue;
        }
      }
      try { [$a1, $d1, $b1] = budget_import((string)$fl['tmp_name'], $nm, $by); $ad += $a1; $du += $d1; $bd += $b1; $nf++; }
      catch (Throwable $t) { $errs[] = $nm . ': ' . $t->getMessage(); }
    }
    $items = $rowsJ ? budget_prepare($rowsJ) : [];   // PDF: אחרי דוחות הבנק, כדי לפרט את שורות "העברה באינטרנט" שזה עתה יובאו
    foreach ($cards as $c0) $items = array_merge($items, budget_card_prepare($c0));
    audit('budget_import', 'budget', '', "קבצים: $nf, יובאו $ad, כפולות $du, לא תקינות $bd" . ($cards ? ', דוחות אשראי: ' . count($cards) : '') . ($rowsJ ? ', PDF: ' . count($rowsJ) . ' שורות' : '') . ($errs ? ', שגיאות: ' . count($errs) : ''));
    $sum = ($nf || $rowsJ ? 'יובאו ' . $ad . ' תנועות' . ($du ? ", דולגו $du שורות שכבר קיימות" : '') . ($bd ? ", $bd שורות לא תקינות (תאריך או סכום חסרים) לא יובאו" : '') . '.' : '') . ($errs ? ' לא יובאו: ' . implode(' · ', array_slice($errs, 0, 6)) . (count($errs) > 6 ? '…' : '') : '');
    if ($items) {
      $_SESSION['bpdf'] = $items; $_SESSION['bpdf_kind'] = ($cards ? ($rowsJ ? 'mix' : 'card') : ''); $_SESSION['bmsg'] = [!$errs, trim(($ad || $du || $bd || $errs ? $sum . ' ' : '') . 'יש תנועות שממתינות לאישור בתצוגה המקדימה למטה.')];
      redirect('budget_tx.php?pdf=1#pdfprev');
    }
    if (!$nf && !$rowsJ) $flash(trim($sum) ?: 'לא יובא דבר.', false);
    if ($rowsJ && !$items) $errs[] = 'ב-PDF לא נמצאו תנועות תקינות (תאריך, שם וסכום)';
    $flash(trim($sum) ?: 'לא יובא דבר.', !$errs);
  }
  redirect($self);
}

// ---- נתונים לתצוגה ----
$msg = $_SESSION['bmsg'] ?? null; unset($_SESSION['bmsg']);
$q = db()->prepare("SELECT COUNT(*) n, SUM(CASE WHEN is_income=0 THEN amount ELSE 0 END) ex, SUM(CASE WHEN is_income=1 THEN amount ELSE 0 END) inc FROM budget_tx $wh"); $q->execute($wa); $tot = $q->fetch();
$per = 200; $pages = max(1, (int)ceil((int)$tot['n'] / $per)); $p = min($pages, max(1, (int)($_GET['p'] ?? 1)));
$q = db()->prepare("SELECT * FROM budget_tx $wh ORDER BY {$SORT[$sk]} $sd, id $sd LIMIT $per OFFSET " . (($p - 1) * $per)); $q->execute($wa); $rows = $q->fetchAll(); $hasPay = bpay_ready(); $bdocs = $hasPay ? bdoc_by_tx(array_map('intval', array_column($rows, 'id'))) : [];
$editId = (int)($_GET['edit'] ?? 0); $ed = null;
if ($editId) { $q = db()->prepare('SELECT * FROM budget_tx WHERE id=?'); $q->execute([$editId]); $ed = $q->fetch() ?: null; }
$showForm = $ed || isset($_GET['add']);
$sortLink = function (string $k, string $l) use ($keep, $sk, $sd) { $o = $keep; unset($o['p']); $o['s'] = $k; $o['dir'] = ($sk === $k && $sd === 'asc') ? 'desc' : 'asc'; return '<a href="budget_tx.php?' . e(http_build_query($o)) . '">' . e($l) . ($sk === $k ? ($sd === 'asc' ? ' ▲' : ' ▼') : '') . '</a>'; };
$pg = function (int $n) use ($keep) { $o = $keep; $o['p'] = $n; return 'budget_tx.php?' . http_build_query($o); };
$opt = fn(array $list, string $sel, string $first = '') => ($first !== '' ? '<option value="">' . e($first) . '</option>' : '') . implode('', array_map(fn($x) => '<option' . ($x === $sel ? ' selected' : '') . '>' . e($x) . '</option>', $list));
$v = fn(string $k, $d = '') => $ed ? $ed[$k] : $d;
$fields = function (string $A, ?array $E) use ($cats, $units, $opt): string { $v = fn(string $k, $d = '') => $E ? $E[$k] : $d; $ed = $E; ob_start(); ?>
    <label>תאריך<input<?= $A ?> type="date" name="d" required value="<?= e($v('tx_date', date('Y-m-d'))) ?>"></label>
    <label>שם (ספק)<input<?= $A ?> name="n" required maxlength="150" value="<?= e($v('name')) ?>"></label>
    <label>תיאור<input<?= $A ?> name="e" maxlength="300" value="<?= e($v('descr')) ?>"></label>
    <label>אסמכתה<input<?= $A ?> name="r" maxlength="100" value="<?= e($v('ref')) ?>"></label>
    <label>סכום ₪<input<?= $A ?> name="a" required inputmode="decimal" value="<?= $ed ? e((string)$ed['amount']) : '' ?>"></label>
    <label>סוג<select<?= $A ?> name="t"><option value="out">הוצאה</option><option value="in"<?= $ed && $ed['is_income'] ? ' selected' : '' ?>>הכנסה</option></select></label>
    <label>קטגוריה<select<?= $A ?> name="c"><?= $opt($cats, (string)$v('category'), 'שיוך אוטומטי / ללא') ?></select></label>
    <label>יחידה<select<?= $A ?> name="u"><?= $opt($units, (string)$v('unit', $units[0])) ?></select></label>
    <label class="chk"><input<?= $A ?> type="checkbox" name="inv"<?= $ed && $ed['has_invoice'] ? ' checked' : '' ?>> חשבונית</label>
    <label class="chk"><input<?= $A ?> type="checkbox" name="rec"<?= $ed && $ed['has_receipt'] ? ' checked' : '' ?>> קבלה</label>
    <?php if (bpay_ready()): ?><label class="chk"><input<?= $A ?> type="checkbox" name="paid"<?= !$ed || !empty($ed['paid']) ? ' checked' : '' ?>> שולם (הוצאה)</label><?php endif; ?>
<?php return ob_get_clean(); };
$inline = $ed && in_array((int)$ed['id'], array_map('intval', array_column($rows, 'id')), true);   // העריכה מוצגת בתוך השורה עצמה, כדי שתמיד תהיה בטווח הראייה
page_head('תקציב – תנועות');
?>
<h1>תקציב – תנועות</h1>
<?php if ($msg) echo '<p class="' . ($msg[0] ? 'ok' : 'er') . '">' . e($msg[1]) . '</p>'; ?>
<details class="card" id="txform"<?= ($showForm && !$inline) ? ' open' : '' ?>><summary><b><?= $ed && !$inline ? 'עריכת תנועה' : 'הוספת הוצאה או הכנסה' ?></b></summary>
  <form method="post" class="bar" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="<?= $ed && !$inline ? 'update' : 'add' ?>"><?php if ($ed && !$inline) echo '<input type="hidden" name="id" value="' . (int)$ed['id'] . '">'; ?>
    <?= $fields('', $inline ? null : $ed) ?>
    <button><?= $ed ? 'שמירת שינויים' : 'הוספה' ?></button><?php if ($ed) echo '<a class="btn sec" href="' . e($self) . '">ביטול</a>'; ?>
  </form>
  <p class="mu">אם לא נבחרה קטגוריה, המערכת משייכת לפי מילות מפתח בשם ובתיאור (אפשר לערוך את הכללים בדף "קטגוריות").</p>
</details>
<form method="get" class="bar auto card"><?= budget_year_select($f) ?>
  <label>חיפוש<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="שם, תיאור, סכום…"></label>
  <label>קטגוריה<select name="cat"><option value="">הכול</option><option value="__un"<?= $f['cat'] === '__un' ? ' selected' : '' ?>>לא משויך</option><?= $opt($cats, $f['cat']) ?></select></label>
  <label>יחידה<select name="unit"><?= $opt($units, $f['unit'], 'הכול') ?></select></label>
  <label>סוג<select name="type"><option value="">הכול</option><option value="out"<?= $f['type'] === 'out' ? ' selected' : '' ?>>הוצאות</option><option value="in"<?= $f['type'] === 'in' ? ' selected' : '' ?>>הכנסות</option></select></label>
  <label>מסמכים<select name="doc"><?php foreach ([''=>'הכול','ni'=>'חסרה חשבונית','nr'=>'חסרה קבלה','nb'=>'חסרים שניהם','ok'=>'יש חשבונית וקבלה'] as $k => $l) echo '<option value="' . $k . '"' . ($f['doc'] === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></label>
  <?php if ($f['month']) echo '<input type="hidden" name="month" value="' . e($f['month']) . '"><span class="pill">חודש ' . e(substr($f['month'], 5) . '/' . substr($f['month'], 0, 4)) . ' <a href="' . e('budget_tx.php?' . http_build_query(array_diff_key($keep, ['month' => 1, 'p' => 1]))) . '">הסרה</a></span>'; ?>
</form>
<p><b><?= (int)$tot['n'] ?></b> תנועות · הוצאות <b><?= e(budget_money0($tot['ex'])) ?></b> · הכנסות <b class="ok"><?= e(budget_money0($tot['inc'])) ?></b> · <a href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'csv=1') ?>">ייצוא CSV (לפי הסינון)</a> · <a href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'pdf=1') ?>">ייצוא PDF (לפי הסינון)</a></p>
<?php if ($rows): ?>
<form method="post" class="autosave" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="save">
<div class="bar"><span class="asv mu" role="status" aria-live="polite">שינויים בקטגוריה, בחשבונית ובקבלה נשמרים אוטומטית.</span> <label class="inl"><input type="checkbox" name="learn" value="1" checked> זכרי שיוך של תנועה שלא שויכה (לפי שם הספק) גם לפעם הבאה</label></div>
<div class="card sc"><table>
<tr><th><?= $sortLink('d', 'תאריך') ?></th><th><?= $sortLink('n', 'שם') ?></th><th>תיאור</th><th>אסמכתה</th><th><?= $sortLink('a', 'סכום') ?></th><th><?= $sortLink('c', 'קטגוריה') ?></th><th><?= $sortLink('u', 'יחידה') ?></th><th>חשבונית</th><th>קבלה</th><?= $hasPay ? '<th>שולם</th>' : '' ?><th></th></tr>
<?php foreach ($rows as $r): $id = (int)$r['id']; ?>
<tr<?= $r['category'] === '' ? ' class="unm"' : '' ?>><td class="n"><?= e(date('d/m/Y', strtotime($r['tx_date']))) ?><input type="hidden" name="ids[]" value="<?= $id ?>"></td><td><?= $r['is_income'] ? e($r['name']) : '<a href="budget_suppliers.php?y=all&amp;s=' . rawurlencode(trim($r['name'])) . '">' . e($r['name']) . '</a>' ?></td><td><?= e($r['descr']) ?></td><td><?= e($r['ref']) ?></td>
<td class="n<?= $r['is_income'] ? ' ok' : '' ?>"><bdi dir="ltr"><?= $r['is_income'] ? '+' : '' ?><?= e(budget_money($r['amount'])) ?></bdi></td>
<td><select name="cat[<?= $id ?>]"><?= $opt($cats, $r['category'], '— לא משויך —') ?></select></td><td><?= e($r['unit']) ?></td>
<?php foreach (['inv' => ['has_invoice', 'חשבונית'], 'rec' => ['has_receipt', 'קבלה']] as $k => [$col, $lb]): ?><td class="bdc"><input type="checkbox" name="<?= $k ?>[<?= $id ?>]" value="1"<?= $r[$col] ? ' checked' : '' ?> aria-label="<?= $lb ?>"><?php if ($hasPay): ?><span class="bdu<?= $r[$col] ? ' on' : '' ?>" data-tx="<?= $id ?>" data-k="<?= $k ?>"><?= bdoc_html($bdocs[$id][$k] ?? []) ?><label class="bdup">+ העלאה<input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" hidden></label></span><?php endif; ?></td><?php endforeach; ?>
<?php if ($hasPay): ?><td><?php if (!$r['is_income']): ?><input type="checkbox" name="paid[<?= $id ?>]" value="1"<?= $r['paid'] ? ' checked' : '' ?> aria-label="שולם"><?php endif; ?></td><?php endif; ?>
<td class="n"><a href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'edit=' . $id) ?>#edrow">עריכה</a> <button class="dg" name="del" value="<?= $id ?>" data-confirm="למחוק את התנועה?" formnovalidate>מחיקה</button></td></tr>
<?php if ($inline && (int)$ed['id'] === $id): ?><tr class="edr" id="edrow"><td colspan="<?= $hasPay ? 11 : 10 ?>"><b>עריכת תנועה</b><div class="bar edf"><?= $fields(' form="edform"', $ed) ?>
  <button form="edform">שמירת שינויים</button><a class="btn sec" href="<?= e($self) ?>">ביטול</a>
  <?php if ($hasPay && !$ed['is_income']): ?><button form="edtp" class="sec" data-confirm="להעביר את ההוצאה לחלונית &quot;לתשלום&quot;? היא תוסר מרשימת התנועות ותחזור אליה רק אחרי שתסומן כשולמה.">העברה ל&quot;לתשלום&quot;</button><?php endif; ?></div></td></tr><?php endif; ?>
<?php endforeach; ?>
</table></div>
</form>
<?php if ($inline): ?><form method="post" id="edform" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="update"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"></form>
<form method="post" id="edtp" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="topay"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"></form><?php endif; ?>
<?php if ($pages > 1): ?><p>עמוד <?= $p ?> מתוך <?= $pages ?> · <?php if ($p > 1) echo '<a href="' . e($pg($p - 1)) . '">הקודם</a> '; if ($p < $pages) echo '<a href="' . e($pg($p + 1)) . '">הבא</a>'; ?></p><?php endif; ?>
<form method="post" class="bar card" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="bulk">
  <label>שיוך כל התנועות שמוצגות בסינון (<?= (int)$tot['n'] ?>) לקטגוריה<select name="c"><?= $opt($cats, '', '— בחרי —') ?></select></label><button class="sec" data-confirm="לשייך את כל <?= (int)$tot['n'] ?> התנועות שבסינון לקטגוריה שנבחרה?">שייכי</button></form>
<?php else: ?><div class="card"><p>לא נמצאו תנועות.</p></div><?php endif; ?>
<?php $pv = $_SESSION['bpdf'] ?? null; if (is_array($pv) && isset($_GET['pdf'])): $nNew = count(array_filter($pv, fn($x) => !$x['dup'])); $nUn = count(array_filter($pv, fn($x) => !$x['dup'] && $x['cat'] === '')); $nUp = count(array_filter($pv, fn($x) => !$x['dup'] && !empty($x['upd']))); $kind = (string)($_SESSION['bpdf_kind'] ?? ''); $nGr = count(array_filter($pv, fn($x) => !$x['dup'] && !empty($x['grp']))); ?>
<form method="post" class="card" action="budget_tx.php" id="pdfprev"><?= csrf_field() ?>
  <h2>תצוגה מקדימה – <?= $kind === 'card' ? 'ייבוא דוח אשראי' : ($kind === 'mix' ? 'ייבוא קבצים' : 'ייבוא מ-PDF') ?></h2>
  <?php if ($nGr): ?><p class="mu"><?= $nGr ?> מהרכישות הותאמו לשורות "דביט כא\"ל" מדוח הבנק (הסכום הכולל זהה בדיוק): שורת הדביט תוחלף ברכישות עצמן, כך שלא יהיה כפל. רכישות שלא הותאמו יתווספו כרגיל, ואם דוח הבנק ייובא אחר כך הן יסתדרו מולו.</p><?php endif; ?>
  <p><b><?= count($pv) ?></b> תנועות בקובץ: <b><?= $nNew ?></b> חדשות<?= $nUp ? ' (מהן ' . $nUp . ' מפרטות שורות "העברה באינטרנט" שכבר יובאו מדוח הבנק, ולא יתווספו פעמיים)' : '' ?><?= count($pv) - $nNew ? ', ' . (count($pv) - $nNew) . ' כבר קיימות (ידולגו)' : '' ?>. <b><?= $nNew - $nUn ?></b> שויכו אוטומטית לקטגוריה<?= $nUn ? ', <b>' . $nUn . '</b> ללא שיוך (יישארו בטבלה לשיוך ידני)' : '' ?>.</p>
  <div class="sc"><table><tr><th>ייבוא</th><th>תאריך</th><th>שם</th><th>תיאור</th><th>אסמכתה</th><th>סכום</th><th>קטגוריה</th></tr>
  <?php foreach ($pv as $i => $x): ?>
    <tr<?= $x['dup'] ? ' class="mu"' : ($x['cat'] === '' ? ' class="unm"' : '') ?>><td><input type="checkbox" name="pick[]" value="<?= $i ?>" aria-label="ייבוא"<?= $x['dup'] ? ' disabled' : ' checked' ?>></td>
    <td class="n"><?= e(date('d/m/Y', strtotime($x['d']))) ?></td><td><?= e($x['n']) ?></td><td><?= e($x['e']) ?></td><td><?= e($x['r']) ?></td>
    <td class="n<?= $x['inc'] ? ' ok' : '' ?>"><bdi dir="ltr"><?= $x['inc'] ? '+' : '' ?><?= e(budget_money($x['a'])) ?></bdi></td>
    <td><?= $x['dup'] ? 'כבר קיימת' : ($x['cat'] === '' ? '— לא משויך —' : e($x['cat'])) ?><?= !empty($x['upd']) ? ' <span class="mu">· מפרטת "העברה באינטרנט" מדוח הבנק</span>' : '' ?><?= !empty($x['grp']) ? ' <span class="mu">· מחליפה ' . e($x['gl']) . '</span>' : '' ?></td></tr>
  <?php endforeach; ?></table></div>
  <p class="bar"><button name="act" value="pdf_apply" data-confirm="לייבא את <?= $nNew ?> התנועות שסומנו?">ייבוא התנועות שסומנו</button><button name="act" value="pdf_cancel" class="sec" formnovalidate>ביטול</button></p>
</form>
<?php endif; ?>
<details class="card" id="imp"><summary><b>ייבוא מ-Excel או CSV</b></summary>
  <form method="post" enctype="multipart/form-data" class="bar" action="<?= e($self) ?>" data-bpdf><?= csrf_field() ?><input type="hidden" name="act" value="import"><input type="hidden" name="rows" value=""><label>קבצים (אפשר לבחור עד 20 יחד: PDF / xlsx / xls / csv)<input type="file" name="file[]" accept=".pdf,.xlsx,.csv,.xls,.htm,.html" multiple required></label><button>ייבוא</button> <span id="bpdf-msg" class="mu" role="status"></span></form>
  <?php $nIb = (int)db()->query("SELECT COUNT(*) FROM budget_tx WHERE name = '" . BUDGET_IB . "' AND category = ''")->fetchColumn(); if ($nIb > 0): ?><p class="aptn"><b><?= $nIb ?></b> שורות "העברה באינטרנט" מדוח הבנק ממתינות לפירוט. יש להעלות כאן את <b>רשימת ההעברות</b> (PDF) מהבנק, והן יתמלאו בשם הספק, בתיאור ובקטגוריה בלי להוסיף תנועות כפולות.</p><?php endif; ?>
  <p class="mu"><b>קובץ PDF:</b> רשימת העברות או תנועות חשבון מהבנק. המערכת קוראת את התנועות, משייכת לקטגוריות לפי הכללים, ומציגה תצוגה מקדימה לאישור. מה שלא זוהה נשאר "לא משויך" לשיוך ידני.</p>
  <p class="mu">עמודות: תאריך, שם, תיאור, אסמכתה, סכום, קטגוריה, יחידה, סוג (הכנסה / הוצאה), חשבונית, קבלה. נדרשות לפחות "תאריך" ו"סכום". שורות בלי קטגוריה ישויכו אוטומטית לפי מילות מפתח. שורות זהות לתנועה קיימת ידולגו, כך שאפשר לייבא שוב קובץ שיוצא מכאן. עד <?= BUDGET_MAX_IMPORT ?> שורות בקובץ.</p>
</details>
<?php page_foot();
