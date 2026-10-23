-- Inventory Intelligence Platform
-- M-2 Ledger Core: Stock Movement Model integrity checks.
-- Do not execute until reviewed.

ALTER TABLE stock_movements
    ADD CONSTRAINT chk_stock_movements_unit_cost
        CHECK (unit_cost IS NULL OR unit_cost >= 0),

    ADD CONSTRAINT chk_stock_movements_type_direction
        CHECK (
            (
                movement_type IN (
                    'OPENING',
                    'PURCHASE',
                    'RETURN_IN',
                    'TRANSFER_IN'
                )
                AND direction = 'in'
            )
            OR
            (
                movement_type IN (
                    'SALE',
                    'RETURN_OUT',
                    'TRANSFER_OUT'
                )
                AND direction = 'out'
            )
            OR movement_type = 'ADJUSTMENT'
        );
