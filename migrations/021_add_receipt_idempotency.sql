
-- Inventory Intelligence Platform
-- Migration: 021_add_receipt_idempotency
-- Purpose: Persist receipt-post request idempotency.
-- MySQL / MariaDB compatible.

CREATE TABLE stock_document_post_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    response_json LONGTEXT NULL,
    status ENUM('processing', 'completed')
        NOT NULL DEFAULT 'processing',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_stock_doc_post_request_tenant_key
        (tenant_id, idempotency_key),

    KEY idx_stock_doc_post_request_document
        (tenant_id, document_id),

    CONSTRAINT fk_stock_doc_post_request_document
        FOREIGN KEY (tenant_id, document_id)
        REFERENCES stock_documents (tenant_id, id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    CONSTRAINT chk_stock_doc_post_request_hash
        CHECK (request_hash REGEXP '^[0-9a-fA-F]{64}$'),

    CONSTRAINT chk_stock_doc_post_request_response
        CHECK (
            (status = 'processing' AND response_json IS NULL)
            OR
            (status = 'completed' AND response_json IS NOT NULL)
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
