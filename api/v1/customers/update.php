<?php
declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * Customer Master API - Update
 * PATCH /api/v1/customers/update.php?id={customer_id}
 */

require_once __DIR__ . '/../../../config/session.php';
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/csrf.php';

$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
if ($requestId === '' || strlen($requestId) > 100) {
    $requestId = bin2hex(random_bytes(16));
}
header('X-Request-ID: ' . $requestId);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH') {
    header('Allow: PATCH');
    api_error_response(405, 'METHOD_NOT_ALLOWED', 'Only PATCH requests are allowed.');
}

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    api_unauthenticated();
}

$userId = (int) $_SESSION['user_id'];
$tenantId = (int) $_SESSION['tenant_id'];
if ($userId <= 0 || $tenantId <= 0) {
    api_error_response(401, 'INVALID_SESSION', 'Your session is invalid. Please sign in again.');
}

requirePermission('customers.update');

if (!csrf_is_valid()) {
    api_error_response(403, 'CSRF_INVALID', 'Invalid or missing CSRF token.');
}

$customerId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($customerId === false || $customerId === null || $customerId <= 0) {
    api_error_response(422, 'INVALID_CUSTOMER_ID', 'Customer ID must be a positive integer.');
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    api_invalid_request('Request body is required.');
}

try {
    $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    api_error_response(400, 'INVALID_JSON', 'Invalid JSON request body.');
}

if (!is_array($data) || array_is_list($data)) {
    api_error_response(400, 'INVALID_JSON', 'Request body must be a JSON object.');
}

$allowedFields = ['name', 'code', 'contact_name', 'phone', 'email', 'status'];
$unknownFields = array_diff(array_keys($data), $allowedFields);
if ($unknownFields) {
    api_error_response(422, 'UNKNOWN_FIELDS', 'One or more fields are not allowed.', [
        'fields' => array_values($unknownFields),
    ]);
}
if (!$data) {
    api_error_response(422, 'NO_FIELDS_TO_UPDATE', 'At least one customer field is required.');
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, name, name_normalized, code, contact_name, phone, email, status, created_at, updated_at
         FROM customers WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
    );
    $stmt->execute([':tenant_id' => $tenantId, ':id' => $customerId]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Customer update load error: ' . $e->getMessage());
    api_database_error('Unable to load the customer.');
}

if (!$before) {
    api_not_found('Customer not found.');
}

$name = (string) $before['name'];
$nameNormalized = (string) $before['name_normalized'];
$code = $before['code'];
$contactName = $before['contact_name'];
$phone = $before['phone'];
$email = $before['email'];
$status = (string) $before['status'];

if (array_key_exists('name', $data)) {
    if (!is_string($data['name'])) {
        api_error_response(422, 'INVALID_NAME', 'Customer name must be a string.');
    }
    $name = trim($data['name']);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150) {
        api_error_response(422, 'INVALID_NAME', 'Customer name is required and cannot exceed 150 characters.');
    }
    $normalized = preg_replace('/\s+/u', ' ', $name);
    if ($normalized === null) {
        api_error_response(422, 'INVALID_NAME', 'Customer name contains invalid characters.');
    }
    $nameNormalized = mb_strtolower(trim($normalized), 'UTF-8');
}

$optionalFields = [
    'code' => ['max' => 100, 'error' => 'INVALID_CODE', 'label' => 'Customer code'],
    'contact_name' => ['max' => 150, 'error' => 'INVALID_CONTACT_NAME', 'label' => 'Contact name'],
    'phone' => ['max' => 50, 'error' => 'INVALID_PHONE', 'label' => 'Phone'],
];

foreach ($optionalFields as $field => $rules) {
    if (!array_key_exists($field, $data)) {
        continue;
    }
    $value = $data[$field];
    if ($value !== null && !is_string($value)) {
        api_error_response(422, $rules['error'], $rules['label'] . ' must be a string or null.');
    }
    $value = $value === null ? null : trim($value);
    if ($value === '') {
        $value = null;
    }
    if ($value !== null && mb_strlen($value, 'UTF-8') > $rules['max']) {
        api_error_response(422, $rules['error'], $rules['label'] . ' exceeds the maximum length.');
    }
    if ($field === 'code') {
        $code = $value;
    } elseif ($field === 'contact_name') {
        $contactName = $value;
    } else {
        $phone = $value;
    }
}

if (array_key_exists('email', $data)) {
    if ($data['email'] !== null && !is_string($data['email'])) {
        api_error_response(422, 'INVALID_EMAIL', 'Email must be a string or null.');
    }
    $email = $data['email'] === null ? null : strtolower(trim($data['email']));
    if ($email === '') {
        $email = null;
    }
    if ($email !== null && (mb_strlen($email, 'UTF-8') > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        api_error_response(422, 'INVALID_EMAIL', 'Please provide a valid customer email address.');
    }
}

if (array_key_exists('status', $data)) {
    if (!is_string($data['status'])) {
        api_error_response(422, 'INVALID_STATUS', 'Status must be active or inactive.');
    }
    $status = trim($data['status']);
    if (!in_array($status, ['active', 'inactive'], true)) {
        api_error_response(422, 'INVALID_STATUS', 'Status must be active or inactive.');
    }
}

try {
    $pdo->beginTransaction();

    $duplicateStmt = $pdo->prepare(
        'SELECT id FROM customers
         WHERE tenant_id = :tenant_id AND name_normalized = :name_normalized AND id <> :id
         LIMIT 1'
    );
    $duplicateStmt->execute([
        ':tenant_id' => $tenantId,
        ':name_normalized' => $nameNormalized,
        ':id' => $customerId,
    ]);
    if ($duplicateStmt->fetchColumn() !== false) {
        $pdo->rollBack();
        api_conflict('A customer with this name already exists.');
    }

    if ($code !== null) {
        $codeStmt = $pdo->prepare(
            'SELECT id FROM customers WHERE tenant_id = :tenant_id AND code = :code AND id <> :id LIMIT 1'
        );
        $codeStmt->execute([':tenant_id' => $tenantId, ':code' => $code, ':id' => $customerId]);
        if ($codeStmt->fetchColumn() !== false) {
            $pdo->rollBack();
            api_conflict('A customer with this code already exists.');
        }
    }

    $updateStmt = $pdo->prepare(
        'UPDATE customers SET
            name = :name,
            name_normalized = :name_normalized,
            code = :code,
            contact_name = :contact_name,
            phone = :phone,
            email = :email,
            status = :status
         WHERE tenant_id = :tenant_id AND id = :id'
    );
    $updateStmt->execute([
        ':name' => $name,
        ':name_normalized' => $nameNormalized,
        ':code' => $code,
        ':contact_name' => $contactName,
        ':phone' => $phone,
        ':email' => $email,
        ':status' => $status,
        ':tenant_id' => $tenantId,
        ':id' => $customerId,
    ]);

    $reloadStmt = $pdo->prepare(
        'SELECT id, tenant_id, name, code, contact_name, phone, email, status, created_at, updated_at
         FROM customers WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
    );
    $reloadStmt->execute([':tenant_id' => $tenantId, ':id' => $customerId]);
    $after = $reloadStmt->fetch(PDO::FETCH_ASSOC);

    if (!$after) {
        throw new RuntimeException('Updated customer could not be loaded.');
    }

    $auditId = audit_event('customer.updated', 'customer', (string) $customerId, $before, $after);
    $pdo->commit();

    api_success_response([
        'message' => 'Customer updated successfully.',
        'customer' => $after,
        'audit_id' => $auditId,
        'request_id' => $requestId,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
        api_conflict('A customer with the same name or code already exists.');
    }
    error_log('Customer update database error: ' . $e->getMessage());
    api_database_error('Unable to update the customer.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Customer update error: ' . $e->getMessage());
    api_server_error('Unable to update the customer.');
}
