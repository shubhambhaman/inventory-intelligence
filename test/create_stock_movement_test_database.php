<?php
declare(strict_types=1);

/**
 * Inventory Intelligence Platform — create dedicated M-2 test database.
 *
 * SAFETY:
 * - Creates ONLY inventory_intelligence_test if it does not exist.
 * - Does not drop/alter any database.
 * - Does not run migrations or insert fixtures.
 * - Connects without selecting a database.
 *
 * Run from project root:
 * C:\php-8.3.33-Win32-vs16-x64\php.exe test\create_stock_movement_test_database.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "STOP: CLI only.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/env.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');
$database = 'inventory_intelligence_test';

if ($username === '') {
    fwrite(STDERR, "STOP: DB_USER missing from config/env.php. No changes made.\n");
    exit(3);
}

try {
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

    echo "PASS: connected to MySQL without selecting a database.\n";

    $check = $pdo->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db'
    );
    $check->execute([':db' => $database]);

    if ($check->fetchColumn() !== false) {
        echo "STOP: {$database} already exists; nothing changed.\n";
        echo "Next: inspect its tables/migration history before deciding whether to reuse it.\n";
        exit(4);
    }

    // Database identifier is a fixed constant above, not user input.
    $pdo->exec(
        'CREATE DATABASE `inventory_intelligence_test` ' .
        'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $verify = $pdo->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db'
    );
    $verify->execute([':db' => $database]);

    if ($verify->fetchColumn() === false) {
        fwrite(STDERR, "ERROR: create command returned but database verification failed.\n");
        exit(5);
    }

    echo "PASS: created and verified inventory_intelligence_test.\n";
    echo "No tables, migrations, fixtures, or stock ledger records were created.\n";
    echo "The inventory_intelligence database was not selected or modified.\n";
    echo "NEXT: inspect migration files and configure a test-only connection before applying migrations.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "STOP: database creation failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "No application migration or fixture command was run.\n");
    exit(10);
}
