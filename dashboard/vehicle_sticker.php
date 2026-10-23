<?php
// dashboard/vehicle_sticker.php - Official Taxi Vehicle Inspection Decal & Barcode Sticker
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ملصق وباركود المركبة | <?= htmlspecialchars($vehicle['door_number']) ?> - <?= htmlspecialchars($vehicle['plate_number']) ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">

    <!-- QR & Barcode Libraries -->
    <script src="<?= url('/assets/js/qrcode.min.js') ?>"></script>
    <script src="<?= url('/assets/js/JsBarcode.all.min.js') ?>"></script>

    <style>
        body, * { font-family: 'Cairo', sans-serif !important; }
        .font-num, [dir="ltr"] { font-family: 'Plus Jakarta Sans', sans-serif !important; }
        
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; margin: 0 !important; }
            .sticker-container {
                box-shadow: none !important;
                border: 2px solid #0f172a !important;
                max-width: 100% !important;
                margin: 0 auto !important;
                page-break-inside: avoid !important;
            }
        }
    </style>
</head>
<body class="min-h-full py-8 px-4 flex flex-col items-center justify-center">

    <!-- Top Action Bar (Hidden on Print) -->
    <div class="max-w-md w-full mb-6 flex items-center justify-between no-print">
        <a href="javascript:history.back()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition shadow-xs">
            <svg class="w-4 h-4 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
            </svg>
            <span>رجوع للأسطول</span>
        </a>

        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-black transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.07-.37-2.18-.37-3.329 0-4.418 3.582-8 8-8s8 3.582 8 8c0 1.15-.13 2.26-.37 3.329m-15.26 0A7.962 7.962 0 004 10.5C4 6.082 7.582 2.5 12 2.5s8 3.582 8 8c0 1.18-.255 2.3-.72 3.329m-14.56 0h14.56m-14.56 0A3.5 3.5 0 003.5 17h17a3.5 3.5 0 00-.72-3.171M6 17v4h12v-4" />
                </svg>
                <span>🖨️ طباعة الملصق (Imprimer)</span>
            </button>
        </div>
    </div>

    <!-- Official Printable Sticker Card -->
    <div id="printableSticker" class="sticker-container bg-white max-w-md w-full rounded-3xl border-3 border-slate-900 shadow-2xl p-6 space-y-4 text-center relative overflow-hidden">
        
        <!-- Top Authority & Enterprise Header -->
        <div class="bg-gradient-to-r from-amber-500 via-amber-400 to-amber-500 text-slate-950 p-3 rounded-2xl border-2 border-slate-900 shadow-xs space-y-1">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-900/80">الجمهورية الجزائرية الديمقراطية الشعبية</div>
            <div class="text-[11px] font-black tracking-tight text-slate-950 flex items-center justify-center gap-2">
                <span>🚕 مؤسسة تاكسي نقيلو • TAXI NAGUILOU</span>
            </div>
            <div class="text-[9px] font-bold text-slate-800">مديرية النقل • رخصة استغلال سيارة أجرة فردية وجماعية</div>
        </div>

        <!-- Door & Plate Identity Badges -->
        <div class="grid grid-cols-2 gap-3 text-right">
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

        <!-- Vehicle & Driver Details Box -->
        <div class="p-3.5 rounded-2xl bg-amber-50/60 border border-amber-300 text-xs space-y-2 text-right">
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

        <!-- Dual Barcode Section: QR Code + Linear Barcode (كود بار خطي) -->
        <div class="p-4 rounded-2xl bg-white border-2 border-slate-900 space-y-4">
            
            <!-- 1. QR Code for Smartphone Scanning -->
            <div class="space-y-1.5">
                <span class="text-[10px] font-extrabold text-slate-500 block uppercase tracking-wider">
                    مسح الرمز بالهاتف الذكي (QR Code) للتحقق والتقييم
                </span>
                <div class="flex justify-center">
                    <div id="stickerQrContainer" class="p-2 bg-white rounded-xl border border-slate-200 shadow-2xs inline-block"></div>
                </div>
            </div>

            <!-- Divider -->
            <div class="border-t border-dashed border-slate-300"></div>

            <!-- 2. Linear Barcode (كود بار للمعاينة والماسحات الضوئية) -->
            <div class="space-y-1">
                <span class="text-[10px] font-extrabold text-slate-500 block uppercase tracking-wider">
                    الباركود الضوئي للمراقبة الميدانية (Code-Barres)
                </span>
                <div class="flex justify-center overflow-hidden">
                    <svg id="stickerBarcodeSvg" class="max-w-full h-14"></svg>
                </div>
            </div>

        </div>

        <!-- Official Footer Stamp -->
        <div class="pt-2 flex items-center justify-between text-[10px] text-slate-500 font-bold border-t border-slate-100">
            <span>رقم السند: <?= htmlspecialchars($qrToken) ?></span>
            <span class="text-amber-800 font-num">EURL TAXI NAGUILOU • 2026</span>
        </div>

    </div>

    <!-- Scripts to render QR and Barcode -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const verifyUrl = <?= json_encode($verifyUrl) ?>;
        const barcodeText = <?= json_encode($barcodeText) ?>;

        // 1. Render QR Code
        const qrContainer = document.getElementById('stickerQrContainer');
        if (qrContainer && typeof QRCode !== 'undefined') {
            new QRCode(qrContainer, {
                text: verifyUrl,
                width: 140,
                height: 140,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }

        // 2. Render Barcode (Code 128)
        if (typeof JsBarcode !== 'undefined') {
            try {
                JsBarcode('#stickerBarcodeSvg', barcodeText, {
                    format: 'CODE128',
                    lineColor: '#0f172a',
                    width: 2,
                    height: 48,
                    displayValue: true,
                    fontSize: 12,
                    fontOptions: 'bold',
                    font: 'Cairo',
                    margin: 4
                });
            } catch (e) {
                console.error('Barcode render error:', e);
            }
        }
    });
    </script>

</body>
</html>
