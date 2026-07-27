-- ============================================================
-- Ninth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: 'damaged' flag on order_returns (so a returned/exchanged item
-- that came back damaged is logged to Damaged Stock instead of being
-- silently restocked for resale).
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `order_returns`
  ADD COLUMN `damaged` TINYINT(1) NOT NULL DEFAULT 0 AFTER `amount`;
