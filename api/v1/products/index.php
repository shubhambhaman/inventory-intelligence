<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * POST /api/v1/products
 *
 * Responsibility:
 * - Create a product for the authenticated tenant
 * - Enforce products.create permission
 * - Enforce CSRF protection
 * - Validate product input
 * - Enforce tenant-scoped category/unit references
 * - Normalize SKU for deterministic uniqueness
 * - Record product.created audit event
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

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {

    header('Allow: GET, POST');

    api_error_response(
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requirePermission('products.view');
} else {
    requirePermission('products.create');
}


/**
 * ---------------------------------------------------------
 * CSRF protection
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_is_valid()) {

    api_error_response(
        403,
        'CSRF_TOKEN_INVALID',
        'Invalid or missing CSRF token.'
    );
}


/**
 * ---------------------------------------------------------
 * Read JSON body
 * ---------------------------------------------------------
 */


/**
 * ---------------------------------------------------------
 * GET /products
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    /**
     * ---------------------------------------------------------
     * Query parameters
     * ---------------------------------------------------------
     */

    $page = filter_input(
        INPUT_GET,
        'page',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    $perPage = filter_input(
        INPUT_GET,
        'per_page',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    $page = ($page !== false && $page !== null)
        ? (int) $page
        : 1;

    $perPage = ($perPage !== false && $perPage !== null)
        ? (int) $perPage
        : 25;


    /**
     * ---------------------------------------------------------
     * Pagination limits
     * ---------------------------------------------------------
     */

    if ($perPage > 100) {
        $perPage = 100;
    }


    /**
     * ---------------------------------------------------------
     * Search
     * ---------------------------------------------------------
     */

    $search = isset($_GET['search'])
        ? trim((string) $_GET['search'])
        : '';


    if (mb_strlen($search) > 150) {

        api_error_response(
            422,
            'INVALID_SEARCH',
            'Search cannot exceed 150 characters.'
        );
    }


    /**
     * ---------------------------------------------------------
     * Status filter
     * ---------------------------------------------------------
     */

    $status = isset($_GET['status'])
        ? trim((string) $_GET['status'])
        : '';


    $allowedStatuses = [
        'draft',
        'active',
        'inactive'
    ];


    if (
        $status !== '' &&
        !in_array($status, $allowedStatuses, true)
    ) {

        api_error_response(
            422,
            'INVALID_STATUS',
            'Status must be draft, active, or inactive.'
        );
    }


    /**
     * ---------------------------------------------------------
     * Category filter
     * ---------------------------------------------------------
     */

    $categoryId = null;

    if (isset($_GET['category_id']) && $_GET['category_id'] !== '') {

        $categoryId = filter_var(
            $_GET['category_id'],
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
                'INVALID_CATEGORY',
                'category_id must be a valid positive integer.'
            );
        }

        $categoryId = (int) $categoryId;
    }


    /**
     * ---------------------------------------------------------
     * Offset
     * ---------------------------------------------------------
     */

    $offset = ($page - 1) * $perPage;


    /**
     * ---------------------------------------------------------
     * Build WHERE conditions
     * ---------------------------------------------------------
     */

    $where = [
        'p.tenant_id = :tenant_id'
    ];

    $params = [
        ':tenant_id' => $tenantId
    ];


    if ($search !== '') {

        $where[] = '
            (
                p.sku LIKE :search_sku
                OR p.name LIKE :search_name
                OR p.brand LIKE :search_brand
                OR p.barcode LIKE :search_barcode
            )
        ';

        $searchValue = '%' . $search . '%';

        $params[':search_sku'] = $searchValue;
        $params[':search_name'] = $searchValue;
        $params[':search_brand'] = $searchValue;
        $params[':search_barcode'] = $searchValue;
    }


    if ($status !== '') {

        $where[] = 'p.status = :status';

        $params[':status'] = $status;
    }


    if ($categoryId !== null) {

        $where[] = 'p.category_id = :category_id';

        $params[':category_id'] = $categoryId;
    }


    $whereSql = implode(
        ' AND ',
        $where
    );


    /**
     * ---------------------------------------------------------
     * Database queries
     * ---------------------------------------------------------
     */

    try {

        /**
         * -----------------------------------------------------
         * Total count
         * -----------------------------------------------------
         */

        $countStmt = $pdo->prepare(
            "
            SELECT COUNT(*)
            FROM products p
            WHERE {$whereSql}
            "
        );

        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();


        /**
         * -----------------------------------------------------
         * Product list
         * -----------------------------------------------------
         *
         * IMPORTANT:
         * - No SELECT *
         * - Tenant scoped
         * - Category/unit names included
         */

        $listSql = "
            SELECT
                p.id,
                p.sku,
                p.name,
                p.description,
                p.category_id,
                c.name AS category_name,
                p.unit_id,
                u.name AS unit_name,
                p.brand,
                p.barcode,
                p.purchase_price,
                p.selling_price,
                p.tax_rate,
                p.preferred_supplier_id,
                p.reorder_policy,
                p.status,
                p.created_at,
                p.updated_at
            FROM products p

            INNER JOIN categories c
                ON c.tenant_id = p.tenant_id
               AND c.id = p.category_id

            INNER JOIN units u
                ON u.tenant_id = p.tenant_id
               AND u.id = p.unit_id

            WHERE {$whereSql}

            ORDER BY p.id DESC

            LIMIT {$perPage}
            OFFSET {$offset}
        ";


        $listStmt = $pdo->prepare($listSql);

        $listStmt->execute($params);

        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);


        /**
         * -----------------------------------------------------
         * Format products
         * -----------------------------------------------------
         */

        $products = [];

        foreach ($rows as $row) {

            $products[] = [
                'id' => (int) $row['id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'description' => $row['description'],

                'category' => [
                    'id' => (int) $row['category_id'],
                    'name' => $row['category_name']
                ],

                'unit' => [
                    'id' => (int) $row['unit_id'],
                    'name' => $row['unit_name']
                ],

                'brand' => $row['brand'],
                'barcode' => $row['barcode'],

                'purchase_price' => $row['purchase_price'],
                'selling_price' => $row['selling_price'],
                'tax_rate' => $row['tax_rate'],

                'preferred_supplier_id' =>
                    $row['preferred_supplier_id'] !== null
                        ? (int) $row['preferred_supplier_id']
                        : null,

                'reorder_policy' => $row['reorder_policy'],

                'status' => $row['status'],

                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }


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
                    'items' => $products,

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
            'Product list database error: ' .
            $e->getMessage()
        );

        api_database_error(
            'Unable to load products.'
        );


    } catch (Throwable $e) {

        error_log(
            'Product list error: ' .
            $e->getMessage()
        );

        api_server_error(
            'Unable to load products.'
        );
    }
}

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
 * Tenant comes from the authenticated session.
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
 * Read fields
 * ---------------------------------------------------------
 */

$sku = trim(
    (string) ($data['sku'] ?? '')
);

$name = trim(
    (string) ($data['name'] ?? '')
);

$description = array_key_exists('description', $data)
    ? trim((string) $data['description'])
    : null;

$categoryId = filter_var(
    $data['category_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

$unitId = filter_var(
    $data['unit_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

$brand = array_key_exists('brand', $data)
    ? trim((string) $data['brand'])
    : null;

$barcode = array_key_exists('barcode', $data)
    ? trim((string) $data['barcode'])
    : null;

$purchasePrice = $data['purchase_price'] ?? null;

$sellingPrice = $data['selling_price'] ?? null;

$taxRate = $data['tax_rate'] ?? null;

$errors = [];

$preferredSupplierId = null;

if (
    array_key_exists('preferred_supplier_id', $data) &&
    $data['preferred_supplier_id'] !== null &&
    $data['preferred_supplier_id'] !== ''
) {
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
        $errors['preferred_supplier_id'] =
            'preferred_supplier_id must be a valid positive integer or null.';
    } else {
        $preferredSupplierId = (int) $validatedSupplierId;
    }
}

$reorderPolicy = $data['reorder_policy'] ?? null;

$status = array_key_exists('status', $data)
    ? trim((string) $data['status'])
    : 'draft';


/**
 * ---------------------------------------------------------
 * Validation
 * ---------------------------------------------------------
 */


/**
 * SKU
 */

if ($sku === '') {

    $errors['sku'] = 'SKU is required.';

} elseif (mb_strlen($sku) > 150) {

    $errors['sku'] = 'SKU cannot exceed 150 characters.';
}


/**
 * Product name
 */

if ($name === '') {

    $errors['name'] = 'Product name is required.';

} elseif (mb_strlen($name) > 255) {

    $errors['name'] = 'Product name cannot exceed 255 characters.';
}


/**
 * Category
 */

if ($categoryId === false) {

    $errors['category_id'] = 'A valid category_id is required.';
}


/**
 * Unit
 */

if ($unitId === false) {

    $errors['unit_id'] = 'A valid unit_id is required.';
}


/**
 * Status
 *
 * Product state model from the specification:
 * draft / active / inactive
 */

$allowedStatuses = [
    'draft',
    'active',
    'inactive'
];

if (!in_array($status, $allowedStatuses, true)) {

    $errors['status'] =
        'Status must be draft, active, or inactive.';
}


/**
 * Price validation
 */

function validate_non_negative_decimal(
    mixed $value,
    string $field,
    array &$errors
): ?string {

    if ($value === null || $value === '') {
        return null;
    }

    if (
        !is_int($value) &&
        !is_float($value) &&
        !is_string($value)
    ) {
        $errors[$field] =
            $field . ' must be a valid non-negative number.';

        return null;
    }

    $valueString = trim((string) $value);

    if (
        $valueString === '' ||
        !preg_match(
            '/^\d+(?:\.\d+)?$/',
            $valueString
        )
    ) {
        $errors[$field] =
            $field . ' must be a valid non-negative number.';

        return null;
    }

    if ((float) $valueString < 0) {
        $errors[$field] =
            $field . ' cannot be negative.';

        return null;
    }

    return $valueString;
}


$purchasePrice = validate_non_negative_decimal(
    $purchasePrice,
    'purchase_price',
    $errors
);

$sellingPrice = validate_non_negative_decimal(
    $sellingPrice,
    'selling_price',
    $errors
);

$taxRate = validate_non_negative_decimal(
    $taxRate,
    'tax_rate',
    $errors
);


/**
 * ---------------------------------------------------------
 * Reorder policy validation
 * ---------------------------------------------------------
 *
 * Stored as JSON.
 */

if ($reorderPolicy !== null) {

    if (
        !is_array($reorderPolicy) &&
        !is_string($reorderPolicy)
    ) {

        $errors['reorder_policy'] =
            'reorder_policy must be a valid JSON object or JSON string.';
    }

    if (is_string($reorderPolicy)) {

        json_decode(
            $reorderPolicy,
            true
        );

        if (json_last_error() !== JSON_ERROR_NONE) {

            $errors['reorder_policy'] =
                'reorder_policy must contain valid JSON.';
        }
    }
}


/**
 * ---------------------------------------------------------
 * Return validation errors
 * ---------------------------------------------------------
 */

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
 * Normalize SKU
 * ---------------------------------------------------------
 *
 * Preserve original SKU in `sku`.
 *
 * Use normalized value only for comparison.
 */

$skuNormalized = mb_strtolower(
    trim($sku),
    'UTF-8'
);


/**
 * ---------------------------------------------------------
 * Normalize nullable fields
 * ---------------------------------------------------------
 */

$description =
    ($description !== null && $description !== '')
        ? $description
        : null;

$brand =
    ($brand !== null && $brand !== '')
        ? $brand
        : null;

$barcode =
    ($barcode !== null && $barcode !== '')
        ? $barcode
        : null;


/**
 * ---------------------------------------------------------
 * Transaction
 * ---------------------------------------------------------
 */

try {

    $pdo->beginTransaction();


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

    $category = $categoryStmt->fetch();


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

    $unit = $unitStmt->fetch();


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
     * Check normalized SKU
     * -----------------------------------------------------
     */

    $skuStmt = $pdo->prepare(
        '
        SELECT
            id
        FROM products
        WHERE tenant_id = :tenant_id
          AND sku_normalized = :sku_normalized
        LIMIT 1
        '
    );

    $skuStmt->execute([
        ':tenant_id' => $tenantId,
        ':sku_normalized' => $skuNormalized
    ]);

    $existingProduct = $skuStmt->fetch();


    if ($existingProduct) {

        $pdo->rollBack();

        api_conflict(
            'A product with this SKU already exists.'
        );
    }


    /**
     * -----------------------------------------------------
     * Verify preferred supplier belongs to current tenant
     * -----------------------------------------------------
     */

    if ($preferredSupplierId !== null) {
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
     * Prepare reorder policy
     * -----------------------------------------------------
     */

    $reorderPolicyJson = null;

    if ($reorderPolicy !== null) {

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
    }


    /**
     * -----------------------------------------------------
     * Insert product
     * -----------------------------------------------------
     */

    $insertStmt = $pdo->prepare(
        '
        INSERT INTO products (
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
            status
        )
        VALUES (
            :tenant_id,
            :sku,
            :sku_normalized,
            :name,
            :description,
            :category_id,
            :unit_id,
            :brand,
            :barcode,
            :purchase_price,
            :selling_price,
            :tax_rate,
            :preferred_supplier_id,
            :reorder_policy,
            :status
        )
        '
    );

    $insertStmt->execute([
        ':tenant_id' => $tenantId,
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
        ':status' => $status
    ]);


    $productId = (int) $pdo->lastInsertId();


    if ($productId <= 0) {

        throw new RuntimeException(
            'Product creation failed.'
        );
    }


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

    $after = $afterStmt->fetch();


    if (!$after) {

        throw new RuntimeException(
            'Created product could not be loaded.'
        );
    }


    /**
     * -----------------------------------------------------
     * Audit
     * -----------------------------------------------------
     *
     * Required event:
     *
     * product.created
     *
     * No before state because the product did not exist.
     */

    $auditId = audit_event(
        'product.created',
        'product',
        (string) $productId,
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
                'Product created successfully.',

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
                        $after['preferred_supplier_id'],
                    'reorder_policy' =>
                        $after['reorder_policy'],
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

    /**
     * Rollback transaction.
     */

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    /**
     * Duplicate SKU can still occur under a race condition.
     *
     * The database UNIQUE constraint remains the final
     * authority.
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
        'Product creation database error: ' .
        $e->getMessage()
    );


    api_database_error(
        'Unable to create the product.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    error_log(
        'Product creation error: ' .
        $e->getMessage()
    );


    api_server_error(
        'Unable to create the product.'
    );
}