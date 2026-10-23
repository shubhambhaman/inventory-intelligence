<?php
declare(strict_types=1);

/**
 * Read-only diagnostic for the IIP M-2 concurrency-test fixtures.
 * Connects only to inventory_intelligence_test. Performs SELECTs only.
 *
 * Run:
 * C:\php-8.3.33-Win32-vs16-x64\php.exe test\diagnose_stock_movement_fixtures.php
 */

const TEST_DATABASE = 'inventory_intelligence_test';

function out(string $message): void
{
    echo $message . PHP_EOL;
}

try {
    $root = dirname(__DIR__);
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

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($db !== TEST_DATABASE) {
        throw new RuntimeException('Safety stop: connected to unexpected database.');
    }
    out('PASS: connected only to ' . TEST_DATABASE);

    $queries = [
        'user' => [
            "SELECT id, tenant_id, status FROM users WHERE email = :value ORDER BY id",
            'stock-movement-test@example.invalid',
        ],
        'product' => [
            "SELECT id, tenant_id, status FROM products WHERE sku = :value ORDER BY id",
            'TEST-SM-001',
        ],
        'warehouse' => [
            "SELECT id, tenant_id, status FROM warehouses WHERE warehouse_code = :value ORDER BY id",
            'TEST-WH-001',
        ],
    ];

    $tenantSets = [];
    foreach ($queries as $label => [$sql, $value]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':value' => $value]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            out('MISSING: ' . $label . ' fixture');
            $tenantSets[$label] = [];
            continue;
        }

        $tenantSets[$label] = [];
        foreach ($rows as $row) {
            $tenantSets[$label][] = (int) $row['tenant_id'];
            out(sprintf(
                '%s: id=%d tenant_id=%d status=%s',
                ucfirst($label),
                (int) $row['id'],
                (int) $row['tenant_id'],
                (string) $row['status']
            ));
        }
    }

    $common = array_values(array_intersect(
        array_unique($tenantSets['user']),
        array_unique($tenantSets['product']),
        array_unique($tenantSets['warehouse'])
    ));

    if ($common) {
        out('PASS: all three fixture types share tenant_id=' . implode(',', $common));
        out('If concurrency test still cannot find them, inspect service/session/bootstrap differences next.');
    } else {
        out('MISMATCH: no single tenant has the reserved user, product, and warehouse together.');
        out('No records were created, updated, or deleted.');
    }
    out('READ-ONLY DIAGNOSTIC COMPLETE.');
} catch (Throwable $e) {
    fwrite(STDERR, 'STOP: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
