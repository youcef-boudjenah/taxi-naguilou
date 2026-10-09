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
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $recipient = trim($_POST['recipient'] ?? 'Direction des Transports de la Wilaya d\'Alger (DTW)');
    $routeLine = trim($_POST['route_line'] ?? 'Ligne Urbaine - Alger Centre / Aéroport Houari Boumediene');
    $subject = trim($_POST['subject'] ?? 'Déclaration d\'affectation de chauffeur titulaire et mise en service de permanence');
    $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
    $validUntil = $_POST['valid_until'] ?? date('Y-m-d', strtotime('+30 days'));
    $notes = trim($_POST['notes'] ?? 'Dossier complet comprenant copie du permis de conduire, certificat médical périodique et attestation d\'assurance.');

    if ($vehicleId > 0 && $driverId > 0) {
        $slipNum = 'BRD-' . date('Y') . '-' . str_pad(rand(100, 9999), 4, '0', STR_PAD_LEFT);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO transmission_slips (slip_number, vehicle_id, driver_id, route_line, issue_date, valid_until, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$slipNum, $vehicleId, $driverId, "$subject | $recipient | $routeLine", $issueDate, $validUntil]);

            // Retrieve populated slip data for instant preview & printing
            $stmtGet = $pdo->prepare("
                SELECT s.*, v.brand, v.model, v.plate_number, v.door_number,
                       u.full_name as driver_name, u.phone as driver_phone, u.license_number as driver_license
                FROM transmission_slips s
                JOIN vehicles v ON s.vehicle_id = v.id
                JOIN users u ON s.driver_id = u.id
                WHERE s.id = ?
            ");
            $stmtGet->execute([$pdo->lastInsertId()]);
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
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-black text-slate-900">Générateur de Bordereaux d'Envoi</h2>
                <span class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-0.5 rounded-full">
                    جداول الإرسال الرسمية للإدارة
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">Générez et imprimez des bordereaux d'envoi officiels pour la direction des transports (DTW), la gendarmerie et les services de contrôle.</p>
        </div>
        
        <!-- Segmented Tab Navigation -->
        <div class="flex items-center bg-slate-100 p-1 rounded-xl text-xs font-bold">
            <a href="/dashboard/slips.php?tab=generator" 
               class="px-4 py-2 rounded-lg transition <?= $activeTab === 'generator' ? 'bg-white text-slate-900 shadow-sm font-black' : 'text-slate-600 hover:text-slate-900' ?>">
                📝 Générateur (المولّد)
            </a>
            <a href="/dashboard/slips.php?tab=history" 
               class="px-4 py-2 rounded-lg transition <?= $activeTab === 'history' ? 'bg-white text-slate-900 shadow-sm font-black' : 'text-slate-600 hover:text-slate-900' ?>">
                📁 Historique des Bordereaux (السجل) (<?= count($slipsHistory) ?>)
            </a>
        </div>
    </div>

    <?php if ($activeTab === 'generator'): ?>
        <!-- TAB 1: GENERATEUR DE BORDEREAUX -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Left Column: Generator Form -->
            <div class="lg:col-span-5 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="font-extrabold text-slate-900 text-sm">إعداد وتوليد جدول إرسال جديد</h3>
                    <p class="text-xs text-slate-400">يرجى ملء تفاصيل الوثيقة الإدارية الموجهة لمصالح المراقبة</p>
                </div>

                <form method="POST" class="space-y-4 text-xs">
                    <input type="hidden" name="action" value="generate_slip">

                    <!-- Destination / Recipient -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">الجهة الموجه إليها (Destinataire / Direction) *</label>
                        <select name="recipient" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-bold bg-white text-xs focus:ring-2 focus:ring-amber-500">
                            <option value="Direction des Transports de la Wilaya d'Alger (DTW)">Direction des Transports de la Wilaya d'Alger (DTW)</option>
                            <option value="Direction de la Réglementation et des Affaires Générales (DRAG)">Direction de la Réglementation et des Affaires Générales (DRAG)</option>
                            <option value="Sûreté de Daïra / Service de la Circulation Routière">Sûreté de Daïra / Service de la Circulation</option>
                            <option value="Groupement de Gendarmerie Nationale - Brigade Territoriale">Groupement de Gendarmerie Nationale</option>
                            <option value="Commission Paritaire de Recours et de Discipline">Commission Paritaire de Recours</option>
                        </select>
                    </div>

                    <!-- Vehicle -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">المركبة المعنية (Véhicule) *</label>
                        <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white font-bold text-xs focus:ring-2 focus:ring-amber-500">
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
                        <label class="block font-bold text-slate-700 mb-1">السائق المكلف (Chauffeur) *</label>
                        <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white font-bold text-xs focus:ring-2 focus:ring-amber-500">
                            <option value="">-- اختر السائق المكلف --</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?= $d['id'] ?>">
                                    <?= htmlspecialchars($d['full_name']) ?> (رقم رخصة: <?= htmlspecialchars($d['license_number'] ?? 'غير مسجل') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Line / Route -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">خط السير / مسار الاستغلال المصرح به *</label>
                        <input type="text" name="route_line" value="Ligne Urbaine: Alger Centre / Bab Ezzouar / Aéroport" required
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-bold text-xs focus:ring-2 focus:ring-amber-500">
                    </div>

                    <!-- Subject / Motif -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">موضوع الإرسال (Objet du bordereau) *</label>
                        <input type="text" name="subject" value="Transmission dossier chauffeur titulaire & affectation de service de permanence" required
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-amber-500">
                    </div>

                    <!-- Dates -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">تاريخ الإصدار</label>
                            <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-num text-xs focus:ring-2 focus:ring-amber-500">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">صالح إلى غاية</label>
                            <input type="date" name="valid_until" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-num text-xs focus:ring-2 focus:ring-amber-500">
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">ملاحظات ووثائق مرفقة</label>
                        <textarea name="notes" rows="2" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-amber-500">نسخة طبق الأصل من رخصة السياقة الصنف B، شهادة الكفاءة المهنية، الشهادة الطبية الدورية، ونسخة من عقد العمل قانون 90-11.</textarea>
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                class="w-full py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-sm flex items-center justify-center gap-2">
                            <span>📄</span>
                            <span>توليد ومعاينة جدول الإرسال (Générer le Bordereau)</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column: Official Bordereau Document Preview & Print Button -->
            <div class="lg:col-span-7 space-y-4">
                
                <div class="flex items-center justify-between">
                    <div class="text-xs font-bold text-slate-600">
                        معاينة الوثيقة الرسمية الصادرة (Aperçu officiel avant impression)
                    </div>
                    <?php if ($generatedSlip): ?>
                        <button onclick="window.print()" 
                                class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                            <span>🖨️</span>
                            <span>طباعة جدول الإرسال (Imprimer)</span>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Printable Bordereau Paper -->
                <div id="printableBordereau" class="bg-white p-8 rounded-2xl border border-slate-300 shadow-lg text-slate-900 space-y-6">
                    
                    <!-- Official Algerian Header with Logo -->
                    <div class="border-b-2 border-slate-900 pb-4">
                        <div class="flex items-center justify-between gap-4">
                            <img src="/logo.webp" alt="EURL TAXI NAGUILOU" class="w-14 h-14 object-contain rounded-full border border-amber-300 shadow-sm shrink-0">
                            <div class="text-center space-y-0.5 flex-1">
                                <div class="text-sm font-black tracking-wide font-sans">الجمهورية الجزائرية الديمقراطية الشعبية</div>
                                <div class="text-xs font-bold text-slate-700 font-sans">RÉPUBLIQUE ALGÉRIENNE DÉMOCRATIQUE ET POPULAIRE</div>
                                <div class="text-[11px] font-semibold text-slate-600 mt-0.5">
                                    وزارة النقل — مديرية النقل لولاية الجزائر (DTW)
                                </div>
                                <div class="text-[10px] text-slate-500 font-medium">
                                    المكتب التنفيذي للرقابة على نشاط سيارات الأجرة ونقل الأشخاص
                                </div>
                            </div>
                            <div class="w-14 text-center text-[10px] text-slate-400 font-bold shrink-0">
                                <div>اعتماد</div>
                                <div class="text-slate-800 font-num">16/2024</div>
                            </div>
                        </div>
                    </div>

                    <!-- Document Title Banner -->
                    <div class="flex items-center justify-between py-2 px-4 bg-slate-50 rounded-xl border border-slate-200">
                        <div>
                            <span class="text-xs font-black text-slate-800">BORDEREAU D'ENVOI OFFICIEL</span>
                            <div class="text-[11px] text-slate-500 font-bold">جدول إرسال رسمي لمصالح النقل والأمن</div>
                        </div>
                        <div class="text-left font-num">
                            <div class="text-sm font-black text-slate-900">
                                <?= $generatedSlip ? htmlspecialchars($generatedSlip['slip_number']) : 'BRD-2026-EXEMPLE' ?>
                            </div>
                            <div class="text-[10px] text-slate-400">
                                التاريخ: <?= $generatedSlip ? htmlspecialchars($generatedSlip['issue_date']) : date('Y-m-d') ?>
                            </div>
                        </div>
                    </div>

                    <!-- Meta Information Grid -->
                    <div class="grid grid-cols-2 gap-4 text-xs">
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="text-[10px] text-slate-400 block font-bold">المؤسسة / الوكالة المرسلة:</span>
                            <div class="font-black text-slate-900 text-sm">منصة تاكسي نقيلو (SARL NAGUILOU TAXI)</div>
                            <div class="text-[11px] text-slate-600">اعتماد مديرية النقل رقم: DTW-ALGER-2024/991</div>
                            <div class="text-[11px] text-slate-500">الجزائر العاصمة • الهاتف: 021.00.00.00</div>
                        </div>

                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="text-[10px] text-slate-400 block font-bold">الجهة المرسل إليها (Destinataire):</span>
                            <div class="font-black text-slate-900 text-xs">
                                <?= $generatedSlip ? htmlspecialchars($generatedSlip['recipient']) : 'Direction des Transports de la Wilaya d\'Alger (DTW)' ?>
                            </div>
                            <div class="text-[11px] text-slate-600">مصلحة تنظيم النقل البري والمراقبة المرورية</div>
                        </div>
                    </div>

                    <!-- Transmitted Vehicle and Driver Section -->
                    <div class="border border-slate-300 rounded-xl overflow-hidden text-xs">
                        <div class="bg-slate-100 px-3 py-2 font-bold text-slate-800 border-b border-slate-300 flex justify-between items-center">
                            <span>بيانات المركبة والسائق المصرح بهما (Désignation des pièces et affectation)</span>
                            <span class="text-[10px] font-bold text-amber-800 bg-amber-100 px-2 py-0.5 rounded">مداومة معتمدة</span>
                        </div>
                        <table class="w-full text-right text-xs">
                            <tr class="border-b border-slate-200">
                                <td class="p-2.5 bg-slate-50 font-bold text-slate-500 w-1/3">المركبة والصانع:</td>
                                <td class="p-2.5 font-bold text-slate-900">
                                    <?= $generatedSlip ? htmlspecialchars($generatedSlip['brand'] . ' ' . $generatedSlip['model']) : 'Renault Symbol' ?>
                                </td>
                            </tr>
                            <tr class="border-b border-slate-200">
                                <td class="p-2.5 bg-slate-50 font-bold text-slate-500">رقم التسجيل والباب:</td>
                                <td class="p-2.5 font-bold font-num text-amber-800">
                                    <?= $generatedSlip ? htmlspecialchars($generatedSlip['plate_number'] . ' (باب: ' . $generatedSlip['door_number'] . ')') : '01458-121-16 (باب: 16-042)' ?>
                                </td>
                            </tr>
                            <tr class="border-b border-slate-200">
                                <td class="p-2.5 bg-slate-50 font-bold text-slate-500">السائق المكلف:</td>
                                <td class="p-2.5 font-bold text-slate-900">
                                    <?= $generatedSlip ? htmlspecialchars($generatedSlip['driver_name']) : 'أحمد بلقاسم' ?>
                                    <span class="text-slate-500 font-num font-normal mr-2">(هاتف: <?= $generatedSlip ? htmlspecialchars($generatedSlip['driver_phone']) : '0770554433' ?>)</span>
                                </td>
                            </tr>
                            <tr class="border-b border-slate-200">
                                <td class="p-2.5 bg-slate-50 font-bold text-slate-500">رقم رخصة السياقة:</td>
                                <td class="p-2.5 font-bold font-num text-slate-800">
                                    <?= $generatedSlip ? htmlspecialchars($generatedSlip['driver_license'] ?? 'B-9481726') : 'B-9481726' ?>
                                </td>
                            </tr>
                            <tr class="border-b border-slate-200">
                                <td class="p-2.5 bg-slate-50 font-bold text-slate-500">مسار الاستغلال المرخص:</td>
                                <td class="p-2.5 font-bold text-slate-900">
                                    <?= $generatedSlip ? htmlspecialchars($generatedSlip['route_line_clean'] ?? $generatedSlip['route_line']) : 'Ligne Urbaine: Alger Centre / Bab Ezzouar / Aéroport' ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="p-2.5 bg-slate-50 font-bold text-slate-500">فترة الصلاحية القانونية:</td>
                                <td class="p-2.5 font-bold font-num text-slate-800">
                                    من <?= $generatedSlip ? htmlspecialchars($generatedSlip['issue_date']) : date('Y-m-d') ?> 
                                    إلى <?= $generatedSlip ? htmlspecialchars($generatedSlip['valid_until']) : date('Y-m-d', strtotime('+30 days')) ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Notes & Legal Declarations -->
                    <div class="p-3 bg-amber-50/50 rounded-xl border border-amber-200 text-[11px] text-amber-950 space-y-1">
                        <div class="font-bold">المرفقات والملاحظات الإدارية:</div>
                        <p class="text-slate-700">
                            <?= $generatedSlip ? htmlspecialchars($generatedSlip['notes'] ?? 'Dossier complet certifié conforme.') : 'نسخة طبق الأصل من رخصة السياقة، شهادة الكفاءة المهنية، الشهادة الطبية الدورية، ونسخة من عقد العمل قانون 90-11.' ?>
                        </p>
                        <p class="text-slate-500 text-[10px] italic">
                            يشهد مسيّر الوكالة بصحة المعلومات الواردة أعلاه ومطابقتها للشروط التنظيمية لمرسوم وزارة النقل المؤطر لنشاط سيارات الأجرة.
                        </p>
                    </div>

                    <!-- Official Stamps & Signatures Footer -->
                    <div class="grid grid-cols-2 gap-6 pt-4 border-t border-slate-200 text-center text-xs">
                        <div class="space-y-12">
                            <div class="font-bold text-slate-700">ختم وتوقيع مسيّر الوكالة (Cachet Agence)</div>
                            <div class="border-2 border-dashed border-slate-300 rounded-xl p-4 text-[10px] text-slate-400">
                                [ SARL NAGUILOU TAXI ]<br>
                                الإدارة العامة — الجزائر
                            </div>
                        </div>
                        <div class="space-y-12">
                            <div class="font-bold text-slate-700">تأشيرة مصالح مديرية النقل (Visa DTW)</div>
                            <div class="border-2 border-dashed border-slate-300 rounded-xl p-4 text-[10px] text-slate-400">
                                [ تأشيرة الاستلام والرقابة ]
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    <?php else: ?>
        <!-- TAB 2: HISTORIQUE DES BORDEREAUX -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden space-y-4 p-5">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                    <h3 class="font-extrabold text-slate-900 text-sm">Historique des Bordereaux (أرشيف جداول الإرسال السابقة)</h3>
                    <p class="text-xs text-slate-500">سجل كامل بجميع جداول الإرسال الصادرة لمديرية النقل ومصالح الرقابة</p>
                </div>
                <a href="/dashboard/slips.php?tab=generator" 
                   class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                    <span>➕</span>
                    <span>إنشاء جدول جديد</span>
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-600 font-extrabold border-b border-slate-100">
                        <tr>
                            <th class="p-3.5 whitespace-nowrap text-center">رقم الجدول (N° Bordereau)</th>
                            <th class="p-3.5 whitespace-nowrap">المركبة المعنية</th>
                            <th class="p-3.5 whitespace-nowrap">السائق المكلف</th>
                            <th class="p-3.5 whitespace-nowrap">خط السير / الموضوع</th>
                            <th class="p-3.5 whitespace-nowrap">تاريخ الإصدار</th>
                            <th class="p-3.5 whitespace-nowrap">تاريخ نهاية الصلاحية</th>
                            <th class="p-3.5 whitespace-nowrap text-center">الحالة</th>
                            <th class="p-3.5 text-center whitespace-nowrap">إجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php if (!empty($slipsHistory)): ?>
                            <?php foreach ($slipsHistory as $slip): ?>
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <span class="font-num font-extrabold text-slate-900 bg-slate-100 px-2.5 py-1 rounded-lg">
                                            <?= htmlspecialchars($slip['slip_number']) ?>
                                        </span>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <div class="font-bold text-slate-900"><?= htmlspecialchars($slip['brand'] . ' ' . $slip['model']) ?></div>
                                        <div class="text-[11px] font-num text-amber-700"><?= htmlspecialchars($slip['plate_number']) ?> (<?= htmlspecialchars($slip['door_number']) ?>)</div>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <div class="font-bold text-slate-800"><?= htmlspecialchars($slip['driver_name']) ?></div>
                                        <div class="text-[11px] font-num text-slate-400"><?= htmlspecialchars($slip['driver_phone']) ?></div>
                                    </td>
                                    <td class="p-3.5 max-w-xs truncate text-slate-600">
                                        <?= htmlspecialchars($slip['route_line']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-num text-slate-700">
                                        <?= htmlspecialchars($slip['issue_date']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-num font-bold text-slate-800">
                                        <?= htmlspecialchars($slip['valid_until']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold text-[10px] border border-emerald-200">
                                            صالح وساري (Actif)
                                        </span>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <a href="/dashboard/slips.php?tab=generator&print_id=<?= $slip['id'] ?>"
                                           class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-[11px] transition shadow-sm inline-flex items-center gap-1">
                                            <span>🖨️</span>
                                            <span>معاينة وطباعة</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="p-8 text-center text-slate-400">
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
