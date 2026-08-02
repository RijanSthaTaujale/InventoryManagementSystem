<?php
// config/auth_guard.php
// Include this at the top of every protected page AFTER config/app.php

if (!isLoggedIn()) {
    redirect('/pages/login.php');
}

// Keep last_activity_at fresh so "active now" stays accurate — throttled so
// this isn't a write on every single request. Also clears logout_at: if the
// tab-close beacon (see components/foot.php) marked this session offline but
// the same browser session is still valid and back making requests, a real
// request means they're back, so any stale "offline" mark is stale, not true.
if (!empty($_SESSION['session_log_id']) && (empty($_SESSION['activity_touched_at']) || time() - $_SESSION['activity_touched_at'] > 20)) {
    $pdo->prepare("UPDATE user_sessions SET last_activity_at=NOW(), logout_at=NULL WHERE id=?")->execute([$_SESSION['session_log_id']]);
    $_SESSION['activity_touched_at'] = time();
}