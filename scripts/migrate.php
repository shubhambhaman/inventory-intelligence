<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * Database Migration Runner
 *
 * Usage:
 *   D:\Xampp\php\php.exe scripts\migrate.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Migration runner can only be executed from CLI.\n");
}

require_once __DIR__ . '/../config/db.php';

$migrationsDir = __DIR__ . '/../migrations';

/**
 * ---------------------------------------------------------
 * Migration directory
 * ---------------------------------------------------------
 */
if (!is_dir($migrationsDir)) {
    fwrite(
        STDERR,
        "ERROR: Migrations directory not found: {$migrationsDir}\n"
    );

    exit(1);
}

/**
 * ---------------------------------------------------------
 * Migration tracking table
 * ---------------------------------------------------------
 */
try {

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration VARCHAR(255) NOT NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_migration (migration)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci"
    );

} catch (PDOException $e) {

    fwrite(
        STDERR,
        "ERROR: Unable to create migrations table.\n"
    );

    fwrite(
        STDERR,
        $e->getMessage() . "\n"
    );

    exit(1);
}

/**
 * ---------------------------------------------------------
 * Find migration files
 * ---------------------------------------------------------
 */
$files = glob(
    $migrationsDir . DIRECTORY_SEPARATOR . '*.sql'
);

if ($files === false) {

    fwrite(
        STDERR,
        "ERROR: Unable to read migrations directory.\n"
    );

    exit(1);
}

/**
 * ---------------------------------------------------------
 * Sort migrations
 * ---------------------------------------------------------
 */
sort($files, SORT_STRING);

if (empty($files)) {

    echo "No migration files found.\n";

    exit(0);
}

/**
 * ---------------------------------------------------------
 * Fetch migration history
 * ---------------------------------------------------------
 */
try {

    $stmt = $pdo->query(
        "SELECT migration
         FROM migrations
         ORDER BY migration ASC"
    );

    $executedMigrations = $stmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    $executedLookup = [];

    foreach ($executedMigrations as $migration) {
        $executedLookup[$migration] = true;
    }

} catch (PDOException $e) {

    fwrite(
        STDERR,
        "ERROR: Unable to read migration history.\n"
    );

    fwrite(
        STDERR,
        $e->getMessage() . "\n"
    );

    exit(1);
}

/**
 * ---------------------------------------------------------
 * Execute pending migrations
 * ---------------------------------------------------------
 */
$pendingCount = 0;

foreach ($files as $file) {

    $migrationName = basename($file);

    /**
     * Skip already executed migrations.
     */
    if (isset($executedLookup[$migrationName])) {

        echo "[SKIP] {$migrationName} already executed.\n";

        continue;
    }

    echo "[RUN ] {$migrationName}\n";

    /**
     * Read migration file.
     */
    $sql = file_get_contents($file);

    if ($sql === false) {

        fwrite(
            STDERR,
            "ERROR: Unable to read {$migrationName}.\n"
        );

        exit(1);
    }

    /**
     * Empty migration protection.
     */
    if (trim($sql) === '') {

        fwrite(
            STDERR,
            "ERROR: Migration {$migrationName} is empty.\n"
        );

        exit(1);
    }

    try {

        /**
         * -------------------------------------------------
         * IMPORTANT:
         * Do NOT wrap DDL migrations in transactions.
         *
         * MariaDB/MySQL DDL statements such as CREATE TABLE
         * can implicitly commit transactions.
         * -------------------------------------------------
         */
        $pdo->exec($sql);

        /**
         * Record migration only after successful execution.
         */
        $recordStmt = $pdo->prepare(
            "INSERT INTO migrations (migration)
             VALUES (:migration)"
        );

        $recordStmt->execute([
            ':migration' => $migrationName
        ]);

        echo "[ OK ] {$migrationName}\n";

        $pendingCount++;

    } catch (Throwable $e) {

        fwrite(
            STDERR,
            "\nMigration failed: {$migrationName}\n"
        );

        fwrite(
            STDERR,
            "Error: " . $e->getMessage() . "\n"
        );

        fwrite(
            STDERR,
            "Migration process stopped.\n"
        );

        exit(1);
    }
}

/**
 * ---------------------------------------------------------
 * Final result
 * ---------------------------------------------------------
 */
echo "\n";
echo "Migration process completed.\n";
echo "Pending migrations executed: {$pendingCount}\n";