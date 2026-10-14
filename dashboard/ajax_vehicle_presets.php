<?php
// dashboard/ajax_vehicle_presets.php - Manage Vehicle Brands & Models Presets in Settings
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
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$defaultPresets = [
    'Renault' => ['Symbol', 'Megane', 'Clio', 'Fluence', 'Kangoo'],
    'Dacia' => ['Logan', 'Sandero', 'Stepway', 'Duster', 'Dokker'],
    'Peugeot' => ['301', '208', '308', '2008', 'Partner'],
    'Hyundai' => ['Accent', 'i10', 'i20', 'Elantra', 'Tucson'],
    'Toyota' => ['Corolla', 'Yaris', 'Avensis', 'Hilux'],
    'Citroën' => ['C-Elysée', 'C3', 'C4', 'Berlingo'],
    'Fiat' => ['Tipo', 'Punto', 'Doblo', '500'],
    'Skoda' => ['Octavia', 'Fabia', 'Rapid'],
    'Volkswagen' => ['Golf', 'Polo', 'Passat', 'Caddy'],
    'Kia' => ['Picanto', 'Rio', 'Cerato']
];

function getVehiclePresets(PDO $pdo, array $defaultPresets): array {
    $raw = get_setting($pdo, 'vehicle_brands_models', '');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }
    }
    // Initialize setting with default presets if empty
    update_setting($pdo, 'vehicle_brands_models', json_encode($defaultPresets, JSON_UNESCAPED_UNICODE), 'fleet');
    return $defaultPresets;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new brand or model
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');

    if (empty($brand)) {
        http_response_code(400);
        echo json_encode(['error' => 'اسم الماركة أو الصانع مطلوب']);
        exit;
    }

    $presets = getVehiclePresets($pdo, $defaultPresets);

    if (!isset($presets[$brand])) {
        $presets[$brand] = [];
    }

    if (!empty($model)) {
        // Support comma-separated models
        $modelsToAdd = array_map('trim', explode(',', $model));
        foreach ($modelsToAdd as $m) {
            if (!empty($m) && !in_array($m, $presets[$brand])) {
                $presets[$brand][] = $m;
            }
        }
    }

    update_setting($pdo, 'vehicle_brands_models', json_encode($presets, JSON_UNESCAPED_UNICODE), 'fleet');
    echo json_encode(['success' => true, 'presets' => $presets, 'selected_brand' => $brand, 'selected_model' => $model], JSON_UNESCAPED_UNICODE);
    exit;
}

// GET request
$presets = getVehiclePresets($pdo, $defaultPresets);
echo json_encode($presets, JSON_UNESCAPED_UNICODE);
