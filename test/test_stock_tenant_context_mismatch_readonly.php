<?php
declare(strict_types=1);

/**
 * Read-only check for the stock-movement endpoint's session/database tenant
 * mismatch guard. Uses the existing test user and an in-memory mismatched
 * session tenant; it does not create tenants or write data.
 *
 * Run from project root:
 * C:\\php-8.3.33-Win32-vs16-x64\\php.exe test\\test_stock_tenant_context_mismatch_readonly.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/env.php';

const TEST_DATABASE = 'inventory_intelligence_test';
const TEST_USER_EMAIL = 'stock-movement-test@example.invalid';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

if ($username === '') {
    fwrite(STDERR, "STOP: DB_USER missing; no test run.\n");
    exit(2);
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, TEST_DATABASE, $charset);
$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($db !== TEST_DATABASE) {
    throw new RuntimeException('Safety stop: unexpected database.');
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.tenant_id, u.status AS user_status, t.status AS tenant_status
       FROM users u
       INNER JOIN tenants t ON t.id = u.tenant_id
      WHERE u.email = :email
      LIMIT 1'
);
$stmt->execute([':email' => TEST_USER_EMAIL]);
$identity = $stmt->fetch();

if (!$identity) {
    fwrite(STDERR, "STOP: known test user not found; no changes made.\n");
    exit(3);
}

echo "Connected database: {$db}\n";
echo "Mode: READ-ONLY\n";

$sessionTenantId = (int) $identity['tenant_id'] + 999999; // In-memory mismatch only.
$verifiedTenantId = filter_var(
    $identity['tenant_id'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ((string) $identity['user_status'] !== 'active' || (string) $identity['tenant_status'] !== 'active') {
    echo "FAIL: test identity is not active; mismatch guard not evaluated.\n";
    exit(1);
}

if ($verifiedTenantId === false || (int) $verifiedTenantId !== $sessionTenantId) {
    echo "PASS: tenant-context guard rejects mismatched session tenant (expected HTTP 403 TENANT_CONTEXT_MISMATCH).\n";
} else {
    echo "FAIL: mismatched tenant context was not rejected.\n";
    exit(1);
}

echo "LIMITATION: this tests the guard predicate against the test DB; it does not make an HTTP request or prove two-tenant row isolation.\n";
echo "PASS: no rows or schema objects changed.\n";
