<?php
// dashboard/settings.php - Platform, Legal & Fiscal Settings
$pageTitle = 'إعدادات المنصة والهوية الجبائية';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();
$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
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
            'fee_vehicle_monthly' => trim($_POST['fee_vehicle_monthly'] ?? '4000.00'),
            'alert_threshold_days' => trim($_POST['alert_threshold_days'] ?? '15'),
            'douxe_ai_enabled' => isset($_POST['douxe_ai_enabled']) ? '1' : '0',
        ];

        foreach ($settingsToUpdate as $key => $val) {
            update_setting($pdo, $key, $val);
        }

        log_audit($pdo, 'settings_updated', 'settings', null, 'تحديث الإعدادات العامة والهوية الجبائية للمنصة');
        $message = 'تم حفظ وتحديث إعدادات المنصة والبيانات القانونية والجبائية بنجاح.';
        $messageType = 'success';
    }
}

$settings = $pdo ? get_all_settings($pdo) : [];
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

            <!-- 4. تسعيرة الاشتراكات وعتبات التنبيه -->
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                    <span class="text-lg">⚙️</span>
                    <h3 class="text-sm font-bold text-slate-900">تسعيرة الاشتراكات ومحرك التنبيهات</h3>
                </div>

                <div class="space-y-3 text-xs">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">رسم فضاء السائق الشهري (دج)</label>
                            <input type="number" step="100" name="fee_space_monthly" value="<?= htmlspecialchars($settings['fee_space_monthly'] ?? '1000.00') ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">رسم المركبة النشطة شهرياً (دج)</label>
                            <input type="number" step="100" name="fee_vehicle_monthly" value="<?= htmlspecialchars($settings['fee_vehicle_monthly'] ?? '4000.00') ?>" required
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">عتبة التنبيه قبل انتهاء الوثائق (أيام)</label>
                        <input type="number" name="alert_threshold_days" value="<?= htmlspecialchars($settings['alert_threshold_days'] ?? '15') ?>" required
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900">
                        <span class="text-[11px] text-slate-400 mt-1 block">يتم إطلاق تنبيه وشيك قبل هذا العدد من الأيام لانتهاء التأمين، الفحص، الساكوريومتر أو السيرغاز.</span>
                    </div>

                    <div class="pt-2">
                        <label class="flex items-center gap-2.5 cursor-pointer">
                            <input type="checkbox" name="douxe_ai_enabled" value="1" <?= ($settings['douxe_ai_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500">
                            <span class="font-bold text-slate-800">تفعيل مساعد الذكاء الاصطناعي (Douxe AI Dispatch)</span>
                        </label>
                    </div>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
