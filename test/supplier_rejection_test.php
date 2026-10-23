<?php
declare(strict_types=1);

require __DIR__ . '/../config/env.php';

$pdo = new PDO(
    "mysql:host=" . ($_ENV['DB_HOST'] ?? '127.0.0.1') .
    ";port=" . ($_ENV['DB_PORT'] ?? '3306') .
    ";dbname=inventory_intelligence_test;charset=utf8mb4",
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'inventory_intelligence_test') {
    throw new RuntimeException('Wrong database; stopped.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('IIP_AUDIT_SKIP_DB_BOOTSTRAP', true);
$GLOBALS['pdo'] = $pdo;

require_once __DIR__ . '/../src/Inventory/StockDocumentPostingService.php';

$user = $pdo->query(
    "SELECT id, tenant_id FROM users
     WHERE status='active' ORDER BY id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    throw new RuntimeException('No active test user.');
}

$tenantId = (int) $user['tenant_id'];
$userId = (int) $user['id'];

$_SESSION['authenticated'] = true;
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['user_id'] = $userId;

$find = $pdo->prepare(
    "SELECT id FROM warehouses
     WHERE tenant_id=? AND status='active' ORDER BY id LIMIT 1"
);
$find->execute([$tenantId]);
$warehouseId = (int) $find->fetchColumn();

$find = $pdo->prepare(
    "SELECT id FROM products
     WHERE tenant_id=? AND status='active' ORDER BY id LIMIT 1"
);
$find->execute([$tenantId]);
$productId = (int) $find->fetchColumn();

if (!$warehouseId || !$productId) {
    throw new RuntimeException('Active test warehouse/product missing.');
}

$documentId = 0;
$reference = 'TEST-NO-SUPPLIER-' . bin2hex(random_bytes(5));

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO stock_documents
            (tenant_id,type,status,warehouse_id,counterparty_id,
             reference_no,document_date,notes,created_by)
         VALUES (?,'PURCHASE_RECEIPT','draft',?,NULL,?,CURRENT_DATE,?,?)"
    );
    $stmt->execute([
        $tenantId,
        $warehouseId,
        $reference,
        'Supplier rejection test',
        $userId
    ]);
    $documentId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "INSERT INTO stock_document_lines
            (tenant_id,document_id,product_id,qty,unit_cost)
         VALUES (?,?,?,?,?)"
    );
    $stmt->execute([
        $tenantId, $documentId, $productId, '0.125000', '1.000000'
    ]);

    $pdo->commit();

    try {
        (new StockDocumentPostingService($pdo))->postPurchaseReceipt($documentId);
        throw new RuntimeException('FAIL: receipt without supplier was posted.');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'requires a valid supplier')) {
            throw $e;
        }
        echo "PASS: receipt without supplier rejected." . PHP_EOL;
    }

    $stmt = $pdo->prepare(
        "SELECT status FROM stock_documents WHERE tenant_id=? AND id=?"
    );
    $stmt->execute([$tenantId, $documentId]);
    $status = $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM stock_movements
         WHERE tenant_id=?
           AND reference_type='stock_document'
           AND reference_id=?"
    );
    $stmt->execute([$tenantId, $documentId]);
    $movements = (int) $stmt->fetchColumn();

    if ($status !== 'draft' || $movements !== 0) {
        throw new RuntimeException(
            "FAIL: status={$status}; movements={$movements}"
        );
    }

    echo "PASS: document remains draft; no stock movement created." . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
