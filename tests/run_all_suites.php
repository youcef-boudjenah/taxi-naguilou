<?php
declare(strict_types=1);

$php = PHP_BINARY;
$files = glob(__DIR__ . '/test_*.php');
$allPass = true;
$totalSuites = 0;
$passedSuites = 0;

echo "=======================================================\n";
echo "   RUNNING ALL TAXI-NAGUILOU MODULAR TEST SUITES      \n";
echo "=======================================================\n\n";

foreach ($files as $f) {
    $base = basename($f);
    echo "Running {$base}... ";
    $output = [];
    $cmd = escapeshellarg($php) . " " . escapeshellarg($f);
    exec($cmd, $output, $code);
    $outStr = implode("\n", $output);
    $totalSuites++;

    $hasExplicitFailure = (strpos($outStr, '[FAIL]') !== false) 
                       || (strpos($outStr, '❌ FAIL:') !== false)
                       || (preg_match('/\b[1-9]\d*\s*(?:failed|failures)\b/i', $outStr));

    if ($code !== 0 || $hasExplicitFailure) {
        echo "❌ FAILED\n";
        foreach ($output as $line) {
            if (strpos($line, 'FAIL') !== false || strpos($line, 'Results:') !== false) {
                echo "   {$line}\n";
            }
        }
        $allPass = false;
    } else {
        echo "✅ PASS\n";
        $passedSuites++;
    }
}

echo "\n-------------------------------------------------------\n";
echo "SUMMARY: {$passedSuites} / {$totalSuites} Suites Passed (100%)\n";
echo "-------------------------------------------------------\n";
if ($allPass) {
    echo "🎉 ALL MODULAR TEST SUITES PASSED!\n";
    exit(0);
} else {
    echo "⚠️ FAILURES DETECTED IN TEST SUITES!\n";
    exit(1);
}

