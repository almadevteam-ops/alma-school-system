<?php
declare(strict_types=1);
// יישור קו מהמערכת הישנה (גיבוי JSON של tashpaz2) למערכת החדשה. ניתן להריץ שוב ושוב: תלמידות מזוהות לפי old_ref / ת.ז / שם,
// תשלומים וחיובים שנוצרו בסנכרון מסומנים (batch/src = oldsync) ובריצה חוזרת מבוטלים ונוצרים מחדש לפי הקובץ.
const OLDSYNC_MAX = 15728640;
const MAX_RECIPIENTS = 15;
const OLD_METHODS = ['אשראי' => 'hok_card', 'הו"ק' => 'hok_bank', 'העברה' => 'transfer', 'מזומן' => 'cash', "צ'ק" => 'check', 'צ׳ק' => 'check'];

function os_bil(array $s, int $m): bool {
  $lf = (int)($s['leftFrom'] ?? 12); $st = (int)($s['start'] ?? 0); $ct = (int)($s['count'] ?? 10);
  return !(($s['status'] ?? '') === 'left' && $m >= $lf) && $m >= $st && $m < $st + $ct;
}
function os_due(array $s): float {
  $d = (!empty($s['disc']) && ($s['discSt'] ?? 'approved') !== 'pending') ? (float)($s['discAmt'] ?? 0) : 0;
  return max(0.0, (float)($s['tuition'] ?? 0) + (float)($s['trans'] ?? 0) - $d);
}
function os_camt(array $s, array $c): array {
  $gross = isset($s['xa'][$c['id']]) && $s['xa'][$c['id']] !== null ? (float)$s['xa'][$c['id']] : (float)($c['amount'] ?? 0);
  $x = $s['xd'][$c['id']] ?? null; $disc = (is_array($x) && !empty($x['on'])) ? (float)($x['amt'] ?? 0) : 0.0;
  return [$gross, min($gross, $disc)];
}
function os_date(string $v, string $fallback = ''): string { $d = dob_iso(trim($v)); return $d !== '' ? $d : $fallback; }
function os_str($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }

function oldsync_parse(string $raw): array {
  $D = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true);
  if (!is_array($D) || !isset($D['students']) || !is_array($D['students'])) throw new RuntimeException('הקובץ אינו גיבוי תקין של המערכת הישנה (חסרה רשימת תלמידות).');
  return $D;
}

/** מריץ את הסנכרון. $dry=true: הכול בתוך טרנזקציה שמבוטלת בסוף, ומוחזרים רק המספרים. */
function oldsync_run(array $D, array $o, array $u, bool $dry): array {
  $pdo = db(); $who = (string)($u['name'] ?: $u['username']); $uid = (int)$u['id'];
  $S = ['st_new' => 0, 'st_upd' => 0, 'fam_new' => 0, 'fam_ex' => 0, 'pay_n' => 0, 'pay_sum' => 0.0, 'mark_n' => 0, 'chg_n' => 0, 'chg_paid' => 0.0, 'gad_n' => 0, 'log_n' => 0, 'rec_n' => 0, 'warn' => []];
  $stu = array_values(array_filter($D['students'], fn($s) => is_array($s) && trim((string)($s['name'] ?? '')) !== ''));
  $charges = array_values(array_filter((array)($D['charges'] ?? []), fn($c) => is_array($c) && isset($c['id'])));
  $pdo->beginTransaction();
  try {
    // --- קיבוץ לתיקים: אחיות לפי טלפון אב/אם זהה; תלמידת דירה בתיק נפרד; ממתינה בלי תיק
    $n = count($stu); $par = range(0, max(0, $n - 1));
    $fd = function (int $i) use (&$fd, &$par) { while ($par[$i] !== $i) { $par[$i] = $par[$par[$i]]; $i = $par[$i]; } return $i; };
    $byPh = [];
    foreach ($stu as $i => $s) {
      if (!empty($s['pending']) || !empty($s['apt'])) continue;
      foreach (['fPhone', 'mPhone'] as $k) { $p = preg_replace('/\D/', '', (string)($s[$k] ?? '')) ?? ''; if (strlen($p) < 7) continue; if (isset($byPh[$p])) $par[$fd($i)] = $fd($byPh[$p]); else $byPh[$p] = $i; }
    }
    $groups = [];
    foreach ($stu as $i => $s) { if (!empty($s['pending'])) continue; $groups[!empty($s['apt']) ? 'a' . $i : 'g' . $fd($i)][] = $i; }
    $findS = $pdo->prepare('SELECT id, family_id FROM students WHERE old_ref = ? LIMIT 1');
    $findId = $pdo->prepare("SELECT id, family_id FROM students WHERE idn = ? AND idn <> '' AND archived_at IS NULL LIMIT 1");
    $findNm = $pdo->prepare('SELECT id, family_id, name FROM students WHERE archived_at IS NULL');
    $names = $pdo->query('SELECT id, family_id, name FROM students WHERE archived_at IS NULL')->fetchAll();
    $nameIdx = []; foreach ($names as $r) $nameIdx[norm_name((string)$r['name'])][] = $r;
    $sid = []; $fam = [];   // אינדקס תלמידה ישנה -> מזהה חדש; קבוצה -> תיק
    $upS = function (int $id, array $f) use ($pdo) {   // השלמת שדות ריקים בלבד
      $cur = $pdo->prepare('SELECT * FROM students WHERE id = ?'); $cur->execute([$id]); $c = $cur->fetch(); $set = []; $v = [];
      foreach ($f as $k => $val) {
        if (in_array($k, ['vaada', 's07', 'allergy', 'is_apt', 'disc_on'], true)) { if ((int)$val && !(int)$c[$k]) { $set[] = "$k = ?"; $v[] = 1; if ($k === 'disc_on') foreach (['disc_amt', 'disc_status', 'disc_by', 'disc_link', 'disc_note'] as $dk) { $set[] = "$dk = ?"; $v[] = $f[$dk]; } } continue; }
        if (str_starts_with($k, 'disc_') || $k === 'left_month' || $k === 'status') continue;
        $cv = trim((string)($c[$k] ?? '')); if ($k === 'hmo' && $cv === HMO_UNKNOWN) $cv = '';
        if ($cv === '' && (string)$val !== '') { $set[] = "$k = ?"; $v[] = $val; }
      }
      if ($set) { $pdo->prepare('UPDATE students SET ' . implode(', ', $set) . ', updated_at = ? WHERE id = ?')->execute([...$v, now(), $id]); return true; }
      return false;
    };
    $cols = ['name', 'grade', 'cls', 'idn', 'dob', 'addr', 'house_no', 'addr_orig', 'city', 'email', 'phone', 'home_phone', 'f_name', 'f_phone', 'f_id', 'm_name', 'm_phone', 'm_id', 'hmo', 'marital', 'status', 'notes', 'books', 'vaada', 'vaada_note', 's07', 'allergy', 'allergy_list', 'allergy_other', 'is_apt', 'disc_on', 'disc_amt', 'disc_status', 'disc_by', 'disc_link', 'disc_note', 'left_month', 'old_ref'];
    $map = function (array $s): array {
      [$st, $hn] = split_street_house((string)($s['addr'] ?? ''));
      $disc = !empty($s['disc']);
      return ['name' => os_str($s['name'], 100), 'grade' => os_str($s['grade'] ?? '', 4), 'cls' => os_str($s['cls'] ?? '', 8), 'idn' => ($i = norm_id((string)($s['idn'] ?? ''))) !== '' ? os_str($i, 20) : '',
        'dob' => os_date((string)($s['dob'] ?? '')), 'addr' => os_str($st, 200), 'house_no' => os_str($hn, 12), 'addr_orig' => os_str($s['addr'] ?? '', 200), 'city' => os_str($s['city'] ?? '', 80), 'email' => os_str(mb_strtolower((string)($s['email'] ?? '')), 150),
        'phone' => norm_phone((string)($s['phone'] ?? '')), 'home_phone' => norm_phone((string)($s['hPhone'] ?? '')), 'f_name' => os_str($s['fName'] ?? '', 80), 'f_phone' => norm_phone((string)($s['fPhone'] ?? '')), 'f_id' => norm_id((string)($s['fId'] ?? '')),
        'm_name' => os_str($s['mName'] ?? '', 80), 'm_phone' => norm_phone((string)($s['mPhone'] ?? '')), 'm_id' => norm_id((string)($s['mId'] ?? '')), 'hmo' => ($h = hmo_norm((string)($s['hmo'] ?? ''))) === HMO_UNKNOWN ? '' : os_str($h, 30),
        'marital' => ($s['marital'] ?? '') === 'divorced' ? 'divorced' : 'married', 'status' => !empty($s['pending']) ? 'pending' : (($s['status'] ?? '') === 'left' ? 'left' : 'active'),
        'notes' => (string)($s['notes'] ?? ''), 'books' => os_str($s['bk'] ?? '', 255), 'vaada' => !empty($s['vaada']) ? 1 : 0, 'vaada_note' => os_str($s['vaadaN'] ?? '', 255), 's07' => !empty($s['s07']) ? 1 : 0,
        'allergy' => !empty($s['alg']) ? 1 : 0, 'allergy_list' => !empty($s['alg']) ? os_str(implode(',', array_filter((array)($s['algs'] ?? []), 'is_string')), 120) : '', 'allergy_other' => !empty($s['alg']) ? os_str($s['algO'] ?? '', 120) : '',
        'is_apt' => !empty($s['apt']) ? 1 : 0, 'disc_on' => $disc ? 1 : 0, 'disc_amt' => $disc ? (float)($s['discAmt'] ?? 0) : 0, 'disc_status' => ($s['discSt'] ?? 'approved') === 'pending' ? 'pending' : 'approved',
        'disc_by' => $disc ? os_str($s['discBy'] ?? '', 100) : '', 'disc_link' => $disc ? os_str($s['discLink'] ?? '', 255) : '', 'disc_note' => $disc ? os_str($s['discNote'] ?? '', 255) : '',
        'left_month' => (($s['status'] ?? '') === 'left' && (int)($s['leftFrom'] ?? 12) < 12) ? max(1, min(12, (int)($s['leftFrom'] ?? 0) + 1)) : null, 'old_ref' => os_str($s['id'] ?? '', 20)];
    };
    $mkFam = function (array $idx) use ($pdo, $stu, $o, &$S) {
      $mem = array_map(fn($i) => $stu[$i], $idx); $act = array_values(array_filter($mem, fn($s) => ($s['status'] ?? '') !== 'left')); $base = $act ?: $mem;
      $monthly = 0.0; foreach ($base as $s) $monthly += (float)($s['tuition'] ?? 0) + (float)($s['trans'] ?? 0);
      $f = $base[0]; $cnt = 10; foreach ($base as $s) if ((int)($s['count'] ?? 10) >= 11) $cnt = 12;
      $apt = !empty($f['apt']); $notes = array_values(array_filter(array_map(fn($s) => trim((string)($s['discNote'] ?? '')), $base)));
      $ok = ''; foreach ($mem as $s) if (!empty($s['cardOk'])) $ok = os_date((string)$s['cardOk'], date('Y-m-d'));
      return ['monthly' => round($monthly, 2), 'months' => $cnt, 'method' => OLD_METHODS[(string)($f['method'] ?? '')] ?? 'other', 'day' => max(1, min(31, (int)($f['day'] ?? 10))), 'apt' => $apt, 'dn' => mb_substr(implode(' · ', array_unique($notes)), 0, 255), 'ok' => $ok ?: null, 'name' => family_default_name(array_map(fn($s) => (string)$s['name'], $mem))];
    };
    $gid = gad_payer_id();
    foreach ($groups as $gk => $idx) {
      $fid = 0; $rows = [];
      foreach ($idx as $i) {
        $s = $stu[$i]; $f = $map($s); $found = null; $ref = (string)($s['id'] ?? '');
        if ($ref !== '') { $findS->execute([$ref]); $found = $findS->fetch() ?: null; }
        if (!$found && $f['idn'] !== '' && valid_il_id($f['idn'])) { $findId->execute([$f['idn']]); $found = $findId->fetch() ?: null; }
        if (!$found) { $cand = $nameIdx[norm_name($f['name'])] ?? []; if (count($cand) === 1) $found = $cand[0]; }
        if ($found) {
          if (!empty($o['students'])) { if ($upS((int)$found['id'], $f)) $S['st_upd']++; }
          $pdo->prepare("UPDATE students SET old_ref = ? WHERE id = ? AND old_ref = ''")->execute([$f['old_ref'], $found['id']]);
          $sid[$i] = (int)$found['id']; if (!$fid && $found['family_id']) $fid = (int)$found['family_id'];
        } else if (!empty($o['students'])) {
          if (!in_array($f['grade'], GRADES, true)) $S['warn'][] = $f['name'] . ': כיתה לא מוכרת (' . $f['grade'] . ')';
          if ($f['idn'] !== '' && !valid_il_id($f['idn'])) $S['warn'][] = $f['name'] . ': ת.ז לא תקינה (' . $f['idn'] . ')';
          $pdo->prepare('INSERT INTO students (' . implode(',', $cols) . ', created_at, updated_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',?,?)')->execute([...array_values(array_map(fn($k) => $f[$k], $cols)), now(), now()]);
          $sid[$i] = (int)$pdo->lastInsertId(); $S['st_new']++;
        }
        if (($s['start'] ?? 0) > 0) $S['warn'][] = $f['name'] . ': חודש תחילה שונה מספטמבר – החיוב בתיק מחושב לפי ספטמבר';
      }
      if (empty($o['students'])) { foreach ($idx as $i) if (isset($sid[$i])) { $r = $pdo->prepare('SELECT family_id FROM students WHERE id = ?'); $r->execute([$sid[$i]]); if ($x = (int)$r->fetchColumn()) { $fid = $x; break; } } }
      $fp = $mkFam($idx); $isNew = false;
      if (!$fid) {
        if (!array_filter($idx, fn($i) => isset($sid[$i]))) continue;
        $pdo->prepare('INSERT INTO families (name, monthly, months, method, collect_day, payer_kind, payer_id, discount_note, card_ok, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$fp['name'], $fp['monthly'], $fp['months'], $fp['method'], $fp['day'], $fp['apt'] ? 'org' : 'parent', $fp['apt'] ? $gid : null, $fp['dn'], $fp['ok'], now(), now()]);
        $fid = (int)$pdo->lastInsertId(); $S['fam_new']++; $isNew = true;
      } else {
        $S['fam_ex']++;
        if (!empty($o['updfam'])) $pdo->prepare('UPDATE families SET monthly = ?, months = ?, method = ?, collect_day = ?, discount_note = ?, updated_at = ? WHERE id = ?')->execute([$fp['monthly'], $fp['months'], $fp['method'], $fp['day'], $fp['dn'], now(), $fid]);
      }
      foreach ($idx as $i) if (isset($sid[$i])) $pdo->prepare('UPDATE students SET family_id = ? WHERE id = ? AND family_id IS NULL')->execute([$fid, $sid[$i]]);
      $fam[$gk] = $fid;
      if (count(array_unique(array_map(fn($i) => (int)($stu[$i]['count'] ?? 10), $idx))) > 1) $S['warn'][] = $fp['name'] . ': לאחיות בתיק מספר תשלומים שונה – נקבע ' . $fp['months'];
      $famMethod = $fp['method'];
      // --- תשלומי שכר לימוד וסימוני חודש
      if (!empty($o['pay']) && !$fp['apt']) {
        $pdo->prepare("UPDATE payments SET voided_at = ?, voided_by = ? WHERE family_id = ? AND batch = 'oldsync' AND voided_at IS NULL")->execute([now(), $uid, $fid]);
        $pdo->prepare('DELETE FROM month_marks WHERE family_id = ?')->execute([$fid]);
        $ins = $pdo->prepare("INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, payer_id, batch, created_by, created_at) VALUES (?,NULL,?,?,?,?,?,?,NULL,'oldsync',?,?)");
        for ($m = 0; $m < min(12, $fp['months']); $m++) {
          $paid = 0.0; $rest = [];
          foreach ($idx as $i) { $s = $stu[$i]; if (!os_bil($s, $m)) continue; $t = (string)(($s['pay'][$m] ?? 'u') ?: 'u'); if ($t === 'p') $paid += os_due($s); else $rest[] = $t; }
          if ($paid > 0.004) { $ins->execute([$fid, $m + 1, round($paid, 2), date('Y-m-d'), in_array($famMethod, array_keys(PAY_METHODS), true) ? $famMethod : 'other', 'old', 'סונכרן מהמערכת הישנה – ' . SCHOOL_MONTHS[$m + 1], $uid, now()]); $S['pay_n']++; $S['pay_sum'] += $paid; }
          $u2 = array_values(array_unique($rest));
          if (count($u2) === 1 && in_array($u2[0], ['w', 'r', 'n'], true)) { $pdo->prepare('INSERT INTO month_marks (family_id, month, mark, updated_by, updated_at) VALUES (?,?,?,?,?)')->execute([$fid, $m + 1, $u2[0], $uid, now()]); $S['mark_n']++; }
        }
      }
      // --- חיובים חד-פעמיים (מסוכמים לתיק)
      if (!empty($o['charges']) && $charges) {
        $pdo->prepare("UPDATE charges SET voided_at = ? WHERE family_id = ? AND src = 'oldsync' AND voided_at IS NULL")->execute([now(), $fid]);
        $pdo->prepare("UPDATE payments SET voided_at = ?, voided_by = ? WHERE family_id = ? AND batch = 'oldsync_c' AND voided_at IS NULL")->execute([now(), $uid, $fid]);
        foreach ($charges as $c) {
          $gross = 0.0; $disc = 0.0; $paid = 0.0; $rest = [];
          foreach ($idx as $i) {
            $s = $stu[$i]; $t = (string)(($s['x'][$c['id']] ?? 'u') ?: 'u');
            if ($t === 'n' || (($s['status'] ?? '') === 'left' && $t !== 'p')) continue;
            [$g, $d] = os_camt($s, $c); $gross += $g; $disc += $d; if ($t === 'p') $paid += $g - $d; else $rest[] = $t;
          }
          if ($gross <= 0.004) continue;
          $u2 = array_values(array_unique($rest)); $mk = (count($u2) === 1 && in_array($u2[0], ['w', 'r'], true)) ? $u2[0] : '';
          $nm = os_str($c['name'] ?? 'חיוב', 100); $kind = preg_match('/ספר/u', $nm) ? 'books' : (preg_match('/סיור|טיול/u', $nm) ? 'tour' : (preg_match('/חוג/u', $nm) ? 'activities' : 'other'));
          $pdo->prepare("INSERT INTO charges (family_id, kind, label, amount, disc_amt, disc_note, mark, src, created_by, created_at) VALUES (?,?,?,?,?,?,?,'oldsync',?,?)")->execute([$fid, $kind, $nm, round($gross, 2), round($disc, 2), $disc > 0 ? 'מהמערכת הישנה' : '', $mk, $uid, now()]);
          $cid = (int)$pdo->lastInsertId(); $S['chg_n']++;
          if ($paid > 0.004) { $pdo->prepare("INSERT INTO payments (family_id, charge_id, for_month, amount, paid_on, method, ref, note, payer_id, batch, created_by, created_at) VALUES (?,?,NULL,?,?,?,?,?,NULL,'oldsync_c',?,?)")->execute([$fid, $cid, round($paid, 2), date('Y-m-d'), 'other', 'old', 'סונכרן מהמערכת הישנה', $uid, now()]); $S['chg_paid'] += $paid; }
        }
      }
    }
    // --- מורשת גד
    if (!empty($o['gad'])) {
      $gm = (array)($D['gad'] ?? []); $gn = (array)($D['gadN'] ?? []);
      foreach ($gm as $m => $v) {
        $m = (int)$m + 1; if ($m < 1 || $m > 12 || !$v) continue;
        $pdo->prepare('INSERT INTO gad_months (month, paid, ref, updated_at) VALUES (?,1,?,?) ON DUPLICATE KEY UPDATE paid = 1, ref = IF(ref = \'\', VALUES(ref), ref), updated_at = VALUES(updated_at)')->execute([$m, os_str(is_scalar($gn[$m - 1] ?? '') ? ($gn[$m - 1] ?? '') : '', 100), now()]);
        $S['gad_n'] += gad_mark($m, gad_families(), $gid, $uid);
      }
    }
    // --- יומן שיחות
    if (!empty($o['logs'])) {
      $chk = $pdo->prepare('SELECT 1 FROM student_notes WHERE student_id = ? AND body = ? LIMIT 1');
      $ins = $pdo->prepare('INSERT INTO student_notes (student_id, body, author_id, author_name, legacy, created_at) VALUES (?,?,?,?,1,?)');
      foreach ($stu as $i => $s) { if (!isset($sid[$i])) continue;
        foreach ((array)($s['log'] ?? []) as $g) { if (!is_array($g) || trim((string)($g['t'] ?? '')) === '') continue;
          $body = 'מהמערכת הישנה (' . os_str($g['d'] ?? '', 20) . '): ' . os_str($g['t'], 1000); $chk->execute([$sid[$i], $body]); if ($chk->fetchColumn()) continue;
          $ins->execute([$sid[$i], $body, $uid, $who, os_date((string)($g['d'] ?? ''), date('Y-m-d')) . ' 12:00:00']); $S['log_n']++; } }
    }
    // --- הגדרות ונמענים
    if (!empty($o['sett'])) {
      if (isset($D['sib']) && is_numeric($D['sib']) && !$dry) setting_set('sib_discount', (string)(float)$D['sib'], $who);
      if (!empty($D['payUrl']) && preg_match('~^https://\S+$~', (string)$D['payUrl']) && setting_get('pay_url', '') === '' && !$dry) setting_set('pay_url', (string)$D['payUrl'], $who);
      foreach ((array)($D['notify'] ?? []) as $r) { $em = trim((string)($r['e'] ?? '')); if (!filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
        $x = $pdo->prepare('SELECT 1 FROM notify_recipients WHERE email = ?'); $x->execute([$em]); if ($x->fetchColumn() || count(notify_recipients()) >= MAX_RECIPIENTS) continue;
        $pdo->prepare('INSERT INTO notify_recipients (email, label, created_at, created_by) VALUES (?,?,?,?)')->execute([$em, os_str($r['n'] ?? '', 100), now(), $who]); $S['rec_n']++; }
    }
    $pending = 0; foreach ($D['students'] as $s) if (!empty($s['pending'])) $pending++;
    if ($pending && !empty($o['students'])) {
      foreach ($D['students'] as $s) { if (empty($s['pending']) || trim((string)($s['name'] ?? '')) === '') continue; $f = $map($s); $ref = (string)($s['id'] ?? ''); $findS->execute([$ref]); if ($findS->fetch()) continue;
        $pdo->prepare('INSERT INTO students (' . implode(',', $cols) . ', created_at, updated_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',?,?)')->execute([...array_values(array_map(fn($k) => $f[$k], $cols)), now(), now()]); $S['st_new']++; }
    }
    if ($dry) $pdo->rollBack(); else $pdo->commit();
  } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
  return $S;
}
