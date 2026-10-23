CREATE TABLE suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(150) NOT NULL,
    name_normalized VARCHAR(150) NOT NULL,

    code VARCHAR(100) NULL,
    contact_name VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(254) NULL,

    lead_time_days DECIMAL(10,2) NULL,
    payment_terms_days INT UNSIGNED NULL,

    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_suppliers_tenant_name (
        tenant_id,
        name_normalized
    ),

    UNIQUE KEY uq_suppliers_tenant_code (
        tenant_id,
        code
    ),

    UNIQUE KEY uq_suppliers_tenant_id_id (
        tenant_id,
        id
    ),

    KEY idx_suppliers_tenant_status (
        tenant_id,
        status
    ),

    KEY idx_suppliers_tenant_name (
        tenant_id,
        name_normalized
    ),

    CONSTRAINT fk_suppliers_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,

    CONSTRAINT chk_suppliers_lead_time
        CHECK (
            lead_time_days IS NULL
            OR lead_time_days >= 0
        ),

    CONSTRAINT chk_suppliers_payment_terms
        CHECK (
            payment_terms_days IS NULL
            OR payment_terms_days >= 0
        )
);