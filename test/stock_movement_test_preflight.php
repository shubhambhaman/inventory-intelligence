<?php
declare(strict_types=1);

/**
 * Inventory Intelligence Platform â€” M-2 test preflight (READ ONLY)
 *
 * Run from project root:
 * C:\php-8.3.33-Win32-vs16-x64\php.exe test\stock_movement_test_preflight.php
 *
 * This script NEVER creates databases/tables/fixtures and performs no INSERT,
 * UPDATE, DELETE, DDL, or migration. It connects without selecting a database,
 * then inspects only inventory_intelligence_test via information_schema.
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/env.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');
$testDatabase = 'inventory_intelligence_test';

function out(string $message): void
{
    echo $message . PHP_EOL;
}

if ($username === '') {
    out('STOP: DB_USER missing from config/env.php. No database was selected.');
    exit(2);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        $host,
        $port,
        $charset
    );

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    out('PASS: connected to MySQL server without selecting a database.');

    $stmt = $pdo->prepare(
        'SELECT SCHEMA_NAME
           FROM information_schema.SCHEMATA
          WHERE SCHEMA_NAME = :database_name'
    );
    $stmt->execute([':database_name' => $testDatabase]);
    $exists = $stmt->fetchColumn();

    if ($exists === false) {
        out('STOP: inventory_intelligence_test does not exist.');
        out('No database, table, fixture, or ledger data was created or changed.');
        out('Next step: create/restore the dedicated test database only if you approve.');
        exit(3);
    }

    out('PASS: inventory_intelligence_test exists.');

    $tablesStmt = $pdo->prepare(
        'SELECT TABLE_NAME
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = :database_name'
    );
    $tablesStmt->execute([':database_name' => $testDatabase]);
    $tables = array_fill_keys(
        array_map('strval', $tablesStmt->fetchAll(PDO::FETCH_COLUMN)),
        true
    );

    $requiredTables = [
        'tenants',
        'users',
        'products',
        'warehouses',
        'stock_movements',
        'audit_events',
        'migrations',
    ];

    $missingTables = [];
    foreach ($requiredTables as $table) {
        if (isset($tables[$table])) {
            out('PASS: table exists: ' . $table);
        } else {
            out('MISSING: table: ' . $table);
            $missingTables[] = $table;
        }
    }

    if ($missingTables !== []) {
        out('STOP: required schema is incomplete. No writes were performed.');
        exit(4);
    }

    $columnsStmt = $pdo->prepare(
        'SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :database_name
            AND TABLE_NAME = :table_name'
    );

    $requiredColumns = [
        'users' => ['id', 'tenant_id', 'email', 'status'],
        'products' => ['id', 'tenant_id', 'sku', 'status'],
        'warehouses' => ['id', 'tenant_id', 'warehouse_code', 'status'],
        'stock_movements' => [
            'id', 'tenant_id', 'product_id', 'warehouse_id',
            'movement_type', 'direction', 'quantity', 'status',
            'idempotency_key', 'request_hash', 'posted_at', 'posted_by',
        ],
        'audit_events' => ['id', 'tenant_id', 'actor_id', 'event_type'],
    ];

    $schemaProblems = [];
    foreach ($requiredColumns as $table => $columns) {
        $columnsStmt->execute([
            ':database_name' => $testDatabase,
            ':table_name' => $table,
        ]);
        $actual = array_fill_keys(
            array_map('strval', $columnsStmt->fetchAll(PDO::FETCH_COLUMN)),
            true
        );

        foreach ($columns as $column) {
            if (!isset($actual[$column])) {
                $schemaProblems[] = $table . '.' . $column;
                out('MISSING COLUMN: ' . $table . '.' . $column);
            }
        }
    }

    if ($schemaProblems !== []) {
        out('STOP: test schema does not match the current posting service.');
        out('No writes were performed.');
        exit(5);
    }

    out('PASS: required columns for the current posting service exist.');

    // Only report fixture availability; do not reveal credentials or mutate data.
    $fixtureSql = "
        SELECT
            EXISTS(
                SELECT 1 FROM users
                 WHERE email = 'stock-movement-test@example.invalid'
                   AND status = 'active'
            ) AS test_user_exists,
            EXISTS(
                SELECT 1 FROM products
                 WHERE sku = 'TEST-SM-001'
                   AND status = 'active'
            ) AS test_product_exists,
            EXISTS(
                SELECT 1 FROM warehouses
                 WHERE warehouse_code = 'TEST-WH-001'
                   AND status = 'active'
            ) AS test_warehouse_exists
    ";

    $fixtureStmt = $pdo->query(
        'SELECT DATABASE() AS selected_database'
    );
    $selectedDatabase = $fixtureStmt->fetchColumn();

    if ($selectedDatabase !== false && $selectedDatabase !== null && $selectedDatabase !== '') {
        out('NOTE: server session has a selected database; the preflight did not change it.');
    }

    $fixtureStmt = $pdo->query(
        "SELECT
            EXISTS(SELECT 1 FROM `{$testDatabase}`.`users`
                   WHERE email = 'stock-movement-test@example.invalid' AND status = 'active') AS test_user_exists,
            EXISTS(SELECT 1 FROM `{$testDatabase}`.`products`
                   WHERE sku = 'TEST-SM-001' AND status = 'active') AS test_product_exists,
            EXISTS(SELECT 1 FROM `{$testDatabase}`.`warehouses`
                   WHERE warehouse_code = 'TEST-WH-001' AND status = 'active') AS test_warehouse_exists"
    );
    $fixtures = $fixtureStmt->fetch();

    foreach ([
        'test_user_exists' => 'active test user',
        'test_product_exists' => 'active test product (TEST-SM-001)',
        'test_warehouse_exists' => 'active test warehouse (TEST-WH-001)',
    ] as $key => $label) {
        out(((int) ($fixtures[$key] ?? 0) === 1 ? 'PASS: ' : 'MISSING: ') . $label);
    }

    if (
        (int) ($fixtures['test_user_exists'] ?? 0) !== 1
        || (int) ($fixtures['test_product_exists'] ?? 0) !== 1
        || (int) ($fixtures['test_warehouse_exists'] ?? 0) !== 1
    ) {
        out('STOP: test fixtures are incomplete. No writes were performed.');
        exit(6);
    }

    out('READY: schema and fixture checks passed.');
    out('This was a read-only preflight. It did not run posting tests.');
    exit(0);
} catch (Throwable $e) {
    out('STOP: preflight failed: ' . $e->getMessage());
    out('No create/insert/update/delete/migration command was issued by this script.');
    exit(10);
}

