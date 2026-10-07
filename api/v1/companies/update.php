<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * PATCH /api/v1/companies/update.php
 *
 * Responsibility:
 * - Update the company belonging to the authenticated tenant
 * - Enforce authentication
 * - Enforce company.update permission
 * - Validate company settings
 * - Never trust tenant_id from the browser
 * - Record company.updated audit event
 * - Return the centralized API Error Contract
 */

session_start();

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
 *
 * Tenant context MUST come from the authenticated session.
 * Never accept tenant_id from the request body.
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
 * Do not check $_SESSION['role'] directly.
 *
 * Permission must be resolved through the centralized
 * RBAC system.
 */

requirePermission('company.update');

/**
 * ---------------------------------------------------------
 * CSRF protection
 * ---------------------------------------------------------
 *
 * Required for browser/session based mutations.
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
 * Read JSON request body
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

$data = json_decode($rawBody, true);

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
    'legal_name',
    'country',
    'currency',
    'timezone',
    'fiscal_year_start'
];


$providedFields = array_keys($data);


/**
 * ---------------------------------------------------------
 * Unknown fields
 * ---------------------------------------------------------
 */

$unknownFields = array_diff(
    $providedFields,
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
 * At least one field required
 * ---------------------------------------------------------
 */

if (empty($providedFields)) {

    api_error_response(
        422,
        'NO_FIELDS',
        'At least one company field must be provided.'
    );
}


/**
 * ---------------------------------------------------------
 * Validation errors
 * ---------------------------------------------------------
 */

$errors = [];


/**
 * ---------------------------------------------------------
 * Company name
 * ---------------------------------------------------------
 *
 * Required when supplied.
 * Minimum 2 characters.
 * Maximum 150 characters.
 */

if (array_key_exists('name', $data)) {

    $name = trim((string) $data['name']);

    if ($name === '') {

        $errors['name'] =
            'Company name cannot be empty.';

    } elseif (mb_strlen($name) < 2) {

        $errors['name'] =
            'Company name must be at least 2 characters.';

    } elseif (mb_strlen($name) > 150) {

        $errors['name'] =
            'Company name cannot exceed 150 characters.';
    }
}


/**
 * ---------------------------------------------------------
 * Legal name
 * ---------------------------------------------------------
 *
 * NULL / empty string means no legal name.
 */

$legalName = null;

if (array_key_exists('legal_name', $data)) {

    if (
        $data['legal_name'] === null ||
        trim((string) $data['legal_name']) === ''
    ) {

        $legalName = null;

    } else {

        $legalName = trim(
            (string) $data['legal_name']
        );

        if (mb_strlen($legalName) > 150) {

            $errors['legal_name'] =
                'Legal name cannot exceed 150 characters.';
        }
    }
}


/**
 * ---------------------------------------------------------
 * Country
 * ---------------------------------------------------------
 *
 * V1 currently supports India.
 */

$country = null;

if (array_key_exists('country', $data)) {

    $country = trim(
        (string) $data['country']
    );

    if ($country === '') {

        $errors['country'] =
            'Country is required.';

    } elseif ($country !== 'India') {

        $errors['country'] =
            'Only India is currently supported in V1.';
    }
}


/**
 * ---------------------------------------------------------
 * Currency
 * ---------------------------------------------------------
 *
 * V1 currently supports INR.
 */

$currency = null;

if (array_key_exists('currency', $data)) {

    $currency = strtoupper(
        trim((string) $data['currency'])
    );

    if ($currency === '') {

        $errors['currency'] =
            'Currency is required.';

    } elseif ($currency !== 'INR') {

        $errors['currency'] =
            'Only INR is currently supported in V1.';
    }
}


/**
 * ---------------------------------------------------------
 * Timezone
 * ---------------------------------------------------------
 *
 * Must be a valid IANA timezone.
 */

$timezone = null;

if (array_key_exists('timezone', $data)) {

    $timezone = trim(
        (string) $data['timezone']
    );

    if ($timezone === '') {

        $errors['timezone'] =
            'Timezone is required.';

    } else {

        try {

            new DateTimeZone($timezone);

        } catch (Exception $e) {

            $errors['timezone'] =
                'Invalid IANA timezone.';
        }
    }
}


/**
 * ---------------------------------------------------------
 * Fiscal year start
 * ---------------------------------------------------------
 *
 * Stored as DATE.
 * Expected format:
 *
 * YYYY-MM-DD
 */

$fiscalYearStart = null;

if (array_key_exists('fiscal_year_start', $data)) {

    $fiscalYearStart = trim(
        (string) $data['fiscal_year_start']
    );

    if ($fiscalYearStart === '') {

        $errors['fiscal_year_start'] =
            'Fiscal year start is required.';

    } else {

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $fiscalYearStart
        );

        $dateErrors =
            DateTimeImmutable::getLastErrors();

        $hasDateErrors = (
            is_array($dateErrors) &&
            (
                $dateErrors['warning_count'] > 0 ||
                $dateErrors['error_count'] > 0
            )
        );

        if (
            $date === false ||
            $hasDateErrors ||
            $date->format('Y-m-d') !== $fiscalYearStart
        ) {

            $errors['fiscal_year_start'] =
                'Fiscal year start must be a valid date in YYYY-MM-DD format.';
        }
    }
}


/**
 * ---------------------------------------------------------
 * Return validation errors
 * ---------------------------------------------------------
 */

if (!empty($errors)) {

    api_validation_error(
        $errors
    );
}


/**
 * ---------------------------------------------------------
 * Build dynamic UPDATE statement
 * ---------------------------------------------------------
 *
 * Only fields explicitly supplied by the client
 * are updated.
 */

$setParts = [];

$params = [
    ':tenant_id' => $tenantId
];


/**
 * Company name.
 */

if (array_key_exists('name', $data)) {

    $setParts[] =
        'name = :name';

    $params[':name'] =
        trim((string) $data['name']);
}


/**
 * Legal name.
 */

if (array_key_exists('legal_name', $data)) {

    $setParts[] =
        'legal_name = :legal_name';

    $params[':legal_name'] =
        $legalName;
}


/**
 * Country.
 */

if (array_key_exists('country', $data)) {

    $setParts[] =
        'country = :country';

    $params[':country'] =
        $country;
}


/**
 * Currency.
 */

if (array_key_exists('currency', $data)) {

    $setParts[] =
        'currency = :currency';

    $params[':currency'] =
        $currency;
}


/**
 * Timezone.
 */

if (array_key_exists('timezone', $data)) {

    $setParts[] =
        'timezone = :timezone';

    $params[':timezone'] =
        $timezone;
}


/**
 * Fiscal year start.
 */

if (array_key_exists('fiscal_year_start', $data)) {

    $setParts[] =
        'fiscal_year_start = :fiscal_year_start';

    $params[':fiscal_year_start'] =
        $fiscalYearStart;
}


/**
 * ---------------------------------------------------------
 * Safety check
 * ---------------------------------------------------------
 */

if (empty($setParts)) {

    api_error_response(
        422,
        'NO_FIELDS',
        'No valid fields were supplied.'
    );
}


/**
 * ---------------------------------------------------------
 * Database + Audit transaction
 * ---------------------------------------------------------
 *
 * Company update and audit record are atomic.
 *
 * If audit insertion fails:
 * - Company update is rolled back.
 * - No unaudited company mutation remains.
 */

try {

    $pdo->beginTransaction();


    /**
     * -----------------------------------------------------
     * Load BEFORE state
     * -----------------------------------------------------
     *
     * This snapshot is stored in audit_events.before_json.
     */
    $beforeStmt = $pdo->prepare(
        'SELECT
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
         LIMIT 1'
    );

    $beforeStmt->execute([
        ':tenant_id' => $tenantId
    ]);

    $before = $beforeStmt->fetch();


    if (!$before) {

        $pdo->rollBack();

        api_not_found(
            'Company could not be found.'
        );
    }


    /**
     * -----------------------------------------------------
     * Update ONLY the authenticated tenant.
     * -----------------------------------------------------
     *
     * tenant_id comes from the server-side session.
     */
    $sql = sprintf(
        'UPDATE tenants
         SET %s
         WHERE id = :tenant_id
         LIMIT 1',
        implode(', ', $setParts)
    );


    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);


    /**
     * -----------------------------------------------------
     * Load AFTER state
     * -----------------------------------------------------
     *
     * This snapshot is stored in audit_events.after_json.
     */
    $afterStmt = $pdo->prepare(
        'SELECT
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
         LIMIT 1'
    );


    $afterStmt->execute([
        ':tenant_id' => $tenantId
    ]);


    $after = $afterStmt->fetch();


    if (!$after) {

        throw new RuntimeException(
            'Company could not be loaded after update.'
        );
    }


    /**
     * -----------------------------------------------------
     * Record audit event
     * -----------------------------------------------------
     *
     * Required event:
     *
     * company.updated
     */
    $auditId = audit_event(
        'company.updated',
        'company',
        (string) $tenantId,
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
     * Successful response
     * -----------------------------------------------------
     */

    http_response_code(200);

    echo json_encode(
        [
            'success' => true,
            'message' =>
                'Company settings updated successfully.',
            'data' => [
                'company' => [
                    'id' =>
                        (int) $after['id'],

                    'name' =>
                        $after['name'],

                    'legal_name' =>
                        $after['legal_name'],

                    'country' =>
                        $after['country'],

                    'currency' =>
                        $after['currency'],

                    'timezone' =>
                        $after['timezone'],

                    'fiscal_year_start' =>
                        $after['fiscal_year_start'],

                    'status' =>
                        $after['status'],

                    'created_at' =>
                        $after['created_at'],

                    'updated_at' =>
                        $after['updated_at']
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
     * Rollback if transaction is still active.
     */
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    /**
     * Never expose technical DB details
     * to the browser.
     */
    error_log(
        'Company update database error: ' .
        $e->getMessage()
    );


    api_database_error(
        'Unable to update company information.'
    );


} catch (Throwable $e) {

    /**
     * Rollback if transaction is still active.
     */
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    /**
     * Never expose technical exception details
     * to the browser.
     */
    error_log(
        'Company update error: ' .
        $e->getMessage()
    );


    api_server_error(
        'Unable to update company information.'
    );
}