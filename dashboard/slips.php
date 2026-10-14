<?php
// dashboard/slips.php - Générateur de Bordereaux d'Envoi & Historique des Bordereaux
$pageTitle = 'Générateur de Bordereaux d\'Envoi | جداول الإرسال الرسمية';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Fetch vehicles and drivers for selector
$vehicles = $pdo->query("SELECT id, brand, model, plate_number, door_number FROM vehicles ORDER BY plate_number ASC")->fetchAll();
$drivers = $pdo->query("SELECT id, full_name, phone, license_number FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC")->fetchAll();

// Active tab
$activeTab = $_GET['tab'] ?? 'generator'; // 'generator' or 'history'

// Handle New Slip Generation
$generatedSlip = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_slip') {
    require_csrf_token();
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $recipient = trim($_POST['recipient'] ?? 'Direction des Transports de la Wilaya d\'Alger (DTW)');
    $routeLine = trim($_POST['route_line'] ?? 'Ligne Urbaine - Alger Centre / Aéroport Houari Boumediene');
    $subject = trim($_POST['subject'] ?? 'Déclaration d\'affectation de chauffeur titulaire et mise en service de permanence');
    $issueDate = !empty($_POST['issue_date']) ? $_POST['issue_date'] : date('Y-m-d');
    $validUntil = !empty($_POST['valid_until']) ? $_POST['valid_until'] : date('Y-m-d', strtotime('+30 days'));
    $notes = trim($_POST['notes'] ?? 'Dossier complet comprenant copie du permis de conduire, certificat médical périodique et attestation d\'assurance.');

    if ($vehicleId > 0 && $driverId > 0) {
        $maxSlipId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM transmission_slips")->fetchColumn();
        $slipNum = 'BRD-' . date('Y') . '-' . str_pad($maxSlipId + 1, 4, '0', STR_PAD_LEFT);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO transmission_slips (slip_number, vehicle_id, driver_id, route_line, issue_date, valid_until, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$slipNum, $vehicleId, $driverId, "$subject | $recipient | $routeLine", $issueDate, $validUntil]);
            $slipId = (int)$pdo->lastInsertId();

            log_audit($pdo, 'slip_generated', 'transmission_slips', $slipId, "إصدار جدول إرسال رقم $slipNum للمركبة $vehicleId والسائق $driverId");

            // Retrieve populated slip data for instant preview & printing
            $stmtGet = $pdo->prepare("
                SELECT s.*, v.brand, v.model, v.plate_number, v.door_number,
                       u.full_name as driver_name, u.phone as driver_phone, u.license_number as driver_license
                FROM transmission_slips s
                JOIN vehicles v ON s.vehicle_id = v.id
                JOIN users u ON s.driver_id = u.id
                WHERE s.id = ?
            ");
            $stmtGet->execute([$slipId]);
            $generatedSlip = $stmtGet->fetch();
            $generatedSlip['recipient'] = $recipient;
            $generatedSlip['subject'] = $subject;
            $generatedSlip['route_line_clean'] = $routeLine;
            $generatedSlip['notes'] = $notes;

            set_flash('success', "تم إنشاء جدول الإرسال رقم $slipNum بنجاح وجاهز للطباعة والتسليم للجهة الوصية");
        } catch (Exception $e) {
            set_flash('error', 'حدث خطأ أثناء إنشاء جدول الإرسال: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى تحديد المركبة والسائق المعني بالإرسال');
    }
}

// Fetch History of Slips
$slipsHistory = $pdo->query("
    SELECT s.*, v.brand, v.model, v.plate_number, v.door_number,
           u.full_name as driver_name, u.phone as driver_phone, u.license_number as driver_license
    FROM transmission_slips s
    JOIN vehicles v ON s.vehicle_id = v.id
    JOIN users u ON s.driver_id = u.id
    ORDER BY s.id DESC
")->fetchAll();

// If a slip print ID is requested directly via GET
if (isset($_GET['print_id'])) {
    $pId = intval($_GET['print_id']);
    $stmtPrint = $pdo->prepare("
        SELECT s.*, v.brand, v.model, v.plate_number, v.door_number,
               u.full_name as driver_name, u.phone as driver_phone, u.license_number as driver_license
        FROM transmission_slips s
        JOIN vehicles v ON s.vehicle_id = v.id
        JOIN users u ON s.driver_id = u.id
        WHERE s.id = ?
    ");
    $stmtPrint->execute([$pId]);
    $generatedSlip = $stmtPrint->fetch();
    if ($generatedSlip) {
        $parts = explode(' | ', $generatedSlip['route_line']);
        $generatedSlip['subject'] = $parts[0] ?? 'Bordereau d\'envoi officiel';
        $generatedSlip['recipient'] = $parts[1] ?? 'Direction des Transports de la Wilaya';
        $generatedSlip['route_line_clean'] = $parts[2] ?? $generatedSlip['route_line'];
        $generatedSlip['notes'] = 'Dossier administratif certifié conforme pour contrôle réglementaire.';
    }
}
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Générateur de Bordereaux d'Envoi</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    جداول الإرسال الرسمية
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">توليد وطباعة جداول الإرسال الرسمية لمديرية النقل (DTW)، مصالح الدرك والأمن الوطني</p>
        </div>
        
        <!-- Segmented Tab Navigation -->
        <div class="flex items-center bg-slate-100 p-1 rounded-xl text-xs font-bold">
            <a href="slips.php?tab=generator" 
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg transition <?= $activeTab === 'generator' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900' ?>">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                </svg>
                <span>المولّد (Générateur)</span>
            </a>
            <a href="slips.php?tab=history" 
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg transition <?= $activeTab === 'history' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900' ?>">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                </svg>
                <span>السجل والأرشيف (<?= count($slipsHistory) ?>)</span>
            </a>
        </div>
    </div>

    <?php if ($activeTab === 'generator'): ?>
        <!-- TAB 1: GENERATEUR DE BORDEREAUX -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Left Column: Generator Form -->
            <div class="lg:col-span-5 bg-white p-5 sm:p-6 rounded-2xl border border-slate-200 shadow-xs space-y-4">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="font-bold text-slate-900 text-sm">إعداد وتوليد جدول إرسال جديد</h3>
                    <p class="text-xs text-slate-400 mt-0.5">يرجى ملء تفاصيل الوثيقة الإدارية الموجهة لمصالح المراقبة</p>
                </div>

                <form method="POST" class="space-y-4 text-xs">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_slip">

                    <!-- Destination / Recipient -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">الجهة الموجه إليها (Destinataire) <span class="text-rose-500">*</span></label>
                        <select name="recipient" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold bg-white text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                            <option value="Direction des Transports de la Wilaya d'Alger (DTW)">Direction des Transports de la Wilaya d'Alger (DTW)</option>
                            <option value="Direction de la Réglementation et des Affaires Générales (DRAG)">Direction de la Réglementation et des Affaires Générales (DRAG)</option>
                            <option value="Sûreté de Daïra / Service de la Circulation Routière">Sûreté de Daïra / Service de la Circulation</option>
                            <option value="Groupement de Gendarmerie Nationale - Brigade Territoriale">Groupement de Gendarmerie Nationale</option>
                            <option value="Commission Paritaire de Recours et de Discipline">Commission Paritaire de Recours</option>
                        </select>
                    </div>

                    <!-- Vehicle -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">المركبة المعنية (Véhicule) <span class="text-rose-500">*</span></label>
                        <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white font-semibold text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                            <option value="">-- اختر مركبة الأسطول --</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['id'] ?>">
                                    <?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (لوحة: <?= htmlspecialchars($v['plate_number']) ?> - باب: <?= htmlspecialchars($v['door_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Driver -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">السائق المكلف (Chauffeur) <span class="text-rose-500">*</span></label>
                        <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white font-semibold text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                            <option value="">-- اختر السائق المكلف --</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?= $d['id'] ?>">
                                    <?= htmlspecialchars($d['full_name']) ?> (رخصة: <?= htmlspecialchars($d['license_number'] ?? 'غير مسجل') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Line / Route -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">خط السير / مسار الاستغلال المصرح به <span class="text-rose-500">*</span></label>
                        <input type="text" name="route_line" value="Ligne Urbaine: Alger Centre / Bab Ezzouar / Aéroport" required
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>

                    <!-- Subject / Motif -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">موضوع الإرسال (Objet) <span class="text-rose-500">*</span></label>
                        <input type="text" name="subject" value="Transmission dossier chauffeur titulaire & affectation de service de permanence" required
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>

                    <!-- Dates -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">تاريخ الإصدار</label>
                            <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">صالح إلى غاية</label>
                            <input type="date" name="valid_until" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">ملاحظات ووثائق مرفقة</label>
                        <textarea name="notes" rows="2" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">نسخة طبق الأصل من رخصة السياقة الصنف B، شهادة الكفاءة المهنية، الشهادة الطبية الدورية، ونسخة من عقد العمل قانون 90-11.</textarea>
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                class="w-full py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                            <span>توليد ومعاينة جدول الإرسال</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column: Official Bordereau Document Preview & Print Button -->
            <div class="lg:col-span-7 space-y-4">
                
                <div class="flex items-center justify-between">
                    <div class="text-xs font-bold text-slate-600">
                        معاينة الوثيقة الرسمية الصادرة
                    </div>
                    <?php if ($generatedSlip): ?>
                        <button onclick="window.print()" 
                                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-xs">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.03-.42-2.1-.523-3.2A11.75 11.75 0 0112 3c3.08 0 5.86 1.173 7.803 3.09.28.277.53.57.753.878m-1.556 7.861A11.724 11.724 0 0112 21c-3.08 0-5.86-1.173-7.803-3.09-.28-.277-.53-.57-.753-.878" />
                            </svg>
                            <span>طباعة جدول الإرسال (PDF)</span>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Printable Bordereau Paper -->
                <div id="printableBordereau" class="bg-white p-6 sm:p-8 rounded-2xl border border-slate-300 shadow-sm text-slate-900 space-y-5 text-xs">
                    
                    <!-- Official Header -->
                    <div class="border-b-2 border-slate-900 pb-4">
                        <div class="flex items-center justify-between gap-4">
                            <img src="<?= url('/logo.webp') ?>" alt="EURL TAXI NAGUILOU" class="w-12 h-12 object-contain rounded-full border border-amber-300 shadow-xs shrink-0">
                            <div class="text-center space-y-0.5 flex-1">
                                <div class="text-xs font-bold tracking-wide">الجمهورية الجزائرية الديمقراطية الشعبية</div>
                                <div class="text-[10px] font-semibold text-slate-700">RÉPUBLIQUE ALGÉRIENNE DÉMOCRATIQUE ET POPULAIRE</div>
                                <div class="text-[10px] font-semibold text-slate-600">وزارة النقل — مديرية النقل لولاية الجزائر (DTW)</div>
                            </div>
                            <div class="w-12 text-center text-[9px] text-slate-400 font-bold shrink-0">
                                <div>اعتماد DTW</div>
                                <div class="text-slate-800 font-num">16/2024</div>
                            </div>
                        </div>
                    </div>

                    <?php if ($generatedSlip): ?>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-slate-500 font-num">رقم: <?= htmlspecialchars($generatedSlip['slip_number']) ?></span>
                                <span class="font-bold text-slate-500 font-num">الجزائر في: <?= htmlspecialchars($generatedSlip['issue_date']) ?></span>
                            </div>

                            <div class="text-center py-2 border-y border-slate-200">
                                <h2 class="text-base font-extrabold text-slate-900">جدول إرسال رسمي (BORDEREAU D'ENVOI)</h2>
                                <p class="text-[11px] text-slate-600 mt-0.5">موجه إلى: <strong><?= htmlspecialchars($generatedSlip['recipient']) ?></strong></p>
                            </div>

                            <div class="grid grid-cols-2 gap-4 bg-slate-50 p-3.5 rounded-xl border border-slate-200">
                                <div>
                                    <span class="text-slate-500 font-semibold block">بيانات المركبة:</span>
                                    <span class="font-bold text-slate-900"><?= htmlspecialchars($generatedSlip['brand'] . ' ' . $generatedSlip['model']) ?></span>
                                    <span class="font-num text-slate-700 block" dir="ltr"><?= htmlspecialchars($generatedSlip['plate_number']) ?> (باب: <?= htmlspecialchars($generatedSlip['door_number']) ?>)</span>
                                </div>
                                <div>
                                    <span class="text-slate-500 font-semibold block">السائق المعني:</span>
                                    <span class="font-bold text-slate-900"><?= htmlspecialchars($generatedSlip['driver_name']) ?></span>
                                    <span class="font-num text-slate-700 block" dir="ltr"><?= htmlspecialchars($generatedSlip['driver_phone']) ?></span>
                                </div>
                            </div>

                            <div>
                                <span class="text-slate-500 font-semibold block">الموضوع والمسار:</span>
                                <p class="font-semibold text-slate-800 mt-0.5"><?= htmlspecialchars($generatedSlip['subject'] ?? $generatedSlip['route_line']) ?></p>
                            </div>

                            <div>
                                <span class="text-slate-500 font-semibold block">الوثائق المرفقة والملاحظات:</span>
                                <p class="text-slate-700 mt-0.5 text-[11px]"><?= htmlspecialchars($generatedSlip['notes'] ?? 'ملف كامل ومطابق للمعايير التنظيمية') ?></p>
                            </div>

                            <div class="pt-6 flex justify-between text-center text-xs">
                                <div>
                                    <span class="text-slate-500 block mb-8">توقيع السائق المعني</span>
                                    <span class="text-[11px] text-slate-400">...........................</span>
                                </div>
                                <div>
                                    <span class="text-slate-500 block mb-8">ختم وتوقيع مسيّر الوكالة</span>
                                    <span class="text-[11px] text-slate-400">SARL NAGUILOU TAXI</span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="p-12 text-center text-slate-400">
                            <p class="font-semibold text-slate-600">يرجى ملء النموذج الجانبي لتوليد جدول الإرسال ومعاينته هنا</p>
                            <p class="text-[11px] text-slate-400 mt-1">أو اختر جدولاً مسجلاً مسبقاً من تبويب "السجل والأرشيف"</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    <?php else: ?>
        <!-- TAB 2: HISTORIQUE DES BORDEREAUX -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-slate-900 text-sm">أرشيف جداول الإرسال الرسمية</h3>
                    <p class="text-xs text-slate-400">سجل كامل لجداول الإرسال الصادرة للمصالح الولائية والميدانية</p>
                </div>
                <a href="slips.php?tab=generator" 
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>إنشاء جدول جديد</span>
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
                        <tr>
                            <th class="p-3.5 whitespace-nowrap text-center">رقم الجدول</th>
                            <th class="p-3.5 whitespace-nowrap">المركبة المعنية</th>
                            <th class="p-3.5 whitespace-nowrap">السائق المكلف</th>
                            <th class="p-3.5 whitespace-nowrap">الموضوع / خط السير</th>
                            <th class="p-3.5 whitespace-nowrap">تاريخ الإصدار</th>
                            <th class="p-3.5 whitespace-nowrap">صالح إلى غاية</th>
                            <th class="p-3.5 whitespace-nowrap text-center">الحالة</th>
                            <th class="p-3.5 text-center whitespace-nowrap">الإجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php if (!empty($slipsHistory)): ?>
                            <?php foreach ($slipsHistory as $slip): ?>
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="p-3.5 whitespace-nowrap text-center font-num font-mono font-bold text-slate-900">
                                        <span class="inline-block" dir="ltr"><?= htmlspecialchars($slip['slip_number']) ?></span>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <div class="font-bold text-slate-900"><?= htmlspecialchars($slip['brand'] . ' ' . $slip['model']) ?></div>
                                        <div class="text-[11px] font-num text-slate-500" dir="ltr"><?= htmlspecialchars($slip['plate_number']) ?> (باب: <?= htmlspecialchars($slip['door_number']) ?>)</div>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($slip['driver_name']) ?></div>
                                        <div class="text-[11px] font-num text-slate-400" dir="ltr"><?= htmlspecialchars($slip['driver_phone']) ?></div>
                                    </td>
                                    <td class="p-3.5 max-w-xs truncate text-slate-600">
                                        <?= htmlspecialchars($slip['route_line']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-num text-slate-700">
                                        <span class="inline-block" dir="ltr"><?= htmlspecialchars($slip['issue_date']) ?></span>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-num font-semibold text-slate-800">
                                        <span class="inline-block" dir="ltr"><?= htmlspecialchars($slip['valid_until']) ?></span>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold text-[10px] border border-emerald-200/60">
                                            ساري (Actif)
                                        </span>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <a href="slips.php?tab=generator&print_id=<?= $slip['id'] ?>"
                                           class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-[11px] transition shadow-xs">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.03-.42-2.1-.523-3.2A11.75 11.75 0 0112 3c3.08 0 5.86 1.173 7.803 3.09.28.277.53.57.753.878m-1.556 7.861A11.724 11.724 0 0112 21c-3.08 0-5.86-1.173-7.803-3.09-.28-.277-.53-.57-.753-.878" />
                                            </svg>
                                            <span>معاينة وطباعة</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="p-10 text-center text-slate-400">
                                    لا توجد جداول إرسال مسجلة في الأرشيف حالياً.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Print Styles -->
<style>
@media print {
    body * {
        visibility: hidden;
    }
    #printableBordereau, #printableBordereau * {
        visibility: visible;
    }
    #printableBordereau {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 20px;
        box-shadow: none;
        border: none;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
