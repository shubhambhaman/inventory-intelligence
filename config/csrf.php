<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * CSRF Protection
 *
 * Responsibility:
 * - Generate one CSRF token per session
 * - Return the current session CSRF token
 * - Validate submitted CSRF tokens
 * - Use timing-safe comparison
 * - Never accept CSRF tokens from server-side configuration
 */


/**
 * ---------------------------------------------------------
 * CSRF configuration
 * ---------------------------------------------------------
 */

const CSRF_SESSION_KEY = '_csrf_token';

const CSRF_TOKEN_BYTES = 32;


/**
 * ---------------------------------------------------------
 * Ensure a PHP session is available.
 * ---------------------------------------------------------
 */
function csrf_ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException(
            'Unable to initialize session for CSRF protection.'
        );
    }
}


/**
 * ---------------------------------------------------------
 * Get/create CSRF token.
 * ---------------------------------------------------------
 *
 * One token is maintained for the lifetime of the
 * current authenticated/browser session.
 */
function csrf_token(): string
{
    csrf_ensure_session();

    if (
        isset($_SESSION[CSRF_SESSION_KEY]) &&
        is_string($_SESSION[CSRF_SESSION_KEY]) &&
        preg_match(
            '/^[a-f0-9]{64}$/',
            $_SESSION[CSRF_SESSION_KEY]
        )
    ) {
        return $_SESSION[CSRF_SESSION_KEY];
    }

    try {

        $token = bin2hex(
            random_bytes(CSRF_TOKEN_BYTES)
        );

    } catch (Throwable $e) {

        throw new RuntimeException(
            'Unable to generate CSRF token.',
            0,
            $e
        );
    }

    $_SESSION[CSRF_SESSION_KEY] = $token;

    return $token;
}


/**
 * ---------------------------------------------------------
 * Rotate CSRF token.
 * ---------------------------------------------------------
 *
 * Useful after important authentication/session changes.
 */
function csrf_rotate_token(): string
{
    csrf_ensure_session();

    unset($_SESSION[CSRF_SESSION_KEY]);

    return csrf_token();
}


/**
 * ---------------------------------------------------------
 * Get submitted CSRF token.
 * ---------------------------------------------------------
 *
 * Supported sources:
 *
 * 1. X-CSRF-Token HTTP header
 * 2. csrf_token POST field
 *
 * JSON APIs should normally use the header.
 */
function csrf_submitted_token(): ?string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (
        is_string($headerToken) &&
        $headerToken !== ''
    ) {
        return trim($headerToken);
    }

    if (
        isset($_POST['csrf_token']) &&
        is_string($_POST['csrf_token']) &&
        $_POST['csrf_token'] !== ''
    ) {
        return trim($_POST['csrf_token']);
    }

    return null;
}


/**
 * ---------------------------------------------------------
 * Validate submitted CSRF token.
 * ---------------------------------------------------------
 */
function csrf_validate(?string $submittedToken = null): bool
{
    csrf_ensure_session();

    $sessionToken = $_SESSION[CSRF_SESSION_KEY] ?? null;

    if (
        !is_string($sessionToken) ||
        $sessionToken === ''
    ) {
        return false;
    }

    if ($submittedToken === null) {
        $submittedToken = csrf_submitted_token();
    }

    if (
        !is_string($submittedToken) ||
        $submittedToken === ''
    ) {
        return false;
    }

    /**
     * Expected token is exactly 64 hexadecimal characters.
     */
    if (!preg_match('/^[a-f0-9]{64}$/', $submittedToken)) {
        return false;
    }

    /**
     * timing-safe comparison prevents ordinary
     * string-comparison timing attacks.
     */
    return hash_equals(
        $sessionToken,
        $submittedToken
    );
}


/**
 * ---------------------------------------------------------
 * Require valid CSRF token.
 * ---------------------------------------------------------
 *
 * This function intentionally does not know anything
 * about the API error contract.
 *
 * It returns false instead of directly sending an API
 * response so callers can use the application's standard
 * error handler.
 */
function csrf_is_valid(): bool
{
    return csrf_validate();
}