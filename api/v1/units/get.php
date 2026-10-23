<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * GET /api/v1/units/get.php?id={unit_id}
 *
 * Responsibility:
 * - Return one tenant-scoped unit
 * - Enforce units.view permission
 * - Prevent cross-tenant data leakage
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';

/*
 * ---------------------------------------------------------
 * HTTP method
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    header('Allow: GET');

    api_error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only GET requests are allowed.'
    );
}

/*
 * ---------------------------------------------------------
 * Authentication / Tenant Context
 * ---------------------------------------------------------
 */

if (
    empty($_SESSION['authenticated']) ||
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    api_unauthenticated();
}

$userId = (int) $_SESSION['user_id'];
$tenantId = (int) $_SESSION['tenant_id'];

if ($userId <= 0 || $tenantId <= 0) {
    api_error_response(
        401,
        'INVALID_SESSION',
        'Your session is invalid. Please sign in again.'
    );
}

/*
 * ---------------------------------------------------------
 * Permission
 * ---------------------------------------------------------
 */

requirePermission('units.view');

/*
 * ---------------------------------------------------------
 * Unit ID
 * ---------------------------------------------------------
 */

$unitId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($unitId === false) {
    api_error_response(
        422,
        'INVALID_UNIT_ID',
        'Unit id must be a valid positive integer.'
    );
}

$unitId = (int) $unitId;

/*
 * ---------------------------------------------------------
 * Load tenant-scoped unit
 * ---------------------------------------------------------
 */

try {

    $unitStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            name,
            symbol,
            name_normalized,
            status,
            created_at,
            updated_at
        FROM units
        WHERE tenant_id = :tenant_id
          AND id = :unit_id
        LIMIT 1
        '
    );

    $unitStmt->execute([
        ':tenant_id' => $tenantId,
        ':unit_id' => $unitId
    ]);

    $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

    if (!$unit) {
        api_not_found(
            'Unit not found.'
        );
    }

    $requestId = audit_get_request_id();

    header(
        'X-Request-ID: ' . $requestId
    );

    http_response_code(200);

    echo json_encode(
        [
            'success' => true,
            'data' => [
                'unit' => [
                    'id' => (int) $unit['id'],
                    'name' => $unit['name'],
                    'symbol' => $unit['symbol'],
                    'status' => $unit['status'],
                    'created_at' => $unit['created_at'],
                    'updated_at' => $unit['updated_at']
                ]
            ],
            'request_id' => $requestId
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;

} catch (PDOException $e) {

    error_log(
        'Unit detail database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to load the unit.'
    );

} catch (Throwable $e) {

    error_log(
        'Unit detail error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to load the unit.'
    );
}