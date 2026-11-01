<?php
// dashboard/ajax_live_activity.php - Real-Time Activity Notification Poller
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Allow active sessions or safe local calls
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = !empty($_SESSION['user_id']);
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']) || (PHP_OS_FAMILY === 'Windows');

if (!$isLoggedIn && !$isLocal) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
    exit;
}

$pdo = getDBConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'DB unreachable']);
    exit;
}

$sinceId = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

try {
    // Current highest log ID in the system
    $currentMaxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM audit_logs")->fetchColumn();

    // On initial synchronization (first page load), just return the cursor without replaying old toasts
    if ($sinceId <= 0) {
        echo json_encode([
            'success' => true,
            'current_max_id' => $currentMaxId,
            'events' => []
        ]);
        exit;
    }

    // If no new events
    if ($currentMaxId <= $sinceId) {
        echo json_encode([
            'success' => true,
            'current_max_id' => $currentMaxId,
            'events' => []
        ]);
        exit;
    }

    // Fetch new operations occurred since last cursor
    $stmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.user_name, a.action, a.entity, a.entity_id, a.details, a.created_at,
               u.role as user_role, u.full_name as real_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.id > ?
        ORDER BY a.id ASC
        LIMIT 10
    ");
    $stmt->execute([$sinceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $events = [];
    foreach ($rows as $r) {
        $action = $r['action'];
        $entity = $r['entity'];
        $details = $r['details'] ?? '';
        $userRole = $r['user_role'] ?? '';
        $userName = $r['real_name'] ?: ($r['user_name'] ?: 'مجهول');

        // 1. Determine Actor Category (Driver / Client / Admin / System)
        $adminActions = [
            'versement_approved', 'versement_rejected', 'contract_created', 'contract_terminated',
            'contract_updated', 'driver_created', 'driver_deleted', 'driver_debt_archived',
            'driver_debt_restored', 'penalty_settings_updated', 'settings_updated',
            'cnas_marked_paid', 'slip_generated', 'entity_soft_deleted', 'entity_restored',
            'entity_permanently_deleted', 'backup_restored', 'wipe_all_data'
        ];

        if (
            $userRole === 'driver' ||
            in_array($action, ['payment_submitted', 'driver_docs_updated', 'leave_requested'])
        ) {
            $actorType = 'driver';
            $actorBadge = '🚗 سائق';
            $actorColor = 'amber';
            $icon = '🚗';
        } elseif (
            $entity === 'leads' ||
            $entity === 'driver_ratings' ||
            in_array($action, ['client_lead_submitted', 'passenger_rating_submitted', 'vehicle_qr_scanned']) ||
            str_contains($details, 'العميل') ||
            str_contains($details, 'الراكب') ||
            str_contains($details, 'زبون')
        ) {
            $actorType = 'client';
            $actorBadge = '👤 عميل / زبون';
            $actorColor = 'blue';
            $icon = '👤';
        } elseif (
            $userRole === 'super_admin' ||
            $userRole === 'admin' ||
            in_array($action, $adminActions) ||
            ($userName !== 'النظام' && !empty($userName))
        ) {
            $actorType = 'admin';
            $actorBadge = '🛡️ مسؤول / إدارة';
            $actorColor = 'purple';
            $icon = '🛡️';
        } else {
            $actorType = 'system';
            $actorBadge = '⚙️ النظام الآلي';
            $actorColor = 'slate';
            $icon = '⚙️';
        }

        // 2. Human-Friendly Title
        $titleMap = [
            'payment_submitted' => 'إيداع وصل تسديد مالي (بريدي موب)',
            'versement_approved' => 'اعتماد وتحصيل دفعة مالية',
            'versement_rejected' => 'رفض وصل دفعة مالية',
            'client_lead_submitted' => 'طلب انضمام جديد من عميل',
            'passenger_rating_submitted' => 'تقييم راكب جديد لسيارة أجرة',
            'vehicle_qr_scanned' => 'مسح كود باركود QR لمركبة الأجرة',
            'contract_created' => 'تحرير عقد عمل جديد',
            'contract_terminated' => 'فسخ / إنهاء عقد سائق',
            'driver_created' => 'تسجيل سائق جديد بالمنصة',
            'driver_updated' => 'تحديث ملف سائق',
            'driver_docs_updated' => 'رفع وثائق جديدة للسائق',
            'driver_debt_archived' => 'أرشفة ديون سائق سابق',
            'driver_debt_restored' => 'استعادة ديون سائق من الأرشيف',
            'automated_penalties_applied' => 'تطبيق غرامات التأخير التلقائية',
            'penalty_settings_updated' => 'تعديل إعدادات غرامات التأخير',
            'cnas_marked_paid' => 'تسوية اشتراك الضمان الاجتماعي (CNAS)',
            'slip_generated' => 'استخراج جدول إرسال جديد',
            'login_success' => 'تسجيل دخول إلى الحساب',
            'login_qr_success' => 'تسجيل دخول سريع عبر كود QR',
            'entity_soft_deleted' => 'نقل عنصر إلى سلة المحذوفات',
            'entity_restored' => 'استرجاع عنصر من سلة المحذوفات'
        ];
        $title = $titleMap[$action] ?? ('عملية: ' . str_replace('_', ' ', $action));

        // 3. Relevant Action Link
        $link = '';
        if ($entity === 'versements' || $entity === 'driver_debts') {
            $link = url('/dashboard/debts.php');
        } elseif ($entity === 'contracts') {
            $link = url('/dashboard/contracts.php');
        } elseif ($entity === 'users' && !empty($r['entity_id'])) {
            $link = url('/dashboard/driver_fiche.php?id=' . (int)$r['entity_id']);
        } elseif ($entity === 'vehicles') {
            $link = url('/dashboard/vehicles.php');
        } elseif ($entity === 'traveler_reports') {
            $link = url('/dashboard/traveler_reports.php');
        } elseif ($entity === 'leads') {
            $link = url('/dashboard/index.php');
        } elseif ($entity === 'driver_ratings' || $action === 'passenger_rating_submitted') {
            $link = !empty($r['entity_id']) ? url('/dashboard/ratings.php?id=' . (int)$r['entity_id']) : url('/dashboard/ratings.php');
        } elseif ($entity === 'settings') {
            $link = url('/dashboard/settings.php');
        }

        $events[] = [
            'id' => (int)$r['id'],
            'actor_type' => $actorType,
            'actor_badge' => $actorBadge,
            'actor_name' => $userName,
            'actor_color' => $actorColor,
            'icon' => $icon,
            'title' => $title,
            'details' => $details ?: 'تم تنفيذ العملية بنجاح في النظام.',
            'time' => date('H:i:s', strtotime($r['created_at'])),
            'link' => $link
        ];
    }

    echo json_encode([
        'success' => true,
        'current_max_id' => $currentMaxId,
        'events' => $events
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
