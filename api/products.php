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

if ($action === 'search') {
    $q    = trim($_GET['q'] ?? '');
    $like = "%$q%";
    $stmt = $pdo->prepare("SELECT id,product_id,name,sell_price,buy_price,quantity,image_url,stock_status FROM products WHERE status='active' AND (name LIKE ? OR product_id LIKE ? OR sku LIKE ?) LIMIT 20");
    $stmt->execute([$like,$like,$like]);
    echo json_encode(['success'=>true,'products'=>$stmt->fetchAll()]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action']);