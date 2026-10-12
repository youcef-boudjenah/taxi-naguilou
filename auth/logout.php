<?php
// auth/logout.php - Logout handler
require_once __DIR__ . '/../includes/auth.php';
logout_user();
redirect('/auth/login.php');
