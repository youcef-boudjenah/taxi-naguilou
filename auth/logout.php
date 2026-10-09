<?php
// auth/logout.php - Logout handler
require_once __DIR__ . '/../includes/auth.php';
logout_user();
header('Location: /auth/login.php');
exit;
