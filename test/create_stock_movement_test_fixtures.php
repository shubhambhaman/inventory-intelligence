<?php
declare(strict_types=1);

/**
 * IIP M-2 — create the minimum fixtures for stock movement integration tests.
 *
 * Run from project root:
 *   C:\php-8.3.33-Win32-vs16-x64\php.exe test\create_stock_movement_test_fixtures.php
 *
 * SAFETY:
 * - Refuses to write unless the selected database is exactly inventory_intelligence_test.
 * - Creates only clearly named test records.
 * - Does not create tables, run migrations, or write stock movements/audit events.
 * - Existing fixture records are reused only when they belong to the same test tenant.
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/env.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');
$expectedDatabase = 'inventory_intelligence_test';

if ($username === '') {
    fwrite(STDERR, "STOP: DB_USER is missing. No database write attempted.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $expectedDatabase, $charset),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actualDatabase !== $expectedDatabase) {
        throw new RuntimeException('Safety stop: selected database is not ' . $expectedDatabase);
    }

    // Confirm required tables exist before starting any writes.
    $requiredTables = ['tenants', 'users', 'categories', 'units', 'products', 'warehouses'];
    $tableCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table_name'
    );
    foreach ($requiredTables as $table) {
        $tableCheck->execute([':schema' => $expectedDatabase, ':table_name' => $table]);
        if ((int) $tableCheck->fetchColumn() !== 1) {
            throw new RuntimeException("Safety stop: required table missing: {$table}. Run the approved test-DB migrations first.");
        }
    }

    $pdo->beginTransaction();

    // Stable test tenant. Reuse by exact name; never adopt an arbitrary tenant.
    $tenantName = 'IIP Stock Movement Integration Test';
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE name = :name LIMIT 1 FOR UPDATE');
    $stmt->execute([':name' => $tenantName]);
    $tenantId = $stmt->fetchColumn();

    if ($tenantId === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO tenants (name, legal_name, country, currency, timezone, fiscal_year_start, status)
             VALUES (:name, :legal_name, 'India', 'INR', 'Asia/Kolkata', '2026-04-01', 'active')"
        );
        $stmt->execute([
            ':name' => $tenantName,
            ':legal_name' => $tenantName,
        ]);
        $tenantId = (int) $pdo->lastInsertId();
        echo "CREATED: test tenant\n";
    } else {
        $tenantId = (int) $tenantId;
        echo "REUSED: test tenant\n";
    }

    // Category and unit are required product parents and are tenant-scoped.
    $stmt = $pdo->prepare(
        'SELECT id FROM categories WHERE tenant_id = :tenant_id AND name_normalized = :normalized LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':tenant_id' => $tenantId, ':normalized' => 'test-stock-movement']);
    $categoryId = $stmt->fetchColumn();

    if ($categoryId === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO categories (tenant_id, name, name_normalized, status)
             VALUES (:tenant_id, 'Test Stock Movement', 'test-stock-movement', 'active')"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $categoryId = (int) $pdo->lastInsertId();
        echo "CREATED: test category\n";
    } else {
        $categoryId = (int) $categoryId;
        echo "REUSED: test category\n";
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM units WHERE tenant_id = :tenant_id AND name_normalized = :normalized LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':tenant_id' => $tenantId, ':normalized' => 'test-unit']);
    $unitId = $stmt->fetchColumn();

    if ($unitId === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO units (tenant_id, name, name_normalized, status)
             VALUES (:tenant_id, 'Test Unit', 'test-unit', 'active')"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $unitId = (int) $pdo->lastInsertId();
        echo "CREATED: test unit\n";
    } else {
        $unitId = (int) $unitId;
        echo "REUSED: test unit\n";
    }

    // Test user. role_id is nullable in the supplied schema; role defaults to owner.
    $testEmail = 'stock-movement-test@example.invalid';
    $stmt = $pdo->prepare('SELECT id, tenant_id FROM users WHERE email = :email LIMIT 1 FOR UPDATE');
    $stmt->execute([':email' => $testEmail]);
    $user = $stmt->fetch();

    if ($user === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO users (tenant_id, email, name, password_hash, role, status)
             VALUES (:tenant_id, :email, :name, :password_hash, 'owner', 'active')"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':email' => $testEmail,
            ':name' => 'Stock Movement Test User',
            // Not used for login by this CLI integration test.
            ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        ]);
        $userId = (int) $pdo->lastInsertId();
        echo "CREATED: test user\n";
    } else {
        if ((int) $user['tenant_id'] !== $tenantId) {
            throw new RuntimeException('Safety stop: the reserved test email already belongs to a different tenant.');
        }
        $userId = (int) $user['id'];
        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->execute([':id' => $userId, ':tenant_id' => $tenantId]);
        echo "REUSED: test user\n";
    }

    $testSku = 'TEST-SM-001';
    $stmt = $pdo->prepare('SELECT id, tenant_id FROM products WHERE sku = :sku LIMIT 1 FOR UPDATE');
    $stmt->execute([':sku' => $testSku]);
    $product = $stmt->fetch();

    if ($product === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO products
                (tenant_id, sku, sku_normalized, name, description, category_id, unit_id, purchase_price, selling_price, status)
             VALUES
                (:tenant_id, :sku, :sku_normalized, :name, :description, :category_id, :unit_id, 2.500000, 3.000000, 'active')"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':sku' => $testSku,
            ':sku_normalized' => 'TEST-SM-001',
            ':name' => 'Stock Movement Integration Test Product',
            ':description' => 'Dedicated automated integration-test fixture.',
            ':category_id' => $categoryId,
            ':unit_id' => $unitId,
        ]);
        $productId = (int) $pdo->lastInsertId();
        echo "CREATED: test product\n";
    } else {
        if ((int) $product['tenant_id'] !== $tenantId) {
            throw new RuntimeException('Safety stop: reserved test SKU already belongs to a different tenant.');
        }
        $productId = (int) $product['id'];
        $stmt = $pdo->prepare(
            "UPDATE products SET status = 'active', category_id = :category_id, unit_id = :unit_id
              WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute([
            ':category_id' => $categoryId,
            ':unit_id' => $unitId,
            ':id' => $productId,
            ':tenant_id' => $tenantId,
        ]);
        echo "REUSED: test product\n";
    }

    $testWarehouseCode = 'TEST-WH-001';
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id FROM warehouses WHERE warehouse_code = :code LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':code' => $testWarehouseCode]);
    $warehouse = $stmt->fetch();

    if ($warehouse === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO warehouses (tenant_id, warehouse_code, name, status)
             VALUES (:tenant_id, :code, 'Stock Movement Test Warehouse', 'active')"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':code' => $testWarehouseCode]);
        $warehouseId = (int) $pdo->lastInsertId();
        echo "CREATED: test warehouse\n";
    } else {
        if ((int) $warehouse['tenant_id'] !== $tenantId) {
            throw new RuntimeException('Safety stop: reserved warehouse code already belongs to a different tenant.');
        }
        $warehouseId = (int) $warehouse['id'];
        $stmt = $pdo->prepare(
            "UPDATE warehouses SET status = 'active' WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute([':id' => $warehouseId, ':tenant_id' => $tenantId]);
        echo "REUSED: test warehouse\n";
    }

    $pdo->commit();

    echo PHP_EOL . "PASS: test fixtures are ready in {$expectedDatabase}." . PHP_EOL;
    echo "Tenant ID: {$tenantId}; User ID: {$userId}; Product ID: {$productId}; Warehouse ID: {$warehouseId}" . PHP_EOL;
    echo "No stock movement or audit event was created." . PHP_EOL;
    echo "Next: run test\\stock_movement_test_preflight.php, then review its output before running the posting integration test." . PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'STOP: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "If a transaction was open, it was rolled back. No posting integration test was run." . PHP_EOL);
    exit(1);
}
