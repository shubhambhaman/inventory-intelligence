-- ============================================================
-- Inventory Intelligence Platform
-- Migration: 008_create_categories_units_products
--
-- M1 Foundation:
-- - Categories
-- - Units
-- - Products
--
-- Source of truth:
-- Inventory Module Detailed Implementation Specification v1.0
-- ============================================================

-- ------------------------------------------------------------
-- Categories
-- ------------------------------------------------------------

CREATE TABLE categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(150) NOT NULL,
    name_normalized VARCHAR(150) NOT NULL,

    parent_id BIGINT UNSIGNED NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_categories_tenant_name_normalized (
        tenant_id,
        name_normalized
    ),

    KEY idx_categories_tenant (
        tenant_id
    ),

    KEY idx_categories_tenant_status (
        tenant_id,
        status
    ),

    KEY idx_categories_parent (
        tenant_id,
        parent_id
    ),

    CONSTRAINT fk_categories_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_categories_parent
        FOREIGN KEY (parent_id)
        REFERENCES categories(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Units
-- ------------------------------------------------------------

CREATE TABLE units (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(100) NOT NULL,
    name_normalized VARCHAR(100) NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_units_tenant_name_normalized (
        tenant_id,
        name_normalized
    ),

    KEY idx_units_tenant (
        tenant_id
    ),

    KEY idx_units_tenant_status (
        tenant_id,
        status
    ),

    CONSTRAINT fk_units_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Products
-- ------------------------------------------------------------

CREATE TABLE products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,

    sku VARCHAR(150) NOT NULL,
    sku_normalized VARCHAR(150) NOT NULL,

    name VARCHAR(255) NOT NULL,
    description TEXT NULL,

    category_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,

    brand VARCHAR(150) NULL,
    barcode VARCHAR(150) NULL,

    purchase_price DECIMAL(20,6) NULL,
    selling_price DECIMAL(20,6) NULL,
    tax_rate DECIMAL(10,4) NULL,

    preferred_supplier_id BIGINT UNSIGNED NULL,

    reorder_policy JSON NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'draft',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_products_tenant_sku_normalized (
        tenant_id,
        sku_normalized
    ),

    KEY idx_products_tenant (
        tenant_id
    ),

    KEY idx_products_tenant_status (
        tenant_id,
        status
    ),

    KEY idx_products_category (
        tenant_id,
        category_id
    ),

    KEY idx_products_unit (
        tenant_id,
        unit_id
    ),

    KEY idx_products_barcode (
        tenant_id,
        barcode
    ),

    CONSTRAINT fk_products_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_products_unit
        FOREIGN KEY (unit_id)
        REFERENCES units(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_products_purchase_price
        CHECK (
            purchase_price IS NULL
            OR purchase_price >= 0
        ),

    CONSTRAINT chk_products_selling_price
        CHECK (
            selling_price IS NULL
            OR selling_price >= 0
        ),

    CONSTRAINT chk_products_tax_rate
        CHECK (
            tax_rate IS NULL
            OR tax_rate >= 0
        )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;