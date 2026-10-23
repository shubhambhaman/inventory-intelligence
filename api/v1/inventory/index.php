<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * GET /api/v1/inventory
 *
 * Read-only, tenant-scoped stock balance projection derived from posted
 * stock movements. Reservation quantities are intentionally not included.
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../src/Inventory/StockBalanceService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    api_error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only GET requests are allowed.'
    );
}

if (
    empty($_SESSION['authenticated'])
    || empty($_SESSION['user_id'])
    || empty($_SESSION['tenant_id'])
) {
    api_unauthenticated();
}

$userId = filter_var(
    $_SESSION['user_id'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$tenantId = filter_var(
    $_SESSION['tenant_id'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($userId === false || $tenantId === false) {
    api_error_response(
        401,
        'INVALID_SESSION',
        'Your session is invalid. Please sign in again.'
    );
}

try {
    $identityStmt = $pdo->prepare(
        'SELECT
            u.tenant_id,
            u.status AS user_status,
            t.status AS tenant_status
         FROM users u
         INNER JOIN tenants t ON t.id = u.tenant_id
         WHERE u.id = :user_id
         LIMIT 1'
    );

    $identityStmt->execute([
        ':user_id' => (int) $userId,
    ]);

    $identity = $identityStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log(
        'Inventory tenant verification failed: ' . $e->getMessage()
    );

    api_server_error('Unable to verify your account context.');
}

if (
    !$identity
    || $identity['user_status'] !== 'active'
    || $identity['tenant_status'] !== 'active'
) {
    api_error_response(
        401,
        'INVALID_SESSION',
        'Your account is inactive or unavailable.'
    );
}

if ((int) $identity['tenant_id'] !== (int) $tenantId) {
    api_error_response(
        403,
        'TENANT_CONTEXT_MISMATCH',
        'Your session tenant does not match your account.'
    );
}

// Use only the tenant ID verified against the database.
$tenantId = (int) $identity['tenant_id'];

requirePermission('stock.view');

$page = 1;
$perPage = 25;

if (array_key_exists('page', $_GET)) {
    $rawPage = $_GET['page'];

    if (
        !is_string($rawPage)
        || !ctype_digit($rawPage)
        || ($validatedPage = filter_var(
            $rawPage,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )) === false
    ) {
        api_error_response(
            422,
            'INVALID_PAGINATION',
            'page must be a positive integer.'
        );
    }

    $page = (int) $validatedPage;
}

if (array_key_exists('per_page', $_GET)) {
    $rawPerPage = $_GET['per_page'];

    if (
        !is_string($rawPerPage)
        || !ctype_digit($rawPerPage)
        || ($validatedPerPage = filter_var(
            $rawPerPage,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )) === false
    ) {
        api_error_response(
            422,
            'INVALID_PAGINATION',
            'per_page must be a positive integer.'
        );
    }

    $perPage = (int) $validatedPerPage;
}

if ($perPage > 100) {
    api_error_response(
        422,
        'INVALID_PAGINATION',
        'per_page cannot exceed 100.'
    );
}

if ($page > intdiv(PHP_INT_MAX, $perPage)) {
    api_error_response(
        422,
        'INVALID_PAGINATION',
        'page is too large.'
    );
}

$productId = null;
if (array_key_exists('product_id', $_GET)) {
    $rawProductId = $_GET['product_id'];
    $validatedProductId = is_string($rawProductId)
        ? filter_var(
            $rawProductId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )
        : false;

    if ($validatedProductId === false) {
        api_error_response(
            422,
            'INVALID_FILTER',
            'product_id must be a positive integer.'
        );
    }

    $productId = (int) $validatedProductId;
}

$warehouseId = null;
if (array_key_exists('warehouse_id', $_GET)) {
    $rawWarehouseId = $_GET['warehouse_id'];
    $validatedWarehouseId = is_string($rawWarehouseId)
        ? filter_var(
            $rawWarehouseId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )
        : false;

    if ($validatedWarehouseId === false) {
        api_error_response(
            422,
            'INVALID_FILTER',
            'warehouse_id must be a positive integer.'
        );
    }

    $warehouseId = (int) $validatedWarehouseId;
}

$search = '';
if (array_key_exists('search', $_GET)) {
    if (!is_string($_GET['search'])) {
        api_error_response(
            422,
            'INVALID_FILTER',
            'search must be a string.'
        );
    }

    $search = trim($_GET['search']);

    if (strlen($search) > 100) {
        api_error_response(
            422,
            'INVALID_FILTER',
            'search cannot exceed 100 characters.'
        );
    }
}

$categoryId = null;
if (array_key_exists('category_id', $_GET)) {
    $rawCategoryId = $_GET['category_id'];
    $validatedCategoryId = is_string($rawCategoryId)
        ? filter_var(
            $rawCategoryId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )
        : false;

    if ($validatedCategoryId === false) {
        api_error_response(
            422,
            'INVALID_FILTER',
            'category_id must be a positive integer.'
        );
    }

    $categoryId = (int) $validatedCategoryId;
}

$productStatus = null;
if (array_key_exists('product_status', $_GET)) {
    if (!is_string($_GET['product_status'])) {
        api_error_response(
            422,
            'INVALID_FILTER',
            'product_status must be a string.'
        );
    }

    $productStatus = trim($_GET['product_status']);

    if ($productStatus === '' || strlen($productStatus) > 50) {
        api_error_response(
            422,
            'INVALID_FILTER',
            'product_status must contain 1 to 50 characters.'
        );
    }
}

$sortBy = 'product_name';
if (array_key_exists('sort_by', $_GET)) {
    if (!is_string($_GET['sort_by'])) {
        api_error_response(
            422,
            'INVALID_SORT',
            'sort_by must be a string.'
        );
    }

    $sortBy = $_GET['sort_by'];
}

$sortDirection = 'asc';
if (array_key_exists('sort_direction', $_GET)) {
    if (!is_string($_GET['sort_direction'])) {
        api_error_response(
            422,
            'INVALID_SORT',
            'sort_direction must be a string.'
        );
    }

    $sortDirection = strtolower($_GET['sort_direction']);
}

$allowedSortBy = ['product_name', 'sku', 'warehouse_name', 'on_hand'];
if (
    !in_array($sortBy, $allowedSortBy, true)
    || !in_array($sortDirection, ['asc', 'desc'], true)
) {
    api_error_response(
        422,
        'INVALID_SORT',
        'sort_by or sort_direction is not supported.'
    );
}

$offset = ($page - 1) * $perPage;

try {
    $result = \App\Inventory\StockBalanceService::list(
        $pdo,
        $tenantId,
        $productId,
        $warehouseId,
        $search,
        $perPage,
        $offset,
        $categoryId,
        $productStatus,
        $sortBy,
        $sortDirection
    );

    $requestId = audit_get_request_id();
    header('X-Request-ID: ' . $requestId);

    http_response_code(200);
    echo json_encode(
        [
            'success' => true,
            'data' => [
                'balances' => $result['items'],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $result['total'],
                    'total_pages' => (int) ceil($result['total'] / $perPage),
                ],
                'request_id' => $requestId,
            ],
        ],
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    error_log(
        'Inventory balance read failed: ' . $e->getMessage()
    );

    api_server_error('Unable to retrieve inventory balances.');
}
