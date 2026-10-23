<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * GET  /api/v1/categories
 * POST /api/v1/categories
 *
 * Responsibility:
 * - List categories for the authenticated tenant
 * - Create a category for the authenticated tenant
 * - Enforce categories.view / categories.manage permissions
 * - Enforce CSRF protection for mutations
 * - Validate category input
 * - Enforce tenant-scoped parent category
 * - Normalize category name for uniqueness
 * - Record category.created audit event
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

$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'POST'], true)) {
    error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only GET and POST requests are allowed.'
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

if ($method === 'GET') {
    requirePermission('categories.view');
} else {
    requirePermission('categories.manage');
}


/**
 * ---------------------------------------------------------
 * CSRF
 *
 * Only required for state-changing POST request.
 * GET requests do not require CSRF.
 * ---------------------------------------------------------
 */

if ($method === 'POST' && !csrf_is_valid()) {
    api_error_response(
        403,
        'CSRF_TOKEN_INVALID',
        'Invalid or missing CSRF token.'
    );
}


/**
 * =========================================================
 * GET - Category List
 * =========================================================
 */

if ($method === 'GET') {

    /**
     * -----------------------------------------------------
     * Pagination
     * -----------------------------------------------------
     */

    $page = isset($_GET['page'])
        ? (int) $_GET['page']
        : 1;

    $perPage = isset($_GET['per_page'])
        ? (int) $_GET['per_page']
        : 25;

    if ($page < 1) {
        api_error_response(
            422,
            'INVALID_PAGE',
            'Page must be at least 1.'
        );
    }

    if ($perPage < 1) {
        api_error_response(
            422,
            'INVALID_PER_PAGE',
            'Per page must be at least 1.'
        );
    }

    /*
     * Protect the endpoint from unnecessarily large result sets.
     */
    $perPage = min($perPage, 100);


    /**
     * -----------------------------------------------------
     * Search
     * -----------------------------------------------------
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


    /**
     * -----------------------------------------------------
     * Status filter
     * -----------------------------------------------------
     */

    $status = trim(
        (string) ($_GET['status'] ?? '')
    );

    if (
        $status !== '' &&
        !in_array($status, ['active', 'inactive'], true)
    ) {
        api_error_response(
            422,
            'INVALID_STATUS',
            'Status must be active or inactive.'
        );
    }


    /**
     * -----------------------------------------------------
     * Build tenant-safe WHERE clause
     * -----------------------------------------------------
     */

    $where = [
        'c.tenant_id = :tenant_id'
    ];

    $params = [
        ':tenant_id' => $tenantId
    ];


    /**
     * -----------------------------------------------------
     * Search filter
     *
     * Search category name and parent category name.
     * -----------------------------------------------------
     */

    if ($search !== '') {

        $where[] = '(
            c.name LIKE :search_name
            OR parent.name LIKE :search_parent
        )';

        $searchLike = '%' . $search . '%';

        /*
         * Separate placeholders are intentional.
         * Avoid reusing the same named PDO placeholder.
         */
        $params[':search_name'] = $searchLike;
        $params[':search_parent'] = $searchLike;
    }


    /**
     * -----------------------------------------------------
     * Status filter
     * -----------------------------------------------------
     */

    if ($status !== '') {

        $where[] = 'c.status = :status';

        $params[':status'] = $status;
    }


    $whereSql = implode(' AND ', $where);


    /**
     * -----------------------------------------------------
     * Count total records
     * -----------------------------------------------------
     */

    $countSql = "
        SELECT COUNT(*)
        FROM categories c

        LEFT JOIN categories parent
            ON parent.tenant_id = c.tenant_id
            AND parent.id = c.parent_id

        WHERE {$whereSql}
    ";

    $countStmt = $pdo->prepare($countSql);

    $countStmt->execute($params);

    $total = (int) $countStmt->fetchColumn();


    /**
     * -----------------------------------------------------
     * Calculate offset
     * -----------------------------------------------------
     */

    $offset = ($page - 1) * $perPage;


    /**
     * -----------------------------------------------------
     * Fetch categories
     *
     * Explicit columns only.
     * No SELECT *.
     * -----------------------------------------------------
     */

    $listSql = "
        SELECT
            c.id,
            c.name,
            c.parent_id,
            parent.name AS parent_name,
            c.status,
            c.created_at,
            c.updated_at

        FROM categories c

        LEFT JOIN categories parent
            ON parent.tenant_id = c.tenant_id
            AND parent.id = c.parent_id

        WHERE {$whereSql}

        ORDER BY c.id DESC

        LIMIT :limit
        OFFSET :offset
    ";

    $listStmt = $pdo->prepare($listSql);


    /**
     * -----------------------------------------------------
     * Bind dynamic WHERE parameters
     * -----------------------------------------------------
     */

    foreach ($params as $key => $value) {
        $listStmt->bindValue($key, $value);
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

    $items = $listStmt->fetchAll(PDO::FETCH_ASSOC);


    /**
     * -----------------------------------------------------
     * Pagination metadata
     * -----------------------------------------------------
     */

    $totalPages = $total > 0
        ? (int) ceil($total / $perPage)
        : 0;


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
}


/**
 * =========================================================
 * POST - Create Category
 * =========================================================
 */


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
    'name',
    'parent_id',
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


/**
 * ---------------------------------------------------------
 * Read fields
 * ---------------------------------------------------------
 */

$name = trim(
    (string) ($data['name'] ?? '')
);


$parentId = null;

if (
    array_key_exists('parent_id', $data) &&
    $data['parent_id'] !== null &&
    $data['parent_id'] !== ''
) {

    $parentId = filter_var(
        $data['parent_id'],
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($parentId === false) {

        api_error_response(
            422,
            'INVALID_PARENT_ID',
            'parent_id must be a valid positive integer.'
        );
    }

    $parentId = (int) $parentId;
}


$status = array_key_exists('status', $data)
    ? trim((string) $data['status'])
    : 'active';


/**
 * ---------------------------------------------------------
 * Validation
 * ---------------------------------------------------------
 */

$errors = [];


if ($name === '') {

    $errors['name'] = 'Category name is required.';

} elseif (mb_strlen($name) > 150) {

    $errors['name'] =
        'Category name cannot exceed 150 characters.';
}


$allowedStatuses = [
    'active',
    'inactive'
];


if (!in_array($status, $allowedStatuses, true)) {

    $errors['status'] =
        'Status must be active or inactive.';
}


if (!empty($errors)) {

    api_error_response(
        422,
        'VALIDATION_ERROR',
        'One or more fields are invalid.',
        [
            'fields' => $errors
        ]
    );
}


/**
 * ---------------------------------------------------------
 * Normalize name
 * ---------------------------------------------------------
 */

$nameNormalized = mb_strtolower(
    trim($name),
    'UTF-8'
);


/**
 * =========================================================
 * Database transaction
 * =========================================================
 */

try {

    $pdo->beginTransaction();


    /**
     * -----------------------------------------------------
     * Validate parent category
     * -----------------------------------------------------
     */

    if ($parentId !== null) {

        $parentStmt = $pdo->prepare(
            '
            SELECT
                id,
                name,
                status
            FROM categories
            WHERE tenant_id = :tenant_id
              AND id = :parent_id
            LIMIT 1
            '
        );

        $parentStmt->execute([
            ':tenant_id' => $tenantId,
            ':parent_id' => $parentId
        ]);

        $parent = $parentStmt->fetch();


        if (!$parent) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_PARENT_CATEGORY',
                'The selected parent category does not exist for this company.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Duplicate name check
     * -----------------------------------------------------
     */

    $duplicateStmt = $pdo->prepare(
        '
        SELECT
            id
        FROM categories
        WHERE tenant_id = :tenant_id
          AND name_normalized = :name_normalized
        LIMIT 1
        '
    );

    $duplicateStmt->execute([
        ':tenant_id' => $tenantId,
        ':name_normalized' => $nameNormalized
    ]);

    $existingCategory = $duplicateStmt->fetch();


    if ($existingCategory) {

        $pdo->rollBack();

        api_conflict(
            'A category with this name already exists.'
        );
    }


    /**
     * -----------------------------------------------------
     * Insert category
     * -----------------------------------------------------
     */

    $insertStmt = $pdo->prepare(
        '
        INSERT INTO categories (
            tenant_id,
            name,
            name_normalized,
            parent_id,
            status
        )
        VALUES (
            :tenant_id,
            :name,
            :name_normalized,
            :parent_id,
            :status
        )
        '
    );


    $insertStmt->execute([
        ':tenant_id' => $tenantId,
        ':name' => $name,
        ':name_normalized' => $nameNormalized,
        ':parent_id' => $parentId,
        ':status' => $status
    ]);


    $categoryId = (int) $pdo->lastInsertId();


    if ($categoryId <= 0) {

        throw new RuntimeException(
            'Category creation failed.'
        );
    }


    /**
     * -----------------------------------------------------
     * Load created category
     * -----------------------------------------------------
     */

    $afterStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            name,
            parent_id,
            status,
            created_at,
            updated_at
        FROM categories
        WHERE tenant_id = :tenant_id
          AND id = :category_id
        LIMIT 1
        '
    );


    $afterStmt->execute([
        ':tenant_id' => $tenantId,
        ':category_id' => $categoryId
    ]);


    $after = $afterStmt->fetch();


    if (!$after) {

        throw new RuntimeException(
            'Created category could not be loaded.'
        );
    }


    /**
     * -----------------------------------------------------
     * Audit
     * -----------------------------------------------------
     */

    $auditId = audit_event(
        'category.created',
        'category',
        (string) $categoryId,
        null,
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

    http_response_code(201);

    echo json_encode(
        [
            'success' => true,

            'message' =>
                'Category created successfully.',

            'data' => [
                'category' => [
                    'id' => (int) $after['id'],

                    'name' => $after['name'],

                    'parent_id' =>
                        $after['parent_id'] !== null
                            ? (int) $after['parent_id']
                            : null,

                    'status' => $after['status'],

                    'created_at' => $after['created_at'],

                    'updated_at' => $after['updated_at']
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
     * Database UNIQUE constraint remains
     * the final authority against race conditions.
     */

    if (
        isset($e->errorInfo[1]) &&
        (int) $e->errorInfo[1] === 1062
    ) {

        api_conflict(
            'A category with this name already exists.'
        );
    }


    error_log(
        'Category creation database error: ' .
        $e->getMessage()
    );


    api_database_error(
        'Unable to create the category.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    error_log(
        'Category creation error: ' .
        $e->getMessage()
    );


    api_server_error(
        'Unable to create the category.'
    );
}