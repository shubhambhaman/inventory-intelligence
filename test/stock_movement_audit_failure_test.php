<?php

declare(strict_types=1);

/**
 * M-2 audit failure rollback test.
 *
 * SAFETY:
 * - Connects only to inventory_intelligence_test.
 * - Uses the same test PDO for posting and audit.
 * - Creates a temporary trigger and drops it in finally.
 * - Verifies failed posting leaves no movement or audit event.
 */

const TEST_DATABASE = 'inventory_intelligence_test';
const TEST_USER_EMAIL = 'stock-movement-test@example.invalid';
const TEST_PRODUCT_SKU = 'TEST-SM-001';
const TEST_WAREHOUSE_CODE = 'TEST-WH-001';
const TEST_TRIGGER = 'trg_test_force_stock_audit_failure';

$pdo = null;
$triggerCreated = false;
$testPassed = false;

try {
    $projectRoot = dirname(__DIR__);

    require_once $projectRoot . '/config/session.php';
    require_once $projectRoot . '/config/env.php';

    $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
    $port = (string) ($_ENV['DB_PORT'] ?? '3306');
    $username = (string) ($_ENV['DB_USER'] ?? '');
    $password = (string) ($_ENV['DB_PASSWORD'] ?? '');
    $charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

    if ($username === '') {
        throw new RuntimeException(
            'DB_USER is missing. Stopped safely.'
        );
    }

    // Explicitly connect to the test database, never DB_NAME from .env.
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname=" .
            TEST_DATABASE . ";charset={$charset}",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $dbName = (string) $pdo->query(
        'SELECT DATABASE()'
    )->fetchColumn();

    if ($dbName !== TEST_DATABASE) {
        throw new RuntimeException(
            'Safety stop: unexpected database.'
        );
    }

    echo "PASS: connected only to {$dbName}\n";

    // Refuse to overwrite an existing trigger.
    $checkTrigger = $pdo->prepare(
        'SELECT COUNT(*)
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = :db
            AND TRIGGER_NAME = :trigger_name'
    );

    $checkTrigger->execute([
        ':db' => $dbName,
        ':trigger_name' => TEST_TRIGGER,
    ]);

    if ((int) $checkTrigger->fetchColumn() !== 0) {
        throw new RuntimeException(
            'Safety stop: test trigger already exists. ' .
            'Inspect it before proceeding.'
        );
    }

    // Resolve existing test fixtures.
    $fixtureQuery = $pdo->prepare(
        "SELECT u.id AS user_id,
                u.tenant_id,
                p.id AS product_id,
                w.id AS warehouse_id
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
        throw new RuntimeException(
            'Required test fixtures are missing. ' .
            'No trigger or posting attempted.'
        );
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = (int) $fixture['user_id'];
    $_SESSION['tenant_id'] = (int) $fixture['tenant_id'];

    /*
     * Bind the test PDO globally BEFORE loading the service/audit files.
     * Rebind it afterward as well, in case included code changes the global.
     */

$GLOBALS['pdo'] = $pdo;

require_once $projectRoot . '/config/request_context.php';

// Explicitly prevent audit.php from loading the development DB connection.
if (!defined('IIP_AUDIT_SKIP_DB_BOOTSTRAP')) {
    define('IIP_AUDIT_SKIP_DB_BOOTSTRAP', true);
}

require_once $projectRoot . '/config/audit.php';
require_once $projectRoot . '/src/Inventory/StockMovementPostingService.php';

// Restore the verified test connection.
$GLOBALS['pdo'] = $pdo;

// Safety guard: verify both object identity and selected database.
$servicePdo = $GLOBALS['pdo'] ?? null;

if (!($servicePdo instanceof PDO)) {
    throw new RuntimeException(
        'Safety stop: global PDO is not a PDO instance.'
    );
}

if ($servicePdo !== $pdo) {
    throw new RuntimeException(
        'Safety stop: service PDO is not the test PDO object.'
    );
}

$serviceDb = (string) $servicePdo->query(
    'SELECT DATABASE()'
)->fetchColumn();

if ($serviceDb !== TEST_DATABASE) {
    throw new RuntimeException(
        'Safety stop: global PDO selected an unexpected database: ' .
        $serviceDb
    );
}



    echo "PASS: posting service PDO is bound to {$serviceDb}\n";

    $suffix = bin2hex(random_bytes(12));
    $idempotencyKey = 'audit-failure-' . $suffix;
    $sourceReference = 'AUDIT-FAIL-' . $suffix;

    $movementCountBefore = (int) $pdo->query(
        'SELECT COUNT(*) FROM stock_movements'
    )->fetchColumn();

    $auditCountBefore = (int) $pdo->query(
        "SELECT COUNT(*)
           FROM audit_events
          WHERE event_type = 'stock.movement_posted'"
    )->fetchColumn();

    // Force only stock.movement_posted audit inserts to fail.
    $pdo->exec(
        "CREATE TRIGGER `" . TEST_TRIGGER . "`
         BEFORE INSERT ON audit_events
         FOR EACH ROW
         BEGIN
             IF NEW.event_type = 'stock.movement_posted' THEN
                 SIGNAL SQLSTATE '45000'
                     SET MESSAGE_TEXT = 'TEST_ONLY_AUDIT_FAILURE';
             END IF;
         END"
    );

    $triggerCreated = true;
    echo "PASS: temporary audit-failure trigger created\n";

    $payload = [
        'product_id' => (int) $fixture['product_id'],
        'warehouse_id' => (int) $fixture['warehouse_id'],
        'movement_type' => 'PURCHASE',
        'quantity' => '1.000000',
        'unit_cost' => '1.000000',
        'source_type' => 'TEST',
        'source_reference' => $sourceReference,
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'idempotency_key' => $idempotencyKey,
    ];

    $expectedAuditFailure = false;

    try {
        \App\Inventory\StockMovementPostingService::post($payload);
    } catch (Throwable $e) {
        $expectedAuditFailure = str_contains(
            $e->getMessage(),
            'TEST_ONLY_AUDIT_FAILURE'
        );

        if (!$expectedAuditFailure) {
            throw new RuntimeException(
                'Posting failed for an unexpected reason: ' .
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    if (!$expectedAuditFailure) {
        throw new RuntimeException(
            'Posting unexpectedly succeeded; audit failure was not observed.'
        );
    }

    echo "PASS: forced audit insert failure was observed\n";

    $movementCountAfter = (int) $pdo->query(
        'SELECT COUNT(*) FROM stock_movements'
    )->fetchColumn();

    $auditCountAfter = (int) $pdo->query(
        "SELECT COUNT(*)
           FROM audit_events
          WHERE event_type = 'stock.movement_posted'"
    )->fetchColumn();

    $movementLookup = $pdo->prepare(
        'SELECT COUNT(*)
           FROM stock_movements
          WHERE tenant_id = :tenant_id
            AND idempotency_key = :idempotency_key'
    );

    $movementLookup->execute([
        ':tenant_id' => (int) $fixture['tenant_id'],
        ':idempotency_key' => $idempotencyKey,
    ]);

    $failedMovementExists = (int) $movementLookup->fetchColumn() !== 0;

    if ($movementCountAfter !== $movementCountBefore) {
        throw new RuntimeException(
            'FAIL: stock_movements count changed after audit failure.'
        );
    }

    echo "PASS: failed posting left no additional stock movement\n";

    if ($auditCountAfter !== $auditCountBefore) {
        throw new RuntimeException(
            'FAIL: stock.movement_posted audit count changed unexpectedly.'
        );
    }

    echo "PASS: failed posting left no additional posting audit event\n";

    if ($failedMovementExists) {
        throw new RuntimeException(
            'FAIL: movement exists for the failed idempotency key.'
        );
    }

    echo "PASS: failed movement was rolled back\n";

    $testPassed = true;
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . PHP_EOL);
} finally {
    if ($triggerCreated && $pdo instanceof PDO) {
        try {
            // Explicitly use the connection that created the trigger.
            if (
                $pdo->query('SELECT DATABASE()')->fetchColumn()
                !== TEST_DATABASE
            ) {
                throw new RuntimeException(
                    'Cleanup safety stop: unexpected database.'
                );
            }

            $pdo->exec(
                'DROP TRIGGER IF EXISTS `' . TEST_TRIGGER . '`'
            );

            echo "PASS: temporary trigger cleanup completed\n";
        } catch (Throwable $cleanupError) {
            fwrite(
                STDERR,
                "CRITICAL: could not remove temporary trigger " .
                TEST_TRIGGER . ': ' .
                $cleanupError->getMessage() . PHP_EOL
            );

            fwrite(
                STDERR,
                "Inspect and remove it manually from " .
                TEST_DATABASE . " if necessary.\n"
            );

            $testPassed = false;
        }
    }
}

if ($testPassed) {
    echo "PASS: audit failure rollback test completed\n";
    exit(0);
}

exit(1);
