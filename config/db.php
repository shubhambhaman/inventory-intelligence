<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * Database Connection
 */

require_once __DIR__ . '/env.php';

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbname = $_ENV['DB_NAME'] ?? '';
$username = $_ENV['DB_USER'] ?? '';
$password = $_ENV['DB_PASSWORD'] ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

if (
    $dbname === '' ||
    $username === ''
) {
    http_response_code(500);
    exit('Database configuration is incomplete.');
}

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

try {
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log(
        'Database connection failed: ' .
        $e->getMessage()
    );

    http_response_code(500);
    exit('Database connection failed.');
}