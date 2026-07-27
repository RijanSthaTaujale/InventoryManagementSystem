-- ============================================================
-- Twelfth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: orders.amount_paid — tracks how much has actually been
-- collected/prepaid so far, separate from the order's current total
-- (which can change via Exchange). Amount still owed to the courier
-- is derived as max(0, total - amount_paid), so a price-changing
-- exchange on an already-paid order correctly shows the difference
-- still due, instead of blindly showing 0.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `orders`
  ADD COLUMN `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `payment_status`;

-- Backfill: any order already marked 'paid' is assumed fully paid at its
-- current total; everything else starts at 0 (nothing collected yet).
UPDATE `orders` SET `amount_paid` = `total` WHERE `payment_status` = 'paid';
