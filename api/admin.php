<?php
// api/admin.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';
header('Content-Type: application/json');

$currentUser = currentUser();
if ($currentUser['role'] !== 'admin') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── SET STATUS ───────────────────────────────────────────────
if ($action === 'set_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $id     = (int)($body['id']     ?? 0);
    $status = trim($body['status']  ?? '');

    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid user']); exit; }
    if (!in_array($status,['active','inactive','deactivated'])) {
        echo json_encode(['success'=>false,'message'=>'Invalid status']); exit;
    }
    if ($id === $currentUser['id']) {
        echo json_encode(['success'=>false,'message'=>'Cannot change your own status']); exit;
    }

    $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status,$id]);
    echo json_encode(['success'=>true]);
    exit;
}

// ── DELETE USER ──────────────────────────────────────────────
if ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);

    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid user']); exit; }
    if ($id === $currentUser['id']) {
        echo json_encode(['success'=>false,'message'=>'Cannot delete your own account']); exit;
    }

    // Nullify references before deleting
    $pdo->prepare("UPDATE orders SET created_by=NULL,updated_by=NULL,assigned_to=NULL,dispatched_by=NULL WHERE created_by=? OR updated_by=? OR assigned_to=? OR dispatched_by=?")->execute([$id,$id,$id,$id]);
    $pdo->prepare("UPDATE products SET created_by=NULL,updated_by=NULL WHERE created_by=? OR updated_by=?")->execute([$id,$id]);
    $pdo->prepare("UPDATE stock_adjustments SET adjusted_by=NULL WHERE adjusted_by=?")->execute([$id]);
    $pdo->prepare("UPDATE order_status_log SET changed_by=NULL WHERE changed_by=?")->execute([$id]);
    $pdo->prepare("DELETE FROM user_sessions WHERE user_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);

    echo json_encode(['success'=>true]);
    exit;
}

// ── RESET PASSWORD ───────────────────────────────────────────
if ($action === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $id       = (int)($body['id']       ?? 0);
    $password = trim($body['password']  ?? '');

    if (!$id || strlen($password) < 6) {
        echo json_encode(['success'=>false,'message'=>'Invalid data']); exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash,$id]);
    // Invalidate all sessions
    $pdo->prepare("DELETE FROM user_sessions WHERE user_id=?")->execute([$id]);

    echo json_encode(['success'=>true]);
    exit;
}

// ── CHANGE ROLE ──────────────────────────────────────────────
if ($action === 'change_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id']   ?? 0);
    $role = trim($body['role']  ?? '');

    if (!$id || !in_array($role,['admin','staff','supervisor'])) {
        echo json_encode(['success'=>false,'message'=>'Invalid data']); exit;
    }
    if ($id === $currentUser['id']) {
        echo json_encode(['success'=>false,'message'=>'Cannot change your own role']); exit;
    }

    $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role,$id]);
    echo json_encode(['success'=>true]);
    exit;
}

// ── ADD FB PAGE ──────────────────────────────────────────────
if ($action === 'add_fb_page' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $name = trim($body['name'] ?? '');

    if (!$name) { echo json_encode(['success'=>false,'message'=>'Page name is required']); exit; }

    $pdo->prepare("INSERT INTO fb_pages (name, created_by) VALUES (?,?)")->execute([$name, $currentUser['id']]);
    echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId(), 'name'=>$name]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action']);