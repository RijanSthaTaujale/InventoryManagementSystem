-- ============================================================
-- Phase 1 Migration — Inventory Pro
-- Run once against the `inventorymanagement` database.
-- Adds: fb_pages, customer_blacklist, damaged_products tables;
--       orders.fb_page_id/courier_name/courier_charge/supervisor_remarks/
--       stock_deducted/stock_restored; stock_adjustments.type 'sale';
--       duplicate-order lookup index.
-- ============================================================

-- ── Facebook page attribution ──────────────────────────────
CREATE TABLE IF NOT EXISTS `fb_pages` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(150)  NOT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED  DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phone-based customer blacklist ─────────────────────────
CREATE TABLE IF NOT EXISTS `customer_blacklist` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `phone`          VARCHAR(20)   NOT NULL,
  `reason`         TEXT          DEFAULT NULL,
  `blacklisted_by` INT UNSIGNED  DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`blacklisted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE INDEX `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Damaged stock log ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `damaged_products` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED  NOT NULL,
  `qty`        INT           NOT NULL,
  `reason`     TEXT          DEFAULT NULL,
  `logged_by`  INT UNSIGNED  DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`logged_by`)  REFERENCES `users`(`id`)    ON DELETE SET NULL,
  INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── orders: FB page attribution, courier, supervisor remarks, stock flags ──
ALTER TABLE `orders`
  ADD COLUMN `fb_page_id`         INT UNSIGNED  DEFAULT NULL AFTER `customer_address`,
  ADD COLUMN `courier_name`       VARCHAR(100)  DEFAULT NULL AFTER `shipping_cost`,
  ADD COLUMN `courier_charge`     DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `courier_name`,
  ADD COLUMN `supervisor_remarks` TEXT          DEFAULT NULL AFTER `remarks`,
  ADD COLUMN `stock_deducted`     TINYINT(1)    NOT NULL DEFAULT 0 AFTER `delivered_at`,
  ADD COLUMN `stock_restored`     TINYINT(1)    NOT NULL DEFAULT 0 AFTER `stock_deducted`,
  ADD FOREIGN KEY (`fb_page_id`) REFERENCES `fb_pages`(`id`) ON DELETE SET NULL,
  ADD INDEX `idx_phone_created` (`customer_phone`, `created_at`);

-- ── stock_adjustments: add 'sale' type for dispatch-time deduction ──
ALTER TABLE `stock_adjustments`
  MODIFY COLUMN `type` ENUM('add','remove','adjustment','initial','sale','return','damaged') NOT NULL DEFAULT 'adjustment';
