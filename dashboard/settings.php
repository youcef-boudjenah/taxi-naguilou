<?php
// dashboard/settings.php - Platform, Legal & Fiscal Settings
$pageTitle = 'إعدادات المنصة والهوية الجبائية';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$pdo = getDBConnection();
$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf_token();
    if (!is_admin()) {
        $message = 'عذراً، فقط مدير المنصة مخول بتعديل الإعدادات والبيانات الجبائية.';
        $messageType = 'error';
    } else {
        $settingsToUpdate = [
            'company_name' => trim($_POST['company_name'] ?? ''),
            'company_slogan' => trim($_POST['company_slogan'] ?? ''),
            'company_phone' => trim($_POST['company_phone'] ?? ''),
            'company_email' => trim($_POST['company_email'] ?? ''),
            'company_address' => trim($_POST['company_address'] ?? ''),
            'legal_rc' => trim($_POST['legal_rc'] ?? ''),
            'legal_nif' => trim($_POST['legal_nif'] ?? ''),
            'legal_nis' => trim($_POST['legal_nis'] ?? ''),
            'legal_article' => trim($_POST['legal_article'] ?? ''),
            'bank_ccp' => trim($_POST['bank_ccp'] ?? ''),
            'bank_rip' => trim($_POST['bank_rip'] ?? ''),
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'bank_rib' => trim($_POST['bank_rib'] ?? ''),
            'fee_space_monthly' => trim($_POST['fee_space_monthly'] ?? '1000.00'),
            'fee_vehicle_monthly' => trim($_POST['fee_vehicle_monthly'] ?? '5000.00'),
            'discount_prepay_3m' => trim($_POST['discount_prepay_3m'] ?? '500.00'),
            'discount_prepay_6m' => trim($_POST['discount_prepay_6m'] ?? '1000.00'),
            'discount_prepay_9m' => trim($_POST['discount_prepay_9m'] ?? '1500.00'),
            'discount_prepay_12m' => trim($_POST['discount_prepay_12m'] ?? '2000.00'),
            'alert_threshold_days' => trim($_POST['alert_threshold_days'] ?? '30'),
            'douxe_ai_enabled' => isset($_POST['douxe_ai_enabled']) ? '1' : '0',
        ];

        foreach ($settingsToUpdate as $key => $val) {
            update_setting($pdo, $key, $val);
        }

        if (isset($_POST['vehicle_brands_models_raw'])) {
            $rawBM = trim($_POST['vehicle_brands_models_raw']);
            $decodedBM = json_decode($rawBM, true);
            if (is_array($decodedBM)) {
                update_setting($pdo, 'vehicle_brands_models', json_encode($decodedBM, JSON_UNESCAPED_UNICODE), 'fleet');
            }
        }

        log_audit($pdo, 'settings_updated', 'settings', null, 'تحديث الإعدادات العامة والهوية الجبائية والأسطول واشتراكات SAAS للمنصة');
        $message = 'تم حفظ وتحديث إعدادات المنصة والبيانات القانونية والجبائية وماركات الأسطول واشتراكات SAAS بنجاح.';
        $messageType = 'success';
    }
}

$settings = $pdo ? get_all_settings($pdo) : [];

// Vehicle presets
$defaultPresets = [
    'Renault' => ['Symbol', 'Megane', 'Clio', 'Fluence', 'Kangoo'],
    'Dacia' => ['Logan', 'Sandero', 'Stepway', 'Duster', 'Dokker'],
    'Peugeot' => ['301', '208', '308', '2008', 'Partner', '307'],
    'Hyundai' => ['Accent', 'i10', 'i20', 'Elantra', 'Tucson'],
    'Toyota' => ['Corolla', 'Yaris', 'Avensis', 'Hilux'],
    'Citroën' => ['C-Elysée', 'C3', 'C4', 'Berlingo'],
    'Fiat' => ['Tipo', 'Punto', 'Doblo', '500'],
    'Skoda' => ['Octavia', 'Fabia', 'Rapid'],
    'Volkswagen' => ['Golf', 'Polo', 'Passat', 'Caddy'],
    'Kia' => ['Picanto', 'Rio', 'Cerato']
];
$vehiclePresetsRaw = get_setting($pdo, 'vehicle_brands_models', '');
$vehiclePresets = !empty($vehiclePresetsRaw) ? json_decode($vehiclePresetsRaw, true) : null;
if (!is_array($vehiclePresets) || empty($vehiclePresets)) {
    $vehiclePresets = $defaultPresets;
    update_setting($pdo, 'vehicle_brands_models', json_encode($defaultPresets, JSON_UNESCAPED_UNICODE), 'fleet');
}
?>

<div class="space-y-6">

    <!-- Header Banner -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-black text-slate-900">إعدادات المنصة والهوية الجبائية والبنكية</h2>
            <p class="text-xs text-slate-500 mt-1">تخصيص البيانات القانونية المطبوعة على العقود (قانون 90-11)، جداول الإرسال، وتفاصيل الحسابات البريدية والبنكية للتحصيل.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200">
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                مطابق للتنظيم التجاري والجبائي الجزائري
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-xl text-xs font-bold flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
            <span><?= $messageType === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="" class="space-y-6">
        <?= csrf_field() ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- 1. الهوية التجارية والاتصال -->
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                    <span class="text-lg">🏢</span>
                    <h3 class="text-sm font-bold text-slate-900">الهوية التجارية وبيانات الاتصال</h3>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">اسم المؤسسة أو الشركة (Raison Sociale)</label>
                        <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? 'EURL TAXI NAGUILOU Services') ?>" required
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-bold text-slate-800">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">الشعار والوصف الترويجي</label>
                        <input type="text" name="company_slogan" value="<?= htmlspecialchars($settings['company_slogan'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-700">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">رقم الهاتف الرسمي</label>
                            <input type="text" name="company_phone" value="<?= htmlspecialchars($settings['company_phone'] ?? '0550 00 00 01') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-800" dir="ltr">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">البريد الإلكتروني الرسمي</label>
                            <input type="email" name="company_email" value="<?= htmlspecialchars($settings['company_email'] ?? 'contact@taxinaguilou.dz') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-800" dir="ltr">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">العنوان والمقر الاجتماعي (Siège Social)</label>
                        <textarea name="company_address" rows="2"
                                  class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-800"><?= htmlspecialchars($settings['company_address'] ?? 'حي النصر، نهج الاستقلال، الجزائر العاصمة') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- 2. البيانات القانونية والجبائية (NIF / NIS / RC / Article) -->
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                    <span class="text-lg">⚖️</span>
                    <h3 class="text-sm font-bold text-slate-900">البيانات القانونية والجبائية (Fiscalité & Registre)</h3>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم السجل التجاري (Registre de Commerce - RC)</label>
                        <input type="text" name="legal_rc" value="<?= htmlspecialchars($settings['legal_rc'] ?? '16/00-9874521B26') ?>" required
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-900 font-bold" dir="ltr">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">الرقم التعريفي الجبائي (NIF)</label>
                            <input type="text" name="legal_nif" value="<?= htmlspecialchars($settings['legal_nif'] ?? '002616098745210') ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-900 font-bold" dir="ltr">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">الرقم التعريفي الإحصائي (NIS)</label>
                            <input type="text" name="legal_nis" value="<?= htmlspecialchars($settings['legal_nis'] ?? '00261609874') ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-900 font-bold" dir="ltr">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم المادة الضريبية (Article d'imposition)</label>
                        <input type="text" name="legal_article" value="<?= htmlspecialchars($settings['legal_article'] ?? '16200984123') ?>" required
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-900 font-bold" dir="ltr">
                    </div>

                    <p class="text-[11px] text-slate-400">تظهر هذه المعلومات تلقائياً على وصولات تسليم المركبات، الفواتير، ونماذج عقود العمل 90-11.</p>
                </div>
            </div>

            <!-- 3. بيانات الحسابات البنكية والبريدية (CCP & BaridiMob) -->
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                    <span class="text-lg">💳</span>
                    <h3 class="text-sm font-bold text-slate-900">بيانات التحصيل (بريد الجزائر والبنوك)</h3>
                </div>

                <div class="space-y-3 text-xs">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">رقم الحساب الجاري البريدي (CCP)</label>
                            <input type="text" name="bank_ccp" value="<?= htmlspecialchars($settings['bank_ccp'] ?? '0021458796') ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-900 font-bold" dir="ltr">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">مفتاح RIP (تطبيق بريدي موب BaridiMob)</label>
                            <input type="text" name="bank_rip" value="<?= htmlspecialchars($settings['bank_rip'] ?? '00799999002145879624') ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-900 font-bold" dir="ltr">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">اسم البنك والوكالة المعتمدة</label>
                        <input type="text" name="bank_name" value="<?= htmlspecialchars($settings['bank_name'] ?? 'بنك الفلاحة والتنمية الريفية (BADR)') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-800">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم الهوية البنكية الكامل (RIB)</label>
                        <input type="text" name="bank_rib" value="<?= htmlspecialchars($settings['bank_rib'] ?? '003 00016 0160004589 77') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-900 font-bold" dir="ltr">
                    </div>
                </div>
            </div>

            <!-- 4. إعدادات النظام واشتراكات المركبات (SAAS & Affiliation) -->
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4 lg:col-span-2">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2.5">
                        <span class="text-xl">📊</span>
                        <div>
                            <h3 class="text-sm font-black text-slate-900">1. إعدادات النظام واشتراكات المركبات (SAAS & Affiliation)</h3>
                            <p class="text-[11px] text-slate-500">إعدادات أسعار اشتراك المركبات وفضاء السائق مع الخصومات.</p>
                        </div>
                    </div>
                    <span class="text-xs px-2.5 py-1 bg-amber-50 text-amber-800 rounded-lg font-bold border border-amber-200">
                        التعريفات المعيارية
                    </span>
                </div>

                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 text-slate-700 font-bold border-b border-slate-200">
                            <tr>
                                <th class="p-3 w-2/3">الميزة</th>
                                <th class="p-3 w-1/3">القيمة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr class="hover:bg-slate-50/50">
                                <td class="p-3 font-bold text-slate-800">
                                    تنبيه المستندات (بالأيام)
                                    <span class="block text-[10px] text-slate-400 font-normal">عدد الأيام قبل انتهاء التأمين أو الفحص التقني لإظهار تنبيه النظام</span>
                                </td>
                                <td class="p-3">
                                    <input type="number" name="alert_threshold_days" value="<?= htmlspecialchars($settings['alert_threshold_days'] ?? '30') ?>" required
                                           class="w-full max-w-[200px] px-3 py-2 rounded-lg border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900 text-left" dir="ltr">
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/50 bg-amber-50/20">
                                <td class="p-3 font-bold text-slate-800">
                                    اشتراك المركبة شهرياً (دج/مركبة)
                                    <span class="block text-[10px] text-slate-400 font-normal">المستحق الشهري عن كل مركبة مسجلة ومرتبطة بأسطول المنصة</span>
                                </td>
                                <td class="p-3">
                                    <div class="relative max-w-[200px]">
                                        <input type="number" step="100" name="fee_vehicle_monthly" value="<?= htmlspecialchars($settings['fee_vehicle_monthly'] ?? '5000.00') ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900 text-left" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/50">
                                <td class="p-3 font-bold text-slate-800">
                                    اشتراك فضاء السائق شهرياً (دج)
                                    <span class="block text-[10px] text-slate-400 font-normal">رسم اشتراك حساب وتطبيق السائق (Espace Chauffeur)</span>
                                </td>
                                <td class="p-3">
                                    <div class="relative max-w-[200px]">
                                        <input type="number" step="100" name="fee_space_monthly" value="<?= htmlspecialchars($settings['fee_space_monthly'] ?? '1000.00') ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900 text-left" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/50">
                                <td class="p-3 font-bold text-slate-800">
                                    خصم الدفع المسبق لـ 3 أشهر / شهرياً (دج)
                                    <span class="block text-[10px] text-slate-400 font-normal">الخصم المطبق شهرياً عند تسوية 3 أشهر مسبقاً (رباعي)</span>
                                </td>
                                <td class="p-3">
                                    <div class="relative max-w-[200px]">
                                        <input type="number" step="50" name="discount_prepay_3m" value="<?= htmlspecialchars($settings['discount_prepay_3m'] ?? '500.00') ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-emerald-700 text-left" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/50">
                                <td class="p-3 font-bold text-slate-800">
                                    خصم الدفع المسبق لـ 6 أشهر / شهرياً (دج)
                                    <span class="block text-[10px] text-slate-400 font-normal">الخصم المطبق شهرياً عند تسوية نصف سنوية مسبقة</span>
                                </td>
                                <td class="p-3">
                                    <div class="relative max-w-[200px]">
                                        <input type="number" step="50" name="discount_prepay_6m" value="<?= htmlspecialchars($settings['discount_prepay_6m'] ?? '1000.00') ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-emerald-700 text-left" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/50">
                                <td class="p-3 font-bold text-slate-800">
                                    خصم الدفع المسبق لـ 9 أشهر / شهرياً (دج)
                                    <span class="block text-[10px] text-slate-400 font-normal">الخصم المطبق شهرياً عند تسوية 9 أشهر مسبقة</span>
                                </td>
                                <td class="p-3">
                                    <div class="relative max-w-[200px]">
                                        <input type="number" step="50" name="discount_prepay_9m" value="<?= htmlspecialchars($settings['discount_prepay_9m'] ?? '1500.00') ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-emerald-700 text-left" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/50">
                                <td class="p-3 font-bold text-slate-800">
                                    خصم الدفع المسبق لـ 12 شهراً / شهرياً (دج)
                                    <span class="block text-[10px] text-slate-400 font-normal">الخصم المطبق شهرياً عند تسوية سنة كاملة مسبقاً (اشتراك سنوي)</span>
                                </td>
                                <td class="p-3">
                                    <div class="relative max-w-[200px]">
                                        <input type="number" step="50" name="discount_prepay_12m" value="<?= htmlspecialchars($settings['discount_prepay_12m'] ?? '2000.00') ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-emerald-700 text-left" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="pt-2 flex items-center justify-between">
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" name="douxe_ai_enabled" value="1" <?= ($settings['douxe_ai_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                               class="w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500">
                        <span class="font-bold text-slate-800 text-xs">تفعيل مساعد الذكاء الاصطناعي (Douxe AI Dispatch)</span>
                    </label>
                </div>
            </div>

            <!-- 5. أسطول المركبات: الماركات والموديلات المعتمدة (Paramètres) -->
            <div id="fleet-presets" class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4 lg:col-span-2">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-100 gap-2">
                    <div class="flex items-center gap-2.5">
                        <span class="text-xl">🚗</span>
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">إعدادات أسطول المركبات: الماركات والموديلات المعتمدة</h3>
                            <p class="text-[11px] text-slate-500">تخصيص وإضافة صانعي المركبات وطرازات التاكسي التي تظهر للاختيار عند تسجيل سيارة جديدة.</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-bold bg-amber-50 text-amber-900 border border-amber-200 px-2.5 py-1 rounded-lg">
                        <?= count($vehiclePresets) ?> صانع معتمد
                    </span>
                </div>

                <!-- Input to add new brand and models -->
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                    <div class="sm:col-span-4">
                        <label class="block font-bold text-slate-700 text-xs mb-1">اسم الماركة أو الصانع (Marque)</label>
                        <input type="text" id="newBrandName" placeholder="مثال: Chery, Geely, DFSK..." 
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                    </div>
                    <div class="sm:col-span-6">
                        <label class="block font-bold text-slate-700 text-xs mb-1">الموديلات المعتمدة (مفصولة بفواصل)</label>
                        <input type="text" id="newBrandModels" placeholder="مثال: Tiggo 2, Tiggo 4, Arrizo 5" 
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                    </div>
                    <div class="sm:col-span-2">
                        <button type="button" onclick="addNewBrandToSettings()" 
                                class="w-full px-3 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-2xs flex items-center justify-center gap-1.5">
                            <span>➕ إضافة</span>
                        </button>
                    </div>
                </div>

                <!-- List of existing brands and models -->
                <input type="hidden" name="vehicle_brands_models_raw" id="vehicleBrandsModelsRaw" value="<?= htmlspecialchars(json_encode($vehiclePresets, JSON_UNESCAPED_UNICODE)) ?>">
                
                <div id="brandsCardsContainer" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($vehiclePresets as $bName => $bModels): ?>
                        <div class="p-3.5 rounded-xl border border-slate-200 bg-white hover:border-amber-300 transition shadow-2xs space-y-2 brand-card" data-brand="<?= htmlspecialchars($bName) ?>">
                            <div class="flex items-center justify-between">
                                <span class="font-black text-slate-900 text-xs flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    <span><?= htmlspecialchars($bName) ?></span>
                                </span>
                                <button type="button" onclick="removeBrandFromSettings('<?= htmlspecialchars($bName, ENT_QUOTES) ?>')" 
                                        class="text-slate-400 hover:text-rose-600 text-xs px-1 rounded transition" title="حذف الماركة">
                                    ✕
                                </button>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <?php foreach ($bModels as $m): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                                        <span><?= htmlspecialchars($m) ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <!-- Submit Button -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
            <button type="reset" class="px-5 py-2.5 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-700 font-bold text-xs transition">
                إلغاء التغييرات
            </button>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-sm flex items-center gap-2">
                <span>💾</span>
                <span>حفظ جميع الإعدادات</span>
            </button>
        </div>

    </form>

</div>

<script>
let currentPresets = <?= json_encode($vehiclePresets, JSON_UNESCAPED_UNICODE) ?>;

function syncPresetsToHiddenInput() {
    const hidden = document.getElementById('vehicleBrandsModelsRaw');
    if (hidden) {
        hidden.value = JSON.stringify(currentPresets);
    }
}

function addNewBrandToSettings() {
    const nameInput = document.getElementById('newBrandName');
    const modelsInput = document.getElementById('newBrandModels');
    const brand = nameInput.value.trim();
    const modelsRaw = modelsInput.value.trim();

    if (!brand) {
        alert('يرجى كتابة اسم الماركة أو الصانع.');
        nameInput.focus();
        return;
    }

    const modelsList = modelsRaw ? modelsRaw.split(',').map(m => m.trim()).filter(m => m.length > 0) : [];
    
    currentPresets[brand] = modelsList;
    syncPresetsToHiddenInput();

    // Append card to DOM
    const container = document.getElementById('brandsCardsContainer');
    const newCard = document.createElement('div');
    newCard.className = 'p-3.5 rounded-xl border border-amber-300 bg-amber-50/30 transition shadow-2xs space-y-2 brand-card';
    newCard.setAttribute('data-brand', brand);
    newCard.innerHTML = `
        <div class="flex items-center justify-between">
            <span class="font-black text-slate-900 text-xs flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                <span>${brand}</span>
            </span>
            <button type="button" onclick="removeBrandFromSettings('${brand}')" 
                    class="text-slate-400 hover:text-rose-600 text-xs px-1 rounded transition" title="حذف الماركة">
                ✕
            </button>
        </div>
        <div class="flex flex-wrap gap-1">
            ${modelsList.map(m => `
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-white text-slate-800 border border-amber-200">
                    <span>${m}</span>
                </span>
            `).join('')}
        </div>
    `;
    container.prepend(newCard);

    nameInput.value = '';
    modelsInput.value = '';
}

function removeBrandFromSettings(brand) {
    if (confirm(`هل أنت متأكد من رغبتك في حذف ماركة "${brand}" من إعدادات الأسطول؟`)) {
        delete currentPresets[brand];
        syncPresetsToHiddenInput();
        const card = document.querySelector(`.brand-card[data-brand="${brand}"]`);
        if (card) card.remove();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
