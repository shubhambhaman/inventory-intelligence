<?php
declare(strict_types=1);

/**
 * IIP M-2 — reset ONLY the dedicated stock movement test database.
 *
 * Run from project root with the explicit confirmation argument:
 * C:\php-8.3.33-Win32-vs16-x64\php.exe test\reset_stock_movement_test_database.php --confirm-reset-test-db
 *
 * This permanently deletes all contents of inventory_intelligence_test.
 * It never selects, drops, or modifies inventory_intelligence.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "STOP: CLI only. No changes made.\n");
    exit(2);
}

if (($argv[1] ?? '') !== '--confirm-reset-test-db') {
    fwrite(STDERR, "STOP: no changes made.\n");
    fwrite(STDERR, "To reset ONLY inventory_intelligence_test, rerun with:\n");
    fwrite(STDERR, "  --confirm-reset-test-db\n");
    exit(3);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/env.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

$testDatabase = 'inventory_intelligence_test';
$developmentDatabase = 'inventory_intelligence';

if ($username === '') {
    fwrite(STDERR, "STOP: DB_USER missing from config/env.php. No changes made.\n");
    exit(4);
}

try {
    // Connect without selecting any schema; never use config/db.php.
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=%s', $host, $port, $charset),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $schemas = $pdo->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA')
        ->fetchAll(PDO::FETCH_COLUMN);
    $schemas = array_map('strval', $schemas);

    if (!in_array($developmentDatabase, $schemas, true)) {
        throw new RuntimeException(
            "Safety stop: expected development schema {$developmentDatabase} was not found. No changes made."
        );
    }

    if (!in_array($testDatabase, $schemas, true)) {
        throw new RuntimeException(
            "Safety stop: {$testDatabase} does not exist. Nothing to reset; no changes made."
        );
    }

    // Fixed hard-coded identifier; no user-supplied identifier is interpolated.
    $pdo->exec('DROP DATABASE `inventory_intelligence_test`');

    $pdo->exec(
        'CREATE DATABASE `inventory_intelligence_test` ' .
        'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $verify = $pdo->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db'
    );
    $verify->execute([':db' => $testDatabase]);
    if ($verify->fetchColumn() === false) {
        throw new RuntimeException('Test database recreation could not be verified.');
    }

    echo "PASS: reset and recreated {$testDatabase}.\n";
    echo "PASS: {$developmentDatabase} was never selected or modified by this script.\n";
    echo "NEXT: run test\\migrate_stock_movement_test_db.php, then test\\create_stock_movement_test_fixtures.php.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "STOP: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Inspect schemas before retrying. Do not run the generic migration script.\n");
    exit(10);
}
