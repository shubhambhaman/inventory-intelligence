<?php

declare(strict_types=1);

/**
 * IIP M-2 — Stock Movement Posting integration test.
 *
 * Run from project root:
 *   C:\php-8.3.33-Win32-vs16-x64\php.exe test\stock_movement_posting_integration_test.php
 *
 * SAFETY: connects directly to inventory_intelligence_test only. It refuses
 * to run against any other database. A successful run writes one movement
 * and one audit event; it never deletes existing data.
 *
 * Required pre-created fixtures in the test DB:
 *   user email: stock-movement-test@example.invalid (active)
 *   product SKU: TEST-SM-001 (active, same tenant)
 *   warehouse code: TEST-WH-001 (active, same tenant)
 */

const TEST_DATABASE = 'inventory_intelligence_test';
const TEST_USER_EMAIL = 'stock-movement-test@example.invalid';
const TEST_PRODUCT_SKU = 'TEST-SM-001';
const TEST_WAREHOUSE_CODE = 'TEST-WH-001';

function testAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function expectPostedMutationBlocked(PDO $pdo, int $movementId, string $operation): void
{
    try {
        if ($operation === 'update') {
            $stmt = $pdo->prepare(
                "UPDATE stock_movements SET quantity = '99.000000' WHERE id = :id AND status = 'posted'"
            );
        } else {
            $stmt = $pdo->prepare(
                "DELETE FROM stock_movements WHERE id = :id AND status = 'posted'"
            );
        }
        $stmt->execute([':id' => $movementId]);
    } catch (PDOException $e) {
        echo 'PASS: posted movement direct ' . $operation . ' is blocked' . PHP_EOL;
        return;
    }

    throw new RuntimeException(
        'FAIL: posted movement direct ' . $operation . ' was not blocked by the database'
    );
}

$projectRoot = dirname(__DIR__);
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
       JOIN products p ON p.tenant_id = u.tenant_id AND p.sku = :sku AND p.status = 'active'
       JOIN warehouses w ON w.tenant_id = u.tenant_id AND w.warehouse_code = :warehouse_code AND w.status = 'active'
      WHERE u.email = :email AND u.status = 'active'
      LIMIT 1"
);
$fixtureQuery->execute([
    ':sku' => TEST_PRODUCT_SKU,
    ':warehouse_code' => TEST_WAREHOUSE_CODE,
    ':email' => TEST_USER_EMAIL,
]);
$fixture = $fixtureQuery->fetch();
if (!$fixture) {
    throw new RuntimeException(
        'Required test fixtures are missing. No movement/audit writes were attempted.'
    );
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['authenticated'] = true;
$_SESSION['user_id'] = (int) $fixture['user_id'];
$_SESSION['tenant_id'] = (int) $fixture['tenant_id'];

$payload = [
    'product_id' => (int) $fixture['product_id'],
    'warehouse_id' => (int) $fixture['warehouse_id'],
    'movement_type' => 'OPENING',
    'direction' => 'in',
    'quantity' => '10.000000',
    'unit_cost' => '2.500000',
    'source_type' => 'TEST',
    'source_reference' => 'SM-IT-' . bin2hex(random_bytes(8)),
    'occurred_at' => gmdate('Y-m-d H:i:s'),
    'idempotency_key' => 'sm-it-' . bin2hex(random_bytes(16)),
];

$movementCountBefore = (int) $testPdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
$auditCountBefore = (int) $testPdo->query(
    "SELECT COUNT(*) FROM audit_events WHERE event_type = 'stock.movement_posted'"
)->fetchColumn();

$first = \App\Inventory\StockMovementPostingService::post($payload);
$movementId = (int) ($first['movement_id'] ?? 0);
testAssert($movementId > 0, 'first post returns movement_id');
testAssert(($first['status'] ?? null) === 'posted', 'first movement is posted');
testAssert(($first['idempotent_replay'] ?? null) === false, 'first post is not a replay');

$retry = \App\Inventory\StockMovementPostingService::post($payload);
testAssert((int) ($retry['movement_id'] ?? 0) === $movementId, 'same payload/key returns same movement');
testAssert(($retry['idempotent_replay'] ?? null) === true, 'identical retry is marked as replay');

$changed = $payload;
$changed['quantity'] = '11.000000';
$conflictCaught = false;
try {
    \App\Inventory\StockMovementPostingService::post($changed);
} catch (\App\Inventory\StockMovementConflictException $e) {
    $conflictCaught = true;
}
testAssert($conflictCaught, 'same key with changed payload is rejected as conflict');

expectPostedMutationBlocked($testPdo, $movementId, 'update');
expectPostedMutationBlocked($testPdo, $movementId, 'delete');

$movementCountAfter = (int) $testPdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
$auditCountAfter = (int) $testPdo->query(
    "SELECT COUNT(*) FROM audit_events WHERE event_type = 'stock.movement_posted'"
)->fetchColumn();

$verify = $testPdo->prepare(
    'SELECT status, quantity FROM stock_movements WHERE tenant_id = :tenant_id AND id = :id'
);
$verify->execute([':tenant_id' => (int) $fixture['tenant_id'], ':id' => $movementId]);
$row = $verify->fetch();

testAssert($movementCountAfter === $movementCountBefore + 1, 'only one ledger movement was added');
testAssert($auditCountAfter === $auditCountBefore + 1, 'only one posting audit event was added');
testAssert(
    is_array($row) && $row['status'] === 'posted' && (string) $row['quantity'] === '10.000000',
    'posted movement quantity/status remain unchanged'
);

// Negative-stock guard: request more than the complete current ledger balance.
$balanceQuery = $testPdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END), 0)
       FROM stock_movements
      WHERE tenant_id = :tenant_id AND product_id = :product_id
        AND warehouse_id = :warehouse_id AND status = 'posted'"
);
$balanceQuery->execute([
    ':tenant_id' => (int) $fixture['tenant_id'],
    ':product_id' => (int) $fixture['product_id'],
    ':warehouse_id' => (int) $fixture['warehouse_id'],
]);
$currentBalance = (float) $balanceQuery->fetchColumn();
$tooMuch = number_format($currentBalance + 1, 6, '.', '');
$rejectPayload = [
    'product_id' => (int) $fixture['product_id'],
    'warehouse_id' => (int) $fixture['warehouse_id'],
    'movement_type' => 'SALE',
    'quantity' => $tooMuch,
    'source_type' => 'TEST',
    'source_reference' => 'SM-NEG-' . bin2hex(random_bytes(8)),
    'occurred_at' => gmdate('Y-m-d H:i:s'),
    'idempotency_key' => 'sm-neg-' . bin2hex(random_bytes(16)),
];
$beforeRejectedMovementCount = (int) $testPdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
$beforeRejectedAuditCount = (int) $testPdo->query(
    "SELECT COUNT(*) FROM audit_events WHERE event_type = 'stock.movement_posted'"
)->fetchColumn();
$insufficientCaught = false;
try {
    \App\Inventory\StockMovementPostingService::post($rejectPayload);
} catch (\App\Inventory\StockMovementConflictException $e) {
    $insufficientCaught = str_contains(strtolower($e->getMessage()), 'insufficient stock');
}
testAssert($insufficientCaught, 'outbound movement exceeding available stock is rejected');
$afterRejectedMovementCount = (int) $testPdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
$afterRejectedAuditCount = (int) $testPdo->query(
    "SELECT COUNT(*) FROM audit_events WHERE event_type = 'stock.movement_posted'"
)->fetchColumn();
testAssert(
    $afterRejectedMovementCount === $beforeRejectedMovementCount,
    'rejected outbound movement creates no ledger row'
);
testAssert(
    $afterRejectedAuditCount === $beforeRejectedAuditCount,
    'rejected outbound movement creates no posting audit event'
);

echo PHP_EOL . 'ALL AVAILABLE INTEGRATION CHECKS PASSED' . PHP_EOL;
echo 'Note: this test writes one permanent movement and audit event in the dedicated test DB.' . PHP_EOL;
