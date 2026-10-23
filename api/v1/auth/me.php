<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * GET /api/v1/auth/me
 *
 * Returns the currently authenticated user
 * and their current tenant/company context.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';

/**
 * JSON response helper.
 */
function json_response(
    int $statusCode,
    array $data
): never {
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * Only GET is allowed.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    json_response(405, [
        'success' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Only GET requests are allowed.'
        ]
    ]);
}

/**
 * Start existing session.
 */
require_once __DIR__ . '/../../../config/session.php';

/**
 * Authentication check.
 */
if (
    empty($_SESSION['authenticated']) ||
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    json_response(401, [
        'success' => false,
        'error' => [
            'code' => 'UNAUTHENTICATED',
            'message' => 'Authentication is required.'
        ]
    ]);
}

$userId   = (int) $_SESSION['user_id'];
$tenantId = (int) $_SESSION['tenant_id'];

/**
 * Validate session identifiers.
 */
if ($userId <= 0 || $tenantId <= 0) {
    json_response(401, [
        'success' => false,
        'error' => [
            'code' => 'INVALID_SESSION',
            'message' => 'Your session is invalid. Please sign in again.'
        ]
    ]);
}

try {

    /**
     * Fetch the authenticated user and
     * their tenant/company.
     *
     * Tenant comes from the server-side session.
     * We never accept tenant_id from the browser.
     */
    $stmt = $pdo->prepare(
        'SELECT
            u.id AS user_id,
            u.email,
            u.name AS user_name,
            u.role,
            u.status AS user_status,
            u.last_login_at,

            t.id AS company_id,
            t.name AS company_name,
            t.legal_name AS company_legal_name,
            t.country AS company_country,
            t.currency AS company_currency,
            t.timezone AS company_timezone,
            t.fiscal_year_start AS company_fiscal_year_start,
            t.status AS company_status

         FROM users u

         INNER JOIN tenants t
            ON t.id = u.tenant_id

         WHERE u.id = :user_id
           AND u.tenant_id = :tenant_id

         LIMIT 1'
    );

    $stmt->execute([
        ':user_id' => $userId,
        ':tenant_id' => $tenantId
    ]);

    $context = $stmt->fetch();

    /**
     * User/company must still exist.
     */
    if (!$context) {
        json_response(401, [
            'success' => false,
            'error' => [
                'code' => 'INVALID_SESSION',
                'message' => 'Your session is no longer valid. Please sign in again.'
            ]
        ]);
    }

    /**
     * User must remain active.
     */
    if ($context['user_status'] !== 'active') {
        json_response(403, [
            'success' => false,
            'error' => [
                'code' => 'ACCOUNT_INACTIVE',
                'message' => 'Your user account is not active.'
            ]
        ]);
    }

    /**
     * Company must remain active.
     */
    if ($context['company_status'] !== 'active') {
        json_response(403, [
            'success' => false,
            'error' => [
                'code' => 'COMPANY_INACTIVE',
                'message' => 'Your company account is not active.'
            ]
        ]);
    }

    /**
     * Return current authentication context.
     */
    json_response(200, [
        'success' => true,
        'data' => [
            'user' => [
                'id' => (int) $context['user_id'],
                'name' => $context['user_name'],
                'email' => $context['email'],
                'role' => $context['role'],
                'status' => $context['user_status'],
                'last_login_at' => $context['last_login_at']
            ],
            'company' => [
                'id' => (int) $context['company_id'],
                'name' => $context['company_name'],
                'legal_name' => $context['company_legal_name'],
                'country' => $context['company_country'],
                'currency' => $context['company_currency'],
                'timezone' => $context['company_timezone'],
                'fiscal_year_start' => $context['company_fiscal_year_start'],
                'status' => $context['company_status']
            ]
        ]
    ]);

} catch (PDOException $e) {

    error_log(
        'Auth me database error: ' .
        $e->getMessage()
    );

    json_response(500, [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Unable to load your account information.'
        ]
    ]);

} catch (Throwable $e) {

    error_log(
        'Auth me error: ' .
        $e->getMessage()
    );

    json_response(500, [
        'success' => false,
        'error' => [
            'code' => 'SERVER_ERROR',
            'message' => 'Unable to load your account information.'
        ]
    ]);
}