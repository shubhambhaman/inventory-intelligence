<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * Audit Service
 *
 * Responsibility:
 * - Record append-only audit events
 * - Automatically use authenticated tenant/user context
 * - Use centralized request correlation ID
 * - Store safe before/after snapshots
 * - Never store passwords, tokens, secrets or sensitive credentials
 */

require_once __DIR__ . '/request_context.php';

/*
 * Normal application requests load the configured database connection.
 * An explicitly opted-in isolated test may inject its own verified PDO.
 */
if (
    !defined('IIP_AUDIT_SKIP_DB_BOOTSTRAP')
    || IIP_AUDIT_SKIP_DB_BOOTSTRAP !== true
) {
    require_once __DIR__ . '/db.php';
}

/** Return the current authenticated user ID. */
function audit_get_actor_id(): ?int
{
    if (
        empty($_SESSION['authenticated']) ||
        empty($_SESSION['user_id'])
    ) {
        return null;
    }

    $actorId = (int) $_SESSION['user_id'];

    return $actorId > 0 ? $actorId : null;
}

/**
 * Return the current tenant ID.
 * Tenant ID must come from the authenticated session, never browser input.
 */
function audit_get_tenant_id(): ?int
{
    if (empty($_SESSION['tenant_id'])) {
        return null;
    }

    $tenantId = (int) $_SESSION['tenant_id'];

    return $tenantId > 0 ? $tenantId : null;
}

/** Get the centralized request correlation ID. */
function audit_get_request_id(): string
{
    return app_request_id();
}

/** Remove sensitive fields recursively from audit data. */
function audit_sanitize_data(mixed $data): mixed
{
    if (!is_array($data)) {
        return $data;
    }

    $sensitiveKeys = [
        'password', 'password_hash', 'current_password', 'new_password',
        'confirm_password', 'database_password', 'db_password', 'passwd',
        'passphrase',
        'token', 'access_token', 'refresh_token', 'api_token', 'auth_token',
        'bearer_token', 'session_token', 'id_token', 'csrf_token',
        'secret', 'client_secret', 'client_key', 'api_key', 'access_key',
        'private_key', 'secret_key', 'encryption_key', 'signing_key',
        'authorization', 'cookie', 'set_cookie', 'session_id',
        'php_session_id', 'credential', 'credentials',
    ];

    $sanitized = [];

    foreach ($data as $key => $value) {
        $normalizedKey = strtolower(trim((string) $key));
        $normalizedKey = preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $normalizedKey
        );
        $normalizedKey = trim((string) $normalizedKey, '_');

        if (in_array($normalizedKey, $sensitiveKeys, true)) {
            $sanitized[$key] = '[REDACTED]';
            continue;
        }

        $sanitized[$key] = audit_sanitize_data($value);
    }

    return $sanitized;
}

/** Convert sanitized snapshot data into JSON; return NULL for no snapshot. */
function audit_encode_snapshot(mixed $data): ?string
{
    if ($data === null) {
        return null;
    }

    return json_encode(
        audit_sanitize_data($data),
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );
}

/** Redact credential-like values from free-text audit reasons. */
function audit_sanitize_reason(?string $reason): ?string
{
    if ($reason === null) {
        return null;
    }

    $reason = trim($reason);

    if ($reason === '') {
        return null;
    }

    // Preserve the Authorization Bearer scheme while hiding its credential.
    $reason = preg_replace(
        '~(\bAuthorization\s*:\s*)(Bearer\s+)\S+~i',
        '$1$2[REDACTED]',
        $reason
    ) ?? $reason;

    // Redact other Authorization header values.
    $reason = preg_replace(
        '~(\bAuthorization\s*:\s*)(?!Bearer\b)\S+~i',
        '$1[REDACTED]',
        $reason
    ) ?? $reason;

    // Authorization is handled separately above.
    $sensitiveNames =
        'password|passwd|passphrase|secret|token|access_token|refresh_token|' .
        'api_token|api_key|client_key|client_secret|secret_key|' .
        'private_key|cookie|set_cookie|session_id|php_session_id|' .
        'credential|credentials|access_key|encryption_key|signing_key|' .
        'auth_token|bearer_token|id_token|csrf_token|database_password|' .
        'db_password|password_hash|current_password|new_password|' .
        'confirm_password';

    // Redact double-quoted credential values.
    $quotedPattern = '~\b(' . $sensitiveNames .
        ')\b(\s*[:=]\s*)"[^"]*"~iu';

    $reason = preg_replace(
        $quotedPattern,
        '$1$2[REDACTED]',
        $reason
    ) ?? $reason;

    // Redact single-quoted and unquoted credential values.
    $pattern = '~\b(' . $sensitiveNames .
        ')\b(\s*[:=]\s*)(\'[^\']*\'|[^\s,;]+)~iu';

    $reason = preg_replace(
        $pattern,
        '$1$2[REDACTED]',
        $reason
    ) ?? $reason;

    // Redact standalone Bearer credentials.
    $reason = preg_replace(
        '~(\bBearer\s+)\S+~i',
        '$1[REDACTED]',
        $reason
    ) ?? $reason;

    return $reason;
}

/**
 * Record an audit event.
 * Tenant, actor and request IDs are derived server-side.
 *
 * @param string      $eventType
 * @param string      $entityType
 * @param string|int  $entityId
 * @param mixed       $before
 * @param mixed       $after
 * @param string|null $reason
 * @return int Audit event ID
 */
function audit_event(
    string $eventType,
    string $entityType,
    string|int $entityId,
    mixed $before = null,
    mixed $after = null,
    ?string $reason = null
): int {
    global $pdo;

    if (!($pdo instanceof PDO)) {
        throw new RuntimeException(
            'Unable to record audit event: database connection is unavailable.'
        );
    }

    $tenantId = audit_get_tenant_id();
    $actorId = audit_get_actor_id();

    if ($tenantId === null) {
        throw new RuntimeException(
            'Unable to record audit event: tenant context is missing.'
        );
    }

    if ($actorId === null) {
        throw new RuntimeException(
            'Unable to record audit event: actor context is missing.'
        );
    }

    $eventType = trim($eventType);
    $entityType = trim($entityType);
    $entityId = trim((string) $entityId);

    if ($eventType === '') {
        throw new InvalidArgumentException(
            'Audit event type is required.'
        );
    }

    if ($entityType === '') {
        throw new InvalidArgumentException(
            'Audit entity type is required.'
        );
    }

    if ($entityId === '') {
        throw new InvalidArgumentException(
            'Audit entity ID is required.'
        );
    }

    $beforeJson = audit_encode_snapshot($before);
    $afterJson = audit_encode_snapshot($after);
    $requestId = audit_get_request_id();
    $reason = audit_sanitize_reason($reason);

    $sql = "
        INSERT INTO audit_events (
            tenant_id,
            actor_id,
            event_type,
            entity_type,
            entity_id,
            before_json,
            after_json,
            reason,
            request_id
        )
        VALUES (
            :tenant_id,
            :actor_id,
            :event_type,
            :entity_type,
            :entity_id,
            :before_json,
            :after_json,
            :reason,
            :request_id
        )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':actor_id' => $actorId,
        ':event_type' => $eventType,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':before_json' => $beforeJson,
        ':after_json' => $afterJson,
        ':reason' => $reason,
        ':request_id' => $requestId,
    ]);

    return (int) $pdo->lastInsertId();
}
