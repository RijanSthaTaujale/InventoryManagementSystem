<?php
// api/auth.php

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// ── LOGOUT ──────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    header('Location: ' . APP_URL . '/pages/login.php');
    exit;
}

// ── LOGIN ────────────────────────────────────────────────────
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $email    = trim($body['email']    ?? '');
    $password = trim($body['password'] ?? '');

    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    if ($user['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Your account has been deactivated. Contact an administrator.']);
        exit;
    }

    // Save to session
    $_SESSION['user'] = [
        'id'           => $user['id'],
        'name'         => $user['name'],
        'display_name' => $user['display_name'],
        'email'        => $user['email'],
        'role'         => $user['role'],
    ];

    // Update last login
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    echo json_encode([
        'success'  => true,
        'role'     => $user['role'],
        'redirect' => APP_URL . '/pages/dashboard.php',
    ]);
    exit;
}

// ── REGISTER ─────────────────────────────────────────────────
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $name             = trim($body['name']             ?? '');
    $email            = trim($body['email']            ?? '');
    $password         = trim($body['password']         ?? '');
    $confirm_password = trim($body['confirm_password'] ?? '');

    if (!$name || !$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }

    if ($password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    // Insert user (default role: staff)
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'staff', 'active')");
    $stmt->execute([$name, $email, $hash]);
    $userId = $pdo->lastInsertId();

    // Auto login after register
    $_SESSION['user'] = [
        'id'           => $userId,
        'name'         => $name,
        'display_name' => '',
        'email'        => $email,
        'role'         => 'staff',
    ];

    echo json_encode([
        'success'  => true,
        'redirect' => APP_URL . '/pages/dashboard.php',
    ]);
    exit;
}

// ── FALLBACK ─────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Invalid request.']);