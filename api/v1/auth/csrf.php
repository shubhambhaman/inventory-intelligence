<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Only GET requests are allowed.'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

if (
    empty($_SESSION['authenticated']) ||
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'UNAUTHENTICATED',
            'message' => 'Authentication is required.'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

$userId = (int) $_SESSION['user_id'];
$tenantId = (int) $_SESSION['tenant_id'];

if ($userId <= 0 || $tenantId <= 0) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'INVALID_SESSION',
            'message' => 'Your session is invalid. Please sign in again.'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

try {
    $token = csrf_token();
} catch (Throwable $e) {

    error_log(
        'CSRF token generation failed: ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'CSRF_TOKEN_ERROR',
            'message' => 'Unable to generate CSRF token.'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

echo json_encode([
    'success' => true,
    'data' => [
        'csrf_token' => $token
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;
