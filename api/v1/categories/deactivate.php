
<?php

/**
 * POST /api/v1/categories/deactivate.php?id={category_id}
 * Deactivate a tenant-owned category without deleting history.
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    api_error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only POST requests are allowed.'
    );
}

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

requirePermission('categories.manage');

if (!csrf_is_valid()) {
    api_error_response(
        403,
        'CSRF_TOKEN_INVALID',
        'Invalid or missing CSRF token.'
    );
}

$categoryId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($categoryId === false || $categoryId === null) {
    api_error_response(
        422,
        'INVALID_CATEGORY_ID',
        'Category id must be a valid positive integer.'
    );
}

$categoryId = (int) $categoryId;

try {
    $pdo->beginTransaction();

    $beforeStmt = $pdo->prepare(
        'SELECT id, tenant_id, name, name_normalized,
                parent_id, status, created_at, updated_at
         FROM categories
         WHERE tenant_id = :tenant_id
           AND id = :category_id
         LIMIT 1'
    );

    $beforeStmt->execute([
        ':tenant_id' => $tenantId,
        ':category_id' => $categoryId
    ]);

    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$before) {
        $pdo->rollBack();
        api_not_found('Category not found.');
    }

    if ($before['status'] === 'inactive') {
        $pdo->rollBack();

        api_error_response(
            409,
            'CATEGORY_ALREADY_INACTIVE',
            'Category is already inactive.'
        );
    }

    $updateStmt = $pdo->prepare(
        'UPDATE categories
         SET status = :status,
             updated_at = CURRENT_TIMESTAMP
         WHERE tenant_id = :tenant_id
           AND id = :category_id'
    );

    $updateStmt->execute([
        ':status' => 'inactive',
        ':tenant_id' => $tenantId,
        ':category_id' => $categoryId
    ]);

    $afterStmt = $pdo->prepare(
        'SELECT id, tenant_id, name, name_normalized,
                parent_id, status, created_at, updated_at
         FROM categories
         WHERE tenant_id = :tenant_id
           AND id = :category_id
         LIMIT 1'
    );

    $afterStmt->execute([
        ':tenant_id' => $tenantId,
        ':category_id' => $categoryId
    ]);

    $after = $afterStmt->fetch(PDO::FETCH_ASSOC);

    if (!$after) {
        throw new RuntimeException(
            'Deactivated category could not be loaded.'
        );
    }

    $auditId = audit_event(
        'category.deactivated',
        'category',
        (string) $categoryId,
        $before,
        $after
    );

    $pdo->commit();

    $requestId = audit_get_request_id();

    header('X-Request-ID: ' . $requestId);
    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => 'Category deactivated successfully.',
        'data' => [
            'category' => [
                'id' => (int) $after['id'],
                'name' => $after['name'],
                'status' => $after['status'],
                'updated_at' => $after['updated_at']
            ],
            'audit_id' => $auditId,
            'request_id' => $requestId
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Category deactivation database error: ' .
        $e->getMessage()
    );

    api_database_error('Unable to deactivate the category.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Category deactivation error: ' .
        $e->getMessage()
    );

    api_server_error('Unable to deactivate the category.');
}
