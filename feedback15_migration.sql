-- ============================================================
-- Fifteenth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Fixes two chatbot bugs reported by the boss:
--   1. "Toiletries bag ko code sodheko product code vancha" — asked for
--      a product's code, the bot sometimes answered with the wrong number.
--      Root cause: the view exposed BOTH `p.id` (the raw internal DB row
--      id, e.g. 90) aliased AS `product_id`, AND `p.product_id` (the real
--      human code, e.g. "PRD-0090") aliased AS `product_code` — two
--      id-shaped fields with confusingly similar names. The AI sometimes
--      grabbed the wrong one. Fix: drop the raw internal id from the view
--      entirely — nothing needs it (the tools match rows by display_name).
--   2. "Material sodhda taha chaina vancha" — asked about a product's
--      material, the bot says it doesn't have that info. Root cause: the
--      view never exposed `description` (or any other free-text field) at
--      all, so there was literally nothing for it to answer with. Fix:
--      add `description` to the view. This only helps going forward if
--      that field is actually filled in per product — it's a data-entry
--      task, not just a schema one.
-- ============================================================

USE `inventorymanagement`;

CREATE OR REPLACE VIEW `product_catalog_full` AS
SELECT
  p.product_id                           AS product_code,
  p.name                                 AS display_name,
  NULL                                   AS variant_label,
  NULL                                   AS variant_value,
  p.sell_price                           AS sell_price,
  p.buy_price                            AS buy_price,
  p.quantity                             AS quantity,
  p.description                          AS description,
  p.category_id                          AS category_id,
  p.status                               AS status
FROM `products` p
WHERE p.status = 'active'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` v WHERE v.product_id = p.id)

UNION ALL

SELECT
  p.product_id                           AS product_code,
  CONCAT(p.name, ' (', v.label, ': ', v.value, ')') AS display_name,
  v.label                                AS variant_label,
  v.value                                AS variant_value,
  v.sell_price                           AS sell_price,
  v.buy_price                            AS buy_price,
  v.qty_adj                              AS quantity,
  p.description                          AS description,
  p.category_id                          AS category_id,
  p.status                               AS status
FROM `products` p
JOIN `product_variants` v ON v.product_id = p.id
WHERE p.status = 'active';
