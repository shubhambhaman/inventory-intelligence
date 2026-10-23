-- Inventory Intelligence Platform
-- M-2 Ledger Core
-- Prevent deletion of posted ledger rows.

CREATE TRIGGER trg_stock_movements_prevent_posted_delete
BEFORE DELETE ON stock_movements
FOR EACH ROW
BEGIN
    IF OLD.status = 'posted' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Posted stock movements cannot be deleted.';
    END IF;
END
