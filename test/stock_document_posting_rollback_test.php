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
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== 'inventory_intelligence_test') {
    throw new RuntimeException('SAFETY STOP: unexpected database: ' . $database);
}

$tenantId = 1;
$userId = 1;
$productId = 1;
$warehouseId = 1;
$triggerName = 'trg_test_force_stock_document_audit_failure';
$triggerCreated = false;
$documentId = 0;
$testPassed = false;
$reference = 'TEST-ROLLBACK-' . bin2hex(random_bytes(6));

$checks = [
    'user' => "SELECT id FROM users WHERE id = 1 AND tenant_id = 1 AND status = 'active'",
    'product' => "SELECT id FROM products WHERE id = 1 AND tenant_id = 1 AND sku = 'TEST-SM-001' AND status = 'active'",
    'warehouse' => "SELECT id FROM warehouses WHERE id = 1 AND tenant_id = 1 AND warehouse_code = 'TEST-WH-001' AND status = 'active'",
];
foreach ($checks as $name => $sql) {
    if (!$pdo->query($sql)->fetchColumn()) {
        throw new RuntimeException("SAFETY STOP: expected test fixture missing: {$name}");
    }
}

$stmt = $pdo->prepare(
    "SELECT id FROM suppliers WHERE tenant_id = :tenant AND status = 'active' ORDER BY id LIMIT 1"
);
$stmt->execute([':tenant' => $tenantId]);
$supplierId = (int) $stmt->fetchColumn();
if ($supplierId < 1) {
    throw new RuntimeException('SAFETY STOP: no active supplier fixture in test database.');
}

$triggerCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'
);
$triggerCheck->execute([$triggerName]);
if ((int) $triggerCheck->fetchColumn() !== 0) {
    throw new RuntimeException('SAFETY STOP: test trigger already exists; refusing to overwrite it.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['authenticated'] = true;
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['user_id'] = $userId;

define('IIP_AUDIT_SKIP_DB_BOOTSTRAP', true);
$GLOBALS['pdo'] = $pdo;
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../src/Inventory/StockDocumentPostingService.php';

try {
    // Create and commit a valid draft receipt before installing the temporary test trigger.
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "INSERT INTO stock_documents
            (tenant_id, type, status, warehouse_id, counterparty_id,
             reference_no, document_date, notes, created_by)
         VALUES (?, 'PURCHASE_RECEIPT', 'draft', ?, ?, ?, CURRENT_DATE, ?, ?)"
    );
    $stmt->execute([
        $tenantId,
        $warehouseId,
        $supplierId,
        $reference,
        'Rollback integration test - temporary audit failure',
        $userId,
    ]);
    $documentId = (int) $pdo->lastInsertId();

    $lineStmt = $pdo->prepare(
        'INSERT INTO stock_document_lines (tenant_id, document_id, product_id, qty, unit_cost) VALUES (?, ?, ?, ?, ?)'
    );
    $lineStmt->execute([$tenantId, $documentId, $productId, '0.125000', '1.000000']);
    $lineStmt->execute([$tenantId, $documentId, $productId, '0.250000', '2.000000']);
    $pdo->commit();

    $movementAuditBefore = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_events WHERE event_type = 'stock.movement_posted' AND entity_type = 'stock_movement'"
    )->fetchColumn();

    // The connection-scoped variable restricts the failure to this exact test document.
    $pdo->prepare('SET @iip_test_document_id = ?')->execute([(string) $documentId]);
    $triggerSql = "CREATE TRIGGER `{$triggerName}` BEFORE INSERT ON audit_events FOR EACH ROW
    BEGIN
        IF BINARY NEW.event_type = BINARY 'stock.document_posted'
           AND BINARY NEW.entity_type = BINARY 'stock_document'
           AND BINARY NEW.entity_id = BINARY CAST(@iip_test_document_id AS CHAR) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TEST_ONLY_DOCUMENT_AUDIT_FAILURE';
        END IF;
    END";
    $pdo->exec($triggerSql);
    $triggerCreated = true;

    $caught = null;
    try {
        (new StockDocumentPostingService($pdo))->postPurchaseReceipt($documentId);
    } catch (Throwable $e) {
        $caught = $e;
    }

    if ($caught === null) {
        throw new RuntimeException('FAIL: expected the test-only document audit trigger to abort posting.');
    }
    if (strpos($caught->getMessage(), 'TEST_ONLY_DOCUMENT_AUDIT_FAILURE') === false) {
        throw new RuntimeException(
            'FAIL: posting failed for an unexpected reason; audit-trigger rollback was not proven. Got: ' . $caught->getMessage(),
            0,
            $caught
        );
    }

    $stmt = $pdo->prepare('SELECT status FROM stock_documents WHERE tenant_id = ? AND id = ?');
    $stmt->execute([$tenantId, $documentId]);
    $status = $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM stock_movements
          WHERE tenant_id = ? AND source_type = 'PURCHASE_RECEIPT' AND source_reference = ?"
    );
    $stmt->execute([$tenantId, (string) $documentId]);
    $movementCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_events WHERE tenant_id = ? AND entity_type = 'stock_document' AND entity_id = ?"
    );
    $stmt->execute([$tenantId, (string) $documentId]);
    $documentAuditCount = (int) $stmt->fetchColumn();

    $movementAuditAfter = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_events WHERE event_type = 'stock.movement_posted' AND entity_type = 'stock_movement'"
    )->fetchColumn();

    if ($status !== 'draft') {
        throw new RuntimeException('FAIL: document status did not roll back to draft.');
    }
    if ($movementCount !== 0) {
        throw new RuntimeException("FAIL: {$movementCount} receipt movement(s) remained after rollback.");
    }
    if ($documentAuditCount !== 0) {
        throw new RuntimeException("FAIL: {$documentAuditCount} document audit event(s) remained after rollback.");
    }
    if ($movementAuditAfter !== $movementAuditBefore) {
        throw new RuntimeException('FAIL: stock.movement_posted audit records remained after rollback.');
    }

    echo "Database: {$database}\n";
    echo "Document ID: {$documentId}\n";
    echo "Expected failure: TEST_ONLY_DOCUMENT_AUDIT_FAILURE\n";
    echo "Document status after rollback: {$status}\n";
    echo "Matching receipt movements after rollback: {$movementCount}\n";
    echo "Document audit events after rollback: {$documentAuditCount}\n";
    echo "Movement audit count unchanged: " . ($movementAuditBefore === $movementAuditAfter ? 'yes' : 'no') . "\n";
    echo "PASS: purchase receipt transaction rolled back after the final document audit failure.\n";
    $testPassed = true;
} finally {
    // Remove only the trigger this test created; never overwrite or drop an existing trigger.
    if ($triggerCreated) {
        try {
            $currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($currentDb !== 'inventory_intelligence_test') {
                throw new RuntimeException('SAFETY STOP: database changed before test trigger cleanup.');
            }
            $pdo->exec("DROP TRIGGER IF EXISTS `{$triggerName}`");
        } catch (Throwable $cleanupError) {
            error_log('CRITICAL: rollback test trigger cleanup failed: ' . $cleanupError->getMessage());
            $testPassed = false;
        }
    }

    $pdo->exec('SET @iip_test_document_id = NULL');
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($documentId > 0) {
        try {
            $currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($currentDb !== 'inventory_intelligence_test') {
                throw new RuntimeException('SAFETY STOP: database changed before fixture cleanup.');
            }
            $stmt = $pdo->prepare('DELETE FROM stock_document_lines WHERE tenant_id = ? AND document_id = ?');
            $stmt->execute([$tenantId, $documentId]);
            $stmt = $pdo->prepare('DELETE FROM stock_documents WHERE tenant_id = ? AND id = ?');
            $stmt->execute([$tenantId, $documentId]);
        } catch (Throwable $cleanupError) {
            error_log('CRITICAL: rollback test fixture cleanup failed: ' . $cleanupError->getMessage());
            $testPassed = false;
        }
    }
    echo "Test fixture cleanup completed.\n";
}

exit($testPassed ? 0 : 1);
