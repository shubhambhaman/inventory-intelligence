<?php
declare(strict_types=1);

/**
 * IIP M-2 Point 4 — tenant-scoped idempotency integration test.
 *
 * Run from project root:
 *   C:\php-8.3.33-Win32-vs16-x64\php.exe test\stock_movement_idempotency_tenant_isolation_test.php
 *
 * SAFETY:
 * - CLI only; connects only to inventory_intelligence_test.
 * - Creates/reuses clearly named Tenant B test fixtures.
 * - Never deletes or updates existing records.
 * - Posts two movements and writes two audit events on a fresh run.
 * - Replays are checked without creating additional movements/events.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "STOP: CLI only.\n");
    exit(2);
}

const IIP_IDEM_TEST_DB = 'inventory_intelligence_test';
const IIP_TENANT_A_EMAIL = 'stock-movement-test@example.invalid';
const IIP_TENANT_A_SKU = 'TEST-SM-001';
const IIP_TENANT_A_WAREHOUSE = 'TEST-WH-001';

function idemAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function getOne(PDO $pdo, string $sql, array $params): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function getOrCreateTenantBFixture(PDO $pdo): array
{
    $tenantName = 'IIP M2 Idempotency Tenant B Test';
    $tenant = getOne(
        $pdo,
        'SELECT id FROM tenants WHERE name = :name LIMIT 1',
        [':name' => $tenantName]
    );

    if ($tenant === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO tenants (name, legal_name, country, currency, timezone, fiscal_year_start, status)
             VALUES (:name, :legal_name, 'India', 'INR', 'Asia/Kolkata', '2026-04-01', 'active')"
        );
        $stmt->execute([':name' => $tenantName, ':legal_name' => $tenantName]);
        $tenantId = (int) $pdo->lastInsertId();
        echo "CREATED: dedicated Tenant B fixture\n";
    } else {
        $tenantId = (int) $tenant['id'];
        echo "REUSED: dedicated Tenant B fixture\n";
    }

    $category = getOne(
        $pdo,
        'SELECT id FROM categories WHERE tenant_id = :tenant_id AND name_normalized = :normalized LIMIT 1',
        [':tenant_id' => $tenantId, ':normalized' => 'iip-idempotency-test']
    );
    if ($category === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO categories (tenant_id, name, name_normalized, status)
             VALUES (:tenant_id, 'Idempotency Test Category', 'iip-idempotency-test', 'active')"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $categoryId = (int) $pdo->lastInsertId();
    } else {
        $categoryId = (int) $category['id'];
    }

    $unit = getOne(
        $pdo,
        'SELECT id FROM units WHERE tenant_id = :tenant_id AND name_normalized = :normalized LIMIT 1',
        [':tenant_id' => $tenantId, ':normalized' => 'iip-idempotency-test-unit']
    );
    if ($unit === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO units (tenant_id, name, name_normalized, status)
             VALUES (:tenant_id, 'Idempotency Test Unit', 'iip-idempotency-test-unit', 'active')"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $unitId = (int) $pdo->lastInsertId();
    } else {
        $unitId = (int) $unit['id'];
    }

    $email = 'stock-movement-idem-tenant-b@example.invalid';
    $user = getOne(
        $pdo,
        'SELECT id, tenant_id FROM users WHERE email = :email LIMIT 1',
        [':email' => $email]
    );
    if ($user === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO users (tenant_id, email, name, password_hash, role, status)
             VALUES (:tenant_id, :email, 'Idempotency Test Tenant B User', :password_hash, 'owner', 'active')"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':email' => $email,
            ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        ]);
        $userId = (int) $pdo->lastInsertId();
    } else {
        if ((int) $user['tenant_id'] !== $tenantId) {
            throw new RuntimeException('Safety stop: reserved Tenant B test email belongs to another tenant.');
        }
        $userId = (int) $user['id'];
    }

    $sku = 'TEST-IDEM-TENANT-B-001';
    $product = getOne(
        $pdo,
        'SELECT id, tenant_id FROM products WHERE tenant_id = :tenant_id AND sku = :sku LIMIT 1',
        [':tenant_id' => $tenantId, ':sku' => $sku]
    );
    if ($product === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO products
                (tenant_id, sku, sku_normalized, name, description, category_id, unit_id, purchase_price, selling_price, status)
             VALUES
                (:tenant_id, :sku, :sku_normalized, 'Idempotency Tenant B Test Product',
                 'Dedicated fixture for Point 4 tenant-scoped idempotency test.',
                 :category_id, :unit_id, 1.000000, 2.000000, 'active')"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':sku' => $sku,
            ':sku_normalized' => $sku,
            ':category_id' => $categoryId,
            ':unit_id' => $unitId,
        ]);
        $productId = (int) $pdo->lastInsertId();
    } else {
        $productId = (int) $product['id'];
    }

    $warehouseCode = 'TEST-IDEM-TENANT-B-WH-001';
    $warehouse = getOne(
        $pdo,
        'SELECT id, tenant_id FROM warehouses WHERE tenant_id = :tenant_id AND warehouse_code = :code LIMIT 1',
        [':tenant_id' => $tenantId, ':code' => $warehouseCode]
    );
    if ($warehouse === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO warehouses (tenant_id, warehouse_code, name, status)
             VALUES (:tenant_id, :code, 'Idempotency Tenant B Test Warehouse', 'active')"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':code' => $warehouseCode]);
        $warehouseId = (int) $pdo->lastInsertId();
    } else {
        $warehouseId = (int) $warehouse['id'];
    }

    return [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
    ];
}

$root = dirname(__DIR__);

// Session bootstrap must run before ANY output, including diagnostic lines
// emitted after fixture creation. CLI tests still enforce the same invariant.
require_once $root . '/config/session.php';
require_once $root . '/config/env.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

if ($username === '') {
    fwrite(STDERR, "STOP: DB_USER missing; no database connection or write attempted.\n");
    exit(3);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, IIP_IDEM_TEST_DB, $charset),
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== IIP_IDEM_TEST_DB) {
    throw new RuntimeException('Safety stop: active database is not ' . IIP_IDEM_TEST_DB);
}
echo 'PASS: connected only to ' . IIP_IDEM_TEST_DB . PHP_EOL;

// Resolve Tenant A fixture before any write.
$tenantA = getOne(
    $pdo,
    "SELECT u.id AS user_id, u.tenant_id, p.id AS product_id, w.id AS warehouse_id
       FROM users u
       JOIN products p ON p.tenant_id = u.tenant_id AND p.sku = :sku AND p.status = 'active'
       JOIN warehouses w ON w.tenant_id = u.tenant_id AND w.warehouse_code = :warehouse_code AND w.status = 'active'
      WHERE u.email = :email AND u.status = 'active'
      LIMIT 1",
    [
        ':sku' => IIP_TENANT_A_SKU,
        ':warehouse_code' => IIP_TENANT_A_WAREHOUSE,
        ':email' => IIP_TENANT_A_EMAIL,
    ]
);
if ($tenantA === null) {
    throw new RuntimeException('Safety stop: Tenant A test fixtures are missing; no fixture writes attempted.');
}

$pdo->beginTransaction();
try {
    $tenantB = getOrCreateTenantBFixture($pdo);
    if ((int) $tenantB['tenant_id'] === (int) $tenantA['tenant_id']) {
        throw new RuntimeException('Safety stop: Tenant A and Tenant B fixture resolved to the same tenant.');
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

// Preserve the explicitly verified test connection: audit.php may load db.php
// and assign its configured connection to the top-level $pdo variable.
$testPdo = $pdo;
require_once $root . '/config/session.php';
require_once $root . '/config/audit.php';
$pdo = $testPdo;
$GLOBALS['pdo'] = $testPdo;
require_once $root . '/src/Inventory/StockMovementPostingService.php';
$pdo = $testPdo;
$GLOBALS['pdo'] = $testPdo;

if ((string) $testPdo->query('SELECT DATABASE()')->fetchColumn() !== IIP_IDEM_TEST_DB) {
    throw new RuntimeException('Safety stop: active test PDO changed before posting.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$key = 'm2-idem-cross-tenant-' . bin2hex(random_bytes(12));
$occurredAt = gmdate('Y-m-d H:i:s');

$payloadA = [
    'product_id' => (int) $tenantA['product_id'],
    'warehouse_id' => (int) $tenantA['warehouse_id'],
    'movement_type' => 'OPENING',
    'direction' => 'in',
    'quantity' => '1.000000',
    'unit_cost' => '1.000000',
    'source_type' => 'TEST',
    'source_reference' => 'IDEM-CROSS-TENANT-A-' . bin2hex(random_bytes(5)),
    'occurred_at' => $occurredAt,
    'idempotency_key' => $key,
];
$payloadB = [
    'product_id' => (int) $tenantB['product_id'],
    'warehouse_id' => (int) $tenantB['warehouse_id'],
    'movement_type' => 'OPENING',
    'direction' => 'in',
    'quantity' => '1.000000',
    'unit_cost' => '1.000000',
    'source_type' => 'TEST',
    'source_reference' => 'IDEM-CROSS-TENANT-B-' . bin2hex(random_bytes(5)),
    'occurred_at' => $occurredAt,
    'idempotency_key' => $key,
];

try {
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = (int) $tenantA['user_id'];
    $_SESSION['tenant_id'] = (int) $tenantA['tenant_id'];
    $resultA = \App\Inventory\StockMovementPostingService::post($payloadA);

    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = (int) $tenantB['user_id'];
    $_SESSION['tenant_id'] = (int) $tenantB['tenant_id'];
    $resultB = \App\Inventory\StockMovementPostingService::post($payloadB);

    idemAssert(($resultA['status'] ?? null) === 'posted' && ($resultA['idempotent_replay'] ?? null) === false,
        'Tenant A can post the shared idempotency key as a new movement');
    idemAssert(($resultB['status'] ?? null) === 'posted' && ($resultB['idempotent_replay'] ?? null) === false,
        'Tenant B can independently post the same key as a new movement');
    idemAssert((int) $resultA['movement_id'] !== (int) $resultB['movement_id'],
        'different tenants receive different movement IDs');

    $verifyMovement = $pdo->prepare(
        'SELECT id, tenant_id, status, request_hash FROM stock_movements
          WHERE tenant_id = :tenant_id AND idempotency_key = :idempotency_key'
    );
    $verifyMovement->execute([
        ':tenant_id' => (int) $tenantA['tenant_id'],
        ':idempotency_key' => $key,
    ]);
    $movementA = $verifyMovement->fetch();

    $verifyMovement->execute([
        ':tenant_id' => (int) $tenantB['tenant_id'],
        ':idempotency_key' => $key,
    ]);
    $movementB = $verifyMovement->fetch();

    idemAssert(is_array($movementA) && (int) $movementA['id'] === (int) $resultA['movement_id']
        && (int) $movementA['tenant_id'] === (int) $tenantA['tenant_id']
        && $movementA['status'] === 'posted' && preg_match('/^[a-f0-9]{64}$/i', (string) $movementA['request_hash']) === 1,
        'Tenant A key lookup returns only Tenant A movement with valid hash');
    idemAssert(is_array($movementB) && (int) $movementB['id'] === (int) $resultB['movement_id']
        && (int) $movementB['tenant_id'] === (int) $tenantB['tenant_id']
        && $movementB['status'] === 'posted' && preg_match('/^[a-f0-9]{64}$/i', (string) $movementB['request_hash']) === 1,
        'Tenant B key lookup returns only Tenant B movement with valid hash');

    $verifyAudit = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_events
          WHERE tenant_id = :tenant_id
            AND event_type = 'stock.movement_posted'
            AND entity_type = 'stock_movement'
            AND entity_id = :entity_id"
    );
    $verifyAudit->execute([
        ':tenant_id' => (int) $tenantA['tenant_id'],
        ':entity_id' => (string) $resultA['movement_id'],
    ]);
    idemAssert((int) $verifyAudit->fetchColumn() === 1, 'Tenant A movement has exactly one matching posting audit event');

    $verifyAudit->execute([
        ':tenant_id' => (int) $tenantB['tenant_id'],
        ':entity_id' => (string) $resultB['movement_id'],
    ]);
    idemAssert((int) $verifyAudit->fetchColumn() === 1, 'Tenant B movement has exactly one matching posting audit event');

    // Retry each tenant's own payload. These should replay their own movement,
    // never the other tenant's movement, and should not add new rows/events.
    $_SESSION['user_id'] = (int) $tenantA['user_id'];
    $_SESSION['tenant_id'] = (int) $tenantA['tenant_id'];
    $retryA = \App\Inventory\StockMovementPostingService::post($payloadA);

    $_SESSION['user_id'] = (int) $tenantB['user_id'];
    $_SESSION['tenant_id'] = (int) $tenantB['tenant_id'];
    $retryB = \App\Inventory\StockMovementPostingService::post($payloadB);

    idemAssert((int) $retryA['movement_id'] === (int) $resultA['movement_id']
        && ($retryA['idempotent_replay'] ?? null) === true,
        'Tenant A retry replays Tenant A movement only');
    idemAssert((int) $retryB['movement_id'] === (int) $resultB['movement_id']
        && ($retryB['idempotent_replay'] ?? null) === true,
        'Tenant B retry replays Tenant B movement only');

    echo PHP_EOL . "TENANT-SCOPED IDEMPOTENCY TEST PASSED." . PHP_EOL;
    echo "Safety note: this run creates/reuses dedicated Tenant B master-data fixtures and, on a fresh key, permanently adds two movements and two audit events in " . IIP_IDEM_TEST_DB . "." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "No existing records were deleted. Any successfully committed posting before the failure remains in the dedicated test DB.\n");
    exit(1);
}
