<?php
// scripts/backup_db.php - Enterprise Automated Database Backup & Retention Engine
// Usage:
//   CLI:  php scripts/backup_db.php
//   HTTP: https://your-domain.com/scripts/backup_db.php?key=YOUR_CRON_SECRET

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../config/db.php';

// 1. Security Check for Web Execution
if (php_sapi_name() !== 'cli') {
    $expectedSecret = getenv('CRON_SECRET') ?: 'naguilou_production_cron_secret_key_change_me';
    $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';

    if (empty($providedKey) || !hash_equals($expectedSecret, (string)$providedKey)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized: Invalid or missing cron security key (key parameter required).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 2. Connect Database
$pdo = getDBConnection();
if (!$pdo) {
    $errorMsg = 'Database connection failed during backup routine.';
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "❌ ERROR: {$errorMsg}\n");
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $errorMsg]);
        exit;
    }
}

$startTime = microtime(true);
$backupDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

$timestamp = date('Y-m-d_H-i-s');
$baseFilename = "backup_taxi_naguilou_{$timestamp}.sql";
$backupFilePath = $backupDir . '/' . $baseFilename;

// 3. Generate SQL Dump
try {
    $fp = fopen($backupFilePath, 'w');
    if (!$fp) {
        throw new Exception("Unable to open backup file for writing: {$backupFilePath}");
    }

    // Write Header
    fwrite($fp, "-- ========================================================\n");
    fwrite($fp, "-- Taxi Naguilou ERP — Automated Database Backup\n");
    fwrite($fp, "-- Generated at: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- PHP Version: " . PHP_VERSION . "\n");
    fwrite($fp, "-- ========================================================\n\n");
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fp, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($fp, "SET NAMES utf8mb4;\n\n");

    // Fetch all tables
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $tableCount = 0;
    $rowCount = 0;

    foreach ($tables as $tRow) {
        $table = $tRow[0];
        $tableCount++;

        // Table Structure
        fwrite($fp, "\n-- Table structure for: `{$table}`\n");
        fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($fp, $createStmt[1] . ";\n\n");

        // Table Rows
        fwrite($fp, "-- Data dump for: `{$table}`\n");
        $rowsStmt = $pdo->query("SELECT * FROM `{$table}`");
        $batch = [];
        $columns = null;

        while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
            $rowCount++;
            if ($columns === null) {
                $columns = array_map(fn($c) => "`{$c}`", array_keys($row));
            }

            $escapedValues = array_map(function($val) use ($pdo) {
                if ($val === null) return 'NULL';
                return $pdo->quote($val);
            }, array_values($row));

            $batch[] = "(" . implode(", ", $escapedValues) . ")";

            if (count($batch) >= 100) {
                fwrite($fp, "INSERT INTO `{$table}` (" . implode(", ", $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }

        if (!empty($batch) && $columns !== null) {
            fwrite($fp, "INSERT INTO `{$table}` (" . implode(", ", $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
        }
    }

    // Write Footer
    fwrite($fp, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fwrite($fp, "-- End of Backup\n");
    fclose($fp);

    // Optional Compression
    $finalFilePath = $backupFilePath;
    if (function_exists('gzencode') && filesize($backupFilePath) > 0) {
        $gzPath = $backupFilePath . '.gz';
        $gzData = gzencode(file_get_contents($backupFilePath), 9);
        if ($gzData !== false) {
            file_put_contents($gzPath, $gzData);
            unlink($backupFilePath);
            $finalFilePath = $gzPath;
        }
    }

    $fileSizeMb = round(filesize($finalFilePath) / (1024 * 1024), 2);
    $durationSec = round(microtime(true) - $startTime, 2);

    // 4. Retention Policy: Prune Backups older than BACKUP_RETENTION_DAYS (default 30)
    $retentionDays = (int)(getenv('BACKUP_RETENTION_DAYS') ?: 30);
    $pruneBefore = time() - ($retentionDays * 86400);
    $prunedCount = 0;

    $existingBackups = glob($backupDir . '/backup_taxi_naguilou_*');
    if ($existingBackups) {
        foreach ($existingBackups as $file) {
            if (is_file($file) && filemtime($file) < $pruneBefore) {
                @unlink($file);
                $prunedCount++;
            }
        }
    }

    // 5. Log activity
    $logLine = sprintf(
        "[%s] SUCCESS: Backup created: %s (Size: %s MB, Tables: %d, Rows: %d, Duration: %ss, Pruned: %d)\n",
        date('Y-m-d H:i:s'),
        basename($finalFilePath),
        $fileSizeMb,
        $tableCount,
        $rowCount,
        $durationSec,
        $prunedCount
    );
    @file_put_contents(__DIR__ . '/../storage/logs/backups.log', $logLine, FILE_APPEND);

    // Response
    $result = [
        'success'      => true,
        'message'      => 'Database backup completed successfully',
        'file'         => basename($finalFilePath),
        'size_mb'      => $fileSizeMb,
        'tables_count' => $tableCount,
        'rows_count'   => $rowCount,
        'duration_sec' => $durationSec,
        'pruned_count' => $prunedCount,
    ];

    if (php_sapi_name() === 'cli') {
        echo "✅ SUCCESS: Database backup complete!\n";
        echo "   File:     " . basename($finalFilePath) . "\n";
        echo "   Size:     {$fileSizeMb} MB\n";
        echo "   Tables:   {$tableCount}\n";
        echo "   Rows:     {$rowCount}\n";
        echo "   Duration: {$durationSec}s\n";
        if ($prunedCount > 0) {
            echo "   Pruned:   {$prunedCount} old backup(s)\n";
        }
    } else {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    $err = "Backup failed: " . $e->getMessage();
    @file_put_contents(__DIR__ . '/../storage/logs/backups.log', "[" . date('Y-m-d H:i:s') . "] ERROR: {$err}\n", FILE_APPEND);

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "❌ ERROR: {$err}\n");
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
}
