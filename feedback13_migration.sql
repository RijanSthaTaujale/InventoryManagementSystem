-- ============================================================
-- Thirteenth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Repurposes the unused `orders.courier_charge` column (its form field
-- was removed a while back and it was never wired into the total) into
-- `orders.extra_charge` — a flat surcharge staff can add on top of the
-- order total (e.g. packaging, rush fee), now actually applied in the
-- total calculation.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `orders`
  CHANGE COLUMN `courier_charge` `extra_charge` DECIMAL(10,2) NOT NULL DEFAULT 0
    COMMENT 'Extra flat charge added on top of the total (e.g. packaging, rush fee)';
