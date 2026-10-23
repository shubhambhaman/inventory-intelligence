<?php

declare(strict_types=1);

/** Read-only fixture diagnostic for IIP M-2 Point 4. */
const TEST_DATABASE = 'inventory_intelligence_test';
const TEST_USER_EMAIL = 'stock-movement-test@example.invalid';
const TEST_PRODUCT_SKU = 'TEST-SM-001';
const TEST_WAREHOUSE_CODE = 'TEST-WH-001';

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/env.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');
if ($username === '') {
    throw new RuntimeException('DB_USER missing in config/env.php.');
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, TEST_DATABASE, $charset);
$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($db !== TEST_DATABASE) {
    throw new RuntimeException('Safety stop: connected database is not ' . TEST_DATABASE);
}
echo 'PASS: connected only to ' . $db . PHP_EOL;

$sql = "SELECT u.id AS user_id, u.tenant_id AS user_tenant_id, u.status AS user_status,
               p.id AS product_id, p.tenant_id AS product_tenant_id, p.sku, p.status AS product_status,
               w.id AS warehouse_id, w.tenant_id AS warehouse_tenant_id,
               w.warehouse_code, w.status AS warehouse_status
          FROM users u
          JOIN products p ON p.tenant_id = u.tenant_id AND p.sku = :sku
          JOIN warehouses w ON w.tenant_id = u.tenant_id AND w.warehouse_code = :warehouse_code
         WHERE u.email = :email";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':sku' => TEST_PRODUCT_SKU,
    ':warehouse_code' => TEST_WAREHOUSE_CODE,
    ':email' => TEST_USER_EMAIL,
]);
$rows = $stmt->fetchAll();
if (!$rows) {
    echo "FAIL: no matching user/product/warehouse fixture found." . PHP_EOL;
    exit(1);
}
foreach ($rows as $i => $row) {
    echo 'Fixture #' . ($i + 1) . ': ' . json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $tenantConsistent = (string) $row['user_tenant_id'] === (string) $row['product_tenant_id']
        && (string) $row['user_tenant_id'] === (string) $row['warehouse_tenant_id'];
    $active = $row['user_status'] === 'active'
        && $row['product_status'] === 'active'
        && $row['warehouse_status'] === 'active';
    echo ($tenantConsistent ? 'PASS' : 'FAIL') . ': fixture tenant IDs ' . ($tenantConsistent ? 'match' : 'do not match') . PHP_EOL;
    echo ($active ? 'PASS' : 'FAIL') . ': fixture statuses ' . ($active ? 'are active' : 'include a non-active value') . PHP_EOL;
}

$check = $pdo->prepare('SELECT id, tenant_id, status, HEX(status) AS status_hex FROM products WHERE sku = :sku');
$check->execute([':sku' => TEST_PRODUCT_SKU]);
foreach ($check->fetchAll() as $row) {
    echo 'Product status raw check: ' . json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
echo 'READ-ONLY diagnostic complete; no rows were inserted, updated, or deleted.' . PHP_EOL;
