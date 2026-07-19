-- ============================================================
-- Seventh Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: partial per-item order returns.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `order_items`
  ADD COLUMN `returned_qty` INT NOT NULL DEFAULT 0 AFTER `qty`;

CREATE TABLE IF NOT EXISTS `order_returns` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`      INT UNSIGNED   NOT NULL,
  `order_item_id` INT UNSIGNED   NOT NULL,
  `qty`           INT            NOT NULL,
  `amount`        DECIMAL(12,2)  NOT NULL DEFAULT 0 COMMENT 'Value deducted from order total',
  `reason`        TEXT           DEFAULT NULL,
  `returned_by`   INT UNSIGNED   DEFAULT NULL,
  `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`)      REFERENCES `orders`(`id`)      ON DELETE CASCADE,
  FOREIGN KEY (`order_item_id`) REFERENCES `order_items`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`returned_by`)   REFERENCES `users`(`id`)       ON DELETE SET NULL,
  INDEX `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
