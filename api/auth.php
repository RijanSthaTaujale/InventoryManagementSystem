<?php
// api/auth.php
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// ── LOGOUT ──────────────────────────────────────────────────
if ($action === 'logout') {
    if (!empty($_SESSION['session_log_id'])) {
        $pdo->prepare("UPDATE user_sessions SET logout_at=NOW() WHERE id=?")->execute([$_SESSION['session_log_id']]);
    }
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

    // Log this session — the id gets carried in $_SESSION so auth_guard.php
    // can refresh last_activity_at, and logout (above) can close it out.
    // `token` isn't actually used for auth (that's the PHP session cookie),
    // it just satisfies the column's NOT NULL UNIQUE constraint.
    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);
    $pdo->prepare("
        INSERT INTO user_sessions (user_id, token, ip, user_agent, expires_at, last_activity_at)
        VALUES (?,?,?,?,?,NOW())
    ")->execute([$user['id'], $token, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, $expiresAt]);
    $_SESSION['session_log_id'] = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'  => true,
        'role'     => $user['role'],
        'redirect' => APP_URL . '/pages/dashboard.php',
    ]);
    exit;
}

// ── FALLBACK ─────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Invalid request.']);