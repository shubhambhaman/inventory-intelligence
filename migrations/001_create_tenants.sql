-- ============================================================
-- Inventory Intelligence Platform
-- Migration: 001_create_tenants
-- Purpose: Create tenant/company foundation
-- ============================================================

CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    name VARCHAR(150) NOT NULL,

    legal_name VARCHAR(150) NULL,

    country VARCHAR(100) NOT NULL DEFAULT 'India',

    currency CHAR(3) NOT NULL DEFAULT 'INR',

    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',

    fiscal_year_start DATE NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;