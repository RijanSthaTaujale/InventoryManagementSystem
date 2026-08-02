<?php
// config/auth_guard.php
// Include this at the top of every protected page AFTER config/app.php

if (!isLoggedIn()) {
    redirect('/pages/login.php');
}

if (!empty($_SESSION['session_log_id'])) {
    // The tab-close beacon (components/foot.php) fires on pagehide, which
    // also fires on plain in-app navigation (clicking any link), not just
    // an actual close — so a "logout_at" mark it left behind can be stale
    // the instant the next page loads. Any real request proves the session
    // is still here, so this always clears it, un-throttled: gating it
    // behind the same throttle as the activity timestamp below let a user
    // clicking around faster than the throttle window get stuck showing
    // "offline" the whole time, even while actively browsing.
    $pdo->prepare("UPDATE user_sessions SET logout_at=NULL WHERE id=? AND logout_at IS NOT NULL")->execute([$_SESSION['session_log_id']]);

    // last_activity_at only needs per-minute precision, so this part stays
    // throttled to avoid a write on every single request.
    if (empty($_SESSION['activity_touched_at']) || time() - $_SESSION['activity_touched_at'] > 20) {
        $pdo->prepare("UPDATE user_sessions SET last_activity_at=NOW() WHERE id=?")->execute([$_SESSION['session_log_id']]);
        $_SESSION['activity_touched_at'] = time();
    }
}