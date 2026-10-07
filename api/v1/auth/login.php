<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * POST /api/v1/auth/login
 *
 * Authenticates a user and creates a secure PHP session.
 */

header('Content-Type: application/json; charset=utf-8');


/**
 * ---------------------------------------------------------
 * Dependencies
 * ---------------------------------------------------------
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/csrf.php';


/**
 * ---------------------------------------------------------
 * Secure session cookie configuration
 * ---------------------------------------------------------
 *
 * IMPORTANT:
 * This MUST happen before session_start().
 *
 * CSRF validation also depends on the PHP session,
 * therefore the session configuration must be completed
 * before CSRF validation is performed.
 */

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ),
    'httponly' => true,
    'samesite' => 'Lax'
]);


/**
 * ---------------------------------------------------------
 * Start session
 * ---------------------------------------------------------
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/**
 * ---------------------------------------------------------
 * JSON response helper
 * ---------------------------------------------------------
 */

function json_response(
    int $statusCode,
    array $data
): never {
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/**
 * ---------------------------------------------------------
 * HTTP method
 * ---------------------------------------------------------
 *
 * Only POST is allowed.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Allow: POST');

    json_response(405, [
        'success' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Only POST requests are allowed.'
        ]
    ]);
}


/**
 * ---------------------------------------------------------
 * CSRF protection
 * ---------------------------------------------------------
 *
 * Login is a browser/session mutation.
 *
 * The login page generates the CSRF token.
 * The frontend sends it in:
 *
 * X-CSRF-Token
 *
 * and also inside the JSON body.
 */

if (!csrf_is_valid()) {

    json_response(403, [
        'success' => false,
        'error' => [
            'code' => 'CSRF_TOKEN_INVALID',
            'message' => 'Invalid or missing CSRF token.'
        ]
    ]);
}


/**
 * ---------------------------------------------------------
 * Read JSON request body
 * ---------------------------------------------------------
 */

$rawBody = file_get_contents('php://input');

if (
    $rawBody === false ||
    trim($rawBody) === ''
) {
    json_response(400, [
        'success' => false,
        'error' => [
            'code' => 'INVALID_REQUEST',
            'message' => 'Request body is required.'
        ]
    ]);
}


/**
 * ---------------------------------------------------------
 * Decode JSON
 * ---------------------------------------------------------
 */

$data = json_decode(
    $rawBody,
    true
);

if (!is_array($data)) {

    json_response(400, [
        'success' => false,
        'error' => [
            'code' => 'INVALID_JSON',
            'message' => 'Invalid JSON request body.'
        ]
    ]);
}


/**
 * ---------------------------------------------------------
 * Extract credentials
 * ---------------------------------------------------------
 */

$email = trim(
    (string) (
        $data['email'] ?? ''
    )
);

$password = (string) (
    $data['password'] ?? ''
);


/**
 * ---------------------------------------------------------
 * Normalize email
 * ---------------------------------------------------------
 */

$email = strtolower($email);


/**
 * ---------------------------------------------------------
 * Validate credentials
 * ---------------------------------------------------------
 */

$errors = [];


/**
 * Email validation.
 */

if ($email === '') {

    $errors['email'] =
        'Email address is required.';

} elseif (!filter_var(
    $email,
    FILTER_VALIDATE_EMAIL
)) {

    $errors['email'] =
        'Enter a valid email address.';

} elseif (mb_strlen($email) > 255) {

    $errors['email'] =
        'Email address is too long.';
}


/**
 * Password validation.
 */

if ($password === '') {

    $errors['password'] =
        'Password is required.';
}


/**
 * Return validation errors.
 */

if (!empty($errors)) {

    json_response(422, [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' =>
                'Please enter your email and password.',
            'fields' => $errors
        ]
    ]);
}


/**
 * ---------------------------------------------------------
 * Generic authentication failure
 * ---------------------------------------------------------
 *
 * Do not reveal whether an email exists.
 */

$invalidCredentialsResponse = function (): never {

    json_response(401, [
        'success' => false,
        'error' => [
            'code' => 'INVALID_CREDENTIALS',
            'message' =>
                'Invalid email or password.'
        ]
    ]);
};


/**
 * ---------------------------------------------------------
 * Authenticate user
 * ---------------------------------------------------------
 */

try {

    /**
     * Find user account(s).
     *
     * Tenant ID is NOT accepted from the browser.
     *
     * Because users are unique by:
     *
     * UNIQUE (tenant_id, email)
     *
     * the same email may theoretically exist
     * in multiple tenants.
     */

    $stmt = $pdo->prepare(
        'SELECT
            u.id AS user_id,
            u.tenant_id,
            u.email,
            u.name AS user_name,
            u.password_hash,
            u.role,
            u.status AS user_status,

            t.name AS company_name,
            t.legal_name AS company_legal_name,
            t.country AS company_country,
            t.currency AS company_currency,
            t.timezone AS company_timezone,
            t.fiscal_year_start AS company_fiscal_year_start,
            t.status AS company_status

         FROM users u

         INNER JOIN tenants t
            ON t.id = u.tenant_id

         WHERE u.email = :email

         LIMIT 2'
    );


    $stmt->execute([
        ':email' => $email
    ]);


    $users = $stmt->fetchAll();


    /**
     * -----------------------------------------------------
     * No account found
     * -----------------------------------------------------
     */

    if (count($users) === 0) {

        $invalidCredentialsResponse();
    }


    /**
     * -----------------------------------------------------
     * Multiple tenant accounts
     * -----------------------------------------------------
     *
     * Current login UI does not ask the user
     * to select a company/workspace.
     *
     * Therefore we cannot safely choose one.
     */

    if (count($users) > 1) {

        json_response(409, [
            'success' => false,
            'error' => [
                'code' => 'ACCOUNT_AMBIGUOUS',
                'message' =>
                    'This email is associated with multiple companies. Please select your company before signing in.'
            ]
        ]);
    }


    $user = $users[0];


    /**
     * -----------------------------------------------------
     * User status
     * -----------------------------------------------------
     */

    if ($user['user_status'] !== 'active') {

        json_response(403, [
            'success' => false,
            'error' => [
                'code' => 'ACCOUNT_INACTIVE',
                'message' =>
                    'This user account is not active.'
            ]
        ]);
    }


    /**
     * -----------------------------------------------------
     * Company status
     * -----------------------------------------------------
     */

    if ($user['company_status'] !== 'active') {

        json_response(403, [
            'success' => false,
            'error' => [
                'code' => 'COMPANY_INACTIVE',
                'message' =>
                    'This company account is not active.'
            ]
        ]);
    }


    /**
     * -----------------------------------------------------
     * Verify password
     * -----------------------------------------------------
     */

    if (!password_verify(
        $password,
        $user['password_hash']
    )) {

        $invalidCredentialsResponse();
    }


    /**
     * -----------------------------------------------------
     * Password rehash
     * -----------------------------------------------------
     *
     * Automatically upgrade the password hash
     * when PHP recommends a stronger/current hash.
     */

    if (password_needs_rehash(
        $user['password_hash'],
        PASSWORD_DEFAULT
    )) {

        $newPasswordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if ($newPasswordHash !== false) {

            $rehashStmt = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash
                 WHERE id = :user_id
                 LIMIT 1'
            );

            $rehashStmt->execute([
                ':password_hash' =>
                    $newPasswordHash,

                ':user_id' =>
                    (int) $user['user_id']
            ]);
        }
    }


    /**
     * -----------------------------------------------------
     * Update last login timestamp
     * -----------------------------------------------------
     */

    $loginUpdateStmt = $pdo->prepare(
        'UPDATE users
         SET last_login_at = CURRENT_TIMESTAMP
         WHERE id = :user_id
         LIMIT 1'
    );

    $loginUpdateStmt->execute([
        ':user_id' =>
            (int) $user['user_id']
    ]);


    /**
     * -----------------------------------------------------
     * Prevent session fixation
     * -----------------------------------------------------
     *
     * The session was already started before CSRF validation.
     *
     * Regenerate the session ID after successful
     * authentication.
     */

    if (!session_regenerate_id(true)) {

        throw new RuntimeException(
            'Unable to establish a secure session.'
        );
    }


    /**
     * -----------------------------------------------------
     * Clear previous authentication context
     * -----------------------------------------------------
     */

    $_SESSION = [];


    /**
     * -----------------------------------------------------
     * Set authenticated server-side context
     * -----------------------------------------------------
     *
     * tenant_id comes from the database.
     *
     * NEVER accept tenant_id from the browser.
     */

    $_SESSION['authenticated'] = true;

    $_SESSION['user_id'] =
        (int) $user['user_id'];

    $_SESSION['tenant_id'] =
        (int) $user['tenant_id'];

    $_SESSION['role'] =
        $user['role'];


    /**
     * -----------------------------------------------------
     * Success response
     * -----------------------------------------------------
     */

    json_response(200, [

        'success' => true,

        'message' =>
            'Signed in successfully.',

        'data' => [

            'user' => [

                'id' =>
                    (int) $user['user_id'],

                'name' =>
                    $user['user_name'],

                'email' =>
                    $user['email'],

                'role' =>
                    $user['role']
            ],

            'company' => [

                'id' =>
                    (int) $user['tenant_id'],

                'name' =>
                    $user['company_name'],

                'legal_name' =>
                    $user['company_legal_name'],

                'country' =>
                    $user['company_country'],

                'currency' =>
                    $user['company_currency'],

                'timezone' =>
                    $user['company_timezone'],

                'fiscal_year_start' =>
                    $user['company_fiscal_year_start'],

                'status' =>
                    $user['company_status']
            ]
        ]
    ]);


} catch (PDOException $e) {

    /**
     * -----------------------------------------------------
     * Database error
     * -----------------------------------------------------
     *
     * Never expose database internals to the browser.
     */

    error_log(
        'Login database error: ' .
        $e->getMessage()
    );


    json_response(500, [

        'success' => false,

        'error' => [

            'code' =>
                'DATABASE_ERROR',

            'message' =>
                'Unable to sign in right now. Please try again.'
        ]
    ]);


} catch (Throwable $e) {

    /**
     * -----------------------------------------------------
     * General server error
     * -----------------------------------------------------
     */

    error_log(
        'Login error: ' .
        $e->getMessage()
    );


    json_response(500, [

        'success' => false,

        'error' => [

            'code' =>
                'SERVER_ERROR',

            'message' =>
                'Unable to sign in right now. Please try again.'
        ]
    ]);
}