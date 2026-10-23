<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * Supplier Master API - Update
 *
 * PATCH /api/v1/suppliers/update.php?id={supplier_id}
 *
 * Responsibilities:
 * - Update supplier for authenticated tenant
 * - Enforce tenant isolation
 * - Enforce suppliers.update permission
 * - Enforce CSRF
 * - Validate supplier input
 * - Normalize supplier name
 * - Enforce tenant-unique supplier name/code
 * - Record supplier.updated audit event
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

if ($method !== 'PATCH') {

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

requirePermission('suppliers.update');


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
 * Supplier ID
 * ---------------------------------------------------------
 */

$supplierId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $supplierId === false
    || $supplierId === null
    || (int) $supplierId <= 0
) {

    api_error_response(
        422,
        'INVALID_SUPPLIER_ID',
        'Supplier ID must be a positive integer.'
    );
}

$supplierId = (int) $supplierId;


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


if (empty($data)) {

    api_error_response(
        422,
        'NO_FIELDS_TO_UPDATE',
        'At least one supplier field is required.'
    );
}


/**
 * ---------------------------------------------------------
 * Load existing supplier
 * ---------------------------------------------------------
 */

try {

    $supplierStmt = $pdo->prepare(
        "
        SELECT
            id,
            tenant_id,
            name,
            name_normalized,
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

    $before = $supplierStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'Supplier update load error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to load the supplier.'
    );
}


if (!$before) {

    api_error_response(
        404,
        'SUPPLIER_NOT_FOUND',
        'Supplier not found.'
    );
}


/**
 * ---------------------------------------------------------
 * Build updated values
 * ---------------------------------------------------------
 */

$name = $before['name'];
$nameNormalized = $before['name_normalized'];
$code = $before['code'];
$contactName = $before['contact_name'];
$phone = $before['phone'];
$email = $before['email'];
$leadTimeDays = $before['lead_time_days'];
$paymentTermsDays = $before['payment_terms_days'];
$status = $before['status'];


/**
 * ---------------------------------------------------------
 * Name
 * ---------------------------------------------------------
 */

if (array_key_exists('name', $data)) {

    $name = trim(
        (string) $data['name']
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
}


/**
 * ---------------------------------------------------------
 * Code
 * ---------------------------------------------------------
 */

if (array_key_exists('code', $data)) {

    if ($data['code'] === null) {

        $code = null;

    } else {

        $code = trim(
            (string) $data['code']
        );

        if ($code === '') {
            $code = null;
        }
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

if (array_key_exists('contact_name', $data)) {

    if ($data['contact_name'] === null) {

        $contactName = null;

    } else {

        $contactName = trim(
            (string) $data['contact_name']
        );

        if ($contactName === '') {
            $contactName = null;
        }
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

if (array_key_exists('phone', $data)) {

    if ($data['phone'] === null) {

        $phone = null;

    } else {

        $phone = trim(
            (string) $data['phone']
        );

        if ($phone === '') {
            $phone = null;
        }
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

if (array_key_exists('email', $data)) {

    if ($data['email'] === null) {

        $email = null;

    } else {

        $email = trim(
            strtolower(
                (string) $data['email']
            )
        );

        if ($email === '') {
            $email = null;
        }
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

if (array_key_exists('lead_time_days', $data)) {

    if (
        $data['lead_time_days'] === null
        || $data['lead_time_days'] === ''
    ) {

        $leadTimeDays = null;

    } else {

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
}


/**
 * ---------------------------------------------------------
 * Payment terms
 * ---------------------------------------------------------
 */

if (array_key_exists('payment_terms_days', $data)) {

    if (
        $data['payment_terms_days'] === null
        || $data['payment_terms_days'] === ''
    ) {

        $paymentTermsDays = null;

    } else {

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
}


/**
 * ---------------------------------------------------------
 * Status
 * ---------------------------------------------------------
 */

if (array_key_exists('status', $data)) {

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
 * Update transaction
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
          AND id <> :id
        LIMIT 1
        "
    );

    $duplicateStmt->execute(
        [
            ':tenant_id' => $tenantId,
            ':name_normalized' => $nameNormalized,
            ':id' => $supplierId
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
              AND id <> :id
            LIMIT 1
            "
        );

        $codeStmt->execute(
            [
                ':tenant_id' => $tenantId,
                ':code' => $code,
                ':id' => $supplierId
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
     * Update supplier
     * -----------------------------------------------------
     */

    $updateStmt = $pdo->prepare(
        "
        UPDATE suppliers
        SET
            name = :name,
            name_normalized = :name_normalized,
            code = :code,
            contact_name = :contact_name,
            phone = :phone,
            email = :email,
            lead_time_days = :lead_time_days,
            payment_terms_days = :payment_terms_days,
            status = :status
        WHERE tenant_id = :tenant_id
          AND id = :id
        "
    );

    $updateStmt->execute(
        [
            ':name' => $name,
            ':name_normalized' => $nameNormalized,
            ':code' => $code,
            ':contact_name' => $contactName,
            ':phone' => $phone,
            ':email' => $email,
            ':lead_time_days' => $leadTimeDays,
            ':payment_terms_days' => $paymentTermsDays,
            ':status' => $status,
            ':tenant_id' => $tenantId,
            ':id' => $supplierId
        ]
    );


    /**
     * -----------------------------------------------------
     * Load updated supplier
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

    $after = $supplierStmt->fetch(
        PDO::FETCH_ASSOC
    );


    if (!$after) {

        throw new RuntimeException(
            'Updated supplier could not be loaded.'
        );
    }


    /**
     * -----------------------------------------------------
     * Audit
     * -----------------------------------------------------
     */

    $auditId = audit_event(
        'supplier.updated',
        'supplier',
        (string) $supplierId,
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
     * Response
     * -----------------------------------------------------
     */

    http_response_code(200);

    echo json_encode(
        [
            'success' => true,
            'message' => 'Supplier updated successfully.',
            'data' => [
                'supplier' => $after,
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
        'Supplier update database error: ' .
        $e->getMessage()
    );

    api_database_error(
        'Unable to update the supplier.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    error_log(
        'Supplier update error: ' .
        $e->getMessage()
    );

    api_server_error(
        'Unable to update the supplier.'
    );
}