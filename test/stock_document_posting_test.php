<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

$pdo = new PDO(
    'mysql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') .
    ';port=' . ($_ENV['DB_PORT'] ?? '3306') .
    ';dbname=inventory_intelligence_test;charset=utf8mb4',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'inventory_intelligence_test') {
    throw new RuntimeException('SAFETY STOP: unexpected database');
}

$tenantId = 1;
$userId = 1;
$productId = 1;
$warehouseId = 1;

foreach ([
    'users' => 'SELECT id FROM users WHERE id = 1 AND tenant_id = 1 AND status = "active"',
    'products' => 'SELECT id FROM products WHERE id = 1 AND tenant_id = 1 AND sku = "TEST-SM-001" AND status = "active"',
    'warehouses' => 'SELECT id FROM warehouses WHERE id = 1 AND tenant_id = 1 AND warehouse_code = "TEST-WH-001" AND status = "active"',
] as $name => $sql) {
    if (!$pdo->query($sql)->fetchColumn()) {
        throw new RuntimeException("SAFETY STOP: test fixture missing: $name");
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['authenticated'] = true;
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['user_id'] = $userId;

define('IIP_AUDIT_SKIP_DB_BOOTSTRAP', true);
$GLOBALS['pdo'] = $pdo;

require_once __DIR__ . '/../src/Inventory/StockDocumentPostingService.php';

$reference = 'TEST-RCPT-' . bin2hex(random_bytes(6));
$pdo->beginTransaction();

try {
    // Find an active supplier belonging to the current test tenant.
    $stmt = $pdo->prepare(
        "SELECT id
           FROM suppliers
          WHERE tenant_id = ?
            AND status = 'active'
          ORDER BY id
          LIMIT 1"
    );
    $stmt->execute([$tenantId]);
    $supplierId = $stmt->fetchColumn();

    if (!$supplierId) {
        throw new RuntimeException(
            'Test requires an active supplier in inventory_intelligence_test.'
        );
    }

    // Create the purchase receipt with a valid supplier.
    $stmt = $pdo->prepare(
        "INSERT INTO stock_documents
            (tenant_id, type, status, warehouse_id, counterparty_id,
             reference_no, document_date, notes, created_by)
         VALUES (?, 'PURCHASE_RECEIPT', 'draft', ?, ?, ?, CURRENT_DATE, ?, ?)"
    );
    $stmt->execute([
        $tenantId,
        $warehouseId,
        (int) $supplierId,
        $reference,
        'Automated integration test',
        $userId
    ]);
    $documentId = (int) $pdo->lastInsertId();

    // Add the receipt line.
    $stmt = $pdo->prepare(
        'INSERT INTO stock_document_lines
            (tenant_id, document_id, product_id, qty, unit_cost)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $tenantId,
        $documentId,
        $productId,
        '0.125000',
        '1.000000'
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$service = new StockDocumentPostingService($pdo);
$first = $service->postPurchaseReceipt($documentId);

if (!is_array($first)) {
    throw new RuntimeException('FAIL: first posting did not return an array');
}

$second = $service->postPurchaseReceipt($documentId);

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM stock_movements
     WHERE tenant_id = ? AND source_type = 'PURCHASE_RECEIPT' AND source_reference = ?"
);
$stmt->execute([$tenantId, (string) $documentId]);
$movementCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT status FROM stock_documents WHERE tenant_id = ? AND id = ?');
$stmt->execute([$tenantId, $documentId]);
$status = $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_events
     WHERE tenant_id = ? AND entity_type = 'stock_document' AND entity_id = ?"
);
$stmt->execute([$tenantId, $documentId]);
$documentAuditCount = (int) $stmt->fetchColumn();

echo "Database: inventory_intelligence_test\n";
echo "Document ID: $documentId\n";
echo "First result: " . json_encode($first) . "\n";
echo "Replay result: " . json_encode($second) . "\n";
echo "Document status: " . ($status ?: 'MISSING') . "\n";
echo "Matching movements: $movementCount\n";

$stmt = $pdo->prepare(
    "SELECT reference_type, reference_id
       FROM stock_movements
      WHERE tenant_id = ?
        AND source_type = 'PURCHASE_RECEIPT'
        AND source_reference = ?
      ORDER BY id
      LIMIT 1"
);
$stmt->execute([$tenantId, (string) $documentId]);
$sourceLink = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Source link: " . json_encode($sourceLink) . PHP_EOL;

if (
    !$sourceLink ||
    $sourceLink['reference_type'] !== 'stock_document' ||
    (int) $sourceLink['reference_id'] !== $documentId
) {
    throw new RuntimeException('FAIL: source document linkage is incorrect.');
}

echo "PASS: stock movement links to its source document" . PHP_EOL;

echo "Document audit events: $documentAuditCount\n";

if ($status !== 'posted' || $movementCount !== 1 || $documentAuditCount < 1) {
    throw new RuntimeException('FAIL: posting, ledger uniqueness, or document audit check failed');
}

echo "PASS: purchase receipt posting and single-movement replay check\n";
