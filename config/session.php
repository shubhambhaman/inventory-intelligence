<?php

declare(strict_types=1);

/**
 * Central session bootstrap.
 * Must be loaded before any session_start() call or output.
 */

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

if (headers_sent($file, $line)) {
    throw new RuntimeException(
        "Session bootstrap loaded after output at {$file}:{$line}."
    );
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

$isHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== ''
    && strtolower((string) $_SERVER['HTTPS']) !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
