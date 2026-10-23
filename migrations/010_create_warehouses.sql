-- ============================================================
-- Migration 010
-- Create warehouses and user warehouse access
-- ============================================================

CREATE TABLE warehouses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,

    warehouse_code VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,

    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,

    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',

    parent_location_id BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_warehouses_tenant_code (
        tenant_id,
        warehouse_code
    ),

    /*
     * Required for tenant-safe composite foreign key
     * used by parent_location_id.
     */
    UNIQUE KEY uq_warehouses_tenant_id_id (
        tenant_id,
        id
    ),

    KEY idx_warehouses_tenant_status (
        tenant_id,
        status
    ),

    KEY idx_warehouses_tenant_parent (
        tenant_id,
        parent_location_id
    ),

    CONSTRAINT fk_warehouses_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,

    CONSTRAINT fk_warehouses_parent_tenant
        FOREIGN KEY (tenant_id, parent_location_id)
        REFERENCES warehouses (tenant_id, id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
);


CREATE TABLE user_warehouse_access (
    user_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (
        user_id,
        warehouse_id
    ),

    KEY idx_user_warehouse_access_warehouse (
        warehouse_id,
        user_id
    ),

    CONSTRAINT fk_user_warehouse_access_user
        FOREIGN KEY (user_id)
        REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,

    CONSTRAINT fk_user_warehouse_access_warehouse
        FOREIGN KEY (warehouse_id)
        REFERENCES warehouses (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
);