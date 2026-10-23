<?php

declare(strict_types=1);

/**
 * IIP M-2 Point 4 — lock-timeout retry and exhaustion test.
 *
 * Run from project root:
 *   C:\php-8.3.33-Win32-vs16-x64\php.exe test\stock_movement_idempotency_retry_test.php
 *
 * SAFETY:
 * - Connects only to inventory_intelligence_test.
 * - Never deletes or updates existing business rows.
 * - First scenario is expected to post one OPENING movement + one audit event
 *   after a temporary product-row lock is released.
 * - Second scenario holds the product lock through bounded retries and expects
 *   no movement/audit write for that failed request.
 */

const TEST_DATABASE = 'inventory_intelligence_test';
const TEST_USER_EMAIL = 'stock-movement-test@example.invalid';
const TEST_PRODUCT_SKU = 'TEST-SM-001';
const TEST_WAREHOUSE_CODE = 'TEST-WH-001';

function retryTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function retryTestConnect(string $projectRoot): PDO
{
    require_once $projectRoot . '/config/env.php';

    $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
    $port = (string) ($_ENV['DB_PORT'] ?? '3306');
    $username = (string) ($_ENV['DB_USER'] ?? '');
    $password = (string) ($_ENV['DB_PASSWORD'] ?? '');
    $charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

    if ($username === '') {
        throw new RuntimeException('DB_USER missing in config/env.php.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $host,
        $port,
        TEST_DATABASE,
        $charset
    );

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== TEST_DATABASE) {
        throw new RuntimeException('Safety stop: connected database is not ' . TEST_DATABASE);
    }

    return $pdo;
}

function retryTestFixture(PDO $pdo): array
{
    $stmt = $pdo->prepare(
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
    $stmt->execute([
        ':sku' => TEST_PRODUCT_SKU,
        ':warehouse_code' => TEST_WAREHOUSE_CODE,
        ':email' => TEST_USER_EMAIL,
    ]);
    $fixture = $stmt->fetch();

    if (!$fixture) {
        throw new RuntimeException('Required active test fixtures missing; no posting attempted.');
    }

    return $fixture;
}

function retryTestAuditCount(PDO $pdo, int $tenantId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM audit_events
          WHERE tenant_id = :tenant_id
            AND event_type = 'stock.movement_posted'"
    );
    $stmt->execute([':tenant_id' => $tenantId]);
    return (int) $stmt->fetchColumn();
}

/*
 * Child worker mode: hold the product row lock, then release it automatically
 * after the requested duration or when the parent creates the release file.
 * It writes no business rows.
 */
if (($argv[1] ?? '') === '--lock-holder') {
    $projectRoot = dirname(__DIR__);
    $productId = (int) ($argv[2] ?? 0);
    $tenantId = (int) ($argv[3] ?? 0);
    $readyFile = (string) ($argv[4] ?? '');
    $releaseFile = (string) ($argv[5] ?? '');
    $holdSeconds = (float) ($argv[6] ?? 0);

    try {
        if ($productId <= 0 || $tenantId <= 0 || $readyFile === '' || $releaseFile === '') {
            throw new RuntimeException('Invalid lock-holder arguments.');
        }

        $lockPdo = retryTestConnect($projectRoot);
        $lockPdo->beginTransaction();
        $lockStmt = $lockPdo->prepare(
            'SELECT id FROM products WHERE tenant_id = :tenant_id AND id = :product_id FOR UPDATE'
        );
        $lockStmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
        ]);

        if (!$lockStmt->fetch()) {
            throw new RuntimeException('Lock-holder could not find the target product row.');
        }

        file_put_contents($readyFile, 'LOCKED');
        $deadline = microtime(true) + max(0.1, $holdSeconds);
        while (microtime(true) < $deadline && !is_file($releaseFile)) {
            usleep(20000);
        }

        if ($lockPdo->inTransaction()) {
            $lockPdo->commit();
        }
        exit(0);
    } catch (Throwable $e) {
        @file_put_contents($readyFile, 'ERROR: ' . $e->getMessage());
        fwrite(STDERR, 'LOCK HOLDER ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(2);
    }
}

$projectRoot = dirname(__DIR__);

/* Load session bootstrap before any output, as required by config/session.php. */
require_once $projectRoot . '/config/session.php';

$pdo = retryTestConnect($projectRoot);
$fixture = retryTestFixture($pdo);
$tenantId = (int) $fixture['tenant_id'];
$productId = (int) $fixture['product_id'];
$warehouseId = (int) $fixture['warehouse_id'];
$userId = (int) $fixture['user_id'];

require_once $projectRoot . '/config/audit.php';
$GLOBALS['pdo'] = $pdo;
require_once $projectRoot . '/src/Inventory/StockMovementPostingService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['authenticated'] = true;
$_SESSION['user_id'] = $userId;
$_SESSION['tenant_id'] = $tenantId;

/*
 * A one-second InnoDB lock wait makes the timeout test bounded and avoids
 * waiting for the server's default lock timeout.
 */
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

echo 'PASS: connected only to ' . TEST_DATABASE . PHP_EOL;

$baseTemp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'iip-idem-retry-' . bin2hex(random_bytes(6));

$startLockHolder = static function (float $holdSeconds) use (
    $baseTemp,
    $productId,
    $tenantId,
    $projectRoot
): array {
    $suffix = bin2hex(random_bytes(3));
    $readyFile = $baseTemp . '-' . $suffix . '.ready';
    $releaseFile = $baseTemp . '-' . $suffix . '.release';

    $command = [
        PHP_BINARY,
        __FILE__,
        '--lock-holder',
        (string) $productId,
        (string) $tenantId,
        $readyFile,
        $releaseFile,
        (string) $holdSeconds,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $projectRoot);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the product-row lock-holder worker.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + 10.0;
    while (!is_file($readyFile) && microtime(true) < $deadline) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException(
                'Lock-holder exited before acquiring the lock. ' . trim($stdout . ' ' . $stderr)
            );
        }
        usleep(20000);
    }

    $ready = is_file($readyFile) ? (string) file_get_contents($readyFile) : '';
    if ($ready !== 'LOCKED') {
        @file_put_contents($releaseFile, 'release');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        throw new RuntimeException(
            'Lock-holder did not confirm its lock. ' . trim($ready . ' ' . $stdout . ' ' . $stderr)
        );
    }

    return [$process, $pipes, $readyFile, $releaseFile];
};

$stopLockHolder = static function (array $holder): void {
    [$process, $pipes, $readyFile, $releaseFile] = $holder;
    @file_put_contents($releaseFile, 'release');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($readyFile);
    @unlink($releaseFile);

    if ($exitCode !== 0) {
        throw new RuntimeException('Lock-holder exited unexpectedly: ' . trim($stdout . ' ' . $stderr));
    }
};

$makePayload = static function (string $prefix) use ($productId, $warehouseId): array {
    return [
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'movement_type' => 'OPENING',
        'direction' => 'in',
        'quantity' => '1.000000',
        'unit_cost' => '1.000000',
        'source_type' => 'TEST',
        'source_reference' => $prefix . '-' . bin2hex(random_bytes(8)),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'idempotency_key' => $prefix . '-' . bin2hex(random_bytes(16)),
    ];
};

/* Scenario A: a temporary lock causes one timeout, then retry succeeds. */
$transientPayload = $makePayload('sm-retry-transient');
$transientHolder = $startLockHolder(1.55);
try {
    $startedAt = microtime(true);
    $transientResult = \App\Inventory\StockMovementPostingService::post($transientPayload);
    $elapsed = microtime(true) - $startedAt;
} finally {
    $stopLockHolder($transientHolder);
}

retryTestAssert(
    ($transientResult['status'] ?? null) === 'posted'
        && ($transientResult['idempotent_replay'] ?? null) === false
        && (int) ($transientResult['movement_id'] ?? 0) > 0,
    'posting succeeds after a transient product-row lock timeout and retry'
);
retryTestAssert(
    $elapsed >= 0.9,
    'transient-lock scenario waited for the lock and did not simply skip the lock'
);

$movementId = (int) $transientResult['movement_id'];
$verifyMovement = $pdo->prepare(
    'SELECT status, quantity FROM stock_movements WHERE tenant_id = :tenant_id AND id = :id'
);
$verifyMovement->execute([':tenant_id' => $tenantId, ':id' => $movementId]);
$postedRow = $verifyMovement->fetch();
retryTestAssert(
    is_array($postedRow)
        && $postedRow['status'] === 'posted'
        && (string) $postedRow['quantity'] === '1.000000',
    'successful retry leaves exactly the expected posted movement'
);

/* Scenario B: keep the product lock through all three bounded attempts. */
$exhaustPayload = $makePayload('sm-retry-exhaust');
$beforeKeyCount = $pdo->prepare(
    'SELECT COUNT(*) FROM stock_movements WHERE tenant_id = :tenant_id AND idempotency_key = :key'
);
$beforeKeyCount->execute([':tenant_id' => $tenantId, ':key' => $exhaustPayload['idempotency_key']]);
$keyRowsBefore = (int) $beforeKeyCount->fetchColumn();
$auditBefore = retryTestAuditCount($pdo, $tenantId);

$exhaustHolder = $startLockHolder(8.0);
$lockTimeoutCaught = false;
$elapsedExhaust = 0.0;
$mysqlError = 0;
try {
    $startedAt = microtime(true);
    try {
        \App\Inventory\StockMovementPostingService::post($exhaustPayload);
    } catch (PDOException $e) {
        $elapsedExhaust = microtime(true) - $startedAt;
        $mysqlError = (int) ($e->errorInfo[1] ?? 0);
        $lockTimeoutCaught = $mysqlError === 1205;
    }
} finally {
    $stopLockHolder($exhaustHolder);
}

retryTestAssert(
    $lockTimeoutCaught,
    'lock timeout is propagated after bounded retries are exhausted'
);
retryTestAssert(
    $elapsedExhaust >= 2.0,
    'failure occurred after multiple lock-wait attempts rather than the first timeout'
);

$beforeKeyCount->execute([':tenant_id' => $tenantId, ':key' => $exhaustPayload['idempotency_key']]);
$keyRowsAfter = (int) $beforeKeyCount->fetchColumn();
$auditAfter = retryTestAuditCount($pdo, $tenantId);

retryTestAssert(
    $keyRowsAfter === $keyRowsBefore,
    'exhausted retries leave no movement row for the failed idempotency key'
);
retryTestAssert(
    $auditAfter === $auditBefore,
    'exhausted retries add no stock.movement_posted audit event'
);

echo PHP_EOL . 'IDEMPOTENCY RETRY / EXHAUSTION TEST PASSED.' . PHP_EOL;
echo 'Safety note: the transient-lock scenario permanently adds one OPENING movement and one audit event to inventory_intelligence_test; the exhausted scenario should add neither.' . PHP_EOL;
