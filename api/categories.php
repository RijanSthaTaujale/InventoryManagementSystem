<?php
// api/categories.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';
header('Content-Type: application/json');

$user = currentUser();
if ($user['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── ADD ──────────────────────────────────────────────────────
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $name = trim($body['name'] ?? '');

    if (!$name) { echo json_encode(['success' => false, 'message' => 'Category name is required.']); exit; }

    $exists = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
    $exists->execute([$name]);
    if ($exists->fetch()) { echo json_encode(['success' => false, 'message' => 'A category with this name already exists.']); exit; }

    $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name]);
    exit;
}

// ── EDIT ─────────────────────────────────────────────────────
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id']   ?? 0);
    $name = trim($body['name'] ?? '');

    if (!$id || !$name) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

    $exists = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id <> ?");
    $exists->execute([$name, $id]);
    if ($exists->fetch()) { echo json_encode(['success' => false, 'message' => 'A category with this name already exists.']); exit; }

    $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$name, $id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);

    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid category.']); exit; }

    $inUse = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
    $inUse->execute([$id]);
    if ((int)$inUse->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete a category that is still assigned to products.']); exit;
    }

    $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
