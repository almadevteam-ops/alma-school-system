<?php
// מתקן סוגריים שהגיעו הפוכים (")אסייה(") בנתונים שיובאו מבחוץ.
return function (PDO $pdo): void {
  $fix = fn(string $v) => (strpos($v, ')') !== false && strpos($v, '(') !== false) ? (string)preg_replace_callback('/\)([^()]*)\(/u', fn($m) => '(' . $m[1] . ')', $v) : $v;
  foreach (['students' => ['name', 'f_name', 'm_name', 'city', 'addr', 'addr_orig', 'books', 'vaada_note', 'allergy_other'], 'families' => ['name', 'discount_note'], 'payers' => ['name']] as $t => $cols) {
    $rows = $pdo->query('SELECT id, ' . implode(', ', $cols) . " FROM $t WHERE " . implode(' OR ', array_map(fn($c) => "$c LIKE '%)%(%'", $cols)))->fetchAll();
    foreach ($rows as $r) {
      $set = []; $v = [];
      foreach ($cols as $c) { $n = $fix((string)$r[$c]); if ($n !== (string)$r[$c]) { $set[] = "$c = ?"; $v[] = $n; } }
      if ($set) $pdo->prepare("UPDATE $t SET " . implode(', ', $set) . ' WHERE id = ?')->execute([...$v, $r['id']]);
    }
  }
};
