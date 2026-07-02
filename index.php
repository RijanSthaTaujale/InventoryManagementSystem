<?php
// index.php
require_once __DIR__ . '/config/app.php';

redirect(isLoggedIn() ? '/pages/dashboard.php' : '/pages/login.php');
