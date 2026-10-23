-- Inventory Intelligence Platform
-- M-2 Ledger Core: align stock_movements with the approved ledger model.
-- MySQL/MariaDB. Do not run until the updated service file is in place.

ALTER TABLE stock_movements
    ADD COLUMN unit_cost DECIMAL(20,6) NULL AFTER quantity,
    MODIFY COLUMN movement_type ENUM(
        'OPENING',
        'PURCHASE',
        'SALE',
        'RETURN_IN',
        'RETURN_OUT',
        'TRANSFER_IN',
        'TRANSFER_OUT',
        'ADJUSTMENT'
    ) NOT NULL,
    MODIFY COLUMN idempotency_key VARCHAR(255) NOT NULL,
    MODIFY COLUMN request_hash CHAR(64) NOT NULL;
