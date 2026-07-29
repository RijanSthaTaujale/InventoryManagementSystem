-- ============================================================
-- Fourteenth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
--
-- Reworks Exchange: instead of swapping the item in-place on the same
-- order, Exchange now creates a brand-new order (same customer details,
-- full order-form capabilities) and the item(s) given up sit in a pending
-- queue until physically received back — that's when stock actually
-- changes, via the new "Exchange" page.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `orders`
  ADD COLUMN `exchanged_from_order_id` INT UNSIGNED NULL
    COMMENT 'If this order was created via Exchange, the original order it replaces items from'
    AFTER `assigned_to`,
  ADD CONSTRAINT `fk_orders_exchanged_from` FOREIGN KEY (`exchanged_from_order_id`)
    REFERENCES `orders`(`id`) ON DELETE SET NULL,
  ADD INDEX `idx_exchanged_from` (`exchanged_from_order_id`);

ALTER TABLE `order_returns`
  ADD COLUMN `new_order_id` INT UNSIGNED NULL
    COMMENT 'For is_exchange=1 rows: the new order created to hold the replacement item(s)'
    AFTER `returned_by`,
  ADD COLUMN `received_at` DATETIME NULL
    COMMENT 'For is_exchange=1 rows: when the item was physically received and stock/returned_qty were settled. NULL = pending receipt. Always NULL for plain (non-exchange) returns.',
  ADD COLUMN `received_damaged` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Set at receipt time if staff mark the received exchange item damaged (logged to Damaged Stock instead of restocked)',
  ADD CONSTRAINT `fk_order_returns_new_order` FOREIGN KEY (`new_order_id`)
    REFERENCES `orders`(`id`) ON DELETE SET NULL,
  ADD INDEX `idx_pending_exchange` (`order_item_id`, `is_exchange`, `received_at`);

-- Existing exchange rows were already settled immediately under the old
-- behavior — backfill so they don't spuriously appear in the new pending queue.
UPDATE `order_returns` SET `received_at` = `created_at`, `received_damaged` = `damaged` WHERE `is_exchange` = 1;
