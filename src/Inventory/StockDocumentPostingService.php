<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/audit.php';

final class StockDocumentPostingService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Atomically post a PURCHASE receipt and all its lines.
     * Tenant and actor context are taken from the authenticated session.
     */
    public function postPurchaseReceipt(int $documentId, ?string $idempotencyKey = null): array
    {
        if (
            session_status() !== PHP_SESSION_ACTIVE ||
            empty($_SESSION['authenticated'])
        ) {
            throw new RuntimeException('Authenticated session required.');
        }

        $tenantId = filter_var(
            $_SESSION['tenant_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $actorId = filter_var(
            $_SESSION['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($tenantId === false || $actorId === false || $documentId < 1) {
            throw new RuntimeException(
                'Invalid authenticated context or document ID.'
            );
        }

        $tenantId = (int) $tenantId;
        $actorId = (int) $actorId;

        if ($idempotencyKey !== null) {
            $idempotencyKey = trim($idempotencyKey);
            if (
                $idempotencyKey === '' ||
                strlen($idempotencyKey) > 255 ||
                preg_match('/[^\x21-\x7E]/', $idempotencyKey)
            ) {
                throw new InvalidArgumentException('Invalid Idempotency-Key.');
            }
        }

        global $pdo;

        if (!($pdo instanceof PDO) || $pdo !== $this->pdo) {
            throw new RuntimeException(
                'Audit and document posting must use the same PDO connection.'
            );
        }

        $requestHash = null;

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                "SELECT id, type, status, warehouse_id, counterparty_id, document_date
                   FROM stock_documents
                  WHERE tenant_id = :tenant
                    AND id = :id
                  FOR UPDATE"
            );

            $stmt->execute([
                ':tenant' => $tenantId,
                ':id' => $documentId,
            ]);

            $document = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$document) {
                throw new RuntimeException(
                    'Source document not found in this tenant.'
                );
            }

            if ($idempotencyKey !== null) {
                $requestHash = $this->buildReceiptRequestHash(
                    $tenantId,
                    $documentId,
                    $document
                );

                $existingRequest = $this->findPostRequest(
                    $tenantId,
                    $idempotencyKey
                );

                if ($existingRequest !== null) {
                    if (!hash_equals((string) $existingRequest['request_hash'], $requestHash)) {
                        throw new DomainException(
                            'IDEMPOTENCY_KEY_REUSED: this key was used for a different request.'
                        );
                    }

                    if ($existingRequest['status'] !== 'completed' || !is_string($existingRequest['response_json'])) {
                        throw new RuntimeException(
                            'IDEMPOTENCY_REQUEST_INCOMPLETE: request outcome is not available.'
                        );
                    }

                    $savedResponse = json_decode(
                        $existingRequest['response_json'],
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    if (!is_array($savedResponse)) {
                        throw new RuntimeException('Stored idempotency response is invalid.');
                    }

                    $this->pdo->commit();
                    return $savedResponse;
                }

                $insertRequest = $this->pdo->prepare(
                    "INSERT INTO stock_document_post_requests
                        (tenant_id, idempotency_key, request_hash, document_id, status)
                     VALUES (:tenant, :key, :hash, :document, 'processing')"
                );
                $insertRequest->execute([
                    ':tenant' => $tenantId,
                    ':key' => $idempotencyKey,
                    ':hash' => $requestHash,
                    ':document' => $documentId,
                ]);
            }

            // Idempotent replay: verify the complete ledger result before returning.
            if ($document['status'] === 'posted') {
                $stmt = $this->pdo->prepare(
                    "SELECT id
                       FROM stock_document_lines
                      WHERE tenant_id = :tenant
                        AND document_id = :document
                      ORDER BY id"
                );

                $stmt->execute([
                    ':tenant' => $tenantId,
                    ':document' => $documentId,
                ]);

                $lines = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!$lines) {
                    throw new RuntimeException(
                        'Posted receipt integrity failure: source lines are missing.'
                    );
                }

                $stmt = $this->pdo->prepare(
                    "SELECT id, status, idempotency_key
                       FROM stock_movements
                      WHERE tenant_id = :tenant
                        AND (
                            (reference_type = 'stock_document'
                             AND reference_id = :reference_id)
                            OR
                            (source_type = 'PURCHASE_RECEIPT'
                             AND source_reference = :source_reference)
                        )
                      ORDER BY id"
                );

                $stmt->execute([
                    ':tenant' => $tenantId,
                    ':reference_id' => $documentId,
                    ':source_reference' => (string) $documentId,
                ]);

                $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($movements) !== count($lines)) {
                    throw new RuntimeException(
                        'Posted receipt integrity failure: movement count does not match source lines.'
                    );
                }

                $expectedKeys = [];

                foreach ($lines as $lineId) {
                    $expectedKeys[] =
                        'receipt:' . $documentId . ':line:' . (int) $lineId;
                }

                $actualKeys = [];
                $movementIds = [];

                foreach ($movements as $movement) {
                    if ($movement['status'] !== 'posted') {
                        throw new RuntimeException(
                            'Posted receipt integrity failure: a linked movement is not posted.'
                        );
                    }

                    $key = $movement['idempotency_key'];

                    if (!is_string($key) || !in_array($key, $expectedKeys, true)) {
                        throw new RuntimeException(
                            'Posted receipt integrity failure: unexpected movement idempotency key.'
                        );
                    }

                    if (in_array($key, $actualKeys, true)) {
                        throw new RuntimeException(
                            'Posted receipt integrity failure: duplicate movement for a source line.'
                        );
                    }

                    $actualKeys[] = $key;
                    $movementIds[] = (int) $movement['id'];
                }

                sort($expectedKeys);
                sort($actualKeys);

                if ($expectedKeys !== $actualKeys) {
                    throw new RuntimeException(
                        'Posted receipt integrity failure: source-line movements are incomplete.'
                    );
                }

                $response = [
                    'document_id' => $documentId,
                    'replayed' => true,
                    'movement_ids' => $movementIds,
                ];
                $this->completePostRequest($tenantId, $idempotencyKey, $response);
                $this->pdo->commit();

                return $response;
            }

            if (
                $document['type'] !== 'PURCHASE_RECEIPT' ||
                $document['status'] !== 'draft'
            ) {
                throw new RuntimeException(
                    'Only a draft PURCHASE_RECEIPT can be posted.'
                );
            }

            // Purchase receipts require an active supplier in the same tenant.
            $counterpartyId = filter_var(
                $document['counterparty_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($counterpartyId === false) {
                throw new RuntimeException(
                    'Purchase receipt requires a valid supplier.'
                );
            }

            $stmt = $this->pdo->prepare(
                "SELECT id
                   FROM suppliers
                  WHERE tenant_id = :tenant
                    AND id = :supplier
                    AND status = 'active'
                  FOR UPDATE"
            );

            $stmt->execute([
                ':tenant' => $tenantId,
                ':supplier' => (int) $counterpartyId,
            ]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Purchase receipt supplier must be active and belong to this tenant.'
                );
            }

            // Warehouse must belong to this tenant and be active.
            $stmt = $this->pdo->prepare(
                "SELECT id
                   FROM warehouses
                  WHERE tenant_id = :tenant
                    AND id = :warehouse
                    AND status = 'active'
                  FOR UPDATE"
            );

            $stmt->execute([
                ':tenant' => $tenantId,
                ':warehouse' => (int) $document['warehouse_id'],
            ]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Purchase receipt warehouse must be active and belong to this tenant.'
                );
            }

            $stmt = $this->pdo->prepare(
                "SELECT id, product_id, qty, unit_cost
                   FROM stock_document_lines
                  WHERE tenant_id = :tenant
                    AND document_id = :document
                  ORDER BY id
                  FOR UPDATE"
            );

            $stmt->execute([
                ':tenant' => $tenantId,
                ':document' => $documentId,
            ]);

            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$lines) {
                throw new RuntimeException(
                    'Cannot post a receipt with no lines.'
                );
            }

            $stmt = $this->pdo->prepare(
                "SELECT id
                   FROM users
                  WHERE tenant_id = :tenant
                    AND id = :actor
                    AND status = 'active'
                  FOR UPDATE"
            );

            $stmt->execute([
                ':tenant' => $tenantId,
                ':actor' => $actorId,
            ]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Posting user is inactive or outside this tenant.'
                );
            }

            $stmtProduct = $this->pdo->prepare(
                "SELECT id
                   FROM products
                  WHERE tenant_id = :tenant
                    AND id = :product
                    AND status = 'active'
                  FOR UPDATE"
            );


        $stmtInsert = $this->pdo->prepare(
            "INSERT INTO stock_movements (
                tenant_id,
                product_id,
                warehouse_id,
                movement_type,
                direction,
                quantity,
                unit_cost,
                source_type,
                source_reference,
                reference_type,
                reference_id,
                status,
                idempotency_key,
                request_hash,
                created_by,
                posted_by,
                occurred_at,
                posted_at
            ) VALUES (
                :tenant,
                :product,
                :warehouse,
                'PURCHASE',
                'in',
                :quantity,
                :unit_cost,
                'PURCHASE_RECEIPT',
                :reference,
                'stock_document',
                :reference_id,
                'posted',
                :idempotency,
                :hash,
                :created_by,
                :posted_by,
                :occurred,
                UTC_TIMESTAMP()
            )"
        );


            $movementIds = [];

            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];

                $stmtProduct->execute([
                    ':tenant' => $tenantId,
                    ':product' => $productId,
                ]);

                if (!$stmtProduct->fetchColumn()) {
                    throw new RuntimeException(
                        'Receipt contains an inactive or cross-tenant product.'
                    );
                }

                $qty = (string) $line['qty'];

                if (
                    !preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $qty) ||
                    (float) $qty <= 0
                ) {
                    throw new RuntimeException(
                        'Receipt line quantity must be positive.'
                    );
                }

                $cost = $line['unit_cost'] === null
                    ? null
                    : (string) $line['unit_cost'];

                if (
                    $cost !== null &&
                    (
                        !preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $cost) ||
                        (float) $cost < 0
                    )
                ) {
                    throw new RuntimeException(
                        'Invalid receipt unit cost.'
                    );
                }

                $lineId = (int) $line['id'];
                $warehouseId = (int) $document['warehouse_id'];

                $movementIdempotencyKey =
                    'receipt:' . $documentId . ':line:' . $lineId;

                $canonical = [
                    'document_id' => $documentId,
                    'line_id' => $lineId,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty' => $qty,
                    'unit_cost' => $cost,
                ];

                $hash = hash(
                    'sha256',
                    json_encode(
                        $canonical,
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    )
                );


                $stmtInsert->execute([
                    ':tenant' => $tenantId,
                    ':product' => $productId,
                    ':warehouse' => $warehouseId,
                    ':quantity' => $qty,
                    ':unit_cost' => $cost,
                    ':reference' => (string) $documentId,
                    ':reference_id' => $documentId,
                    ':idempotency' => $movementIdempotencyKey,
                    ':hash' => $hash,
                    ':created_by' => $actorId,
                    ':posted_by' => $actorId,
                    ':occurred' => $document['document_date'] . ' 00:00:00',
                ]);


                $movementId = (int) $this->pdo->lastInsertId();
                $movementIds[] = $movementId;

                audit_event(
                    'stock.movement_posted',
                    'stock_movement',
                    $movementId,
                    null,
                    [
                        'id' => $movementId,
                        'tenant_id' => $tenantId,
                        'product_id' => $productId,
                        'warehouse_id' => $warehouseId,
                        'movement_type' => 'PURCHASE',
                        'direction' => 'in',
                        'quantity' => $qty,
                        'source_type' => 'PURCHASE_RECEIPT',
                        'source_reference' => (string) $documentId,
                        'reference_type' => 'stock_document',
                        'reference_id' => $documentId,
                        'status' => 'posted',
                    ],
                    'Purchase receipt posted.'
                );
            }

            $stmt = $this->pdo->prepare(
                "UPDATE stock_documents
                    SET status = 'posted',
                        posted_by = :actor,
                        posted_at = UTC_TIMESTAMP()
                  WHERE tenant_id = :tenant
                    AND id = :document
                    AND status = 'draft'"
            );

            $stmt->execute([
                ':actor' => $actorId,
                ':tenant' => $tenantId,
                ':document' => $documentId,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Could not mark receipt as posted.'
                );
            }

            audit_event(
                'stock.document_posted',
                'stock_document',
                $documentId,
                ['status' => 'draft'],
                [
                    'status' => 'posted',
                    'movement_ids' => $movementIds,
                ],
                'Purchase receipt and its stock movements posted atomically.'
            );

            $response = [
                'document_id' => $documentId,
                'replayed' => false,
                'movement_ids' => $movementIds,
            ];
            $this->completePostRequest($tenantId, $idempotencyKey, $response);
            $this->pdo->commit();

            return $response;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Concurrent requests with the same key may race on the tenant-scoped unique index.
            if ($idempotencyKey !== null && $requestHash !== null && $this->isDuplicateKeyError($e)) {
                $existingRequest = $this->findPostRequest($tenantId, $idempotencyKey);
                if ($existingRequest !== null) {
                    if (!hash_equals((string) $existingRequest['request_hash'], $requestHash)) {
                        throw new DomainException(
                            'IDEMPOTENCY_KEY_REUSED: this key was used for a different request.',
                            0,
                            $e
                        );
                    }
                    if ($existingRequest['status'] === 'completed' && is_string($existingRequest['response_json'])) {
                        $savedResponse = json_decode(
                            $existingRequest['response_json'],
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        );
                        if (is_array($savedResponse)) {
                            return $savedResponse;
                        }
                    }
                }
            }

            throw $e;
        }
    }

    private function buildReceiptRequestHash(int $tenantId, int $documentId, array $document): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, product_id, qty, unit_cost
               FROM stock_document_lines
              WHERE tenant_id = :tenant AND document_id = :document
              ORDER BY id
              FOR UPDATE"
        );
        $stmt->execute([':tenant' => $tenantId, ':document' => $documentId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $canonicalLines = [];
        foreach ($lines as $line) {
            $canonicalLines[] = [
                'line_id' => (int) $line['id'],
                'product_id' => (int) $line['product_id'],
                'qty' => (string) $line['qty'],
                'unit_cost' => $line['unit_cost'] === null ? null : (string) $line['unit_cost'],
            ];
        }

        $canonical = [
            'operation' => 'post_purchase_receipt',
            'tenant_id' => $tenantId,
            'document_id' => $documentId,
            'type' => (string) $document['type'],
            'warehouse_id' => (int) $document['warehouse_id'],
            'counterparty_id' => $document['counterparty_id'] === null ? null : (int) $document['counterparty_id'],
            'document_date' => (string) $document['document_date'],
            'lines' => $canonicalLines,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function findPostRequest(int $tenantId, string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT request_hash, status, response_json, document_id
               FROM stock_document_post_requests
              WHERE tenant_id = :tenant AND idempotency_key = :key
              LIMIT 1'
        );
        $stmt->execute([':tenant' => $tenantId, ':key' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function completePostRequest(int $tenantId, ?string $idempotencyKey, array $response): void
    {
        if ($idempotencyKey === null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE stock_document_post_requests
                SET status = 'completed', response_json = :response
              WHERE tenant_id = :tenant AND idempotency_key = :key AND status = 'processing'"
        );
        $stmt->execute([
            ':response' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':tenant' => $tenantId,
            ':key' => $idempotencyKey,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Could not persist idempotency response.');
        }
    }

    private function isDuplicateKeyError(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $info = $e->errorInfo;
        return ($info[0] ?? null) === '23000' && (int) ($info[1] ?? 0) === 1062;
    }
}
