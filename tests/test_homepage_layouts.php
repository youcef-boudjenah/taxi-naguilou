<?php
// tests/test_homepage_layouts.php - Automated Verification Test for Homepage Dynamic Layout Switching

$_SERVER['PHP_SELF'] = '/dashboard/index.php';
$_SERVER['SCRIPT_NAME'] = '/dashboard/index.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'super_admin';
$_SESSION['user_name'] = 'المشرف العام';
$_SESSION['user_wilaya'] = 'أدرار';
$_SESSION['user'] = [
    'id'       => 1,
    'name'     => 'المشرف العام',
    'username' => 'admin',
    'role'     => 'super_admin',
    'wilaya'   => 'أدرار',
    'status'   => 'active',
];

require_once __DIR__ . '/../config/db.php';

ob_start();
$currentPage = 'index';
require __DIR__ . '/../dashboard/index.php';
$html = ob_get_clean();

echo "========================================================\n";
echo "  HOMEPAGE DYNAMIC LAYOUT SWITCHING TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $message\n";
        $passCount++;
    } else {
        echo " [FAIL] $message\n";
        $failCount++;
    }
}

// 1. Output non-empty
assertTest(!empty($html), "Dashboard index renders non-empty HTML output");

// 2. Check Layout Containers in DOM
assertTest(strpos($html, 'id="homeLayoutWrapper"') !== false, "DOM contains root wrapper #homeLayoutWrapper");
assertTest(strpos($html, 'id="layoutViewGrid"') !== false, "DOM contains Grid View container #layoutViewGrid");
assertTest(strpos($html, 'id="layoutViewSidebar"') !== false, "DOM contains Sidebar View container #layoutViewSidebar");
assertTest(strpos($html, 'id="layoutViewList"') !== false, "DOM contains List View container #layoutViewList");

// 3. Check App Bar & Sub-Ribbon Layout Switchers
assertTest(strpos($html, 'data-layout-target="grid"') !== false, "DOM contains Grid layout target button");
assertTest(strpos($html, 'data-layout-target="sidebar"') !== false, "DOM contains Sidebar layout target button");
assertTest(strpos($html, 'data-layout-target="list"') !== false, "DOM contains List layout target button");
assertTest(strpos($html, 'setHomeLayout(\'grid\')') !== false, "DOM contains setHomeLayout('grid') triggers");
assertTest(strpos($html, 'setHomeLayout(\'sidebar\')') !== false, "DOM contains setHomeLayout('sidebar') triggers");
assertTest(strpos($html, 'setHomeLayout(\'list\')') !== false, "DOM contains setHomeLayout('list') triggers");

// 4. Check Content Selection Controls
assertTest(strpos($html, 'id="appBarCategorySelect"') !== false, "App Bar contains #appBarCategorySelect dropdown");
assertTest(strpos($html, 'id="mobileCategorySelect"') !== false, "Sub-Ribbon contains #mobileCategorySelect dropdown");
assertTest(strpos($html, 'id="categoryFilterBar"') !== false, "Sub-Ribbon contains #categoryFilterBar desktop pills");
assertTest(strpos($html, 'data-filter="fleet"') !== false, "Category filter bar contains 'fleet' pill");
assertTest(strpos($html, 'data-filter="drivers"') !== false, "Category filter bar contains 'drivers' pill");
assertTest(strpos($html, 'data-filter="finance"') !== false, "Category filter bar contains 'finance' pill");
assertTest(strpos($html, 'data-filter="system"') !== false, "Category filter bar contains 'system' pill");

// 5. Check Sidebar View Components
assertTest(strpos($html, 'sidebar-nav-item') !== false, "Sidebar View contains .sidebar-nav-item buttons");
assertTest(strpos($html, 'id="sidebarBannerTitle"') !== false, "Sidebar View contains #sidebarBannerTitle banner");
assertTest(strpos($html, 'id="sidebarDrawerPanel"') !== false, "Sidebar View contains mobile drawer panel #sidebarDrawerPanel");
assertTest(strpos($html, 'id="sidebarDrawerBackdrop"') !== false, "Sidebar View contains mobile drawer backdrop #sidebarDrawerBackdrop");

// 6. Check List View Structure
assertTest(strpos($html, 'launcher-row') !== false, "List View contains .launcher-row items");
assertTest(strpos($html, 'الوحدة والمهام التشغيلية') !== false, "List View contains table column header 'الوحدة والمهام التشغيلية'");

// 7. Check Client Engine & Persistence
assertTest(strpos($html, 'naguilou_home_layout') !== false, "Client JavaScript persists to localStorage 'naguilou_home_layout'");
assertTest(strpos($html, 'window.setHomeLayout') !== false, "Client JavaScript defines window.setHomeLayout");
assertTest(strpos($html, 'window.setHomeCategory') !== false, "Client JavaScript defines window.setHomeCategory");
assertTest(strpos($html, 'window.filterHomeModules') !== false, "Client JavaScript defines window.filterHomeModules");
assertTest(strpos($html, 'window.toggleSidebarDrawer') !== false, "Client JavaScript defines window.toggleSidebarDrawer");

// 8. Check Empty State
assertTest(strpos($html, 'id="noResultsState"') !== false, "DOM contains #noResultsState empty state container");
assertTest(strpos($html, 'resetLauncherFilter()') !== false, "DOM contains resetLauncherFilter() button");

// 9. Check Launcher Modules Presence
assertTest(strpos($html, 'سجل المركبات والوثائق') !== false, "Module 'سجل المركبات والوثائق' present in HTML");
assertTest(strpos($html, 'السائقون والبطاقات المهنية') !== false, "Module 'السائقون والبطاقات المهنية' present in HTML");
assertTest(strpos($html, 'عقود العمل (قانون 90-11)') !== false, "Module 'عقود العمل' present in HTML");
assertTest(strpos($html, 'جداول الإرسال (Bordereaux)') !== false, "Module 'جداول الإرسال' present in HTML");
assertTest(strpos($html, 'المناوبة الليلية') !== false, "Module 'المناوبة الليلية' present in HTML");
assertTest(strpos($html, 'الفوترة والاشتراكات') !== false, "Module 'الفوترة والاشتراكات' present in HTML");

// 10. Check Sidebar View Dashboard Statistics Section (First page requirement)
assertTest(strpos($html, 'id="sidebarStatsSection"') !== false, "Sidebar View contains #sidebarStatsSection for default statistics page");
assertTest(strpos($html, 'id="sidebarCardsSection"') !== false, "Sidebar View contains #sidebarCardsSection for category cards");
assertTest(strpos($html, 'جاهزية الأسطول') !== false, "Statistics section contains KPI 'جاهزية الأسطول'");
assertTest(strpos($html, 'السائقون والعقود') !== false, "Statistics section contains KPI 'السائقون والعقود'");
assertTest(strpos($html, 'التحصيل والاسترداد') !== false, "Statistics section contains KPI 'التحصيل والاسترداد'");
assertTest(strpos($html, 'المناوبة وبطاقات الإرسال') !== false, "Statistics section contains KPI 'المناوبة وبطاقات الإرسال'");
assertTest(strpos($html, 'الامتثال للضمان الاجتماعي (CNAS)') !== false, "Statistics section contains deep breakdown 'CNAS'");
assertTest(strpos($html, 'value="stats"') !== false, "Dropdowns contain option value='stats'");
assertTest(strpos($html, 'data-filter="stats"') !== false, "Category filter bar contains 'stats' button");

// 11. Test Taxi Driver View
$_SESSION['user_role'] = 'driver';
$_SESSION['user']['role'] = 'driver';
$user = $_SESSION['user'];
$isTaxiDriver = true;
ob_start();
$currentPage = 'index';
require __DIR__ . '/../dashboard/index.php';
$driverHtml = ob_get_clean();

assertTest(strpos($driverHtml, 'id="sidebarStatsSection"') !== false, "Driver role receives #sidebarStatsSection in Sidebar View");
assertTest(strpos($driverHtml, 'إجمالي الذمة والديون') !== false, "Driver statistics contain 'إجمالي الذمة والديون'");
assertTest(strpos($driverHtml, 'الوثائق والصلاحيات') !== false, "Driver statistics contain 'الوثائق والصلاحيات'");
assertTest(strpos($driverHtml, 'مركبة العمل والمناوبة') !== false, "Driver statistics contain 'مركبة العمل والمناوبة'");

echo "\n--------------------------------------------------------\n";
echo "SUMMARY: Passed: $passCount, Failed: $failCount\n";
echo "--------------------------------------------------------\n";

if ($failCount > 0) {
    exit(1);
}
