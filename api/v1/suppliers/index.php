<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * Supplier Master API
 *
 * GET  /api/v1/suppliers/
 * POST /api/v1/suppliers/
 *
 * Responsibilities:
 * - List suppliers for authenticated tenant
 * - Create supplier for authenticated tenant
 * - Enforce tenant isolation
 * - Enforce suppliers.view / suppliers.create permissions
 * - Enforce CSRF on POST
 * - Validate supplier input
 * - Normalize supplier name for duplicate detection
 * - Enforce tenant-unique supplier code when supplied
 * - Record supplier.created audit event
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
 * Request ID
 * ---------------------------------------------------------
 */

$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

if (
    $requestId === ''
    || strlen($requestId) > 100
) {
    $requestId = bin2hex(random_bytes(16));
}

header('X-Request-ID: ' . $requestId);


/**
 * ---------------------------------------------------------
 * HTTP method
 * ---------------------------------------------------------
 */

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if (!in_array($method, ['GET', 'POST'], true)) {

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
    empty($_SESSION['authenticated'])
    || empty($_SESSION['user_id'])
    || empty($_SESSION['tenant_id'])
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

    requirePermission('suppliers.view');

} else {

    requirePermission('suppliers.create');
}


/**
 * ---------------------------------------------------------
 * CSRF
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
 * GET - List suppliers
 * =========================================================
 */

if ($method === 'GET') {

    /**
     * -----------------------------------------------------
     * Pagination
     * -----------------------------------------------------
     */

    $page = filter_input(
        INPUT_GET,
        'page',
        FILTER_VALIDATE_INT
    );

    $perPage = filter_input(
        INPUT_GET,
        'per_page',
        FILTER_VALIDATE_INT
    );

    $page = ($page !== false && $page !== null)
        ? (int) $page
        : 1;

    $perPage = ($perPage !== false && $perPage !== null)
        ? (int) $perPage
        : 25;


    if ($page < 1) {

        api_error_response(
            422,
            'INVALID_PAGE',
            'Page must be greater than or equal to 1.'
        );
    }


    if ($perPage < 1 || $perPage > 100) {

        api_error_response(
            422,
            'INVALID_PER_PAGE',
            'per_page must be between 1 and 100.'
        );
    }


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
        $status !== ''
        && !in_array(
            $status,
            ['active', 'inactive'],
            true
        )
    ) {

        api_error_response(
            422,
            'INVALID_STATUS',
            'Status must be active or inactive.'
        );
    }


    /**
     * -----------------------------------------------------
     * Build WHERE clause
     * -----------------------------------------------------
     */

    $where = [
        's.tenant_id = :tenant_id'
    ];

    $params = [
        ':tenant_id' => $tenantId
    ];


    if ($search !== '') {

        $where[] = '(
            s.name LIKE :search
            OR s.name_normalized LIKE :search_normalized
            OR s.code LIKE :search_code
            OR s.contact_name LIKE :search_contact
            OR s.phone LIKE :search_phone
            OR s.email LIKE :search_email
        )';

        $searchLike = '%' . $search . '%';

        $params[':search'] = $searchLike;
        $params[':search_normalized'] = $searchLike;
        $params[':search_code'] = $searchLike;
        $params[':search_contact'] = $searchLike;
        $params[':search_phone'] = $searchLike;
        $params[':search_email'] = $searchLike;
    }


    if ($status !== '') {

        $where[] = 's.status = :status';

        $params[':status'] = $status;
    }


    $whereSql = implode(
        ' AND ',
        $where
    );


    /**
     * -----------------------------------------------------
     * Count
     * -----------------------------------------------------
     */

    try {

        $countStmt = $pdo->prepare(
            "
            SELECT COUNT(*)
            FROM suppliers s
            WHERE {$whereSql}
            "
        );

        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

    } catch (Throwable $e) {

        error_log(
            'Supplier list count error: ' .
            $e->getMessage()
        );

        api_database_error(
            'Unable to load supplier count.'
        );
    }


    /**
     * -----------------------------------------------------
     * Pagination calculation
     * -----------------------------------------------------
     */

    $offset = ($page - 1) * $perPage;

    $totalPages = $total > 0
        ? (int) ceil($total / $perPage)
        : 0;


    /**
     * -----------------------------------------------------
     * Supplier list
     * -----------------------------------------------------
     */

    try {

        $sql = "
            SELECT
                s.id,
                s.name,
                s.code,
                s.contact_name,
                s.phone,
                s.email,
                s.lead_time_days,
                s.payment_terms_days,
                s.status,
                s.created_at,
                s.updated_at
            FROM suppliers s
            WHERE {$whereSql}
            ORDER BY s.name ASC, s.id ASC
            LIMIT :limit
            OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $key => $value) {

            $stmt->bindValue(
                $key,
                $value,
                PDO::PARAM_STR
            );
        }

        $stmt->bindValue(
            ':limit',
            $perPage,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $suppliers = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    } catch (Throwable $e) {

        error_log(
            'Supplier list database error: ' .
            $e->getMessage()
        );

        api_database_error(
            'Unable to load suppliers.'
        );
    }


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
                'items' => $suppliers,

                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages
                ]
            ],

            'request_id' => $requestId
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/**
 * =========================================================
 * POST - Create supplier
 * =========================================================
 */


/**
 * ---------------------------------------------------------
 * Read JSON body
 * ---------------------------------------------------------
 */

$rawBody = file_get_contents('php://input');

if (
    $rawBody === false
    || trim($rawBody) === ''
) {

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
    'code',
    'contact_name',
    'phone',
    'email',
    'lead_time_days',
    'payment_terms_days',
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
 * Name
 * ---------------------------------------------------------
 */

$name = trim(
    (string) ($data['name'] ?? '')
);


if ($name === '') {

    api_error_response(
        422,
        'INVALID_NAME',
        'Supplier name is required.'
    );
}


if (mb_strlen($name) > 150) {

    api_error_response(
        422,
        'INVALID_NAME',
        'Supplier name cannot exceed 150 characters.'
    );
}


/**
 * ---------------------------------------------------------
 * Normalize supplier name
 * ---------------------------------------------------------
 */

$nameNormalized = mb_strtolower(
    trim(
        preg_replace(
            '/\s+/u',
            ' ',
            $name
        )
    ),
    'UTF-8'
);


/**
 * ---------------------------------------------------------
 * Code
 * ---------------------------------------------------------
 */

$code = null;

if (
    array_key_exists('code', $data)
    && $data['code'] !== null
) {

    $code = trim(
        (string) $data['code']
    );

    if ($code === '') {
        $code = null;
    }

    if (
        $code !== null
        && mb_strlen($code) > 100
    ) {

        api_error_response(
            422,
            'INVALID_CODE',
            'Supplier code cannot exceed 100 characters.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Contact name
 * ---------------------------------------------------------
 */

$contactName = null;

if (
    array_key_exists('contact_name', $data)
    && $data['contact_name'] !== null
) {

    $contactName = trim(
        (string) $data['contact_name']
    );

    if ($contactName === '') {
        $contactName = null;
    }

    if (
        $contactName !== null
        && mb_strlen($contactName) > 150
    ) {

        api_error_response(
            422,
            'INVALID_CONTACT_NAME',
            'Contact name cannot exceed 150 characters.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Phone
 * ---------------------------------------------------------
 */

$phone = null;

if (
    array_key_exists('phone', $data)
    && $data['phone'] !== null
) {

    $phone = trim(
        (string) $data['phone']
    );

    if ($phone === '') {
        $phone = null;
    }

    if (
        $phone !== null
        && mb_strlen($phone) > 50
    ) {

        api_error_response(
            422,
            'INVALID_PHONE',
            'Phone cannot exceed 50 characters.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Email
 * ---------------------------------------------------------
 */

$email = null;

if (
    array_key_exists('email', $data)
    && $data['email'] !== null
) {

    $email = trim(
        strtolower(
            (string) $data['email']
        )
    );

    if ($email === '') {
        $email = null;
    }

    if (
        $email !== null
        && (
            mb_strlen($email) > 254
            || !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        )
    ) {

        api_error_response(
            422,
            'INVALID_EMAIL',
            'Please provide a valid supplier email address.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Lead time
 * ---------------------------------------------------------
 */

$leadTimeDays = null;

if (
    array_key_exists('lead_time_days', $data)
    && $data['lead_time_days'] !== null
    && $data['lead_time_days'] !== ''
) {

    if (
        !is_int($data['lead_time_days'])
        && !is_float($data['lead_time_days'])
        && !is_numeric($data['lead_time_days'])
    ) {

        api_error_response(
            422,
            'INVALID_LEAD_TIME',
            'Lead time must be a non-negative number.'
        );
    }

    $leadTimeDays = (float) $data['lead_time_days'];

    if ($leadTimeDays < 0) {

        api_error_response(
            422,
            'INVALID_LEAD_TIME',
            'Lead time cannot be negative.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Payment terms
 * ---------------------------------------------------------
 */

$paymentTermsDays = null;

if (
    array_key_exists('payment_terms_days', $data)
    && $data['payment_terms_days'] !== null
    && $data['payment_terms_days'] !== ''
) {

    if (
        filter_var(
            $data['payment_terms_days'],
            FILTER_VALIDATE_INT
        ) === false
    ) {

        api_error_response(
            422,
            'INVALID_PAYMENT_TERMS',
            'Payment terms must be a non-negative integer.'
        );
    }

    $paymentTermsDays = (int) $data['payment_terms_days'];

    if ($paymentTermsDays < 0) {

        api_error_response(
            422,
            'INVALID_PAYMENT_TERMS',
            'Payment terms cannot be negative.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Status
 * ---------------------------------------------------------
 */

$status = 'active';

if (
    array_key_exists('status', $data)
    && $data['status'] !== null
) {

    $status = trim(
        (string) $data['status']
    );

    if (
        !in_array(
            $status,
            ['active', 'inactive'],
            true
        )
    ) {

        api_error_response(
            422,
            'INVALID_STATUS',
            'Status must be active or inactive.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Duplicate / create transaction
 * ---------------------------------------------------------
 */

try {

    $pdo->beginTransaction();


    /**
     * -----------------------------------------------------
     * Duplicate normalized name
     * -----------------------------------------------------
     */

    $duplicateStmt = $pdo->prepare(
        "
        SELECT id
        FROM suppliers
        WHERE tenant_id = :tenant_id
          AND name_normalized = :name_normalized
        LIMIT 1
        "
    );

    $duplicateStmt->execute(
        [
            ':tenant_id' => $tenantId,
            ':name_normalized' => $nameNormalized
        ]
    );

    $existingSupplierId = $duplicateStmt->fetchColumn();

    if ($existingSupplierId !== false) {

        $pdo->rollBack();

        api_error_response(
            409,
            'DUPLICATE_SUPPLIER',
            'A supplier with this name already exists.'
        );
    }


    /**
     * -----------------------------------------------------
     * Duplicate code
     * -----------------------------------------------------
     */

    if ($code !== null) {

        $codeStmt = $pdo->prepare(
            "
            SELECT id
            FROM suppliers
            WHERE tenant_id = :tenant_id
              AND code = :code
            LIMIT 1
            "
        );

        $codeStmt->execute(
            [
                ':tenant_id' => $tenantId,
                ':code' => $code
            ]
        );

        $existingCodeId = $codeStmt->fetchColumn();

        if ($existingCodeId !== false) {

            $pdo->rollBack();

            api_error_response(
                409,
                'DUPLICATE_SUPPLIER_CODE',
                'A supplier with this code already exists.'
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Insert supplier
     * -----------------------------------------------------
     */

    $insertStmt = $pdo->prepare(
        "
        INSERT INTO suppliers (
            tenant_id,
            name,
            name_normalized,
            code,
            contact_name,
            phone,
            email,
            lead_time_days,
            payment_terms_days,
            status
        ) VALUES (
            :tenant_id,
            :name,
            :name_normalized,
            :code,
            :contact_name,
            :phone,
            :email,
            :lead_time_days,
            :payment_terms_days,
            :status
        )
        "
    );

    $insertStmt->execute(
        [
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':name_normalized' => $nameNormalized,
            ':code' => $code,
            ':contact_name' => $contactName,
            ':phone' => $phone,
            ':email' => $email,
            ':lead_time_days' => $leadTimeDays,
            ':payment_terms_days' => $paymentTermsDays,
            ':status' => $status
        ]
    );


    $supplierId = (int) $pdo->lastInsertId();


    /**
     * -----------------------------------------------------
     * Load created supplier
     * -----------------------------------------------------
     */

    $supplierStmt = $pdo->prepare(
        "
        SELECT
            id,
            name,
            code,
            contact_name,
            phone,
            email,
            lead_time_days,
            payment_terms_days,
            status,
            created_at,
            updated_at
        FROM suppliers
        WHERE tenant_id = :tenant_id
          AND id = :id
        LIMIT 1
        "
    );

    $supplierStmt->execute(
        [
            ':tenant_id' => $tenantId,
            ':id' => $supplierId
        ]
    );

    $supplier = $supplierStmt->fetch(
        PDO::FETCH_ASSOC
    );


    if (!$supplier) {

        throw new RuntimeException(
            'Created supplier could not be loaded.'
        );
    }


    /**
     * -----------------------------------------------------
     * Audit
     * -----------------------------------------------------
     */

    $auditId = audit_event(
        'supplier.created',
        'supplier',
        (string) $supplierId,
        null,
        $supplier
    );


    /**
     * -----------------------------------------------------
     * Commit
     * -----------------------------------------------------
     */

    $pdo->commit();


    /**
     * -----------------------------------------------------
     * Response
     * -----------------------------------------------------
     */

    http_response_code(201);

    echo json_encode(
        [
            'success' => true,
            'message' => 'Supplier created successfully.',
            'data' => [
                'supplier' => $supplier,
                'audit_id' => $auditId,
                'request_id' => $requestId
            ]
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;


} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    /**
     * MariaDB duplicate-key protection.
     */

    if (
        isset($e->errorInfo[1])
        && (int) $e->errorInfo[1] === 1062
    ) {

        api_error_response(
            409,
            'DUPLICATE_SUPPLIER',
            'A supplier with the same name or code already exists.'
        );
    }


    error_log(
        'Supplier create database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to create the supplier.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    error_log(
        'Supplier create error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to create the supplier.'
    );
}