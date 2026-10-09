<?php
// dashboard/vehicles.php - Full Fleet & Vehicle Management
$pageTitle = 'سجل المركبات والوثائق';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();
$drivers = [];
if ($pdo) {
    $drivers = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' AND status = 'active'")->fetchAll();
}

// Handle Add / Edit Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $actionType = $_POST['action_type'];
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $color = trim($_POST['color'] ?? 'أصفر');
    $doorNumber = trim($_POST['door_number'] ?? '');
    $driverId = !empty($_POST['current_driver_id']) ? intval($_POST['current_driver_id']) : null;
    $insuranceExpiry = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $insuranceCompany = trim($_POST['insurance_company'] ?? 'SAA');
    $tcExpiry = !empty($_POST['technical_control_expiry']) ? $_POST['technical_control_expiry'] : null;
    $extExpiry = !empty($_POST['extinguisher_expiry']) ? $_POST['extinguisher_expiry'] : null;
    $gplExpiry = !empty($_POST['gpl_expiry']) ? $_POST['gpl_expiry'] : null;
    $sacExpiry = !empty($_POST['saccuriometre_expiry']) ? $_POST['saccuriometre_expiry'] : null;
    $sacText = trim($_POST['saccuriometre_text'] ?? 'معاير ومختوم');

    if ($actionType === 'create') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO vehicles (owner_id, current_driver_id, brand, model, plate_number, color, door_number, insurance_expiry, insurance_company, technical_control_expiry, extinguisher_expiry, gpl_expiry, saccuriometre_expiry, saccuriometre_text, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $user['id'], $driverId, $brand, $model, $plateNumber, $color, $doorNumber,
                $insuranceExpiry, $insuranceCompany, $tcExpiry, $extExpiry, $gplExpiry, $sacExpiry, $sacText
            ]);
            set_flash('success', 'تمت إضافة سيارة الأجرة بنجاح إلى الأسطول');
            header('Location: /dashboard/vehicles.php');
            exit;
        } catch (Exception $e) {
            set_flash('error', 'حدث خطأ أثناء الإضافة: رقم اللوحة قد يكون مسجلاً مسبقاً');
        }
    } elseif ($actionType === 'update') {
        $vId = intval($_POST['vehicle_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                UPDATE vehicles SET brand=?, model=?, plate_number=?, color=?, door_number=?, current_driver_id=?,
                       insurance_expiry=?, insurance_company=?, technical_control_expiry=?, extinguisher_expiry=?,
                       gpl_expiry=?, saccuriometre_expiry=?, saccuriometre_text=?
                WHERE id=?
            ");
            $stmt->execute([
                $brand, $model, $plateNumber, $color, $doorNumber, $driverId,
                $insuranceExpiry, $insuranceCompany, $tcExpiry, $extExpiry, $gplExpiry, $sacExpiry, $sacText, $vId
            ]);
            set_flash('success', 'تم تحديث بيانات المركبة والوثائق بنجاح');
            header('Location: /dashboard/vehicles.php');
            exit;
        } catch (Exception $e) {
            set_flash('error', 'تعذر تحديث المركبة');
        }
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    try {
        $pdo->prepare("DELETE FROM vehicles WHERE id=?")->execute([$delId]);
        set_flash('success', 'تم حذف المركبة بنجاح');
    } catch (Exception $e) {
        set_flash('error', 'تعذر حذف المركبة لوجود عقود أو بيانات مرتبطة بها');
    }
    header('Location: /dashboard/vehicles.php');
    exit;
}

// Fetch vehicles
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$query = "SELECT v.*, u.full_name as driver_name, u.phone as driver_phone FROM vehicles v LEFT JOIN users u ON v.current_driver_id = u.id WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (v.plate_number LIKE ? OR v.brand LIKE ? OR v.model LIKE ? OR u.full_name LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

if ($filter === 'expiring') {
    $query .= " AND (v.technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) OR v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))";
}

$query .= " ORDER BY v.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

// Vehicle to edit if edit param present
$editVehicle = null;
if (isset($_GET['edit'])) {
    $eId = intval($_GET['edit']);
    $stmtE = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmtE->execute([$eId]);
    $editVehicle = $stmtE->fetch();
}
?>

<div class="space-y-6">
    
    <!-- Header with Search & Add Trigger -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">سجل سيارات الأجرة (<?= count($vehicles) ?>)</h2>
            <p class="text-xs text-slate-500">متابعة الفحص التقني، التأمين، شهادة سيرغاز GPL والساكوريومتر</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('addVehicleModal').classList.toggle('hidden')" 
                    class="px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                <span>➕</span>
                <span>إضافة سيارة جديدة</span>
            </button>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ابحث برقم اللوحة، الماركة، أو اسم السائق..."
                   class="w-full sm:max-w-xs px-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:border-amber-500">
            <button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-900 text-white font-bold">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="/dashboard/vehicles.php" class="text-slate-400 hover:text-slate-600 underline">إلغاء التصفية</a>
            <?php endif; ?>
        </form>

        <div class="flex items-center gap-2 w-full sm:w-auto">
            <span class="text-slate-400 font-semibold">عرض:</span>
            <a href="/dashboard/vehicles.php?filter=all" class="px-2.5 py-1 rounded-lg border <?= $filter === 'all' ? 'bg-amber-500 text-slate-950 font-bold border-amber-500' : 'text-slate-600 border-slate-200' ?>">الكل</a>
            <a href="/dashboard/vehicles.php?filter=expiring" class="px-2.5 py-1 rounded-lg border <?= $filter === 'expiring' ? 'bg-rose-500 text-white font-bold border-rose-500' : 'text-rose-600 border-rose-200' ?>">وثائق تنتهي قريباً</a>
        </div>
    </div>

    <!-- Vehicles Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-extrabold border-b border-slate-100">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap">المركبة ولوحة الترقيم</th>
                        <th class="p-3.5 whitespace-nowrap">رقم الباب واللون</th>
                        <th class="p-3.5 whitespace-nowrap">السائق المكلف</th>
                        <th class="p-3.5 whitespace-nowrap">المراقبة التقنية</th>
                        <th class="p-3.5 whitespace-nowrap">التأمين الشامل</th>
                        <th class="p-3.5 whitespace-nowrap">فحص سيرغاز</th>
                        <th class="p-3.5 whitespace-nowrap">عداد التاكسي</th>
                        <th class="p-3.5 text-center whitespace-nowrap">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($vehicles)): ?>
                        <?php foreach ($vehicles as $v): ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-extrabold text-slate-900"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?></div>
                                    <div class="text-[11px] text-amber-700 font-num font-bold"><?= htmlspecialchars($v['plate_number']) ?></div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="text-slate-800">باب: <span class="font-num font-bold"><?= htmlspecialchars($v['door_number']) ?></span></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars($v['color']) ?></div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($v['driver_name'])): ?>
                                        <div class="font-bold text-slate-800"><?= htmlspecialchars($v['driver_name']) ?></div>
                                        <div class="text-[11px] text-slate-400 font-num"><?= htmlspecialchars($v['driver_phone']) ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic">شاغر (بدون سائق)</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <?php 
                                        $tcDiff = !empty($v['technical_control_expiry']) ? (strtotime($v['technical_control_expiry']) - time()) / 86400 : 999;
                                    ?>
                                    <?php if ($tcDiff < 15): ?>
                                        <span class="px-2 py-0.5 rounded bg-rose-50 text-rose-700 font-bold border border-rose-200">
                                            ⚠️ <?= htmlspecialchars($v['technical_control_expiry']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="font-num text-slate-700"><?= htmlspecialchars($v['technical_control_expiry'] ?? '—') ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num text-slate-800 font-bold"><?= htmlspecialchars($v['insurance_expiry'] ?? '—') ?></div>
                                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($v['insurance_company']) ?></div>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num text-slate-700"><?= htmlspecialchars($v['gpl_expiry'] ?? 'مطابق') ?></div>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[11px] font-semibold">
                                        <?= htmlspecialchars($v['saccuriometre_text']) ?>
                                    </span>
                                </td>

                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="/dashboard/vehicles.php?edit=<?= $v['id'] ?>" class="px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition">
                                            تعديل
                                        </a>
                                        <a href="/dashboard/contracts.php?action=new&vehicle_id=<?= $v['id'] ?>" class="px-2.5 py-1 rounded bg-amber-50 hover:bg-amber-100 text-amber-800 font-bold transition">
                                            عقد 90-11
                                        </a>
                                        <a href="/dashboard/vehicles.php?delete=<?= $v['id'] ?>" onclick="return confirm('هل أنت متأكد من حذف هذه المركبة؟')" class="px-2 py-1 rounded text-rose-600 hover:bg-rose-50 font-bold transition">
                                            حذف
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-8 text-center text-slate-400 text-xs">لا توجد مركبات مطابقة لمعايير البحث.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Add / Edit Vehicle -->
<div id="addVehicleModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm <?= $editVehicle ? 'flex' : 'hidden' ?> items-center justify-center p-4">
    <div class="bg-white max-w-2xl w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl max-h-[92vh] overflow-y-auto space-y-5 text-right">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-extrabold text-slate-900 text-lg">
                    <?= $editVehicle ? 'تعديل بيانات سيارة الأجرة والوثائق' : 'إضافة سيارة أجرة جديدة إلى الأسطول' ?>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">أدخل بيانات المركبة وتواريخ صلاحية المراقبة التقنية والتأمين والعداد</p>
            </div>
            <a href="/dashboard/vehicles.php" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center font-bold text-sm transition">✕</a>
        </div>

        <form method="POST" action="/dashboard/vehicles.php" class="space-y-4 text-xs">
            <input type="hidden" name="action_type" value="<?= $editVehicle ? 'update' : 'create' ?>">
            <?php if ($editVehicle): ?>
                <input type="hidden" name="vehicle_id" value="<?= $editVehicle['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">
                        الماركة أو الصانع <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="brand" required placeholder="مثال: رينو، داسيا، بيجو" value="<?= htmlspecialchars($editVehicle['brand'] ?? '') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">
                        طراز المركبة (الموديل) <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="model" required placeholder="مثال: سامبول، لوغان، 301" value="<?= htmlspecialchars($editVehicle['model'] ?? '') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">
                        رقم لوحة الترقيم <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="plate_number" required placeholder="01458-121-16" dir="ltr" value="<?= htmlspecialchars($editVehicle['plate_number'] ?? '') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-right font-num transition">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">
                        رقم الباب المعتمد <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="door_number" required placeholder="مثال: 16-042" value="<?= htmlspecialchars($editVehicle['door_number'] ?? '') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">لون السيارة</label>
                    <input type="text" name="color" value="<?= htmlspecialchars($editVehicle['color'] ?? 'أصفر') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تعيين السائق الأساسي</label>
                    <select name="current_driver_id" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white transition">
                        <option value="">-- بدون سائق حالياً --</option>
                        <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ($editVehicle && $editVehicle['current_driver_id'] == $d['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Documents & Regulatory Expiry Dates -->
            <div class="border-t border-slate-100 pt-4 space-y-3">
                <span class="block font-black text-slate-800 text-sm">تواريخ الصلاحية والوثائق التنظيمية:</span>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء المراقبة التقنية</label>
                        <input type="date" name="technical_control_expiry" value="<?= htmlspecialchars($editVehicle['technical_control_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white font-num transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء التأمين الشامل</label>
                        <input type="date" name="insurance_expiry" value="<?= htmlspecialchars($editVehicle['insurance_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white font-num transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">شركة التأمين</label>
                        <select name="insurance_company" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white transition">
                            <option value="SAA" <?= ($editVehicle && $editVehicle['insurance_company'] === 'SAA') ? 'selected' : '' ?>>SAA (الوطنية)</option>
                            <option value="CAAT" <?= ($editVehicle && $editVehicle['insurance_company'] === 'CAAT') ? 'selected' : '' ?>>CAAT</option>
                            <option value="CIAR" <?= ($editVehicle && $editVehicle['insurance_company'] === 'CIAR') ? 'selected' : '' ?>>CIAR</option>
                            <option value="CASH" <?= ($editVehicle && $editVehicle['insurance_company'] === 'CASH') ? 'selected' : '' ?>>CASH</option>
                            <option value="Alliance" <?= ($editVehicle && $editVehicle['insurance_company'] === 'Alliance') ? 'selected' : '' ?>>Alliance</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">صلاحية فحص خزان سيرغاز</label>
                        <input type="date" name="gpl_expiry" value="<?= htmlspecialchars($editVehicle['gpl_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white font-num transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">صلاحية قارورة الإطفاء</label>
                        <input type="date" name="extinguisher_expiry" value="<?= htmlspecialchars($editVehicle['extinguisher_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white font-num transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">صلاحية فحص عداد التاكسي (الساكوريومتر)</label>
                        <input type="date" name="saccuriometre_expiry" value="<?= htmlspecialchars($editVehicle['saccuriometre_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white font-num transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">وضعية ختم العداد</label>
                        <input type="text" name="saccuriometre_text" value="<?= htmlspecialchars($editVehicle['saccuriometre_text'] ?? 'معاير ومختوم') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-5 border-t border-slate-100">
                <a href="/dashboard/vehicles.php" class="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold transition shadow-sm">
                    <?= $editVehicle ? 'حفظ التعديلات' : 'إضافة المركبة إلى الأسطول' ?>
                </button>
            </div>
        </form>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
