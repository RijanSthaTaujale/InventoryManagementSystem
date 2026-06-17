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

// ── CREATE ORDER ─────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $customer_name    = trim($body['customer_name']    ?? '');
    $customer_phone   = trim($body['customer_phone']   ?? '');
    $customer_email   = trim($body['customer_email']   ?? '');
    $customer_address = trim($body['customer_address'] ?? '');
    $payment_method   = trim($body['payment_method']   ?? 'cash');
    $payment_status   = trim($body['payment_status']   ?? 'unpaid');
    $shipping_method  = trim($body['shipping_method']  ?? '');
    $shipping_cost    = (float)($body['shipping_cost'] ?? 0);
    $discount         = (float)($body['discount']      ?? 0);
    $discount_type    = trim($body['discount_type']    ?? 'fixed');
    $remarks          = trim($body['remarks']          ?? '');
    $items            = $body['items'] ?? [];

    if (!$customer_name) {
        echo json_encode(['success' => false, 'message' => 'Customer name is required.']); exit;
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
                (order_id, customer_name, customer_phone, customer_email, customer_address,
                 subtotal, discount, discount_type, shipping_cost, shipping_method, total,
                 payment_method, payment_status, status, remarks, created_by, assigned_to)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'new',?,?,?)
        ")->execute([
            $orderId, $customer_name, $customer_phone ?: null, $customer_email ?: null, $customer_address ?: null,
            $subtotal, $discountAmount, $discount_type, $shipping_cost, $shipping_method ?: null, $total,
            $payment_method, $payment_status, $remarks ?: null, $user['id'], $user['id'],
        ]);
        $dbOrderId = $pdo->lastInsertId();

        // Insert items and deduct stock
        foreach ($lineItems as $li) {
            $pdo->prepare("
                INSERT INTO order_items
                    (order_id, product_id, product_name, variant_info, qty, sell_price, buy_price, total)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([
                $dbOrderId, $li['product_id'], $li['product_name'], $li['variant_info'],
                $li['qty'], $li['sell_price'], $li['buy_price'], $li['total'],
            ]);

            // Deduct stock
            $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id=?")->execute([$li['qty'], $li['product_id']]);

            // Recalculate stock_status
            $row = $pdo->prepare("SELECT quantity, min_stock_level FROM products WHERE id=?");
            $row->execute([$li['product_id']]);
            $pr  = $row->fetch();
            $qty = (int)$pr['quantity'];
            $min = (int)$pr['min_stock_level'];
            $ss  = $qty <= 0 ? 'outofstock' : ($qty <= 2 ? 'critical' : ($qty <= $min ? 'lowstock' : 'instock'));
            $pdo->prepare("UPDATE products SET stock_status=? WHERE id=?")->execute([$ss, $li['product_id']]);
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

// ── UPDATE STATUS ────────────────────────────────────────────
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $orderId   = trim($body['order_id'] ?? '');
    $newStatus = trim($body['status']   ?? '');

    $valid = ['new','confirmed','pending','cancelled','dispatched','delivered','returned','in_courier'];
    if (!$orderId || !in_array($newStatus, $valid)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']); exit;
    }

    // Role-based permission checks
    if ($newStatus === 'dispatched' && !$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Only admin can dispatch orders.']); exit;
    }
    if (in_array($newStatus, ['delivered', 'returned', 'in_courier']) && $isStaff) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions for this status.']); exit;
    }

    $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE order_id=?");
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

    $pdo->prepare("UPDATE orders SET status=?, updated_by=?$extra WHERE id=?")->execute($params);

    $pdo->prepare("INSERT INTO order_status_log (order_id,from_status,to_status,changed_by) VALUES (?,?,?,?)")
        ->execute([$order['id'], $order['status'], $newStatus, $user['id']]);

    echo json_encode(['success' => true]);
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