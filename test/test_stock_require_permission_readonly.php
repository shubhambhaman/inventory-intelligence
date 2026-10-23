<?php
declare(strict_types=1);

/**
 * Read-only test of the real requirePermission('stock.view') denial path.
 * Run from project root:
 * C:\\php-8.3.33-Win32-vs16-x64\\php.exe test\\test_stock_require_permission_readonly.php
 * Uses inventory_intelligence_test only. No database writes.
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

if ((string) $testPdo->query('SELECT DATABASE()')->fetchColumn() !== TEST_DATABASE) {
    throw new RuntimeException('Safety stop: unexpected database.');
}

$stmt = $testPdo->prepare('SELECT id, tenant_id, status FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => TEST_USER_EMAIL]);
$user = $stmt->fetch();

if (!$user) {
    fwrite(STDERR, "STOP: test user not found; no changes made.\n");
    exit(3);
}

$_SESSION['authenticated'] = true;
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['tenant_id'] = (int) $user['tenant_id'];

/* Load the real authorization functions, then restore the verified test PDO. */
require_once $root . '/config/authorization.php';
$GLOBALS['pdo'] = $testPdo;
$pdo = $testPdo;

if ((string) $GLOBALS['pdo']->query('SELECT DATABASE()')->fetchColumn() !== TEST_DATABASE) {
    throw new RuntimeException('Safety stop: permission check is not using test DB.');
}

echo "Connected database: " . TEST_DATABASE . "\n";
echo "Mode: READ-ONLY\n";
echo "Calling actual requirePermission('stock.view'); expected centralized 403 JSON below.\n";

/* Real api_forbidden() should terminate with the centralized denial response. */
requirePermission('stock.view');

fwrite(STDERR, "FAIL: requirePermission returned instead of denying access.\n");
exit(1);
