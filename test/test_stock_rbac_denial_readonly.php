<?php
declare(strict_types=1);

/**
 * M-2 RBAC denial predicate test (READ ONLY).
 * Run from project root:
 * C:\php-8.3.33-Win32-vs16-x64\php.exe test\test_stock_rbac_denial_readonly.php
 *
 * Connects explicitly to inventory_intelligence_test. Executes SELECT-only
 * database checks and invokes the real hasPermission() function. It does not
 * call requirePermission() because the centralized denial helper terminates
 * the request. It does not make HTTP requests or mutate rows/schema.
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
$testPdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$db = (string) $testPdo->query('SELECT DATABASE()')->fetchColumn();
if ($db !== TEST_DATABASE) {
    throw new RuntimeException('Safety stop: connected to unexpected database.');
}
echo "Connected database: {$db}\n";
echo "Mode: READ-ONLY\n";

$stmt = $testPdo->prepare(
    'SELECT id, tenant_id, role_id, status
       FROM users
      WHERE email = :email
      LIMIT 1'
);
$stmt->execute([':email' => TEST_USER_EMAIL]);
$user = $stmt->fetch();

if (!$user) {
    echo "BLOCKED: reserved test user not found; no changes made.\n";
    exit(3);
}

$_SESSION['authenticated'] = true;
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['tenant_id'] = (int) $user['tenant_id'];

/* Load actual authorization functions, then ensure checks use test DB PDO. */
require_once $root . '/config/authorization.php';
$GLOBALS['pdo'] = $testPdo;
$pdo = $testPdo;

if ((string) $GLOBALS['pdo']->query('SELECT DATABASE()')->fetchColumn() !== TEST_DATABASE) {
    throw new RuntimeException('Safety stop: authorization check is not using the test DB.');
}

echo 'Test user id=' . (int) $user['id']
    . ', tenant_id=' . (int) $user['tenant_id']
    . ', role_id=' . ($user['role_id'] === null ? 'NULL' : (string) $user['role_id'])
    . ', status=' . (string) $user['status'] . "\n";

$allowed = hasPermission('stock.view');
if ($allowed === false) {
    echo "PASS: real hasPermission('stock.view') returned false for the no-permission test user.\n";
} else {
    echo "FAIL: test user unexpectedly has stock.view.\n";
    exit(1);
}

echo "LIMITATION: this CLI test verifies the real permission predicate, not the HTTP 403 response from requirePermission().\n";
echo "PASS: completed; no rows or schema objects changed.\n";
