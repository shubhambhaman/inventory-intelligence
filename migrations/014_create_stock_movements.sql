
-- ============================================================
-- Inventory Intelligence Platform
-- Migration: 014_create_stock_movements
-- Milestone: M-2 Ledger Core
-- Purpose: Create the append-oriented stock movement ledger
-- Database: MySQL / MariaDB
-- ============================================================

CREATE TABLE stock_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    tenant_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,

    -- Movement classification
    movement_type VARCHAR(50) NOT NULL,

    -- Quantity remains positive; direction determines stock impact.
    direction ENUM('in', 'out') NOT NULL,
    quantity DECIMAL(20,6) NOT NULL,

    -- Originating business record
    source_type VARCHAR(50) NOT NULL,
    source_reference VARCHAR(100) NOT NULL,

    -- Ledger lifecycle
    status ENUM('pending', 'posted', 'reversed')
        NOT NULL DEFAULT 'pending',

    -- Compensating movement reference
    reversal_of_movement_id BIGINT UNSIGNED NULL,

    -- Idempotency fields
    idempotency_key VARCHAR(255) NULL,
    request_hash CHAR(64) NULL,

    -- Actor references
    created_by BIGINT UNSIGNED NOT NULL,
    posted_by BIGINT UNSIGNED NULL,

    -- Timestamps
    occurred_at DATETIME NOT NULL,
    posted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Tenant-scoped unique identifier for composite foreign keys.
    UNIQUE KEY uq_stock_movements_tenant_id_id (
        tenant_id,
        id
    ),

    -- One idempotency key per tenant.
    UNIQUE KEY uq_stock_movements_tenant_idempotency (
        tenant_id,
        idempotency_key
    ),

    -- One direct reversal link per original movement.
    UNIQUE KEY uq_stock_movements_reversal (
        tenant_id,
        reversal_of_movement_id
    ),

    -- Ledger query indexes
    KEY idx_stock_movements_tenant_product_time (
        tenant_id,
        product_id,
        occurred_at,
        id
    ),

    KEY idx_stock_movements_tenant_warehouse_time (
        tenant_id,
        warehouse_id,
        occurred_at,
        id
    ),

    KEY idx_stock_movements_tenant_status (
        tenant_id,
        status,
        created_at
    ),

    KEY idx_stock_movements_source (
        tenant_id,
        source_type,
        source_reference
    ),

    KEY idx_stock_movements_created_by (
        tenant_id,
        created_by
    ),

    KEY idx_stock_movements_posted_by (
        tenant_id,
        posted_by
    ),

    -- Tenant reference
    CONSTRAINT fk_stock_movements_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants (id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    -- Product must belong to the same tenant.
    CONSTRAINT fk_stock_movements_product_tenant
        FOREIGN KEY (tenant_id, product_id)
        REFERENCES products (tenant_id, id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    -- Warehouse must belong to the same tenant.
    CONSTRAINT fk_stock_movements_warehouse_tenant
        FOREIGN KEY (tenant_id, warehouse_id)
        REFERENCES warehouses (tenant_id, id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    -- Creator must belong to the same tenant.
    CONSTRAINT fk_stock_movements_created_by_tenant
        FOREIGN KEY (tenant_id, created_by)
        REFERENCES users (tenant_id, id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    -- Poster must belong to the same tenant, when present.
    CONSTRAINT fk_stock_movements_posted_by_tenant
        FOREIGN KEY (tenant_id, posted_by)
        REFERENCES users (tenant_id, id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    -- Reversal must reference a movement in the same tenant.
    CONSTRAINT fk_stock_movements_reversal_tenant
        FOREIGN KEY (tenant_id, reversal_of_movement_id)
        REFERENCES stock_movements (tenant_id, id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    -- Quantity must always be positive.
    CONSTRAINT chk_stock_movements_quantity
        CHECK (quantity > 0),

    -- Hash must be a 64-character hexadecimal SHA-256 hash, if present.
    CONSTRAINT chk_stock_movements_request_hash
        CHECK (
            request_hash IS NULL
            OR request_hash REGEXP '^[0-9a-fA-F]{64}$'
        ),

    -- Posted movements require posting actor and timestamp.
    CONSTRAINT chk_stock_movements_posting_state
        CHECK (
            status <> 'posted'
            OR (
                posted_at IS NOT NULL
                AND posted_by IS NOT NULL
            )
        )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
