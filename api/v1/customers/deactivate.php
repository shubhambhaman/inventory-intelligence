<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/session.php';

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_error_response(405, 'METHOD_NOT_ALLOWED', 'Use POST to deactivate a customer.');
}

$userId = getAuthenticatedUserId();
if (!$userId) {
    api_unauthenticated();
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    api_forbidden('A valid tenant context is required.');
}

requirePermission('customers.update');

if (!csrf_is_valid()) {
    api_error_response(403, 'CSRF_VALIDATION_FAILED', 'CSRF validation failed.');
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id <= 0) {
    api_invalid_request('A valid customer id is required.');
}

try {
    $pdo->beginTransaction();

    $select = $pdo->prepare(
        'SELECT id, tenant_id, name, code, contact_name, phone, email, status, created_at, updated_at
         FROM customers
         WHERE id = :id AND tenant_id = :tenant_id
         LIMIT 1
         FOR UPDATE'
    );
    $select->execute([
        ':id' => $id,
        ':tenant_id' => $tenantId,
    ]);
    $customer = $select->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $pdo->rollBack();
        api_not_found('Customer not found.');
    }

    if ($customer['status'] === 'inactive') {
        $pdo->commit();
        api_success_response([
            'message' => 'Customer is already inactive.',
            'customer' => $customer,
            'audit_id' => null,
            'request_id' => audit_get_request_id(),
        ]);
    }

    $before = $customer;

    $update = $pdo->prepare(
        "UPDATE customers
         SET status = 'inactive', updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND tenant_id = :tenant_id AND status = 'active'"
    );
    $update->execute([
        ':id' => $id,
        ':tenant_id' => $tenantId,
    ]);

    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        api_conflict('Customer status changed before deactivation could complete.');
    }

    $fetchUpdated = $pdo->prepare(
        'SELECT id, tenant_id, name, code, contact_name, phone, email, status, created_at, updated_at
         FROM customers
         WHERE id = :id AND tenant_id = :tenant_id
         LIMIT 1'
    );
    $fetchUpdated->execute([
        ':id' => $id,
        ':tenant_id' => $tenantId,
    ]);
    $after = $fetchUpdated->fetch(PDO::FETCH_ASSOC);

    if (!$after) {
        throw new RuntimeException('Customer could not be reloaded after deactivation.');
    }

    $auditId = audit_event(
        'customer.updated',
        'customer',
        (string) $id,
        $before,
        $after,
        'Customer deactivated'
    );

    $pdo->commit();

    api_success_response([
        'message' => 'Customer deactivated successfully.',
        'customer' => $after,
        'audit_id' => $auditId,
        'request_id' => audit_get_request_id(),
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Customer deactivation failed. Customer ID: ' . (string) $id .
        '; Error: ' . $exception->getMessage()
    );

    api_database_error();
}
