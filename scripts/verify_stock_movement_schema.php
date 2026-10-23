<?php
declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * READ-ONLY stock movement schema verification.
 *
 * Run from the project root:
 * D:\Xampp\php\php.exe scripts\verify_stock_movement_schema.php
 *
 * This script only executes SELECT statements against information_schema
 * and the migrations history table. It does not create, alter, insert,
 * update, or delete anything.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only. No database changes were made.\n");
}

require_once __DIR__ . '/../config/db.php';

function printSection(string $title): void
{
    echo "\n=== {$title} ===\n";
}

function safeQuery(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        echo "Read unavailable: " . $e->getMessage() . "\n";
        return [];
    }
}

echo "Inventory Intelligence Platform - READ-ONLY schema check\n";
echo "Database connection established. No writes will be performed.\n";

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Connected database: {$dbName}\n";

printSection('Migration history (014-018)');
$migrationsTable = safeQuery(
    $pdo,
    "SELECT TABLE_NAME
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'migrations'"
);

if ($migrationsTable === []) {
    echo "migrations table not found or could not be read.\n";
} else {
    $rows = safeQuery(
        $pdo,
        "SELECT migration, executed_at
           FROM migrations
          WHERE migration REGEXP '^01[4-8]_'
          ORDER BY migration"
    );

    if ($rows === []) {
        echo "No migration records matching 014-018 were returned.\n";
    } else {
        foreach ($rows as $row) {
            echo ($row['migration'] ?? '') . " | " . ($row['executed_at'] ?? '') . "\n";
        }
    }
}

printSection('Relevant tables');
$tables = safeQuery(
    $pdo,
    "SELECT TABLE_NAME, ENGINE
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND (
            TABLE_NAME IN (
                'stock_movements',
                'stock_balances',
                'inventory_balances',
                'audit_events',
                'products',
                'warehouses',
                'users',
                'tenants',
                'migrations'
            )
            OR TABLE_NAME LIKE '%stock%balance%'
        )
      ORDER BY TABLE_NAME"
);

if ($tables === []) {
    echo "No matching tables found or metadata unavailable.\n";
} else {
    foreach ($tables as $row) {
        echo ($row['TABLE_NAME'] ?? '') . " | ENGINE=" . ($row['ENGINE'] ?? 'unknown') . "\n";
    }
}

printSection('stock_movements columns');
$columns = safeQuery(
    $pdo,
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'stock_movements'
      ORDER BY ORDINAL_POSITION"
);

if ($columns === []) {
    echo "stock_movements columns not found or metadata unavailable.\n";
} else {
    foreach ($columns as $row) {
        echo implode(' | ', [
            (string) $row['COLUMN_NAME'],
            (string) $row['COLUMN_TYPE'],
            'NULL=' . (string) $row['IS_NULLABLE'],
            'DEFAULT=' . (string) ($row['COLUMN_DEFAULT'] ?? 'NULL'),
            'EXTRA=' . (string) ($row['EXTRA'] ?? ''),
        ]) . "\n";
    }
}

printSection('stock_movements indexes');
$indexes = safeQuery(
    $pdo,
    "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'stock_movements'
      ORDER BY INDEX_NAME, SEQ_IN_INDEX"
);

if ($indexes === []) {
    echo "No indexes found or metadata unavailable.\n";
} else {
    foreach ($indexes as $row) {
        echo implode(' | ', [
            (string) $row['INDEX_NAME'],
            'NON_UNIQUE=' . (string) $row['NON_UNIQUE'],
            'SEQ=' . (string) $row['SEQ_IN_INDEX'],
            'COLUMN=' . (string) ($row['COLUMN_NAME'] ?? 'NULL'),
        ]) . "\n";
    }
}

printSection('stock_movements foreign keys and checks');
$constraints = safeQuery(
    $pdo,
    "SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE,
            kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME,
            kcu.REFERENCED_COLUMN_NAME, cc.CHECK_CLAUSE
       FROM information_schema.TABLE_CONSTRAINTS tc
       LEFT JOIN information_schema.KEY_COLUMN_USAGE kcu
         ON kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
        AND kcu.TABLE_NAME = tc.TABLE_NAME
        AND kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
       LEFT JOIN information_schema.CHECK_CONSTRAINTS cc
         ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
        AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
      WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
        AND tc.TABLE_NAME = 'stock_movements'
      ORDER BY tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION"
);

if ($constraints === []) {
    echo "No constraints found or metadata unavailable.\n";
} else {
    foreach ($constraints as $row) {
        echo implode(' | ', [
            (string) $row['CONSTRAINT_NAME'],
            (string) $row['CONSTRAINT_TYPE'],
            'COLUMN=' . (string) ($row['COLUMN_NAME'] ?? ''),
            'REFERENCES=' . (string) ($row['REFERENCED_TABLE_NAME'] ?? '') .
                '.' . (string) ($row['REFERENCED_COLUMN_NAME'] ?? ''),
            'CHECK=' . (string) ($row['CHECK_CLAUSE'] ?? ''),
        ]) . "\n";
    }
}

printSection('stock_movements triggers');
$triggers = safeQuery(
    $pdo,
    "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION,
            ACTION_STATEMENT
       FROM information_schema.TRIGGERS
      WHERE TRIGGER_SCHEMA = DATABASE()
        AND EVENT_OBJECT_TABLE = 'stock_movements'
      ORDER BY TRIGGER_NAME"
);

if ($triggers === []) {
    echo "No triggers found or trigger metadata unavailable.\n";
} else {
    foreach ($triggers as $row) {
        echo "\n" . ($row['TRIGGER_NAME'] ?? '') .
            " | " . ($row['ACTION_TIMING'] ?? '') .
            " " . ($row['EVENT_MANIPULATION'] ?? '') . "\n";
        echo (string) ($row['ACTION_STATEMENT'] ?? '') . "\n";
    }
}

printSection('Audit table columns');
$auditColumns = safeQuery(
    $pdo,
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'audit_events'
      ORDER BY ORDINAL_POSITION"
);

if ($auditColumns === []) {
    echo "audit_events columns not found or metadata unavailable.\n";
} else {
    foreach ($auditColumns as $row) {
        echo ($row['COLUMN_NAME'] ?? '') . " | " .
            ($row['COLUMN_TYPE'] ?? '') . " | NULL=" .
            ($row['IS_NULLABLE'] ?? '') . "\n";
    }
}

echo "\nREAD-ONLY CHECK COMPLETE. No database rows or schema objects were changed.\n";
