<?php
// קישורים זמניים להורדת מסמך (לשליחה בוואטסאפ): אסימון אקראי, תוקף מוגבל.
return function (PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS doc_links (
    token CHAR(40) NOT NULL PRIMARY KEY,
    hist_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    KEY k_exp (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
