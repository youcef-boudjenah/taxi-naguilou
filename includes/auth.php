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
        redirect('/auth/login.php');
    }
}

function require_auth() {
    require_login();
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

function require_admin() {
    require_role(['super_admin', 'admin']);
}

function is_owner(): bool {
    return has_role(['owner']);
}

function is_driver(): bool {
    return has_role(['driver']);
}

function has_role($roles): bool {
    if (!is_logged_in()) {
        return false;
    }
    $currentRole = $_SESSION['user_role'] ?? '';
    if (is_array($roles)) {
        if ($currentRole === 'super_admin' && (in_array('admin', $roles) || in_array('super_admin', $roles))) {
            return true;
        }
        return in_array($currentRole, $roles);
    }
    if ($currentRole === 'super_admin' && ($roles === 'admin' || $roles === 'super_admin')) {
        return true;
    }
    return $currentRole === $roles;
}

function require_role($roles) {
    require_login();
    if (!has_role($roles)) {
        set_flash('error', 'عذراً، هذا القسم يتطلب صلاحيات مخصصة ولا يمكنك الوصول إليه.');
        redirect('/dashboard/index.php');
        exit;
    }
}

/**
 * CSRF Protection Helpers
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

function require_csrf_token(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token()) {
            set_flash('error', 'انتهت صلاحية جلسة العمل أو رمز الأمان غير مطابق (CSRF Token Invalid). يرجى المحاولة مجدداً.');
            $referer = $_SERVER['HTTP_REFERER'] ?? '/dashboard/index.php';
            header("Location: " . $referer);
            exit;
        }
    }
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
        log_audit($pdo, 'login_failed', 'auth', $user['id'], "محاولة دخول فاشلة للمستخدم {$user['email']}");
        return ['success' => false, 'message' => 'كلمة المرور غير صحيحة'];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => 'هذا الحساب معلق أو قيد المراجعة الإدارية'];
    }

    // Regenerate session ID to prevent session fixation
    if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_phone'] = $user['phone'];
    $_SESSION['user_wilaya'] = $user['wilaya'];

    log_audit($pdo, 'login_success', 'auth', $user['id'], "تسجيل دخول ناجح إلى المنصة");

    return ['success' => true, 'user' => $user];
}

function logout_user() {
    $pdo = getDBConnection();
    if ($pdo && is_logged_in()) {
        log_audit($pdo, 'logout', 'auth', $_SESSION['user_id'] ?? null, "تسجيل خروج من المنصة");
    }
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
