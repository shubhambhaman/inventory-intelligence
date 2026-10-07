-- ============================================================
-- Inventory Intelligence Platform
-- Migration: 004_create_permissions
-- Purpose: Create RBAC permissions
-- ============================================================

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    permission_key VARCHAR(100) NOT NULL,

    display_name VARCHAR(150) NOT NULL,

    description VARCHAR(255) NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_permissions_key (
        permission_key
    )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;