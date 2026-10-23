
-- Inventory Intelligence Platform
-- Migration: 019_create_stock_documents
-- Database: MySQL / MariaDB
-- Target: dedicated test database first

CREATE TABLE stock_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,

    type VARCHAR(50) NOT NULL,
    status ENUM('draft', 'posted', 'cancelled')
        NOT NULL DEFAULT 'draft',

    warehouse_id BIGINT UNSIGNED NOT NULL,
    counterparty_id BIGINT UNSIGNED NULL,

    reference_no VARCHAR(100) NULL,
    document_date DATE NOT NULL,
    notes TEXT NULL,

    created_by BIGINT UNSIGNED NOT NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_stock_documents_tenant_id (tenant_id, id),
    KEY idx_stock_documents_tenant_status_date
        (tenant_id, status, document_date),
    KEY idx_stock_documents_tenant_type
        (tenant_id, type),
    KEY idx_stock_documents_tenant_reference
        (tenant_id, reference_no),

    CONSTRAINT fk_stock_documents_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT fk_stock_documents_warehouse_tenant
        FOREIGN KEY (tenant_id, warehouse_id)
        REFERENCES warehouses (tenant_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT fk_stock_documents_created_by_tenant
        FOREIGN KEY (tenant_id, created_by)
        REFERENCES users (tenant_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT fk_stock_documents_posted_by_tenant
        FOREIGN KEY (tenant_id, posted_by)
        REFERENCES users (tenant_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT chk_stock_documents_posted_state
        CHECK (
            status <> 'posted'
            OR (posted_by IS NOT NULL AND posted_at IS NOT NULL)
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE stock_document_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,

    qty DECIMAL(20,6) NOT NULL,
    unit_cost DECIMAL(20,6) NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_stock_document_lines_document
        (tenant_id, document_id),
    KEY idx_stock_document_lines_product
        (tenant_id, product_id),

    CONSTRAINT fk_stock_document_lines_document_tenant
        FOREIGN KEY (tenant_id, document_id)
        REFERENCES stock_documents (tenant_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT fk_stock_document_lines_product_tenant
        FOREIGN KEY (tenant_id, product_id)
        REFERENCES products (tenant_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT chk_stock_document_lines_qty
        CHECK (qty > 0),

    CONSTRAINT chk_stock_document_lines_unit_cost
        CHECK (unit_cost IS NULL OR unit_cost >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
