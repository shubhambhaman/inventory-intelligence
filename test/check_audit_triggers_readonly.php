
<?php


/**
 * READ-ONLY check for audit_events triggers.
 * Connects only to inventory_intelligence_test.
 * Does not modify rows or schema.
 */

require_once dirname(__DIR__) . '/config/env.php';

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USER'] ?? '');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');
$charset = (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

if ($username === '') {
    throw new RuntimeException('DB_USER is missing. Stopped safely.');
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname=inventory_intelligence_test;charset={$charset}",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

if ($dbName !== 'inventory_intelligence_test') {
    throw new RuntimeException('Safety stop: unexpected database.');
}

echo "Connected database: {$dbName}\n";
echo "Mode: READ-ONLY\n";

$stmt = $pdo->prepare(
    "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING
       FROM information_schema.TRIGGERS
      WHERE TRIGGER_SCHEMA = :db_name
        AND EVENT_OBJECT_TABLE = 'audit_events'
      ORDER BY TRIGGER_NAME"
);

$stmt->execute([':db_name' => $dbName]);
$triggers = $stmt->fetchAll();

if (!$triggers) {
    echo "No triggers found on audit_events.\n";
} else {
    foreach ($triggers as $trigger) {
        printf(
            "%s | %s | %s | %s\n",
            $trigger['TRIGGER_NAME'],
            $trigger['EVENT_MANIPULATION'],
            $trigger['EVENT_OBJECT_TABLE'],
            $trigger['ACTION_TIMING']
        );
    }
}

echo "PASS: read-only check completed; no rows or schema objects changed.\n";
