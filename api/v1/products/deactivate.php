<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * POST /api/v1/products/deactivate.php?id={product_id}
 *
 * Responsibility:
 * - Deactivate product for the authenticated tenant
 * - Enforce products.update permission
 * - Enforce CSRF protection
 * - Preserve historical product reference
 * - Record product.deactivated audit event
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
 * Deactivation is a product mutation.
 */

requirePermission('products.update');

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
 * Product ID
 * ---------------------------------------------------------
 */

$productId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($productId === false) {

    api_error_response(
        422,
        'INVALID_PRODUCT_ID',
        'Product id must be a valid positive integer.'
    );
}

$productId = (int) $productId;

/**
 * ---------------------------------------------------------
 * Transaction
 * ---------------------------------------------------------
 */

try {

    $pdo->beginTransaction();

    /**
     * -----------------------------------------------------
     * Load existing product
     * -----------------------------------------------------
     *
     * Tenant is always taken from the authenticated session.
     */

    $productStmt = $pdo->prepare(
        '
        SELECT
            id,
            tenant_id,
            sku,
            sku_normalized,
            name,
            description,
            category_id,
            unit_id,
            brand,
            barcode,
            purchase_price,
            selling_price,
            tax_rate,
            preferred_supplier_id,
            reorder_policy,
            status,
            created_at,
            updated_at
        FROM products
        WHERE tenant_id = :tenant_id
          AND id = :product_id
        LIMIT 1
        '
    );

    $productStmt->execute([
        ':tenant_id' => $tenantId,
        ':product_id' => $productId
    ]);

    $before = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$before) {

        $pdo->rollBack();

        api_not_found(
            'Product not found.'
        );
    }

    /**
     * -----------------------------------------------------
     * Already inactive
     * -----------------------------------------------------
     *
     * Deactivation is idempotent at the business level.
     * No second audit event is created.
     */

    if ($before['status'] === 'inactive') {

        $pdo->rollBack();

        api_error_response(
            409,
            'PRODUCT_ALREADY_INACTIVE',
            'Product is already inactive.'
        );
    }

    /**
     * -----------------------------------------------------
     * Deactivate product
     * -----------------------------------------------------
     *
     * Historical references remain intact because the row
     * is not deleted.
     */

    $updateStmt = $pdo->prepare(
        '
        UPDATE products
        SET
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE tenant_id = :tenant_id
          AND id = :product_id
        '
    );

    $updateStmt->execute([
        ':status' => 'inactive',
        ':tenant_id' => $tenantId,
        ':product_id' => $productId
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
            sku,
            sku_normalized,
            name,
            description,
            category_id,
            unit_id,
            brand,
            barcode,
            purchase_price,
            selling_price,
            tax_rate,
            preferred_supplier_id,
            reorder_policy,
            status,
            created_at,
            updated_at
        FROM products
        WHERE tenant_id = :tenant_id
          AND id = :product_id
        LIMIT 1
        '
    );

    $afterStmt->execute([
        ':tenant_id' => $tenantId,
        ':product_id' => $productId
    ]);

    $after = $afterStmt->fetch(PDO::FETCH_ASSOC);

    if (!$after) {

        throw new RuntimeException(
            'Deactivated product could not be loaded.'
        );
    }

    /**
     * -----------------------------------------------------
     * Audit
     * -----------------------------------------------------
     */

    $auditId = audit_event(
        'product.deactivated',
        'product',
        (string) $productId,
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
            'message' => 'Product deactivated successfully.',
            'data' => [
                'product' => [
                    'id' => (int) $after['id'],
                    'sku' => $after['sku'],
                    'name' => $after['name'],
                    'status' => $after['status'],
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

    error_log(
        'Product deactivation database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to deactivate the product.'
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Product deactivation error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to deactivate the product.'
    );
}