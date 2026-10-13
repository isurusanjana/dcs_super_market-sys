<?php
// CLI: php database/upgrade.php   - upgrades a v1 (books & stationery) database to v2 (supermarket).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/../app/bootstrap.php';
$sql = file_get_contents(__DIR__ . '/upgrade_v2_supermarket.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
$ok = 0; $skipped = 0;
foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $st) {
    try { DB::pdo()->exec($st); $ok++; }
    catch (PDOException $e) { $c = (int)($e->errorInfo[1] ?? 0); if (in_array($c, [1060, 1061, 1091], true)) { $skipped++; continue; } fwrite(STDERR, "FAILED: " . substr($st, 0, 80) . "\n  " . $e->getMessage() . "\n"); exit(1); }
}
echo "Schema: $ok statements applied, $skipped already present.\n";
Seeder::permissions(); Seeder::supermarketDefaults(); Seeder::upgradeRoles();
DB::pdo()->exec('INSERT IGNORE INTO stock (warehouse_id, product_id, qty) SELECT w.id, p.id, 0 FROM warehouses w CROSS JOIN products p');   // every product has a stock row in every warehouse
echo "Permissions, settings and role grants updated. Upgrade to v2 complete.\n";
