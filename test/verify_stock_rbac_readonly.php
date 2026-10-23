<?php

declare(strict_types=1);

/**
 * Read-only RBAC inspection for M-2 stock movement security testing.
 *
 * Run from project root:
 *   C:\php-8.3.33-Win32-vs16-x64\php.exe test\verify_stock_rbac_readonly.php
 *
 * Safety:
 * - Connects explicitly to inventory_intelligence_test only.
 * - Refuses to run against any other database.
 * - Executes SELECT statements only; performs no writes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/env.php';

const TEST_DATABASE = 'inventory_intelligence_test';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

if ($username === '') {
    fwrite(STDERR, "DB_USER is missing; stopped before connecting.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $host,
    $port,
    TEST_DATABASE,
    $charset
);

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database !== TEST_DATABASE) {
        throw new RuntimeException(
            'Safety stop: connected database is not ' . TEST_DATABASE
        );
    }

    echo "Connected database: {$database}\n";
    echo "Mode: READ-ONLY (SELECT statements only)\n";

    $tables = ['roles', 'permissions', 'role_permissions', 'users'];
    $countQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );

    foreach ($tables as $table) {
        $countQuery->execute([':table_name' => $table]);
        if ((int) $countQuery->fetchColumn() === 0) {
            echo "Table {$table}: MISSING\n";
            continue;
        }

        // Table names come only from the fixed allow-list above.
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        echo "Table {$table}: {$count} row(s)\n";
    }

    $permissionTableExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissions'"
    )->fetchColumn();

    if ((int) $permissionTableExists > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, permission_key, display_name
             FROM permissions
             WHERE permission_key = :permission_key
             LIMIT 1'
        );
        $stmt->execute([':permission_key' => 'stock.view']);
        $permission = $stmt->fetch();

        if ($permission === false) {
            echo "Permission stock.view: NOT FOUND\n";
        } else {
            echo 'Permission stock.view: FOUND (id='
                . (int) $permission['id'] . ")\n";
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, email, role, role_id, status
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute([
        ':email' => 'stock-movement-test@example.invalid',
    ]);
    $user = $stmt->fetch();

    if ($user === false) {
        echo "Test user: NOT FOUND\n";
    } else {
        echo 'Test user: id=' . (int) $user['id']
            . ' | tenant_id=' . (int) $user['tenant_id']
            . ' | role=' . (string) $user['role']
            . ' | role_id=' . ($user['role_id'] === null ? 'NULL' : (string) $user['role_id'])
            . ' | status=' . (string) $user['status'] . "\n";

        if ($user['role_id'] !== null) {
            $stmt = $pdo->prepare(
                'SELECT p.permission_key
                 FROM role_permissions rp
                 INNER JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = :role_id
                 ORDER BY p.permission_key'
            );
            $stmt->execute([':role_id' => (int) $user['role_id']]);
            $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);

            echo "Permissions assigned to test user's role:\n";
            if ($keys === []) {
                echo "  (none)\n";
            } else {
                foreach ($keys as $key) {
                    echo '  ' . (string) $key . "\n";
                }
            }
        } else {
            echo "Role permission mapping: not applicable; test user's role_id is NULL.\n";
        }
    }

    echo "PASS: read-only RBAC inspection completed; no rows or schema objects changed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'RBAC inspection failed: ' . $e->getMessage() . "\n");
    exit(1);
}
