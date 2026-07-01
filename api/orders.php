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

// Applies qty_change (direction * item qty) to every product in an order's
// line items, recalculates stock_status, and logs one stock_adjustments row
// per item. direction: -1 to deduct (dispatch), +1 to restore (return).
function adjustOrderStock(PDO $pdo, int $orderDbId, string $adjType, int $direction, int $userId, string $reason): void {
    $items = $pdo->prepare("SELECT product_id, qty FROM order_items WHERE order_id=? AND product_id IS NOT NULL");
    $items->execute([$orderDbId]);

    foreach ($items->fetchAll() as $item) {
        $productId = (int)$item['product_id'];
        $qtyChange = $direction * (int)$item['qty'];

        $row = $pdo->prepare("SELECT quantity, min_stock_level FROM products WHERE id=?");
        $row->execute([$productId]);
        $product = $row->fetch();
        if (!$product) continue;

        $qtyBefore = (int)$product['quantity'];
        $qtyAfter  = max(0, $qtyBefore + $qtyChange);
        $min       = (int)$product['min_stock_level'];
        $stockStatus = $qtyAfter <= 0 ? 'outofstock' : ($qtyAfter <= 2 ? 'critical' : ($qtyAfter <= $min ? 'lowstock' : 'instock'));

        $pdo->prepare("UPDATE products SET quantity=?, stock_status=? WHERE id=?")
            ->execute([$qtyAfter, $stockStatus, $productId]);

        $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reason,adjusted_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$productId, $adjType, $qtyBefore, $qtyAfter - $qtyBefore, $qtyAfter, $reason, $userId]);
    }
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
        if ($product['quantity'] < $qty) {
            echo json_encode(['success' => false, 'message' => "Insufficient stock for {$product['name']}."]); exit;
        }

        $sell_price   = (float)($item['sell_price'] ?? $product['sell_price']);
        $buy_price    = (float)$product['buy_price'];
        $line_total   = $sell_price * $qty;
        $subtotal    += $line_total;

        $lineItems[] = [
            'product_id'   => $product_id,
            'product_name' => $product['name'],
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
                    (order_id, product_id, product_name, variant_info, qty, sell_price, buy_price, total)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([
                $dbOrderId, $li['product_id'], $li['product_name'], $li['variant_info'],
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

// ── CHECK DUPLICATE (same phone, same day) ───────────────────
if ($action === 'check_duplicate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone = trim($_GET['phone'] ?? '');
    if (!preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(['success' => true, 'duplicate' => false]); exit;
    }

    $stmt = $pdo->prepare("
        SELECT order_id, created_at FROM orders
        WHERE customer_phone = ? AND DATE(created_at) = CURDATE()
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$phone]);
    $existing = $stmt->fetch();

    echo json_encode([
        'success'   => true,
        'duplicate' => (bool)$existing,
        'order_id'  => $existing['order_id'] ?? null,
    ]);
    exit;
}

// ── UPDATE STATUS (single order) ─────────────────────────────
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $orderId   = trim($body['order_id'] ?? '');
    $newStatus = trim($body['status']   ?? '');

    $valid = ['new','confirmed','pending','cancelled','dispatched','delivered','returned','in_courier'];
    if (!$orderId || !in_array($newStatus, $valid)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']); exit;
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

        $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by) VALUES (?,?,?,?)")
            ->execute([$order['id'], $order['status'], $newStatus, $user['id']]);

        // Stock deducts once, at dispatch time
        if ($newStatus === 'dispatched' && !$order['stock_deducted']) {
            adjustOrderStock($pdo, $order['id'], 'sale', -1, $user['id'], "Dispatched {$orderId}");
            $pdo->prepare("UPDATE orders SET stock_deducted=1 WHERE id=?")->execute([$order['id']]);
        }

        // Stock restores once, at return time
        if ($newStatus === 'returned' && !$order['stock_restored']) {
            adjustOrderStock($pdo, $order['id'], 'return', 1, $user['id'], "Returned {$orderId}");
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

// ── CONFIRM ALL DISPATCHED ORDERS (bulk, admin + supervisor) ──
// Reverts every order currently in "dispatched" status back to "confirmed".
// Use case: undo/recall a batch of orders that were dispatched in error.
if ($action === 'confirm_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin && !$isSuper) {
        echo json_encode(['success' => false, 'message' => 'Only admin or supervisor can do this.']); exit;
    }

    $rows = $pdo->query("SELECT id, order_id, stock_deducted FROM orders WHERE status='dispatched'")->fetchAll();
    if (empty($rows)) {
        echo json_encode(['success' => true, 'count' => 0, 'message' => 'No dispatched orders to confirm.']); exit;
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $pdo->prepare("UPDATE orders SET status='confirmed', updated_by=? WHERE id=?")
                ->execute([$user['id'], $row['id']]);
            $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by) VALUES (?,'dispatched','confirmed',?)")
                ->execute([$row['id'], $user['id']]);

            // Undoing a dispatch must restore the stock that dispatch deducted
            if ($row['stock_deducted']) {
                adjustOrderStock($pdo, $row['id'], 'adjustment', 1, $user['id'], "Dispatch undone via Confirm All ({$row['order_id']})");
                $pdo->prepare("UPDATE orders SET stock_deducted=0 WHERE id=?")->execute([$row['id']]);
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'count' => count($rows)]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to update orders.']);
    }
    exit;
}

// ── UPDATE PAYMENT STATUS ────────────────────────────────────
if ($action === 'payment' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
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