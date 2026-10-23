-- Inventory Intelligence Platform
-- M-2 Ledger Core
-- Allow pending -> posted, but prevent updates to already-posted rows.

CREATE TRIGGER trg_stock_movements_prevent_posted_update
BEFORE UPDATE ON stock_movements
FOR EACH ROW
BEGIN
    IF OLD.status = 'posted' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Posted stock movements are immutable. Create a compensating movement.';
    END IF;
END
