<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/authorization.php';
require_once __DIR__ . '/../../../config/error_handler.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/csrf.php';


$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    /*
     * ---------------------------------------------------------
     * GET - List Customers
     * ---------------------------------------------------------
     */
    if ($method === 'GET') {
        requirePermission('customers.view');

        $tenantId = (int) ($_SESSION['tenant_id'] ?? 0);

        if ($tenantId <= 0) {
            api_error_response(
                500,
                'TENANT_CONTEXT_MISSING',
                'Tenant context is missing.'
            );
        }

        $page = max(
            1,
            (int) ($_GET['page'] ?? 1)
        );

        $perPage = (int) ($_GET['per_page'] ?? 20);

        $allowedPerPage = [20, 50, 100];

        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 20;
        }

        $search = trim(
            (string) ($_GET['search'] ?? '')
        );

        $status = trim(
            (string) ($_GET['status'] ?? '')
        );

        if (
            $status !== '' &&
            !in_array($status, ['active', 'inactive'], true)
        ) {
            api_error_response(
                422,
                'INVALID_STATUS',
                'Status must be active or inactive.'
            );
        }

        $where = [
            'tenant_id = :tenant_id'
        ];

        $params = [
            ':tenant_id' => $tenantId
        ];

        if ($search !== '') {
            $where[] = '(
                name_normalized LIKE :search
                OR code LIKE :search_code
                OR contact_name LIKE :search_contact
                OR phone LIKE :search_phone
                OR email LIKE :search_email
            )';

            $searchValue = '%' . mb_strtolower($search, 'UTF-8') . '%';

            $params[':search'] = $searchValue;
            $params[':search_code'] = '%' . $search . '%';
            $params[':search_contact'] = '%' . $search . '%';
            $params[':search_phone'] = '%' . $search . '%';
            $params[':search_email'] = '%' . $search . '%';
        }

        if ($status !== '') {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        /*
         * Total count
         */
        $countSql = "
            SELECT COUNT(*)
            FROM customers
            WHERE {$whereSql}
        ";

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

        $totalPages = $total > 0
            ? (int) ceil($total / $perPage)
            : 0;

        $offset = ($page - 1) * $perPage;

        /*
         * Customer list
         *
         * Explicit columns only.
         */
        $sql = "
            SELECT
                id,
                tenant_id,
                name,
                code,
                contact_name,
                phone,
                email,
                status,
                created_at,
                updated_at
            FROM customers
            WHERE {$whereSql}
            ORDER BY name ASC, id ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(
                $key,
                $value,
                PDO::PARAM_STR
            );
        }

        $stmt->bindValue(
            ':limit',
            $perPage,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $items = $stmt->fetchAll();

        api_success_response(
            [
                'items' => $items,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages
                ]
            ],
            200
        );

        exit;
    }

    /*
     * ---------------------------------------------------------
     * POST - Create Customer
     * ---------------------------------------------------------
     */
    if ($method === 'POST') {

        requirePermission('customers.create');

        if (!csrf_is_valid()) {
            api_error_response(
                403,
                'CSRF_INVALID',
                'Invalid or missing CSRF token.'
            );
        }

        $tenantId = (int) ($_SESSION['tenant_id'] ?? 0);

        if ($tenantId <= 0) {
            api_error_response(
                500,
                'TENANT_CONTEXT_MISSING',
                'Tenant context is missing.'
            );
        }

        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || trim($rawBody) === '') {
            api_error_response(
                400,
                'INVALID_JSON',
                'Request body is required.'
            );
        }

        $data = json_decode(
            $rawBody,
            true
        );

        if (
            !is_array($data) ||
            json_last_error() !== JSON_ERROR_NONE
        ) {
            api_error_response(
                400,
                'INVALID_JSON',
                'Request body must contain valid JSON.'
            );
        }

        /*
         * -----------------------------------------------------
         * Read fields
         * -----------------------------------------------------
         */
        $name = trim(
            (string) ($data['name'] ?? '')
        );

        $code = trim(
            (string) ($data['code'] ?? '')
        );

        $contactName = trim(
            (string) ($data['contact_name'] ?? '')
        );

        $phone = trim(
            (string) ($data['phone'] ?? '')
        );

        $email = trim(
            (string) ($data['email'] ?? '')
        );

        $status = trim(
            (string) ($data['status'] ?? 'active')
        );

        /*
         * -----------------------------------------------------
         * Validate name
         * -----------------------------------------------------
         */
        if ($name === '') {
            api_error_response(
                422,
                'VALIDATION_ERROR',
                'Customer name is required.'
            );
        }

        if (mb_strlen($name, 'UTF-8') > 150) {
            api_error_response(
                422,
                'VALIDATION_ERROR',
                'Customer name cannot exceed 150 characters.'
            );
        }

        /*
         * -----------------------------------------------------
         * Validate code
         * -----------------------------------------------------
         */
        if ($code !== '' && mb_strlen($code, 'UTF-8') > 100) {
            api_error_response(
                422,
                'VALIDATION_ERROR',
                'Customer code cannot exceed 100 characters.'
            );
        }

        /*
         * -----------------------------------------------------
         * Validate contact name
         * -----------------------------------------------------
         */
        if (
            $contactName !== '' &&
            mb_strlen($contactName, 'UTF-8') > 150
        ) {
            api_error_response(
                422,
                'VALIDATION_ERROR',
                'Contact name cannot exceed 150 characters.'
            );
        }

        /*
         * -----------------------------------------------------
         * Validate phone
         * -----------------------------------------------------
         */
        if (
            $phone !== '' &&
            mb_strlen($phone, 'UTF-8') > 50
        ) {
            api_error_response(
                422,
                'INVALID_CONTACT_DATA',
                'Phone number cannot exceed 50 characters.'
            );
        }

        /*
         * -----------------------------------------------------
         * Validate email
         * -----------------------------------------------------
         */
        if ($email !== '') {

            if (mb_strlen($email, 'UTF-8') > 254) {
                api_error_response(
                    422,
                    'INVALID_CONTACT_DATA',
                    'Email cannot exceed 254 characters.'
                );
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                api_error_response(
                    422,
                    'INVALID_CONTACT_DATA',
                    'Please provide a valid email address.'
                );
            }
        }

        /*
         * -----------------------------------------------------
         * Validate status
         * -----------------------------------------------------
         */
        if (!in_array($status, ['active', 'inactive'], true)) {
            api_error_response(
                422,
                'INVALID_STATUS',
                'Status must be active or inactive.'
            );
        }

        /*
         * -----------------------------------------------------
         * Normalize customer name
         * -----------------------------------------------------
         */
        $nameNormalized = mb_strtolower(
            trim(
                preg_replace(
                    '/\s+/u',
                    ' ',
                    $name
                )
            ),
            'UTF-8'
        );

        /*
         * -----------------------------------------------------
         * Duplicate customer name
         * -----------------------------------------------------
         */
        $duplicateNameStmt = $pdo->prepare(
            "
            SELECT id
            FROM customers
            WHERE tenant_id = :tenant_id
              AND name_normalized = :name_normalized
            LIMIT 1
            "
        );

        $duplicateNameStmt->execute([
            ':tenant_id' => $tenantId,
            ':name_normalized' => $nameNormalized
        ]);

        if ($duplicateNameStmt->fetchColumn() !== false) {
            api_error_response(
                409,
                'DUPLICATE_CUSTOMER',
                'A customer with this name already exists.'
            );
        }

        /*
         * -----------------------------------------------------
         * Duplicate code
         * -----------------------------------------------------
         */
        if ($code !== '') {

            $duplicateCodeStmt = $pdo->prepare(
                "
                SELECT id
                FROM customers
                WHERE tenant_id = :tenant_id
                  AND code = :code
                LIMIT 1
                "
            );

            $duplicateCodeStmt->execute([
                ':tenant_id' => $tenantId,
                ':code' => $code
            ]);

            if ($duplicateCodeStmt->fetchColumn() !== false) {
                api_error_response(
                    409,
                    'DUPLICATE_CUSTOMER_CODE',
                    'A customer with this code already exists.'
                );
            }
        }

        /*
         * -----------------------------------------------------
         * Create customer
         * -----------------------------------------------------
         */
        $pdo->beginTransaction();

        try {

            $insertStmt = $pdo->prepare(
                "
                INSERT INTO customers (
                    tenant_id,
                    name,
                    name_normalized,
                    code,
                    contact_name,
                    phone,
                    email,
                    status
                )
                VALUES (
                    :tenant_id,
                    :name,
                    :name_normalized,
                    :code,
                    :contact_name,
                    :phone,
                    :email,
                    :status
                )
                "
            );

            $insertStmt->execute([
                ':tenant_id' => $tenantId,
                ':name' => $name,
                ':name_normalized' => $nameNormalized,
                ':code' => $code !== '' ? $code : null,
                ':contact_name' => $contactName !== ''
                    ? $contactName
                    : null,
                ':phone' => $phone !== ''
                    ? $phone
                    : null,
                ':email' => $email !== ''
                    ? $email
                    : null,
                ':status' => $status
            ]);

            $customerId = (int) $pdo->lastInsertId();

            /*
             * Reload inserted record.
             */
            $customerStmt = $pdo->prepare(
                "
                SELECT
                    id,
                    tenant_id,
                    name,
                    code,
                    contact_name,
                    phone,
                    email,
                    status,
                    created_at,
                    updated_at
                FROM customers
                WHERE tenant_id = :tenant_id
                  AND id = :id
                LIMIT 1
                "
            );

            $customerStmt->execute([
                ':tenant_id' => $tenantId,
                ':id' => $customerId
            ]);

            $customer = $customerStmt->fetch();

            if (!$customer) {
                throw new RuntimeException(
                    'Customer could not be loaded after creation.'
                );
            }

            /*
             * Audit
             */
            audit_event(
                'customer.created',
                'customer',
                (string) $customerId,
                null,
                $customer
            );

            $pdo->commit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        api_success_response(
            [
                'customer' => $customer
            ],
            201
        );

        exit;
    }

    /*
     * ---------------------------------------------------------
     * Method not allowed
     * ---------------------------------------------------------
     */
    header('Allow: GET, POST');

    api_error_response(
        405,
        'METHOD_NOT_ALLOWED',
        'Method not allowed.'
    );

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Customer API database error: ' .
        $e->getMessage()
    );

    api_database_error();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Customer API error: ' .
        $e->getMessage()
    );

    api_server_error();
}
