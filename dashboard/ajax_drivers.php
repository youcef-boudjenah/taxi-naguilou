<?php
// dashboard/ajax_drivers.php - Live AJAX Search for Drivers
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDBConnection();
if (!$pdo) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');

try {
    if (!empty($q)) {
        $searchTerm = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.phone, u.license_number, u.card_number, u.probation_stage,
                   v.plate_number AS current_plate, v.door_number AS current_door
            FROM users u
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id AND v.status = 'active')
            WHERE u.role = 'driver' AND u.status = 'active'
              AND (u.full_name LIKE ? OR u.phone LIKE ? OR u.license_number LIKE ? OR u.card_number LIKE ?)
            ORDER BY u.full_name ASC
            LIMIT 30
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    } else {
        $stmt = $pdo->query("
            SELECT u.id, u.full_name, u.phone, u.license_number, u.card_number, u.probation_stage,
                   v.plate_number AS current_plate, v.door_number AS current_door
            FROM users u
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id AND v.status = 'active')
            WHERE u.role = 'driver' AND u.status = 'active'
            ORDER BY u.full_name ASC
            LIMIT 50
        ");
    }
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($drivers, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode([]);
}
