<?php
// api/health.php - Production Health Check & Monitoring Endpoint
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/db.php';

$startTime = microtime(true);
$checks = [];
$isHealthy = true;

// 1. Database Health Check
$dbStart = microtime(true);
$pdo = getDBConnection();
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT 1");
        $dbLatencyMs = round((microtime(true) - $dbStart) * 1000, 2);
        $checks['database'] = [
            'status'     => 'up',
            'latency_ms' => $dbLatencyMs,
            'driver'     => 'mysql',
        ];
    } catch (\Throwable $e) {
        $isHealthy = false;
        $checks['database'] = [
            'status'  => 'down',
            'message' => 'Database query failed'
        ];
    }
} else {
    $isHealthy = false;
    $checks['database'] = [
        'status'  => 'down',
        'message' => 'Database connection failed'
    ];
}

// 2. Storage & Directory Permissions
$uploadsDir = __DIR__ . '/../uploads';
$logsDir    = __DIR__ . '/../storage/logs';
$backupsDir = __DIR__ . '/../storage/backups';

$uploadsWritable = is_writable($uploadsDir);
$logsWritable    = is_writable($logsDir);
$backupsWritable = is_writable($backupsDir);

$storageHealthy = $uploadsWritable && $logsWritable && $backupsWritable;
if (!$storageHealthy) {
    $isHealthy = false;
}

$freeBytes = @disk_free_space(__DIR__);
$freeGb = $freeBytes !== false ? round($freeBytes / (1024 * 1024 * 1024), 2) : null;

$checks['storage'] = [
    'status'           => $storageHealthy ? 'healthy' : 'degraded',
    'uploads_writable' => $uploadsWritable,
    'logs_writable'    => $logsWritable,
    'backups_writable' => $backupsWritable,
    'free_disk_gb'     => $freeGb,
];

// 3. PHP Runtime & Extensions
$requiredExts = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json'];
$extStatus = [];
foreach ($requiredExts as $ext) {
    $loaded = extension_loaded($ext);
    $extStatus[$ext] = $loaded;
    if (!$loaded) {
        $isHealthy = false;
    }
}

$checks['php'] = [
    'version'         => PHP_VERSION,
    'memory_limit'    => ini_get('memory_limit'),
    'opcache_enabled' => function_exists('opcache_get_status') && !empty(opcache_get_status()['opcache_enabled']),
    'extensions'      => $extStatus,
];

// Response payload
$totalLatencyMs = round((microtime(true) - $startTime) * 1000, 2);
$response = [
    'status'           => $isHealthy ? 'healthy' : 'unhealthy',
    'timestamp'        => date('c'),
    'environment'      => defined('APP_ENV') ? APP_ENV : 'unknown',
    'response_time_ms' => $totalLatencyMs,
    'checks'           => $checks,
];

http_response_code($isHealthy ? 200 : 503);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!defined('TEST_MODE')) {
    exit;
}
