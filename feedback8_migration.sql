-- ============================================================
-- Eighth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: admin-managed Shipping Method and Payment Method lists,
-- backfilled with the options that were previously hardcoded in the
-- order form.
-- ============================================================

USE `inventorymanagement`;

CREATE TABLE IF NOT EXISTS `shipping_methods` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100)  NOT NULL,
  `cost`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED  DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100)  NOT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED  DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `shipping_methods` (`name`, `cost`) VALUES
  ('Standard Shipping (Rs 100)', 100),
  ('Express Shipping (Rs 250)', 250),
  ('Regional Logistics', 150),
  ('Pickup', 0);

INSERT INTO `payment_methods` (`name`) VALUES
  ('Cash on Delivery'),
  ('eSewa'),
  ('Khalti'),
  ('Bank Transfer'),
  ('Card');
