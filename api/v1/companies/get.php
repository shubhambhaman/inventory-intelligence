<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * GET /api/v1/companies
 *
 * Returns the company belonging to the currently
 * authenticated user's tenant.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';

/**
 * JSON response helper.
 */
function json_response(int $statusCode, array $data): never
{
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
 * Start the existing PHP session.
 */
session_start();

/**
 * Authentication check.
 *
 * We do NOT accept tenant_id from the browser.
 * Tenant context comes from the authenticated session.
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
 * Basic session integrity check.
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
     * Fetch only the fields required by the company context.
     *
     * No SELECT *.
     */
    $stmt = $pdo->prepare(
        'SELECT
            id,
            name,
            legal_name,
            country,
            currency,
            timezone,
            fiscal_year_start,
            status,
            created_at,
            updated_at
         FROM tenants
         WHERE id = :tenant_id
         LIMIT 1'
    );

    $stmt->execute([
        ':tenant_id' => $tenantId
    ]);

    $company = $stmt->fetch();

    /**
     * Tenant from session must exist.
     */
    if (!$company) {
        json_response(404, [
            'success' => false,
            'error' => [
                'code' => 'COMPANY_NOT_FOUND',
                'message' => 'Company could not be found.'
            ]
        ]);
    }

    /**
     * Return company context.
     */
    json_response(200, [
        'success' => true,
        'data' => [
        'company' => [
            'id' => (int) $company['id'],
            'name' => $company['name'],
            'legal_name' => $company['legal_name'],
            'country' => $company['country'],
            'currency' => $company['currency'],
            'timezone' => $company['timezone'],
            'fiscal_year_start' => $company['fiscal_year_start'],
            'status' => $company['status'],
            'created_at' => $company['created_at'],
            'updated_at' => $company['updated_at']
        ]
        ]
    ]);

} catch (PDOException $e) {

    /**
     * Do not expose database internals to the browser.
     */
    error_log(
        'Get company database error: ' . $e->getMessage()
    );

    json_response(500, [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Unable to load company information.'
        ]
    ]);
}