<?php
declare(strict_types=1);

/**
 * TEST-ONLY migration runner for migration 019.
 * Never uses the configured DB_NAME to select the target database.
 */

$root = dirname(__DIR__);
require_once $root . '/config/env.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$user = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

$database = 'inventory_intelligence_test';
$migrationName = '019_create_stock_documents.sql';
$migrationFile = $root . '/migrations/' . $migrationName;

function stop(string $message): never
{
    fwrite(STDERR, "STOP: {$message}\n");
    exit(1);
}

if ($user === '') {
    stop('DB_USER is missing.');
}

if (!is_file($migrationFile)) {
    stop("Migration file not found: {$migrationFile}");
}

$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    stop('Migration file is empty or unreadable.');
}

try {
    // Explicitly select the test DB in the DSN.
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        ),
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        stop('Connected database is not inventory_intelligence_test.');
    }

    echo "PASS: connected only to {$database}\n";

    $requiredExistingTables = [
        'tenants',
        'users',
        'products',
        'warehouses',
        'migrations',
    ];

    foreach ($requiredExistingTables as $table) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = :db
                AND TABLE_NAME = :table_name'
        );
        $stmt->execute([
            ':db' => $database,
            ':table_name' => $table,
        ]);

        if ((int) $stmt->fetchColumn() !== 1) {
            stop("Required existing table missing: {$table}");
        }
    }

    foreach (['stock_documents', 'stock_document_lines'] as $table) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = :db
                AND TABLE_NAME = :table_name'
        );
        $stmt->execute([
            ':db' => $database,
            ':table_name' => $table,
        ]);

        if ((int) $stmt->fetchColumn() !== 0) {
            stop("{$table} already exists; refusing partial/repeated migration.");
        }
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM migrations WHERE migration = :migration'
    );
    $stmt->execute([':migration' => $migrationName]);

    if ((int) $stmt->fetchColumn() !== 0) {
        stop('Migration is already recorded; refusing to rerun.');
    }

    echo "PASS: prerequisites checked; target tables do not exist.\n";

    // This migration contains two CREATE TABLE statements.
    // Execute each separately; MySQL DDL is not transactionally reversible.
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        static fn(string $statement): bool => $statement !== ''
    );

    if (count($statements) !== 2) {
        stop(
            'Expected exactly two SQL statements; refusing unexpected file format.'
        );
    }

    foreach ($statements as $index => $statement) {
        $pdo->exec($statement);
        echo 'PASS: DDL statement ' . ($index + 1) . " executed.\n";
    }

    $stmt = $pdo->prepare(
        'INSERT INTO migrations (migration) VALUES (:migration)'
    );
    $stmt->execute([':migration' => $migrationName]);

    echo "SUCCESS: {$migrationName} applied to {$database} only.\n";
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAILED: " . $e->getMessage() . "\n"
        . "DDL may have partially applied; do not rerun blindly.\n"
    );
    exit(1);
}
