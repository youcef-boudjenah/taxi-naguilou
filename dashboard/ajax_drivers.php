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
    $uCols = [];
    try {
        $uCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {}
    $uColMap = array_flip($uCols);

    $vCols = [];
    try {
        $vCols = $pdo->query("SHOW COLUMNS FROM `vehicles`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {}
    $vColMap = array_flip($vCols);

    $cardSelect = isset($uColMap['card_number']) ? "u.card_number" : "NULL AS card_number";
    $probSelect = isset($uColMap['probation_stage']) ? "u.probation_stage" : "'trial_1' AS probation_stage";
    $plateSelect = isset($vColMap['plate_number']) ? "v.plate_number AS current_plate" : "NULL AS current_plate";
    $doorSelect = isset($vColMap['door_number']) ? "v.door_number AS current_door" : "NULL AS current_door";

    $joinVeh = "";
    if (isset($vColMap['current_driver_id'])) {
        $statusCond = isset($vColMap['status']) ? "AND v.status = 'active'" : "";
        $joinVeh = "LEFT JOIN vehicles v ON (v.current_driver_id = u.id $statusCond)";
    }

    if (!empty($q)) {
        $searchTerm = '%' . $q . '%';
        $cardCond = isset($uColMap['card_number']) ? " OR u.card_number LIKE ?" : "";
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.phone, u.license_number, $cardSelect, $probSelect,
                   $plateSelect, $doorSelect
            FROM users u
            $joinVeh
            WHERE u.role = 'driver' AND u.status = 'active'
              AND (u.full_name LIKE ? OR u.phone LIKE ? OR u.license_number LIKE ?$cardCond)
            ORDER BY u.full_name ASC
            LIMIT 30
        ");
        $params = [$searchTerm, $searchTerm, $searchTerm];
        if (isset($uColMap['card_number'])) {
            $params[] = $searchTerm;
        }
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query("
            SELECT u.id, u.full_name, u.phone, u.license_number, $cardSelect, $probSelect,
                   $plateSelect, $doorSelect
            FROM users u
            $joinVeh
            WHERE u.role = 'driver' AND u.status = 'active'
            ORDER BY u.full_name ASC
            LIMIT 50
        ");
    }
    $drivers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    echo json_encode($drivers ?: [], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode([]);
}
