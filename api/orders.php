<?php
// api/orders.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';
header('Content-Type: application/json');

$action  = $_GET['action'] ?? '';
$user    = currentUser();
$isAdmin = $user['role'] === 'admin';
$isSuper = $user['role'] === 'supervisor';
$isStaff = $user['role'] === 'staff';

// ── CHECK BLACKLIST ──────────────────────────────────────────
if ($action === 'check_blacklist') {
    $phone = trim($_GET['phone'] ?? '');
    $result = ['success' => true, 'blacklisted' => false, 'reason' => null];
    if (preg_match('/^\d{10}$/', $phone)) {
        $stmt = $pdo->prepare("SELECT reason FROM customer_blacklist WHERE phone=?");
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        if ($row) { $result['blacklisted'] = true; $result['reason'] = $row['reason']; }
    }
    echo json_encode($result);
    exit;
}

// ── ADD TO BLACKLIST (admin + supervisor) ────────────────────
if ($action === 'add_blacklist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin && !$isSuper) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

    $body   = json_decode(file_get_contents('php://input'), true);
    $phone  = trim($body['phone']  ?? '');
    $reason = trim($body['reason'] ?? '');

    if (!preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(['success'=>false,'message'=>'Phone number must be exactly 10 digits']); exit;
    }

    $pdo->prepare("
        INSERT INTO customer_blacklist (phone, reason, blacklisted_by) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), blacklisted_by = VALUES(blacklisted_by), created_at = NOW()
    ")->execute([$phone, $reason ?: null, $user['id']]);
    echo json_encode(['success'=>true]);
    exit;
}

// ── REMOVE FROM BLACKLIST (admin + supervisor) ───────────────
if ($action === 'remove_blacklist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin && !$isSuper) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);

    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid entry']); exit; }

    $pdo->prepare("DELETE FROM customer_blacklist WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    exit;
}

// Applies qty_change (direction * item qty) to every product in an order's
// line items, recalculates stock_status, and logs one stock_adjustments row
// per item. direction: -1 to deduct (dispatch), +1 to restore (return).
function adjustOrderStock(PDO $pdo, int $orderDbId, string $adjType, int $direction, int $userId, string $reason): void {
    $items = $pdo->prepare("SELECT product_id, variant_id, qty, returned_qty FROM order_items WHERE order_id=? AND product_id IS NOT NULL");
    $items->execute([$orderDbId]);

    foreach ($items->fetchAll() as $item) {
        // Only the still-outstanding quantity — qty minus whatever was already
        // given back via a per-item Return, an Exchange, or the whole-order
        // Returned status — is actually still deducted from stock. Using the
        // raw line qty here double-restores (and even un-writes-off damaged
        // units) when this order is later deleted after any of those.
        $remainingQty = (int)$item['qty'] - (int)$item['returned_qty'];
        if ($remainingQty <= 0) continue;

        $productId = (int)$item['product_id'];
        $variantId = $item['variant_id'] !== null ? (int)$item['variant_id'] : null;
        $qtyChange = $direction * $remainingQty;

        // If the line item is tied to a specific variant, deduct/restore that
        // variant's own stock first, then re-derive the product total as the
        // sum of all its variants (keeps products.quantity accurate for the
        // existing low-stock/inventory logic that reads it directly).
        if ($variantId) {
            $vRow = $pdo->prepare("SELECT qty_adj FROM product_variants WHERE id=? AND product_id=?");
            $vRow->execute([$variantId, $productId]);
            $variant = $vRow->fetch();
            if ($variant) {
                $vQtyAfter = (int)$variant['qty_adj'] + $qtyChange;
                $pdo->prepare("UPDATE product_variants SET qty_adj=? WHERE id=?")->execute([$vQtyAfter, $variantId]);
            }
        }

        $row = $pdo->prepare("SELECT quantity, min_stock_level FROM products WHERE id=?");
        $row->execute([$productId]);
        $product = $row->fetch();
        if (!$product) continue;

        $qtyBefore = (int)$product['quantity'];
        $min       = (int)$product['min_stock_level'];

        if ($variantId) {
            // Product total is the sum of all its variants' stock.
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(qty_adj),0) FROM product_variants WHERE product_id=?");
            $sumStmt->execute([$productId]);
            $qtyAfter = (int)$sumStmt->fetchColumn();
        } else {
            // No floor at 0 — dispatching more than what's in stock is allowed and
            // pushes quantity negative (backorder) rather than being blocked.
            $qtyAfter = $qtyBefore + $qtyChange;
        }
        $stockStatus = $qtyAfter <= 0 ? 'outofstock' : ($qtyAfter <= 2 ? 'critical' : ($qtyAfter <= $min ? 'lowstock' : 'instock'));

        $pdo->prepare("UPDATE products SET quantity=?, stock_status=? WHERE id=?")
            ->execute([$qtyAfter, $stockStatus, $productId]);

        $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reason,adjusted_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$productId, $adjType, $qtyBefore, $qtyAfter - $qtyBefore, $qtyAfter, $reason, $userId]);
    }
}

// Un-deducts stock for a specific qty of a single order item — unless
// it's coming back damaged, in which case it's logged to Damaged Stock
// instead of being put back up for sale. Either way it records the
// return against that line (returned_qty) and the order (order_returns +
// a reduced subtotal/total). Shared by the standalone per-item "Return"
// action, the "Exchange" action, and the whole-order "Returned" status
// change, so a line already partially returned is never double-counted
// no matter which path triggers the rest of it.
function returnOrderItem(PDO $pdo, array $item, int $qty, int $userId, string $orderRef, ?string $reason, bool $damaged = false, bool $isExchange = false): float {
    $productId = (int)($item['product_id'] ?? 0);
    $variantId = $item['variant_id'] !== null ? (int)$item['variant_id'] : null;

    if ($productId) {
        if ($damaged) {
            // The unit isn't sellable — don't touch qty_adj/quantity at all,
            // just record the loss (mirrors api/inventory.php's log_damage).
            $pdo->prepare("INSERT INTO damaged_products (product_id, qty, reason, logged_by) VALUES (?,?,?,?)")
                ->execute([$productId, $qty, $reason ?: "Damaged on return ({$orderRef})", $userId]);

            $qtyStmt = $pdo->prepare("SELECT quantity FROM products WHERE id=?");
            $qtyStmt->execute([$productId]);
            $qtyNow = (int)$qtyStmt->fetchColumn();
            $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reference,reason,adjusted_by) VALUES (?,'damaged',?,0,?,?,?,?)")
                ->execute([$productId, $qtyNow, $qtyNow, $orderRef, $reason ?: 'Damaged on return, not restocked', $userId]);
        } else {
            if ($variantId) {
                $vRow = $pdo->prepare("SELECT qty_adj FROM product_variants WHERE id=? AND product_id=?");
                $vRow->execute([$variantId, $productId]);
                if ($vRow->fetch()) {
                    $pdo->prepare("UPDATE product_variants SET qty_adj = qty_adj + ? WHERE id=?")->execute([$qty, $variantId]);
                }
            }

            $row = $pdo->prepare("SELECT quantity, min_stock_level FROM products WHERE id=?");
            $row->execute([$productId]);
            $product = $row->fetch();
            if ($product) {
                $qtyBefore = (int)$product['quantity'];
                $min       = (int)$product['min_stock_level'];

                if ($variantId) {
                    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(qty_adj),0) FROM product_variants WHERE product_id=?");
                    $sumStmt->execute([$productId]);
                    $qtyAfter = (int)$sumStmt->fetchColumn();
                } else {
                    $qtyAfter = $qtyBefore + $qty;
                }
                $stockStatus = $qtyAfter <= 0 ? 'outofstock' : ($qtyAfter <= 2 ? 'critical' : ($qtyAfter <= $min ? 'lowstock' : 'instock'));

                $pdo->prepare("UPDATE products SET quantity=?, stock_status=? WHERE id=?")->execute([$qtyAfter, $stockStatus, $productId]);
                $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reference,reason,adjusted_by) VALUES (?,'return',?,?,?,?,?,?)")
                    ->execute([$productId, $qtyBefore, $qty, $qtyAfter, $orderRef, $reason, $userId]);
            }
        }
    }

    $pdo->prepare("UPDATE order_items SET returned_qty = returned_qty + ? WHERE id=?")->execute([$qty, $item['id']]);

    $amount = round($qty * (float)$item['sell_price'], 2);
    $pdo->prepare("UPDATE orders SET subtotal = GREATEST(0, subtotal - ?), total = GREATEST(0, total - ?) WHERE id=?")
        ->execute([$amount, $amount, $item['order_id']]);

    $pdo->prepare("INSERT INTO order_returns (order_id,order_item_id,qty,amount,damaged,is_exchange,reason,returned_by) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$item['order_id'], $item['id'], $qty, $amount, $damaged ? 1 : 0, $isExchange ? 1 : 0, $reason, $userId]);

    return $amount;
}

// ── CREATE ORDER ─────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $customer_name    = trim($body['customer_name']    ?? '');
    $customer_phone   = trim($body['customer_phone']   ?? '');
    $customer_email   = trim($body['customer_email']   ?? '');
    $customer_address = trim($body['customer_address'] ?? '');
    $fb_page_id       = (int)($body['fb_page_id']      ?? 0) ?: null;
    $amount_paid_in   = max(0, (float)($body['amount_paid'] ?? 0));
    $shipping_method  = trim($body['shipping_method']  ?? '');
    $shipping_cost    = (float)($body['shipping_cost'] ?? 0);
    $courier_name     = trim($body['courier_name']     ?? '');
    $extra_charge     = max(0, (float)($body['extra_charge'] ?? 0));
    $discount         = (float)($body['discount']      ?? 0);
    $discount_type    = trim($body['discount_type']    ?? 'fixed');
    $remarks          = trim($body['remarks']          ?? '');
    $items            = $body['items'] ?? [];

    if (!$customer_name) {
        echo json_encode(['success' => false, 'message' => 'Customer name is required.']); exit;
    }
    if (!preg_match('/^\d{10}$/', $customer_phone)) {
        echo json_encode(['success' => false, 'message' => 'Phone number must be exactly 10 digits.']); exit;
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Order must have at least one item.']); exit;
    }

    // Validate and price each item
    $lineItems = [];
    $subtotal  = 0;
    foreach ($items as $item) {
        $product_id = (int)($item['product_id'] ?? 0);
        $qty        = (int)($item['qty']        ?? 0);
        if (!$product_id || $qty < 1) continue;

        $p = $pdo->prepare("SELECT id, name, sell_price, buy_price, quantity FROM products WHERE id=? AND status='active'");
        $p->execute([$product_id]);
        $product = $p->fetch();
        if (!$product) {
            echo json_encode(['success' => false, 'message' => "Product ID $product_id not found."]); exit;
        }
        // Orders are allowed regardless of stock level — quantity may go negative (backorder).

        // A variant, if selected, has its own independent sell/buy price — not
        // an adjustment on the product's. Buy price always comes from the
        // server (never client-trusted); sell price can still be overridden
        // per line item (existing discount/negotiation behavior).
        $variant_id = (int)($item['variant_id'] ?? 0) ?: null;
        $variant    = null;
        if ($variant_id) {
            $vCheck = $pdo->prepare("SELECT id, sell_price, buy_price FROM product_variants WHERE id=? AND product_id=?");
            $vCheck->execute([$variant_id, $product_id]);
            $variant = $vCheck->fetch();
            if (!$variant) $variant_id = null;
        }

        $defaultSellPrice = $variant ? $variant['sell_price'] : $product['sell_price'];
        $sell_price   = (float)($item['sell_price'] ?? $defaultSellPrice);
        $buy_price    = (float)($variant ? $variant['buy_price'] : $product['buy_price']);
        $line_total   = $sell_price * $qty;
        $subtotal    += $line_total;

        $lineItems[] = [
            'product_id'   => $product_id,
            'product_name' => $product['name'],
            'variant_id'   => $variant_id,
            'qty'          => $qty,
            'sell_price'   => $sell_price,
            'buy_price'    => $buy_price,
            'total'        => $line_total,
            'variant_info' => trim($item['variant_info'] ?? '') ?: null,
        ];
    }

    if (empty($lineItems)) {
        echo json_encode(['success' => false, 'message' => 'No valid items in order.']); exit;
    }

    // Calculate discount
    $discountAmount = $discount_type === 'percent'
        ? round($subtotal * ($discount / 100), 2)
        : $discount;
    $total = max(0, $subtotal - $discountAmount + $extra_charge + $shipping_cost);
    // Amount actually collected right now — staff can override the form's
    // full-amount default down to 0 (COD) or any partial figure. Tracked
    // separately from $total, since $total can later change via an Exchange.
    $amount_paid = min($amount_paid_in, $total);
    if ($amount_paid <= 0) {
        $payment_status = 'unpaid';
        $payment_method = 'Cash on Delivery';
    } elseif ($amount_paid >= $total) {
        $payment_status = 'paid';
        $payment_method = 'Prepaid';
    } else {
        $payment_status = 'partial';
        $payment_method = 'Partial Payment';
    }

    // Generate order_id: ORD-YYYYMMDD-XXXX
    $last = $pdo->query("SELECT order_id FROM orders ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq  = $last ? ((int)substr($last, -4) + 1) : 1;
    $orderId = 'ORD-' . date('Ymd') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // Begin transaction
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO orders
                (order_id, customer_name, customer_phone, customer_email, customer_address, fb_page_id,
                 subtotal, discount, discount_type, extra_charge, shipping_cost, shipping_method, courier_name, total,
                 payment_method, payment_status, amount_paid, status, remarks, created_by, assigned_to)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'new',?,?,?)
        ")->execute([
            $orderId, $customer_name, $customer_phone ?: null, $customer_email ?: null, $customer_address ?: null, $fb_page_id,
            $subtotal, $discountAmount, $discount_type, $extra_charge, $shipping_cost, $shipping_method ?: null, $courier_name ?: null, $total,
            $payment_method, $payment_status, $amount_paid, $remarks ?: null, $user['id'], $user['id'],
        ]);
        $dbOrderId = $pdo->lastInsertId();

        // Insert items — stock is NOT deducted here; deduction happens at dispatch time (see status action below)
        foreach ($lineItems as $li) {
            $pdo->prepare("
                INSERT INTO order_items
                    (order_id, product_id, product_name, variant_id, variant_info, qty, sell_price, buy_price, total)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $dbOrderId, $li['product_id'], $li['product_name'], $li['variant_id'], $li['variant_info'],
                $li['qty'], $li['sell_price'], $li['buy_price'], $li['total'],
            ]);
        }

        // Log initial status
        $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by) VALUES (?,NULL,'new',?)")
            ->execute([$dbOrderId, $user['id']]);

        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $orderId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to create order. Please try again.']);
    }
    exit;
}

// ── QUICK SEARCH (topbar global search) ───────────────────────
if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q    = trim($_GET['q'] ?? '');
    $like = "%$q%";
    $stmt = $pdo->prepare("
        SELECT order_id, customer_name, customer_phone, status, total
        FROM orders WHERE order_id LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?
        ORDER BY created_at DESC LIMIT 8
    ");
    $stmt->execute([$like, $like, $like]);
    $orders = $stmt->fetchAll();
    if (!$isAdmin && !$isSuper) { foreach ($orders as &$o) unset($o['total']); }
    echo json_encode(['success' => true, 'orders' => $orders]);
    exit;
}

// ── CHECK DUPLICATE (same phone, same day) ───────────────────
if ($action === 'check_duplicate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone          = trim($_GET['phone'] ?? '');
    $excludeOrderId = trim($_GET['exclude_order_id'] ?? '');
    if (!preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(['success' => true, 'duplicate' => false]); exit;
    }

    $sql    = "SELECT order_id, created_at FROM orders WHERE customer_phone = ? AND DATE(created_at) = CURDATE()";
    $params = [$phone];
    if ($excludeOrderId) { $sql .= " AND order_id != ?"; $params[] = $excludeOrderId; }
    $sql .= " ORDER BY created_at DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetch();

    echo json_encode([
        'success'   => true,
        'duplicate' => (bool)$existing,
        'order_id'  => $existing['order_id'] ?? null,
    ]);
    exit;
}

// ── UPDATE ORDER (details + items; pre-dispatch orders only) ──
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true);
    $orderId = trim($body['order_id'] ?? '');
    if (!$orderId) { echo json_encode(['success' => false, 'message' => 'Order ID is required.']); exit; }

    $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE order_id=?");
    $stmt->execute([$orderId]);
    $existing = $stmt->fetch();
    if (!$existing) { echo json_encode(['success' => false, 'message' => 'Order not found.']); exit; }

    $editableStatuses = ['new', 'pending', 'confirmed'];
    if (!in_array($existing['status'], $editableStatuses)) {
        echo json_encode(['success' => false, 'message' => "This order can no longer be edited (status: {$existing['status']})."]); exit;
    }

    $customer_name    = trim($body['customer_name']    ?? '');
    $customer_phone   = trim($body['customer_phone']   ?? '');
    $customer_email   = trim($body['customer_email']   ?? '');
    $customer_address = trim($body['customer_address'] ?? '');
    $fb_page_id       = (int)($body['fb_page_id']      ?? 0) ?: null;
    $amount_paid_in   = max(0, (float)($body['amount_paid'] ?? 0));
    $shipping_method  = trim($body['shipping_method']  ?? '');
    $shipping_cost    = (float)($body['shipping_cost'] ?? 0);
    $courier_name     = trim($body['courier_name']     ?? '');
    $extra_charge     = max(0, (float)($body['extra_charge'] ?? 0));
    $discount         = (float)($body['discount']      ?? 0);
    $discount_type    = trim($body['discount_type']    ?? 'fixed');
    $remarks          = trim($body['remarks']          ?? '');
    $items            = $body['items'] ?? [];

    if (!$customer_name) {
        echo json_encode(['success' => false, 'message' => 'Customer name is required.']); exit;
    }
    if (!preg_match('/^\d{10}$/', $customer_phone)) {
        echo json_encode(['success' => false, 'message' => 'Phone number must be exactly 10 digits.']); exit;
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Order must have at least one item.']); exit;
    }

    // Validate and price each item (same rules as create)
    $lineItems = [];
    $subtotal  = 0;
    foreach ($items as $item) {
        $product_id = (int)($item['product_id'] ?? 0);
        $qty        = (int)($item['qty']        ?? 0);
        if (!$product_id || $qty < 1) continue;

        $p = $pdo->prepare("SELECT id, name, sell_price, buy_price, quantity FROM products WHERE id=? AND status='active'");
        $p->execute([$product_id]);
        $product = $p->fetch();
        if (!$product) {
            echo json_encode(['success' => false, 'message' => "Product ID $product_id not found."]); exit;
        }
        // Orders are allowed regardless of stock level — quantity may go negative (backorder).

        // A variant, if selected, has its own independent sell/buy price — not
        // an adjustment on the product's. Buy price always comes from the
        // server (never client-trusted); sell price can still be overridden
        // per line item (existing discount/negotiation behavior).
        $variant_id = (int)($item['variant_id'] ?? 0) ?: null;
        $variant    = null;
        if ($variant_id) {
            $vCheck = $pdo->prepare("SELECT id, sell_price, buy_price FROM product_variants WHERE id=? AND product_id=?");
            $vCheck->execute([$variant_id, $product_id]);
            $variant = $vCheck->fetch();
            if (!$variant) $variant_id = null;
        }

        $defaultSellPrice = $variant ? $variant['sell_price'] : $product['sell_price'];
        $sell_price   = (float)($item['sell_price'] ?? $defaultSellPrice);
        $buy_price    = (float)($variant ? $variant['buy_price'] : $product['buy_price']);
        $line_total   = $sell_price * $qty;
        $subtotal    += $line_total;

        $lineItems[] = [
            'product_id'   => $product_id,
            'product_name' => $product['name'],
            'variant_id'   => $variant_id,
            'qty'          => $qty,
            'sell_price'   => $sell_price,
            'buy_price'    => $buy_price,
            'total'        => $line_total,
            'variant_info' => trim($item['variant_info'] ?? '') ?: null,
        ];
    }

    if (empty($lineItems)) {
        echo json_encode(['success' => false, 'message' => 'No valid items in order.']); exit;
    }

    $discountAmount = $discount_type === 'percent'
        ? round($subtotal * ($discount / 100), 2)
        : $discount;
    $total = max(0, $subtotal - $discountAmount + $extra_charge + $shipping_cost);
    // Editing only happens pre-dispatch, so nothing could have been collected
    // by the courier yet — safe to just recompute the same way order creation does.
    $amount_paid = min($amount_paid_in, $total);
    if ($amount_paid <= 0) {
        $payment_status = 'unpaid';
        $payment_method = 'Cash on Delivery';
    } elseif ($amount_paid >= $total) {
        $payment_status = 'paid';
        $payment_method = 'Prepaid';
    } else {
        $payment_status = 'partial';
        $payment_method = 'Partial Payment';
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE orders SET
                customer_name=?, customer_phone=?, customer_email=?, customer_address=?, fb_page_id=?,
                subtotal=?, discount=?, discount_type=?, extra_charge=?, shipping_cost=?, shipping_method=?,
                courier_name=?, total=?, payment_method=?, payment_status=?, amount_paid=?, remarks=?, updated_by=?
            WHERE id=?
        ")->execute([
            $customer_name, $customer_phone ?: null, $customer_email ?: null, $customer_address ?: null, $fb_page_id,
            $subtotal, $discountAmount, $discount_type, $extra_charge, $shipping_cost, $shipping_method ?: null,
            $courier_name ?: null, $total, $payment_method, $payment_status, $amount_paid, $remarks ?: null, $user['id'],
            $existing['id'],
        ]);

        // Replace line items wholesale — safe because editable statuses are always pre-dispatch (no stock movement to reconcile)
        $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$existing['id']]);
        foreach ($lineItems as $li) {
            $pdo->prepare("
                INSERT INTO order_items
                    (order_id, product_id, product_name, variant_id, variant_info, qty, sell_price, buy_price, total)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $existing['id'], $li['product_id'], $li['product_name'], $li['variant_id'], $li['variant_info'],
                $li['qty'], $li['sell_price'], $li['buy_price'], $li['total'],
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $orderId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to update order. Please try again.']);
    }
    exit;
}

// ── UPDATE STATUS (single order) ─────────────────────────────
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $orderId   = trim($body['order_id'] ?? '');
    $newStatus = trim($body['status']   ?? '');
    $note      = trim($body['note']     ?? '');

    $valid = ['new','confirmed','pending','cancelled','dispatched','delivered','returned','in_courier'];
    if (!$orderId || !in_array($newStatus, $valid)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']); exit;
    }

    // A reason is required so it's clear later why an order was cancelled or held pending
    if (in_array($newStatus, ['cancelled', 'pending']) && !$note) {
        echo json_encode(['success' => false, 'message' => 'A remarks/reason is required for this status.']); exit;
    }

    // Role-based permission checks
    // Dispatch is now allowed for all roles (admin, supervisor, staff).
    if (in_array($newStatus, ['delivered', 'returned', 'in_courier']) && $isStaff) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions for this status.']); exit;
    }

    $stmt = $pdo->prepare("SELECT id, status, stock_deducted, stock_restored FROM orders WHERE order_id=?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { echo json_encode(['success' => false, 'message' => 'Order not found.']); exit; }

    $extra  = '';
    $params = [$newStatus, $user['id'], $order['id']];

    if ($newStatus === 'dispatched') {
        $extra  = ', dispatched_by=?, dispatched_at=NOW()';
        $params = [$newStatus, $user['id'], $user['id'], $order['id']];
    } elseif ($newStatus === 'delivered') {
        // Whatever's still owed is collected at the door on delivery — if the
        // order was 'unpaid' or only 'partial'ly prepaid, it's fully paid now.
        // This matters later: an Exchange or re-export on this order must
        // never tell the courier to collect money again for it.
        $extra = ", delivered_at=NOW(), amount_paid=IF(payment_status IN ('unpaid','partial'),total,amount_paid), payment_status=IF(payment_status IN ('unpaid','partial'),'paid',payment_status)";
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE orders SET status=?, updated_by=?$extra WHERE id=?")->execute($params);

        $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by,note) VALUES (?,?,?,?,?)")
            ->execute([$order['id'], $order['status'], $newStatus, $user['id'], $note ?: null]);

        // Stock deducts once, at dispatch time
        if ($newStatus === 'dispatched' && !$order['stock_deducted']) {
            adjustOrderStock($pdo, $order['id'], 'sale', -1, $user['id'], "Dispatched {$orderId}");
            $pdo->prepare("UPDATE orders SET stock_deducted=1 WHERE id=?")->execute([$order['id']]);
        }

        // Stock restores once, at return time. Only the remaining un-returned
        // qty on each line is restored, so this stays correct even if some
        // items were already partially returned via the per-item Return action.
        if ($newStatus === 'returned' && !$order['stock_restored']) {
            $returnItemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id=? AND product_id IS NOT NULL");
            $returnItemsStmt->execute([$order['id']]);
            foreach ($returnItemsStmt->fetchAll() as $ri) {
                $remaining = (int)$ri['qty'] - (int)$ri['returned_qty'];
                if ($remaining > 0) {
                    returnOrderItem($pdo, $ri, $remaining, $user['id'], $orderId, 'Full order return');
                }
            }
            $pdo->prepare("UPDATE orders SET stock_restored=1 WHERE id=?")->execute([$order['id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to update status.']);
    }
    exit;
}

// ── RETURN ORDER ITEM (partial or full line, admin + supervisor) ──
if ($action === 'return_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin && !$isSuper) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

    $body    = json_decode(file_get_contents('php://input'), true);
    $itemId  = (int)($body['item_id'] ?? 0);
    $qty     = (int)($body['qty']     ?? 0);
    $reason  = trim($body['reason']   ?? '');
    $damaged = !empty($body['damaged']);

    if (!$itemId || $qty < 1) { echo json_encode(['success' => false, 'message' => 'Invalid data']); exit; }

    $stmt = $pdo->prepare("
        SELECT oi.*, o.order_id AS order_ref, o.stock_deducted
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) { echo json_encode(['success' => false, 'message' => 'Order item not found']); exit; }
    if (!$item['stock_deducted']) { echo json_encode(['success' => false, 'message' => 'This order has not been dispatched yet — nothing to return.']); exit; }

    $remaining = (int)$item['qty'] - (int)$item['returned_qty'];
    if ($qty > $remaining) { echo json_encode(['success' => false, 'message' => "Only $remaining unit(s) left to return."]); exit; }

    $pdo->beginTransaction();
    try {
        $amount = returnOrderItem($pdo, $item, $qty, $user['id'], $item['order_ref'], $reason ?: null, $damaged);
        $pdo->commit();
        echo json_encode(['success' => true, 'amount' => $amount]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to process return.']);
    }
    exit;
}

// ── EXCHANGE ORDER ITEM (admin + supervisor) ──────────────────
// Swaps a quantity of one order item for a different product/variant on
// the same order: the old item is disposed of exactly like a Return
// (restocked, or logged to Damaged Stock if it came back damaged), the
// new item's stock is deducted and added as its own line, and the order
// total is adjusted by the net price difference — not just subtracted.
if ($action === 'exchange_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin && !$isSuper) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

    $body           = json_decode(file_get_contents('php://input'), true);
    $itemId         = (int)($body['item_id']         ?? 0);
    $qty            = (int)($body['qty']             ?? 0);
    $reason         = trim($body['reason']           ?? '');
    $damaged        = !empty($body['damaged']);
    $newProductId   = (int)($body['new_product_id']  ?? 0);
    $newVariantId   = (int)($body['new_variant_id']  ?? 0) ?: null;
    $newQty         = (int)($body['new_qty']         ?? 0) ?: $qty;
    // Whether the customer already settled the price difference in person
    // (paid more for an upgrade, or was refunded for a downgrade) — if not,
    // it's left for the courier to collect/adjust on redelivery.
    $alreadyPaid    = !empty($body['already_paid']);

    if (!$itemId || $qty < 1 || !$newProductId || $newQty < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']); exit;
    }

    $stmt = $pdo->prepare("
        SELECT oi.*, o.order_id AS order_ref, o.stock_deducted,
               o.status AS order_status, o.payment_status AS order_payment_status,
               o.amount_paid AS order_amount_paid, o.total AS order_total
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) { echo json_encode(['success' => false, 'message' => 'Order item not found']); exit; }
    if (!$item['stock_deducted']) { echo json_encode(['success' => false, 'message' => 'This order has not been dispatched yet — nothing to exchange.']); exit; }

    $remaining = (int)$item['qty'] - (int)$item['returned_qty'];
    if ($qty > $remaining) { echo json_encode(['success' => false, 'message' => "Only $remaining unit(s) left to exchange."]); exit; }

    $newProduct = $pdo->prepare("SELECT id, name, sell_price, buy_price FROM products WHERE id=? AND status='active'");
    $newProduct->execute([$newProductId]);
    $newProduct = $newProduct->fetch();
    if (!$newProduct) { echo json_encode(['success' => false, 'message' => 'Replacement product not found']); exit; }

    $newVariant = null;
    if ($newVariantId) {
        $vStmt = $pdo->prepare("SELECT id, label, value, sell_price, buy_price FROM product_variants WHERE id=? AND product_id=?");
        $vStmt->execute([$newVariantId, $newProductId]);
        $newVariant = $vStmt->fetch();
        if (!$newVariant) { $newVariantId = null; }
    }

    $newSellPrice  = (float)($newVariant ? $newVariant['sell_price'] : $newProduct['sell_price']);
    $newBuyPrice   = (float)($newVariant ? $newVariant['buy_price']  : $newProduct['buy_price']);
    $newTotal      = round($newSellPrice * $newQty, 2);
    $newVariantInfo = $newVariant ? ($newVariant['label'] . ': ' . $newVariant['value']) : null;

    $pdo->beginTransaction();
    try {
        // 1. Dispose of the old item exactly like a Return.
        $oldReason = $reason ?: "Exchanged for {$newProduct['name']}";
        returnOrderItem($pdo, $item, $qty, $user['id'], $item['order_ref'], $oldReason, $damaged, true);

        // 2. Deduct stock for the new item.
        if ($newVariantId) {
            $pdo->prepare("UPDATE product_variants SET qty_adj = qty_adj - ? WHERE id=?")->execute([$newQty, $newVariantId]);
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(qty_adj),0) FROM product_variants WHERE product_id=?");
            $sumStmt->execute([$newProductId]);
            $qtyAfter = (int)$sumStmt->fetchColumn();
        } else {
            $qtyAfter = null;
        }
        $prodRow = $pdo->prepare("SELECT quantity, min_stock_level FROM products WHERE id=?");
        $prodRow->execute([$newProductId]);
        $prodRow  = $prodRow->fetch();
        $qtyBefore = (int)$prodRow['quantity'];
        $min       = (int)$prodRow['min_stock_level'];
        if ($qtyAfter === null) { $qtyAfter = $qtyBefore - $newQty; }
        $stockStatus = $qtyAfter <= 0 ? 'outofstock' : ($qtyAfter <= 2 ? 'critical' : ($qtyAfter <= $min ? 'lowstock' : 'instock'));

        $pdo->prepare("UPDATE products SET quantity=?, stock_status=? WHERE id=?")->execute([$qtyAfter, $stockStatus, $newProductId]);
        $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reference,reason,adjusted_by) VALUES (?,'sale',?,?,?,?,?,?)")
            ->execute([$newProductId, $qtyBefore, -$newQty, $qtyAfter, $item['order_ref'], "Exchange — replaced with {$newProduct['name']}", $user['id']]);

        // 3. Add the new item as its own line on the same order.
        $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, product_name, variant_id, variant_info, qty, sell_price, buy_price, total)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([$item['order_id'], $newProductId, $newProduct['name'], $newVariantId, $newVariantInfo, $newQty, $newSellPrice, $newBuyPrice, $newTotal]);

        // 4. The order total was already reduced by the old item's value inside
        // returnOrderItem() — now add the new item's value on top, so the net
        // effect is the price difference either way.
        $pdo->prepare("UPDATE orders SET subtotal = subtotal + ?, total = total + ? WHERE id=?")
            ->execute([$newTotal, $newTotal, $item['order_id']]);

        // 5. Reconcile the payment ledger against the new total. If the
        // customer already settled the difference in person, treat the order
        // as fully paid now; otherwise leave amount_paid untouched and just
        // re-derive paid/partial/unpaid so it's accurate again (the price
        // change can push an already-'paid' order back into 'partial').
        $totalStmt = $pdo->prepare("SELECT total FROM orders WHERE id=?");
        $totalStmt->execute([$item['order_id']]);
        $updatedTotal = (float)$totalStmt->fetchColumn();

        if ($alreadyPaid) {
            $newAmountPaid    = $updatedTotal;
            $newPaymentStatus = 'paid';
        } else {
            $newAmountPaid = (float)$item['order_amount_paid'];
            if ($newAmountPaid <= 0) {
                $newPaymentStatus = 'unpaid';
            } elseif ($newAmountPaid >= $updatedTotal) {
                $newPaymentStatus = 'paid';
            } else {
                $newPaymentStatus = 'partial';
            }
        }
        $newPaymentMethod = [
            'unpaid'  => 'Cash on Delivery',
            'partial' => 'Partial Payment',
            'paid'    => 'Prepaid',
        ][$newPaymentStatus];

        // 6. A replacement item always needs its own physical shipment — if
        // the order had already left the pipeline (or gone all the way to
        // Delivered), kick it back to Confirmed so staff track the redelivery
        // through Dispatched → In Courier → Delivered again instead of it
        // silently staying "Delivered" for an item that hasn't shipped yet.
        // stock_deducted is left as-is, since the replacement's stock was
        // already deducted in step 2 — dispatching again won't double-deduct.
        if (in_array($item['order_status'], ['dispatched', 'in_courier', 'delivered'], true)) {
            $pdo->prepare("
                UPDATE orders SET amount_paid=?, payment_status=?, payment_method=?, status='confirmed',
                       dispatched_at=NULL, delivered_at=NULL, updated_by=?
                WHERE id=?
            ")->execute([$newAmountPaid, $newPaymentStatus, $newPaymentMethod, $user['id'], $item['order_id']]);

            $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by,note) VALUES (?,?,?,?,?)")
                ->execute([$item['order_id'], $item['order_status'], 'confirmed', $user['id'], 'Reset for redelivery after exchange']);
        } else {
            $pdo->prepare("UPDATE orders SET amount_paid=?, payment_status=?, payment_method=? WHERE id=?")
                ->execute([$newAmountPaid, $newPaymentStatus, $newPaymentMethod, $item['order_id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to process exchange.']);
    }
    exit;
}

// ── BULK: DISPATCH ALL CONFIRMED (admin + supervisor) ─────────
if ($action === 'dispatch_all_confirmed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin && !$isSuper) {
        echo json_encode(['success' => false, 'message' => 'Only admin or supervisor can do this.']); exit;
    }

    $rows = $pdo->query("SELECT id, order_id, stock_deducted FROM orders WHERE status='confirmed'")->fetchAll();
    if (empty($rows)) {
        echo json_encode(['success' => true, 'count' => 0, 'message' => 'No confirmed orders to dispatch.']); exit;
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $pdo->prepare("UPDATE orders SET status='dispatched', dispatched_by=?, dispatched_at=NOW(), updated_by=? WHERE id=?")
                ->execute([$user['id'], $user['id'], $row['id']]);
            $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by) VALUES (?,'confirmed','dispatched',?)")
                ->execute([$row['id'], $user['id']]);

            if (!$row['stock_deducted']) {
                adjustOrderStock($pdo, $row['id'], 'sale', -1, $user['id'], "Bulk dispatched ({$row['order_id']})");
                $pdo->prepare("UPDATE orders SET stock_deducted=1 WHERE id=?")->execute([$row['id']]);
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'count' => count($rows)]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to dispatch orders.']);
    }
    exit;
}

// ── BULK: MOVE ALL DISPATCHED TO IN COURIER (admin + supervisor) ──
if ($action === 'move_all_to_courier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin && !$isSuper) {
        echo json_encode(['success' => false, 'message' => 'Only admin or supervisor can do this.']); exit;
    }

    $rows = $pdo->query("SELECT id FROM orders WHERE status='dispatched'")->fetchAll();
    if (empty($rows)) {
        echo json_encode(['success' => true, 'count' => 0, 'message' => 'No dispatched orders to move.']); exit;
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $pdo->prepare("UPDATE orders SET status='in_courier', updated_by=? WHERE id=?")
                ->execute([$user['id'], $row['id']]);
            $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by) VALUES (?,'dispatched','in_courier',?)")
                ->execute([$row['id'], $user['id']]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'count' => count($rows)]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to update orders.']);
    }
    exit;
}

// ── BULK: DELIVER BY PASTED ORDER IDs (admin + supervisor) ────
// Only orders currently "in_courier" are transitioned; everything else is skipped and reported.
if ($action === 'bulk_deliver' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin && !$isSuper) {
        echo json_encode(['success' => false, 'message' => 'Only admin or supervisor can do this.']); exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true);
    $orderIds = array_filter(array_map('trim', $body['order_ids'] ?? []));
    if (empty($orderIds)) {
        echo json_encode(['success' => false, 'message' => 'No order IDs provided.']); exit;
    }

    $delivered = 0;
    $skipped   = [];

    $pdo->beginTransaction();
    try {
        foreach ($orderIds as $oid) {
            $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE order_id=?");
            $stmt->execute([$oid]);
            $o = $stmt->fetch();

            if (!$o) { $skipped[] = "$oid (not found)"; continue; }
            if ($o['status'] !== 'in_courier') { $skipped[] = "$oid (status: {$o['status']})"; continue; }

            $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW(), updated_by=?, amount_paid=IF(payment_status IN ('unpaid','partial'),total,amount_paid), payment_status=IF(payment_status IN ('unpaid','partial'),'paid',payment_status) WHERE id=?")
                ->execute([$user['id'], $o['id']]);
            $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by) VALUES (?,'in_courier','delivered',?)")
                ->execute([$o['id'], $user['id']]);
            $delivered++;
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'delivered' => $delivered, 'skipped' => $skipped]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to process bulk delivery.']);
    }
    exit;
}

// ── DELETE ORDER (admin only) ─────────────────────────────────
// Permanently removes an order (order_items/order_returns/order_status_log
// cascade via FK). If stock had already been deducted for it (dispatched or
// later), that stock is restored first so deleting never silently leaves
// inventory short.
if ($action === 'delete_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'Only admin can delete orders.']); exit; }

    $body    = json_decode(file_get_contents('php://input'), true);
    $orderId = trim($body['order_id'] ?? '');
    if (!$orderId) { echo json_encode(['success' => false, 'message' => 'Order ID required.']); exit; }

    $stmt = $pdo->prepare("SELECT id, order_id, stock_deducted FROM orders WHERE order_id=?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { echo json_encode(['success' => false, 'message' => 'Order not found.']); exit; }

    $pdo->beginTransaction();
    try {
        if ($order['stock_deducted']) {
            adjustOrderStock($pdo, $order['id'], 'return', 1, $user['id'], "Order {$order['order_id']} deleted — stock restored");
        }
        $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$order['id']]);
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to delete order.']);
    }
    exit;
}

// ── BULK DELETE ORDERS (admin only) ───────────────────────────
if ($action === 'bulk_delete_orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'Only admin can delete orders.']); exit; }

    $body     = json_decode(file_get_contents('php://input'), true);
    $orderIds = array_filter(array_map('trim', $body['order_ids'] ?? []));
    if (empty($orderIds)) { echo json_encode(['success' => false, 'message' => 'No orders selected.']); exit; }

    $deleted = 0;
    $pdo->beginTransaction();
    try {
        foreach ($orderIds as $oid) {
            $stmt = $pdo->prepare("SELECT id, order_id, stock_deducted FROM orders WHERE order_id=?");
            $stmt->execute([$oid]);
            $order = $stmt->fetch();
            if (!$order) continue;

            if ($order['stock_deducted']) {
                adjustOrderStock($pdo, $order['id'], 'return', 1, $user['id'], "Order {$order['order_id']} deleted — stock restored");
            }
            $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$order['id']]);
            $deleted++;
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'deleted' => $deleted]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to delete orders.']);
    }
    exit;
}

// ── EXPORT ORDERS AS CSV (respects current list filters) ─────
if ($action === 'export_csv' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $search   = trim($_GET['search']   ?? '');
    $status   = $_GET['status']        ?? '';
    $dateFrom = $_GET['date_from']     ?? '';
    $dateTo   = $_GET['date_to']       ?? '';
    $courier  = trim($_GET['courier']  ?? '');

    $validStatuses = ['new','confirmed','pending','cancelled','dispatched','in_courier','delivered','returned'];
    $where  = ['1=1'];
    $params = [];

    if ($search) {
        $where[] = "(o.order_id LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
        $like    = "%$search%";
        $params  = array_merge($params, [$like, $like, $like]);
    }
    if ($status === 'exchanged') {
        $where[] = "EXISTS (SELECT 1 FROM order_returns r WHERE r.order_id = o.id AND r.is_exchange = 1)";
    } elseif ($status && in_array($status, $validStatuses)) { $where[] = "o.status = ?"; $params[] = $status; }
    if ($dateFrom) { $where[] = "DATE(o.created_at) >= ?"; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = "DATE(o.created_at) <= ?"; $params[] = $dateTo; }
    if ($courier)  { $where[] = "o.courier_name = ?"; $params[] = $courier; }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // One row per order — multi-item orders get their product names/quantities
    // joined together (same item order for both, so they line up positionally).
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.customer_name, o.customer_phone, o.courier_name, o.customer_address,
               o.total, o.amount_paid, o.remarks, fp.name AS page_name,
               GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR '; ') AS product_names,
               GROUP_CONCAT(oi.qty ORDER BY oi.id SEPARATOR '; ') AS quantities
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN fb_pages fp ON fp.id = o.fb_page_id
        $whereSQL
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders_export_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order ID', 'Name', 'Phone Number', 'Courier', 'Address', 'Order Value', 'Amount', 'Quantity', 'Product Name', 'Page', 'Remarks']);

    foreach ($rows as $r) {
        // Order Value is always the real order total (our revenue). Amount is
        // what the courier should actually collect on delivery — the total
        // minus whatever's already been paid/prepaid, never negative. This
        // stays correct even after a price-changing Exchange on an
        // already-paid order (e.g. Rs 500 paid, exchanged for a Rs 600 item
        // — Amount correctly shows the Rs 100 difference still owed).
        $amountToCollect = max(0, (float)$r['total'] - (float)$r['amount_paid']);
        fputcsv($out, [
            $r['order_id'],
            $r['customer_name'],
            $r['customer_phone'],
            $r['courier_name'] ?? '',
            $r['customer_address'] ?? '',
            $r['total'],
            $amountToCollect,
            $r['quantities'] ?? '',
            $r['product_names'] ?? '',
            $r['page_name'] ?? '',
            $r['remarks'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── UPDATE PAYMENT STATUS ────────────────────────────────────
if ($action === 'payment' && ($isAdmin || $isSuper) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body           = json_decode(file_get_contents('php://input'), true);
    $orderId        = trim($body['order_id']        ?? '');
    $payment_status = trim($body['payment_status']  ?? '');

    $validPay = ['unpaid', 'paid', 'partial', 'refunded'];
    if (!$orderId || !in_array($payment_status, $validPay)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']); exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_id=?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { echo json_encode(['success' => false, 'message' => 'Order not found.']); exit; }

    $pdo->prepare("UPDATE orders SET payment_status=?, updated_by=? WHERE id=?")
        ->execute([$payment_status, $user['id'], $order['id']]);

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);