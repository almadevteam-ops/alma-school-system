<?php
declare(strict_types=1);
// תקציב מתוכנן מול ביצוע: לכל שנה, יחידה וקטגוריה.

function bplan_ready(): bool {
  static $ok = null;
  if ($ok === null) { try { $ok = (bool)db()->query("SHOW TABLES LIKE 'budget_plan'")->fetch(); } catch (Throwable $t) { $ok = false; } }
  return $ok;
}
/** שורות לפי קטגוריה: [cat, plan, spent]; unit '' = כל היחידות (סכום התקציבים של היחידות). */
function bplan_rows(int $y, string $unit): array {
  $pdo = db(); $rg = budget_range($y); $plan = []; $spent = [];
  $q = $pdo->prepare('SELECT category, SUM(amount) a FROM budget_plan WHERE year_start = ?' . ($unit !== '' ? ' AND unit = ?' : '') . ' GROUP BY category'); $q->execute($unit !== '' ? [$y, $unit] : [$y]);
  foreach ($q as $r) $plan[(string)$r['category']] = (float)$r['a'];
  $q = $pdo->prepare('SELECT category, SUM(amount) a FROM budget_tx WHERE is_income = 0 AND tx_date BETWEEN ? AND ?' . ($unit !== '' ? ' AND unit = ?' : '') . ' GROUP BY category'); $q->execute($unit !== '' ? [$rg[0], $rg[1], $unit] : [$rg[0], $rg[1]]);
  foreach ($q as $r) $spent[(string)$r['category']] = (float)$r['a'];
  $cats = array_values(array_unique(array_merge(budget_cats(), array_keys($plan), array_filter(array_keys($spent), fn($c) => $c !== ''))));
  sort($cats, SORT_STRING); $rows = [];
  foreach ($cats as $c) $rows[] = ['cat' => $c, 'plan' => $plan[$c] ?? 0.0, 'spent' => $spent[$c] ?? 0.0];
  if (!empty($spent[''])) $rows[] = ['cat' => '', 'plan' => 0.0, 'spent' => $spent['']];
  return $rows;
}
function bplan_totals(array $rows): array {
  $p = 0.0; $s = 0.0; $sp = 0.0;   // $sp: הוצאות בקטגוריות שיש להן תקציב
  foreach ($rows as $r) { $p += $r['plan']; $s += $r['spent']; if ($r['plan'] > 0) $sp += $r['spent']; }
  return ['plan' => $p, 'spent' => $s, 'spent_planned' => $sp];
}
function bplan_pct(float $plan, float $spent): ?float { return $plan > 0.004 ? $spent / $plan * 100 : null; }
function bplan_class(?float $pct): string { return $pct === null ? '' : ($pct >= 100 ? ' over' : ($pct >= 80 ? ' warn' : '')); }
function bplan_set(int $y, string $unit, string $cat, float $amount): void {
  $amount = round(max(0.0, $amount), 2);
  if ($amount < 0.005) { db()->prepare('DELETE FROM budget_plan WHERE year_start = ? AND unit = ? AND category = ?')->execute([$y, $unit, $cat]); return; }
  db()->prepare('REPLACE INTO budget_plan (year_start, unit, category, amount, updated_at) VALUES (?,?,?,?,?)')->execute([$y, $unit, $cat, $amount, now()]);
}
/** העתקת תקציב משנה קודמת לשורות שעדיין ריקות (ליחידה אחת, או לכולן). מחזיר מספר שורות שהועתקו. */
function bplan_copy(int $y, string $unit, float $pct = 0.0): int {
  $q = db()->prepare('SELECT unit, category, amount FROM budget_plan WHERE year_start = ?' . ($unit !== '' ? ' AND unit = ?' : '')); $q->execute($unit !== '' ? [$y - 1, $unit] : [$y - 1]); $n = 0;
  $ex = db()->prepare('SELECT COUNT(*) FROM budget_plan WHERE year_start = ? AND unit = ? AND category = ?');
  foreach ($q->fetchAll() as $r) { $ex->execute([$y, $r['unit'], $r['category']]); if ((int)$ex->fetchColumn()) continue; bplan_set($y, (string)$r['unit'], (string)$r['category'], (float)$r['amount'] * (1 + $pct / 100)); $n++; }
  return $n;
}
