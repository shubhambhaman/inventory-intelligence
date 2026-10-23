<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

$database = 'inventory_intelligence_test';

$pdo = new PDO(
    'mysql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') .
    ';port=' . ($_ENV['DB_PORT'] ?? '3306') .
    ';dbname=' . $database . ';charset=utf8mb4',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('SAFETY STOP: unexpected database.');
}

$check = $pdo->prepare(
    'SELECT COLUMN_NAME
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME = ?
        AND COLUMN_NAME IN (?, ?)'
);
$check->execute([
    $database,
    'stock_movements',
    'reference_type',
    'reference_id',
]);

$existing = $check->fetchAll(PDO::FETCH_COLUMN);

if ($existing) {
    throw new RuntimeException(
        'SAFETY STOP: source-linkage columns already exist. Do not rerun migration.'
    );
}

$sql = file_get_contents(
    __DIR__ . '/../migrations/020_add_stock_movement_source_linkage.sql'
);

if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('Migration file is missing or empty.');
}

$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$sql = trim($sql);

if (!preg_match('/^ALTER\s+TABLE\s+stock_movements\b/i', $sql)) {
    throw new RuntimeException('SAFETY STOP: unexpected migration statement.');
}

$pdo->exec($sql);

$verify = $pdo->prepare(
    'SELECT COLUMN_NAME
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME = ?
        AND COLUMN_NAME IN (?, ?)
      ORDER BY COLUMN_NAME'
);
$verify->execute([
    $database,
    'stock_movements',
    'reference_type',
    'reference_id',
]);

$columns = $verify->fetchAll(PDO::FETCH_COLUMN);

if (count($columns) !== 2) {
    throw new RuntimeException('FAIL: source-linkage columns not verified.');
}

echo "PASS: connected only to {$database}" . PHP_EOL;
echo "PASS: migration 020 applied." . PHP_EOL;
echo "PASS: columns verified: " . implode(', ', $columns) . PHP_EOL;
