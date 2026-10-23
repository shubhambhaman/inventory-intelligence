<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * DELETE /api/v1/auth/logout
 *
 * Destroys the current authenticated PHP session.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/session.php';
require_once __DIR__ . '/../../../config/csrf.php';

/**
 * Only DELETE is allowed.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    header('Allow: DELETE');

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Only DELETE requests are allowed.'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}



/**
 * CSRF protection.
 *
 * Logout is a browser/session mutation,
 * therefore a valid CSRF token is required.
 */
if (!csrf_is_valid()) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'CSRF_TOKEN_INVALID',
            'message' => 'Invalid or missing CSRF token.'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

/**
 * Destroy all server-side session data.
 */
$_SESSION = [];

/**
 * Delete the browser's session cookie.
 */
if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]
    );
}

/**
 * Destroy the PHP session.
 */
session_destroy();

/**
 * Successful logout.
 */
http_response_code(200);

echo json_encode([
    'success' => true,
    'message' => 'Signed out successfully.'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;