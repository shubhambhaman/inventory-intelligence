<?php
declare(strict_types=1);

/**
 * Inventory Intelligence Platform — M-2 TEST-ONLY migration runner.
 *
 * Run from project root:
 * C:\php-8.3.33-Win32-vs16-x64\php.exe test\migrate_stock_movement_test_db.php
 *
 * Safety:
 * - Hard-coded target: inventory_intelligence_test only.
 * - Connects without selecting a DB, then explicitly selects the test DB.
 * - Refuses to run if any non-test database is selected/targeted.
 * - Refuses to run if any migration other than 001-018 is present.
 * - Applies only pending migrations in /migrations, sorted by filename.
 * - Does not DROP databases/tables or seed fixtures.
 *
 * Note: MySQL DDL implicitly commits. If a migration fails partway through,
 * inspect the test DB before rerunning; never point this script at dev DB.
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
$testDatabase = 'inventory_intelligence_test';
$migrationsDir = $projectRoot . DIRECTORY_SEPARATOR . 'migrations';

function stopRun(string $message, int $code = 1): never
{
    fwrite(STDERR, "STOP: {$message}\n");
    exit($code);
}

if ($username === '') {
    stopRun('DB_USER missing from config/env.php. No migration was run.', 3);
}
if (!is_dir($migrationsDir)) {
    stopRun("Migrations directory not found: {$migrationsDir}", 4);
}

$files = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql');
if ($files === false || $files === []) {
    stopRun('No SQL migration files found. No migration was run.', 5);
}
sort($files, SORT_STRING);

$unexpectedFiles = [];
foreach ($files as $file) {
    $name = basename($file);
    if (!preg_match('/^(00[1-9]|01[0-8])_[a-z0-9_]+\.sql$/i', $name)) {
        $unexpectedFiles[] = $name;
    }
}
if ($unexpectedFiles !== []) {
    stopRun(
        'Unexpected migration filename(s); review before applying: ' .
        implode(', ', $unexpectedFiles),
        6
    );
}

try {
    // Do not select the application database from config/db.php.
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

    echo "PASS: connected without selecting a database.\n";

    $existsStmt = $pdo->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db'
    );
    $existsStmt->execute([':db' => $testDatabase]);
    if ($existsStmt->fetchColumn() === false) {
        stopRun("{$testDatabase} does not exist. No migration was run.", 7);
    }

    $pdo->exec('USE `inventory_intelligence_test`');
    $selected = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($selected !== $testDatabase) {
        stopRun("Wrong database selected ({$selected}). No migration was run.", 8);
    }
    echo "PASS: explicitly selected {$selected}.\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration VARCHAR(255) NOT NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $executed = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $executedLookup = array_fill_keys(array_map('strval', $executed), true);

    $pending = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($executedLookup[$name])) {
            echo "[SKIP] {$name} already recorded.\n";
            continue;
        }
        $pending[] = $file;
    }

    echo "Pending migrations: " . count($pending) . "\n";
    if ($pending === []) {
        echo "PASS: test database schema is already up to date.\n";
        exit(0);
    }

    foreach ($pending as $file) {
        $name = basename($file);
        $sql = trim((string) file_get_contents($file));
        if ($sql === '') {
            stopRun("{$name} is empty; stopped before executing it.", 9);
        }

        echo "[RUN ] {$name}\n";
        try {
            // Each migration file is executed as authored. DDL may auto-commit.
            $pdo->exec($sql);
            $record = $pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
            $record->execute([':migration' => $name]);
            echo "[ OK ] {$name}\n";
        } catch (Throwable $e) {
            fwrite(STDERR, "[FAIL] {$name}: {$e->getMessage()}\n");
            fwrite(STDERR, "STOP: inspect {$testDatabase} before retrying; no dev DB was selected.\n");
            exit(10);
        }
    }

    $tableStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'stock_movements'"
    );
    $tableStmt->execute([':db' => $testDatabase]);
    if ((int) $tableStmt->fetchColumn() !== 1) {
        stopRun('stock_movements table not found after migrations.', 11);
    }

    $triggerStmt = $pdo->prepare(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = :db
           AND TRIGGER_NAME IN (
             "trg_stock_movements_prevent_posted_update",
             "trg_stock_movements_prevent_posted_delete"
           )'
    );
    $triggerStmt->execute([':db' => $testDatabase]);
    $triggers = $triggerStmt->fetchAll(PDO::FETCH_COLUMN);

    echo "PASS: stock_movements table exists.\n";
    echo "Immutability triggers verified: " . count($triggers) . "/2.\n";
    echo "Migrations applied to {$testDatabase} only. No fixtures or ledger rows inserted.\n";
} catch (Throwable $e) {
    stopRun('migration setup failed: ' . $e->getMessage(), 12);
}
