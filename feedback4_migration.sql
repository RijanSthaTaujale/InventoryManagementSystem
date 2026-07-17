-- ============================================================
-- Fourth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Adds: product_catalog_full view (chatbot variant visibility).
-- ============================================================

USE `inventorymanagement`;

-- ── Unified catalog view: every row is queryable like a single product ──
-- Plain products (no variants) appear as themselves. Products WITH variants
-- are expanded one row per variant, each with its own effective price and
-- its own stock quantity — so an AI/reporting tool that only knows how to
-- query "products" can see per-variant stock (e.g. "XL Black") without
-- needing to know about the separate product_variants table.
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
  (p.sell_price + v.price_adj)           AS sell_price,
  p.buy_price                            AS buy_price,
  v.qty_adj                              AS quantity,
  p.category_id                          AS category_id,
  p.status                               AS status
FROM `products` p
JOIN `product_variants` v ON v.product_id = p.id
WHERE p.status = 'active';
