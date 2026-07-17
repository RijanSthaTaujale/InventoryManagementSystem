-- ============================================================
-- Fifth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: product_variants.sell_price / buy_price (each variant now has
--       its own independent price, not an adjustment on the product's
--       price). Backfills from the old price_adj column, then drops it.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `product_variants`
  ADD COLUMN `sell_price` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `value`,
  ADD COLUMN `buy_price`  DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `sell_price`;

-- Backfill: preserve today's effective price (product price + old adjustment)
-- as the variant's own sell price; buy price starts as the product's buy price
-- (previously shared, since there was no per-variant cost).
UPDATE `product_variants` v
JOIN `products` p ON p.id = v.product_id
SET v.sell_price = p.sell_price + v.price_adj,
    v.buy_price  = p.buy_price
WHERE v.sell_price = 0;

ALTER TABLE `product_variants` DROP COLUMN `price_adj`;

-- Also update the chatbot catalog view (from feedback4_migration.sql) to use
-- the new columns directly instead of computing from price_adj.
CREATE OR REPLACE VIEW `product_catalog_full` AS
SELECT
  p.id                                   AS product_id,
  p.product_id                           AS product_code,
  p.name                                 AS display_name,
  NULL                                   AS variant_label,
  NULL                                   AS variant_value,
  p.sell_price                           AS sell_price,
  p.buy_price                            AS buy_price,
  p.quantity                             AS quantity,
  p.category_id                          AS category_id,
  p.status                               AS status
FROM `products` p
WHERE p.status = 'active'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` v WHERE v.product_id = p.id)

UNION ALL

SELECT
  p.id                                   AS product_id,
  p.product_id                           AS product_code,
  CONCAT(p.name, ' (', v.label, ': ', v.value, ')') AS display_name,
  v.label                                AS variant_label,
  v.value                                AS variant_value,
  v.sell_price                           AS sell_price,
  v.buy_price                            AS buy_price,
  v.qty_adj                              AS quantity,
  p.category_id                          AS category_id,
  p.status                               AS status
FROM `products` p
JOIN `product_variants` v ON v.product_id = p.id
WHERE p.status = 'active';
