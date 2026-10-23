CREATE TABLE customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(150) NOT NULL,
    name_normalized VARCHAR(150) NOT NULL,

    code VARCHAR(100) NULL,
    contact_name VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(254) NULL,

    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_customers_tenant_name (
        tenant_id,
        name_normalized
    ),

    UNIQUE KEY uq_customers_tenant_code (
        tenant_id,
        code
    ),

    UNIQUE KEY uq_customers_tenant_id_id (
        tenant_id,
        id
    ),

    KEY idx_customers_tenant_status (
        tenant_id,
        status
    ),

    KEY idx_customers_tenant_name (
        tenant_id,
        name_normalized
    ),

    CONSTRAINT fk_customers_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
);