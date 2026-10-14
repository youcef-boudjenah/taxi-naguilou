<?php
// api/submit_lead.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مسموح بها']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$fullName = trim($input['full_name'] ?? '');
$phone = trim($input['phone'] ?? '');
$email = trim($input['email'] ?? '');
$roleType = trim($input['role_type'] ?? 'owner');
$wilaya = trim($input['wilaya'] ?? '');
$fleetSize = intval($input['fleet_size'] ?? 1);
$message = trim($input['message'] ?? '');

// Validation
if (empty($fullName) || empty($phone) || empty($wilaya)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'يرجى ملء جميع الحقول المطلوبة (الاسم الكامل، رقم الهاتف، والولاية)'
    ]);
    exit;
}

// Email validation
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'يرجى إدخال بريد إلكتروني صحيح'
    ]);
    exit;
}

// Phone format validation (Algerian numbers: 9 to 10 digits)
$cleanPhone = preg_replace('/[^0-9]/', '', $phone);
if (strlen($cleanPhone) < 9 || strlen($cleanPhone) > 12) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'يرجى إدخال رقم هاتف صالح (مثال: 0550123456)'
    ]);
    exit;
}

$validRoles = ['owner', 'driver', 'agency', 'investor'];
if (!in_array($roleType, $validRoles)) {
    $roleType = 'owner';
}

$pdo = getDBConnection();

if (!$pdo) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر الاتصال بقاعدة البيانات حالياً. يرجى المحاولة لاحقاً.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO leads (full_name, phone, email, role_type, wilaya, fleet_size, message)
        VALUES (:full_name, :phone, :email, :role_type, :wilaya, :fleet_size, :message)
    ");
    $stmt->execute([
        ':full_name' => $fullName,
        ':phone' => $phone,
        ':email' => !empty($email) ? $email : null,
        ':role_type' => $roleType,
        ':wilaya' => $wilaya,
        ':fleet_size' => max(1, $fleetSize),
        ':message' => !empty($message) ? $message : null
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'تم استلام طلبكم بنجاح! سيتواصل معكم فريق تاكسي نقيلو خلال 24 ساعة.',
        'lead_id' => (int)$pdo->lastInsertId()
    ]);
    exit;
} catch (PDOException $e) {
    error_log("Database Insert Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء حفظ الطلب في النظام. يرجى المحاولة مرة أخرى.'
    ]);
    exit;
}
