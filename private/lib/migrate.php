<?php
declare(strict_types=1);
function migration_files(): array { $f = glob(PRIV . '/migrations/*.sql') ?: []; sort($f); return $f; }
function migrations_pending(): bool {
  static $r = null; if ($r !== null) return $r;
  $files = migration_files();
  if (!$files) return $r = false;
  $has = db()->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
  if (!$has) return $r = true;
  $done = array_column(db()->query('SELECT name FROM schema_migrations')->fetchAll(), 'name');
  foreach ($files as $f) if (!in_array(basename($f), $done, true)) return $r = true;
  return $r = false;
}
function migrate(): array {
  $pdo = db();
  $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $done = array_column($pdo->query('SELECT name FROM schema_migrations')->fetchAll(), 'name');
  $applied = [];
  foreach (migration_files() as $f) {
    $n = basename($f);
    if (in_array($n, $done, true)) continue;
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($f)))) as $sql) $pdo->exec($sql);
    $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?,?)')->execute([$n, now()]);
    $applied[] = $n;
  }
  return $applied;
}
