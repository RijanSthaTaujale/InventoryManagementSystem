<?php
// api/products.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$isAdmin = currentUser()['role'] === 'admin';

if ($action === 'delete' && $isAdmin) {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
    $pdo->prepare("UPDATE products SET status='discontinued' WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    exit;
}

// Permanently removes a product — unlike the soft-delete above (which just
// discontinues it), this actually erases the row. Order history survives
// since order_items snapshots product_name/sell_price/buy_price and only
// has product_id/variant_id nulled (ON DELETE SET NULL); but this product's
// own variants, photos, stock-adjustment log, and damaged-stock log all
// cascade-delete with it, which is why it's a separate, admin-only action
// from the reversible discontinue above.
if ($action === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Only admin can delete products.']); exit; }

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

    $stmt = $pdo->prepare("SELECT image_url FROM products WHERE id=?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) { echo json_encode(['success'=>false,'message'=>'Product not found.']); exit; }

    $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);

    if ($product['image_url'] && str_starts_with($product['image_url'], '/assets/uploads/products/')) {
        @unlink(__DIR__ . '/../' . ltrim($product['image_url'], '/'));
    }

    echo json_encode(['success'=>true]);
    exit;
}

if ($action === 'search') {
    $q    = trim($_GET['q'] ?? '');
    $like = "%$q%";
    $stmt = $pdo->prepare("SELECT id,product_id,name,sell_price,buy_price,quantity,image_url,stock_status,fb_page_id FROM products WHERE status='active' AND (name LIKE ? OR product_id LIKE ? OR sku LIKE ?) LIMIT 20");
    $stmt->execute([$like,$like,$like]);
    $products = $stmt->fetchAll();

    // Fetch every matched product's variants in one query instead of one
    // query per row — this endpoint fires on every keystroke while building
    // an order, so N+1 here means N+1 on every search a staff member types.
    $variantsByProduct = [];
    if ($products) {
        $ids          = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $vStmt = $pdo->prepare("SELECT id,product_id,label,value,sell_price,buy_price,qty_adj FROM product_variants WHERE product_id IN ($placeholders) ORDER BY id");
        $vStmt->execute($ids);
        foreach ($vStmt->fetchAll() as $v) {
            $variantsByProduct[$v['product_id']][] = $v;
        }
    }

    foreach ($products as &$p) {
        $p['image_url'] = productImageUrl($p['image_url']);
        $p['variants']  = $variantsByProduct[$p['id']] ?? [];
    }
    unset($p);
    echo json_encode(['success'=>true,'products'=>$products]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action']);