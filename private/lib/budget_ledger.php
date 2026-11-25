<?php
// כרטסת ספק: חיובים (חשבוניות/הוצאות), תשלומים ויתרה רצה – כמו בתוכנת הנהלת חשבונות.
function bl_norm(string $s): string { return trim(preg_replace('/\s+/u', ' ', $s)); }
/** מפת איחוד שמות: [שם חלופי => שם ראשי]. ריקה אם הטבלה עוד לא קיימת (לפני עדכון מסד נתונים). */
function bl_aliases(bool $fresh = false): array {
  static $m = null; if ($m !== null && !$fresh) return $m; $m = [];
  try { foreach (db()->query('SELECT alias, supplier FROM budget_supplier_alias') as $r) $m[(string)$r['alias']] = (string)$r['supplier']; } catch (Throwable $t) { $m = []; }
  return $m;
}
function bl_canon(string $n): string { $n = bl_norm($n); return bl_aliases()[$n] ?? $n; }
/** כל השמות שמוצגים תחת שם ראשי (כולל הראשי). */
function bl_names(string $main): array { $main = bl_norm($main); $o = [$main]; foreach (bl_aliases() as $a => $c) if ($c === $main) $o[] = $a; return $o; }
/** איחוד: כל $names יוצגו תחת $target. */
function bl_merge(array $names, string $target, string $by): int {
  $pdo = db(); $target = bl_norm($target); $n = 0; $names = array_values(array_unique(array_filter(array_map('bl_norm', $names), fn($x) => $x !== '' && $x !== $target)));
  $pdo->prepare('DELETE FROM budget_supplier_alias WHERE alias = ?')->execute([$target]);
  foreach ($names as $a) {
    $pdo->prepare('REPLACE INTO budget_supplier_alias (alias, supplier, created_by, created_at) VALUES (?,?,?,?)')->execute([$a, $target, mb_substr($by, 0, 100), now()]);
    $pdo->prepare('UPDATE budget_supplier_alias SET supplier = ? WHERE supplier = ?')->execute([$target, $a]); $n++;   // מי שהיה מאוחד לשם הזה עובר לשם החדש
  }
  bl_aliases(true); return $n;
}

/** רשימת ספקים עם סיכומים לתקופה: [שם => [charge, paid, inv (מס' חשבוניות), open (מס' חשבוניות פתוחות)]] */
function bl_suppliers(?array $range): array {
  $pdo = db(); $out = []; $add = function (string $n, float $c, float $p, int $inv, int $open) use (&$out) { $n = bl_canon($n); if ($n === '') return; $o = &$out[$n]; $o = ($o ?? ['charge' => 0.0, 'paid' => 0.0, 'inv' => 0, 'open' => 0]); $o['charge'] += $c; $o['paid'] += $p; $o['inv'] += $inv; $o['open'] += $open; };
  $rw = $range ? ' AND d BETWEEN ? AND ?' : ''; $ra = $range ?: [];
  $q = $pdo->prepare("SELECT supplier n, SUM(total) c, COUNT(*) k, SUM(status = 'open') o FROM (SELECT supplier, total, status, COALESCE(inv_date, DATE(created_at)) d FROM budget_payables) x WHERE 1=1 $rw GROUP BY supplier"); $q->execute($ra);
  foreach ($q as $r) $add((string)$r['n'], (float)$r['c'], 0, (int)$r['k'], (int)$r['o']);
  $q = $pdo->prepare("SELECT p.supplier n, SUM(l.amount) s FROM budget_pay_log l JOIN budget_payables p ON p.id = l.payable_id WHERE 1=1 " . ($range ? ' AND l.paid_on BETWEEN ? AND ?' : '') . ' GROUP BY p.supplier'); $q->execute($ra);
  foreach ($q as $r) $add((string)$r['n'], 0, (float)$r['s'], 0, 0);
  $q = $pdo->prepare("SELECT name n, SUM(amount) c, SUM(CASE WHEN paid = 1 THEN amount ELSE 0 END) p FROM budget_tx WHERE is_income = 0 AND payable_id IS NULL" . ($range ? ' AND tx_date BETWEEN ? AND ?' : '') . ' GROUP BY name'); $q->execute($ra);
  foreach ($q as $r) $add((string)$r['n'], (float)$r['c'], (float)$r['p'], 0, 0);
  return $out;
}

/** כרטסת ספק: שורות עם יתרה רצה. מחזיר [rows, opening, totals, info] */
function bl_ledger(string $name, ?array $range): array {
  $pdo = db(); $name = bl_canon($name); $E = []; $nm = bl_names($name); $ph = implode(',', array_fill(0, count($nm), '?'));
  $q = $pdo->prepare("SELECT * FROM budget_payables WHERE TRIM(supplier) IN ($ph)"); $q->execute($nm); $pays = $q->fetchAll(); $ids = array_map(fn($r) => (int)$r['id'], $pays);
  $info = ['biz' => '', 'bank' => '', 'branch' => '', 'account' => '']; foreach (array_reverse($pays) as $p) { foreach ($info as $k => $_) if ($info[$k] === '' && ($k === 'biz' ? $p['biz_id'] : $p[$k]) !== '') $info[$k] = $k === 'biz' ? $p['biz_id'] : $p[$k]; }
  foreach ($pays as $p) {
    $d = $p['inv_date'] ?: substr((string)$p['created_at'], 0, 10); $st = $p['status'] === 'paid' ? 'שולמה' : ((float)$p['paid_amount'] > 0 ? 'שולמה חלקית' : 'ממתינה לתשלום');
    $E[] = ['d' => $d, 'o' => 0, 'doc' => 'חשבונית' . ($p['ref'] !== '' ? ' ' . $p['ref'] : ''), 'txt' => trim($p['descr'] . ' · ' . $st . ($p['due_date'] ? ' · לתשלום עד ' . date('d/m/Y', strtotime($p['due_date'])) : ''), ' ·'), 'c' => (float)$p['total'], 'p' => 0.0, 'kind' => 'inv', 'open' => $p['status'] === 'open'];
  }
  if ($ids) { $in = implode(',', $ids); foreach ($pdo->query("SELECT * FROM budget_pay_log WHERE payable_id IN ($in)") as $l) $E[] = ['d' => (string)$l['paid_on'], 'o' => 1, 'doc' => 'תשלום', 'txt' => $l['note'], 'c' => 0.0, 'p' => (float)$l['amount'], 'kind' => 'pay', 'open' => false]; }
  $q = $pdo->prepare("SELECT * FROM budget_tx WHERE TRIM(name) IN ($ph) AND is_income = 0 AND payable_id IS NULL"); $q->execute($nm);
  foreach ($q as $t) $E[] = ['d' => (string)$t['tx_date'], 'o' => 2, 'doc' => $t['ref'] !== '' ? 'אסמכתה ' . $t['ref'] : 'הוצאה', 'txt' => trim(($t['descr'] !== '' ? $t['descr'] . ' · ' : '') . ($t['category'] !== '' ? $t['category'] : '') . ($t['paid'] ? '' : ' · לא שולמה'), ' ·'), 'c' => (float)$t['amount'], 'p' => $t['paid'] ? (float)$t['amount'] : 0.0, 'kind' => 'tx', 'open' => !$t['paid']];
  usort($E, fn($a, $b) => [$a['d'], $a['o']] <=> [$b['d'], $b['o']]);
  $open = 0.0; $rows = []; $tc = 0.0; $tp = 0.0; $bal = 0.0;
  foreach ($E as $e) {
    if ($range && $e['d'] < $range[0]) { $open += $e['c'] - $e['p']; continue; }
    if ($range && $e['d'] > $range[1]) continue;
    $rows[] = $e;
  }
  $bal = $open; foreach ($rows as &$r) { $bal += $r['c'] - $r['p']; $r['bal'] = round($bal, 2); $tc += $r['c']; $tp += $r['p']; } unset($r);
  return ['rows' => $rows, 'opening' => round($open, 2), 'totals' => ['c' => round($tc, 2), 'p' => round($tp, 2), 'bal' => round($bal, 2)], 'info' => $info];
}

/** קובץ xlsx פשוט (גיליון אחד, מימין לשמאל). $rows: מערך שורות של תאים (מחרוזת/מספר). שורות עם 'b' => true בתחילת המערך המשויך מודגשות: מועבר ב-$bold (אינדקסים). */
function xlsx_build(array $rows, array $widths, array $bold = [], string $sheet = 'כרטסת'): ?string {
  if (!class_exists('ZipArchive')) return null;
  $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
  $sd = '';
  foreach ($rows as $ri => $r) {
    $sd .= '<row r="' . ($ri + 1) . '">';
    foreach ($r as $ci => $v) {
      if ($v === '' || $v === null) continue; $ref = $col($ci) . ($ri + 1); $b = in_array($ri, $bold, true);
      if (is_int($v) || is_float($v)) $sd .= '<c r="' . $ref . '" s="' . ($b ? 3 : 2) . '"><v>' . $v . '</v></c>';
      else $sd .= '<c r="' . $ref . '" t="inlineStr"' . ($b ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x((string)$v) . '</t></is></c>';
    }
    $sd .= '</row>';
  }
  $cols = ''; foreach ($widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
  $files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $sheet), 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
    'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><cols>' . $cols . '</cols><sheetData>' . $sd . '</sheetData></worksheet>',
  ];
  $tmp = tempnam(sys_get_temp_dir(), 'xl'); $z = new ZipArchive(); if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) return null;
  foreach ($files as $n => $c) $z->addFromString($n, $c); $z->close(); $b = (string)file_get_contents($tmp); @unlink($tmp); return $b;
}

/** שורות הכרטסת לייצוא: [כותרת-עליונה..., שורות] */
function bl_export_rows(string $name, array $L, string $period): array {
  $h = ['תאריך', 'מסמך', 'פרטים', 'חיוב', 'תשלום', 'יתרה לתשלום'];
  $rows = [['כרטסת ספק: ' . $name], ['תקופה: ' . $period . ($L['info']['biz'] !== '' ? ' · עוסק ' . $L['info']['biz'] : '') . ($L['info']['account'] !== '' ? ' · בנק ' . $L['info']['bank'] . '-' . $L['info']['branch'] . '-' . $L['info']['account'] : '')], [], $h];
  if (abs($L['opening']) > 0.004) $rows[] = ['', 'יתרת פתיחה', '', '', '', $L['opening']];
  foreach ($L['rows'] as $r) $rows[] = [date('d/m/Y', strtotime($r['d'])), $r['doc'], $r['txt'], $r['c'] > 0 ? $r['c'] : '', $r['p'] > 0 ? $r['p'] : '', $r['bal']];
  $rows[] = []; $rows[] = ['', 'סה"כ', '', $L['totals']['c'], $L['totals']['p'], $L['totals']['bal']];
  return $rows;
}
function bl_pdf(string $name, array $L, string $period): string {
  $d = new PdfDoc(dirname(__DIR__) . '/fonts'); $R = 555.0; $y = 0.0; $pg = 0;
  $fit = function (string $t, float $sz, float $mw) use ($d): string { $t = bl_norm($t); if ($d->width($t, $sz) <= $mw) return $t; while ($t !== '' && $d->width($t . '…', $sz) > $mw) $t = mb_substr($t, 0, -1); return $t . '…'; };
  $cols = [['תאריך', 50, 54], ['מסמך', 78, 82], ['פרטים', 168, 172], ['חיוב', 58, 62], ['תשלום', 58, 62], ['יתרה', 62, 66]];
  $n = fn($v) => number_format((float)$v, 2);
  $head = function () use ($d, &$y, &$pg, $name, $L, $period, $R, $cols) {
    $d->addPage(); $pg++; $y = 46.0;
    $d->text('כרטסת ספק', $R, $y, 11, 'r', 'r'); $d->text(date('d/m/Y'), 40, $y, 9, 'r', 'l'); $y += 22;
    $d->text($name, $R, $y, 16, 'b', 'r'); $y += 16;
    $meta = 'תקופה: ' . $period . ($L['info']['biz'] !== '' ? ' · עוסק ' . $L['info']['biz'] : '') . ($L['info']['account'] !== '' ? ' · בנק ' . $L['info']['bank'] . '-' . $L['info']['branch'] . '-' . $L['info']['account'] : '');
    $d->text($meta, $R, $y, 9, 'r', 'r'); $y += 20;
    $x = $R; foreach ($cols as $c) { $d->text($c[0], $x, $y, 9, 'b', 'r'); $x -= $c[2]; } $y += 14;
  };
  $head(); $line = function (array $v, string $f = 'r') use ($d, &$y, $cols, $R, $fit) { $x = $R; foreach ($cols as $i => $c) { $d->text($fit((string)($v[$i] ?? ''), 8, (float)$c[1]), $x, $y, 8, $f, 'r'); $x -= $c[2]; } $y += 13; };
  if (abs($L['opening']) > 0.004) $line(['', 'יתרת פתיחה', '', '', '', $n($L['opening'])]);
  foreach ($L['rows'] as $r) { if ($y > 790) $head(); $line([date('d/m/Y', strtotime($r['d'])), $r['doc'], $r['txt'], $r['c'] > 0 ? $n($r['c']) : '', $r['p'] > 0 ? $n($r['p']) : '', $n($r['bal'])]); }
  if ($y > 770) $head(); $y += 6; $line(['', 'סה"כ', '', $n($L['totals']['c']), $n($L['totals']['p']), $n($L['totals']['bal'])], 'b');
  return $d->output();
}
