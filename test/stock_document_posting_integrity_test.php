<?php
declare(strict_types=1);

/**
 * Verifies that replay rejects a posted purchase receipt with missing ledger movements.
 * Safety: connects only to inventory_intelligence_test and removes its own fixture rows.
 */

require_once __DIR__ . '/../config/env.php';

const TEST_DATABASE = 'inventory_intelligence_test';

$pdo = new PDO(
    'mysql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') .
    ';port=' . ($_ENV['DB_PORT'] ?? '3306') .
    ';dbname=' . TEST_DATABASE .
    ';charset=' . ($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASSWORD'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== TEST_DATABASE) {
    throw new RuntimeException('SAFETY STOP: wrong database; expected inventory_intelligence_test.');
}

$tenantId = 1;
$userId = 1;
$productId = 1;
$warehouseId = 1;
$documentId = null;

foreach ([
    'active test user' => "SELECT id FROM users WHERE id = 1 AND tenant_id = 1 AND status = 'active'",
    'active test product' => "SELECT id FROM products WHERE id = 1 AND tenant_id = 1 AND status = 'active'",
    'active test warehouse' => "SELECT id FROM warehouses WHERE id = 1 AND tenant_id = 1 AND status = 'active'",
] as $label => $sql) {
    if (!$pdo->query($sql)->fetchColumn()) {
        throw new RuntimeException("SAFETY STOP: missing $label fixture.");
    }
}

$stmt = $pdo->prepare(
    "SELECT id FROM suppliers
      WHERE tenant_id = :tenant AND status = 'active'
      ORDER BY id LIMIT 1"
);
$stmt->execute([':tenant' => $tenantId]);
$supplierId = $stmt->fetchColumn();

if (!$supplierId) {
    throw new RuntimeException('SAFETY STOP: active supplier fixture missing in test database.');
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

$reference = 'TEST-INTEGRITY-' . bin2hex(random_bytes(6));

try {
    // A posted document must satisfy the DB check: posted_by and posted_at are both non-null.
    // The deliberate inconsistency is only that its source line has no matching ledger movement.
    $stmt = $pdo->prepare(
        "INSERT INTO stock_documents
            (tenant_id, type, status, warehouse_id, counterparty_id,
             reference_no, document_date, notes, created_by, posted_by, posted_at)
         VALUES
            (:tenant, 'PURCHASE_RECEIPT', 'posted', :warehouse, :supplier,
             :reference, CURRENT_DATE, 'Integrity replay test fixture', :actor, :posted_by, UTC_TIMESTAMP())"
    );
    $stmt->execute([
        ':tenant' => $tenantId,
        ':warehouse' => $warehouseId,
        ':supplier' => (int) $supplierId,
        ':reference' => $reference,
        ':actor' => $userId,
        ':posted_by' => $userId,
    ]);
    $documentId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO stock_document_lines (tenant_id, document_id, product_id, qty, unit_cost)
         VALUES (:tenant, :document, :product, 0.125000, 1.000000)'
    );
    $stmt->execute([
        ':tenant' => $tenantId,
        ':document' => $documentId,
        ':product' => $productId,
    ]);

    echo 'Database: ' . TEST_DATABASE . PHP_EOL;
    echo 'Integrity fixture document ID: ' . $documentId . PHP_EOL;
    echo 'Expected condition: posted receipt has 1 source line and 0 movements.' . PHP_EOL;

    $service = new StockDocumentPostingService($pdo);
    $caught = false;

    try {
        $result = $service->postPurchaseReceipt($documentId);
        echo 'Unexpected service result: ' . json_encode($result) . PHP_EOL;
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Posted receipt integrity failure')) {
            $caught = true;
            echo 'Expected integrity exception: ' . $e->getMessage() . PHP_EOL;
        } else {
            echo 'Unexpected exception: ' . $e->getMessage() . PHP_EOL;
        }
    }

    if (!$caught) {
        throw new RuntimeException(
            'FAIL: replay did not reject a posted receipt with missing ledger movements.'
        );
    }

    echo 'PASS: replay rejects a posted receipt with missing ledger movements.' . PHP_EOL;
} finally {
    // Remove only this test's own rows; no ledger movements are created by this fixture.
    if ($documentId !== null) {
        $stmt = $pdo->prepare(
            'DELETE FROM stock_document_lines WHERE tenant_id = :tenant AND document_id = :document'
        );
        $stmt->execute([':tenant' => $tenantId, ':document' => $documentId]);

        $stmt = $pdo->prepare(
            'DELETE FROM stock_documents WHERE tenant_id = :tenant AND id = :document'
        );
        $stmt->execute([':tenant' => $tenantId, ':document' => $documentId]);

        echo 'Test fixture cleanup completed.' . PHP_EOL;
    }
}
