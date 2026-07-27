-- ============================================================
-- Eleventh Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: fb_page_id on products, so a product can carry its usual
-- Facebook Page and auto-fill it when added to a new order.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `products`
  ADD COLUMN `fb_page_id` INT UNSIGNED DEFAULT NULL AFTER `category_id`,
  ADD FOREIGN KEY (`fb_page_id`) REFERENCES `fb_pages`(`id`) ON DELETE SET NULL;
