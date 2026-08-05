-- ============================================================
-- Seventeenth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds dedicated edit tracking to orders, separate from updated_by
-- (which also gets touched by status/dispatch changes) so "who last
-- edited this order" is accurate to actual content edits only.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `orders`
  ADD COLUMN `edited_by` INT UNSIGNED DEFAULT NULL
    COMMENT 'Who last saved an edit via the order edit form — separate from updated_by, which also changes on status/dispatch updates'
    AFTER `updated_by`,
  ADD COLUMN `edited_at` DATETIME DEFAULT NULL
    AFTER `edited_by`,
  ADD CONSTRAINT `fk_orders_edited_by` FOREIGN KEY (`edited_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
