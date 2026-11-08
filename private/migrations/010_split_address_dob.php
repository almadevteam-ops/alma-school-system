<?php
// מפריד את מספר הבית מתוך שדה הכתובת (המספר עובר לשדה "מספר בית", הכתובת המקורית נשמרת ב-addr_orig),
// ומיישר תאריכי לידה לפורמט אחיד YYYY-MM-DD. לא מוחק שום מידע: מה שלא ניתן לפענח נשאר כמו שהוא.
return function (PDO $pdo): void {
  $up = $pdo->prepare('UPDATE students SET addr = ?, house_no = ?, addr_orig = ?, dob = ? WHERE id = ?');
  foreach ($pdo->query('SELECT id, addr, house_no, addr_orig, dob FROM students')->fetchAll() as $r) {
    $addr = (string)$r['addr']; $house = (string)$r['house_no']; $orig = (string)$r['addr_orig'];
    if ($orig === '' && $addr !== '' && $house === '') {
      [$street, $num] = split_street_house($addr);
      if ($num !== '') { $orig = $addr; $house = $num; $addr = $street; }
    }
    $dob = (string)$r['dob']; $iso = dob_iso($dob); if ($iso !== '') $dob = $iso;
    if ($addr !== $r['addr'] || $house !== $r['house_no'] || $orig !== $r['addr_orig'] || $dob !== $r['dob']) $up->execute([$addr, $house, $orig, $dob, $r['id']]);
  }
};
