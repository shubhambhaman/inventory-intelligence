-- ============================================================
-- Inventory Intelligence Platform
-- Migration: 007_create_audit_events
-- Purpose: Append-only audit trail
-- ============================================================

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    tenant_id BIGINT UNSIGNED NOT NULL,

    actor_id BIGINT UNSIGNED NOT NULL,

    event_type VARCHAR(100) NOT NULL,

    entity_type VARCHAR(100) NOT NULL,

    entity_id VARCHAR(100) NOT NULL,

    before_json JSON NULL,

    after_json JSON NULL,

    reason TEXT NULL,

    request_id VARCHAR(100) NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_audit_tenant_created (
        tenant_id,
        created_at
    ),

    KEY idx_audit_tenant_entity (
        tenant_id,
        entity_type,
        entity_id
    ),

    KEY idx_audit_tenant_actor (
        tenant_id,
        actor_id,
        created_at
    ),

    KEY idx_audit_tenant_event (
        tenant_id,
        event_type,
        created_at
    ),

    CONSTRAINT fk_audit_events_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_audit_events_actor
        FOREIGN KEY (actor_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;