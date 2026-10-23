<?php
declare(strict_types=1);

/**
 * Point 4: client-request idempotency tests for purchase receipt posting.
 *
 * SAFETY: This script is restricted to inventory_intelligence_test.
 * It creates two receipt documents. The first is posted and creates one
 * permanent stock movement plus audit/idempotency records. The second stays
 * draft. No cleanup is performed because posted ledger rows are immutable.
 */

require_once __DIR__ . '/../config/env.php';

$pdo = new PDO(
    'mysql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') .
    ';port=' . ($_ENV['DB_PORT'] ?? '3306') .
    ';dbname=inventory_intelligence_test;charset=utf8mb4',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASSWORD'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'inventory_intelligence_test') {
    throw new RuntimeException('SAFETY STOP: unexpected database.');
}

$tenantId = 1;
$userId = 1;
$productId = 1;
$warehouseId = 1;

foreach ([
    'active test user' => [
        'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND status = ?',
        [$userId, $tenantId, 'active'],
    ],
    'active test product' => [
        'SELECT id FROM products WHERE id = ? AND tenant_id = ? AND sku = ? AND status = ?',
        [$productId, $tenantId, 'TEST-SM-001', 'active'],
    ],
    'active test warehouse' => [
        'SELECT id FROM warehouses WHERE id = ? AND tenant_id = ? AND warehouse_code = ? AND status = ?',
        [$warehouseId, $tenantId, 'TEST-WH-001', 'active'],
    ],
] as $label => [$sql, $params]) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException("SAFETY STOP: missing $label fixture.");
    }
}

$stmt = $pdo->prepare(
    "SELECT id FROM suppliers
      WHERE tenant_id = ? AND status = 'active'
      ORDER BY id LIMIT 1"
);
$stmt->execute([$tenantId]);
$supplierId = (int) $stmt->fetchColumn();
if ($supplierId < 1) {
    throw new RuntimeException('SAFETY STOP: active supplier fixture missing.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['authenticated'] = true;
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['user_id'] = $userId;

if (!defined('IIP_AUDIT_SKIP_DB_BOOTSTRAP')) {
    define('IIP_AUDIT_SKIP_DB_BOOTSTRAP', true);
}
$GLOBALS['pdo'] = $pdo;

require_once __DIR__ . '/../src/Inventory/StockDocumentPostingService.php';

$createReceipt = static function (string $reference) use (
    $pdo, $tenantId, $warehouseId, $supplierId, $userId, $productId
): int {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO stock_documents
                (tenant_id, type, status, warehouse_id, counterparty_id,
                 reference_no, document_date, notes, created_by)
             VALUES (?, 'PURCHASE_RECEIPT', 'draft', ?, ?, ?, CURRENT_DATE, ?, ?)"
        );
        $stmt->execute([
            $tenantId, $warehouseId, $supplierId, $reference,
            'Point 4 idempotency test', $userId,
        ]);
        $documentId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO stock_document_lines
                (tenant_id, document_id, product_id, qty, unit_cost)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tenantId, $documentId, $productId, '0.125000', '1.000000']);

        $pdo->commit();
        return $documentId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
};

$countMovements = static function (int $documentId) use ($pdo, $tenantId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM stock_movements
          WHERE tenant_id = ?
            AND source_type = 'PURCHASE_RECEIPT'
            AND source_reference = ?"
    );
    $stmt->execute([$tenantId, (string) $documentId]);
    return (int) $stmt->fetchColumn();
};

$referencePrefix = 'TEST-IDEM-' . bin2hex(random_bytes(5));
$documentA = $createReceipt($referencePrefix . '-A');
$documentB = $createReceipt($referencePrefix . '-B');
$key = 'point4-' . bin2hex(random_bytes(16));
$service = new StockDocumentPostingService($pdo);

echo "Database: inventory_intelligence_test\n";
echo "Receipt A: $documentA\n";
echo "Receipt B: $documentB\n";
echo "Idempotency key: $key\n";
echo "NOTE: Receipt A will be posted permanently in the dedicated test database.\n";

$first = $service->postPurchaseReceipt($documentA, $key);
if (!isset($first['document_id'], $first['movement_ids']) ||
    (int) $first['document_id'] !== $documentA) {
    throw new RuntimeException('FAIL: first request returned an unexpected response.');
}

$replay = $service->postPurchaseReceipt($documentA, $key);
if ($first !== $replay) {
    throw new RuntimeException(
        'FAIL: same key and same request did not return the exact saved response.'
    );
}
if ($countMovements($documentA) !== 1) {
    throw new RuntimeException('FAIL: same-key replay created duplicate stock movements.');
}

$stmt = $pdo->prepare(
    'SELECT request_hash, status, response_json, document_id
       FROM stock_document_post_requests
      WHERE tenant_id = ? AND idempotency_key = ?'
);
$stmt->execute([$tenantId, $key]);
$requestRecord = $stmt->fetch();

if (!$requestRecord ||
    $requestRecord['status'] !== 'completed' ||
    (int) $requestRecord['document_id'] !== $documentA ||
    !is_string($requestRecord['response_json']) ||
    json_decode($requestRecord['response_json'], true) !== $first) {
    throw new RuntimeException('FAIL: completed idempotency response was not persisted correctly.');
}
echo "PASS: same receipt + same key returns exact saved response.\n";
echo "PASS: idempotency response is persisted as completed.\n";
echo "PASS: receipt A has exactly one stock movement.\n";

$conflictCaught = false;
try {
    $service->postPurchaseReceipt($documentB, $key);
} catch (DomainException $e) {
    if (strpos($e->getMessage(), 'IDEMPOTENCY_KEY_REUSED') === false) {
        throw $e;
    }
    $conflictCaught = true;
}

if (!$conflictCaught) {
    throw new RuntimeException('FAIL: same key with a different receipt was not rejected.');
}
if ($countMovements($documentB) !== 0) {
    throw new RuntimeException('FAIL: rejected request created a stock movement for receipt B.');
}

$stmt = $pdo->prepare(
    'SELECT status FROM stock_documents WHERE tenant_id = ? AND id = ?'
);
$stmt->execute([$tenantId, $documentB]);
$statusB = $stmt->fetchColumn();

if ($statusB !== 'draft') {
    throw new RuntimeException('FAIL: conflicting receipt B changed from draft.');
}

echo "PASS: same key + different receipt is rejected.\n";
echo "PASS: conflicting receipt B remains draft with zero stock movements.\n";
echo "ALL AVAILABLE SERVICE-LEVEL IDEMPOTENCY CHECKS PASSED.\n";
echo "API Idempotency-Key header wiring and concurrent-request behavior are NOT covered by this script.\n";
