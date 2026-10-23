<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * PATCH /api/v1/categories/update.php?id={category_id}
 *
 * Responsibility:
 * - Update category metadata for the authenticated tenant
 * - Enforce categories.manage permission
 * - Enforce CSRF protection
 * - Validate category input
 * - Enforce tenant-scoped parent category
 * - Prevent duplicate normalized category names
 * - Record before/after audit event
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
    error_response(
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

requirePermission('categories.manage');


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
 * Category ID
 * ---------------------------------------------------------
 */

$categoryId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($categoryId === false) {
    api_error_response(
        422,
        'INVALID_CATEGORY_ID',
        'Category id must be a valid positive integer.'
    );
}

$categoryId = (int) $categoryId;


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
 * Require at least one field
 * ---------------------------------------------------------
 */

if (empty($data)) {
    api_error_response(
        422,
        'NO_FIELDS',
        'At least one category field must be provided.'
    );
}


/**
 * ---------------------------------------------------------
 * Database transaction
 * ---------------------------------------------------------
 */

try {

    $pdo->beginTransaction();


    /**
     * -----------------------------------------------------
     * Load existing category
     * -----------------------------------------------------
     */

    $categoryStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            name,
            name_normalized,
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

    $categoryStmt->execute([
        ':tenant_id' => $tenantId,
        ':category_id' => $categoryId
    ]);

    $before = $categoryStmt->fetch(PDO::FETCH_ASSOC);


    if (!$before) {

        $pdo->rollBack();

        api_not_found(
            'Category not found.'
        );
    }


    /**
     * -----------------------------------------------------
     * Determine new values
     * -----------------------------------------------------
     */

    $name = $before['name'];
    $nameNormalized = $before['name_normalized'];
    $parentId = $before['parent_id'] !== null
        ? (int) $before['parent_id']
        : null;
    $status = $before['status'];


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
                'Category name is required.'
            );
        }

        if (mb_strlen($name) > 150) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_NAME',
                'Category name cannot exceed 150 characters.'
            );
        }

        $nameNormalized = mb_strtolower(
            $name,
            'UTF-8'
        );
    }


    /**
     * -----------------------------------------------------
     * Parent ID
     * -----------------------------------------------------
     */

    if (array_key_exists('parent_id', $data)) {

        if (
            $data['parent_id'] === null ||
            $data['parent_id'] === ''
        ) {

            $parentId = null;

        } else {

            $validatedParentId = filter_var(
                $data['parent_id'],
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
                    'INVALID_PARENT_ID',
                    'parent_id must be a valid positive integer or null.'
                );
            }

            $parentId = (int) $validatedParentId;
        }
    }


    /**
     * -----------------------------------------------------
     * Prevent category from being its own parent
     * -----------------------------------------------------
     */

    if (
        $parentId !== null &&
        $parentId === $categoryId
    ) {

        $pdo->rollBack();

        api_error_response(
            422,
            'INVALID_PARENT_CATEGORY',
            'A category cannot be its own parent.'
        );
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

        $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);


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
     * Prevent indirect parent cycles
     * -----------------------------------------------------
     * Walk upward from the proposed parent. If this category is
     * encountered, assigning this parent would create a cycle.
     * Tenant scoping is applied to every ancestor lookup.
     */

    if ($parentId !== null) {
        $ancestorId = $parentId;
        $visitedAncestorIds = [];

        while ($ancestorId !== null) {
            if ($ancestorId === $categoryId) {
                $pdo->rollBack();
                api_error_response(
                    422,
                    'INVALID_PARENT_CATEGORY',
                    'The selected parent would create a category hierarchy cycle.'
                );
            }

            // Also fail safely if the existing hierarchy already contains a cycle.
            if (isset($visitedAncestorIds[$ancestorId])) {
                $pdo->rollBack();
                api_error_response(
                    422,
                    'INVALID_PARENT_CATEGORY',
                    'The existing category hierarchy contains a cycle.'
                );
            }
            $visitedAncestorIds[$ancestorId] = true;

            $ancestorStmt = $pdo->prepare(
                'SELECT parent_id FROM categories WHERE tenant_id = :tenant_id AND id = :ancestor_id LIMIT 1'
            );
            $ancestorStmt->execute([
                ':tenant_id' => $tenantId,
                ':ancestor_id' => $ancestorId,
            ]);
            $ancestorParent = $ancestorStmt->fetchColumn();

            if ($ancestorParent === false) {
                // Parent chain is inconsistent; reject instead of trusting it.
                $pdo->rollBack();
                api_error_response(
                    422,
                    'INVALID_PARENT_CATEGORY',
                    'The selected parent hierarchy is invalid.'
                );
            }

            $ancestorId = $ancestorParent !== null ? (int) $ancestorParent : null;
        }
    }


    /**
     * -----------------------------------------------------
     * Duplicate normalized name
     * -----------------------------------------------------
     *
     * Exclude the category currently being updated.
     */

    $duplicateStmt = $pdo->prepare(
        '
        SELECT
            id
        FROM categories
        WHERE tenant_id = :tenant_id
          AND name_normalized = :name_normalized
          AND id <> :category_id
        LIMIT 1
        '
    );

    $duplicateStmt->execute([
        ':tenant_id' => $tenantId,
        ':name_normalized' => $nameNormalized,
        ':category_id' => $categoryId
    ]);

    $duplicateCategory = $duplicateStmt->fetch(PDO::FETCH_ASSOC);


    if ($duplicateCategory) {

        $pdo->rollBack();

        api_conflict(
            'A category with this name already exists.'
        );
    }


    /**
     * -----------------------------------------------------
     * Update category
     * -----------------------------------------------------
     */

    $updateStmt = $pdo->prepare(
        '
        UPDATE categories
        SET
            name = :name,
            name_normalized = :name_normalized,
            parent_id = :parent_id,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE tenant_id = :tenant_id
          AND id = :category_id
        '
    );

    $updateStmt->execute([
        ':name' => $name,
        ':name_normalized' => $nameNormalized,
        ':parent_id' => $parentId,
        ':status' => $status,
        ':tenant_id' => $tenantId,
        ':category_id' => $categoryId
    ]);


    /**
     * -----------------------------------------------------
     * Load updated category
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

    $after = $afterStmt->fetch(PDO::FETCH_ASSOC);


    if (!$after) {

        throw new RuntimeException(
            'Updated category could not be loaded.'
        );
    }


    /**
     * -----------------------------------------------------
     * Audit
     * -----------------------------------------------------
     */

    $auditId = audit_event(
        'category.updated',
        'category',
        (string) $categoryId,
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
                'Category updated successfully.',

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
        'Category update database error: ' .
        $e->getMessage()
    );


    api_database_error(
        'Unable to update the category.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    error_log(
        'Category update error: ' .
        $e->getMessage()
    );


    api_server_error(
        'Unable to update the category.'
    );
}