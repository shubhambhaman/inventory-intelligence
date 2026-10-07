<?php

declare(strict_types=1);

/**
 * Central request context.
 *
 * Responsibility:
 * - Generate/read one request ID per HTTP request
 * - Reuse the same request ID throughout the application
 * - Validate externally supplied request IDs
 */

function app_request_id(): string
{
    static $requestId = null;

    if ($requestId !== null) {
        return $requestId;
    }

    /**
     * Reuse request ID supplied by trusted infrastructure/client
     * only when it matches our safe format.
     */
    $incomingRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

    if (
        is_string($incomingRequestId) &&
        preg_match(
            '/^[A-Za-z0-9._:-]{1,100}$/',
            $incomingRequestId
        )
    ) {
        $requestId = $incomingRequestId;

        return $requestId;
    }

    /**
     * Generate a cryptographically strong request ID.
     */
    try {
        $requestId = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        /**
         * Extremely unlikely fallback.
         */
        $requestId = uniqid('', true);
    }

    return $requestId;
}