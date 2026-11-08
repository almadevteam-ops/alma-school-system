<?php
// מנרמל טלפונים (0 בהתחלה) ומיישר את שדה קופת חולים לרשימה הקבועה.
return function (PDO $pdo): void {
  $up = $pdo->prepare('UPDATE students SET phone = ?, f_phone = ?, m_phone = ?, hmo = ? WHERE id = ?');
  foreach ($pdo->query('SELECT id, phone, f_phone, m_phone, hmo FROM students')->fetchAll() as $r) {
    $n = [norm_phone((string)$r['phone']), norm_phone((string)$r['f_phone']), norm_phone((string)$r['m_phone']), hmo_norm((string)$r['hmo'])];
    if ($n !== [$r['phone'], $r['f_phone'], $r['m_phone'], $r['hmo']]) $up->execute([...$n, $r['id']]);
  }
};
