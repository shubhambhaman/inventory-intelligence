<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * Centralized Application Logger
 *
 * Responsibility:
 * - Provide one logging entry point for the application
 * - Write structured JSON logs
 * - Include request correlation ID
 * - Prevent sensitive values from being logged
 * - Keep technical details out of API responses
 */

require_once __DIR__ . '/audit.php';


/**
 * ---------------------------------------------------------
 * Log levels
 * ---------------------------------------------------------
 */

const LOG_LEVEL_DEBUG = 'DEBUG';
const LOG_LEVEL_INFO  = 'INFO';
const LOG_LEVEL_WARN  = 'WARN';
const LOG_LEVEL_ERROR = 'ERROR';


/**
 * ---------------------------------------------------------
 * Get log directory
 * ---------------------------------------------------------
 */

function app_log_directory(): string
{
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR
        . 'storage' . DIRECTORY_SEPARATOR
        . 'logs';

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(
                'Unable to create application log directory.'
            );
        }
    }

    return $directory;
}


/**
 * ---------------------------------------------------------
 * Get current log file
 * ---------------------------------------------------------
 */

function app_log_file(): string
{
    return app_log_directory()
        . DIRECTORY_SEPARATOR
        . 'application-' . date('Y-m-d') . '.log';
}


/**
 * ---------------------------------------------------------
 * Sanitize log context
 * ---------------------------------------------------------
 *
 * Never write credentials/secrets/tokens/passwords
 * into application logs.
 */

function app_log_sanitize(mixed $data): mixed
{
    if (!is_array($data)) {
        return $data;
    }

    $sensitiveKeys = [
        'password',
        'password_hash',
        'current_password',
        'new_password',
        'confirm_password',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'secret',
        'client_secret',
        'authorization',
        'cookie',
        'session_id',
        'php_session_id',
    ];

    $sanitized = [];

    foreach ($data as $key => $value) {

        $normalizedKey = strtolower(
            str_replace(
                ['-', ' '],
                '_',
                (string) $key
            )
        );

        if (in_array(
            $normalizedKey,
            $sensitiveKeys,
            true
        )) {
            $sanitized[$key] = '[REDACTED]';
            continue;
        }

        $sanitized[$key] =
            app_log_sanitize($value);
    }

    return $sanitized;
}


/**
 * ---------------------------------------------------------
 * Write application log
 * ---------------------------------------------------------
 *
 * @param string $level
 * @param string $message
 * @param array  $context
 */
function app_log(
    string $level,
    string $message,
    array $context = []
): void {

    $allowedLevels = [
        LOG_LEVEL_DEBUG,
        LOG_LEVEL_INFO,
        LOG_LEVEL_WARN,
        LOG_LEVEL_ERROR,
    ];

    $level = strtoupper(trim($level));

    if (!in_array($level, $allowedLevels, true)) {
        $level = LOG_LEVEL_INFO;
    }

    $message = trim($message);

    if ($message === '') {
        $message = 'Application log message is empty.';
    }


    /**
     * Request correlation.
     */
    try {
        $requestId = audit_get_request_id();
    } catch (Throwable $e) {
        $requestId = null;
    }


    /**
     * Authenticated context.
     *
     * Read-only context.
     * Never trust these values as authorization.
     */
    $userId = null;
    $tenantId = null;

    if (session_status() === PHP_SESSION_ACTIVE) {

        if (!empty($_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
        }

        if (!empty($_SESSION['tenant_id'])) {
            $tenantId = (int) $_SESSION['tenant_id'];
        }
    }


    /**
     * Build structured log record.
     */
    $record = [
        'timestamp' => date('c'),
        'level' => $level,
        'message' => $message,
        'request_id' => $requestId,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'context' => app_log_sanitize($context),
    ];


    /**
     * Encode as one JSON object per line.
     */
    try {

        $line = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );

    } catch (Throwable $e) {

        /**
         * Logging itself must never break the application.
         */
        $line = json_encode([
            'timestamp' => date('c'),
            'level' => LOG_LEVEL_ERROR,
            'message' => 'Application logging failed.',
            'request_id' => $requestId,
        ]);
    }


    /**
     * Write to daily log file.
     */
    $result = @file_put_contents(
        app_log_file(),
        $line . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );


    /**
     * Last-resort fallback.
     *
     * If application file logging fails,
     * PHP's error_log remains available.
     */
    if ($result === false) {

        error_log(
            '[Inventory Intelligence] ' . $line
        );
    }
}


/**
 * ---------------------------------------------------------
 * Convenience methods
 * ---------------------------------------------------------
 */

function app_log_debug(
    string $message,
    array $context = []
): void {
    app_log(
        LOG_LEVEL_DEBUG,
        $message,
        $context
    );
}


function app_log_info(
    string $message,
    array $context = []
): void {
    app_log(
        LOG_LEVEL_INFO,
        $message,
        $context
    );
}


function app_log_warning(
    string $message,
    array $context = []
): void {
    app_log(
        LOG_LEVEL_WARN,
        $message,
        $context
    );
}


function app_log_error(
    string $message,
    array $context = []
): void {
    app_log(
        LOG_LEVEL_ERROR,
        $message,
        $context
    );
}