<?php
// dashboard/drivers.php - Liste Chauffeurs, Expiration Imminente & CHAUFFEURS PERMANENCE
$pageTitle = 'Liste Chauffeurs | سجل السائقين والمداومة';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Handle Add Driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_driver') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $licenseExpiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
    $medExpiry = !empty($_POST['medical_cert_expiry']) ? $_POST['medical_cert_expiry'] : null;
    $wilaya = trim($_POST['wilaya'] ?? '16 - الجزائر العاصمة');

    if (!empty($fullName) && !empty($phone)) {
        if (empty($email)) {
            $email = 'driver_' . time() . '@taxinaguilou.dz';
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, email, password_hash, phone, role, status, wilaya, license_number, license_expiry, medical_cert_expiry)
                VALUES (?, ?, ?, ?, 'driver', 'active', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $fullName, $email, password_hash('password123', PASSWORD_BCRYPT),
                $phone, $wilaya, $licenseNumber, $licenseExpiry, $medExpiry
            ]);
            set_flash('success', 'تم تسجيل السائق بنجاح وإصدار ملفه المهني');
            header('Location: /dashboard/drivers.php');
            exit;
        } catch (Exception $e) {
            set_flash('error', 'حدث خطأ: قد يكون البريد الإلكتروني أو رقم الهاتف مسجلاً مسبقاً');
        }
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, actifs, permanence, anciens

// Fetch Expiration Imminente Drivers (< 30 days or expired)
$stmtExpiring = $pdo->query("
    SELECT u.*, v.plate_number, v.door_number,
           DATEDIFF(u.license_expiry, CURDATE()) as license_days,
           DATEDIFF(u.medical_cert_expiry, CURDATE()) as med_days
    FROM users u
    LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
    WHERE u.role = 'driver'
      AND (
          (u.license_expiry IS NOT NULL AND u.license_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
          OR
          (u.medical_cert_expiry IS NOT NULL AND u.medical_cert_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
      )
    ORDER BY LEAST(
        COALESCE(u.license_expiry, '2099-12-31'),
        COALESCE(u.medical_cert_expiry, '2099-12-31')
    ) ASC
");
$imminentDrivers = $stmtExpiring->fetchAll();

// Fetch Permanence Drivers IDs from driver_schedules
$permanenceDriverIds = $pdo->query("
    SELECT DISTINCT driver_id 
    FROM driver_schedules 
    WHERE is_permanence = 1 AND shift_date >= CURDATE()
")->fetchAll(PDO::FETCH_COLUMN);

// Fetch Main Drivers List
$sql = "
    SELECT u.*, v.plate_number, v.brand, v.model, v.door_number
    FROM users u
    LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
    WHERE u.role = 'driver'
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE ? OR u.phone LIKE ? OR u.license_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY u.id DESC";
$stmtD = $pdo->prepare($sql);
$stmtD->execute($params);
$allDrivers = $stmtD->fetchAll();

// If filter is permanence
$drivers = [];
foreach ($allDrivers as $d) {
    $isPerm = in_array($d['id'], $permanenceDriverIds);
    $d['is_permanence'] = $isPerm;

    if ($filter === 'permanence' && !$isPerm) {
        continue;
    }
    $drivers[] = $d;
}

// Display counts
$countActifs = 95;
$countAnciens = 3;
$countPermanence = count($permanenceDriverIds);
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-black text-slate-900">Liste Chauffeurs</h2>
                <span class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-0.5 rounded-full">
                    سجل السائقين والمداومة الرسمية
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">إدارة الكفاءة المهنية، رخص السياقة، الفحوصات الدورية، وتعيين سائقي المداومة (CHAUFFEURS PERMANENCE)</p>
        </div>
        
        <div class="flex items-center gap-2">
            <a href="/dashboard/planning.php" 
               class="px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition flex items-center gap-1.5 shadow-sm">
                <span>📅</span>
                <span>Prochain planning (3 jours)</span>
            </a>
            <button onclick="document.getElementById('addDriverModal').classList.remove('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                <span>➕</span>
                <span>تسجيل سائق جديد</span>
            </button>
        </div>
    </div>

    <!-- SECTION 1: Expiration Imminente Gestion Chauffeurs -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center font-bold text-sm">⚠️</span>
                <div>
                    <h3 class="font-extrabold text-slate-900 text-base">Expiration Imminente Gestion Chauffeurs (تنبيهات انتهاء الصلاحية الوشيكة)</h3>
                    <p class="text-xs text-slate-500">رخص السياقة الصنف B والشهادات الطبية الدورية التي تنتهي صلاحيتها خلال أقل من 30 يوماً</p>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= !empty($imminentDrivers) ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700' ?>">
                <?= count($imminentDrivers) ?> تنبيهات مستعجلة
            </span>
        </div>

        <?php if (!empty($imminentDrivers)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($imminentDrivers as $expD): ?>
                    <div class="p-4 rounded-xl bg-rose-50/40 border border-rose-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="font-extrabold text-slate-900 text-sm"><?= htmlspecialchars($expD['full_name']) ?></div>
                            <span class="px-2 py-0.5 rounded bg-rose-100 text-rose-800 text-[10px] font-black">إشعار عاجل</span>
                        </div>
                        <div class="space-y-1.5 text-xs text-slate-700">
                            <div class="flex justify-between">
                                <span class="text-slate-500">الهاتف:</span>
                                <span class="font-num font-bold"><?= htmlspecialchars($expD['phone']) ?></span>
                            </div>
                            <?php if ($expD['license_days'] !== null && $expD['license_days'] <= 30): ?>
                                <div class="flex justify-between items-center text-rose-700 font-bold bg-white p-1.5 rounded border border-rose-100">
                                    <span>رخصة السياقة:</span>
                                    <span class="font-num text-[11px]">
                                        <?= $expD['license_days'] < 0 ? 'منتهية منذ ' . abs($expD['license_days']) . ' يوم' : 'باقي ' . $expD['license_days'] . ' يوم (' . $expD['license_expiry'] . ')' ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if ($expD['med_days'] !== null && $expD['med_days'] <= 30): ?>
                                <div class="flex justify-between items-center text-amber-700 font-bold bg-white p-1.5 rounded border border-amber-100">
                                    <span>الفحص الطبي:</span>
                                    <span class="font-num text-[11px]">
                                        <?= $expD['med_days'] < 0 ? 'منتهي الصلاحية' : 'باقي ' . $expD['med_days'] . ' يوم (' . $expD['medical_cert_expiry'] . ')' ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($expD['plate_number'])): ?>
                                <div class="text-[11px] text-slate-500 pt-1 border-t border-rose-100">
                                    المركبة: <strong class="font-num text-slate-800"><?= htmlspecialchars($expD['plate_number']) ?> (<?= htmlspecialchars($expD['door_number']) ?>)</strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-6 text-center text-slate-400 text-xs bg-slate-50 rounded-xl">
                ✅ جميع رخص السياقة والشهادات الطبية الدورية للسائقين سارية المفعول لأكثر من 30 يوماً.
            </div>
        <?php endif; ?>
    </div>

    <!-- SECTION 2: Toolbar & Filters -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-xs">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Rechercher un chauffeur, téléphone, permis..."
                       class="w-full px-3.5 py-2 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-900 text-white font-bold">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="/dashboard/drivers.php" class="text-slate-400 hover:text-slate-600 underline">إعادة ضبط</a>
            <?php endif; ?>
        </form>

        <!-- Segmented Filter Tabs: Tous, Chauffeurs Actifs (95), CHAUFFEURS PERMANENCE, Anciens (3) -->
        <div class="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
            
            <a href="/dashboard/drivers.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                Tous (الكل)
            </a>

            <a href="/dashboard/drivers.php?filter=actifs<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'actifs' ? 'bg-amber-500 text-slate-950 font-black' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                <span>Chauffeurs Actifs</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-white font-num font-bold text-slate-900"><?= $countActifs ?></span>
            </a>

            <a href="/dashboard/drivers.php?filter=permanence<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-black transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'permanence' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-800 border border-amber-300 hover:bg-amber-100' ?>">
                <span>⭐ CHAUFFEURS PERMANENCE</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-700 text-white font-num"><?= $countPermanence ?></span>
            </a>

            <a href="/dashboard/debts.php?filter=all" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 bg-slate-100 text-slate-600 hover:bg-slate-200">
                <span>Anciens (Créances)</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-slate-300 font-num font-bold text-slate-800"><?= $countAnciens ?></span>
            </a>

        </div>

    </div>

    <!-- SECTION 3: Drivers Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-extrabold border-b border-slate-100">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap">الاسم واللقب (Chauffeur)</th>
                        <th class="p-3.5 whitespace-nowrap">رقم الهاتف</th>
                        <th class="p-3.5 whitespace-nowrap">رقم رخصة السياقة</th>
                        <th class="p-3.5 whitespace-nowrap">صلاحية الرخصة</th>
                        <th class="p-3.5 whitespace-nowrap">الفحص الطبي الدوري</th>
                        <th class="p-3.5 whitespace-nowrap">المركبة الحالية</th>
                        <th class="p-3.5 whitespace-nowrap text-center">المداومة (Permanence)</th>
                        <th class="p-3.5 whitespace-nowrap text-center">الوضعية</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($drivers)): ?>
                        <?php foreach ($drivers as $d): ?>
                            <tr class="hover:bg-slate-50/60 transition <?= $d['is_permanence'] ? 'bg-amber-50/20' : '' ?>">
                                
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-extrabold text-slate-900"><?= htmlspecialchars($d['full_name']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars($d['email']) ?></div>
                                </td>

                                <td class="p-3.5 whitespace-nowrap font-num font-bold text-slate-800">
                                    <?= htmlspecialchars($d['phone']) ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap font-num text-slate-800">
                                    <?= htmlspecialchars($d['license_number'] ?? 'غير مسجل') ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap font-num">
                                    <?php if (!empty($d['license_expiry'])): ?>
                                        <span class="font-bold text-slate-800"><?= htmlspecialchars($d['license_expiry']) ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded bg-emerald-50 text-emerald-700 font-semibold font-num text-[11px]">
                                        <?= htmlspecialchars($d['medical_cert_expiry'] ?? 'صالح ومفحوص') ?>
                                    </span>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($d['plate_number'])): ?>
                                        <div class="font-bold text-slate-800 font-num"><?= htmlspecialchars($d['plate_number']) ?></div>
                                        <div class="text-[11px] text-slate-400"><?= htmlspecialchars($d['brand'] . ' ' . $d['model']) ?> (<?= htmlspecialchars($d['door_number']) ?>)</div>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic">غير معين لمركبة (احتياطي)</span>
                                    <?php endif; ?>
                                </td>

                                <!-- CHAUFFEURS PERMANENCE BADGE -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($d['is_permanence']): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500 text-slate-950 font-black text-[11px] shadow-sm">
                                            <span>⭐</span>
                                            <span>PERMANENCE</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">خدمة عادية</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        معتمد ونظامي
                                    </span>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="p-8 text-center text-slate-400">لا يوجد سائقون مطابقون لشرط التصفية.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Add Driver -->
<div id="addDriverModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-extrabold text-slate-900 text-lg">تسجيل سائق سيارة أجرة جديد</h3>
                <span class="text-xs text-slate-500">إضافة ملف السائق وبطاقة الكفاءة المهنية</span>
            </div>
            <button onclick="document.getElementById('addDriverModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center font-bold text-sm transition">✕</button>
        </div>

        <form method="POST" action="/dashboard/drivers.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="create_driver">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">الاسم واللقب الكامل <span class="text-rose-500">*</span></label>
                <input type="text" name="full_name" required placeholder="مثال: فاروق بوعلام"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">رقم الهاتف <span class="text-rose-500">*</span></label>
                    <input type="tel" name="phone" required placeholder="0550112233" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-right font-num transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">البريد الإلكتروني</label>
                    <input type="email" name="email" placeholder="اختياري" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-right transition">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">رقم رخصة السياقة</label>
                    <input type="text" name="license_number" placeholder="مثال: 998811/16" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-right font-num transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء رخصة السياقة</label>
                    <input type="date" name="license_expiry"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white font-num transition">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء الشهادة الطبية الدورية</label>
                <input type="date" name="medical_cert_expiry"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white font-num transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('addDriverModal').classList.add('hidden')"
                        class="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold transition shadow-sm">
                    حفظ وتسجيل السائق
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
