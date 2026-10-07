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
require_once __DIR__ . '/db.php';


/**
 * ---------------------------------------------------------
 * Return the current authenticated user ID.
 * ---------------------------------------------------------
 */
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
 * ---------------------------------------------------------
 * Return the current tenant ID.
 * ---------------------------------------------------------
 *
 * Tenant ID MUST come from the authenticated session.
 * It must never be accepted from browser input.
 */
function audit_get_tenant_id(): ?int
{
    if (empty($_SESSION['tenant_id'])) {
        return null;
    }

    $tenantId = (int) $_SESSION['tenant_id'];

    return $tenantId > 0 ? $tenantId : null;
}


/**
 * ---------------------------------------------------------
 * Get the current request correlation ID.
 * ---------------------------------------------------------
 *
 * Request ID generation is centralized in:
 *
 * config/request_context.php
 *
 * This wrapper keeps the existing audit API unchanged.
 */
function audit_get_request_id(): string
{
    return app_request_id();
}


/**
 * ---------------------------------------------------------
 * Remove sensitive fields recursively from audit data.
 * ---------------------------------------------------------
 *
 * Passwords, tokens, secrets and credentials must never
 * be persisted in the audit trail.
 */
function audit_sanitize_data(mixed $data): mixed
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

        $sanitized[$key] = audit_sanitize_data($value);
    }

    return $sanitized;
}


/**
 * ---------------------------------------------------------
 * Convert audit snapshot data into JSON.
 * ---------------------------------------------------------
 *
 * Returns NULL when no snapshot is supplied.
 */
function audit_encode_snapshot(
    mixed $data
): ?string {

    if ($data === null) {
        return null;
    }

    $safeData = audit_sanitize_data($data);

    return json_encode(
        $safeData,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );
}


/**
 * ---------------------------------------------------------
 * Record an audit event.
 * ---------------------------------------------------------
 *
 * IMPORTANT:
 * - tenant_id is never accepted from the browser
 * - actor_id is never accepted from the browser
 * - request_id is generated/derived server-side
 *
 * @param string      $eventType
 * @param string      $entityType
 * @param string|int  $entityId
 * @param mixed       $before
 * @param mixed       $after
 * @param string|null $reason
 *
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

    $tenantId = audit_get_tenant_id();
    $actorId  = audit_get_actor_id();

    /**
     * Tenant context is mandatory.
     */
    if ($tenantId === null) {
        throw new RuntimeException(
            'Unable to record audit event: tenant context is missing.'
        );
    }

    /**
     * Actor context is mandatory for current V1 audit events.
     */
    if ($actorId === null) {
        throw new RuntimeException(
            'Unable to record audit event: actor context is missing.'
        );
    }


    /**
     * Normalize identifiers.
     */
    $eventType = trim($eventType);
    $entityType = trim($entityType);
    $entityId = trim((string) $entityId);


    /**
     * Validate event type.
     */
    if ($eventType === '') {
        throw new InvalidArgumentException(
            'Audit event type is required.'
        );
    }


    /**
     * Validate entity type.
     */
    if ($entityType === '') {
        throw new InvalidArgumentException(
            'Audit entity type is required.'
        );
    }


    /**
     * Validate entity ID.
     */
    if ($entityId === '') {
        throw new InvalidArgumentException(
            'Audit entity ID is required.'
        );
    }


    /**
     * Prepare safe before/after snapshots.
     */
    $beforeJson = audit_encode_snapshot($before);
    $afterJson  = audit_encode_snapshot($after);


    /**
     * Get centralized request ID.
     */
    $requestId = audit_get_request_id();


    /**
     * Normalize optional reason.
     */
    if ($reason !== null) {

        $reason = trim($reason);

        if ($reason === '') {
            $reason = null;
        }
    }


    /**
     * Insert immutable audit event.
     */
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
        ':tenant_id'   => $tenantId,
        ':actor_id'    => $actorId,
        ':event_type'  => $eventType,
        ':entity_type' => $entityType,
        ':entity_id'   => $entityId,
        ':before_json' => $beforeJson,
        ':after_json'  => $afterJson,
        ':reason'      => $reason,
        ':request_id'  => $requestId,
    ]);

    return (int) $pdo->lastInsertId();
}