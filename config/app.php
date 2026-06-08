<?php
// config/app.php

define('APP_URL', '/InventoryManagement');
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