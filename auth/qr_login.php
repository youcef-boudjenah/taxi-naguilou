<?php
// auth/qr_login.php - Fast QR-Code Based User Authentication & Portal Access
require_once __DIR__ . '/../includes/auth.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    set_flash('error', 'رمز الدخول السريع (QR Code) غير متوفر أو غير صالح.');
    redirect('/auth/login.php');
    exit;
}

$res = login_user_by_qr_token($token);

if ($res['success']) {
    $uName = htmlspecialchars($res['user']['full_name']);
    set_flash('success', "مرحباً بك {$uName}! تم تسجيل الدخول إلى حسابك بنجاح عبر رمز QR.");
    redirect('/dashboard/index.php');
    exit;
} else {
    set_flash('error', $res['message']);
    redirect('/auth/login.php');
    exit;
}
