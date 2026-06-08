<?php
// config/auth_guard.php
// Include this at the top of every protected page AFTER config/app.php

if (!isLoggedIn()) {
    redirect('/pages/login.php');
}