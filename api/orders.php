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
    $items = $pdo->prepare("SELECT product_id, variant_id, qty FROM order_items WHERE order_id=? AND product_id IS NOT NULL");
    $items->execute([$orderDbId]);

    foreach ($items->fetchAll() as $item) {
        $productId = (int)$item['product_id'];
        $variantId = $item['variant_id'] !== null ? (int)$item['variant_id'] : null;
        $qtyChange = $direction * (int)$item['qty'];

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

// Restores stock for a specific qty of a single order item, records the
// return against that line (returned_qty) and the order (order_returns +
// a reduced subtotal/total), and logs it in the inventory log too. Shared
// by the standalone per-item "Return" action and by the whole-order
// "Returned" status change, so a line already partially returned is never
// double-restocked no matter which path triggers the rest of it.
function returnOrderItem(PDO $pdo, array $item, int $qty, int $userId, string $orderRef, ?string $reason): float {
    $productId = (int)($item['product_id'] ?? 0);
    $variantId = $item['variant_id'] !== null ? (int)$item['variant_id'] : null;

    if ($productId) {
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

    $pdo->prepare("UPDATE order_items SET returned_qty = returned_qty + ? WHERE id=?")->execute([$qty, $item['id']]);

    $amount = round($qty * (float)$item['sell_price'], 2);
    $pdo->prepare("UPDATE orders SET subtotal = GREATEST(0, subtotal - ?), total = GREATEST(0, total - ?) WHERE id=?")
        ->execute([$amount, $amount, $item['order_id']]);

    $pdo->prepare("INSERT INTO order_returns (order_id,order_item_id,qty,amount,reason,returned_by) VALUES (?,?,?,?,?,?)")
        ->execute([$item['order_id'], $item['id'], $qty, $amount, $reason, $userId]);

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
    $payment_method   = trim($body['payment_method']   ?? 'cash');
    $payment_status   = 'unpaid'; // always starts unpaid — not client-controlled
    $shipping_method  = trim($body['shipping_method']  ?? '');
    $shipping_cost    = (float)($body['shipping_cost'] ?? 0);
    $courier_name     = trim($body['courier_name']     ?? '');
    $courier_charge   = (float)($body['courier_charge'] ?? 0);
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
    $total = max(0, $subtotal - $discountAmount + $shipping_cost);

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
                 subtotal, discount, discount_type, shipping_cost, shipping_method, courier_name, courier_charge, total,
                 payment_method, payment_status, status, remarks, created_by, assigned_to)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'new',?,?,?)
        ")->execute([
            $orderId, $customer_name, $customer_phone ?: null, $customer_email ?: null, $customer_address ?: null, $fb_page_id,
            $subtotal, $discountAmount, $discount_type, $shipping_cost, $shipping_method ?: null, $courier_name ?: null, $courier_charge, $total,
            $payment_method, $payment_status, $remarks ?: null, $user['id'], $user['id'],
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
    $payment_method   = trim($body['payment_method']   ?? 'cash');
    $shipping_method  = trim($body['shipping_method']  ?? '');
    $shipping_cost    = (float)($body['shipping_cost'] ?? 0);
    $courier_name     = trim($body['courier_name']     ?? '');
    $courier_charge   = (float)($body['courier_charge'] ?? 0);
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
    $total = max(0, $subtotal - $discountAmount + $shipping_cost);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE orders SET
                customer_name=?, customer_phone=?, customer_email=?, customer_address=?, fb_page_id=?,
                subtotal=?, discount=?, discount_type=?, shipping_cost=?, shipping_method=?,
                courier_name=?, courier_charge=?, total=?, payment_method=?, remarks=?, updated_by=?
            WHERE id=?
        ")->execute([
            $customer_name, $customer_phone ?: null, $customer_email ?: null, $customer_address ?: null, $fb_page_id,
            $subtotal, $discountAmount, $discount_type, $shipping_cost, $shipping_method ?: null,
            $courier_name ?: null, $courier_charge, $total, $payment_method, $remarks ?: null, $user['id'],
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
        $extra = ', delivered_at=NOW()';
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

    $body   = json_decode(file_get_contents('php://input'), true);
    $itemId = (int)($body['item_id'] ?? 0);
    $qty    = (int)($body['qty']     ?? 0);
    $reason = trim($body['reason']   ?? '');

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
        $amount = returnOrderItem($pdo, $item, $qty, $user['id'], $item['order_ref'], $reason ?: null);
        $pdo->commit();
        echo json_encode(['success' => true, 'amount' => $amount]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to process return.']);
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

            $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW(), updated_by=? WHERE id=?")
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
    if ($status && in_array($status, $validStatuses)) { $where[] = "o.status = ?"; $params[] = $status; }
    if ($dateFrom) { $where[] = "DATE(o.created_at) >= ?"; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = "DATE(o.created_at) <= ?"; $params[] = $dateTo; }
    if ($courier)  { $where[] = "o.courier_name = ?"; $params[] = $courier; }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // One row per order — multi-item orders get their product names/quantities
    // joined together (same item order for both, so they line up positionally).
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.customer_name, o.customer_phone, o.courier_name, o.customer_address,
               o.total, o.remarks, fp.name AS page_name,
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
    fputcsv($out, ['Order ID', 'Name', 'Phone Number', 'Courier', 'Address', 'Price', 'Quantity', 'Product Name', 'Page', 'Remarks']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['order_id'],
            $r['customer_name'],
            $r['customer_phone'],
            $r['courier_name'] ?? '',
            $r['customer_address'] ?? '',
            $r['total'],
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