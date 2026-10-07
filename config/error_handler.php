<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * Central API Error Contract
 *
 * Responsibility:
 * - Standardize API error responses
 * - Prevent raw technical errors from reaching clients
 * - Provide consistent HTTP status + application error code
 */

/**
 * Send a standardized JSON API error response.
 *
 * @param int         $statusCode HTTP status code
 * @param string      $errorCode  Application-level error code
 * @param string      $message    Safe client-facing message
 * @param array|null  $fields     Optional validation field errors
 */
function api_error_response(
    int $statusCode,
    string $errorCode,
    string $message,
    ?array $fields = null
): never {

    http_response_code($statusCode);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    $error = [
        'code' => $errorCode,
        'message' => $message,
    ];

    if ($fields !== null && !empty($fields)) {
        $error['fields'] = $fields;
    }

    echo json_encode(
        [
            'success' => false,
            'error' => $error,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * Authentication error.
 */
function api_unauthenticated(
    string $message = 'Authentication is required.'
): never {

    api_error_response(
        401,
        'UNAUTHENTICATED',
        $message
    );
}

/**
 * Authorization error.
 */
function api_forbidden(
    string $message = 'You do not have permission to perform this action.'
): never {

    api_error_response(
        403,
        'FORBIDDEN',
        $message
    );
}

/**
 * Not found error.
 */
function api_not_found(
    string $message = 'The requested resource could not be found.'
): never {

    api_error_response(
        404,
        'NOT_FOUND',
        $message
    );
}

/**
 * Invalid request error.
 */
function api_invalid_request(
    string $message = 'Invalid request.'
): never {

    api_error_response(
        400,
        'INVALID_REQUEST',
        $message
    );
}

/**
 * Validation error.
 */
function api_validation_error(
    array $fields,
    string $message = 'Please correct the highlighted fields.'
): never {

    api_error_response(
        422,
        'VALIDATION_ERROR',
        $message,
        $fields
    );
}

/**
 * Conflict error.
 */
function api_conflict(
    string $message = 'The requested operation conflicts with the current state.'
): never {

    api_error_response(
        409,
        'CONFLICT',
        $message
    );
}

/**
 * Database error.
 *
 * Technical database details must NOT be exposed to the client.
 */
function api_database_error(
    string $message = 'A database error occurred.'
): never {

    api_error_response(
        500,
        'DATABASE_ERROR',
        $message
    );
}

/**
 * Generic server error.
 *
 * Technical exception details must NOT be exposed to the client.
 */
function api_server_error(
    string $message = 'An unexpected server error occurred.'
): never {

    api_error_response(
        500,
        'SERVER_ERROR',
        $message
    );
}