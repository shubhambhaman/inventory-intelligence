<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * POST /api/v1/warehouses/access.php
 *
 * Responsibility:
 * - Assign a user to a warehouse
 * - Remove a user's warehouse access
 * - Enforce authentication and tenant isolation
 * - Enforce CSRF protection
 * - Prevent cross-tenant user/warehouse access
 * - Record warehouse.access_changed audit event
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/csrf.php';


/**
 * ---------------------------------------------------------
 * HTTP method
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Allow: POST');

    api_error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only POST requests are allowed.'
    );
}


/**
 * ---------------------------------------------------------
 * Authentication
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


/**
 * ---------------------------------------------------------
 * Session integrity
 * ---------------------------------------------------------
 */

if ($userId <= 0 || $tenantId <= 0) {

    api_error_response(
        401,
        'INVALID_SESSION',
        'Your session is invalid. Please sign in again.'
    );
}


/**
 * ---------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------
 *
 * The specification requires warehouse access management,
 * but does not define a separate warehouse-access permission.
 *
 * Therefore use the existing warehouse mutation permission
 * rather than inventing a new permission key.
 */

requirePermission('warehouses.update');


/**
 * ---------------------------------------------------------
 * CSRF
 * ---------------------------------------------------------
 */

if (!csrf_is_valid()) {

    api_error_response(
        403,
        'CSRF_TOKEN_INVALID',
        'Invalid or missing CSRF token.'
    );
}


/**
 * ---------------------------------------------------------
 * Read JSON body
 * ---------------------------------------------------------
 */

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {

    api_invalid_request(
        'Request body is required.'
    );
}


/**
 * ---------------------------------------------------------
 * Decode JSON
 * ---------------------------------------------------------
 */

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


/**
 * ---------------------------------------------------------
 * Allowed fields
 * ---------------------------------------------------------
 */

$allowedFields = [
    'user_id',
    'warehouse_id',
    'action'
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


/**
 * ---------------------------------------------------------
 * Required fields
 * ---------------------------------------------------------
 */

if (
    !array_key_exists('user_id', $data) ||
    !array_key_exists('warehouse_id', $data)
) {

    api_error_response(
        422,
        'MISSING_FIELDS',
        'user_id and warehouse_id are required.'
    );
}


/**
 * ---------------------------------------------------------
 * Validate user ID
 * ---------------------------------------------------------
 */

$targetUserId = filter_var(
    $data['user_id'],
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($targetUserId === false) {

    api_error_response(
        422,
        'INVALID_USER_ID',
        'user_id must be a valid positive integer.'
    );
}

$targetUserId = (int) $targetUserId;


/**
 * ---------------------------------------------------------
 * Validate warehouse ID
 * ---------------------------------------------------------
 */

$warehouseId = filter_var(
    $data['warehouse_id'],
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($warehouseId === false) {

    api_error_response(
        422,
        'INVALID_WAREHOUSE_ID',
        'warehouse_id must be a valid positive integer.'
    );
}

$warehouseId = (int) $warehouseId;


/**
 * ---------------------------------------------------------
 * Validate action
 * ---------------------------------------------------------
 *
 * Default action is assign.
 */

$action = 'assign';

if (array_key_exists('action', $data)) {

    if (!is_string($data['action'])) {

        api_error_response(
            422,
            'INVALID_ACTION',
            'action must be assign or remove.'
        );
    }

    $action = strtolower(trim($data['action']));

    if (!in_array($action, ['assign', 'remove'], true)) {

        api_error_response(
            422,
            'INVALID_ACTION',
            'action must be assign or remove.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Transaction
 * ---------------------------------------------------------
 */

try {

    $pdo->beginTransaction();


    /**
     * -----------------------------------------------------
     * Load target user within current tenant
     * -----------------------------------------------------
     */

    $userStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            email,
            status
        FROM users
        WHERE id = :user_id
          AND tenant_id = :tenant_id
        LIMIT 1
        '
    );

    $userStmt->execute([
        ':user_id' => $targetUserId,
        ':tenant_id' => $tenantId
    ]);

    $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {

        $pdo->rollBack();

        api_error_response(
            404,
            'USER_NOT_FOUND',
            'The selected user does not belong to this company.'
        );
    }


    /**
     * -----------------------------------------------------
     * Load warehouse within current tenant
     * -----------------------------------------------------
     */

    $warehouseStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            warehouse_code,
            name,
            status
        FROM warehouses
        WHERE id = :warehouse_id
          AND tenant_id = :tenant_id
        LIMIT 1
        '
    );

    $warehouseStmt->execute([
        ':warehouse_id' => $warehouseId,
        ':tenant_id' => $tenantId
    ]);

    $warehouse = $warehouseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$warehouse) {

        $pdo->rollBack();

        api_error_response(
            404,
            'WAREHOUSE_NOT_FOUND',
            'The selected warehouse does not belong to this company.'
        );
    }


    /**
     * -----------------------------------------------------
     * Existing access
     * -----------------------------------------------------
     */

    $accessStmt = $pdo->prepare(
        '
        SELECT
            user_id,
            warehouse_id
        FROM user_warehouse_access
        WHERE user_id = :user_id
          AND warehouse_id = :warehouse_id
        LIMIT 1
        '
    );

    $accessStmt->execute([
        ':user_id' => $targetUserId,
        ':warehouse_id' => $warehouseId
    ]);

    $existingAccess = $accessStmt->fetch(PDO::FETCH_ASSOC);


    /**
     * -----------------------------------------------------
     * ASSIGN
     * -----------------------------------------------------
     */

    if ($action === 'assign') {

        if ($existingAccess) {

            $pdo->rollBack();

            api_error_response(
                409,
                'ALREADY_ASSIGNED',
                'The user already has access to this warehouse.'
            );
        }


        $insertStmt = $pdo->prepare(
            '
            INSERT INTO user_warehouse_access (
                user_id,
                warehouse_id
            )
            VALUES (
                :user_id,
                :warehouse_id
            )
            '
        );

        $insertStmt->execute([
            ':user_id' => $targetUserId,
            ':warehouse_id' => $warehouseId
        ]);


        /**
         * -------------------------------------------------
         * Audit assignment
         * -------------------------------------------------
         */

        $after = [
            'user_id' => $targetUserId,
            'warehouse_id' => $warehouseId,
            'access' => 'assigned'
        ];

        $auditId = audit_event(
            'warehouse.access_changed',
            'warehouse',
            (string) $warehouseId,
            null,
            $after
        );


        /**
         * -------------------------------------------------
         * Commit
         * -------------------------------------------------
         */

        $pdo->commit();


        /**
         * -------------------------------------------------
         * Success response
         * -------------------------------------------------
         */

        echo json_encode(
            [
                'success' => true,
                'message' => 'Warehouse access assigned successfully.',
                'data' => [
                    'user_id' => $targetUserId,
                    'warehouse_id' => $warehouseId,
                    'warehouse_code' => $warehouse['warehouse_code'],
                    'warehouse_name' => $warehouse['name'],
                    'access' => 'assigned',
                    'audit_id' => $auditId
                ]
            ],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }


    /**
     * -----------------------------------------------------
     * REMOVE
     * -----------------------------------------------------
     */

    if (!$existingAccess) {

        $pdo->rollBack();

        api_error_response(
            404,
            'ACCESS_NOT_FOUND',
            'The user does not have access to this warehouse.'
        );
    }


    /**
     * -----------------------------------------------------
     * Delete access
     * -----------------------------------------------------
     */

    $deleteStmt = $pdo->prepare(
        '
        DELETE FROM user_warehouse_access
        WHERE user_id = :user_id
          AND warehouse_id = :warehouse_id
        '
    );

    $deleteStmt->execute([
        ':user_id' => $targetUserId,
        ':warehouse_id' => $warehouseId
    ]);


    /**
     * -----------------------------------------------------
     * Audit removal
     * -----------------------------------------------------
     *
     * Before state = access existed.
     * After state = access removed.
     */

    $before = [
        'user_id' => $targetUserId,
        'warehouse_id' => $warehouseId,
        'access' => 'assigned'
    ];

    $after = [
        'user_id' => $targetUserId,
        'warehouse_id' => $warehouseId,
        'access' => 'removed'
    ];

    $auditId = audit_event(
        'warehouse.access_changed',
        'warehouse',
        (string) $warehouseId,
        $before,
        $after
    );


    /**
     * -----------------------------------------------------
     * Commit
     * -----------------------------------------------------
     */

    $pdo->commit();


    /**
     * -----------------------------------------------------
     * Success response
     * -----------------------------------------------------
     */

    echo json_encode(
        [
            'success' => true,
            'message' => 'Warehouse access removed successfully.',
            'data' => [
                'user_id' => $targetUserId,
                'warehouse_id' => $warehouseId,
                'warehouse_code' => $warehouse['warehouse_code'],
                'warehouse_name' => $warehouse['name'],
                'access' => 'removed',
                'audit_id' => $auditId
            ]
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;


} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    /**
     * Duplicate assignment can still happen under
     * concurrent requests. The database primary key
     * remains the final authority.
     */

    if (
        isset($e->errorInfo[1]) &&
        (int) $e->errorInfo[1] === 1062
    ) {

        api_conflict(
            'The user already has access to this warehouse.'
        );
    }


    error_log(
        'Warehouse access database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to change warehouse access.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Warehouse access error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to change warehouse access.'
    );
}