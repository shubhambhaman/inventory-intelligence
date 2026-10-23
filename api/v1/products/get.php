<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * GET /api/v1/products/get.php?id={id}
 *
 * Responsibility:
 * - Return one product for the authenticated tenant
 * - Enforce products.view permission
 * - Enforce tenant isolation
 * - Return category and unit information
 * - Do not expose stock/transaction facts before M2 ledger exists
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';


/**
 * ---------------------------------------------------------
 * HTTP method
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    header('Allow: GET');

    api_error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Only GET requests are allowed.'
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

requirePermission('products.view');


/**
 * ---------------------------------------------------------
 * Product ID
 * ---------------------------------------------------------
 */

$productId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);


if ($productId === false || $productId === null) {

    api_error_response(
        422,
        'INVALID_PRODUCT_ID',
        'A valid product id is required.'
    );
}


/**
 * ---------------------------------------------------------
 * Load product
 * ---------------------------------------------------------
 */

try {

    $stmt = $pdo->prepare(
        '
        SELECT
            p.id,
            p.tenant_id,
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

        WHERE p.tenant_id = :tenant_id
          AND p.id = :product_id

        LIMIT 1
        '
    );


    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':product_id' => (int) $productId
    ]);


    $product = $stmt->fetch(PDO::FETCH_ASSOC);


    /**
     * -----------------------------------------------------
     * Not found
     * -----------------------------------------------------
     */

    if (!$product) {

        api_not_found(
            'Product not found.'
        );
    }


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
     * Response
     * -----------------------------------------------------
     */

    http_response_code(200);

    echo json_encode(
        [
            'success' => true,

            'data' => [
                'product' => [
                    'id' => (int) $product['id'],

                    'sku' => $product['sku'],

                    'name' => $product['name'],

                    'description' => $product['description'],

                    'category' => [
                        'id' => (int) $product['category_id'],
                        'name' => $product['category_name']
                    ],

                    'unit' => [
                        'id' => (int) $product['unit_id'],
                        'name' => $product['unit_name']
                    ],

                    'brand' => $product['brand'],

                    'barcode' => $product['barcode'],

                    'purchase_price' =>
                        $product['purchase_price'],

                    'selling_price' =>
                        $product['selling_price'],

                    'tax_rate' =>
                        $product['tax_rate'],

                    'preferred_supplier_id' =>
                        $product['preferred_supplier_id'] !== null
                            ? (int) $product['preferred_supplier_id']
                            : null,

                    'reorder_policy' =>
                        $product['reorder_policy'],

                    'status' =>
                        $product['status'],

                    'created_at' =>
                        $product['created_at'],

                    'updated_at' =>
                        $product['updated_at']
                ],

                /*
                 * Stock balances and transaction history
                 * will be attached after the M2 stock ledger
                 * becomes the authoritative source.
                 */
                'stock' => null,

                'transaction_history' => null
            ],

            'request_id' => $requestId
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;


} catch (PDOException $e) {

    error_log(
        'Product detail database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to load the product.'
    );


} catch (Throwable $e) {

    error_log(
        'Product detail error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to load the product.'
    );
}