
-- Migration 020: Link stock movements to tenant-scoped source documents.
-- Existing source_type/source_reference columns remain unchanged.
-- Legacy rows retain NULL reference_type/reference_id.

ALTER TABLE stock_movements
    ADD COLUMN reference_type VARCHAR(50) NULL
        AFTER source_reference,
    ADD COLUMN reference_id BIGINT UNSIGNED NULL
        AFTER reference_type,
    ADD INDEX idx_stock_movements_source_document
        (tenant_id, reference_type, reference_id),
    ADD CONSTRAINT chk_stock_movements_reference_pair
        CHECK (
            (reference_type IS NULL AND reference_id IS NULL)
            OR
            (
                reference_type = 'stock_document'
                AND reference_id IS NOT NULL
            )
        ),
    ADD CONSTRAINT fk_stock_movements_source_document_tenant
        FOREIGN KEY (tenant_id, reference_id)
        REFERENCES stock_documents (tenant_id, id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT;
