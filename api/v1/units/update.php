<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * PATCH /api/v1/units/update.php?id={unit_id}
 *
 * Responsibilities:
 * - Update tenant-scoped unit metadata
 * - Support unit name, symbol and status
 * - Enforce RBAC and CSRF
 * - Validate input
 * - Prevent duplicate normalized unit names
 * - Record before/after audit event
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/csrf.php';

/* ---------------------------------------------------------
   Request method
--------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only PATCH requests are allowed.'
    );
}

/* ---------------------------------------------------------
   Authentication and tenant context
--------------------------------------------------------- */

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

/* ---------------------------------------------------------
   Permission and CSRF
--------------------------------------------------------- */

requirePermission('units.manage');

if (!csrf_is_valid()) {
    api_error_response(
        403,
        'CSRF_TOKEN_INVALID',
        'Invalid or missing CSRF token.'
    );
}

/* ---------------------------------------------------------
   Validate unit ID
--------------------------------------------------------- */

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

/* ---------------------------------------------------------
   Parse JSON request body
--------------------------------------------------------- */

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    api_invalid_request(
        'Request body is required.'
    );
}

try {
    $data = json_decode(
        $rawBody,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    api_error_response(
        400,
        'INVALID_JSON',
        'Invalid JSON request body.'
    );
}

if (!is_array($data)) {
    api_error_response(
        400,
        'INVALID_JSON',
        'Invalid JSON request body.'
    );
}

/* ---------------------------------------------------------
   Allowed fields
--------------------------------------------------------- */

$allowedFields = [
    'name',
    'symbol',
    'status'
];

$unknownFields = array_diff(
    array_keys($data),
    $allowedFields
);

if (!empty($unknownFields)) {
    api_error_response(
        422,
        'UNKNOWN_FIELDS',
        'One or more fields are not allowed.',
        [
            'fields' => array_values($unknownFields)
        ]
    );
}

if (empty($data)) {
    api_error_response(
        422,
        'NO_FIELDS',
        'At least one unit field must be provided.'
    );
}

/* ---------------------------------------------------------
   Validate and normalize supplied symbol
--------------------------------------------------------- */

$symbol = null;

if (array_key_exists('symbol', $data)) {
    if (
        $data['symbol'] !== null &&
        !is_string($data['symbol'])
    ) {
        api_error_response(
            422,
            'INVALID_SYMBOL',
            'Unit abbreviation must be a string or null.'
        );
    }

    $symbol = $data['symbol'] === null
        ? null
        : trim($data['symbol']);

    if ($symbol === '') {
        $symbol = null;
    }

    if (
        $symbol !== null &&
        mb_strlen($symbol, 'UTF-8') > 20
    ) {
        api_error_response(
            422,
            'INVALID_SYMBOL',
            'Unit abbreviation cannot exceed 20 characters.'
        );
    }
}

/* ---------------------------------------------------------
   Update unit
--------------------------------------------------------- */

try {
    $pdo->beginTransaction();

    /*
     * Load existing unit.
     * Always scope by authenticated tenant.
     */
    $unitStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            name,
            name_normalized,
            symbol,
            status,
            created_at,
            updated_at
        FROM units
        WHERE tenant_id = :tenant_id
          AND id = :unit_id
        LIMIT 1
        FOR UPDATE
        '
    );

    $unitStmt->execute([
        ':tenant_id' => $tenantId,
        ':unit_id' => $unitId
    ]);

    $before = $unitStmt->fetch(PDO::FETCH_ASSOC);

    if (!$before) {
        $pdo->rollBack();

        api_not_found('Unit not found.');
    }

    $name = (string) $before['name'];
    $nameNormalized = (string) $before['name_normalized'];
    $status = (string) $before['status'];
    $currentSymbol = $before['symbol'] ?? null;

    /*
     * Preserve the existing symbol if it wasn't supplied.
     * Explicit null or an empty string clears it.
     */
    if (array_key_exists('symbol', $data)) {
        $currentSymbol = $symbol;
    }

    /* Validate name if supplied. */

    if (array_key_exists('name', $data)) {
        if (!is_string($data['name'])) {
            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_NAME',
                'Unit name must be a string.'
            );
        }

        $name = trim($data['name']);

        if ($name === '') {
            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_NAME',
                'Unit name is required.'
            );
        }

        if (mb_strlen($name, 'UTF-8') > 150) {
            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_NAME',
                'Unit name cannot exceed 150 characters.'
            );
        }

        $nameNormalized = mb_strtolower(
            $name,
            'UTF-8'
        );
    }

    /* Validate status if supplied. */

    if (array_key_exists('status', $data)) {
        if (!is_string($data['status'])) {
            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_STATUS',
                'Status must be active or inactive.'
            );
        }

        $status = trim($data['status']);

        if (!in_array(
            $status,
            ['active', 'inactive'],
            true
        )) {
            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_STATUS',
                'Status must be active or inactive.'
            );
        }
    }

    /*
     * Prevent duplicate normalized names within this tenant.
     */
    $duplicateStmt = $pdo->prepare(
        '
        SELECT id
        FROM units
        WHERE tenant_id = :tenant_id
          AND name_normalized = :name_normalized
          AND id <> :unit_id
        LIMIT 1
        '
    );

    $duplicateStmt->execute([
        ':tenant_id' => $tenantId,
        ':name_normalized' => $nameNormalized,
        ':unit_id' => $unitId
    ]);

    if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();

        api_conflict(
            'A unit with this name already exists.'
        );
    }

    /*
     * Update name, symbol and status.
     */
    $updateStmt = $pdo->prepare(
        '
        UPDATE units
        SET
            name = :name,
            name_normalized = :name_normalized,
            symbol = :symbol,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE tenant_id = :tenant_id
          AND id = :unit_id
        '
    );

    $updateStmt->execute([
        ':name' => $name,
        ':name_normalized' => $nameNormalized,
        ':symbol' => $currentSymbol,
        ':status' => $status,
        ':tenant_id' => $tenantId,
        ':unit_id' => $unitId
    ]);

    /*
     * Load updated unit for response and audit.
     */
    $afterStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            name,
            name_normalized,
            symbol,
            status,
            created_at,
            updated_at
        FROM units
        WHERE tenant_id = :tenant_id
          AND id = :unit_id
        LIMIT 1
        '
    );

    $afterStmt->execute([
        ':tenant_id' => $tenantId,
        ':unit_id' => $unitId
    ]);

    $after = $afterStmt->fetch(PDO::FETCH_ASSOC);

    if (!$after) {
        throw new RuntimeException(
            'Updated unit could not be loaded.'
        );
    }

    /* Audit before and after values. */

    $auditId = audit_event(
        'unit.updated',
        'unit',
        (string) $unitId,
        $before,
        $after
    );

    $pdo->commit();

    $requestId = audit_get_request_id();

    header('X-Request-ID: ' . $requestId);

    http_response_code(200);

    echo json_encode(
        [
            'success' => true,
            'message' => 'Unit updated successfully.',
            'data' => [
                'unit' => [
                    'id' => (int) $after['id'],
                    'name' => $after['name'],
                    'symbol' => $after['symbol'],
                    'status' => $after['status'],
                    'created_at' => $after['created_at'],
                    'updated_at' => $after['updated_at']
                ],
                'audit_id' => $auditId,
                'request_id' => $requestId
            ]
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * Protect against duplicate-key conflicts.
     */
    if (
        isset($e->errorInfo[1]) &&
        (int) $e->errorInfo[1] === 1062
    ) {
        api_conflict(
            'A unit with this name already exists.'
        );
    }

    error_log(
        'Unit update database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to update the unit.'
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Unit update error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to update the unit.'
    );
}
