<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * POST /api/v1/companies/
 *
 * Responsibility:
 * - Create a new tenant/company
 * - Create the first Owner user
 * - Establish authenticated session
 * - Record company.created audit event
 * - Return centralized API errors
 */

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
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

if (!csrf_is_valid()) {
    api_error_response(
        403,
        'CSRF_TOKEN_INVALID',
        'Invalid or missing CSRF token.'
    );
}
/**
 * ---------------------------------------------------------
 * Read JSON request
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
    'companyName',
    'fullName',
    'email',
    'password',
    'terms',
    'csrf_token'
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
 * Read input
 * ---------------------------------------------------------
 */

$companyName = trim(
    (string) ($data['companyName'] ?? '')
);

$fullName = trim(
    (string) ($data['fullName'] ?? '')
);

$email = strtolower(
    trim((string) ($data['email'] ?? ''))
);

$password = (string) ($data['password'] ?? '');

$termsAccepted = $data['terms'] ?? false;


/**
 * ---------------------------------------------------------
 * Validation
 * ---------------------------------------------------------
 */

$errors = [];


/**
 * Company name
 *
 * Source requirement:
 * 2–150 characters.
 */
if ($companyName === '') {

    $errors['companyName'] =
        'Company name is required.';

} elseif (mb_strlen($companyName) < 2) {

    $errors['companyName'] =
        'Company name must be at least 2 characters.';

} elseif (mb_strlen($companyName) > 150) {

    $errors['companyName'] =
        'Company name cannot exceed 150 characters.';
}


/**
 * Full name.
 */
if ($fullName === '') {

    $errors['fullName'] =
        'Full name is required.';

} elseif (mb_strlen($fullName) < 2) {

    $errors['fullName'] =
        'Full name must be at least 2 characters.';

} elseif (mb_strlen($fullName) > 150) {

    $errors['fullName'] =
        'Full name cannot exceed 150 characters.';
}


/**
 * Email.
 */
if ($email === '') {

    $errors['email'] =
        'Email is required.';

} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    $errors['email'] =
        'Please enter a valid email address.';

} elseif (mb_strlen($email) > 255) {

    $errors['email'] =
        'Email cannot exceed 255 characters.';
}


/**
 * Password.
 */
if ($password === '') {

    $errors['password'] =
        'Password is required.';

} elseif (mb_strlen($password) < 8) {

    $errors['password'] =
        'Password must be at least 8 characters.';
}


/**
 * Terms.
 */
if ($termsAccepted !== true) {

    $errors['termsAccepted'] =
        'You must accept the terms and conditions.';
}


/**
 * Return validation errors.
 */
if (!empty($errors)) {

    api_validation_error(
        $errors
    );
}


/**
 * ---------------------------------------------------------
 * Default company configuration
 * ---------------------------------------------------------
 *
 * V1 defaults:
 * - Country: India
 * - Currency: INR
 * - Timezone: Asia/Kolkata
 * - Fiscal year: 1 April
 */

$country = 'India';

$currency = 'INR';

$timezone = 'Asia/Kolkata';

$fiscalYearStart = date('Y') . '-04-01';

$status = 'active';

$role = 'owner';


/**
 * ---------------------------------------------------------
 * Password hashing
 * ---------------------------------------------------------
 */

$passwordHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);


if ($passwordHash === false) {

    api_server_error(
        'Unable to securely create the account.'
    );
}


/**
 * ---------------------------------------------------------
 * Transaction
 * ---------------------------------------------------------
 *
 * Company + first user + audit are one atomic operation.
 *
 * If audit fails:
 * - user is rolled back
 * - tenant is rolled back
 * - signup is not considered successful
 */

try {

    $pdo->beginTransaction();


    /**
     * -----------------------------------------------------
     * 1. Create tenant/company
     * -----------------------------------------------------
     */

    $tenantSql = "
        INSERT INTO tenants (
            name,
            legal_name,
            country,
            currency,
            timezone,
            fiscal_year_start,
            status
        )
        VALUES (
            :name,
            :legal_name,
            :country,
            :currency,
            :timezone,
            :fiscal_year_start,
            :status
        )
    ";

    $tenantStmt = $pdo->prepare(
        $tenantSql
    );

    $tenantStmt->execute([
        ':name' =>
            $companyName,

        ':legal_name' =>
            null,

        ':country' =>
            $country,

        ':currency' =>
            $currency,

        ':timezone' =>
            $timezone,

        ':fiscal_year_start' =>
            $fiscalYearStart,

        ':status' =>
            $status
    ]);


    $tenantId = (int) $pdo->lastInsertId();


    if ($tenantId <= 0) {

        throw new RuntimeException(
            'Tenant creation failed.'
        );
    }


    /**
     * -----------------------------------------------------
     * 2. Get Owner role
     * -----------------------------------------------------
     */

    $roleStmt = $pdo->prepare(
        "
        SELECT id
        FROM roles
        WHERE name = :role
        LIMIT 1
        "
    );

    $roleStmt->execute([
        ':role' => $role
    ]);

    $roleRow = $roleStmt->fetch();


    if (!$roleRow) {

        throw new RuntimeException(
            'Owner role is not configured.'
        );
    }


    $roleId = (int) $roleRow['id'];


    /**
     * -----------------------------------------------------
     * 3. Create first Owner user
     * -----------------------------------------------------
     */

    $userSql = "
        INSERT INTO users (
            tenant_id,
            email,
            name,
            password_hash,
            role,
            role_id,
            status
        )
        VALUES (
            :tenant_id,
            :email,
            :name,
            :password_hash,
            :role,
            :role_id,
            :status
        )
    ";

    $userStmt = $pdo->prepare(
        $userSql
    );

    $userStmt->execute([
        ':tenant_id' =>
            $tenantId,

        ':email' =>
            $email,

        ':name' =>
            $fullName,

        ':password_hash' =>
            $passwordHash,

        ':role' =>
            $role,

        ':role_id' =>
            $roleId,

        ':status' =>
            'active'
    ]);


    $userId = (int) $pdo->lastInsertId();


    if ($userId <= 0) {

        throw new RuntimeException(
            'Owner user creation failed.'
        );
    }


    /**
     * -----------------------------------------------------
     * 4. Load created company
     * -----------------------------------------------------
     *
     * This becomes the "after" snapshot for the audit.
     */

    $companyStmt = $pdo->prepare(
        "
        SELECT
            id,
            name,
            legal_name,
            country,
            currency,
            timezone,
            fiscal_year_start,
            status,
            created_at,
            updated_at
        FROM tenants
        WHERE id = :tenant_id
        LIMIT 1
        "
    );

    $companyStmt->execute([
        ':tenant_id' =>
            $tenantId
    ]);

    $company = $companyStmt->fetch();


    if (!$company) {

        throw new RuntimeException(
            'Created company could not be loaded.'
        );
    }


    /**
     * -----------------------------------------------------
     * 5. Establish server-side auth context
     * -----------------------------------------------------
     *
     * audit_event() gets actor_id and tenant_id
     * from the authenticated session.
     */


    if (!session_regenerate_id(true)) {
        throw new RuntimeException(
            'Unable to establish a secure session.'
        );
    }

    $_SESSION['authenticated'] = true;

    $_SESSION['user_id'] =
        $userId;

    $_SESSION['tenant_id'] =
        $tenantId;

    $_SESSION['role'] =
        $role;


    /**
     * -----------------------------------------------------
     * 6. Record company.created audit event
     * -----------------------------------------------------
     *
     * There is no "before" state because this company
     * did not exist before creation.
     */

    $auditId = audit_event(
        'company.created',
        'company',
        (string) $tenantId,
        null,
        $company
    );


    /**
     * -----------------------------------------------------
     * 7. Commit
     * -----------------------------------------------------
     */

    $pdo->commit();


    /**
     * -----------------------------------------------------
     * 8. Request ID
     * -----------------------------------------------------
     */

    $requestId =
        audit_get_request_id();


    header(
        'X-Request-ID: ' .
        $requestId
    );


    /**
     * -----------------------------------------------------
     * 9. Success response
     * -----------------------------------------------------
     */

    http_response_code(201);

    echo json_encode(
        [
            'success' => true,

            'message' =>
                'Company and owner account created successfully.',

            'data' => [

                'company' => [
                    'id' =>
                        (int) $company['id'],

                    'name' =>
                        $company['name'],

                    'legal_name' =>
                        $company['legal_name'],

                    'country' =>
                        $company['country'],

                    'currency' =>
                        $company['currency'],

                    'timezone' =>
                        $company['timezone'],

                    'fiscal_year_start' =>
                        $company['fiscal_year_start'],

                    'status' =>
                        $company['status']
                ],

                'user' => [
                    'id' =>
                        $userId,

                    'name' =>
                        $fullName,

                    'email' =>
                        $email,

                    'role' =>
                        $role
                ],

                'audit_id' =>
                    $auditId,

                'request_id' =>
                    $requestId
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
     * Clear authentication context because signup
     * was not committed successfully.
     */
    unset(
        $_SESSION['authenticated'],
        $_SESSION['user_id'],
        $_SESSION['tenant_id'],
        $_SESSION['role']
    );


    /**
     * Duplicate email within tenant is handled
     * as a conflict.
     */
    if ((int) $e->errorInfo[1] === 1062) {

        api_conflict(
            'An account with this email already exists.'
        );
    }


    /**
     * Never expose database details.
     */
    error_log(
        'Company creation database error: ' .
        $e->getMessage()
    );


    api_database_error(
        'Unable to create the company account.'
    );


} catch (Throwable $e) {

    /**
     * Rollback transaction.
     */
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    /**
     * Clear authentication context because signup
     * was not committed successfully.
     */
    unset(
        $_SESSION['authenticated'],
        $_SESSION['user_id'],
        $_SESSION['tenant_id'],
        $_SESSION['role']
    );


    /**
     * Never expose technical exception details.
     */
    error_log(
        'Company creation error: ' .
        $e->getMessage()
    );


    api_server_error(
        'Unable to create the company account.'
    );
}
