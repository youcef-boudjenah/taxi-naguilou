<?php
// tests/test_transmission_slip.php
require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();
$passed = 0;
$failed = 0;

function it($condition, $description) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $description\n";
        $passed++;
    } else {
        echo "  [FAIL] $description\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "   TAXI NAGUILOU - TRANSMISSION SLIP (BORDEREAU) TEST   \n";
echo "========================================================\n\n";

// 1. Test Logo Files
echo "1. Testing Slip Header Logo Files...\n";
$logoPathPng = __DIR__ . '/../uploads/slips/slip_header_logo.png';
$logoPathJpg = __DIR__ . '/../uploads/slips/slip_header_logo.jpg';

it(file_exists($logoPathPng) || file_exists($logoPathJpg), "Logo file exists in uploads/slips/");
if (file_exists($logoPathPng)) {
    $imgSize = @getimagesize($logoPathPng);
    it(!empty($imgSize) && $imgSize[0] >= 800, "Logo has high resolution (width >= 800px, found {$imgSize[0]}px)");
}

// 2. Test slips.php template source code
echo "\n2. Testing slips.php Template & UI Structure...\n";
$slipsContent = file_get_contents(__DIR__ . '/../dashboard/slips.php');

it(strpos($slipsContent, 'name="doc_notes[]"') !== false, "Form includes doc_notes[] inputs for document observations");
it(strpos($slipsContent, 'name="general_note"') !== false, "Form includes general_note input for slip-level notes");
it(strpos($slipsContent, '<th class="p-2 w-32 sm:w-36 text-center">ملاحظات</th>') !== false, "Printable table includes 4th column header: ملاحظات");
it(strpos($slipsContent, 'pvGeneralNoteBox') !== false, "Printable sheet includes optional general note box");
it(strpos($slipsContent, 'h-28 sm:h-36 max-h-44') !== false, "Logo has large responsive dimensions on screen preview");
it(strpos($slipsContent, 'height: 38mm !important;') !== false, "Logo has prominent large dimensions in print CSS (38mm)");

// 3. Test Database Insert & Retrieval of 4-column documents and general_note
echo "\n3. Testing Database CRUD with Notes...\n";
$testSlipNumber = 'TEST-SLIP-' . time();
$testDocs = [
    ['num' => '01', 'title' => 'بطاقة رمادية', 'count' => '01', 'notes' => 'لأجل التجديد'],
    ['num' => '02', 'title' => 'رخصة استغلال', 'count' => '01', 'notes' => 'الأصلية'],
    ['num' => '03', 'title' => 'شهادة الفحص', 'count' => '01', 'notes' => 'صورة طبق الأصل'],
];
$testGeneralNote = 'تسلّم الوثائق باليد لدى مصلحة النقل للمعاينة';

$stmt = $pdo->prepare("
    INSERT INTO transmission_slips 
    (slip_number, recipient, subject, address, agrement, wilaya, route_line, issue_date, valid_until, documents_json, general_note, status)
    VALUES (?, 'مدير النقل لولاية أدرار', 'تجديد رخص', 'أدرار', '02/22', 'أدرار', 'route', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?, 'active')
");
$stmt->execute([$testSlipNumber, json_encode($testDocs, JSON_UNESCAPED_UNICODE), $testGeneralNote]);
$insertedId = (int)$pdo->lastInsertId();

it($insertedId > 0, "Slip created in database with ID $insertedId");

// Retrieve and verify
$stmtFetch = $pdo->prepare("SELECT * FROM transmission_slips WHERE id = ?");
$stmtFetch->execute([$insertedId]);
$slipRow = $stmtFetch->fetch(PDO::FETCH_ASSOC);

it($slipRow['general_note'] === $testGeneralNote, "general_note saved and retrieved correctly");

$decodedDocs = json_decode($slipRow['documents_json'], true);
it(is_array($decodedDocs) && count($decodedDocs) === 3, "All 3 documents saved in documents_json");
it($decodedDocs[0]['notes'] === 'لأجل التجديد', "Document 1 note preserved ('لأجل التجديد')");
it($decodedDocs[1]['notes'] === 'الأصلية', "Document 2 note preserved ('الأصلية')");
it($decodedDocs[2]['notes'] === 'صورة طبق الأصل', "Document 3 note preserved ('صورة طبق الأصل')");

// Cleanup test row
$pdo->prepare("DELETE FROM transmission_slips WHERE id = ?")->execute([$insertedId]);
echo "\nCleaned up synthetic test slip.\n";

echo "========================================================\n";
echo "TEST RESULTS: PASS: $passed | FAIL: $failed\n";
echo "========================================================\n";

if ($failed > 0) exit(1);

