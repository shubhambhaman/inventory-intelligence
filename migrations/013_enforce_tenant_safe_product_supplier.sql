-- Inventory Intelligence Platform
-- Migration 013: Enforce tenant-safe preferred supplier references.
-- Review existing data before applying this migration (see notes below).
--
-- Preflight query (must return zero rows before applying the FK):
-- SELECT p.id AS product_id, p.tenant_id AS product_tenant_id,
--        p.preferred_supplier_id, s.tenant_id AS supplier_tenant_id
-- FROM products p
-- LEFT JOIN suppliers s ON s.id = p.preferred_supplier_id
-- WHERE p.preferred_supplier_id IS NOT NULL
--   AND (s.id IS NULL OR s.tenant_id <> p.tenant_id);
--
-- If the preflight query returns rows, reconcile them deliberately before
-- applying the FK; do not silently null or reassign supplier references.

ALTER TABLE products
    ADD KEY idx_products_tenant_preferred_supplier (
        tenant_id,
        preferred_supplier_id
    );

ALTER TABLE products
    ADD CONSTRAINT fk_products_preferred_supplier_tenant
    FOREIGN KEY (
        tenant_id,
        preferred_supplier_id
    )
    REFERENCES suppliers (
        tenant_id,
        id
    )
    ON UPDATE RESTRICT
    ON DELETE RESTRICT;
