<?php
// pages/register.php
// Self-registration is disabled. Only admins can create accounts via the admin panel.
require_once __DIR__ . '/../config/app.php';

// If somehow already logged in, go to dashboard
if (isLoggedIn()) {
    redirect('/pages/dashboard.php');
}

// Everyone else goes to login
redirect('/pages/login.php');