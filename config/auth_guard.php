<?php
// config/auth_guard.php
// Include this at the top of every protected page AFTER config/app.php

if (!isLoggedIn()) {
    redirect('/pages/login.php');
}

// Keep last_activity_at fresh so "active now" stays accurate — throttled to
// once a minute per session so this isn't a write on every single request.
if (!empty($_SESSION['session_log_id']) && (empty($_SESSION['activity_touched_at']) || time() - $_SESSION['activity_touched_at'] > 60)) {
    $pdo->prepare("UPDATE user_sessions SET last_activity_at=NOW() WHERE id=?")->execute([$_SESSION['session_log_id']]);
    $_SESSION['activity_touched_at'] = time();
}