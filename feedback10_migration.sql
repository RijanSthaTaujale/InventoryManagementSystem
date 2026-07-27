-- ============================================================
-- Tenth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: reliable exchange identification (a real flag, not just a
-- free-text reason that a custom note can silently erase).
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `order_returns`
  ADD COLUMN `is_exchange` TINYINT(1) NOT NULL DEFAULT 0 AFTER `damaged`;
