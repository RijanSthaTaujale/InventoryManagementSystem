-- ============================================================
-- Third Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: couriers table (+ backfill from existing orders);
--       order_items.variant_id (per-variant stock deduction).
-- ============================================================

USE `inventorymanagement`;

-- ── Admin-managed courier list ─────────────────────────────
CREATE TABLE IF NOT EXISTS `couriers` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(150)  NOT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED  DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `couriers` (`name`)
SELECT DISTINCT `courier_name` FROM `orders`
WHERE `courier_name` IS NOT NULL AND `courier_name` <> ''
  AND `courier_name` NOT IN (SELECT `name` FROM `couriers`);

-- ── Per-variant stock linkage on order line items ──────────
ALTER TABLE `order_items`
  ADD COLUMN `variant_id` INT UNSIGNED DEFAULT NULL AFTER `product_name`,
  ADD FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`) ON DELETE SET NULL;

-- ── Business email fix ──────────────────────────────────────
INSERT INTO `settings` (`key`, `value`) VALUES ('business_email', 'Pompoynepal@gmail.com')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
