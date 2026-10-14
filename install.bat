@echo off
chcp 65001 >nul
title TAXI-NAGUILOU — One-Click Installer & Setup Wizard
color 0E

cls
echo ======================================================================
echo    🚕  TAXI-NAGUILOU — معالج تثبيت وتهيئة المنصة على ويندوز
echo    EURL TAXI NAGUILOU — Algerian Fleet Management Software
echo ======================================================================
echo.

:: 1. Search for PHP Executable
set "PHP_BIN="
where php >nul 2>nul
if %errorlevel% equ 0 (
    set "PHP_BIN=php"
) else (
    for /d %%D in ("C:\laragon\bin\php\php*") do (
        if exist "%%D\php.exe" (
            set "PHP_BIN=%%D\php.exe"
        )
    )
    if not defined PHP_BIN (
        if exist "C:\xampp\php\php.exe" (
            set "PHP_BIN=C:\xampp\php\php.exe"
        )
    )
)

if not defined PHP_BIN (
    echo [!] تحذير: لم يتم العثور على مفسر PHP في مسار النظام (PATH) أو مجلد Laragon.
    echo     يرجى التأكد من تشغيل Laragon أو إضافة PHP إلى متغيرات البيئة.
    echo.
    pause
    exit /b 1
)

echo [+] تم اكتشاف مفسر PHP بنجاح:
echo     "%PHP_BIN%"
echo.

:: 2. Create Uploads Directories if missing
echo [+] جاري التحقق من مجلدات التخزين والمرفقات (Uploads)...
if not exist "uploads" mkdir "uploads"
if not exist "uploads\cars" mkdir "uploads\cars"
if not exist "uploads\drivers" mkdir "uploads\drivers"
if not exist "uploads\contracts" mkdir "uploads\contracts"
if not exist "uploads\slips" mkdir "uploads\slips"
echo     ✓ مجلدات uploads جاهزة تماماً.
echo.

:: 3. Main Menu
:MENU
echo ----------------------------------------------------------------------
echo  اختر العملية المطلوبة:
echo ----------------------------------------------------------------------
echo  [1] فتح معالج التثبيت التفاعلي في المتصفح (موصى به) -> Web Installer
echo  [2] تثبيت سريع وتلقائي بنقرة واحدة (1-Click Express Local Install)
echo  [3] فحص البيئة وتشغيل حزمة الاختبارات الشاملة (Run Test Suite)
echo  [4] إلغاء قفل التثبيت (حذف ملف install.lock لإعادة التثبيت)
echo  [5] فتح لوحة التحكم مباشرة (Open App in Browser)
echo  [6] خروج (Exit)
echo ----------------------------------------------------------------------
set /p choice="أدخل رقم الخيار [1-6]: "

if "%choice%"=="1" goto WEB_INSTALL
if "%choice%"=="2" goto EXPRESS_INSTALL
if "%choice%"=="3" goto RUN_TESTS
if "%choice%"=="4" goto UNLOCK
if "%choice%"=="5" goto OPEN_APP
if "%choice%"=="6" goto END

echo [!] خيار غير صحيح، يرجى المحاولة ثانية.
goto MENU

:WEB_INSTALL
echo.
echo [+] جاري فتح معالج التثبيت في المتصفح...
start http://localhost/taxi-naguilou/install.php
echo     إذا كنت تستخدم Virtual Host في Laragon:
echo     http://taxi-naguilou.test/install.php
echo.
pause
goto MENU

:EXPRESS_INSTALL
echo.
echo [+] جاري تشغيل التثبيت السريع المحلي...
"%PHP_BIN%" install.php --express --force
echo.
echo [+] تم الانتهاء! جاري فتح شاشة تسجيل الدخول...
start http://localhost/taxi-naguilou/auth/login.php
echo.
pause
goto MENU

:RUN_TESTS
echo.
echo [+] جاري تشغيل حزمة الاختبارات الشاملة...
"%PHP_BIN%" tests/run_tests.php
echo.
pause
goto MENU

:UNLOCK
echo.
if exist "install.lock" (
    del /f /q "install.lock"
    echo [+] تم حذف ملف install.lock بنجاح. يمكنك الآن إعادة التثبيت.
) else (
    echo [i] ملف install.lock غير موجود بالفعل.
)
echo.
pause
goto MENU

:OPEN_APP
echo.
echo [+] جاري فتح لوحة تحكم تاكسي نقيلو...
start http://localhost/taxi-naguilou/
echo.
goto MENU

:END
echo.
echo شكراً لاستخدامك منصة تاكسي نقيلو!
timeout /t 2 >nul
exit /b 0
