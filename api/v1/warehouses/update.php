<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * PATCH /api/v1/warehouses/update.php?id={warehouse_id}
 *
 * Responsibility:
 * - Update warehouse metadata for authenticated tenant
 * - Enforce warehouses.update permission
 * - Enforce CSRF protection
 * - Validate warehouse input
 * - Enforce tenant-scoped parent warehouse
 * - Prevent warehouse hierarchy cycles
 * - Prevent duplicate warehouse codes
 * - Record before/after warehouse.updated audit event
 * - Return centralized API error contract
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

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {

    header('Allow: PATCH');

    api_error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only PATCH requests are allowed.'
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
 * Warehouse ID
 * ---------------------------------------------------------
 */

$warehouseId = filter_var(
    $_GET['id'] ?? null,
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
        'Warehouse id must be a valid positive integer.'
    );
}

$warehouseId = (int) $warehouseId;


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
 *
 * tenant_id is intentionally NOT allowed.
 * Tenant comes from authenticated session.
 */

$allowedFields = [
    'warehouse_code',
    'name',
    'address',
    'city',
    'state',
    'status',
    'parent_location_id'
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
 * Require at least one field
 * ---------------------------------------------------------
 */

if (empty($data)) {

    api_error_response(
        422,
        'NO_FIELDS',
        'At least one warehouse field must be provided.'
    );
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
     * Load existing warehouse
     * -----------------------------------------------------
     */

    $warehouseStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            warehouse_code,
            name,
            address,
            city,
            state,
            status,
            parent_location_id,
            created_at,
            updated_at
        FROM warehouses
        WHERE tenant_id = :tenant_id
          AND id = :warehouse_id
        LIMIT 1
        '
    );

    $warehouseStmt->execute([
        ':tenant_id' => $tenantId,
        ':warehouse_id' => $warehouseId
    ]);

    $before = $warehouseStmt->fetch(PDO::FETCH_ASSOC);


    if (!$before) {

        $pdo->rollBack();

        api_not_found(
            'Warehouse not found.'
        );
    }


    /**
     * -----------------------------------------------------
     * Current values
     * -----------------------------------------------------
     */

    $warehouseCode = $before['warehouse_code'];
    $name = $before['name'];
    $address = $before['address'];
    $city = $before['city'];
    $state = $before['state'];
    $status = $before['status'];

    $parentLocationId =
        $before['parent_location_id'] !== null
            ? (int) $before['parent_location_id']
            : null;


    /**
     * -----------------------------------------------------
     * Warehouse code
     * -----------------------------------------------------
     */

    if (array_key_exists('warehouse_code', $data)) {

        $warehouseCode = trim(
            (string) $data['warehouse_code']
        );

        if ($warehouseCode === '') {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_WAREHOUSE_CODE',
                'Warehouse code is required.'
            );
        }

        if (mb_strlen($warehouseCode) > 100) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_WAREHOUSE_CODE',
                'Warehouse code cannot exceed 100 characters.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Name
     * -----------------------------------------------------
     */

    if (array_key_exists('name', $data)) {

        $name = trim(
            (string) $data['name']
        );

        if ($name === '') {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_NAME',
                'Warehouse name is required.'
            );
        }

        if (mb_strlen($name) > 150) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_NAME',
                'Warehouse name cannot exceed 150 characters.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Address
     * -----------------------------------------------------
     */

    if (array_key_exists('address', $data)) {

        $address =
            $data['address'] === null
                ? null
                : trim((string) $data['address']);

        if ($address === '') {
            $address = null;
        }

        if (
            $address !== null &&
            mb_strlen($address) > 255
        ) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_ADDRESS',
                'Address cannot exceed 255 characters.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * City
     * -----------------------------------------------------
     */

    if (array_key_exists('city', $data)) {

        $city =
            $data['city'] === null
                ? null
                : trim((string) $data['city']);

        if ($city === '') {
            $city = null;
        }

        if (
            $city !== null &&
            mb_strlen($city) > 100
        ) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_CITY',
                'City cannot exceed 100 characters.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * State
     * -----------------------------------------------------
     */

    if (array_key_exists('state', $data)) {

        $state =
            $data['state'] === null
                ? null
                : trim((string) $data['state']);

        if ($state === '') {
            $state = null;
        }

        if (
            $state !== null &&
            mb_strlen($state) > 100
        ) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_STATE',
                'State cannot exceed 100 characters.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Status
     * -----------------------------------------------------
     */

    if (array_key_exists('status', $data)) {

        $status = trim(
            (string) $data['status']
        );

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


    /**
     * -----------------------------------------------------
     * Parent location
     * -----------------------------------------------------
     */

    if (array_key_exists('parent_location_id', $data)) {

        if (
            $data['parent_location_id'] === null ||
            $data['parent_location_id'] === ''
        ) {

            $parentLocationId = null;

        } else {

            $validatedParentId = filter_var(
                $data['parent_location_id'],
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1
                    ]
                ]
            );

            if ($validatedParentId === false) {

                $pdo->rollBack();

                api_error_response(
                    422,
                    'INVALID_PARENT_LOCATION',
                    'parent_location_id must be a valid positive integer or null.'
                );
            }

            $parentLocationId = (int) $validatedParentId;
        }
    }


    /**
     * -----------------------------------------------------
     * Prevent self-parent
     * -----------------------------------------------------
     */

    if (
        $parentLocationId !== null &&
        $parentLocationId === $warehouseId
    ) {

        $pdo->rollBack();

        api_error_response(
            422,
            'INVALID_PARENT_LOCATION',
            'A warehouse cannot be its own parent.'
        );
    }


    /**
     * -----------------------------------------------------
     * Validate parent belongs to same tenant
     * -----------------------------------------------------
     */

    if ($parentLocationId !== null) {

        $parentStmt = $pdo->prepare(
            '
            SELECT
                id,
                warehouse_code,
                name,
                status,
                parent_location_id
            FROM warehouses
            WHERE tenant_id = :tenant_id
              AND id = :parent_location_id
            LIMIT 1
            '
        );

        $parentStmt->execute([
            ':tenant_id' => $tenantId,
            ':parent_location_id' => $parentLocationId
        ]);

        $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);


        if (!$parent) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_PARENT_LOCATION',
                'The selected parent location does not exist for this company.'
            );
        }


        /**
         * -------------------------------------------------
         * Prevent hierarchy cycles
         * -------------------------------------------------
         *
         * Walk upward from the selected parent.
         * If the warehouse currently being updated is found
         * anywhere in the chain, the update would create a
         * cycle.
         */

        $visited = [];

        $currentParentId = $parentLocationId;

        while ($currentParentId !== null) {

            if (isset($visited[$currentParentId])) {

                $pdo->rollBack();

                api_error_response(
                    422,
                    'INVALID_PARENT_LOCATION',
                    'The warehouse hierarchy contains a cycle.'
                );
            }

            $visited[$currentParentId] = true;


            if ($currentParentId === $warehouseId) {

                $pdo->rollBack();

                api_error_response(
                    422,
                    'INVALID_PARENT_LOCATION',
                    'The selected parent location would create a hierarchy cycle.'
                );
            }


            $ancestorStmt = $pdo->prepare(
                '
                SELECT
                    parent_location_id
                FROM warehouses
                WHERE tenant_id = :tenant_id
                  AND id = :warehouse_id
                LIMIT 1
                '
            );

            $ancestorStmt->execute([
                ':tenant_id' => $tenantId,
                ':warehouse_id' => $currentParentId
            ]);

            $ancestor = $ancestorStmt->fetch(PDO::FETCH_ASSOC);

            if (!$ancestor) {
                break;
            }

            $currentParentId =
                $ancestor['parent_location_id'] !== null
                    ? (int) $ancestor['parent_location_id']
                    : null;
        }
    }


    /**
     * -----------------------------------------------------
     * Duplicate warehouse code
     * -----------------------------------------------------
     *
     * Exclude current warehouse.
     */

    $duplicateStmt = $pdo->prepare(
        '
        SELECT
            id
        FROM warehouses
        WHERE tenant_id = :tenant_id
          AND warehouse_code = :warehouse_code
          AND id <> :warehouse_id
        LIMIT 1
        '
    );

    $duplicateStmt->execute([
        ':tenant_id' => $tenantId,
        ':warehouse_code' => $warehouseCode,
        ':warehouse_id' => $warehouseId
    ]);

    $duplicateWarehouse =
        $duplicateStmt->fetch(PDO::FETCH_ASSOC);


    if ($duplicateWarehouse) {

        $pdo->rollBack();

        api_conflict(
            'A warehouse with this code already exists.'
        );
    }


    /**
     * -----------------------------------------------------
     * Update warehouse
     * -----------------------------------------------------
     */

    $updateStmt = $pdo->prepare(
        '
        UPDATE warehouses
        SET
            warehouse_code = :warehouse_code,
            name = :name,
            address = :address,
            city = :city,
            state = :state,
            status = :status,
            parent_location_id = :parent_location_id,
            updated_at = CURRENT_TIMESTAMP
        WHERE tenant_id = :tenant_id
          AND id = :warehouse_id
        '
    );

    $updateStmt->execute([
        ':warehouse_code' => $warehouseCode,
        ':name' => $name,
        ':address' => $address,
        ':city' => $city,
        ':state' => $state,
        ':status' => $status,
        ':parent_location_id' => $parentLocationId,
        ':tenant_id' => $tenantId,
        ':warehouse_id' => $warehouseId
    ]);


    /**
     * -----------------------------------------------------
     * Load AFTER state
     * -----------------------------------------------------
     */

    $afterStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            warehouse_code,
            name,
            address,
            city,
            state,
            status,
            parent_location_id,
            created_at,
            updated_at
        FROM warehouses
        WHERE tenant_id = :tenant_id
          AND id = :warehouse_id
        LIMIT 1
        '
    );

    $afterStmt->execute([
        ':tenant_id' => $tenantId,
        ':warehouse_id' => $warehouseId
    ]);

    $after = $afterStmt->fetch(PDO::FETCH_ASSOC);


    if (!$after) {

        throw new RuntimeException(
            'Updated warehouse could not be loaded.'
        );
    }


    /**
     * -----------------------------------------------------
     * Audit
     * -----------------------------------------------------
     */

    $auditId = audit_event(
        'warehouse.updated',
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
     * Request ID
     * -----------------------------------------------------
     */

    $requestId = audit_get_request_id();

    header(
        'X-Request-ID: ' . $requestId
    );


    /**
     * -----------------------------------------------------
     * Success response
     * -----------------------------------------------------
     */

    http_response_code(200);

    echo json_encode(
        [
            'success' => true,

            'message' =>
                'Warehouse updated successfully.',

            'data' => [
                'warehouse' => [
                    'id' => (int) $after['id'],

                    'warehouse_code' =>
                        $after['warehouse_code'],

                    'name' =>
                        $after['name'],

                    'address' =>
                        $after['address'],

                    'city' =>
                        $after['city'],

                    'state' =>
                        $after['state'],

                    'status' =>
                        $after['status'],

                    'parent_location_id' =>
                        $after['parent_location_id'] !== null
                            ? (int) $after['parent_location_id']
                            : null,

                    'created_at' =>
                        $after['created_at'],

                    'updated_at' =>
                        $after['updated_at']
                ],

                'audit_id' => $auditId,

                'request_id' => $requestId
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
     * Database UNIQUE constraint remains the final
     * authority against race conditions.
     */

    if (
        isset($e->errorInfo[1]) &&
        (int) $e->errorInfo[1] === 1062
    ) {

        api_conflict(
            'A warehouse with this code already exists.'
        );
    }


    error_log(
        'Warehouse update database error: ' .
        $e->getMessage()
    );


    api_database_error(
        'Unable to update the warehouse.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    error_log(
        'Warehouse update error: ' .
        $e->getMessage()
    );


    api_server_error(
        'Unable to update the warehouse.'
    );
}