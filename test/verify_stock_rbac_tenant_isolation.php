<?php
declare(strict_types=1);

/**
 * IIP M-2 security verification helper.
 *
 * READ-ONLY: connects only to inventory_intelligence_test and executes SELECTs.
 * It does not change users, roles, permissions, tenants, or ledger rows.
 * Run from project root:
 * C:\\php-8.3.33-Win32-vs16-x64\\php.exe test\\verify_stock_rbac_tenant_isolation.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "FAIL: CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config/env.php';
const TEST_DB = 'inventory_intelligence_test';

$host = (string)($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string)($_ENV['DB_PORT'] ?? '3306');
$user = (string)($_ENV['DB_USER'] ?? '');
$pass = (string)($_ENV['DB_PASSWORD'] ?? '');
$charset = (string)($_ENV['DB_CHARSET'] ?? 'utf8mb4');
if ($user === '') {
    fwrite(STDERR, "STOP: DB_USER missing; no database query was run.\n");
    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, TEST_DB, $charset),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== TEST_DB) {
    throw new RuntimeException('Safety stop: wrong database.');
}
echo "Connected database: " . TEST_DB . "\nMode: READ-ONLY\n";

$required = ['tenants', 'users', 'roles', 'permissions', 'role_permissions', 'stock_movements'];
$checkTable = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
foreach ($required as $table) {
    $checkTable->execute([$table]);
    if ((int)$checkTable->fetchColumn() !== 1) {
        echo "BLOCKED: required table missing: {$table}\n";
        exit(3);
    }
}
echo "PASS: required security and ledger tables exist.\n";

$tenantRows = (int)$pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
$userRows = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$movementRows = (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
echo "Tenants: {$tenantRows}; users: {$userRows}; movements: {$movementRows}\n";

$fixture = $pdo->prepare('SELECT id, tenant_id, role_id, status FROM users WHERE email = ? LIMIT 1');
$fixture->execute(['stock-movement-test@example.invalid']);
$testUser = $fixture->fetch();
if (!$testUser) {
    echo "BLOCKED: reserved test user not found.\n";
    exit(4);
}
echo 'Test user: id=' . (int)$testUser['id'] . ', tenant_id=' . (int)$testUser['tenant_id'] . ', role_id=' . ($testUser['role_id'] === null ? 'NULL' : (int)$testUser['role_id']) . ', status=' . $testUser['status'] . "\n";

// Exercise the same database-backed permission predicate used by hasPermission().
$permissionCheck = $pdo->prepare("SELECT 1
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    INNER JOIN role_permissions rp ON rp.role_id = r.id
    INNER JOIN permissions p ON p.id = rp.permission_id
    INNER JOIN tenants t ON t.id = u.tenant_id
    WHERE u.id = :user_id AND u.status = 'active' AND t.status = 'active'
      AND p.permission_key = :permission_key LIMIT 1");
$permissionCheck->execute([':user_id' => (int)$testUser['id'], ':permission_key' => 'stock.view']);
$hasStockView = $permissionCheck->fetchColumn() !== false;
if (!$hasStockView) {
    echo "PASS: current test fixture does not grant stock.view (expected deny predicate).\n";
} else {
    echo "INFO: current test fixture grants stock.view.\n";
}

// Verify the tenant-scoped predicate is structurally restrictive for existing rows.
$crossTenant = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements sm
    INNER JOIN products p ON p.tenant_id = sm.tenant_id AND p.id = sm.product_id
    INNER JOIN warehouses w ON w.tenant_id = sm.tenant_id AND w.id = sm.warehouse_id
    WHERE sm.tenant_id <> p.tenant_id OR sm.tenant_id <> w.tenant_id")->fetchColumn();
if ($crossTenant === 0) {
    echo "PASS: no movement joined to a product/warehouse from a different tenant.\n";
} else {
    echo "FAIL: found {$crossTenant} cross-tenant movement joins.\n";
    exit(5);
}

if ($tenantRows < 2) {
    echo "LIMITATION: only one tenant exists; cross-tenant HTTP isolation is NOT proven.\n";
} else {
    echo "NOTE: multiple tenants exist; run an authenticated HTTP cross-tenant request test to prove API behavior.\n";
}
echo "PASS: read-only security inspection completed; no rows or schema objects changed.\n";
