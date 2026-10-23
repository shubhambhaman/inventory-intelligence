<?php
declare(strict_types=1);

/**
 * IIP M-2 concurrent stock posting test.
 *
 * Run from project root:
 * C:\php-8.3.33-Win32-vs16-x64\php.exe test\stock_movement_concurrency_test.php
 *
 * SAFETY:
 * - Connects only to inventory_intelligence_test.
 * - Does not delete or update existing business records.
 * - Writes one or two SALE movements only if the posting service allows them.
 * - Uses the reserved test user/product/warehouse fixtures.
 */

const TEST_DATABASE = 'inventory_intelligence_test';
const TEST_USER_EMAIL = 'stock-movement-test@example.invalid';
const TEST_PRODUCT_SKU = 'TEST-SM-001';
const TEST_WAREHOUSE_CODE = 'TEST-WH-001';

function failTest(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function passTest(string $message): void
{
    echo "PASS: {$message}" . PHP_EOL;
}

function connectTestDatabase(string $root): PDO
{
    require_once $root . '/config/env.php';

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

    $testPdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ((string) $testPdo->query('SELECT DATABASE()')->fetchColumn() !== TEST_DATABASE) {
        throw new RuntimeException('Safety stop: not connected to the dedicated test database.');
    }

    return $testPdo;
}

function loadFixture(PDO $testPdo): array
{
    $stmt = $testPdo->prepare(
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
        throw new RuntimeException('Reserved active test user/product/warehouse fixture not found.');
    }

    return $fixture;
}

function currentBalance(PDO $testPdo, int $tenantId, int $productId, int $warehouseId): string
{
    $stmt = $testPdo->prepare(
        "SELECT CAST(COALESCE(SUM(
                    CASE WHEN direction = 'in' THEN quantity ELSE -quantity END
                ), 0) AS DECIMAL(20,6)) AS balance
           FROM stock_movements
          WHERE tenant_id = :tenant_id
            AND product_id = :product_id
            AND warehouse_id = :warehouse_id
            AND status = 'posted'"
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':product_id' => $productId,
        ':warehouse_id' => $warehouseId,
    ]);

    return (string) $stmt->fetchColumn();
}

function workerMode(string $root, array $args): never
{
    if (count($args) !== 6) {
        failTest('Invalid worker arguments.');
    }

    [, , $payloadPath, $gatePath, $readyPath, $resultPath] = $args;
    $payload = json_decode((string) file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);

    // Each worker owns its own DB connection and PHP session context.
    require_once $root . '/config/session.php';
    $testPdo = connectTestDatabase($root);
    require_once $root . '/config/audit.php';
    $GLOBALS['pdo'] = $testPdo;
    $GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Resolve fixtures BEFORE loading the posting service. A required PHP file
    // executes in this function's scope and can overwrite a local variable
    // such as $testPdo while loading its own database bootstrap.
    $fixture = loadFixture($testPdo);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = (int) $fixture['user_id'];
    $_SESSION['tenant_id'] = (int) $fixture['tenant_id'];

    require_once $root . '/src/Inventory/StockMovementPostingService.php';

    // Rebind the service's expected global connection to the explicitly verified test PDO.
    // The local $testPdo is intentionally distinct so included bootstrap files cannot overwrite it.
    $GLOBALS['pdo'] = $testPdo;

    // Never let a bootstrap included by the service redirect this test to dev DB.
    $activePdo = $GLOBALS['pdo'] ?? null;
    if (!$activePdo instanceof PDO
        || (string) $activePdo->query('SELECT DATABASE()')->fetchColumn() !== TEST_DATABASE) {
        throw new RuntimeException(
            'Safety stop: posting service bootstrap changed the active PDO away from ' . TEST_DATABASE
        );
    }

    file_put_contents($readyPath, 'ready');

    $deadline = microtime(true) + 20.0;
    while (!is_file($gatePath)) {
        if (microtime(true) > $deadline) {
            file_put_contents($resultPath, json_encode([
                'outcome' => 'worker_error',
                'message' => 'Timed out waiting for the shared start gate.',
            ]));
            exit(2);
        }
        usleep(10000);
    }

    try {
        $result = \App\Inventory\StockMovementPostingService::post($payload);
        file_put_contents($resultPath, json_encode([
            'outcome' => 'posted',
            'movement_id' => (int) ($result['movement_id'] ?? 0),
            'replay' => (bool) ($result['idempotent_replay'] ?? false),
        ], JSON_THROW_ON_ERROR));
        exit(0);
    } catch (\App\Inventory\StockMovementConflictException $e) {
        file_put_contents($resultPath, json_encode([
            'outcome' => 'rejected',
            'message' => $e->getMessage(),
        ], JSON_THROW_ON_ERROR));
        exit(0);
    } catch (Throwable $e) {
        file_put_contents($resultPath, json_encode([
            'outcome' => 'worker_error',
            'message' => get_class($e) . ': ' . $e->getMessage(),
        ], JSON_THROW_ON_ERROR));
        exit(3);
    }
}

$root = dirname(__DIR__);
if (($argv[1] ?? '') === '--worker') {
    workerMode($root, $argv);
}

$temporaryFiles = [];
$processes = [];

try {
    require_once $root . '/config/session.php';
    $testPdo = connectTestDatabase($root);
    require_once $root . '/config/audit.php';

    $fixture = loadFixture($testPdo);
    $tenantId = (int) $fixture['tenant_id'];
    $productId = (int) $fixture['product_id'];
    $warehouseId = (int) $fixture['warehouse_id'];

    // Both independent workers receive byte-for-byte equivalent business input,
    // including the same tenant context from the reserved fixture and same key.
    $token = bin2hex(random_bytes(12));
    $payload = [
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'movement_type' => 'OPENING',
        'quantity' => '1.000000',
        'unit_cost' => '1.000000',
        'source_type' => 'TEST_IDEMPOTENCY_CONCURRENCY',
        'source_reference' => 'SM-IDEMP-' . $token,
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'idempotency_key' => 'sm-idemp-conc-' . $token,
    ];

    $movementCountStmt = $testPdo->prepare(
        'SELECT COUNT(*) FROM stock_movements WHERE tenant_id = :tenant_id'
    );
    $movementCountStmt->execute([':tenant_id' => $tenantId]);
    $movementCountBefore = (int) $movementCountStmt->fetchColumn();

    $auditCountStmt = $testPdo->prepare(
        "SELECT COUNT(*) FROM audit_events
          WHERE tenant_id = :tenant_id AND event_type = 'stock.movement_posted'"
    );
    $auditCountStmt->execute([':tenant_id' => $tenantId]);
    $auditCountBefore = (int) $auditCountStmt->fetchColumn();

    $gatePath = $root . '/test/.stock-idempotency-gate-' . $token;
    $temporaryFiles[] = $gatePath;
    $workerPaths = [];

    for ($i = 1; $i <= 2; $i++) {
        $payloadPath = $root . '/test/.stock-idempotency-payload-' . $token . '-' . $i . '.json';
        $readyPath = $root . '/test/.stock-idempotency-ready-' . $token . '-' . $i;
        $resultPath = $root . '/test/.stock-idempotency-result-' . $token . '-' . $i . '.json';
        $temporaryFiles[] = $payloadPath;
        $temporaryFiles[] = $readyPath;
        $temporaryFiles[] = $resultPath;

        file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
        $workerPaths[] = [$payloadPath, $readyPath, $resultPath];
    }

    $php = PHP_BINARY;
    foreach ($workerPaths as [$payloadPath, $readyPath, $resultPath]) {
        $command = '"' . $php . '" "' . __FILE__ . '" --worker "'
            . $payloadPath . '" "' . $gatePath . '" "'
            . $readyPath . '" "' . $resultPath . '"';

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($command, $descriptorSpec, $pipes, $root);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not launch a concurrent PHP worker.');
        }
        fclose($pipes[0]);
        $processes[] = ['proc' => $proc, 'pipes' => $pipes, 'ready' => $readyPath, 'result' => $resultPath];
    }

    $deadline = microtime(true) + 20.0;
    while (true) {
        $allReady = true;
        foreach ($processes as $process) {
            if (!is_file($process['ready'])) {
                $allReady = false;
                break;
            }
        }
        if ($allReady) {
            break;
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Workers did not reach the start gate in time.');
        }
        usleep(10000);
    }

    file_put_contents($gatePath, 'go');

    $results = [];
    foreach ($processes as $process) {
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $exitCode = proc_close($process['proc']);

        if (!is_file($process['result'])) {
            throw new RuntimeException('Worker produced no result. Exit=' . $exitCode . '; ' . $stderr . $stdout);
        }
        $result = json_decode((string) file_get_contents($process['result']), true, 512, JSON_THROW_ON_ERROR);
        if (($result['outcome'] ?? '') !== 'posted') {
            throw new RuntimeException('Expected both identical requests to succeed/replay; got '
                . ($result['outcome'] ?? 'unknown') . ': ' . ($result['message'] ?? $stderr));
        }
        $results[] = $result;
    }

    if (count($results) !== 2) {
        throw new RuntimeException('Expected exactly two worker results.');
    }

    $movementIds = array_values(array_unique(array_map(
        static fn(array $r): int => (int) ($r['movement_id'] ?? 0),
        $results
    )));
    if (count($movementIds) !== 1 || $movementIds[0] <= 0) {
        throw new RuntimeException('Concurrent identical requests did not return the same valid movement ID.');
    }
    passTest('two independent workers posted the same idempotency key concurrently');
    passTest('both requests returned the same movement ID');

    $replayCount = count(array_filter($results, static fn(array $r): bool => ($r['replay'] ?? false) === true));
    $originalCount = count(array_filter($results, static fn(array $r): bool => ($r['replay'] ?? true) === false));
    if ($replayCount !== 1 || $originalCount !== 1) {
        throw new RuntimeException('Expected one original post and one idempotent replay; got originals='
            . $originalCount . ', replays=' . $replayCount . '.');
    }
    passTest('exactly one request was the original post and the other was an idempotent replay');

    $movementCountStmt->execute([':tenant_id' => $tenantId]);
    $movementCountAfter = (int) $movementCountStmt->fetchColumn();
    if ($movementCountAfter !== $movementCountBefore + 1) {
        throw new RuntimeException('Expected exactly one new movement for this tenant; delta='
            . ($movementCountAfter - $movementCountBefore) . '.');
    }

    $keyStmt = $testPdo->prepare(
        'SELECT id, status, quantity, request_hash FROM stock_movements
          WHERE tenant_id = :tenant_id AND idempotency_key = :idempotency_key'
    );
    $keyStmt->execute([
        ':tenant_id' => $tenantId,
        ':idempotency_key' => $payload['idempotency_key'],
    ]);
    $movement = $keyStmt->fetch();
    if (!$movement || (int) $movement['id'] !== $movementIds[0]
        || $movement['status'] !== 'posted'
        || (string) $movement['quantity'] !== '1.000000'
        || !preg_match('/^[a-f0-9]{64}$/i', (string) $movement['request_hash'])) {
        throw new RuntimeException('Persisted movement does not match the expected posted idempotent request.');
    }
    passTest('exactly one posted ledger row exists for the tenant/key with a valid request hash');

    $auditCountStmt->execute([':tenant_id' => $tenantId]);
    $auditCountAfter = (int) $auditCountStmt->fetchColumn();
    if ($auditCountAfter !== $auditCountBefore + 1) {
        throw new RuntimeException('Expected exactly one new posting audit event; delta='
            . ($auditCountAfter - $auditCountBefore) . '.');
    }
    passTest('only one posting audit event was added for the concurrent duplicate requests');

    echo PHP_EOL . 'IDEMPOTENCY CONCURRENCY TEST PASSED.' . PHP_EOL;
    echo 'Safety note: this test permanently adds one OPENING movement and one audit event to inventory_intelligence_test.' . PHP_EOL;
} catch (Throwable $e) {
    failTest(get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($processes as $process) {
        if (is_resource($process['proc'])) {
            @proc_terminate($process['proc']);
            @proc_close($process['proc']);
        }
    }
    foreach ($temporaryFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
