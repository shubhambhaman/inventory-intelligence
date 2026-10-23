<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * PATCH /api/v1/products/update.php?id={product_id}
 *
 * Responsibility:
 * - Update product metadata for the authenticated tenant
 * - Enforce products.update permission
 * - Enforce CSRF protection
 * - Validate product input
 * - Enforce tenant-scoped category/unit references
 * - Normalize SKU for deterministic uniqueness
 * - Record before/after product.updated audit event
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
 *
 * Tenant comes from authenticated session.
 */

$allowedFields = [
    'sku',
    'name',
    'description',
    'category_id',
    'unit_id',
    'brand',
    'barcode',
    'purchase_price',
    'selling_price',
    'tax_rate',
    'preferred_supplier_id',
    'reorder_policy',
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
        'At least one product field must be provided.'
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
     * Load existing product
     * -----------------------------------------------------
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
     * Determine current values
     * -----------------------------------------------------
     */

    $sku = $before['sku'];
    $name = $before['name'];
    $description = $before['description'];

    $categoryId = (int) $before['category_id'];
    $unitId = (int) $before['unit_id'];

    $brand = $before['brand'];
    $barcode = $before['barcode'];

    $purchasePrice = $before['purchase_price'];
    $sellingPrice = $before['selling_price'];
    $taxRate = $before['tax_rate'];

    $preferredSupplierId =
        $before['preferred_supplier_id'] !== null
            ? (int) $before['preferred_supplier_id']
            : null;

    $reorderPolicy = $before['reorder_policy'];
    $status = $before['status'];


    /**
     * -----------------------------------------------------
     * SKU
     * -----------------------------------------------------
     */

    if (array_key_exists('sku', $data)) {

        $sku = trim((string) $data['sku']);

        if ($sku === '') {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_SKU',
                'SKU is required.'
            );
        }

        if (mb_strlen($sku) > 150) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_SKU',
                'SKU cannot exceed 150 characters.'
            );
        }
    }

    $skuNormalized = mb_strtolower(
        trim($sku),
        'UTF-8'
    );


    /**
     * -----------------------------------------------------
     * Product name
     * -----------------------------------------------------
     */

    if (array_key_exists('name', $data)) {

        $name = trim((string) $data['name']);

        if ($name === '') {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_NAME',
                'Product name is required.'
            );
        }

        if (mb_strlen($name) > 255) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_NAME',
                'Product name cannot exceed 255 characters.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Description
     * -----------------------------------------------------
     */

    if (array_key_exists('description', $data)) {

        $description =
            $data['description'] === null
                ? null
                : trim((string) $data['description']);

        if ($description === '') {
            $description = null;
        }
    }


    /**
     * -----------------------------------------------------
     * Category
     * -----------------------------------------------------
     */

    if (array_key_exists('category_id', $data)) {

        $validatedCategoryId = filter_var(
            $data['category_id'],
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        if ($validatedCategoryId === false) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_CATEGORY',
                'category_id must be a valid positive integer.'
            );
        }

        $categoryId = (int) $validatedCategoryId;
    }


    /**
     * -----------------------------------------------------
     * Unit
     * -----------------------------------------------------
     */

    if (array_key_exists('unit_id', $data)) {

        $validatedUnitId = filter_var(
            $data['unit_id'],
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        if ($validatedUnitId === false) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_UNIT',
                'unit_id must be a valid positive integer.'
            );
        }

        $unitId = (int) $validatedUnitId;
    }


    /**
     * -----------------------------------------------------
     * Brand
     * -----------------------------------------------------
     */

    if (array_key_exists('brand', $data)) {

        $brand =
            $data['brand'] === null
                ? null
                : trim((string) $data['brand']);

        if ($brand === '') {
            $brand = null;
        }
    }


    /**
     * -----------------------------------------------------
     * Barcode
     * -----------------------------------------------------
     */

    if (array_key_exists('barcode', $data)) {

        $barcode =
            $data['barcode'] === null
                ? null
                : trim((string) $data['barcode']);

        if ($barcode === '') {
            $barcode = null;
        }
    }


    /**
     * -----------------------------------------------------
     * Price validation helper
     * -----------------------------------------------------
     */

    $validateDecimal = static function (
        mixed $value,
        string $field
    ): ?string {

        if ($value === null || $value === '') {
            return null;
        }

        if (
            !is_int($value) &&
            !is_float($value) &&
            !is_string($value)
        ) {
            api_error_response(
                422,
                'INVALID_' . strtoupper($field),
                $field . ' must be a valid non-negative number.'
            );
        }

        $valueString = trim((string) $value);

        if (
            $valueString === '' ||
            !preg_match(
                '/^\d+(?:\.\d+)?$/',
                $valueString
            )
        ) {
            api_error_response(
                422,
                'INVALID_' . strtoupper($field),
                $field . ' must be a valid non-negative number.'
            );
        }

        if ((float) $valueString < 0) {
            api_error_response(
                422,
                'INVALID_' . strtoupper($field),
                $field . ' cannot be negative.'
            );
        }

        return $valueString;
    };


    /**
     * -----------------------------------------------------
     * Prices
     * -----------------------------------------------------
     */

    if (array_key_exists('purchase_price', $data)) {

        $purchasePrice = $validateDecimal(
            $data['purchase_price'],
            'purchase_price'
        );
    }


    if (array_key_exists('selling_price', $data)) {

        $sellingPrice = $validateDecimal(
            $data['selling_price'],
            'selling_price'
        );
    }


    if (array_key_exists('tax_rate', $data)) {

        $taxRate = $validateDecimal(
            $data['tax_rate'],
            'tax_rate'
        );
    }


    /**
     * -----------------------------------------------------
     * Preferred supplier input validation
     * -----------------------------------------------------
     */

    if (array_key_exists('preferred_supplier_id', $data)) {
        if (
            $data['preferred_supplier_id'] === null ||
            $data['preferred_supplier_id'] === ''
        ) {
            $preferredSupplierId = null;
        } else {
            $validatedSupplierId = filter_var(
                $data['preferred_supplier_id'],
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1
                    ]
                ]
            );

            if ($validatedSupplierId === false) {
                $pdo->rollBack();

                api_error_response(
                    422,
                    'INVALID_SUPPLIER',
                    'preferred_supplier_id must be a valid positive integer or null.'
                );
            }

            $preferredSupplierId = (int) $validatedSupplierId;
        }
    }


    /**
     * -----------------------------------------------------
     * Reorder policy
     * -----------------------------------------------------
     */

    $reorderPolicyJson = $reorderPolicy;

    if (array_key_exists('reorder_policy', $data)) {

        $reorderPolicy = $data['reorder_policy'];

        if ($reorderPolicy === null || $reorderPolicy === '') {

            $reorderPolicyJson = null;

        } else {

            if (
                !is_array($reorderPolicy) &&
                !is_string($reorderPolicy)
            ) {

                $pdo->rollBack();

                api_error_response(
                    422,
                    'INVALID_REORDER_POLICY',
                    'reorder_policy must be a valid JSON object or JSON string.'
                );
            }

            try {

                if (is_string($reorderPolicy)) {

                    $decodedPolicy = json_decode(
                        $reorderPolicy,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                } else {

                    $decodedPolicy = $reorderPolicy;
                }

                $reorderPolicyJson = json_encode(
                    $decodedPolicy,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_THROW_ON_ERROR
                );

            } catch (JsonException $e) {

                $pdo->rollBack();

                api_error_response(
                    422,
                    'INVALID_REORDER_POLICY',
                    'reorder_policy must contain valid JSON.'
                );
            }
        }
    }


    /**
     * -----------------------------------------------------
     * Status
     * -----------------------------------------------------
     */

    if (array_key_exists('status', $data)) {

        $status = trim((string) $data['status']);

        if (!in_array(
            $status,
            ['draft', 'active', 'inactive'],
            true
        )) {

            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_STATUS',
                'Status must be draft, active, or inactive.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Verify category belongs to current tenant
     * -----------------------------------------------------
     */

    $categoryStmt = $pdo->prepare(
        '
        SELECT
            id,
            name,
            status
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

    $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$category) {

        $pdo->rollBack();

        api_error_response(
            422,
            'INVALID_CATEGORY',
            'The selected category does not exist for this company.'
        );
    }


    /**
     * -----------------------------------------------------
     * Verify unit belongs to current tenant
     * -----------------------------------------------------
     */

    $unitStmt = $pdo->prepare(
        '
        SELECT
            id,
            name,
            status
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

    $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

    if (!$unit) {

        $pdo->rollBack();

        api_error_response(
            422,
            'INVALID_UNIT',
            'The selected unit does not exist for this company.'
        );
    }


    /**
     * -----------------------------------------------------
     * Verify newly assigned supplier belongs to current tenant
     * -----------------------------------------------------
     */

    if (
        array_key_exists('preferred_supplier_id', $data) &&
        $preferredSupplierId !== null
    ) {
        $supplierStmt = $pdo->prepare(
            '
            SELECT id
            FROM suppliers
            WHERE tenant_id = :tenant_id
              AND id = :supplier_id
            LIMIT 1
            '
        );

        $supplierStmt->execute([
            ':tenant_id' => $tenantId,
            ':supplier_id' => $preferredSupplierId
        ]);

        if (!$supplierStmt->fetchColumn()) {
            $pdo->rollBack();

            api_error_response(
                422,
                'INVALID_SUPPLIER',
                'The selected supplier does not exist for this company.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Duplicate normalized SKU
     * -----------------------------------------------------
     *
     * Exclude current product.
     */

    $skuStmt = $pdo->prepare(
        '
        SELECT
            id
        FROM products
        WHERE tenant_id = :tenant_id
          AND sku_normalized = :sku_normalized
          AND id <> :product_id
        LIMIT 1
        '
    );

    $skuStmt->execute([
        ':tenant_id' => $tenantId,
        ':sku_normalized' => $skuNormalized,
        ':product_id' => $productId
    ]);

    $existingProduct = $skuStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingProduct) {

        $pdo->rollBack();

        api_conflict(
            'A product with this SKU already exists.'
        );
    }


    /**
     * -----------------------------------------------------
     * Update product
     * -----------------------------------------------------
     */

    $updateStmt = $pdo->prepare(
        '
        UPDATE products
        SET
            sku = :sku,
            sku_normalized = :sku_normalized,
            name = :name,
            description = :description,
            category_id = :category_id,
            unit_id = :unit_id,
            brand = :brand,
            barcode = :barcode,
            purchase_price = :purchase_price,
            selling_price = :selling_price,
            tax_rate = :tax_rate,
            preferred_supplier_id = :preferred_supplier_id,
            reorder_policy = :reorder_policy,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE tenant_id = :tenant_id
          AND id = :product_id
        '
    );

    $updateStmt->execute([
        ':sku' => $sku,
        ':sku_normalized' => $skuNormalized,
        ':name' => $name,
        ':description' => $description,
        ':category_id' => $categoryId,
        ':unit_id' => $unitId,
        ':brand' => $brand,
        ':barcode' => $barcode,
        ':purchase_price' => $purchasePrice,
        ':selling_price' => $sellingPrice,
        ':tax_rate' => $taxRate,
        ':preferred_supplier_id' => $preferredSupplierId,
        ':reorder_policy' => $reorderPolicyJson,
        ':status' => $status,
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
            'Updated product could not be loaded.'
        );
    }


    /**
     * -----------------------------------------------------
     * Audit
     * -----------------------------------------------------
     */

    $auditId = audit_event(
        'product.updated',
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

            'message' =>
                'Product updated successfully.',

            'data' => [
                'product' => [
                    'id' => (int) $after['id'],
                    'sku' => $after['sku'],
                    'name' => $after['name'],
                    'description' => $after['description'],
                    'category_id' => (int) $after['category_id'],
                    'unit_id' => (int) $after['unit_id'],
                    'brand' => $after['brand'],
                    'barcode' => $after['barcode'],
                    'purchase_price' => $after['purchase_price'],
                    'selling_price' => $after['selling_price'],
                    'tax_rate' => $after['tax_rate'],
                    'preferred_supplier_id' =>
                        $after['preferred_supplier_id'] !== null
                            ? (int) $after['preferred_supplier_id']
                            : null,
                    'reorder_policy' => $after['reorder_policy'],
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
     * Database UNIQUE constraint remains the
     * final authority against race conditions.
     */

    if (
        isset($e->errorInfo[1]) &&
        (int) $e->errorInfo[1] === 1062
    ) {

        api_conflict(
            'A product with this SKU already exists.'
        );
    }


    error_log(
        'Product update database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to update the product.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Product update error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to update the product.'
    );
}