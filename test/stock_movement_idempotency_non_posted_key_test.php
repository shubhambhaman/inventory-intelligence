<?php

declare(strict_types=1);

/**
 * IIP M-2 Point 4 — reject replay for a non-posted idempotency key.
 *
 * Run from project root:
 *   C:\php-8.3.33-Win32-vs16-x64\php.exe test\stock_movement_idempotency_non_posted_key_test.php
 *
 * SAFETY:
 * - Connects only to inventory_intelligence_test.
 * - Does not delete or update existing rows.
 * - Inserts one dedicated PENDING test movement; it remains in the test DB.
 * - Does not create a posting audit event.
 */

const TEST_DATABASE = 'inventory_intelligence_test';
const TEST_USER_EMAIL = 'stock-movement-test@example.invalid';
const TEST_PRODUCT_SKU = 'TEST-SM-001';
const TEST_WAREHOUSE_CODE = 'TEST-WH-001';

function idempotencyTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }

    echo 'PASS: ' . $message . PHP_EOL;
}

$projectRoot = dirname(__DIR__);

/*
 * Session bootstrap must run before any output. This test uses the same
 * authenticated session context expected by the internal posting service.
 */
require_once $projectRoot . '/config/env.php';
require_once $projectRoot . '/config/session.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

if ($username === '') {
    throw new RuntimeException('DB_USER missing in config/env.php; stopped before connecting.');
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $host,
    $port,
    TEST_DATABASE,
    $charset
);

$testPdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$dbName = (string) $testPdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== TEST_DATABASE) {
    throw new RuntimeException('Safety stop: connected database is not ' . TEST_DATABASE);
}
echo 'PASS: connected only to ' . TEST_DATABASE . PHP_EOL;

require_once $projectRoot . '/config/audit.php';
$GLOBALS['pdo'] = $testPdo;
$pdo = $testPdo;
require_once $projectRoot . '/src/Inventory/StockMovementPostingService.php';

$fixtureQuery = $testPdo->prepare(
    "SELECT u.id AS user_id, u.tenant_id, p.id AS product_id, w.id AS warehouse_id
       FROM users u
       JOIN products p
         ON p.tenant_id = u.tenant_id
        AND p.sku = :sku
        AND p.status = 'active'
       JOIN warehouses w
         ON w.tenant_id = u.tenant_id
        AND w.warehouse_code = :warehouse_code
        AND w.status = 'active'
      WHERE u.email = :email
        AND u.status = 'active'
      LIMIT 1"
);
$fixtureQuery->execute([
    ':sku' => TEST_PRODUCT_SKU,
    ':warehouse_code' => TEST_WAREHOUSE_CODE,
    ':email' => TEST_USER_EMAIL,
]);
$fixture = $fixtureQuery->fetch();

if (!$fixture) {
    throw new RuntimeException('Required active test fixtures are missing; no movement write attempted.');
}

$_SESSION['authenticated'] = true;
$_SESSION['user_id'] = (int) $fixture['user_id'];
$_SESSION['tenant_id'] = (int) $fixture['tenant_id'];

$payload = [
    'product_id' => (int) $fixture['product_id'],
    'warehouse_id' => (int) $fixture['warehouse_id'],
    'movement_type' => 'OPENING',
    'direction' => 'in',
    'quantity' => '2.000000',
    'unit_cost' => '1.000000',
    'source_type' => 'TEST',
    'source_reference' => 'SM-NONPOSTED-' . bin2hex(random_bytes(8)),
    'occurred_at' => gmdate('Y-m-d H:i:s'),
    'idempotency_key' => 'sm-nonposted-' . bin2hex(random_bytes(16)),
];

$normalizedPayload = \App\Inventory\StockMovementModel::validateAndNormalize($payload);
$requestJson = json_encode(
    $normalizedPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$requestHash = hash('sha256', $requestJson);

$insert = $testPdo->prepare(
    "INSERT INTO stock_movements (
        tenant_id,
        product_id,
        warehouse_id,
        movement_type,
        direction,
        quantity,
        unit_cost,
        source_type,
        source_reference,
        status,
        idempotency_key,
        request_hash,
        created_by,
        posted_by,
        occurred_at,
        posted_at
    ) VALUES (
        :tenant_id,
        :product_id,
        :warehouse_id,
        :movement_type,
        :direction,
        :quantity,
        :unit_cost,
        :source_type,
        :source_reference,
        'pending',
        :idempotency_key,
        :request_hash,
        :created_by,
        NULL,
        :occurred_at,
        NULL
    )"
);
$insert->execute([
    ':tenant_id' => (int) $fixture['tenant_id'],
    ':product_id' => $normalizedPayload['product_id'],
    ':warehouse_id' => $normalizedPayload['warehouse_id'],
    ':movement_type' => $normalizedPayload['movement_type'],
    ':direction' => $normalizedPayload['direction'],
    ':quantity' => $normalizedPayload['quantity'],
    ':unit_cost' => $normalizedPayload['unit_cost'],
    ':source_type' => $normalizedPayload['source_type'],
    ':source_reference' => $normalizedPayload['source_reference'],
    ':idempotency_key' => $normalizedPayload['idempotency_key'],
    ':request_hash' => $requestHash,
    ':created_by' => (int) $fixture['user_id'],
    ':occurred_at' => $normalizedPayload['occurred_at'],
]);

$pendingMovementId = (int) $testPdo->lastInsertId();
idempotencyTestAssert($pendingMovementId > 0, 'dedicated pending movement fixture was created');

$countForKey = $testPdo->prepare(
    'SELECT COUNT(*) FROM stock_movements WHERE tenant_id = :tenant_id AND idempotency_key = :key'
);
$countForKey->execute([
    ':tenant_id' => (int) $fixture['tenant_id'],
    ':key' => $normalizedPayload['idempotency_key'],
]);
$rowsBefore = (int) $countForKey->fetchColumn();

$auditCountQuery = $testPdo->prepare(
    "SELECT COUNT(*)
       FROM audit_events
      WHERE tenant_id = :tenant_id
        AND event_type = 'stock.movement_posted'"
);
$auditCountQuery->execute([':tenant_id' => (int) $fixture['tenant_id']]);
$auditBefore = (int) $auditCountQuery->fetchColumn();

$conflictCaught = false;
try {
    \App\Inventory\StockMovementPostingService::post($payload);
} catch (\App\Inventory\StockMovementConflictException $e) {
    $conflictCaught = str_contains(strtolower($e->getMessage()), 'not posted');
}

idempotencyTestAssert(
    $conflictCaught,
    'reusing a matching key for a pending movement is rejected as a conflict'
);

$countForKey->execute([
    ':tenant_id' => (int) $fixture['tenant_id'],
    ':key' => $normalizedPayload['idempotency_key'],
]);
$rowsAfter = (int) $countForKey->fetchColumn();

$auditCountQuery->execute([':tenant_id' => (int) $fixture['tenant_id']]);
$auditAfter = (int) $auditCountQuery->fetchColumn();

$verify = $testPdo->prepare(
    'SELECT id, status, request_hash FROM stock_movements WHERE tenant_id = :tenant_id AND id = :id'
);
$verify->execute([
    ':tenant_id' => (int) $fixture['tenant_id'],
    ':id' => $pendingMovementId,
]);
$stored = $verify->fetch();

idempotencyTestAssert(
    $rowsAfter === $rowsBefore && is_array($stored) && (int) $stored['id'] === $pendingMovementId,
    'rejected retry does not create a second row for the key'
);
idempotencyTestAssert(
    is_array($stored) && $stored['status'] === 'pending' && hash_equals($requestHash, (string) $stored['request_hash']),
    'existing pending movement remains unchanged with its original hash'
);
idempotencyTestAssert(
    $auditAfter === $auditBefore,
    'rejected retry adds no stock.movement_posted audit event'
);

echo PHP_EOL . 'NON-POSTED IDEMPOTENCY KEY TEST PASSED.' . PHP_EOL;
echo 'Safety note: one PENDING test movement remains permanently in inventory_intelligence_test; no existing rows were deleted or updated.' . PHP_EOL;
