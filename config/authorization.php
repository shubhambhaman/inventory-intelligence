<?php

declare(strict_types=1);

/**
 * Inventory Intelligence
 * RBAC Authorization
 *
 * Responsibility:
 * - Identify the authenticated user
 * - Check whether the user has a permission
 * - Deny unauthorized API access
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/error_handler.php';


/**
 * Return the currently authenticated user's ID.
 *
 * @return int|null
 */
function getAuthenticatedUserId(): ?int
{
    if (
        empty($_SESSION['authenticated']) ||
        empty($_SESSION['user_id'])
    ) {
        return null;
    }

    return (int) $_SESSION['user_id'];
}


/**
 * Check whether the current authenticated user
 * has a specific permission.
 *
 * Example:
 * hasPermission('products.create')
 *
 * @param string $permissionKey
 * @return bool
 */
function hasPermission(string $permissionKey): bool
{
    global $pdo;

    $userId = getAuthenticatedUserId();

    if ($userId === null) {
        return false;
    }

    if ($permissionKey === '') {
        return false;
    }

    $sql = "
        SELECT 1
        FROM users u
        INNER JOIN roles r
            ON r.id = u.role_id
        INNER JOIN role_permissions rp
            ON rp.role_id = r.id
        INNER JOIN permissions p
            ON p.id = rp.permission_id
        INNER JOIN tenants t
            ON t.id = u.tenant_id
        WHERE u.id = :user_id
          AND u.status = 'active'
          AND t.status = 'active'
          AND p.permission_key = :permission_key
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':user_id' => $userId,
            ':permission_key' => $permissionKey,
        ]);

        return (bool) $stmt->fetchColumn();

    } catch (PDOException $e) {
        return false;
    }
}


/**
 * Require a specific permission.
 *
 * If the authenticated user does not have
 * the required permission, the request ends
 * with the centralized HTTP 403 error contract.
 *
 * Example:
 *
 * requirePermission('company.update');
 *
 * @param string $permissionKey
 * @return void
 */
function requirePermission(string $permissionKey): void
{
    if (hasPermission($permissionKey)) {
        return;
    }

    api_forbidden();
}