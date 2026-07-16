<?php
// api/inventory.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';
header('Content-Type: application/json');

$action  = $_GET['action'] ?? '';
$user    = currentUser();
$isAdmin = $user['role'] === 'admin';
$isAdminOrSupervisor = in_array($user['role'], ['admin', 'supervisor'], true);

if ($action === 'adjust' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body       = json_decode(file_get_contents('php://input'), true);
    $product_id = (int)($body['product_id'] ?? 0);
    $type       = trim($body['type']        ?? 'add');
    $qty        = (int)($body['qty']        ?? 0);
    $reason     = trim($body['reason']      ?? '');

    if (!$product_id) { echo json_encode(['success'=>false,'message'=>'Invalid product']); exit; }

    $stmt = $pdo->prepare("SELECT id, quantity FROM products WHERE id=? AND status='active'");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    if (!$product) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

    $qtyBefore = (int)$product['quantity'];

    if ($type === 'add') {
        $qtyAfter  = $qtyBefore + $qty;
        $qtyChange = $qty;
    } elseif ($type === 'remove') {
        $qtyAfter  = max(0, $qtyBefore - $qty);
        $qtyChange = -($qtyBefore - $qtyAfter);
    } elseif ($type === 'adjustment') {
        $qtyAfter  = max(0, $qty);
        $qtyChange = $qtyAfter - $qtyBefore;
    } else {
        echo json_encode(['success'=>false,'message'=>'Invalid type']); exit;
    }

    // Recalculate stock_status
    $minStock = (int)$pdo->prepare("SELECT min_stock_level FROM products WHERE id=?")->execute([$product_id]) ? 5 : 5;
    $minRow   = $pdo->prepare("SELECT min_stock_level FROM products WHERE id=?");
    $minRow->execute([$product_id]);
    $minStock = (int)($minRow->fetchColumn() ?: 5);

    $stockStatus = $qtyAfter <= 0 ? 'outofstock'
        : ($qtyAfter <= 2 ? 'critical'
        : ($qtyAfter <= $minStock ? 'lowstock' : 'instock'));

    // Update product
    $pdo->prepare("UPDATE products SET quantity=?, stock_status=?, updated_by=? WHERE id=?")
        ->execute([$qtyAfter, $stockStatus, $user['id'], $product_id]);

    // Log adjustment
    $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reason,adjusted_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$product_id, $type, $qtyBefore, $qtyChange, $qtyAfter, $reason ?: null, $user['id']]);

    echo json_encode(['success'=>true,'qty_after'=>$qtyAfter,'stock_status'=>$stockStatus]);
    exit;
}

// ── LOG DAMAGE (admin only) ───────────────────────────────────
// Deducts damaged qty from stock, logs it in both damaged_products
// (dedicated damage log) and stock_adjustments (type='damaged', for the
// unified per-product inventory log).
if ($action === 'log_damage' && $isAdminOrSupervisor && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body       = json_decode(file_get_contents('php://input'), true);
    $product_id = (int)($body['product_id'] ?? 0);
    $qty        = (int)($body['qty']        ?? 0);
    $reason     = trim($body['reason']      ?? '');

    if (!$product_id || $qty < 1) { echo json_encode(['success'=>false,'message'=>'Invalid product or quantity']); exit; }

    $stmt = $pdo->prepare("SELECT id, quantity, min_stock_level FROM products WHERE id=? AND status='active'");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    if (!$product) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

    $qtyBefore = (int)$product['quantity'];
    $qtyAfter  = max(0, $qtyBefore - $qty);
    $qtyChange = $qtyAfter - $qtyBefore;
    $minStock  = (int)$product['min_stock_level'];

    $stockStatus = $qtyAfter <= 0 ? 'outofstock'
        : ($qtyAfter <= 2 ? 'critical'
        : ($qtyAfter <= $minStock ? 'lowstock' : 'instock'));

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE products SET quantity=?, stock_status=?, updated_by=? WHERE id=?")
            ->execute([$qtyAfter, $stockStatus, $user['id'], $product_id]);

        $pdo->prepare("INSERT INTO damaged_products (product_id, qty, reason, logged_by) VALUES (?,?,?,?)")
            ->execute([$product_id, $qty, $reason ?: null, $user['id']]);

        $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reason,adjusted_by) VALUES (?,'damaged',?,?,?,?,?)")
            ->execute([$product_id, $qtyBefore, $qtyChange, $qtyAfter, $reason ?: null, $user['id']]);

        $pdo->commit();
        echo json_encode(['success'=>true,'qty_after'=>$qtyAfter,'stock_status'=>$stockStatus]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Failed to log damage.']);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action or insufficient permissions']);