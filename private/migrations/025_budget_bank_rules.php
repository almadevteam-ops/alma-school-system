<?php
// כללי שיוך לדוח הבנק: "דביט כא"ל" = רכישות באשראי, "הפקדת שיק" = שכר לימוד (הכנסה).
return function (PDO $pdo): void {
  $cat = $pdo->prepare('INSERT IGNORE INTO budget_cats (name) VALUES (?)');
  $has = $pdo->prepare('SELECT 1 FROM budget_rules WHERE kw = ? LIMIT 1');
  $ins = $pdo->prepare('INSERT INTO budget_rules (ord, kw, category) VALUES (?,?,?)');
  $ord = (int)$pdo->query('SELECT COALESCE(MAX(ord),0) FROM budget_rules')->fetchColumn();
  foreach ([['דביט כא"ל', 'רכישות באשראי'], ['הפקדת שיק', 'שכר לימוד']] as [$kw, $c]) {
    $has->execute([$kw]); if ($has->fetchColumn()) continue;
    $cat->execute([$c]); $ord += 10; $ins->execute([$ord, $kw, $c]);
  }
};
