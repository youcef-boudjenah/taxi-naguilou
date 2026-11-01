<?php
// dashboard/vehicle_sticker.php - Official Taxi Vehicle Decal & Pure Barcode/QR Sticker
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = getDBConnection();
$vehicleId = intval($_GET['id'] ?? 0);

if (!$pdo || $vehicleId <= 0) {
    die("معرف المركبة غير صالح.");
}

$stmt = $pdo->prepare("
    SELECT v.*, 
           u.full_name as driver_name, u.phone as driver_phone, u.card_number as driver_card, 
           u.license_number as driver_license, u.wilaya as driver_wilaya,
           r.full_name as repl_name, r.phone as repl_phone
    FROM vehicles v
    LEFT JOIN users u ON v.current_driver_id = u.id
    LEFT JOIN users r ON v.replacement_driver_id = r.id
    WHERE v.id = ? AND v.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([$vehicleId]);
$vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vehicle) {
    die("تعذر العثور على سجل المركبة المطلوب.");
}

// Ensure vehicle permanently has a valid qr_code
if (empty($vehicle['qr_code'])) {
    $generatedQr = 'TN-V' . str_pad($vehicle['id'], 3, '0', STR_PAD_LEFT) . '-' . substr(md5($vehicle['id'] . ($vehicle['plate_number'] ?? '') . 'taxi_salt'), 0, 6);
    try {
        $pdo->prepare("UPDATE vehicles SET qr_code = ? WHERE id = ?")->execute([$generatedQr, $vehicle['id']]);
        $vehicle['qr_code'] = $generatedQr;
    } catch (\Throwable $e) {}
}

$qrToken = !empty($vehicle['qr_code']) ? $vehicle['qr_code'] : ('TN-V' . str_pad($vehicle['id'], 3, '0', STR_PAD_LEFT));
$verifyUrl = absolute_url('/verify_vehicle.php?v=' . urlencode($qrToken));
$barcodeText = !empty($vehicle['plate_number']) ? str_replace('/', '-', $vehicle['plate_number']) : ('TN-V' . str_pad($vehicle['id'], 3, '0', STR_PAD_LEFT));
$isActive = ($vehicle['status'] ?? 'active') === 'active';

// Default mode is 'barcode_only' (الكودبار فقط بدون كلام)
$initialMode = trim($_GET['mode'] ?? 'barcode_only');
if (!in_array($initialMode, ['barcode_only', 'qr_only', 'linear_only', 'full'])) {
    $initialMode = 'barcode_only';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كودبار وملصق سيارة الأجرة | <?= htmlspecialchars($vehicle['door_number']) ?> - <?= htmlspecialchars($vehicle['plate_number']) ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">

    <!-- QR & Barcode Libraries -->
    <script src="<?= url('/assets/js/qrcode.min.js') ?>"></script>
    <script src="<?= url('/assets/js/JsBarcode.all.min.js') ?>"></script>

    <style>
        body, * { font-family: 'Cairo', sans-serif !important; }
        .font-num, [dir="ltr"] { font-family: 'Plus Jakarta Sans', sans-serif !important; }
        
        /* Interactive Mode Styles */
        body.mode-barcode-only .hide-on-barcode-only,
        body.mode-qr-only .hide-on-barcode-only,
        body.mode-linear-only .hide-on-barcode-only {
            display: none !important;
        }

        body.mode-qr-only .hide-on-qr-only {
            display: none !important;
        }

        body.mode-linear-only .hide-on-linear-only {
            display: none !important;
        }

        body.mode-barcode-only #stickerDualSection,
        body.mode-qr-only #stickerDualSection,
        body.mode-linear-only #stickerDualSection {
            border: none !important;
            padding: 0 !important;
            background: transparent !important;
        }

        body.mode-barcode-only #printableSticker,
        body.mode-qr-only #printableSticker,
        body.mode-linear-only #printableSticker {
            max-width: 320px;
            padding: 1.5rem;
            border-width: 2px;
        }

        /* Pure Print Styles */
        @media print {
            @page {
                margin: 4mm !important;
                size: auto !important;
            }
            .no-print { display: none !important; }
            html, body {
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                height: 100% !important;
            }

            .sticker-container {
                box-shadow: none !important;
                border: none !important;
                max-width: 100% !important;
                margin: 0 auto !important;
                page-break-inside: avoid !important;
                padding: 0 !important;
            }

            /* In Barcode-only mode: Strip all borders and outer paddings completely */
            body.mode-barcode-only #printableSticker,
            body.mode-qr-only #printableSticker,
            body.mode-linear-only #printableSticker {
                border: none !important;
                padding: 0 !important;
                margin: 0 auto !important;
                max-width: 100% !important;
            }

            /* Crisp SVG and QR image rendering */
            #stickerQrContainer img,
            #stickerQrContainer canvas,
            #stickerBarcodeSvg {
                image-rendering: -webkit-optimize-contrast !important;
                image-rendering: crisp-edges !important;
            }
        }
    </style>
</head>
<body id="pageBody" class="min-h-full py-8 px-4 flex flex-col items-center justify-center mode-<?= $initialMode ?>">

    <!-- Top Action Toolbar (No-Print) -->
    <div class="max-w-2xl w-full mb-6 space-y-3 no-print">
        
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <a href="javascript:history.back()" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition shadow-2xs">
                <svg class="w-4 h-4 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                <span>رجوع للأسطول</span>
            </a>

            <!-- Print Trigger Button -->
            <button onclick="triggerPrint()" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-slate-950 text-xs font-black transition shadow-md cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.07-.37-2.18-.37-3.329 0-4.418 3.582-8 8-8s8 3.582 8 8c0 1.15-.13 2.26-.37 3.329m-15.26 0A7.962 7.962 0 004 10.5C4 6.082 7.582 2.5 12 2.5s8 3.582 8 8c0 1.18-.255 2.3-.72 3.329m-14.56 0h14.56m-14.56 0A3.5 3.5 0 003.5 17h17a3.5 3.5 0 00-.72-3.171M6 17v4h12v-4" />
                </svg>
                <span id="printBtnLabel">🖨️ طباعة الكودبار فقط (بدون كلام)</span>
            </button>
        </div>

        <!-- Mode Selector Switcher -->
        <div class="bg-white p-2.5 rounded-2xl border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-2.5 text-xs">
            
            <div class="flex items-center gap-1.5 w-full sm:w-auto font-bold flex-wrap">
                <span class="text-slate-400 text-[11px] ml-1">شكل الطباعة:</span>
                
                <button type="button" onclick="setStickerMode('barcode_only')" id="btnModeBarcodeOnly"
                        class="mode-btn px-3 py-1.5 rounded-xl transition cursor-pointer font-black text-xs flex items-center gap-1">
                    <span>🏷️ كودبار فقط (بدون كلام)</span>
                </button>

                <button type="button" onclick="setStickerMode('qr_only')" id="btnModeQrOnly"
                        class="mode-btn px-3 py-1.5 rounded-xl transition cursor-pointer font-bold text-xs flex items-center gap-1">
                    <span>📱 رمز QR فقط</span>
                </button>

                <button type="button" onclick="setStickerMode('linear_only')" id="btnModeLinearOnly"
                        class="mode-btn px-3 py-1.5 rounded-xl transition cursor-pointer font-bold text-xs flex items-center gap-1">
                    <span>📊 باركود خطي فقط</span>
                </button>

                <button type="button" onclick="setStickerMode('full')" id="btnModeFull"
                        class="mode-btn px-3 py-1.5 rounded-xl transition cursor-pointer font-bold text-xs flex items-center gap-1">
                    <span>📑 الملصق الإداري الكامل</span>
                </button>
            </div>

            <!-- Optional: Show/Hide text below barcode bars -->
            <label class="inline-flex items-center gap-2 cursor-pointer text-[11px] font-bold text-slate-600 select-none shrink-0" id="barcodeNumberToggleWrap">
                <input type="checkbox" id="toggleBarcodeText" checked onchange="toggleBarcodeTextValue(this.checked)" class="rounded text-amber-500 focus:ring-amber-400 cursor-pointer">
                <span>إظهار رقم اللوحة تحت الخطوط</span>
            </label>

        </div>

        <!-- Tip Box -->
        <div class="p-2.5 bg-amber-50/80 border border-amber-200/80 rounded-xl text-[11px] text-amber-900 flex items-center gap-2">
            <span>💡</span>
            <span><strong>جاهز للطباعة على لاصق السيارة (Autocollant):</strong> تم ضبط الطباعة لتطبع <strong>الكودبار الصافي فقط بدون أي نصوص أو جداول إدارية</strong> لتثبيته مباشرة على زجاج أو طبلون سيارة الأجرة.</span>
        </div>

    </div>

    <!-- Sticker Container Card -->
    <div id="printableSticker" class="sticker-container bg-white w-full rounded-3xl border-3 border-slate-900 shadow-2xl p-6 space-y-4 text-center relative overflow-hidden transition-all duration-200">
        
        <!-- 1. Top Authority & Enterprise Header (Hidden on Barcode-only) -->
        <div class="hide-on-barcode-only bg-gradient-to-r from-amber-500 via-amber-400 to-amber-500 text-slate-950 p-3.5 rounded-2xl border-2 border-slate-900 shadow-xs space-y-1.5">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-900/80">الجمهورية الجزائرية الديمقراطية الشعبية</div>
            <div class="flex items-center justify-center gap-2.5 my-1">
                <img src="<?= url('/logo.webp') ?>" alt="تاكسي نقيلو" class="w-12 h-12 object-contain rounded-full bg-white p-1 border-2 border-slate-900 shadow-sm shrink-0" onerror="this.src='<?= url('/assets/logo.png') ?>'">
                <div class="text-right">
                    <span class="text-xl font-black text-slate-950 block leading-tight tracking-tight">تاكسي نقيلو</span>
                    <span class="text-[9px] font-bold text-slate-800 uppercase block tracking-wider">EURL TAXI NAGUILOU Services</span>
                </div>
            </div>
            <div class="text-[9.5px] font-bold text-slate-900">وزارة النقل — مديرية النقل لولاية أدرار • رخصة استغلال سيارة أجرة</div>
        </div>

        <!-- 2. Door & Plate Identity Badges (Hidden on Barcode-only) -->
        <div class="hide-on-barcode-only grid grid-cols-2 gap-3 text-right">
            <!-- Door Number Badge -->
            <div class="p-3 rounded-2xl bg-slate-50 border-2 border-slate-900 text-center space-y-0.5">
                <span class="text-[10px] font-bold text-slate-500 block uppercase tracking-wider">رقم الباب الرسمي (DTW)</span>
                <span class="text-xl font-black text-amber-600 font-num block" dir="ltr">
                    <?= htmlspecialchars($vehicle['door_number']) ?>
                </span>
            </div>

            <!-- License Plate Badge -->
            <div class="p-3 rounded-2xl bg-slate-50 border-2 border-slate-900 text-center space-y-0.5">
                <span class="text-[10px] font-bold text-slate-500 block uppercase tracking-wider">رقم لوحة الترقيم (Matricule)</span>
                <span class="text-lg font-black text-slate-950 font-num block" dir="ltr">
                    <?= htmlspecialchars(str_replace('/', '-', $vehicle['plate_number'])) ?>
                </span>
            </div>
        </div>

        <!-- 3. Vehicle & Driver Details Box (Hidden on Barcode-only) -->
        <div class="hide-on-barcode-only p-3.5 rounded-2xl bg-amber-50/60 border border-amber-300 text-xs space-y-2 text-right">
            <div class="flex items-center justify-between border-b border-amber-200/80 pb-1.5">
                <span class="font-bold text-slate-900">المركبة:</span>
                <strong class="text-slate-950 font-black">
                    <?= htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']) ?> 
                    <span class="text-slate-500 font-medium">(<?= htmlspecialchars($vehicle['color'] ?? 'أصفر') ?>)</span>
                </strong>
            </div>
            <div class="flex items-center justify-between border-b border-amber-200/80 pb-1.5">
                <span class="font-bold text-slate-900">السائق المعتمد:</span>
                <strong class="text-slate-950 font-extrabold">
                    <?= htmlspecialchars($vehicle['driver_name'] ?? 'بدون سائق حالياً') ?>
                </strong>
            </div>
            <div class="flex items-center justify-between text-[11px] text-slate-600">
                <span>الحالة التنظيمية:</span>
                <?php if ($isActive): ?>
                    <span class="px-2 py-0.5 rounded-md font-bold text-[10px] bg-emerald-100 text-emerald-800 border border-emerald-300">
                        مركبة معتمدة ونشطة في الميدان ✓
                    </span>
                <?php else: ?>
                    <span class="px-2 py-0.5 rounded-md font-bold text-[10px] bg-rose-100 text-rose-800 border border-rose-300">
                        معلقة إدارياً
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 4. Pure Barcode & QR Code Section -->
        <div id="stickerDualSection" class="p-4 rounded-2xl bg-white border-2 border-slate-900 space-y-4">
            
            <!-- A. QR Code (مسح الهاتف الذكي) -->
            <div id="qrCodeBlock" class="hide-on-linear-only space-y-1.5">
                <span class="hide-on-barcode-only text-[10px] font-extrabold text-slate-500 block uppercase tracking-wider">
                    مسح الرمز بالهاتف الذكي (QR Code) للتحقق والتقييم
                </span>
                <div class="flex justify-center p-2">
                    <div id="stickerQrContainer" class="p-1 bg-white inline-block"></div>
                </div>
            </div>

            <!-- Divider (Hidden when barcode-only or single code) -->
            <div id="codeDivider" class="hide-on-barcode-only hide-on-qr-only hide-on-linear-only border-t border-dashed border-slate-300"></div>

            <!-- B. Linear Barcode (كود بار خطي للمراقبة) -->
            <div id="linearBarcodeBlock" class="hide-on-qr-only space-y-1 pt-1">
                <span class="hide-on-barcode-only text-[10px] font-extrabold text-slate-500 block uppercase tracking-wider">
                    الباركود الضوئي للمراقبة الميدانية (Code-Barres)
                </span>
                <div class="flex justify-center overflow-hidden py-1">
                    <svg id="stickerBarcodeSvg" class="max-w-full"></svg>
                </div>
            </div>

        </div>

        <!-- 5. Official Footer Stamp (Hidden on Barcode-only) -->
        <div class="hide-on-barcode-only pt-2 flex items-center justify-between text-[10px] text-slate-500 font-bold border-t border-slate-100">
            <span>رقم السند: <?= htmlspecialchars($qrToken) ?></span>
            <span class="text-amber-800 font-num">EURL TAXI NAGUILOU • 2026</span>
        </div>

    </div>

    <!-- Scripts -->
    <script>
    let currentMode = <?= json_encode($initialMode) ?>;
    let showBarcodeText = true;
    const verifyUrl = <?= json_encode($verifyUrl) ?>;
    const barcodeText = <?= json_encode($barcodeText) ?>;

    function renderCodes() {
        // 1. Render QR Code (High-Res 180x180)
        const qrContainer = document.getElementById('stickerQrContainer');
        if (qrContainer && typeof QRCode !== 'undefined') {
            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: verifyUrl,
                width: 170,
                height: 170,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }

        // 2. Render Barcode (Code 128)
        if (typeof JsBarcode !== 'undefined') {
            try {
                JsBarcode('#stickerBarcodeSvg', barcodeText, {
                    format: 'CODE128',
                    lineColor: '#000000',
                    width: 2.2,
                    height: 52,
                    displayValue: showBarcodeText,
                    fontSize: 13,
                    fontOptions: 'bold',
                    font: 'Cairo',
                    margin: 6
                });
            } catch (e) {
                console.error('Barcode render error:', e);
            }
        }
    }

    function setStickerMode(mode) {
        currentMode = mode;
        const body = document.getElementById('pageBody');
        body.classList.remove('mode-barcode-only', 'mode-qr-only', 'mode-linear-only', 'mode-full');
        body.classList.add('mode-' + mode);

        // Update button styles
        const btns = {
            'barcode_only': document.getElementById('btnModeBarcodeOnly'),
            'qr_only': document.getElementById('btnModeQrOnly'),
            'linear_only': document.getElementById('btnModeLinearOnly'),
            'full': document.getElementById('btnModeFull')
        };

        Object.keys(btns).forEach(k => {
            const b = btns[k];
            if (!b) return;
            if (k === mode) {
                b.className = 'mode-btn px-3 py-1.5 rounded-xl transition cursor-pointer font-black text-xs flex items-center gap-1 bg-amber-500 text-slate-950 shadow-2xs';
            } else {
                b.className = 'mode-btn px-3 py-1.5 rounded-xl transition cursor-pointer font-bold text-xs flex items-center gap-1 bg-slate-100 text-slate-600 hover:bg-slate-200';
            }
        });

        // Update Print Button Label
        const printBtnLabel = document.getElementById('printBtnLabel');
        if (printBtnLabel) {
            if (mode === 'barcode_only') {
                printBtnLabel.textContent = '🖨️ طباعة الكودبار فقط (بدون كلام)';
            } else if (mode === 'qr_only') {
                printBtnLabel.textContent = '🖨️ طباعة رمز QR فقط (مربع نقي)';
            } else if (mode === 'linear_only') {
                printBtnLabel.textContent = '🖨️ طباعة الباركود الخطي فقط';
            } else {
                printBtnLabel.textContent = '🖨️ طباعة الملصق الإداري الكامل';
            }
        }

        // Toggle visibility of barcode text option
        const textToggleWrap = document.getElementById('barcodeNumberToggleWrap');
        if (textToggleWrap) {
            textToggleWrap.style.display = (mode === 'qr_only') ? 'none' : 'inline-flex';
        }
    }

    function toggleBarcodeTextValue(checked) {
        showBarcodeText = checked;
        renderCodes();
    }

    function triggerPrint() {
        window.print();
    }

    document.addEventListener('DOMContentLoaded', function() {
        setStickerMode(currentMode);
        renderCodes();

        // Check if URL has print=1
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === '1') {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    });
    </script>

</body>
</html>
