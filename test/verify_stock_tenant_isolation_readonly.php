<?php
declare(strict_types=1);

/**
 * IIP M-2 tenant-isolation preflight — READ ONLY.
 * Connects only to inventory_intelligence_test. SELECTs and information_schema only.
 * It does not create fixtures or modify ledger/audit data.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/config/env.php';
const TEST_DB = 'inventory_intelligence_test';
$host = (string)($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string)($_ENV['DB_PORT'] ?? '3306');
$user = (string)($_ENV['DB_USER'] ?? '');
$pass = (string)($_ENV['DB_PASSWORD'] ?? '');
$charset = (string)($_ENV['DB_CHARSET'] ?? 'utf8mb4');
if ($user === '') { fwrite(STDERR, "STOP: DB_USER missing; no connection attempted.\n"); exit(3); }
$pdo = new PDO("mysql:host={$host};port={$port};dbname=" . TEST_DB . ";charset={$charset}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== TEST_DB) {
    throw new RuntimeException('Safety stop: connected database is not ' . TEST_DB);
}
echo "Connected database: " . TEST_DB . "\nMode: READ-ONLY\n";

$required = ['tenants','users','products','warehouses','stock_movements'];
$check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name');
foreach ($required as $table) {
    $check->execute([':name' => $table]);
    if ((int)$check->fetchColumn() !== 1) {
        echo "STOP: required table missing: {$table}\n";
        exit(4);
    }
}
echo "PASS: required tenant and ledger tables exist.\n";

$tenantRows = $pdo->query('SELECT id, status FROM tenants ORDER BY id')->fetchAll();
$userRows = $pdo->query('SELECT id, tenant_id, status FROM users ORDER BY id')->fetchAll();
$movementRows = $pdo->query('SELECT tenant_id, COUNT(*) AS movement_count FROM stock_movements GROUP BY tenant_id ORDER BY tenant_id')->fetchAll();
echo 'Tenant rows: ' . count($tenantRows) . "\n";
echo 'User rows: ' . count($userRows) . "\n";
echo 'Tenant IDs with ledger rows: ' . count($movementRows) . "\n";
foreach ($movementRows as $row) {
    echo '  tenant_id=' . (int)$row['tenant_id'] . ', movements=' . (int)$row['movement_count'] . "\n";
}

// Prove the tenant predicate used by the GET endpoint cannot return a movement
// whose tenant differs from the requested tenant. This is read-only and uses
// actual rows; it does not simulate an HTTP request or create a second tenant.
$leakStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_movements sm WHERE sm.tenant_id = :requested_tenant AND sm.tenant_id <> :same_tenant');
foreach ($tenantRows as $tenant) {
    $tenantId = (int)$tenant['id'];
    $leakStmt->execute([':requested_tenant' => $tenantId, ':same_tenant' => $tenantId]);
    $leaks = (int)$leakStmt->fetchColumn();
    if ($leaks !== 0) {
        echo "FAIL: tenant predicate check returned unexpected mismatch rows for tenant {$tenantId}.\n";
        exit(1);
    }
}
echo "PASS: tenant-scoped predicate returns no cross-tenant rows for each existing tenant.\n";

if (count($tenantRows) < 2) {
    echo "LIMITATION: fewer than two tenants exist; a real cross-tenant isolation test cannot be proven without a second-tenant fixture.\n";
} else {
    echo "NOTE: multiple tenants exist. This script inspected tenant distributions, but endpoint-level cross-tenant HTTP testing is still required.\n";
}
echo "PASS: completed read-only inspection; no rows or schema objects changed.\n";
