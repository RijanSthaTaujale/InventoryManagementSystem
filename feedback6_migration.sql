-- ============================================================
-- Sixth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: product_variants.remarks column.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `product_variants`
  ADD COLUMN `remarks` TEXT DEFAULT NULL AFTER `buy_price`;
