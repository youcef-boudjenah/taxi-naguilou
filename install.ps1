# TAXI-NAGUILOU — PowerShell Automated Setup Script
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

Write-Host "======================================================================" -ForegroundColor Yellow
Write-Host "   🚕  TAXI-NAGUILOU — معالج تثبيت وتهيئة المنصة عبر PowerShell" -ForegroundColor Yellow
Write-Host "   EURL TAXI NAGUILOU — Algerian Fleet Management Software" -ForegroundColor DarkGray
Write-Host "======================================================================" -ForegroundColor Yellow
Write-Host ""

# 1. Discover PHP
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
$phpPath = $null

if ($phpCmd) {
    $phpPath = $phpCmd.Source
} else {
    $laragonPhps = Get-ChildItem -Path "C:\laragon\bin\php\php*" -ErrorAction SilentlyContinue | Where-Object { Test-Path "$($_.FullName)\php.exe" }
    if ($laragonPhps) {
        $phpPath = "$($laragonPhps[0].FullName)\php.exe"
    } elseif (Test-Path "C:\xampp\php\php.exe") {
        $phpPath = "C:\xampp\php\php.exe"
    }
}

if (-not $phpPath) {
    Write-Host "[!] خطأ: تعذر العثور على مفسر PHP. يرجى تشغيل Laragon أو تثبيت PHP." -ForegroundColor Red
    exit 1
}

Write-Host "[+] تم اكتشاف مفسر PHP:" -ForegroundColor Green
Write-Host "    $phpPath" -ForegroundColor Cyan
Write-Host ""

# 2. Ensure Uploads Directories
Write-Host "[+] فحص وتجهيز مجلدات التخزين والمرفقات..." -ForegroundColor Green
$folders = @("uploads", "uploads/cars", "uploads/drivers", "uploads/contracts", "uploads/slips")
foreach ($f in $folders) {
    $targetPath = Join-Path $PSScriptRoot $f
    if (-not (Test-Path $targetPath)) {
        New-Item -ItemType Directory -Path $targetPath -Force | Out-Null
    }
}
Write-Host "    ✓ مجلدات uploads جاهزة تماماً." -ForegroundColor Green
Write-Host ""

# 3. Execution Menu
Write-Host "----------------------------------------------------------------------" -ForegroundColor Gray
Write-Host " [1] فتح معالج التثبيت في المتصفح (Web Installer)" -ForegroundColor White
Write-Host " [2] تثبيت سريع وتلقائي بنقرة واحدة (1-Click Express Local Install)" -ForegroundColor White
Write-Host " [3] تشغيل حزمة الاختبارات الشاملة (Run Test Suite)" -ForegroundColor White
Write-Host " [4] إعادة ضبط وحذف ملف القفل (Unlock Installation)" -ForegroundColor White
Write-Host " [5] خروج (Exit)" -ForegroundColor White
Write-Host "----------------------------------------------------------------------" -ForegroundColor Gray

$choice = Read-Host "أدخل رقم الخيار [1-5]"

switch ($choice) {
    "1" {
        Write-Host "جاري فتح معالج التثبيت في المتصفح..." -ForegroundColor Cyan
        Start-Process "http://localhost/taxi-naguilou/install.php"
    }
    "2" {
        Write-Host "جاري تشغيل التثبيت السريع عبر PHP..." -ForegroundColor Cyan
        & "$phpPath" "$PSScriptRoot\install.php" --express --force
        Write-Host "تم التثبيت بنجاح! جاري فتح تسجيل الدخول..." -ForegroundColor Green
        Start-Process "http://localhost/taxi-naguilou/auth/login.php"
    }
    "3" {
        Write-Host "جاري تشغيل الاختبارات..." -ForegroundColor Cyan
        & "$phpPath" "$PSScriptRoot\tests\run_tests.php"
    }
    "4" {
        $lockPath = Join-Path $PSScriptRoot "install.lock"
        if (Test-Path $lockPath) {
            Remove-Item $lockPath -Force
            Write-Host "[+] تم حذف ملف install.lock بنجاح." -ForegroundColor Green
        } else {
            Write-Host "[i] ملف install.lock غير موجود مسبقاً." -ForegroundColor Yellow
        }
    }
    Default {
        Write-Host "تم الإنهاء." -ForegroundColor Gray
    }
}
