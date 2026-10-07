-- ============================================================
-- Inventory Intelligence Platform
-- Migration: 002_create_users
-- Purpose: Create tenant-scoped users
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    tenant_id BIGINT UNSIGNED NOT NULL,

    email VARCHAR(255) NOT NULL,

    name VARCHAR(150) NOT NULL,

    password_hash VARCHAR(255) NOT NULL,

    role VARCHAR(50) NOT NULL DEFAULT 'owner',

    role_id BIGINT UNSIGNED NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'active',

    last_login_at TIMESTAMP NULL DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_users_tenant_email (
        tenant_id,
        email
    ),

    KEY idx_users_tenant (
        tenant_id
    ),

    KEY idx_users_status (
        tenant_id,
        status
    ),

    KEY idx_users_role_id (
        role_id
    ),

    CONSTRAINT fk_users_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;