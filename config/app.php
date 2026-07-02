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