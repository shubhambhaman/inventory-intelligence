<?php

declare(strict_types=1);

namespace App\Inventory;

use PDO;
use RuntimeException;

/**
 * Read-only stock balance projection derived from the authoritative movement ledger.
 * Tenant scope is mandatory; this service never trusts tenant data from a request body.
 */
final class StockBalanceService
{
    /**
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function list(
        PDO $pdo,
        int $tenantId,
        ?int $productId,
        ?int $warehouseId,
        string $search,
        int $limit,
        int $offset,
        ?int $categoryId = null,
        ?string $productStatus = null,
        string $sortBy = 'product_name',
        string $sortDirection = 'asc'
    ): array {
        if ($tenantId < 1 || $limit < 1 || $limit > 100 || $offset < 0) {
            throw new RuntimeException('Invalid stock balance query scope.');
        }
        if ($categoryId !== null && $categoryId < 1) {
            throw new RuntimeException('Invalid category filter.');
        }
        if ($productStatus !== null && ($productStatus === '' || strlen($productStatus) > 50)) {
            throw new RuntimeException('Invalid product status filter.');
        }

        // Never interpolate request values into SQL identifiers or sort direction.
        $sortColumns = [
            'product_name' => 'p.name',
            'sku' => 'p.sku',
            'warehouse_name' => 'w.name',
            'on_hand' => 'on_hand',
        ];
        if (!isset($sortColumns[$sortBy]) || !in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new RuntimeException('Invalid stock balance sorting.');
        }
        $orderBy = $sortColumns[$sortBy] . ' ' . strtoupper($sortDirection);

        $where = ['p.tenant_id = :product_tenant_id', 'w.tenant_id = :warehouse_tenant_id'];
        $params = [
            ':ledger_tenant_id' => $tenantId,
            ':product_tenant_id' => $tenantId,
            ':warehouse_tenant_id' => $tenantId,
        ];

        if ($productId !== null) {
            $where[] = 'p.id = :product_id';
            $params[':product_id'] = $productId;
        }
        if ($warehouseId !== null) {
            $where[] = 'w.id = :warehouse_id';
            $params[':warehouse_id'] = $warehouseId;
        }
        if ($categoryId !== null) {
            $where[] = 'p.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }
        if ($productStatus !== null) {
            // This is the products.status lifecycle field, not an inventory health score.
            $where[] = 'p.status = :product_status';
            $params[':product_status'] = $productStatus;
        }
        if ($search !== '') {
            $where[] = '(p.sku LIKE :search_sku OR p.name LIKE :search_name OR p.barcode LIKE :search_barcode OR w.name LIKE :search_warehouse)';
            $needle = '%' . $search . '%';
            $params[':search_sku'] = $needle;
            $params[':search_name'] = $needle;
            $params[':search_barcode'] = $needle;
            $params[':search_warehouse'] = $needle;
        }

        $whereSql = implode(' AND ', $where);

        // Count only product/warehouse pairs represented in the posted ledger.
        // Use unique named placeholders for compatibility with native PDO prepares.
        $countWhere = ['p.tenant_id = :count_product_tenant', 'w.tenant_id = :count_warehouse_tenant'];
        $countParams = [
            ':count_ledger_tenant' => $tenantId,
            ':count_product_tenant' => $tenantId,
            ':count_warehouse_tenant' => $tenantId,
        ];
        if ($productId !== null) {
            $countWhere[] = 'p.id = :count_product_id';
            $countParams[':count_product_id'] = $productId;
        }
        if ($warehouseId !== null) {
            $countWhere[] = 'w.id = :count_warehouse_id';
            $countParams[':count_warehouse_id'] = $warehouseId;
        }
        if ($categoryId !== null) {
            $countWhere[] = 'p.category_id = :count_category_id';
            $countParams[':count_category_id'] = $categoryId;
        }
        if ($productStatus !== null) {
            $countWhere[] = 'p.status = :count_product_status';
            $countParams[':count_product_status'] = $productStatus;
        }
        if ($search !== '') {
            $countWhere[] = '(p.sku LIKE :count_search_sku OR p.name LIKE :count_search_name OR p.barcode LIKE :count_search_barcode OR w.name LIKE :count_search_warehouse)';
            $countNeedle = '%' . $search . '%';
            $countParams[':count_search_sku'] = $countNeedle;
            $countParams[':count_search_name'] = $countNeedle;
            $countParams[':count_search_barcode'] = $countNeedle;
            $countParams[':count_search_warehouse'] = $countNeedle;
        }
        $countSql = "
            SELECT COUNT(*)
            FROM (
                SELECT sm.product_id, sm.warehouse_id
                FROM stock_movements sm
                WHERE sm.tenant_id = :count_ledger_tenant
                  AND sm.status = 'posted'
                GROUP BY sm.product_id, sm.warehouse_id
            ) ledger_pairs
            INNER JOIN products p ON p.id = ledger_pairs.product_id
            INNER JOIN warehouses w ON w.id = ledger_pairs.warehouse_id
            WHERE " . implode(' AND ', $countWhere);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT
                p.id AS product_id,
                p.sku,
                p.name AS product_name,
                w.id AS warehouse_id,
                w.warehouse_code AS warehouse_code,
                w.name AS warehouse_name,
                CAST(SUM(CASE
                    WHEN sm.direction = 'in' THEN sm.quantity
                    WHEN sm.direction = 'out' THEN -sm.quantity
                    ELSE 0
                END) AS DECIMAL(20,6)) AS on_hand
            FROM stock_movements sm
            INNER JOIN products p
                ON p.tenant_id = sm.tenant_id
               AND p.id = sm.product_id
            INNER JOIN warehouses w
                ON w.tenant_id = sm.tenant_id
               AND w.id = sm.warehouse_id
            WHERE sm.tenant_id = :ledger_tenant_id
              AND sm.status = 'posted'
              AND {$whereSql}
            GROUP BY p.id, p.sku, p.name, w.id, w.warehouse_code, w.name
            ORDER BY {$orderBy}, p.id ASC, w.id ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['product_id'] = (int) $item['product_id'];
            $item['warehouse_id'] = (int) $item['warehouse_id'];
            // Preserve DECIMAL precision; do not convert quantities to float.
            $item['on_hand'] = (string) $item['on_hand'];
        }
        unset($item);

        return ['items' => $items, 'total' => $total];
    }
}
