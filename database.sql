-- ============================================================
-- Inventory Pro — Complete Database Schema
-- MySQL 8.0+
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================
-- 1. USERS & AUTH (already partially exists — extended here)
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(120)  NOT NULL,
  `display_name`  VARCHAR(60)   DEFAULT NULL,
  `email`         VARCHAR(180)  NOT NULL UNIQUE,
  `password`      VARCHAR(255)  NOT NULL,
  `role`          ENUM('admin','staff','supervisor') NOT NULL DEFAULT 'staff',
  `photo`         VARCHAR(255)  DEFAULT NULL,
  `status`        ENUM('active','inactive','deactivated') NOT NULL DEFAULT 'active',
  `last_login`    DATETIME      DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_role` (`role`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`      VARCHAR(180) NOT NULL,
  `token`      VARCHAR(100) NOT NULL UNIQUE,
  `expires_at` DATETIME     NOT NULL,
  `used`       TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(100) NOT NULL UNIQUE,
  `ip`         VARCHAR(45)  DEFAULT NULL,
  `user_agent` TEXT         DEFAULT NULL,
  `expires_at` DATETIME     NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_token` (`token`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. CATEGORIES
-- ============================================================

CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(120) NOT NULL UNIQUE,
  `description` TEXT         DEFAULT NULL,
  `parent_id`   INT UNSIGNED DEFAULT NULL,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  INDEX `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. PRODUCTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `products` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id`        VARCHAR(30)    NOT NULL UNIQUE COMMENT 'e.g. PRD-0001',
  `name`              VARCHAR(200)   NOT NULL,
  `slug`              VARCHAR(220)   NOT NULL UNIQUE,
  `category_id`       INT UNSIGNED   DEFAULT NULL,
  `brand`             VARCHAR(100)   DEFAULT NULL,
  `sku`               VARCHAR(80)    DEFAULT NULL UNIQUE,
  `description`       TEXT           DEFAULT NULL,
  `buy_price`         DECIMAL(12,2)  NOT NULL DEFAULT 0 COMMENT 'Cost price',
  `sell_price`        DECIMAL(12,2)  NOT NULL DEFAULT 0 COMMENT 'Selling price',
  `image_url`         VARCHAR(255)   DEFAULT NULL,
  `quantity`          INT            NOT NULL DEFAULT 0,
  `min_stock_level`   INT            NOT NULL DEFAULT 5  COMMENT 'Threshold for low stock alerts',
  `max_stock_level`   INT            NOT NULL DEFAULT 1000,
  `location`          VARCHAR(100)   DEFAULT NULL COMMENT 'Warehouse shelf / location',
  `status`            ENUM('active','inactive','discontinued') NOT NULL DEFAULT 'active',
  `stock_status`      ENUM('instock','lowstock','critical','outofstock') NOT NULL DEFAULT 'instock',
  `weight`            DECIMAL(8,3)   DEFAULT NULL COMMENT 'kg',
  `features`          TEXT           DEFAULT NULL COMMENT 'JSON array of feature strings',
  `video_links`       TEXT           DEFAULT NULL COMMENT 'JSON array of video URLs',
  `additional_info`   TEXT           DEFAULT NULL COMMENT 'JSON key-value pairs',
  `created_by`        INT UNSIGNED   DEFAULT NULL,
  `updated_by`        INT UNSIGNED   DEFAULT NULL,
  `created_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)       ON DELETE SET NULL,
  FOREIGN KEY (`updated_by`)  REFERENCES `users`(`id`)       ON DELETE SET NULL,
  INDEX `idx_category` (`category_id`),
  INDEX `idx_stock_status` (`stock_status`),
  INDEX `idx_status` (`status`),
  FULLTEXT INDEX `ft_search` (`name`, `brand`, `sku`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product photos (multiple images per product)
CREATE TABLE IF NOT EXISTS `product_photos` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `url`        VARCHAR(255) NOT NULL,
  `alt_text`   VARCHAR(200) DEFAULT NULL,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product variants (size, color, etc.)
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `label`      VARCHAR(50)  NOT NULL COMMENT 'e.g. Color, Size',
  `value`      VARCHAR(100) NOT NULL COMMENT 'e.g. Red, XL',
  `sku_suffix` VARCHAR(30)  DEFAULT NULL,
  `price_adj`  DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Price adjustment (+/-)',
  `qty_adj`    INT           NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. CUSTOMERS
-- ============================================================

CREATE TABLE IF NOT EXISTS `customers` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(120) NOT NULL,
  `phone`      VARCHAR(20)  DEFAULT NULL,
  `email`      VARCHAR(180) DEFAULT NULL,
  `address`    TEXT         DEFAULT NULL,
  `city`       VARCHAR(80)  DEFAULT NULL,
  `notes`      TEXT         DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_phone` (`phone`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fb_pages` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(150)  NOT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED  DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_blacklist` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `phone`          VARCHAR(20)   NOT NULL,
  `reason`         TEXT          DEFAULT NULL,
  `blacklisted_by` INT UNSIGNED  DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`blacklisted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE INDEX `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. ORDERS
-- ============================================================

CREATE TABLE IF NOT EXISTS `orders` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`        VARCHAR(30)    NOT NULL UNIQUE COMMENT 'e.g. ORD-2024-00125',
  `customer_id`     INT UNSIGNED   DEFAULT NULL,
  `customer_name`   VARCHAR(120)   NOT NULL COMMENT 'Snapshot at order time',
  `customer_phone`  VARCHAR(20)    DEFAULT NULL,
  `customer_email`  VARCHAR(180)   DEFAULT NULL,
  `customer_address` TEXT          DEFAULT NULL,
  `fb_page_id`      INT UNSIGNED   DEFAULT NULL COMMENT 'Facebook page this order was attributed to',
  `status`          ENUM('new','confirmed','pending','cancelled','dispatched','delivered','returned','in_courier') NOT NULL DEFAULT 'new',
  `subtotal`        DECIMAL(12,2)  NOT NULL DEFAULT 0,
  `discount`        DECIMAL(12,2)  NOT NULL DEFAULT 0,
  `discount_type`   ENUM('fixed','percent') DEFAULT 'fixed',
  `total`           DECIMAL(12,2)  NOT NULL DEFAULT 0,
  `shipping_method` VARCHAR(100)   DEFAULT NULL,
  `shipping_cost`   DECIMAL(10,2)  NOT NULL DEFAULT 0,
  `courier_name`    VARCHAR(100)   DEFAULT NULL,
  `courier_charge`  DECIMAL(10,2)  NOT NULL DEFAULT 0,
  `payment_method`  VARCHAR(80)    DEFAULT NULL,
  `payment_status`  ENUM('unpaid','paid','partial','refunded') NOT NULL DEFAULT 'unpaid',
  `remarks`         TEXT           DEFAULT NULL COMMENT 'Staff message / notes',
  `supervisor_remarks` TEXT        DEFAULT NULL,
  `assigned_to`     INT UNSIGNED   DEFAULT NULL COMMENT 'Staff user handling this order',
  `dispatched_by`   INT UNSIGNED   DEFAULT NULL,
  `dispatched_at`   DATETIME       DEFAULT NULL,
  `delivered_at`    DATETIME       DEFAULT NULL,
  `stock_deducted`  TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Set once stock is deducted at dispatch',
  `stock_restored`  TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Set once stock is restored at return',
  `created_by`      INT UNSIGNED   DEFAULT NULL,
  `updated_by`      INT UNSIGNED   DEFAULT NULL,
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`)   REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`fb_page_id`)    REFERENCES `fb_pages`(`id`)  ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`)   REFERENCES `users`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`dispatched_by`) REFERENCES `users`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)    REFERENCES `users`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`updated_by`)    REFERENCES `users`(`id`)     ON DELETE SET NULL,
  INDEX `idx_status`     (`status`),
  INDEX `idx_customer`   (`customer_id`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_phone_created` (`customer_phone`, `created_at`),
  FULLTEXT INDEX `ft_order` (`order_id`, `customer_name`, `customer_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`    INT UNSIGNED   NOT NULL,
  `product_id`  INT UNSIGNED   DEFAULT NULL,
  `product_name` VARCHAR(200)  NOT NULL COMMENT 'Snapshot',
  `variant_info` VARCHAR(200)  DEFAULT NULL COMMENT 'e.g. Color:Red, Size:XL',
  `qty`         INT            NOT NULL DEFAULT 1,
  `buy_price`   DECIMAL(12,2)  NOT NULL DEFAULT 0 COMMENT 'Snapshot cost price',
  `sell_price`  DECIMAL(12,2)  NOT NULL DEFAULT 0 COMMENT 'Snapshot sell price',
  `total`       DECIMAL(12,2)  NOT NULL DEFAULT 0,
  FOREIGN KEY (`order_id`)   REFERENCES `orders`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL,
  INDEX `idx_order`   (`order_id`),
  INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order status history log
CREATE TABLE IF NOT EXISTS `order_status_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`   INT UNSIGNED NOT NULL,
  `from_status` VARCHAR(30)  DEFAULT NULL,
  `to_status`  VARCHAR(30)   NOT NULL,
  `changed_by` INT UNSIGNED  DEFAULT NULL,
  `note`       TEXT          DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`)   REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`)  ON DELETE SET NULL,
  INDEX `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. INVENTORY / STOCK
-- ============================================================

CREATE TABLE IF NOT EXISTS `stock_adjustments` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id`   INT UNSIGNED  NOT NULL,
  `type`         ENUM('add','remove','adjustment','initial','sale','return','damaged') NOT NULL DEFAULT 'adjustment',
  `qty_before`   INT           NOT NULL DEFAULT 0,
  `qty_change`   INT           NOT NULL COMMENT 'Positive = add, negative = remove',
  `qty_after`    INT           NOT NULL DEFAULT 0,
  `reference`    VARCHAR(100)  DEFAULT NULL COMMENT 'Order ID, PO number, etc.',
  `reason`       TEXT          DEFAULT NULL,
  `adjusted_by`  INT UNSIGNED  DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`)  REFERENCES `products`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`adjusted_by`) REFERENCES `users`(`id`)    ON DELETE SET NULL,
  INDEX `idx_product`    (`product_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ============================================================
-- 7. REPORTS / ANALYTICS CACHE (optional, for performance)
-- ============================================================

CREATE TABLE IF NOT EXISTS `report_cache` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `report_key` VARCHAR(100) NOT NULL UNIQUE,
  `data`       LONGTEXT     NOT NULL COMMENT 'JSON',
  `generated_at` DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_key` (`report_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. SETTINGS
-- ============================================================

CREATE TABLE IF NOT EXISTS `settings` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`        VARCHAR(100) NOT NULL UNIQUE,
  `value`      TEXT         DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. ACTIVITY LOG (audit trail)
-- ============================================================

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED  DEFAULT NULL,
  `action`     VARCHAR(100)  NOT NULL COMMENT 'e.g. order.created, product.updated',
  `entity`     VARCHAR(60)   DEFAULT NULL COMMENT 'e.g. order, product',
  `entity_id`  INT UNSIGNED  DEFAULT NULL,
  `meta`       TEXT          DEFAULT NULL COMMENT 'JSON extra data',
  `ip`         VARCHAR(45)   DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_user`       (`user_id`),
  INDEX `idx_action`     (`action`),
  INDEX `idx_entity`     (`entity`, `entity_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. SEED DATA
-- ============================================================

-- Default admin user (password: Admin@1234)
INSERT IGNORE INTO `users` (`name`, `display_name`, `email`, `password`, `role`, `status`) VALUES
('Super Admin',    'Admin',    'admin@inventorypro.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uAhye5Ckm', 'admin',      'active'),
('Staff One',      'Staff',    'staff@inventorypro.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uAhye5Ckm', 'staff',       'active'),
('Supervisor One', 'Supervisor','super@inventorypro.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uAhye5Ckm', 'supervisor',  'active');
-- NOTE: default password above is 'password' (bcrypt). Change in production!

-- Default categories
INSERT IGNORE INTO `categories` (`name`, `slug`, `sort_order`) VALUES
('Electronics',   'electronics',    1),
('Clothing',      'clothing',       2),
('Food & Drink',  'food-drink',     3),
('Home & Living', 'home-living',    4),
('Beauty',        'beauty',         5),
('Sports',        'sports',         6),
('Books',         'books',          7),
('Toys',          'toys',           8);

-- Default settings
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
('store_name',      'Inventory Pro'),
('store_currency',  'NPR'),
('currency_symbol', 'Rs'),
('low_stock_threshold', '5'),
('critical_stock_threshold', '2'),
('order_prefix',    'ORD'),
('product_prefix',  'PRD');

-- Sample products
INSERT IGNORE INTO `products`
  (`product_id`,`name`,`slug`,`category_id`,`brand`,`buy_price`,`sell_price`,`quantity`,`min_stock_level`,`stock_status`,`location`,`status`)
VALUES
('PRD-0001','Velocity Nike X2','velocity-nike-x2',1,'Nike',2500,3500,45,10,'instock','Shelf A1','active'),
('PRD-0002','SonicFlow Wireless','sonicflow-wireless',1,'Sony',1800,3500,12,8,'instock','Shelf B2','active'),
('PRD-0003','Organic Himalayan Coffee','organic-himalayan-coffee',3,'HimalayanBrew',800,1200,8,10,'lowstock','Cold Storage','active'),
('PRD-0004','Tote Bag Canvas','tote-bag-canvas',4,'LocalCraft',250,450,3,5,'critical','Shelf C4','active'),
('PRD-0005','Handcrafted Soap','handcrafted-soap',5,'PureNepal',150,280,0,5,'outofstock','Shelf D1','active'),
('PRD-0006','Body Oil Premium','body-oil-premium',5,'NatureCo',350,650,22,5,'instock','Shelf D2','active'),
('PRD-0007','Wireless Microphone','wireless-microphone',1,'AudioTech',3200,5800,7,5,'instock','Shelf A3','active'),
('PRD-0008','Fancy Bag Leather','fancy-bag-leather',4,'LuxCraft',4500,8500,4,3,'instock','Shelf C1','active');

-- Sample customers
INSERT IGNORE INTO `customers` (`name`,`phone`,`email`,`address`,`city`) VALUES
('Aasha Shrestha',  '9801234567', 'aasha@example.com',  'Baluwatar, Kathmandu', 'Kathmandu'),
('Bikash Thapa',    '9812345678', 'bikash@example.com', 'Lazimpat, Kathmandu',  'Kathmandu'),
('Sunita Rai',      '9823456789', 'sunita@example.com', 'Pokhara, Gandaki',     'Pokhara'),
('Rajesh Gurung',   '9834567890', 'rajesh@example.com', 'Butwal, Lumbini',      'Butwal'),
('Priya Maharjan',  '9845678901', 'priya@example.com',  'Patan, Lalitpur',      'Lalitpur');

SET FOREIGN_KEY_CHECKS = 1;