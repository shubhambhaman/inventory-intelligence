-- ============================================================
-- Inventory Intelligence Platform
-- Migration: 009_strengthen_m1_tenant_foreign_keys
--
-- Purpose:
-- Enforce tenant-safe relationships between:
-- - categories -> parent category
-- - products -> categories
-- - products -> units
--
-- This prevents cross-tenant foreign-key references at DB level.
-- ============================================================


-- ------------------------------------------------------------
-- Categories
-- ------------------------------------------------------------

ALTER TABLE categories
    ADD UNIQUE KEY uq_categories_tenant_id_id (
        tenant_id,
        id
    );


-- ------------------------------------------------------------
-- Units
-- ------------------------------------------------------------

ALTER TABLE units
    ADD UNIQUE KEY uq_units_tenant_id_id (
        tenant_id,
        id
    );


-- ------------------------------------------------------------
-- Products
-- ------------------------------------------------------------

ALTER TABLE products
    ADD UNIQUE KEY uq_products_tenant_id_id (
        tenant_id,
        id
    );


-- ------------------------------------------------------------
-- Remove old single-column category parent FK
-- ------------------------------------------------------------

ALTER TABLE categories
    DROP FOREIGN KEY fk_categories_parent;


-- ------------------------------------------------------------
-- Add tenant-safe category parent FK
-- ------------------------------------------------------------

ALTER TABLE categories
    ADD CONSTRAINT fk_categories_parent_tenant
    FOREIGN KEY (
        tenant_id,
        parent_id
    )
    REFERENCES categories (
        tenant_id,
        id
    )
    ON UPDATE CASCADE
    ON DELETE RESTRICT;


-- ------------------------------------------------------------
-- Remove old product category FK
-- ------------------------------------------------------------

ALTER TABLE products
    DROP FOREIGN KEY fk_products_category;


-- ------------------------------------------------------------
-- Add tenant-safe product category FK
-- ------------------------------------------------------------

ALTER TABLE products
    ADD CONSTRAINT fk_products_category_tenant
    FOREIGN KEY (
        tenant_id,
        category_id
    )
    REFERENCES categories (
        tenant_id,
        id
    )
    ON UPDATE CASCADE
    ON DELETE RESTRICT;


-- ------------------------------------------------------------
-- Remove old product unit FK
-- ------------------------------------------------------------

ALTER TABLE products
    DROP FOREIGN KEY fk_products_unit;


-- ------------------------------------------------------------
-- Add tenant-safe product unit FK
-- ------------------------------------------------------------

ALTER TABLE products
    ADD CONSTRAINT fk_products_unit_tenant
    FOREIGN KEY (
        tenant_id,
        unit_id
    )
    REFERENCES units (
        tenant_id,
        id
    )
    ON UPDATE CASCADE
    ON DELETE RESTRICT;