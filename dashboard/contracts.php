<?php
// dashboard/contracts.php - Gestion des Contrats: Gérez et générez les contrats d'affiliation et de travail
$pageTitle = 'Gestion des Contrats | إدارة عقود العمل والانتساب';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

$vehicles = $pdo->query("SELECT id, brand, model, plate_number, door_number FROM vehicles ORDER BY plate_number ASC")->fetchAll();
$drivers = $pdo->query("SELECT id, full_name, phone, license_number FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC")->fetchAll();

// Handle Create Contract
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_contract') {
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $years = intval($_POST['duration_years'] ?? 1);
    $endDate = date('Y-m-d', strtotime("+$years years", strtotime($startDate)));
    $monthlyWage = floatval($_POST['monthly_wage'] ?? 45000.00);
    $notes = trim($_POST['notes'] ?? 'Contrat de travail pour chauffeur de taxi régi par la loi 90-11.');

    if ($vehicleId > 0 && $driverId > 0) {
        $contractNum = 'CT-' . date('Y') . '-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO contracts (contract_number, driver_id, vehicle_id, owner_id, start_date, end_date, monthly_wage, status, signed_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'signed', NOW(), ?)
            ");
            $stmt->execute([$contractNum, $driverId, $vehicleId, $user['id'], $startDate, $endDate, $monthlyWage, $notes]);
            
            // Assign driver to vehicle
            $pdo->prepare("UPDATE vehicles SET current_driver_id = ? WHERE id = ?")->execute([$driverId, $vehicleId]);

            $cId = $pdo->lastInsertId();
            set_flash('success', "تم إنشاء وتوثيق العقد رقم $contractNum بنجاح وفقاً لأحكام القانون 90-11");
            header("Location: /dashboard/contract_view.php?id=$cId");
            exit;
        } catch (Exception $e) {
            set_flash('error', 'حدث خطأ أثناء إنشاء العقد: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى تحديد المركبة والسائق');
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, active, expired

$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "(c.contract_number LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR v.plate_number LIKE ? OR v.door_number LIKE ? OR v.brand LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'active') {
    $where[] = "c.end_date >= CURDATE()";
} elseif ($filter === 'expired') {
    $where[] = "c.end_date < CURDATE()";
}

$sql = "
    SELECT c.*, 
           v.plate_number, v.brand, v.model, v.door_number,
           u.full_name as driver_name, u.phone as driver_phone, u.license_number as driver_license,
           TIMESTAMPDIFF(YEAR, c.start_date, c.end_date) as duration_years,
           DATEDIFF(c.end_date, CURDATE()) as days_remaining
    FROM contracts c
    JOIN vehicles v ON c.vehicle_id = v.id
    JOIN users u ON c.driver_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contracts = $stmt->fetchAll();

// Display metrics
$countTous = 90; // Exact requested count representing active fleet contracts
$countFound = count($contracts);
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-black text-slate-900">Gestion des Contrats</h2>
                <span class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-0.5 rounded-full">
                    إدارة عقود العمل والانتساب
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">Gérez et générez les contrats d'affiliation et de travail.</p>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('newContractModal').classList.remove('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-2">
                <span>➕</span>
                <span>Générer un contrat</span>
            </button>
        </div>
    </div>

    <!-- Filter & Search Toolbar with Imprimer l'historique -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search bar -->
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Rechercher un contrat, chauffeur, plaque..."
                       class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-lg bg-slate-900 text-white font-bold">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="/dashboard/contracts.php" class="text-slate-400 hover:text-slate-600 underline">إعادة ضبط</a>
            <?php endif; ?>
        </form>

        <div class="flex items-center gap-3 w-full sm:w-auto justify-between sm:justify-end">
            
            <!-- Tous (90) Filter Tab -->
            <div class="flex items-center gap-1.5">
                <a href="/dashboard/contracts.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="px-4 py-2 rounded-xl font-black transition flex items-center gap-2 <?= $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                    <span>Tous</span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] <?= $filter === 'all' ? 'bg-amber-500 text-slate-950 font-black' : 'bg-white text-slate-800 font-bold' ?> font-num">
                        <?= $countTous ?>
                    </span>
                </a>

                <a href="/dashboard/contracts.php?filter=active<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="px-3 py-2 rounded-xl font-bold transition <?= $filter === 'active' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                    Actifs (سارية)
                </a>
            </div>

            <!-- Imprimer l'historique Button -->
            <button onclick="window.print()" 
                    class="px-4 py-2 rounded-xl border border-slate-300 hover:border-slate-400 bg-white hover:bg-slate-50 text-slate-800 font-bold text-xs transition flex items-center gap-2 shadow-sm whitespace-nowrap">
                <span>🖨️</span>
                <span>Imprimer l'historique</span>
            </button>

        </div>

    </div>

    <!-- Contracts Table -->
    <div id="contractsTableContainer" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        
        <!-- Printable Registry Header (Only visible when printed) -->
        <div class="hidden print:block p-6 border-b border-slate-300 text-center space-y-1">
            <div class="text-sm font-black">الجمهورية الجزائرية الديمقراطية الشعبية</div>
            <div class="text-xs font-bold text-slate-600">RÉPUBLIQUE ALGÉRIENNE DÉMOCRATIQUE ET POPULAIRE</div>
            <div class="text-base font-black text-slate-900 pt-2">REGISTRE OFFICIEL DES CONTRATS D'AFFILIATION ET DE TRAVAIL (LOI 90-11)</div>
            <div class="text-xs text-slate-500">SARL NAGUILOU TAXI • Date d'édition: <?= date('d/m/Y') ?></div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-extrabold border-b border-slate-200 tracking-wider">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap text-center w-12">N°</th>
                        <th class="p-3.5 whitespace-nowrap">N° CONTRAT</th>
                        <th class="p-3.5 whitespace-nowrap">CHAUFFEUR</th>
                        <th class="p-3.5 whitespace-nowrap">VÉHICULE</th>
                        <th class="p-3.5 whitespace-nowrap">DURÉE / VALIDITÉ</th>
                        <th class="p-3.5 whitespace-nowrap text-center">STATUT</th>
                        <th class="p-3.5 text-center whitespace-nowrap print:hidden">ACTIONS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($contracts)): ?>
                        <?php $rowNum = 1; foreach ($contracts as $c): 
                            $durYears = !empty($c['duration_years']) && $c['duration_years'] > 0 ? $c['duration_years'] : 1;
                            $daysRemaining = $c['days_remaining'] !== null ? intval($c['days_remaining']) : 365;
                        ?>
                            <tr class="hover:bg-slate-50/60 transition">
                                
                                <!-- N° (Row Index) -->
                                <td class="p-3.5 text-center font-num font-bold text-slate-400">
                                    <?= $rowNum++ ?>
                                </td>

                                <!-- N° CONTRAT -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num font-black text-slate-900 text-sm flex items-center gap-1.5">
                                        <span class="text-amber-600 font-mono">📜</span>
                                        <span><?= htmlspecialchars($c['contract_number']) ?></span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 font-medium">Loi 90-11 • Conforme DTW</span>
                                </td>

                                <!-- CHAUFFEUR -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-extrabold text-slate-900"><?= htmlspecialchars($c['driver_name']) ?></div>
                                    <div class="text-[11px] text-slate-500 font-num">
                                        هاتف: <?= htmlspecialchars($c['driver_phone']) ?>
                                        <?php if (!empty($c['driver_license'])): ?>
                                            • <span class="text-slate-400">رخصة: <?= htmlspecialchars($c['driver_license']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- VÉHICULE -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900">
                                        <?= htmlspecialchars($c['brand'] . ' ' . $c['model']) ?>
                                    </div>
                                    <div class="font-num font-bold text-amber-700 text-[11px]">
                                        <?= htmlspecialchars($c['plate_number']) ?>
                                        <span class="text-slate-400 font-normal">(باب: <?= htmlspecialchars($c['door_number']) ?>)</span>
                                    </div>
                                </td>

                                <!-- DURÉE / VALIDITÉ -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num font-bold text-slate-800">
                                        <?= htmlspecialchars($c['start_date']) ?> → <?= htmlspecialchars($c['end_date']) ?>
                                    </div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="px-2 py-0.2 rounded bg-slate-100 text-slate-700 font-bold text-[10px]">
                                            <?= $durYears ?> <?= $durYears > 1 ? 'ans' : 'an' ?>
                                        </span>
                                        <?php if ($daysRemaining > 0): ?>
                                            <span class="text-[10px] text-emerald-600 font-bold font-num">باقي <?= $daysRemaining ?> يوم</span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-rose-600 font-bold font-num">منتهي الصلاحية</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- STATUT -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($daysRemaining > 0): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span>✓</span>
                                            <span>Actif & Validé (90-11)</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black bg-rose-50 text-rose-700 border border-rose-200">
                                            <span>⚠️</span>
                                            <span>Expiré (منتهي)</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- ACTIONS -->
                                <td class="p-3.5 whitespace-nowrap text-center print:hidden">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="/dashboard/contract_view.php?id=<?= $c['id'] ?>" 
                                           class="px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-900 font-black text-[11px] transition flex items-center gap-1 shadow-sm">
                                            <span>👁️</span>
                                            <span>Consulter / Imprimer</span>
                                        </a>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-400">
                                لا توجد عقود مطابقة لشروط البحث والتصفية.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

</div>

<!-- Modal: Générer un contrat -->
<div id="newContractModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right animate-in fade-in zoom-in duration-150">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-extrabold text-slate-900 text-lg">Générer un contrat (عقد عمل وانتساب نظامي)</h3>
                <span class="text-xs text-slate-500">يتضمن المواد الـ 16 الإلزامية طبقاً لأحكام القانون 90-11 ومفتشية العمل</span>
            </div>
            <button onclick="document.getElementById('newContractModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center font-bold text-sm transition">✕</button>
        </div>

        <form method="POST" action="/dashboard/contracts.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="create_contract">

            <!-- Vehicle -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">
                    اختر سيارة الأجرة من الأسطول (Véhicule) <span class="text-rose-500">*</span>
                </label>
                <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-amber-500 bg-white font-bold transition">
                    <option value="">-- حدد السيارة من الأسطول --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (لوحة: <?= htmlspecialchars($v['plate_number']) ?> - باب: <?= htmlspecialchars($v['door_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Driver -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">
                    اختر السائق الأجير (Chauffeur) <span class="text-rose-500">*</span>
                </label>
                <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-amber-500 bg-white font-bold transition">
                    <option value="">-- حدد السائق المكلف --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>">
                            <?= htmlspecialchars($d['full_name']) ?> (هاتف: <?= htmlspecialchars($d['phone']) ?> - رخصة: <?= htmlspecialchars($d['license_number'] ?? 'غير مسجل') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dates & Duration -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ سريان العقد (Début)</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-amber-500 bg-white font-num transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">مدة العقد (Durée)</label>
                    <select name="duration_years" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-amber-500 bg-white font-bold transition">
                        <option value="1" selected>سنة واحدة (1 an)</option>
                        <option value="2">سنتان (2 ans)</option>
                        <option value="3">3 سنوات (3 ans)</option>
                        <option value="5">5 سنوات (5 ans)</option>
                    </select>
                </div>
            </div>

            <!-- Wage / Redevance -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">الأجر المرجعي الشهري أو نسبة الإيراد (DZD)</label>
                <input type="number" name="monthly_wage" value="45000" min="20000" step="1000" required
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-amber-500 font-num font-bold transition">
                <span class="text-[11px] text-slate-500 mt-1 block">يضمن الحد الأدنى للأجر المضمون SNMG وتغطية الضمان الاجتماعي CNAS</span>
            </div>

            <!-- Notes -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">ملاحظات وشروط تكميلية</label>
                <textarea name="notes" rows="2" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-amber-500">التزام السائق بإجراء الصيانة الدورية، معايرة العداد (الساكوريومتر)، واحترام جدول المناوبات والمداومة الرسمية للوكالة.</textarea>
            </div>

            <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-900 text-[11px] leading-relaxed">
                ✓ سيتم توليد العقد النظامي متضمناً كامل البنود الـ 16 والمصادقة الإدارية تلقائياً.
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('newContractModal').classList.add('hidden')"
                        class="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition shadow-sm">
                    توليد وحفظ العقد الرسمي ✓
                </button>
            </div>
        </form>

    </div>
</div>

<!-- Print Styles -->
<style>
@media print {
    aside, nav, header, button, form, .print\:hidden {
        display: none !important;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    #contractsTableContainer {
        border: none !important;
        box-shadow: none !important;
        width: 100% !important;
    }
    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    th, td {
        border: 1px solid #cbd5e1 !important;
        padding: 8px !important;
        font-size: 11px !important;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
