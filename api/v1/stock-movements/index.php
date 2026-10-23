<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * GET /api/v1/stock-movements
 *
 * Read-only, tenant-scoped stock ledger endpoint.
 * Uses the existing stock.view permission and centralized API conventions.
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';

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
        'Stock movement tenant verification failed: ' . $e->getMessage()
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

$page = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$perPage = filter_input(
    INPUT_GET,
    'per_page',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

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

$movementType = isset($_GET['movement_type'])
    ? strtoupper(trim((string) $_GET['movement_type']))
    : '';
$status = isset($_GET['status'])
    ? strtolower(trim((string) $_GET['status']))
    : '';
$direction = isset($_GET['direction'])
    ? strtolower(trim((string) $_GET['direction']))
    : '';
$productId = isset($_GET['product_id'])
    ? filter_var($_GET['product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
$warehouseId = isset($_GET['warehouse_id'])
    ? filter_var($_GET['warehouse_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;

$allowedTypes = [
    'OPENING',
    'PURCHASE',
    'SALE',
    'RETURN_IN',
    'RETURN_OUT',
    'TRANSFER_IN',
    'TRANSFER_OUT',
    'ADJUSTMENT',
];

if ($movementType !== '' && !in_array($movementType, $allowedTypes, true)) {
    api_error_response(422, 'INVALID_FILTER', 'Unsupported movement_type filter.');
}

if ($status !== '' && !in_array($status, ['pending', 'posted', 'reversed'], true)) {
    api_error_response(422, 'INVALID_FILTER', 'Unsupported status filter.');
}

if ($direction !== '' && !in_array($direction, ['in', 'out'], true)) {
    api_error_response(422, 'INVALID_FILTER', 'direction must be in or out.');
}

if ($productId === false || $warehouseId === false) {
    api_error_response(422, 'INVALID_FILTER', 'product_id and warehouse_id must be positive integers.');
}

$where = ['sm.tenant_id = :tenant_id'];
$params = [':tenant_id' => (int) $tenantId];

if ($movementType !== '') {
    $where[] = 'sm.movement_type = :movement_type';
    $params[':movement_type'] = $movementType;
}
if ($status !== '') {
    $where[] = 'sm.status = :status';
    $params[':status'] = $status;
}
if ($direction !== '') {
    $where[] = 'sm.direction = :direction';
    $params[':direction'] = $direction;
}
if ($productId !== null) {
    $where[] = 'sm.product_id = :product_id';
    $params[':product_id'] = (int) $productId;
}
if ($warehouseId !== null) {
    $where[] = 'sm.warehouse_id = :warehouse_id';
    $params[':warehouse_id'] = (int) $warehouseId;
}

$whereSql = implode(' AND ', $where);
$offset = ($page - 1) * $perPage;

try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM stock_movements sm
          WHERE {$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            sm.id,
            sm.product_id,
            p.sku,
            p.name AS product_name,
            sm.warehouse_id,
            w.name AS warehouse_name,
            sm.movement_type,
            sm.direction,
            sm.quantity,
            sm.unit_cost,
            sm.source_type,
            sm.source_reference,
            sm.status,
            sm.reversal_of_movement_id,
            sm.created_by,
            sm.posted_by,
            sm.occurred_at,
            sm.posted_at,
            sm.created_at
        FROM stock_movements sm
        INNER JOIN products p
            ON p.tenant_id = sm.tenant_id
           AND p.id = sm.product_id
        INNER JOIN warehouses w
            ON w.tenant_id = sm.tenant_id
           AND w.id = sm.warehouse_id
        WHERE {$whereSql}
        ORDER BY sm.occurred_at DESC, sm.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['warehouse_id'] = (int) $row['warehouse_id'];
        $row['quantity'] = (string) $row['quantity'];
        $row['unit_cost'] = $row['unit_cost'] !== null
            ? (string) $row['unit_cost']
            : null;
        $row['reversal_of_movement_id'] = $row['reversal_of_movement_id'] !== null
            ? (int) $row['reversal_of_movement_id']
            : null;
        $row['created_by'] = (int) $row['created_by'];
        $row['posted_by'] = $row['posted_by'] !== null
            ? (int) $row['posted_by']
            : null;
    }
    unset($row);

    $requestId = audit_get_request_id();
    header('X-Request-ID: ' . $requestId);

    http_response_code(200);
    echo json_encode(
        [
            'success' => true,
            'data' => [
                'movements' => $rows,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int) ceil($total / $perPage),
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
        'Stock movement ledger read failed: ' . $e->getMessage()
    );
    api_server_error('Unable to retrieve stock movements.');
}
