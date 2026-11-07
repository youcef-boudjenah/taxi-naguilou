<?php
// scripts/ftp_deploy.php - Automated FTP Deployment Tool for Taxi Naguilou
// Usage:
//   php scripts/ftp_deploy.php --user="USER" --pass="PASS" [--host="HOST"] [--dir="DIR"]
// Or set FTP_HOST, FTP_USER, FTP_PASS, FTP_DIR in .env

$root = dirname(__DIR__);

// Load .env if present
$envFile = $root . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            putenv(trim($k) . '=' . trim($v, " \t\n\r\0\x0B\"'"));
        }
    }
}

// Parse CLI options
$options = getopt('', ['host::', 'user::', 'pass::', 'dir::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/ftp_deploy.php --user=USERNAME --pass=PASSWORD [--host=HOSTNAME] [--dir=REMOTE_DIR]\n";
    echo "Example: php scripts/ftp_deploy.php --user=myuser --pass=mypass --dir=public_html\n";
    exit(0);
}

$host = $options['host'] ?? getenv('FTP_HOST') ?: 'website-9715b8e7.mvn.mwg.mybluehost.me';
$user = $options['user'] ?? getenv('FTP_USER') ?: '';
$pass = $options['pass'] ?? getenv('FTP_PASS') ?: '';
$remoteDir = trim($options['dir'] ?? getenv('FTP_DIR') ?: 'website_9715b8e7', '/');

if (empty($user) || empty($pass)) {
    echo "❌ ERROR: FTP User and Password are required.\n";
    echo "Run with: php scripts/ftp_deploy.php --user=\"YOUR_USER\" --pass=\"YOUR_PASS\"\n";
    exit(1);
}

// Files to deploy
$customFiles = $options['files'] ?? null;
if ($customFiles) {
    $filesToDeploy = array_map('trim', explode(',', $customFiles));
} else {
    $filesToDeploy = [
        'config/db.php',
        'includes/header.php',
        'dashboard/index.php',
        'dashboard/notifications.php',
        'dashboard/debts.php',
        'dashboard/leaves.php',
        'dashboard/users.php',
        'includes/backup_importer.php',
        'tests/run_all_suites.php',
        'tests/test_notifications_isolation.php'
    ];
}

echo "========================================================\n";
echo "   TAXI NAGUILOU — AUTOMATED FTP DEPLOYMENT TOOL       \n";
echo "========================================================\n";
echo "Host:       $host:21\n";
echo "User:       $user\n";
echo "Remote Dir: $remoteDir/\n";
echo "Files:      " . count($filesToDeploy) . "\n\n";

$allSuccess = true;
foreach ($filesToDeploy as $relPath) {
    $localFile = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    if (!file_exists($localFile)) {
        echo "⚠️ Skipping missing local file: $relPath\n";
        continue;
    }

    $targetUrl = "ftp://" . $host . "/" . $remoteDir . "/" . $relPath;
    echo "Uploading: $relPath ... ";

    $outputLines = [];
    $authArg = escapeshellarg($user . ":" . $pass);
    $curlCmd = "curl.exe -s -S --connect-timeout 20 --ftp-create-dirs --user $authArg -T " . escapeshellarg($localFile) . " " . escapeshellarg($targetUrl) . " 2>&1";
    exec($curlCmd, $outputLines, $exitCode);

    if ($exitCode === 0) {
        echo "✅ SUCCESS\n";
    } else {
        // Try without remoteDir prefix if user is already chrooted to root
        $fallbackUrl = "ftp://" . $host . "/" . $relPath;
        $fallbackOutput = [];
        $fallbackCmd = "curl.exe -s -S --connect-timeout 20 --ftp-create-dirs --user $authArg -T " . escapeshellarg($localFile) . " " . escapeshellarg($fallbackUrl) . " 2>&1";
        exec($fallbackCmd, $fallbackOutput, $fallbackExit);

        if ($fallbackExit === 0) {
            echo "✅ SUCCESS (chrooted root)\n";
        } else {
            echo "❌ FAILED (Code $exitCode)\n";
            echo "   Error: " . implode(" ", $outputLines) . "\n";
            $allSuccess = false;
        }
    }
}

echo "========================================================\n";
if ($allSuccess) {
    echo "🎉 ALL MODIFICATIONS UPLOADED SUCCESSFULLY VIA FTP!\n";
    exit(0);
} else {
    echo "⚠️ UPLOAD COMPLETED WITH ERRORS. Check credentials.\n";
    exit(2);
}
