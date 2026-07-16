<?php
// config/app.php

// Auto-detect the base URL path this app is served under. Works whether
// the vhost's DocumentRoot points straight at this folder (APP_URL '') or
// at a shared htdocs root above it (APP_URL '/InventoryManagement') —
// so the same codebase behaves correctly across different server setups
// without per-machine config edits.
$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot     = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$appUrl      = ($docRoot !== '' && stripos($projectRoot, $docRoot) === 0)
    ? substr($projectRoot, strlen($docRoot))
    : '';

define('APP_URL', $appUrl);
unset($projectRoot, $docRoot, $appUrl);

define('APP_NAME', 'Inventory Pro');

session_start();

require_once __DIR__ . '/db.php';

// Get the currently logged-in user from session
function currentUser(): array {
    return $_SESSION['user'] ?? [];
}

// Check if a user is logged in
function isLoggedIn(): bool {
    return !empty($_SESSION['user']['id']);
}

// Redirect helper
function redirect(string $path): void {
    header('Location: ' . APP_URL . $path);
    exit;
}

// Sanitize output
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Resolves a product image_url for display: external URLs pass through
// unchanged, local upload paths (e.g. /assets/uploads/products/x.jpg) get
// APP_URL prefixed.
function productImageUrl(?string $path): string {
    if (!$path) return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return APP_URL . $path;
}

// Read a key/value row from the settings table (cached per-request)
function getSetting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    global $pdo;
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $cache[$key] = ($val !== false ? $val : $default);
}

// Next unused PRD-#### code, based on the highest existing numeric suffix
// (not insertion order) so it stays correct even if the newest row was deleted.
function nextProductId(PDO $pdo): string {
    $max = (int)$pdo->query("SELECT MAX(CAST(SUBSTRING(product_id,5) AS UNSIGNED)) FROM products WHERE product_id LIKE 'PRD-%'")->fetchColumn();
    return 'PRD-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

// products.product_id is UNIQUE at the DB level; under concurrent creates two
// requests can generate the same next code before either commits. Retries
// with a freshly-generated code on a duplicate-key error instead of crashing.
function insertProductWithUniqueId(PDO $pdo, string $sql, array $namedFields, int $maxAttempts = 5): array {
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $productId = nextProductId($pdo);
        $fields = array_merge($namedFields, ['product_id' => $productId]);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($fields));
            return ['id' => $pdo->lastInsertId(), 'product_id' => $productId];
        } catch (PDOException $e) {
            $isDuplicateProductId = $e->getCode() === '23000' && str_contains($e->getMessage(), "'product_id'");
            if (!$isDuplicateProductId || $attempt === $maxAttempts - 1) throw $e;
        }
    }
    throw new RuntimeException('Could not generate a unique product ID.');
}