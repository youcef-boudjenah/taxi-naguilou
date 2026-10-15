<?php
/**
 * dashboard/import.php — Data Import Tool
 * Allows importing data from old site (JSON/CSV/SQL) or manual entry
 */
$pageTitle = 'استيراد البيانات من الموقع القديم';
require_once __DIR__ . '/../includes/header.php';
require_admin();
?>

<div class="space-y-6">

    <!-- Page Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-black text-slate-900 dark:text-white">استيراد البيانات</h1>
            <p class="text-xs text-slate-500 mt-0.5">نقل البيانات من الموقع القديم (taxi-naguilou.com) إلى هذه المنصة</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= url('/dashboard/') ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                ← العودة للوحة التحكم
            </a>
        </div>
    </div>

    <!-- Info Banner -->
    <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-xl p-4 flex gap-3">
        <div class="text-amber-600 dark:text-amber-400 text-xl shrink-0">ℹ️</div>
        <div class="text-xs text-amber-800 dark:text-amber-200 space-y-1">
            <div class="font-bold">كيفية نقل البيانات من الموقع القديم</div>
            <div>الموقع القديم (taxi-naguilou.com) يعمل بتقنية Next.js. لنقل البيانات:</div>
            <ol class="list-decimal list-inside space-y-0.5 mt-1">
                <li>ادخل إلى لوحة تحكم الموقع القديم</li>
                <li>صدّر البيانات بصيغة JSON أو CSV أو SQL (إن كانت متاحة)</li>
                <li>أو أدخل البيانات يدوياً أدناه في كل قسم</li>
            </ol>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="bg-white dark:bg-[#1e293b] rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="border-b border-slate-200 dark:border-slate-700 flex overflow-x-auto">
            <?php
            $tabs = [
                'json'     => ['icon' => '📥', 'label' => 'استيراد JSON/SQL'],
                'drivers'  => ['icon' => '👤', 'label' => 'سائقون'],
                'vehicles' => ['icon' => '🚕', 'label' => 'مركبات'],
                'billing'  => ['icon' => '💰', 'label' => 'فوترة'],
                'contracts'=> ['icon' => '📄', 'label' => 'عقود'],
                'debts'    => ['icon' => '📊', 'label' => 'ديون'],
            ];
            foreach ($tabs as $key => $tab):
            ?>
            <button onclick="switchTab('<?= $key ?>')" id="tab-btn-<?= $key ?>"
                    class="import-tab shrink-0 flex items-center gap-1.5 px-4 py-3 text-xs font-bold text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white border-b-2 border-transparent hover:border-amber-400 transition whitespace-nowrap">
                <span><?= $tab['icon'] ?></span>
                <span><?= $tab['label'] ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- TAB: JSON / SQL Import -->
        <div id="tab-json" class="import-panel p-5 space-y-4">
            <div class="text-sm font-bold text-slate-800 dark:text-white">استيراد ملف JSON أو SQL كامل</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- File Upload -->
                <div class="border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-xl p-6 text-center space-y-3 hover:border-amber-400 transition cursor-pointer" onclick="document.getElementById('fileUpload').click()">
                    <div class="text-3xl">📂</div>
                    <div class="text-xs font-bold text-slate-700 dark:text-slate-200">اسحب ملف هنا أو اضغط لاختياره</div>
                    <div class="text-xs text-slate-400">JSON • SQL • CSV (حجم أقصى: 50MB)</div>
                    <input type="file" id="fileUpload" accept=".json,.sql,.csv" class="hidden" onchange="handleFileUpload(this)">
                </div>
                <!-- Paste JSON -->
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-700 dark:text-slate-200 block">أو الصق محتوى JSON مباشرة:</label>
                    <textarea id="jsonPaste" rows="8" placeholder='{"drivers": [...], "vehicles": [...], "contracts": [...]}' 
                              class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-xs font-mono text-slate-700 dark:text-slate-200 focus:border-amber-400 focus:ring-1 focus:ring-amber-400 resize-none" dir="ltr"></textarea>
                </div>
            </div>
            <div id="fileInfo" class="hidden bg-slate-50 dark:bg-slate-800 rounded-lg p-3 text-xs text-slate-600 dark:text-slate-300"></div>
            <div class="flex items-center gap-2">
                <button onclick="processImport()" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs rounded-xl transition shadow-sm flex items-center gap-2">
                    <span>⚡</span> بدء الاستيراد التلقائي
                </button>
                <button onclick="previewData()" class="px-4 py-2.5 border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-bold text-xs rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    👁 معاينة
                </button>
            </div>
            <div id="importProgress" class="hidden space-y-2">
                <div class="text-xs font-bold text-slate-700 dark:text-white">تقدم الاستيراد:</div>
                <div class="bg-slate-100 dark:bg-slate-800 rounded-full h-2.5">
                    <div id="progressBar" class="bg-amber-500 h-2.5 rounded-full transition-all duration-500" style="width:0%"></div>
                </div>
                <div id="progressText" class="text-xs text-slate-500"></div>
            </div>
            <div id="importResults" class="hidden"></div>
        </div>

        <!-- TAB: Drivers Manual Import -->
        <div id="tab-drivers" class="import-panel hidden p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div class="text-sm font-bold text-slate-800 dark:text-white">إضافة سائقين من الموقع القديم</div>
                <button onclick="addDriverRow()" class="px-3 py-1.5 bg-violet-500 hover:bg-violet-600 text-white text-xs font-bold rounded-lg transition">+ إضافة سائق</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-right">
                            <th class="px-3 py-2 font-bold rounded-r-lg">الاسم الكامل</th>
                            <th class="px-3 py-2 font-bold">البريد الإلكتروني</th>
                            <th class="px-3 py-2 font-bold">الهاتف</th>
                            <th class="px-3 py-2 font-bold">تاريخ البداية</th>
                            <th class="px-3 py-2 font-bold">رقم رخصة القيادة</th>
                            <th class="px-3 py-2 font-bold rounded-l-lg">إجراء</th>
                        </tr>
                    </thead>
                    <tbody id="driversTable">
                        <tr id="driver-row-template" class="border-b border-slate-100 dark:border-slate-700">
                            <td class="px-2 py-1.5"><input type="text" placeholder="محمد بلقاسم" class="driver-name w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-violet-400 focus:ring-1 focus:ring-violet-400"></td>
                            <td class="px-2 py-1.5"><input type="email" placeholder="driver@email.com" dir="ltr" class="driver-email w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-violet-400 focus:ring-1 focus:ring-violet-400"></td>
                            <td class="px-2 py-1.5"><input type="text" placeholder="06XXXXXXXX" dir="ltr" class="driver-phone w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-violet-400 focus:ring-1 focus:ring-violet-400"></td>
                            <td class="px-2 py-1.5"><input type="date" class="driver-start w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-violet-400 focus:ring-1 focus:ring-violet-400" dir="ltr"></td>
                            <td class="px-2 py-1.5"><input type="text" placeholder="123456789" dir="ltr" class="driver-license w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-violet-400 focus:ring-1 focus:ring-violet-400"></td>
                            <td class="px-2 py-1.5"><button onclick="this.closest('tr').remove()" class="text-rose-500 hover:text-rose-700 font-bold">✕</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center gap-2 pt-2">
                <button onclick="saveDrivers()" class="px-5 py-2.5 bg-violet-500 hover:bg-violet-600 text-white font-bold text-xs rounded-xl transition shadow-sm">💾 حفظ السائقين</button>
                <div id="driversStatus" class="text-xs font-bold"></div>
            </div>
        </div>

        <!-- TAB: Vehicles Manual Import -->
        <div id="tab-vehicles" class="import-panel hidden p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div class="text-sm font-bold text-slate-800 dark:text-white">إضافة مركبات من الموقع القديم</div>
                <button onclick="addVehicleRow()" class="px-3 py-1.5 bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold rounded-lg transition">+ إضافة مركبة</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-right">
                            <th class="px-3 py-2 font-bold rounded-r-lg">العلامة التجارية</th>
                            <th class="px-3 py-2 font-bold">الطراز</th>
                            <th class="px-3 py-2 font-bold">رقم اللوحة</th>
                            <th class="px-3 py-2 font-bold">رقم الباب</th>
                            <th class="px-3 py-2 font-bold">اللون</th>
                            <th class="px-3 py-2 font-bold">تأمين (انتهاء)</th>
                            <th class="px-3 py-2 font-bold">مراقبة تقنية</th>
                            <th class="px-3 py-2 font-bold rounded-l-lg">إجراء</th>
                        </tr>
                    </thead>
                    <tbody id="vehiclesTable">
                        <tr class="border-b border-slate-100 dark:border-slate-700">
                            <td class="px-2 py-1.5"><input type="text" placeholder="Toyota" dir="ltr" class="v-brand w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-orange-400 focus:ring-1 focus:ring-orange-400"></td>
                            <td class="px-2 py-1.5"><input type="text" placeholder="Corolla" dir="ltr" class="v-model w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-orange-400 focus:ring-1 focus:ring-orange-400"></td>
                            <td class="px-2 py-1.5"><input type="text" placeholder="123 TAX 16" dir="ltr" class="v-plate w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-orange-400 focus:ring-1 focus:ring-orange-400"></td>
                            <td class="px-2 py-1.5"><input type="text" placeholder="T-001" dir="ltr" class="v-door w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-orange-400 focus:ring-1 focus:ring-orange-400"></td>
                            <td class="px-2 py-1.5"><input type="text" placeholder="أصفر" class="v-color w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-orange-400 focus:ring-1 focus:ring-orange-400"></td>
                            <td class="px-2 py-1.5"><input type="date" dir="ltr" class="v-insurance w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-orange-400 focus:ring-1 focus:ring-orange-400"></td>
                            <td class="px-2 py-1.5"><input type="date" dir="ltr" class="v-control w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-white focus:border-orange-400 focus:ring-1 focus:ring-orange-400"></td>
                            <td class="px-2 py-1.5"><button onclick="this.closest('tr').remove()" class="text-rose-500 hover:text-rose-700 font-bold">✕</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center gap-2 pt-2">
                <button onclick="saveVehicles()" class="px-5 py-2.5 bg-orange-500 hover:bg-orange-600 text-white font-bold text-xs rounded-xl transition shadow-sm">💾 حفظ المركبات</button>
                <div id="vehiclesStatus" class="text-xs font-bold"></div>
            </div>
        </div>

        <!-- TAB: Billing -->
        <div id="tab-billing" class="import-panel hidden p-5 space-y-4">
            <div class="text-sm font-bold text-slate-800 dark:text-white">استيراد سجلات الفوترة</div>
            <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 text-xs text-slate-600 dark:text-slate-300 space-y-2">
                <div class="font-bold text-slate-800 dark:text-white">CSV Template للفوترة:</div>
                <code class="block bg-white dark:bg-slate-900 rounded-lg p-2 font-mono text-[10px] border border-slate-200 dark:border-slate-700" dir="ltr">vehicle_plate,amount,period_month,due_date,payment_date,status,payment_method,notes
123 TAX 16,5000,2026-10,2026-10-01,2026-10-05,paid,baridimob,
456 TAX 16,5000,2026-10,2026-10-01,,pending,baridimob,</code>
            </div>
            <div class="space-y-2">
                <label class="text-xs font-bold text-slate-700 dark:text-slate-200 block">الصق بيانات CSV:</label>
                <textarea id="billingCsv" rows="8" placeholder="vehicle_plate,amount,period_month,due_date,payment_date,status..." 
                          class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-xs font-mono text-slate-700 dark:text-slate-200 focus:border-amber-400 resize-none" dir="ltr"></textarea>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="saveBillingCsv()" class="px-5 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs rounded-xl transition shadow-sm">💾 استيراد الفوترة</button>
                <div id="billingStatus" class="text-xs font-bold"></div>
            </div>
        </div>

        <!-- TAB: Contracts -->
        <div id="tab-contracts" class="import-panel hidden p-5 space-y-4">
            <div class="text-sm font-bold text-slate-800 dark:text-white">استيراد العقود</div>
            <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-xl p-3 text-xs text-amber-700 dark:text-amber-300">
                ⚠️ يجب أن يكون السائق والمركبة والمالك مسجلين في النظام أولاً قبل استيراد العقود.
            </div>
            <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 text-xs text-slate-600 dark:text-slate-300 space-y-2">
                <div class="font-bold text-slate-800 dark:text-white">CSV Template للعقود:</div>
                <code class="block bg-white dark:bg-slate-900 rounded-lg p-2 font-mono text-[10px] border border-slate-200 dark:border-slate-700" dir="ltr">contract_number,driver_email,vehicle_plate,start_date,end_date,monthly_wage,status
CTR-001,driver@email.com,123 TAX 16,2026-01-01,2026-12-31,45000,signed</code>
            </div>
            <div class="space-y-2">
                <textarea id="contractsCsv" rows="6" placeholder="contract_number,driver_email,vehicle_plate,start_date..."
                          class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-xs font-mono text-slate-700 dark:text-slate-200 focus:border-amber-400 resize-none" dir="ltr"></textarea>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="saveContractsCsv()" class="px-5 py-2.5 bg-blue-500 hover:bg-blue-600 text-white font-bold text-xs rounded-xl transition shadow-sm">💾 استيراد العقود</button>
                <div id="contractsStatus" class="text-xs font-bold"></div>
            </div>
        </div>

        <!-- TAB: Debts -->
        <div id="tab-debts" class="import-panel hidden p-5 space-y-4">
            <div class="text-sm font-bold text-slate-800 dark:text-white">استيراد الديون والمستحقات</div>
            <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 text-xs space-y-2">
                <div class="font-bold text-slate-800 dark:text-white">CSV Template:</div>
                <code class="block bg-white dark:bg-slate-900 rounded-lg p-2 font-mono text-[10px] border border-slate-200 dark:border-slate-700" dir="ltr">chauffeur_name,phone,total_credits,total_versements,driver_type,status,notes
محمد بلقاسم,0661234567,150000,50000,actif,endette,</code>
            </div>
            <div class="space-y-2">
                <textarea id="debtsCsv" rows="6" placeholder="chauffeur_name,phone,total_credits,total_versements..."
                          class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-xs font-mono text-slate-700 dark:text-slate-200 focus:border-amber-400 resize-none" dir="ltr"></textarea>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="saveDebtsCsv()" class="px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white font-bold text-xs rounded-xl transition shadow-sm">💾 استيراد الديون</button>
                <div id="debtsStatus" class="text-xs font-bold"></div>
            </div>
        </div>
    </div>

    <!-- Import Log -->
    <div id="importLog" class="bg-slate-900 rounded-xl p-4 font-mono text-xs text-slate-300 min-h-[80px] max-h-64 overflow-y-auto hidden">
        <div class="text-slate-500 mb-2">// سجل عمليات الاستيراد</div>
    </div>
</div>

<style>
.import-tab.active { color: #d97706; border-bottom-color: #d97706; }
.dark .import-tab.active { color: #fbbf24; border-bottom-color: #fbbf24; }
</style>

<script>
// --- Tab Switching ---
function switchTab(key) {
    document.querySelectorAll('.import-panel').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.import-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + key).classList.remove('hidden');
    document.getElementById('tab-btn-' + key).classList.add('active');
}
switchTab('json');

// --- File Upload Handler ---
function handleFileUpload(input) {
    const file = input.files[0];
    if (!file) return;
    const info = document.getElementById('fileInfo');
    info.classList.remove('hidden');
    info.textContent = `📁 ${file.name} (${(file.size/1024).toFixed(1)} KB)`;
    const reader = new FileReader();
    reader.onload = e => {
        if (file.name.endsWith('.json')) {
            document.getElementById('jsonPaste').value = e.target.result;
        }
        logImport(`تم تحميل الملف: ${file.name}`);
    };
    reader.readAsText(file, 'utf-8');
}

// --- JSON Import ---
async function processImport() {
    const raw = document.getElementById('jsonPaste').value.trim();
    if (!raw) { alert('الرجاء لصق بيانات JSON أو اختيار ملف'); return; }
    
    let data;
    try { data = JSON.parse(raw); } catch(e) { alert('خطأ في صيغة JSON: ' + e.message); return; }
    
    document.getElementById('importProgress').classList.remove('hidden');
    document.getElementById('importResults').classList.add('hidden');
    
    const steps = [];
    if (data.tables || data.data) {
        steps.push({ key: 'full_backup', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=full_backup', d: data });
    } else {
        if (data.drivers || data.users) steps.push({ key: 'drivers', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=drivers', d: data.drivers || data.users });
        if (data.vehicles) steps.push({ key: 'vehicles', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=vehicles', d: data.vehicles });
        if (data.contracts) steps.push({ key: 'contracts', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=contracts', d: data.contracts });
        if (data.subscriptions || data.driver_subscriptions) steps.push({ key: 'subscriptions', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=full_backup', d: data });
        if (data.billings || data.billing) steps.push({ key: 'billing', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=billing', d: data.billings || data.billing });
        if (data.debts || data.driver_debts) steps.push({ key: 'debts', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=debts', d: data.debts || data.driver_debts });
        if (data.cnas_declarations) steps.push({ key: 'cnas', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=cnas', d: data.cnas_declarations });
        if (data.leaves) steps.push({ key: 'leaves', endpoint: '<?= url('/dashboard/import_api.php') ?>?type=leaves', d: data.leaves });
    }
    
    if (steps.length === 0) {
        logImport('❌ لا توجد بيانات قابلة للاستيراد في هذا الملف. المفاتيح المتوقعة: tables, data, drivers, vehicles, contracts, billings, debts');
        setProgress(100, 'اكتمل بدون بيانات');
        return;
    }
    
    let results = [];
    for (let i = 0; i < steps.length; i++) {
        const s = steps[i];
        setProgress(Math.round((i / steps.length) * 100), `جاري استيراد: ${s.key} (${s.d.length} سجل)...`);
        logImport(`→ ${s.key}: ${s.d.length} سجلات...`);
        try {
            const resp = await fetch(s.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(s.d)
            });
            const r = await resp.json();
            results.push({ key: s.key, ...r });
            logImport(`✅ ${s.key}: أدخلت ${r.inserted ?? 0}، تخطت ${r.skipped ?? 0}، أخطاء ${r.errors ?? 0}`);
        } catch(e) {
            results.push({ key: s.key, error: e.message });
            logImport(`❌ ${s.key}: ${e.message}`);
        }
    }
    
    setProgress(100, 'اكتمل الاستيراد!');
    showResults(results);
}

function setProgress(pct, text) {
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressText').textContent = text;
}

function showResults(results) {
    const div = document.getElementById('importResults');
    div.classList.remove('hidden');
    div.innerHTML = `<div class="mt-3 space-y-1">${results.map(r => `
        <div class="flex items-center gap-2 text-xs p-2 rounded-lg ${r.error ? 'bg-rose-50 dark:bg-rose-950/30 text-rose-700 dark:text-rose-300' : 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-300'}">
            <span>${r.error ? '❌' : '✅'}</span>
            <span class="font-bold">${r.key}:</span>
            <span>${r.error || `أدخلت ${r.inserted ?? 0} | تخطت ${r.skipped ?? 0} | أخطاء ${r.errors ?? 0}`}</span>
        </div>
    `).join('')}</div>`;
}

function previewData() {
    const raw = document.getElementById('jsonPaste').value.trim();
    if (!raw) { alert('لا توجد بيانات للمعاينة'); return; }
    try {
        const data = JSON.parse(raw);
        const keys = Object.keys(data);
        logImport(`معاينة: ${keys.map(k => `${k}(${Array.isArray(data[k]) ? data[k].length : 1})`).join(', ')}`);
    } catch(e) { alert('خطأ في JSON: ' + e.message); }
}

// --- Manual Driver Rows ---
function addDriverRow() {
    const tr = document.querySelector('#driversTable tr').cloneNode(true);
    tr.querySelectorAll('input').forEach(i => i.value = '');
    document.getElementById('driversTable').appendChild(tr);
}

async function saveDrivers() {
    const rows = document.querySelectorAll('#driversTable tr');
    const drivers = [];
    rows.forEach(row => {
        const name = row.querySelector('.driver-name')?.value.trim();
        if (!name) return;
        drivers.push({
            full_name: name,
            email: row.querySelector('.driver-email')?.value.trim() || '',
            phone: row.querySelector('.driver-phone')?.value.trim() || '0000000000',
            activity_start_date: row.querySelector('.driver-start')?.value || null,
            license_number: row.querySelector('.driver-license')?.value.trim() || null,
        });
    });
    if (!drivers.length) { alert('أضف سائقاً على الأقل'); return; }
    const status = document.getElementById('driversStatus');
    status.textContent = '⏳ جاري الحفظ...';
    try {
        const resp = await fetch('<?= url('/dashboard/import_api.php') ?>?type=drivers', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(drivers)
        });
        const r = await resp.json();
        status.className = 'text-xs font-bold text-emerald-600';
        status.textContent = `✅ أدخلت ${r.inserted ?? 0} | تخطي ${r.skipped ?? 0}`;
        logImport(`drivers: أدخلت ${r.inserted}, تخطت ${r.skipped}, أخطاء ${r.errors}`);
    } catch(e) {
        status.className = 'text-xs font-bold text-rose-600';
        status.textContent = '❌ ' + e.message;
    }
}

// --- Manual Vehicle Rows ---
function addVehicleRow() {
    const tbody = document.getElementById('vehiclesTable');
    const first = tbody.querySelector('tr').cloneNode(true);
    first.querySelectorAll('input').forEach(i => i.value = '');
    tbody.appendChild(first);
}

async function saveVehicles() {
    const rows = document.querySelectorAll('#vehiclesTable tr');
    const vehicles = [];
    rows.forEach(row => {
        const plate = row.querySelector('.v-plate')?.value.trim();
        if (!plate) return;
        vehicles.push({
            brand: row.querySelector('.v-brand')?.value.trim() || 'Unknown',
            model: row.querySelector('.v-model')?.value.trim() || 'Unknown',
            plate_number: plate,
            door_number: row.querySelector('.v-door')?.value.trim() || plate,
            color: row.querySelector('.v-color')?.value.trim() || 'أصفر',
            insurance_expiry: row.querySelector('.v-insurance')?.value || null,
            technical_control_expiry: row.querySelector('.v-control')?.value || null,
        });
    });
    if (!vehicles.length) { alert('أضف مركبة على الأقل'); return; }
    const status = document.getElementById('vehiclesStatus');
    status.textContent = '⏳ جاري الحفظ...';
    try {
        const resp = await fetch('<?= url('/dashboard/import_api.php') ?>?type=vehicles', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(vehicles)
        });
        const r = await resp.json();
        status.className = 'text-xs font-bold text-emerald-600';
        status.textContent = `✅ أدخلت ${r.inserted ?? 0} | تخطي ${r.skipped ?? 0}`;
    } catch(e) {
        status.className = 'text-xs font-bold text-rose-600';
        status.textContent = '❌ ' + e.message;
    }
}

// --- CSV helpers ---
function parseCsv(csv) {
    const lines = csv.trim().split('\n');
    if (lines.length < 2) return [];
    const headers = lines[0].split(',').map(h => h.trim());
    return lines.slice(1).filter(l => l.trim()).map(line => {
        const vals = line.split(',').map(v => v.trim());
        const obj = {};
        headers.forEach((h, i) => obj[h] = vals[i] || '');
        return obj;
    });
}

async function postCsv(endpoint, data, statusId) {
    const status = document.getElementById(statusId);
    if (!data.length) { alert('لا توجد بيانات'); return; }
    status.textContent = '⏳ جاري الاستيراد...';
    try {
        const resp = await fetch(endpoint, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data)
        });
        const r = await resp.json();
        status.className = 'text-xs font-bold text-emerald-600';
        status.textContent = `✅ أدخلت ${r.inserted ?? 0} | تخطي ${r.skipped ?? 0} | أخطاء ${r.errors ?? 0}`;
        logImport(`${statusId}: أدخلت ${r.inserted}, تخطت ${r.skipped}`);
    } catch(e) {
        status.className = 'text-xs font-bold text-rose-600';
        status.textContent = '❌ ' + e.message;
    }
}

function saveBillingCsv() {
    postCsv('<?= url('/dashboard/import_api.php') ?>?type=billing', parseCsv(document.getElementById('billingCsv').value), 'billingStatus');
}
function saveContractsCsv() {
    postCsv('<?= url('/dashboard/import_api.php') ?>?type=contracts', parseCsv(document.getElementById('contractsCsv').value), 'contractsStatus');
}
function saveDebtsCsv() {
    postCsv('<?= url('/dashboard/import_api.php') ?>?type=debts', parseCsv(document.getElementById('debtsCsv').value), 'debtsStatus');
}

// --- Log ---
function logImport(msg) {
    const log = document.getElementById('importLog');
    log.classList.remove('hidden');
    const line = document.createElement('div');
    const t = new Date().toLocaleTimeString('ar-DZ');
    line.textContent = `[${t}] ${msg}`;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
