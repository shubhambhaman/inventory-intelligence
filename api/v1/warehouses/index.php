<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * GET  /api/v1/warehouses/
 * POST /api/v1/warehouses/
 *
 * Responsibility:
 * - List tenant-scoped warehouses
 * - Create warehouse for authenticated tenant
 * - Enforce RBAC
 * - Enforce CSRF on mutation
 * - Validate warehouse input
 * - Prevent duplicate warehouse codes within tenant
 * - Validate tenant-scoped parent location
 * - Record warehouse.created audit event
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/csrf.php';

/*
|--------------------------------------------------------------------------
| HTTP Method
|--------------------------------------------------------------------------
*/

$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'POST'], true)) {

    header('Allow: GET, POST');

    api_error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only GET and POST requests are allowed.'
    );
}

/*
|--------------------------------------------------------------------------
| Authentication / Tenant Context
|--------------------------------------------------------------------------
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
|--------------------------------------------------------------------------
| Permission
|--------------------------------------------------------------------------
*/

if ($method === 'GET') {
    requirePermission('warehouses.view');
} else {
    requirePermission('warehouses.create');
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if ($method === 'POST' && !csrf_is_valid()) {

    api_error_response(
        403,
        'CSRF_TOKEN_INVALID',
        'Invalid or missing CSRF token.'
    );
}

/*
|--------------------------------------------------------------------------
| GET - List Warehouses
|--------------------------------------------------------------------------
*/

if ($method === 'GET') {

    /*
     * Pagination
     */
    $page = filter_var(
        $_GET['page'] ?? 1,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($page === false) {

        api_error_response(
            422,
            'INVALID_PAGE',
            'Page must be a positive integer.'
        );
    }

    $perPage = filter_var(
        $_GET['per_page'] ?? 25,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($perPage === false) {

        api_error_response(
            422,
            'INVALID_PER_PAGE',
            'per_page must be a positive integer.'
        );
    }

    $page = (int) $page;
    $perPage = min((int) $perPage, 100);

    $offset = ($page - 1) * $perPage;

    /*
     * Search
     */
    $search = trim(
        (string) ($_GET['search'] ?? '')
    );

    if (mb_strlen($search) > 150) {

        api_error_response(
            422,
            'INVALID_SEARCH',
            'Search cannot exceed 150 characters.'
        );
    }

    /*
     * Status
     */
    $status = null;

    if (
        isset($_GET['status']) &&
        $_GET['status'] !== ''
    ) {

        $status = trim(
            (string) $_GET['status']
        );

        if (!in_array(
            $status,
            ['active', 'inactive'],
            true
        )) {

            api_error_response(
                422,
                'INVALID_STATUS',
                'Status must be active or inactive.'
            );
        }
    }

    /*
     * Parent location filter
     */
    $parentLocationId = null;

    if (
        isset($_GET['parent_location_id']) &&
        $_GET['parent_location_id'] !== ''
    ) {

        $parentLocationId = filter_var(
            $_GET['parent_location_id'],
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        if ($parentLocationId === false) {

            api_error_response(
                422,
                'INVALID_PARENT_LOCATION',
                'parent_location_id must be a valid positive integer.'
            );
        }

        $parentLocationId = (int) $parentLocationId;
    }

    try {

        /*
         * Tenant-scoped WHERE
         */
        $where = [
            'w.tenant_id = :tenant_id'
        ];

        $params = [
            ':tenant_id' => $tenantId
        ];

        /*
         * Search warehouse code/name/location
         */
        if ($search !== '') {

            $where[] = '
                (
                    w.warehouse_code LIKE :search_code
                    OR w.name LIKE :search_name
                    OR w.city LIKE :search_city
                    OR w.state LIKE :search_state
                )
            ';

            $searchValue = '%' . $search . '%';

            $params[':search_code'] = $searchValue;
            $params[':search_name'] = $searchValue;
            $params[':search_city'] = $searchValue;
            $params[':search_state'] = $searchValue;
        }

        if ($status !== null) {

            $where[] = 'w.status = :status';

            $params[':status'] = $status;
        }

        if ($parentLocationId !== null) {

            $where[] = 'w.parent_location_id = :parent_location_id';

            $params[':parent_location_id'] = $parentLocationId;
        }

        $whereSql = implode(
            ' AND ',
            $where
        );

        /*
         * Total count
         */
        $countStmt = $pdo->prepare(
            "
            SELECT COUNT(*)
            FROM warehouses w
            WHERE {$whereSql}
            "
        );

        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

        /*
         * Warehouse list
         *
         * Explicit columns only.
         */
        $listStmt = $pdo->prepare(
            "
            SELECT
                w.id,
                w.warehouse_code,
                w.name,
                w.address,
                w.city,
                w.state,
                w.status,
                w.parent_location_id,
                p.name AS parent_location_name,
                w.created_at,
                w.updated_at
            FROM warehouses w
            LEFT JOIN warehouses p
                ON p.tenant_id = w.tenant_id
               AND p.id = w.parent_location_id
            WHERE {$whereSql}
            ORDER BY w.id DESC
            LIMIT :limit
            OFFSET :offset
            "
        );

        foreach ($params as $key => $value) {

            $listStmt->bindValue(
                $key,
                $value,
                PDO::PARAM_STR
            );
        }

        $listStmt->bindValue(
            ':limit',
            $perPage,
            PDO::PARAM_INT
        );

        $listStmt->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $listStmt->execute();

        $items = [];

        while (
            $row = $listStmt->fetch(PDO::FETCH_ASSOC)
        ) {

            $items[] = [
                'id' => (int) $row['id'],
                'warehouse_code' => $row['warehouse_code'],
                'name' => $row['name'],
                'address' => $row['address'],
                'city' => $row['city'],
                'state' => $row['state'],
                'status' => $row['status'],
                'parent_location' => [
                    'id' => $row['parent_location_id'] !== null
                        ? (int) $row['parent_location_id']
                        : null,
                    'name' => $row['parent_location_name']
                ],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        $totalPages = $total > 0
            ? (int) ceil($total / $perPage)
            : 0;

        $requestId = audit_get_request_id();

        header(
            'X-Request-ID: ' . $requestId
        );

        http_response_code(200);

        echo json_encode(
            [
                'success' => true,
                'data' => [
                    'items' => $items,
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'total_pages' => $totalPages
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
            'Warehouse list database error: ' .
            $e->getMessage()
        );

        api_database_error(
            'Unable to load warehouses.'
        );

    } catch (Throwable $e) {

        error_log(
            'Warehouse list error: ' .
            $e->getMessage()
        );

        api_server_error(
            'Unable to load warehouses.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| POST - Create Warehouse
|--------------------------------------------------------------------------
*/

$rawBody = file_get_contents('php://input');

if (
    $rawBody === false ||
    trim($rawBody) === ''
) {

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

/*
|--------------------------------------------------------------------------
| Allowed Fields
|--------------------------------------------------------------------------
|
| tenant_id is intentionally not accepted.
| Tenant always comes from authenticated session.
|
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

/*
|--------------------------------------------------------------------------
| Required fields
|--------------------------------------------------------------------------
*/

$warehouseCode = null;

if (
    !array_key_exists(
        'warehouse_code',
        $data
    ) ||
    !is_string($data['warehouse_code'])
) {

    api_error_response(
        422,
        'INVALID_WAREHOUSE_CODE',
        'Warehouse code is required.'
    );
}

$warehouseCode = trim(
    $data['warehouse_code']
);

if ($warehouseCode === '') {

    api_error_response(
        422,
        'INVALID_WAREHOUSE_CODE',
        'Warehouse code is required.'
    );
}

if (mb_strlen($warehouseCode) > 100) {

    api_error_response(
        422,
        'INVALID_WAREHOUSE_CODE',
        'Warehouse code cannot exceed 100 characters.'
    );
}

$name = null;

if (
    !array_key_exists('name', $data) ||
    !is_string($data['name'])
) {

    api_error_response(
        422,
        'INVALID_NAME',
        'Warehouse name is required.'
    );
}

$name = trim(
    $data['name']
);

if ($name === '') {

    api_error_response(
        422,
        'INVALID_NAME',
        'Warehouse name is required.'
    );
}

if (mb_strlen($name) > 150) {

    api_error_response(
        422,
        'INVALID_NAME',
        'Warehouse name cannot exceed 150 characters.'
    );
}

/*
|--------------------------------------------------------------------------
| Optional metadata
|--------------------------------------------------------------------------
*/

$address = null;

if (array_key_exists('address', $data)) {

    if (
        $data['address'] !== null &&
        !is_string($data['address'])
    ) {

        api_error_response(
            422,
            'INVALID_ADDRESS',
            'Address must be a string or null.'
        );
    }

    $address = $data['address'] !== null
        ? trim($data['address'])
        : null;

    if (
        $address !== null &&
        $address === ''
    ) {
        $address = null;
    }

    if (
        $address !== null &&
        mb_strlen($address) > 255
    ) {

        api_error_response(
            422,
            'INVALID_ADDRESS',
            'Address cannot exceed 255 characters.'
        );
    }
}

$city = null;

if (array_key_exists('city', $data)) {

    if (
        $data['city'] !== null &&
        !is_string($data['city'])
    ) {

        api_error_response(
            422,
            'INVALID_CITY',
            'City must be a string or null.'
        );
    }

    $city = $data['city'] !== null
        ? trim($data['city'])
        : null;

    if ($city === '') {
        $city = null;
    }

    if (
        $city !== null &&
        mb_strlen($city) > 100
    ) {

        api_error_response(
            422,
            'INVALID_CITY',
            'City cannot exceed 100 characters.'
        );
    }
}

$state = null;

if (array_key_exists('state', $data)) {

    if (
        $data['state'] !== null &&
        !is_string($data['state'])
    ) {

        api_error_response(
            422,
            'INVALID_STATE',
            'State must be a string or null.'
        );
    }

    $state = $data['state'] !== null
        ? trim($data['state'])
        : null;

    if ($state === '') {
        $state = null;
    }

    if (
        $state !== null &&
        mb_strlen($state) > 100
    ) {

        api_error_response(
            422,
            'INVALID_STATE',
            'State cannot exceed 100 characters.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

$status = 'active';

if (array_key_exists('status', $data)) {

    if (!is_string($data['status'])) {

        api_error_response(
            422,
            'INVALID_STATUS',
            'Status must be active or inactive.'
        );
    }

    $status = trim(
        $data['status']
    );

    if (!in_array(
        $status,
        ['active', 'inactive'],
        true
    )) {

        api_error_response(
            422,
            'INVALID_STATUS',
            'Status must be active or inactive.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Parent Location
|--------------------------------------------------------------------------
*/

$parentLocationId = null;

if (
    array_key_exists(
        'parent_location_id',
        $data
    )
) {

    if ($data['parent_location_id'] === null) {

        $parentLocationId = null;

    } else {

        $parentLocationId = filter_var(
            $data['parent_location_id'],
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        if ($parentLocationId === false) {

            api_error_response(
                422,
                'INVALID_PARENT_LOCATION',
                'parent_location_id must be a valid positive integer or null.'
            );
        }

        $parentLocationId = (int) $parentLocationId;
    }
}

/*
|--------------------------------------------------------------------------
| Transaction
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();

    /*
     * Duplicate warehouse code.
     */
    $duplicateStmt = $pdo->prepare(
        '
        SELECT
            id
        FROM warehouses
        WHERE tenant_id = :tenant_id
          AND warehouse_code = :warehouse_code
        LIMIT 1
        '
    );

    $duplicateStmt->execute([
        ':tenant_id' => $tenantId,
        ':warehouse_code' => $warehouseCode
    ]);

    $duplicate = $duplicateStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if ($duplicate) {

        $pdo->rollBack();

        api_conflict(
            'A warehouse with this code already exists.'
        );
    }

    /*
     * Validate parent location.
     *
     * Tenant ID is always included.
     */
    if ($parentLocationId !== null) {

        $parentStmt = $pdo->prepare(
            '
            SELECT
                id,
                name,
                status
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

        $parent = $parentStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$parent) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_PARENT_LOCATION',
                'The selected parent location does not exist for this company.'
            );
        }
    }

    /*
     * Insert warehouse.
     */
    $insertStmt = $pdo->prepare(
        '
        INSERT INTO warehouses (
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
        )
        VALUES (
            :tenant_id,
            :warehouse_code,
            :name,
            :address,
            :city,
            :state,
            :status,
            :parent_location_id,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        '
    );

    $insertStmt->execute([
        ':tenant_id' => $tenantId,
        ':warehouse_code' => $warehouseCode,
        ':name' => $name,
        ':address' => $address,
        ':city' => $city,
        ':state' => $state,
        ':status' => $status,
        ':parent_location_id' => $parentLocationId
    ]);

    $warehouseId = (int) $pdo->lastInsertId();

    if ($warehouseId <= 0) {

        throw new RuntimeException(
            'Warehouse creation failed.'
        );
    }

    /*
     * Load created warehouse.
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

    $warehouse = $warehouseStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$warehouse) {

        throw new RuntimeException(
            'Created warehouse could not be loaded.'
        );
    }

    /*
     * Audit.
     */
    $auditId = audit_event(
        'warehouse.created',
        'warehouse',
        (string) $warehouseId,
        null,
        $warehouse
    );

    $pdo->commit();

    $requestId = audit_get_request_id();

    header(
        'X-Request-ID: ' . $requestId
    );

    http_response_code(201);

    echo json_encode(
        [
            'success' => true,
            'message' => 'Warehouse created successfully.',
            'data' => [
                'warehouse' => [
                    'id' => (int) $warehouse['id'],
                    'warehouse_code' =>
                        $warehouse['warehouse_code'],
                    'name' => $warehouse['name'],
                    'address' => $warehouse['address'],
                    'city' => $warehouse['city'],
                    'state' => $warehouse['state'],
                    'status' => $warehouse['status'],
                    'parent_location_id' =>
                        $warehouse['parent_location_id'] !== null
                            ? (int) $warehouse['parent_location_id']
                            : null,
                    'created_at' => $warehouse['created_at'],
                    'updated_at' => $warehouse['updated_at']
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

    /*
     * Protect against concurrent duplicate creation.
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
        'Warehouse create database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to create the warehouse.'
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Warehouse create error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to create the warehouse.'
    );
}