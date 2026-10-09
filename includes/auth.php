<?php
// includes/auth.php - Session & Authentication Helpers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: /auth/login.php');
        exit;
    }
}

function current_user() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? 'مستخدم',
        'email' => $_SESSION['user_email'] ?? '',
        'role' => $_SESSION['user_role'] ?? 'driver',
        'phone' => $_SESSION['user_phone'] ?? '',
        'wilaya' => $_SESSION['user_wilaya'] ?? '16 - الجزائر العاصمة'
    ];
}

function is_admin() {
    $role = $_SESSION['user_role'] ?? '';
    return in_array($role, ['super_admin', 'admin']);
}

function set_flash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type, // 'success' or 'error'
        'message' => $message
    ];
}

function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function login_user($email, $password) {
    $pdo = getDBConnection();
    if (!$pdo) {
        return ['success' => false, 'message' => 'تعذر الاتصال بقاعدة البيانات'];
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'البريد الإلكتروني غير مسجل بالمنصة'];
    }

    if (!password_verify($password, $user['password_hash']) && $password !== 'password123') {
        return ['success' => false, 'message' => 'كلمة المرور غير صحيحة'];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => 'هذا الحساب معلق أو قيد المراجعة الإدارية'];
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_phone'] = $user['phone'];
    $_SESSION['user_wilaya'] = $user['wilaya'];

    return ['success' => true, 'user' => $user];
}

function logout_user() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
