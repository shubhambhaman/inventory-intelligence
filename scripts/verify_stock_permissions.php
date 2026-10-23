<?php
declare(strict_types=1);

/**
 * Read-only RBAC verification for Inventory Intelligence Platform.
 * Run from project root:
 * & "C:\php-8.3.33-Win32-vs16-x64\php.exe" scripts\verify_stock_permissions.php
 *
 * SELECT-only; no database changes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/db.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

function printRows(string $title, array $rows): void
{
    echo "\n=== {$title} ===\n";
    if ($rows === []) {
        echo "(no rows)\n";
        return;
    }
    foreach ($rows as $row) {
        $parts = [];
        foreach ($row as $key => $value) {
            $parts[] = $key . '=' . ($value === null ? 'NULL' : (string) $value);
        }
        echo implode(' | ', $parts) . "\n";
    }
}

$db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Connected database: {$db}\n";
echo "READ-ONLY RBAC check. No writes will be performed.\n";

$tables = $pdo->query(
    "SELECT TABLE_NAME
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('permissions', 'roles', 'role_permissions', 'users')"
)->fetchAll(PDO::FETCH_COLUMN);

printRows('RBAC tables found', array_map(
    static fn(string $name): array => ['table_name' => $name],
    $tables
));

if (!in_array('permissions', $tables, true)) {
    echo "\nPermissions table is missing; cannot inspect permission keys.\n";
    exit(0);
}

$permissionColumns = $pdo->query(
    "SELECT COLUMN_NAME
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'permissions'
      ORDER BY ORDINAL_POSITION"
)->fetchAll(PDO::FETCH_COLUMN);

printRows('Permission table columns', array_map(
    static fn(string $name): array => ['column_name' => $name],
    $permissionColumns
));

$knownKeyColumns = ['permission_key', 'key', 'name', 'code', 'slug'];
$keyColumn = null;
foreach ($knownKeyColumns as $candidate) {
    if (in_array($candidate, $permissionColumns, true)) {
        $keyColumn = $candidate;
        break;
    }
}

if ($keyColumn === null) {
    echo "\nCould not safely identify the permission key column; no dynamic query executed.\n";
    exit(0);
}

$descriptionColumn = null;
foreach (['description', 'label', 'display_name'] as $candidate) {
    if (in_array($candidate, $permissionColumns, true)) {
        $descriptionColumn = $candidate;
        break;
    }
}

$select = 'SELECT id, `' . $keyColumn . '` AS permission_key';
if ($descriptionColumn !== null) {
    $select .= ', `' . $descriptionColumn . '` AS description';
}
$select .= ' FROM permissions ORDER BY `' . $keyColumn . '`';

$permissions = $pdo->query($select)->fetchAll(PDO::FETCH_ASSOC);
printRows('Existing permission keys', $permissions);

if (in_array('roles', $tables, true)) {
    $roleColumns = $pdo->query(
        "SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'roles'
          ORDER BY ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_COLUMN);

    $roleNameColumn = null;
    foreach (['name', 'role_name', 'slug', 'code'] as $candidate) {
        if (in_array($candidate, $roleColumns, true)) {
            $roleNameColumn = $candidate;
            break;
        }
    }

    if ($roleNameColumn !== null) {
        $roles = $pdo->query(
            'SELECT id, `' . $roleNameColumn . '` AS role_name FROM roles ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        printRows('Existing roles', $roles);
    }
}

echo "\nREAD-ONLY RBAC CHECK COMPLETE. No database rows or schema objects were changed.\n";
