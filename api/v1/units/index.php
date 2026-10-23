<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * GET  /api/v1/units/
 * POST /api/v1/units/
 *
 * Responsibility:
 * - List tenant-scoped units
 * - Create a unit for the authenticated tenant
 * - Enforce RBAC
 * - Enforce CSRF on mutation
 * - Prevent duplicate normalized unit names
 * - Record audit event on creation
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/csrf.php';

$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'POST'], true)) {
    error_response(
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
    requirePermission('units.view');
} else {
    requirePermission('units.manage');
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
| GET - List Units
|--------------------------------------------------------------------------
*/

if ($method === 'GET') {

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

    $perPage = min((int) $perPage, 100);
    $page = (int) $page;

    $offset = ($page - 1) * $perPage;

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

    $status = null;

    if (isset($_GET['status']) && $_GET['status'] !== '') {
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

    try {

        /*
         * Build tenant-scoped WHERE conditions.
         */
        $where = [
            'u.tenant_id = :tenant_id'
        ];

        $params = [
            ':tenant_id' => $tenantId
        ];

        if ($search !== '') {
            $where[] = '
                (
                    u.name LIKE :search_name
                    OR u.name_normalized LIKE :search_normalized
                )
            ';

            $searchValue = '%' . $search . '%';

            $params[':search_name'] = $searchValue;
            $params[':search_normalized'] =
                '%' . mb_strtolower($search, 'UTF-8') . '%';
        }

        if ($status !== null) {
            $where[] = 'u.status = :status';
            $params[':status'] = $status;
        }

        $whereSql = implode(
            ' AND ',
            $where
        );

        /*
         * Total count.
         */
        $countStmt = $pdo->prepare(
            "
            SELECT COUNT(*)
            FROM units u
            WHERE {$whereSql}
            "
        );

        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

        /*
         * Unit list.
         *
         * Explicit columns only.
         */
        $listStmt = $pdo->prepare(
            "
            SELECT
                u.id,
                u.name,
                u.symbol,
                u.status,
                u.created_at,
                u.updated_at
            FROM units u
            WHERE {$whereSql}
            ORDER BY u.id DESC
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

        while ($row = $listStmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'symbol' => $row['symbol'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
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
                    'items' => $items,
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'total_pages' =>
                            $total > 0
                                ? (int) ceil($total / $perPage)
                                : 0
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
            'Unit list database error: ' .
            $e->getMessage()
        );

        api_database_error(
            'Unable to load units.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| POST - Create Unit
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Allowed Fields
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Validate Name
|--------------------------------------------------------------------------
*/

if (
    !array_key_exists('name', $data) ||
    !is_string($data['name'])
) {
    api_error_response(
        422,
        'INVALID_NAME',
        'Unit name is required.'
    );
}

$name = trim(
    $data['name']
);

if ($name === '') {
    api_error_response(
        422,
        'INVALID_NAME',
        'Unit name is required.'
    );
}

if (mb_strlen($name) > 150) {
    api_error_response(
        422,
        'INVALID_NAME',
        'Unit name cannot exceed 150 characters.'
    );
}

$normalizedName = preg_replace('/\\s+/u', ' ', $name);
if ($normalizedName === null) {
    api_error_response(
        422,
        'INVALID_NAME',
        'Unit name contains invalid characters.'
    );
}

$nameNormalized = mb_strtolower(
    trim($normalizedName),
    'UTF-8'
);

/*
|--------------------------------------------------------------------------
| Validate Symbol / Abbreviation
|--------------------------------------------------------------------------
*/

$symbol = null;

if (array_key_exists('symbol', $data)) {
    if ($data['symbol'] !== null && !is_string($data['symbol'])) {
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

    if ($symbol !== null && mb_strlen($symbol, 'UTF-8') > 20) {
        api_error_response(
            422,
            'INVALID_SYMBOL',
            'Unit abbreviation cannot exceed 20 characters.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Validate Status
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

try {

    $pdo->beginTransaction();

    /*
     * Duplicate check.
     */
    $duplicateStmt = $pdo->prepare(
        '
        SELECT
            id
        FROM units
        WHERE tenant_id = :tenant_id
          AND name_normalized = :name_normalized
        LIMIT 1
        '
    );

    $duplicateStmt->execute([
        ':tenant_id' => $tenantId,
        ':name_normalized' => $nameNormalized
    ]);

    $duplicate = $duplicateStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if ($duplicate) {
        $pdo->rollBack();

        api_conflict(
            'A unit with this name already exists.'
        );
    }

    /*
     * Insert.
     */
    $insertStmt = $pdo->prepare(
        '
        INSERT INTO units (
            tenant_id,
            name,
            name_normalized,
            symbol,
            status,
            created_at,
            updated_at
        )
        VALUES (
            :tenant_id,
            :name,
            :name_normalized,
            :symbol,
            :status,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        '
    );

    $insertStmt->execute([
        ':tenant_id' => $tenantId,
        ':name' => $name,
        ':name_normalized' => $nameNormalized,
        ':symbol' => $symbol,
        ':status' => $status
    ]);

    $unitId = (int) $pdo->lastInsertId();

    /*
     * Load created unit.
     */
    $unitStmt = $pdo->prepare(
        '
        SELECT
            id,
            name,
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

    $unitStmt->execute([
        ':tenant_id' => $tenantId,
        ':unit_id' => $unitId
    ]);

    $unit = $unitStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$unit) {
        throw new RuntimeException(
            'Created unit could not be loaded.'
        );
    }

    /*
     * Audit.
     */
    $auditId = audit_event(
        'unit.created',
        'unit',
        (string) $unitId,
        null,
        $unit
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
            'message' => 'Unit created successfully.',
            'data' => [
                'unit' => [
                    'id' => (int) $unit['id'],
                    'name' => $unit['name'],
                    'symbol' => $unit['symbol'],
                    'status' => $unit['status'],
                    'created_at' => $unit['created_at'],
                    'updated_at' => $unit['updated_at']
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
     * MariaDB duplicate-key protection.
     * This also protects against concurrent create requests
     * racing past the application-level duplicate check.
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
        'Unit create database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to create the unit.'
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Unit create error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to create the unit.'
    );
}