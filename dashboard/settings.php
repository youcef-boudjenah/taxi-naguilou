<?php
// dashboard/settings.php - Platform, Legal & Fiscal Settings
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = getDBConnection();
$message = null;
$messageType = null;

// 1. Handle Backup Export (before header to prevent output corruption)
if (isset($_GET['action']) && $_GET['action'] === 'export_backup' && $pdo) {
    require_once __DIR__ . '/../includes/backup_importer.php';
    $dump = export_full_backup($pdo);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="taxi_naguilou_backup_' . date('Y-m-d_His') . '.json"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Handle Backup Upload & Restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_backup' && $pdo) {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط مدير المنصة مخول برفع واستعادة النسخ الاحتياطية.');
        redirect('/dashboard/settings.php');
        exit;
    }

    require_once __DIR__ . '/../includes/backup_importer.php';

    $payload = null;
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['backup_file']['tmp_name'];
        $raw = file_get_contents($tmp);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $payload = json_decode($raw, true);
    } elseif (!empty($_POST['backup_json_text'])) {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', trim($_POST['backup_json_text']));
        $payload = json_decode($raw, true);
    }

    if (!is_array($payload)) {
        set_flash('error', 'الملف المرفوع ليس ملف JSON صالحاً أو تالف.');
        redirect('/dashboard/settings.php#backup-section');
        exit;
    }

    $result = import_full_backup($pdo, $payload);
    if ($result['success']) {
        $c = $result['counts'];
        $msg = "تم استعادة النسخة الاحتياطية بنجاح! (" . 
            "{$c['users']} مستخدم، {$c['vehicles']} مركبة، {$c['contracts']} عقد، {$c['subscriptions']} اشتراك، {$c['slips']} جدول إرسال)";
        log_audit($pdo, 'backup_restored', 'system', null, $msg);
        set_flash('success', $msg);
    } else {
        set_flash('error', $result['message']);
    }
    redirect('/dashboard/settings.php#backup-section');
    exit;
}

// 3. Handle Import Scraped Live Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_scraped_live' && $pdo) {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط مدير المنصة مخول باستيراد البيانات.');
        redirect('/dashboard/settings.php');
        exit;
    }

    require_once __DIR__ . '/../includes/backup_importer.php';

    $payload = null;
    $liveBackupPath = __DIR__ . '/../uploads/backups/scraped_backup_live.json';
    if (file_exists($liveBackupPath)) {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($liveBackupPath));
        $payload = json_decode($raw, true);
    }

    if (!is_array($payload) || empty($payload['tables']['users'])) {
        $dir = 'C:/Users/boudj/.gemini/antigravity-ide/brain/3fa97054-7e8a-4df1-995c-d96c125949a3/scratch/scraped_api_data';
        if (is_dir($dir)) {
            $readJson = function($path) {
                if (!file_exists($path)) return [];
                $content = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($path));
                return json_decode($content, true) ?: [];
            };
            $payload = [
                'users'              => $readJson("$dir/users.json"),
                'vehicles'           => $readJson("$dir/vehicles.json"),
                'contracts'          => $readJson("$dir/contracts.json"),
                'subscriptions'      => $readJson("$dir/subscriptions.json"),
                'transmission_slips' => $readJson("$dir/transmission-slips.json"),
                'notifications'      => $readJson("$dir/notifications.json"),
                'settings'           => $readJson("$dir/tenant_settings.json"),
            ];
        }
    }

    if (!is_array($payload) || empty($payload)) {
        set_flash('error', 'ملف البيانات المستخرجة غير متوفر حالياً.');
        redirect('/dashboard/settings.php#backup-section');
        exit;
    }

    $result = import_full_backup($pdo, $payload);
    if ($result['success']) {
        $c = $result['counts'];
        $msg = "تم استيراد ومعالجة بيانات الموقع القديم المباشرة بنجاح! (" . 
            "{$c['users']} مستخدم/سائق، {$c['vehicles']} مركبة، {$c['contracts']} عقد، {$c['subscriptions']} اشتراك، {$c['notifications']} إشعار)";
        log_audit($pdo, 'live_data_imported', 'system', null, $msg);
        set_flash('success', $msg);
    } else {
        set_flash('error', $result['message']);
    }
    redirect('/dashboard/settings.php#backup-section');
    exit;
}

$pageTitle = 'إعدادات المنصة والهوية الجبائية';
require_once __DIR__ . '/../includes/header.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'wipe_all_data' && $pdo) {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط مدير المنصة مخول بتنفيذ عمليات حذف وتهيئة البيانات.');
        redirect('/dashboard/settings.php');
        exit;
    }

    $confirmText = trim($_POST['confirm_delete'] ?? '');
    if ($confirmText !== 'تأكيد' && strtoupper($confirmText) !== 'DELETE') {
        set_flash('error', 'كلمة التأكيد غير صحيحة. يرجى كتابة "تأكيد" أو "DELETE" لتأكيد حذف البيانات.');
        redirect('/dashboard/settings.php#danger-zone');
        exit;
    }

    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

        $tablesToWipe = [
            'traveler_passengers',
            'traveler_reports',
            'cnas_declarations',
            'notifications',
            'leads',
            'driver_subscriptions',
            'insurance_policies',
            'driver_schedules',
            'night_watch',
            'versements',
            'driver_debts',
            'transmission_slips',
            'billings',
            'leaves',
            'contracts',
            'vehicles',
            'audit_logs',
        ];

        foreach ($tablesToWipe as $table) {
            try {
                $pdo->exec("DELETE FROM `$table`");
                $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
            } catch (\Throwable $e) {
                // Ignore gracefully if table does not exist
            }
        }

        // Clean non-admin users (preserve current admin and all super_admin / admin accounts)
        $currentUserId = intval($_SESSION['user_id'] ?? 0);
        $pdo->prepare("DELETE FROM users WHERE role NOT IN ('super_admin', 'admin') AND id != ?")->execute([$currentUserId]);

        // Clean uploaded files if applicable
        $uploadDirs = [
            __DIR__ . '/../uploads/drivers',
            __DIR__ . '/../uploads/slips',
            __DIR__ . '/../uploads/scans',
            __DIR__ . '/../uploads/cars',
            __DIR__ . '/../uploads/contracts',
        ];
        foreach ($uploadDirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*');
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (is_file($file) && !str_contains($file, 'index.html')) {
                            @unlink($file);
                        }
                    }
                }
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        log_audit($pdo, 'factory_reset', 'system', null, 'إعادة ضبط شاملة وحذف وتصفير جميع بيانات الأسطول والسائقين والعمليات');
        set_flash('success', 'تم حذف وتصفير جميع بيانات النظام الميدانية والتشغيلية بنجاح، مع الحفاظ على حسابات الإدارة.');
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        set_flash('error', 'حدث خطأ أثناء حذف البيانات: ' . $e->getMessage());
    }

    redirect('/dashboard/settings.php');
    exit;
}

// 4. Handle Instant AJAX Toggle Discount Offer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_discount_offer' && $pdo) {
    require_csrf_token();
    if (!is_admin()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'عذراً، فقط مدير المنصة مخول بتعديل إعدادات العروض والخصومات.']);
        exit;
    }
    $offer = trim($_POST['offer'] ?? '');
    $enabled = ($_POST['enabled'] ?? '1') === '1' ? '1' : '0';
    $validOffers = ['3m', '6m', '9m', '12m'];
    if (in_array($offer, $validOffers)) {
        $settingKey = "discount_prepay_{$offer}_enabled";
        update_setting($pdo, $settingKey, $enabled, 'billing');
        $labelOffer = [
            '3m' => '3 أشهر',
            '6m' => '6 أشهر',
            '9m' => '9 أشهر',
            '12m' => '12 شهراً'
        ][$offer] ?? $offer;
        $statusText = $enabled === '1' ? 'تفعيل' : 'تعطيل وإيقاف';
        log_audit($pdo, 'discount_offer_toggled', 'settings', null, "{$statusText} عرض خصم الدفع المسبق لـ {$labelOffer}");
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'offer' => $offer,
            'enabled' => $enabled,
            'message' => $enabled === '1' ? "تم تفعيل عرض الخصم لـ {$labelOffer} بنجاح." : "تم تعطيل وإيقاف عرض الخصم لـ {$labelOffer}."
        ]);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'بيانات العرض غير صالحة.']);
    exit;
}

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
            'company_wilaya' => trim($_POST['company_wilaya'] ?? ''),
            'legal_rc' => trim($_POST['legal_rc'] ?? ''),
            'legal_nif' => trim($_POST['legal_nif'] ?? ''),
            'legal_nis' => trim($_POST['legal_nis'] ?? ''),
            'legal_article' => trim($_POST['legal_article'] ?? ''),
            'bank_ccp' => trim($_POST['bank_ccp'] ?? ''),
            'bank_rip' => trim($_POST['bank_rip'] ?? ''),
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'bank_rib' => trim($_POST['bank_rib'] ?? ''),
            'baridimob_number' => trim($_POST['baridimob_number'] ?? ''),
            'bank_account_holder' => trim($_POST['bank_account_holder'] ?? ''),
            'fee_space_monthly' => trim($_POST['fee_space_monthly'] ?? '1000.00'),
            'fee_vehicle_monthly' => trim($_POST['fee_vehicle_monthly'] ?? '5000.00'),
            'discount_prepay_3m' => trim($_POST['discount_prepay_3m'] ?? '500.00'),
            'discount_prepay_6m' => trim($_POST['discount_prepay_6m'] ?? '1000.00'),
            'discount_prepay_9m' => trim($_POST['discount_prepay_9m'] ?? '1500.00'),
            'discount_prepay_12m' => trim($_POST['discount_prepay_12m'] ?? '2000.00'),
            'discount_prepay_3m_enabled' => (isset($_POST['discount_prepay_3m_enabled']) && $_POST['discount_prepay_3m_enabled'] === '1') ? '1' : '0',
            'discount_prepay_6m_enabled' => (isset($_POST['discount_prepay_6m_enabled']) && $_POST['discount_prepay_6m_enabled'] === '1') ? '1' : '0',
            'discount_prepay_9m_enabled' => (isset($_POST['discount_prepay_9m_enabled']) && $_POST['discount_prepay_9m_enabled'] === '1') ? '1' : '0',
            'discount_prepay_12m_enabled' => (isset($_POST['discount_prepay_12m_enabled']) && $_POST['discount_prepay_12m_enabled'] === '1') ? '1' : '0',
            'alert_threshold_days' => trim($_POST['alert_threshold_days'] ?? '30'),
            'douxe_ai_enabled' => isset($_POST['douxe_ai_enabled']) ? '1' : '0',
        ];

        foreach ($settingsToUpdate as $key => $val) {
            update_setting($pdo, $key, $val);
        }

        // Synchronize admin user profile (wilaya, address, phone) so user records match company settings
        $adminAddress = trim($_POST['company_address'] ?? '');
        $adminWilaya = trim($_POST['company_wilaya'] ?? '');
        if (empty($adminWilaya) && !empty($adminAddress)) {
            $adminWilaya = clean_wilaya_name($adminAddress, '');
        }
        $adminPhone = trim($_POST['company_phone'] ?? '');

        $currentUserId = intval($_SESSION['user_id'] ?? 0);
        if ($currentUserId > 0) {
            $stmtUserUp = $pdo->prepare("UPDATE users SET phone = COALESCE(NULLIF(?, ''), phone), address = COALESCE(NULLIF(?, ''), address), wilaya = COALESCE(NULLIF(?, ''), wilaya) WHERE id = ?");
            $stmtUserUp->execute([$adminPhone, $adminAddress, $adminWilaya, $currentUserId]);
            if (!empty($adminWilaya)) {
                $_SESSION['user_wilaya'] = $adminWilaya;
            }
        }
        if (!empty($adminWilaya)) {
            $pdo->prepare("UPDATE users SET wilaya = ? WHERE role IN ('super_admin', 'admin') AND (wilaya = '16 - الجزائر العاصمة' OR wilaya IS NULL OR wilaya = '')")->execute([$adminWilaya]);
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

$wipeStats = [
    'vehicles'  => 0,
    'drivers'   => 0,
    'contracts' => 0,
    'slips'     => 0,
    'reports'   => 0,
    'debts'     => 0,
];
$backupStats = [
    'users'         => 0,
    'vehicles'      => 0,
    'contracts'     => 0,
    'subscriptions' => 0,
    'slips'         => 0,
    'notifications' => 0,
];
if ($pdo) {
    try {
        $wipeStats['vehicles']  = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
        $wipeStats['drivers']   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver'")->fetchColumn();
        $wipeStats['contracts'] = (int)$pdo->query("SELECT COUNT(*) FROM contracts")->fetchColumn();
        $wipeStats['slips']     = (int)$pdo->query("SELECT COUNT(*) FROM transmission_slips")->fetchColumn();
        $wipeStats['reports']   = (int)$pdo->query("SELECT COUNT(*) FROM traveler_reports")->fetchColumn();
        $wipeStats['debts']     = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts")->fetchColumn();

        $backupStats['users']         = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $backupStats['vehicles']      = $wipeStats['vehicles'];
        $backupStats['contracts']     = $wipeStats['contracts'];
        $backupStats['subscriptions'] = (int)$pdo->query("SELECT COUNT(*) FROM driver_subscriptions")->fetchColumn();
        $backupStats['slips']         = $wipeStats['slips'];
        $backupStats['notifications'] = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    } catch (\Throwable $e) {}
}

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

$pageTitle = 'إعدادات المنصة والهوية | Paramètres & SAAS';
require_once __DIR__ . '/../includes/header.php';
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

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">العنوان والمقر الاجتماعي (Siège Social)</label>
                            <textarea name="company_address" rows="2"
                                      class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-800" placeholder="مثال: حي 200 مسكن، أدرار"><?= htmlspecialchars($settings['company_address'] ?? 'أدرار') ?></textarea>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">ولاية المقر الاجتماعي / مديرية النقل (DTW)</label>
                            <input type="text" name="company_wilaya" value="<?= htmlspecialchars($settings['company_wilaya'] ?? '01 - أدرار') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-800 font-semibold" placeholder="مثال: 01 - أدرار أو أدرار">
                            <span class="text-[10px] text-slate-400 mt-1 block">تحدد ولاية مديرية النقل (DTW) الرسمية التي تصدر وتعتمد عليها العقود ومحاضر الفسخ</span>
                        </div>
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

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">رقم هاتف بريدي موب (BaridiMob Mobile)</label>
                            <input type="text" name="baridimob_number" value="<?= htmlspecialchars($settings['baridimob_number'] ?? '0663.55.00.42') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num text-slate-900 font-bold" dir="ltr">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">اسم صاحب الحساب المعتمد (Titulaire du compte)</label>
                            <input type="text" name="bank_account_holder" value="<?= htmlspecialchars($settings['bank_account_holder'] ?? 'EURL TAXI NAGUILOU Services') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 font-bold">
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

                <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="p-3 w-1/2">الميزة أو العرض</th>
                                <th class="p-3 w-1/4 text-center">حالة العرض</th>
                                <th class="p-3 w-1/4 text-left">القيمة / الخصم المطبق</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="p-3 font-bold text-slate-800 dark:text-slate-200">
                                    تنبيه المستندات (بالأيام)
                                    <span class="block text-[10px] text-slate-400 dark:text-slate-500 font-normal">عدد الأيام قبل انتهاء التأمين أو الفحص التقني لإظهار تنبيه النظام</span>
                                </td>
                                <td class="p-3 text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700">إعداد أساسي</span>
                                </td>
                                <td class="p-3 text-left">
                                    <input type="number" name="alert_threshold_days" value="<?= htmlspecialchars($settings['alert_threshold_days'] ?? '30') ?>" required
                                           class="w-full max-w-[200px] px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900 dark:text-white text-left" dir="ltr">
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 bg-amber-50/20 dark:bg-amber-950/20">
                                <td class="p-3 font-bold text-slate-800 dark:text-slate-200">
                                    اشتراك المركبة شهرياً (دج/مركبة)
                                    <span class="block text-[10px] text-slate-400 dark:text-slate-500 font-normal">المستحق الشهري عن كل مركبة مسجلة ومرتبطة بأسطول المنصة</span>
                                </td>
                                <td class="p-3 text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700">اشتراك ثابت</span>
                                </td>
                                <td class="p-3 text-left">
                                    <div class="relative max-w-[200px]">
                                        <input type="number" step="100" name="fee_vehicle_monthly" value="<?= htmlspecialchars($settings['fee_vehicle_monthly'] ?? '5000.00') ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900 dark:text-white text-left" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="p-3 font-bold text-slate-800 dark:text-slate-200">
                                    اشتراك فضاء السائق شهرياً (دج)
                                    <span class="block text-[10px] text-slate-400 dark:text-slate-500 font-normal">رسم اشتراك حساب وتطبيق السائق (Espace Chauffeur)</span>
                                </td>
                                <td class="p-3 text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700">اشتراك ثابت</span>
                                </td>
                                <td class="p-3 text-left">
                                    <div class="relative max-w-[200px]">
                                        <input type="number" step="100" name="fee_space_monthly" value="<?= htmlspecialchars($settings['fee_space_monthly'] ?? '1000.00') ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-slate-900 dark:text-white text-left" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                </td>
                            </tr>
                            <?php
                            $discountOffers = [
                                '3m' => [
                                    'title' => 'خصم الدفع المسبق لـ 3 أشهر / شهرياً (دج)',
                                    'subtitle' => 'الخصم المطبق شهرياً عند تسوية 3 أشهر مسبقاً (رباعي)',
                                    'default_amount' => '500.00',
                                    'key' => 'discount_prepay_3m',
                                    'enabled_key' => 'discount_prepay_3m_enabled'
                                ],
                                '6m' => [
                                    'title' => 'خصم الدفع المسبق لـ 6 أشهر / شهرياً (دج)',
                                    'subtitle' => 'الخصم المطبق شهرياً عند تسوية نصف سنوية مسبقة',
                                    'default_amount' => '1000.00',
                                    'key' => 'discount_prepay_6m',
                                    'enabled_key' => 'discount_prepay_6m_enabled'
                                ],
                                '9m' => [
                                    'title' => 'خصم الدفع المسبق لـ 9 أشهر / شهرياً (دج)',
                                    'subtitle' => 'الخصم المطبق شهرياً عند تسوية 9 أشهر مسبقة',
                                    'default_amount' => '1500.00',
                                    'key' => 'discount_prepay_9m',
                                    'enabled_key' => 'discount_prepay_9m_enabled'
                                ],
                                '12m' => [
                                    'title' => 'خصم الدفع المسبق لـ 12 شهراً / شهرياً (دج)',
                                    'subtitle' => 'الخصم المطبق شهرياً عند تسوية سنة كاملة مسبقاً (اشتراك سنوي)',
                                    'default_amount' => '2000.00',
                                    'key' => 'discount_prepay_12m',
                                    'enabled_key' => 'discount_prepay_12m_enabled'
                                ],
                            ];
                            foreach ($discountOffers as $slug => $offer):
                                $isEnabled = ($settings[$offer['enabled_key']] ?? '1') === '1';
                                $val = $settings[$offer['key']] ?? $offer['default_amount'];
                            ?>
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition <?= $isEnabled ? 'bg-emerald-50/15 dark:bg-emerald-950/20' : 'bg-slate-50/40 dark:bg-slate-900/40' ?>" id="row_offer_<?= $slug ?>">
                                <td class="p-3 font-bold text-slate-800 dark:text-slate-200">
                                    <div class="flex items-center gap-2">
                                        <span><?= htmlspecialchars($offer['title']) ?></span>
                                        <span id="badge_status_<?= $slug ?>" class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $isEnabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300' ?>">
                                            <?= $isEnabled ? 'متاح' : 'معطّل' ?>
                                        </span>
                                    </div>
                                    <span class="block text-[10px] text-slate-400 dark:text-slate-500 font-normal mt-0.5"><?= htmlspecialchars($offer['subtitle']) ?></span>
                                </td>
                                <td class="p-3 text-center whitespace-nowrap">
                                    <!-- Hidden input for standard form submission -->
                                    <input type="hidden" name="<?= $offer['enabled_key'] ?>" id="hidden_<?= $offer['enabled_key'] ?>" value="<?= $isEnabled ? '1' : '0' ?>">
                                    
                                    <!-- Interactive Toggle Button -->
                                    <button type="button" 
                                            onclick="toggleDiscountOffer('<?= $slug ?>')" 
                                            id="btn_toggle_<?= $slug ?>" 
                                            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-bold transition shadow-2xs border cursor-pointer select-none <?= $isEnabled ? 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800' : 'bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 border-slate-300 dark:border-slate-700' ?>"
                                            title="<?= $isEnabled ? 'انقر لتعطيل وإيقاف هذا العرض' : 'انقر لتفعيل هذا العرض' ?>">
                                        <span class="w-8 h-4.5 rounded-full p-0.5 transition flex items-center <?= $isEnabled ? 'bg-emerald-500 justify-end' : 'bg-slate-300 dark:bg-slate-600 justify-start' ?>" id="switch_track_<?= $slug ?>">
                                            <span class="w-3.5 h-3.5 rounded-full bg-white shadow-xs transition" id="switch_thumb_<?= $slug ?>"></span>
                                        </span>
                                        <span id="label_toggle_<?= $slug ?>"><?= $isEnabled ? 'مفعّل' : 'معطّل' ?></span>
                                    </button>
                                    <span id="saved_feedback_<?= $slug ?>" class="hidden text-[10px] text-emerald-600 dark:text-emerald-400 font-bold block mt-1">✓ تم التحديث</span>
                                </td>
                                <td class="p-3 text-left">
                                    <div class="relative max-w-[200px]" id="wrapper_input_<?= $slug ?>">
                                        <input type="number" step="50" name="<?= $offer['key'] ?>" id="input_<?= $offer['key'] ?>" 
                                               value="<?= htmlspecialchars($val) ?>" required
                                               class="w-full px-3 py-2 pr-12 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num font-bold text-emerald-700 dark:text-emerald-400 text-left transition <?= $isEnabled ? '' : 'opacity-40 pointer-events-none bg-slate-100 dark:bg-slate-900/60' ?>" dir="ltr">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 pointer-events-none">دج</span>
                                    </div>
                                    <span id="text_status_hint_<?= $slug ?>" class="text-[10px] font-normal block mt-1 <?= $isEnabled ? 'text-slate-400 dark:text-slate-500' : 'text-rose-500 dark:text-rose-400 font-bold' ?>">
                                        <?= $isEnabled ? 'عرض مفعّل ونشط' : 'العرض موقف حالياً' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
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

    <!-- ==============================================
         SECTION 4.5: النسخ الاحتياطي واستعادة ورفع البيانات (Backup & Restore Zone)
         ============================================== -->
    <div id="backup-section" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden space-y-0">
        
        <!-- Header Banner -->
        <div class="p-5 sm:p-6 bg-gradient-to-l from-amber-50/60 via-white to-slate-50/40 dark:from-amber-950/20 dark:via-slate-900 dark:to-slate-900 border-b border-slate-200 dark:border-slate-800">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-3.5">
                    <div class="w-11 h-11 rounded-2xl bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 flex items-center justify-center shrink-0 shadow-2xs">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-black text-slate-900 dark:text-white text-base">النسخ الاحتياطي واستعادة ورفع البيانات (Backup & Migration)</h3>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-900/50 text-amber-800 dark:text-amber-300 font-mono">JSON Native</span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                            تصدير قاعدة البيانات بالكامل بصيغة JSON، رفع واستعادة النسخ الاحتياطية الميدانية، أو مزامنة بيانات الموقع القديم (taxi-naguilou.com) بنقرة واحدة.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <a href="?action=export_backup" 
                       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white dark:bg-white dark:hover:bg-slate-100 dark:text-slate-900 font-bold text-xs transition shadow-xs">
                        <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        <span>تصدير نسخة احتياطية (JSON)</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Live DB Counts Bar -->
        <div class="p-4 sm:p-5 bg-slate-50/60 dark:bg-slate-900/40 border-b border-slate-100 dark:border-slate-800/80">
            <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 block mb-2.5">إحصائيات السجلات المحفوظة حالياً في قاعدة البيانات المحلية:</span>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5">
                <div class="p-2.5 bg-white dark:bg-slate-800/80 rounded-xl border border-slate-200 dark:border-slate-700/80 text-center">
                    <span class="text-[10px] text-slate-400 block font-semibold">المستخدمون والسائقون</span>
                    <span class="text-sm font-black text-slate-900 dark:text-white font-num"><?= $backupStats['users'] ?></span>
                </div>
                <div class="p-2.5 bg-white dark:bg-slate-800/80 rounded-xl border border-slate-200 dark:border-slate-700/80 text-center">
                    <span class="text-[10px] text-slate-400 block font-semibold">مركبات الأسطول</span>
                    <span class="text-sm font-black text-slate-900 dark:text-white font-num"><?= $backupStats['vehicles'] ?></span>
                </div>
                <div class="p-2.5 bg-white dark:bg-slate-800/80 rounded-xl border border-slate-200 dark:border-slate-700/80 text-center">
                    <span class="text-[10px] text-slate-400 block font-semibold">عقود العمل (90-11)</span>
                    <span class="text-sm font-black text-slate-900 dark:text-white font-num"><?= $backupStats['contracts'] ?></span>
                </div>
                <div class="p-2.5 bg-white dark:bg-slate-800/80 rounded-xl border border-slate-200 dark:border-slate-700/80 text-center">
                    <span class="text-[10px] text-slate-400 block font-semibold">الاشتراكات الشهرية</span>
                    <span class="text-sm font-black text-slate-900 dark:text-white font-num"><?= $backupStats['subscriptions'] ?></span>
                </div>
                <div class="p-2.5 bg-white dark:bg-slate-800/80 rounded-xl border border-slate-200 dark:border-slate-700/80 text-center">
                    <span class="text-[10px] text-slate-400 block font-semibold">جداول الإرسال</span>
                    <span class="text-sm font-black text-slate-900 dark:text-white font-num"><?= $backupStats['slips'] ?></span>
                </div>
                <div class="p-2.5 bg-white dark:bg-slate-800/80 rounded-xl border border-slate-200 dark:border-slate-700/80 text-center">
                    <span class="text-[10px] text-slate-400 block font-semibold">مركز التنبيهات</span>
                    <span class="text-sm font-black text-slate-900 dark:text-white font-num"><?= $backupStats['notifications'] ?></span>
                </div>
            </div>
        </div>

        <!-- Main Backup Grid -->
        <div class="p-5 sm:p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Card A: رفع واستعادة نسخة احتياطية (Upload & Restore) -->
            <div class="p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-800/50 space-y-4 shadow-2xs">
                <div class="flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <span class="text-lg">📤</span>
                    <div>
                        <h4 class="text-xs font-black text-slate-900 dark:text-white">رفع واستعادة نسخة احتياطية (Upload Backup JSON)</h4>
                        <p class="text-[11px] text-slate-400 mt-0.5">اختر ملف JSON للنسخة الاحتياطية لدمج أو استعادة السجلات</p>
                    </div>
                </div>

                <form method="POST" action="" enctype="multipart/form-data" class="space-y-4" onsubmit="return confirm('تأكيد: هل ترغب في بدء استعادة البيانات من الملف المرفوع ودمجها في النظام؟');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_backup">

                    <!-- File Drag Drop Area -->
                    <div class="border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl p-5 text-center hover:border-amber-400 dark:hover:border-amber-500 transition cursor-pointer bg-slate-50/50 dark:bg-slate-900/30"
                         onclick="document.getElementById('backupFileInput').click()">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 flex items-center justify-center mx-auto mb-2">
                            📁
                        </div>
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-200 block" id="selectedBackupFileName">
                            اضغط لاختيار ملف النسخة الاحتياطية (.json)
                        </span>
                        <span class="text-[10px] text-slate-400 mt-1 block">يدعم ملفات JSON الصادرة من النظام أو ملفات استخراج الموقع القديم</span>
                        <input type="file" id="backupFileInput" name="backup_file" accept=".json" class="hidden" 
                               onchange="if(this.files[0]) document.getElementById('selectedBackupFileName').textContent = '📄 ' + this.files[0].name + ' (' + Math.round(this.files[0].size/1024) + ' KB)'">
                    </div>

                    <!-- Collapsible JSON text paste -->
                    <details class="text-[11px] text-slate-500">
                        <summary class="font-bold text-slate-600 dark:text-slate-400 cursor-pointer hover:text-amber-600">أو الصق محتوى JSON يدوياً هنا...</summary>
                        <textarea name="backup_json_text" rows="4" placeholder='{"tables": {...}} أو {"users": [...]}' 
                                  class="w-full mt-2 p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-[10px] font-mono text-slate-800 dark:text-slate-200 resize-none" dir="ltr"></textarea>
                    </details>

                    <button type="submit" 
                            class="w-full py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-xs flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                        </svg>
                        <span>بدء رفع واستعادة النسخة الاحتياطية</span>
                    </button>
                </form>
            </div>

            <!-- Card B: مزامنة بيانات الموقع القديم المباشرة & روابط سريعة -->
            <div class="p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-800/50 space-y-4 shadow-2xs flex flex-col justify-between">
                <div class="space-y-3">
                    <div class="flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                            </svg>
                        </div>
                        <div>
                            <h4 class="text-xs font-black text-slate-900 dark:text-white">مزامنة بيانات الموقع القديم (taxi-naguilou.com)</h4>
                            <p class="text-[11px] text-slate-400 mt-0.5">استيراد مباشر للبيانات المستخرجة من واجهة الخادم السحابي Live API</p>
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800/40 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center gap-1.5 text-xs font-black text-emerald-900 dark:text-emerald-300">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                حزمة البيانات المباشرة جاهزة على الخادم
                            </span>
                            <span class="text-[10px] font-mono px-2 py-0.5 bg-emerald-100 dark:bg-emerald-900/50 text-emerald-800 dark:text-emerald-200 rounded font-bold">Live Scraped</span>
                        </div>
                        <p class="text-[11px] text-emerald-800/80 dark:text-emerald-400 leading-relaxed">
                            تم سحب وتنظيف جميع البيانات الرسمية: <strong class="font-bold">99 مستخدم/سائق</strong>، <strong class="font-bold">94 سيارة أجرة</strong>، <strong class="font-bold">90 عقد قانون 90-11</strong>، <strong class="font-bold">95 اشتراك شهري</strong>، و<strong class="font-bold">100 إشعار</strong>.
                        </p>
                    </div>

                    <form method="POST" action="<?= url('/dashboard/settings.php') ?>" onsubmit="return confirm('تأكيد: هل ترغب في مزامنة واستيراد جميع بيانات الموقع القديم إلى هذه المنصة؟');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="import_scraped_live">
                        <button type="submit" 
                                class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black text-xs transition shadow-xs flex items-center justify-center gap-2 cursor-pointer">
                            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                            </svg>
                            <span>استيراد وتحديث بيانات الموقع القديم بنقرة واحدة</span>
                        </button>
                    </form>
                </div>

                <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs">
                    <span class="text-[11px] text-slate-400 font-semibold">هل ترغب في استيراد ملفات CSV مخصصة أو جداول معينة؟</span>
                    <a href="<?= url('/dashboard/import.php') ?>" 
                       class="font-bold text-amber-600 hover:text-amber-700 dark:text-amber-400 inline-flex items-center gap-1">
                        <span>أداة الاستيراد المتقدم</span>
                        <span>←</span>
                    </a>
                </div>
            </div>

        </div>

    </div>

    <!-- ==============================================
         SECTION 5: منطقة العمليات الحساسة • حذف وتصفير البيانات (Danger Zone)
         ============================================== -->
    <div id="danger-zone" class="bg-white rounded-2xl border-2 border-rose-200 dark:border-rose-900/60 shadow-xs overflow-hidden">
        
        <div class="p-5 sm:p-6 bg-gradient-to-l from-rose-50/70 to-white dark:from-rose-950/20 dark:to-slate-900 border-b border-rose-100 dark:border-rose-900/40">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-100 dark:bg-rose-900/50 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-black text-rose-950 dark:text-rose-300 text-base">منطقة العمليات الحساسة • حذف وتصفير جميع البيانات</h3>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-600 text-white font-mono">DANGER ZONE</span>
                        </div>
                        <p class="text-xs text-rose-700/80 dark:text-rose-400 mt-1 leading-relaxed">
                            يتيح هذا الإجراء لمدير المنصة حذف وتصفير جميع سجلات الأسطول والسائقين، العقود 90-11، جداول الإرسال، محاضر المسافرين، والديون دفعة واحدة لتنظيف النظام أو بدء دورة جديدة. حسابات الإدارة تبقى محفوظة ولن تحذف.
                        </p>
                    </div>
                </div>

                <button type="button" onclick="openWipeModal()" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black text-xs transition shadow-sm shrink-0">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                    </svg>
                    <span>حذف جميع البيانات الآن...</span>
                </button>
            </div>
        </div>

        <!-- Live Data Summary to be Purged -->
        <div class="p-5 sm:p-6 bg-slate-50/50 dark:bg-slate-900/50">
            <span class="text-[11px] font-bold text-slate-500 block mb-3">السجلات الميدانية الحالية التي سيتم حذفها وتصفيرها بالكامل:</span>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div class="p-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-center">
                    <span class="text-[11px] text-slate-400 block font-semibold">سيارات الأسطول</span>
                    <span class="text-base font-black text-slate-900 dark:text-white font-num"><?= $wipeStats['vehicles'] ?></span>
                </div>
                <div class="p-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-center">
                    <span class="text-[11px] text-slate-400 block font-semibold">السائقون المسجلون</span>
                    <span class="text-base font-black text-slate-900 dark:text-white font-num"><?= $wipeStats['drivers'] ?></span>
                </div>
                <div class="p-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-center">
                    <span class="text-[11px] text-slate-400 block font-semibold">عقود العمل (90-11)</span>
                    <span class="text-base font-black text-slate-900 dark:text-white font-num"><?= $wipeStats['contracts'] ?></span>
                </div>
                <div class="p-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-center">
                    <span class="text-[11px] text-slate-400 block font-semibold">جداول الإرسال</span>
                    <span class="text-base font-black text-slate-900 dark:text-white font-num"><?= $wipeStats['slips'] ?></span>
                </div>
                <div class="p-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-center">
                    <span class="text-[11px] text-slate-400 block font-semibold">محاضر المسافرين</span>
                    <span class="text-base font-black text-slate-900 dark:text-white font-num"><?= $wipeStats['reports'] ?></span>
                </div>
                <div class="p-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-center">
                    <span class="text-[11px] text-slate-400 block font-semibold">ذمم الديون</span>
                    <span class="text-base font-black text-slate-900 dark:text-white font-num"><?= $wipeStats['debts'] ?></span>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- Modal: Wipe All Data Confirmation Dialog -->
<div id="wipeAllDataModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-rose-200 dark:border-rose-900/60 shadow-2xl space-y-5 text-right m-auto animate-fadeIn">
        
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
            <div class="flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-xl bg-rose-100 dark:bg-rose-900/50 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>
                <div>
                    <h3 class="font-black text-slate-900 dark:text-white text-base">تأكيد حذف وتصفير جميع البيانات</h3>
                    <span class="text-xs text-rose-600 font-bold">تحذير أمني: هذا الإجراء نهائي ولا يمكن التراجع عنه</span>
                </div>
            </div>
            <button type="button" onclick="closeWipeModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-500 flex items-center justify-center transition">✕</button>
        </div>

        <div class="space-y-3 text-xs text-slate-600 dark:text-slate-300">
            <p class="leading-relaxed">
                أنت على وشك تنفيذ <strong class="text-rose-600 font-black">حذف شامل وتصفير كامل</strong> لجميع سجلات المنصة التشغيلية، بما في ذلك:
            </p>
            <ul class="list-disc list-inside space-y-1 pr-2 text-[11px] text-slate-500">
                <li>جميع سيارات الأجرة ووثائق التأمين والمراقبة التقنية</li>
                <li>جميع بطاقات السائقين وسجلات الفحص الطبي</li>
                <li>عقود العمل الرسمية (قانون 90-11) وتصريحات الضمان CNAS</li>
                <li>جداول الإرسال ومحاضر المسافرين بين الولايات</li>
                <li>حراسة الحظيرة الليلية، جداول المناوبات، والديون والمدفوعات</li>
            </ul>
            <div class="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/40 rounded-xl text-amber-900 dark:text-amber-300 text-[11px] font-semibold flex items-center gap-2">
                <span>🛡️</span>
                <span>ملاحظة: حسابات الإدارة والمشرفين (Admins) لن يتم حذفها لضمان استمرار قدرتك على الدخول.</span>
            </div>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="wipe_all_data">

            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-200 text-xs mb-1.5">
                    لتأكيد العملية، يرجى كتابة كلمة <span class="text-rose-600 font-black select-all">تأكيد</span> أو <span class="text-rose-600 font-black select-all">DELETE</span> أدناه:
                </label>
                <input type="text" id="confirmDeleteInput" name="confirm_delete" required 
                       oninput="validateWipeConfirmation(this.value)"
                       placeholder="اكتب تأكيد أو DELETE هنا..." 
                       class="w-full px-3.5 py-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-xs font-bold text-center focus:outline-none focus:border-rose-500 transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="closeWipeModal()" class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold text-xs hover:bg-slate-50 transition">
                    إلغاء وتراجع
                </button>
                <button type="submit" id="wipeSubmitBtn" disabled
                        class="px-5 py-2.5 rounded-xl bg-rose-600 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-rose-700 text-white font-black text-xs transition shadow-xs flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                    </svg>
                    <span>نعم، احذف جميع البيانات نهائياً</span>
                </button>
            </div>
        </form>

    </div>
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

// Danger Zone: Wipe All Data Modal Helpers
function openWipeModal() {
    const modal = document.getElementById('wipeAllDataModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        const input = document.getElementById('confirmDeleteInput');
        if (input) {
            input.value = '';
            setTimeout(() => input.focus(), 50);
        }
        const btn = document.getElementById('wipeSubmitBtn');
        if (btn) btn.disabled = true;
    }
}

function closeWipeModal() {
    const modal = document.getElementById('wipeAllDataModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function validateWipeConfirmation(val) {
    const clean = val.trim();
    const btn = document.getElementById('wipeSubmitBtn');
    if (btn) {
        btn.disabled = !(clean === 'تأكيد' || clean.toUpperCase() === 'DELETE');
    }
}

// Toggle Prepayment Discount Offer (Activate / Deactivate)
function toggleDiscountOffer(slug) {
    const hidden = document.getElementById('hidden_discount_prepay_' + slug + '_enabled');
    const btn = document.getElementById('btn_toggle_' + slug);
    const track = document.getElementById('switch_track_' + slug);
    const label = document.getElementById('label_toggle_' + slug);
    const badge = document.getElementById('badge_status_' + slug);
    const input = document.getElementById('input_discount_prepay_' + slug);
    const hint = document.getElementById('text_status_hint_' + slug);
    const row = document.getElementById('row_offer_' + slug);
    const feedback = document.getElementById('saved_feedback_' + slug);
    if (!hidden || !btn) return;

    const isCurrentlyActive = hidden.value === '1';
    const nextState = !isCurrentlyActive;
    hidden.value = nextState ? '1' : '0';

    // Update UI immediately (Optimistic UI)
    if (nextState) {
        btn.className = "inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-bold transition shadow-2xs border cursor-pointer select-none bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800";
        btn.title = "انقر لتعطيل وإيقاف هذا العرض";
        if (track) track.className = "w-8 h-4.5 rounded-full p-0.5 transition flex items-center bg-emerald-500 justify-end";
        if (label) label.textContent = "مفعّل";
        if (badge) {
            badge.className = "px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300";
            badge.textContent = "متاح";
        }
        if (input) {
            input.classList.remove('opacity-40', 'pointer-events-none', 'bg-slate-100', 'dark:bg-slate-900/60');
        }
        if (hint) {
            hint.className = "text-[10px] text-slate-400 dark:text-slate-500 font-normal block mt-1";
            hint.textContent = "عرض مفعّل ونشط";
        }
        if (row) {
            row.classList.remove('bg-slate-50/40', 'dark:bg-slate-900/40');
            row.classList.add('bg-emerald-50/15', 'dark:bg-emerald-950/20');
        }
    } else {
        btn.className = "inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-bold transition shadow-2xs border cursor-pointer select-none bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 border-slate-300 dark:border-slate-700";
        btn.title = "انقر لتفعيل هذا العرض";
        if (track) track.className = "w-8 h-4.5 rounded-full p-0.5 transition flex items-center bg-slate-300 dark:bg-slate-600 justify-start";
        if (label) label.textContent = "معطّل";
        if (badge) {
            badge.className = "px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300";
            badge.textContent = "معطّل";
        }
        if (input) {
            input.classList.add('opacity-40', 'pointer-events-none', 'bg-slate-100', 'dark:bg-slate-900/60');
        }
        if (hint) {
            hint.className = "text-[10px] text-rose-500 dark:text-rose-400 font-bold block mt-1";
            hint.textContent = "العرض موقف حالياً";
        }
        if (row) {
            row.classList.remove('bg-emerald-50/15', 'dark:bg-emerald-950/20');
            row.classList.add('bg-slate-50/40', 'dark:bg-slate-900/40');
        }
    }

    // AJAX Auto-Save in background
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const fd = new FormData();
    fd.append('action', 'toggle_discount_offer');
    fd.append('offer', slug);
    fd.append('enabled', nextState ? '1' : '0');
    fd.append('csrf_token', csrfToken);

    fetch('settings.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (feedback) {
            feedback.textContent = nextState ? '✓ تم التفعيل' : '✓ تم الإيقاف';
            feedback.classList.remove('hidden');
            setTimeout(() => { feedback.classList.add('hidden'); }, 2500);
        }
    })
    .catch(err => {
        console.warn('Auto-save notice:', err);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
