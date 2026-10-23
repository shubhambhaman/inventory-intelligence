<?php

declare(strict_types=1);

namespace App\Inventory;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/StockMovementModel.php';
require_once __DIR__ . '/../../config/audit.php';

/**
 * Internal stock movement posting service.
 *
 * Call only from an authenticated, CSRF-protected API/business workflow
 * that has already enforced the appropriate stock-movement permission.
 * Tenant and actor are never accepted from the request payload.
 *
 * Each successful posting and its audit event share one PDO transaction.
 * Stock balances are derived from posted ledger movements.
 */
final class StockMovementPostingService
{
    /**
     * Create and post one movement atomically with its audit event.
     *
     * @return array{movement_id:int,status:string,idempotent_replay:bool}
     */
    public static function post(array $input): array
    {
        // InnoDB may choose one concurrent transaction as a deadlock victim.
        // Retry the complete atomic operation after the attempt has rolled back.
        // Idempotency makes an uncertain commit safe to replay as well.
        $maxAttempts = 3;
        for ($attempt = 1; ; $attempt++) {
            try {
                return self::postAttempt($input);
            } catch (PDOException $e) {
                $mysqlError = (int) ($e->errorInfo[1] ?? 0);
                $retryable = in_array($mysqlError, [1213, 1205], true);
                if (!$retryable || $attempt >= $maxAttempts) {
                    throw $e;
                }

                // Short bounded jitter reduces the chance of the same collision
                // repeating immediately. No DB transaction remains open here.
                usleep(random_int(30000, 90000) * $attempt);
            }
        }
    }

    /** Execute one atomic posting attempt; failures are rolled back before retry. */
    private static function postAttempt(array $input): array
    {
        global $pdo;

        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection is unavailable.');
        }

        if (
            session_status() !== PHP_SESSION_ACTIVE
            || empty($_SESSION['authenticated'])
        ) {
            throw new RuntimeException(
                'An authenticated session is required to post stock movements.'
            );
        }

        $actorId = self::positiveSessionId($_SESSION['user_id'] ?? null);
        $tenantId = self::positiveSessionId($_SESSION['tenant_id'] ?? null);

        if ($actorId === null || $tenantId === null) {
            throw new RuntimeException(
                'Authenticated tenant/user context is missing.'
            );
        }

        $payload = StockMovementModel::validateAndNormalize($input);

        $requestJson = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        );
        $requestHash = hash('sha256', $requestJson);

        try {
            // Use a fresh committed view for each consistent read in this
            // transaction. The idempotency lookup below is a plain SELECT;
            // under REPEATABLE READ it can establish a snapshot before this
            // request waits for the product-row lock. READ COMMITTED prevents
            // the later stock check from reusing that older snapshot.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();

            // Do not lock a missing idempotency-key index range here. Under
            // InnoDB REPEATABLE READ, two different keys can acquire overlapping
            // gap locks and deadlock before either request reaches the product
            // row lock. The unique tenant/idempotency constraint plus duplicate-
            // key replay handling below protects concurrent duplicate requests.
            // The product row is locked next, serializing stock-sensitive posts.
            $existing = self::findByIdempotencyKey(
                $pdo,
                $tenantId,
                $payload['idempotency_key'],
                false
            );

            if ($existing !== null) {
                self::assertIdempotentReplay($existing, $requestHash);
                $pdo->commit();

                return [
                    'movement_id' => (int) $existing['id'],
                    'status' => 'posted',
                    'idempotent_replay' => true,
                ];
            }

            self::assertActiveProduct(
                $pdo,
                $tenantId,
                $payload['product_id']
            );
            self::assertActiveWarehouse(
                $pdo,
                $tenantId,
                $payload['warehouse_id']
            );

            // Product row is locked above, serializing postings for this SKU.
            // Check the ledger balance before accepting any outbound movement.
            if ($payload['direction'] === 'out') {
                self::assertSufficientStock(
                    $pdo,
                    $tenantId,
                    $payload['product_id'],
                    $payload['warehouse_id'],
                    $payload['quantity']
                );
            }

            $stmt = $pdo->prepare(
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
                    status,
                    idempotency_key,
                    request_hash,
                    created_by,
                    posted_by,
                    occurred_at,
                    posted_at
                ) VALUES (
                    :tenant_id,
                    :product_id,
                    :warehouse_id,
                    :movement_type,
                    :direction,
                    :quantity,
                    :unit_cost,
                    :source_type,
                    :source_reference,
                    'posted',
                    :idempotency_key,
                    :request_hash,
                    :created_by,
                    :posted_by,
                    :occurred_at,
                    CURRENT_TIMESTAMP
                )"
            );

            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':product_id' => $payload['product_id'],
                ':warehouse_id' => $payload['warehouse_id'],
                ':movement_type' => $payload['movement_type'],
                ':direction' => $payload['direction'],
                ':quantity' => $payload['quantity'],
                ':unit_cost' => $payload['unit_cost'],
                ':source_type' => $payload['source_type'],
                ':source_reference' => $payload['source_reference'],
                ':idempotency_key' => $payload['idempotency_key'],
                ':request_hash' => $requestHash,
                ':created_by' => $actorId,
                ':posted_by' => $actorId,
                ':occurred_at' => $payload['occurred_at'],
            ]);

            $movementId = (int) $pdo->lastInsertId();

            /*
             * audit_event() obtains tenant_id, actor_id and request_id
             * from the authenticated session and inserts through global $pdo.
             * Because the same PDO transaction is active, audit failure rolls
             * back the stock movement too.
             */
            \audit_event(
                'stock.movement_posted',
                'stock_movement',
                $movementId,
                null,
                [
                    'id' => $movementId,
                    'tenant_id' => $tenantId,
                    'product_id' => $payload['product_id'],
                    'warehouse_id' => $payload['warehouse_id'],
                    'movement_type' => $payload['movement_type'],
                    'direction' => $payload['direction'],
                    'quantity' => $payload['quantity'],
                    'unit_cost' => $payload['unit_cost'],
                    'source_type' => $payload['source_type'],
                    'source_reference' => $payload['source_reference'],
                    'status' => 'posted',
                    'created_by' => $actorId,
                    'posted_by' => $actorId,
                    'occurred_at' => $payload['occurred_at'],
                    'request_hash' => $requestHash,
                ],
                'Stock movement posted through the internal posting service.'
            );

            $pdo->commit();

            return [
                'movement_id' => $movementId,
                'status' => 'posted',
                'idempotent_replay' => false,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof PDOException && self::isDuplicateKeyError($e)) {
                /*
                 * Another concurrent request may have inserted the same key.
                 * Read the winner only after this transaction has rolled back.
                 */
                $existing = self::findByIdempotencyKey(
                    $pdo,
                    $tenantId,
                    $payload['idempotency_key'],
                    false
                );

                if ($existing !== null) {
                    self::assertIdempotentReplay($existing, $requestHash);

                    return [
                        'movement_id' => (int) $existing['id'],
                        'status' => 'posted',
                        'idempotent_replay' => true,
                    ];
                }
            }

            throw $e;
        }
    }

    private static function assertActiveProduct(
        PDO $pdo,
        int $tenantId,
        int $productId
    ): void {
        $stmt = $pdo->prepare(
            "SELECT id, status
               FROM products
              WHERE tenant_id = :tenant_id
                AND id = :product_id
              LIMIT 1
              FOR UPDATE"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
        ]);

        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new StockMovementConflictException(
                'The selected product does not exist in this tenant.'
            );
        }

        if (($product['status'] ?? null) !== 'active') {
            throw new StockMovementConflictException(
                'Stock movements can only be posted for active products.'
            );
        }
    }

    /**
     * Reject outbound movements that would make the posted ledger balance negative.
     * The caller must hold the product row lock before calling this method.
     * FOR UPDATE makes this a current/locking read under InnoDB, avoiding a stale
     * REPEATABLE READ snapshot created by the earlier idempotency lookup.
     */
    private static function assertSufficientStock(
        PDO $pdo,
        int $tenantId,
        int $productId,
        int $warehouseId,
        string $requestedQuantity
    ): void {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(
                    CASE WHEN direction = 'in' THEN quantity ELSE -quantity END
                ), 0) AS available_quantity,
                (
                    COALESCE(SUM(
                        CASE WHEN direction = 'in' THEN quantity ELSE -quantity END
                    ), 0) < CAST(:requested_quantity AS DECIMAL(20,6))
                ) AS insufficient_stock
             FROM stock_movements
             WHERE tenant_id = :tenant_id
               AND product_id = :product_id
               AND warehouse_id = :warehouse_id
               AND status = 'posted'
             FOR UPDATE"
        );
        $stmt->execute([
            ':requested_quantity' => $requestedQuantity,
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
            ':warehouse_id' => $warehouseId,
        ]);
        $balance = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$balance) {
            throw new RuntimeException('Unable to calculate available stock.');
        }

        if ((int) $balance['insufficient_stock'] === 1) {
            throw new StockMovementConflictException(
                'Insufficient stock. Available: '
                . (string) $balance['available_quantity']
                . '; requested: ' . $requestedQuantity . '.'
            );
        }
    }

    private static function assertActiveWarehouse(
        PDO $pdo,
        int $tenantId,
        int $warehouseId
    ): void {
        $stmt = $pdo->prepare(
            "SELECT id, status
               FROM warehouses
              WHERE tenant_id = :tenant_id
                AND id = :warehouse_id
              LIMIT 1
              FOR UPDATE"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':warehouse_id' => $warehouseId,
        ]);

        $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$warehouse) {
            throw new StockMovementConflictException(
                'The selected warehouse does not exist in this tenant.'
            );
        }

        if (($warehouse['status'] ?? null) !== 'active') {
            throw new StockMovementConflictException(
                'Stock movements can only be posted to active warehouses.'
            );
        }
    }

    private static function findByIdempotencyKey(
        PDO $pdo,
        int $tenantId,
        string $idempotencyKey,
        bool $forUpdate
    ): ?array {
        $sql = "
            SELECT id, status, request_hash
              FROM stock_movements
             WHERE tenant_id = :tenant_id
               AND idempotency_key = :idempotency_key
             LIMIT 1
        ";

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':idempotency_key' => $idempotencyKey,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function assertIdempotentReplay(
        array $existing,
        string $requestHash
    ): void {
        if (!hash_equals(
            strtolower((string) ($existing['request_hash'] ?? '')),
            strtolower($requestHash)
        )) {
            throw new StockMovementConflictException(
                'This idempotency_key was already used with a different payload.'
            );
        }

        if (($existing['status'] ?? null) !== 'posted') {
            throw new StockMovementConflictException(
                'This idempotency_key belongs to a movement that is not posted.'
            );
        }
    }

    private static function positiveSessionId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $normalized = ltrim($value, '0');
        $maxInteger = (string) PHP_INT_MAX;

        if (
            $normalized === ''
            || strlen($normalized) > strlen($maxInteger)
            || (
                strlen($normalized) === strlen($maxInteger)
                && strcmp($normalized, $maxInteger) > 0
            )
        ) {
            return null;
        }

        $id = (int) $normalized;

        return $id > 0 ? $id : null;
    }

    private static function isDuplicateKeyError(PDOException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}

final class StockMovementConflictException extends RuntimeException
{
}
