<?php
// index.php - Taxi Naguilou Clean & Simple Landing Page
require_once __DIR__ . '/includes/auth.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تاكسي نقيلو | إدارة سيارات ومكاتب الأجرة في الجزائر</title>
    <meta name="description" content="منصة تاكسي نقيلو - الحل الأبسط لإدارة سيارات الأجرة، متابعة صلاحية الوثائق، وتوليد عقود العمل القانونية في الجزائر.">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alexandria:wght@400;500;600;700;800;900&family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        amber: {
                            500: '#f59e0b',
                            600: '#d97706'
                        }
                    },
                    fontFamily: {
                        sans: ['Alexandria', 'Cairo', 'Tajawal', 'sans-serif'],
                        num: ['Plus Jakarta Sans', 'Alexandria', 'Cairo', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <?= render_theme_head() ?>

    <style>
        html, body, input, button, select, textarea {
            font-family: 'Alexandria', 'Cairo', 'Tajawal', sans-serif;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
        }
        html[dir="rtl"], [dir="rtl"], h1, h2, h3, h4, h5, h6, p, span, a, button, label, input, td, th {
            letter-spacing: normal !important;
        }
        .font-num, .font-mono, [dir="ltr"] {
            font-family: 'Plus Jakarta Sans', 'Alexandria', 'Cairo', sans-serif !important;
            font-feature-settings: "tnum" 1;
            font-variant-numeric: tabular-nums;
        }
        ::selection {
            background-color: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body class="bg-white text-slate-800 antialiased selection:bg-amber-100 selection:text-amber-900">

    <!-- Simple Top Bar -->
    <div class="bg-slate-900 text-slate-200 text-xs py-2 px-4 text-center">
        <span>✓ مطابقة تامة للقانون الجزائري 90-11 وتنظيمات مديريات النقل</span>
    </div>

    <!-- Clean Header -->
    <header class="border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white/95 dark:bg-[#111827]/95 backdrop-blur z-50">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-18 flex items-center justify-between py-4">
            <!-- Brand -->
            <a href="<?= url('/') ?>" class="flex items-center gap-3">
                <img src="<?= url('/logo.webp') ?>" alt="تاكسي نقيلو" class="w-11 h-11 object-contain rounded-full bg-white p-0.5 border border-amber-300 shadow-sm shrink-0">
                <div>
                    <span class="text-xl font-extrabold text-slate-900 dark:text-white tracking-tight block leading-none">تاكسي <span class="text-amber-600 dark:text-amber-400">نقيلو</span></span>
                    <span class="text-[10px] text-slate-400 font-bold block mt-0.5 uppercase tracking-wider">EURL TAXI NAGUILOU Services</span>
                </div>
            </a>

            <!-- Nav Links -->
            <nav class="hidden md:flex items-center gap-8 text-sm font-semibold text-slate-600 dark:text-slate-300">
                <a href="#features" class="hover:text-amber-600 dark:hover:text-amber-400 transition-colors">المميزات</a>
                <a href="#how-it-works" class="hover:text-amber-600 dark:hover:text-amber-400 transition-colors">كيف يعمل</a>
                <a href="#pricing" class="hover:text-amber-600 dark:hover:text-amber-400 transition-colors">الأسعار</a>
                <a href="#faq" class="hover:text-amber-600 dark:hover:text-amber-400 transition-colors">الأسئلة الشائعة</a>
            </nav>

            <div class="flex items-center gap-2">
                <?= render_theme_toggle_btn() ?>
                <a href="<?= url('/auth/login.php') ?>" class="inline-flex items-center justify-center px-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold text-xs transition">
                    دخول المنصة
                </a>
                <a href="#register" class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm">
                    تجربة مجانية
                </a>
            </div>
        </div>
    </header>

    <!-- Clean Hero -->
    <section class="py-16 md:py-24 bg-gradient-to-b from-slate-50 to-white dark:from-[#111827] dark:to-[#0b0f14] border-b border-slate-100 dark:border-slate-800/80">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center space-y-6">
            
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 text-amber-800 dark:text-amber-300 text-xs font-semibold shadow-2xs">
                <span>✨ المنصة الأبسط لإدارة التاكسي في الجزائر</span>
            </div>

            <h1 class="text-3xl sm:text-5xl font-black text-slate-950 dark:text-white leading-tight tracking-tight">
                أدِر سيارات الأجرة ووثائقها <br class="hidden sm:inline" />
                <span class="text-amber-600 dark:text-amber-400">بكل سهولة وبدون تعقيد</span>
            </h1>

            <p class="text-base sm:text-lg text-slate-600 dark:text-slate-300 max-w-2xl mx-auto leading-relaxed">
                تتبع مواعيد المراقبة التقنية والتأمين، ولّد عقود العمل القانونية (90-11)، وعيّن السائق البديل في ثوانٍ مع تجنب غرامات المحشر.
            </p>

            <div class="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="#register" class="w-full sm:w-auto px-6 py-3.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-sm transition shadow">
                    ابدأ تجربة مجانية (14 يوماً)
                </a>
                <a href="tel:0550000000" class="w-full sm:w-auto px-6 py-3.5 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 bg-white dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 font-bold text-sm transition">
                    📞 اتصل بنا مباشرة
                </a>
            </div>

            <div class="pt-4 flex items-center justify-center gap-6 text-xs text-slate-500 dark:text-slate-400">
                <span>✓ بدون بطاقة بنكية</span>
                <span>✓ إعداد في 5 دقائق</span>
                <span>✓ متوافق مع جميع الهواتف</span>
            </div>

            <!-- Clean Preview Card -->
            <div class="pt-8 max-w-2xl mx-auto">
                <div class="bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200 dark:border-slate-700/80 p-6 shadow-sm text-right space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700/60 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-100">سيارة أجرة: 01458-121-16 (بيجو 301)</span>
                        </div>
                        <span class="text-xs bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-500/30 font-bold px-2 py-0.5 rounded">الوضعية: نظامية</span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center text-xs">
                        <div class="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60">
                            <div class="text-slate-400">المراقبة التقنية</div>
                            <div class="font-bold text-emerald-600 dark:text-emerald-400 mt-1 font-num">باقي 72 يوم</div>
                        </div>
                        <div class="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60">
                            <div class="text-slate-400">تأمين المسؤولية</div>
                            <div class="font-bold text-emerald-600 dark:text-emerald-400 mt-1">صالح (SAA)</div>
                        </div>
                        <div class="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60">
                            <div class="text-slate-400">عقد العمل</div>
                            <div class="font-bold text-emerald-600 dark:text-emerald-400 mt-1">ساري (90-11)</div>
                        </div>
                        <div class="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60">
                            <div class="text-slate-400">الساكوريومتر</div>
                            <div class="font-bold text-emerald-600 dark:text-emerald-400 mt-1">مختوم ومفحوص</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- 3 Simple Features -->
    <section id="features" class="py-16 bg-white dark:bg-[#0b0f14] border-t border-slate-100 dark:border-slate-800/80">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            
            <div class="text-center max-w-xl mx-auto mb-12 space-y-2">
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white">كل ما تحتاجه في مكان واحد</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">بدون تعقيدات برمجية أو إعدادات صعبة.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Feature 1 -->
                <div class="p-6 rounded-2xl bg-slate-50 dark:bg-[#161d27] border border-slate-100 dark:border-slate-800 space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400 flex items-center justify-center text-lg font-bold">
                        ⏰
                    </div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">تنبيهات انتهاء الوثائق</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        تنبيه قبل انتهاء التأمين، الفحص التقني، قارورة الإطفاء وخزان سيرغاز لتفادي حجز السيارة والغرامات.
                    </p>
                </div>

                <!-- Feature 2 -->
                <div class="p-6 rounded-2xl bg-slate-50 dark:bg-[#161d27] border border-slate-100 dark:border-slate-800 space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400 flex items-center justify-center text-lg font-bold">
                        📄
                    </div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">عقود العمل القانونية 90-11</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        توليد عقد عمل نظامي رسمي مع السائق بضغطة زر، جاهز للطباعة أو التوقيع ومطابق لمفتشية العمل.
                    </p>
                </div>

                <!-- Feature 3 -->
                <div class="p-6 rounded-2xl bg-slate-50 dark:bg-[#161d27] border border-slate-100 dark:border-slate-800 space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400 flex items-center justify-center text-lg font-bold">
                        🔄
                    </div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">نظام السائق البديل</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        في حال عطلة أو مرض السائق الأساسي، يمكنك تعيين بديل وتوليد جدول الإرسال فورياً بدون توقف العمل.
                    </p>
                </div>
            </div>

        </div>
    </section>

    <!-- How it works (3 steps) -->
    <section id="how-it-works" class="py-16 bg-slate-50 dark:bg-[#111827] border-t border-slate-100 dark:border-slate-800/80">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12 space-y-2">
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white">كيف تبدأ في 3 خطوات بسيطة؟</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">لا تحتاج إلى خبرة تقنية إطلاقاً.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-center">
                <div class="bg-white dark:bg-[#161d27] p-6 rounded-2xl border border-slate-200 dark:border-slate-800 space-y-2">
                    <div class="w-8 h-8 rounded-full bg-slate-900 dark:bg-amber-500 text-white dark:text-slate-950 font-bold text-sm flex items-center justify-center mx-auto">1</div>
                    <h4 class="font-bold text-slate-900 dark:text-white text-sm">سجّل بياناتك</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400">أدخل اسمك ورقم هاتفك لتفعيل حسابك التجريبي فوراً.</p>
                </div>
                <div class="bg-white dark:bg-[#161d27] p-6 rounded-2xl border border-slate-200 dark:border-slate-800 space-y-2">
                    <div class="w-8 h-8 rounded-full bg-slate-900 dark:bg-amber-500 text-white dark:text-slate-950 font-bold text-sm flex items-center justify-center mx-auto">2</div>
                    <h4 class="font-bold text-slate-900 dark:text-white text-sm">أضف سيارتك وسائقك</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400">سجل رقم اللوحة وتواريخ الفحص والتأمين بنقرات سريعة.</p>
                </div>
                <div class="bg-white dark:bg-[#161d27] p-6 rounded-2xl border border-slate-200 dark:border-slate-800 space-y-2">
                    <div class="w-8 h-8 rounded-full bg-slate-900 dark:bg-amber-500 text-white dark:text-slate-950 font-bold text-sm flex items-center justify-center mx-auto">3</div>
                    <h4 class="font-bold text-slate-900 dark:text-white text-sm">استمتع براحة البال</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400">النظام يتكفل بالتنبيهات وطباعة العقود والإرساليات تلقائياً.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Clean Pricing -->
    <section id="pricing" class="py-16 bg-white dark:bg-[#0b0f14] border-t border-slate-100 dark:border-slate-800/80">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12 space-y-2">
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white">أسعار واضحة ومناسبة</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">بدون أي رسوم خفية أو التزامات معقدة.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-2xl mx-auto">
                <!-- Single Car -->
                <div class="p-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#161d27] space-y-4">
                    <div>
                        <h3 class="font-bold text-slate-900 dark:text-white text-lg">المالك الفردي</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">لسيارة تاكسي واحدة</p>
                    </div>
                    <div class="text-3xl font-black text-slate-900 dark:text-white font-num">
                        1,500 <span class="text-xs font-normal text-slate-500 dark:text-slate-400">دج / شهرياً</span>
                    </div>
                    <ul class="text-xs text-slate-600 dark:text-slate-300 space-y-2 border-t border-slate-100 dark:border-slate-800 pt-3">
                        <li>✓ متابعة وثائق السيارة بالكامل</li>
                        <li>✓ توليد عقود العمل 90-11</li>
                        <li>✓ تنبيهات الصلاحية عبر SMS</li>
                        <li>✓ دعم فني عبر الواتساب</li>
                    </ul>
                    <a href="#register" class="block w-full py-2.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:border-slate-400 dark:hover:border-slate-600 text-center text-xs font-bold text-slate-800 dark:text-slate-200 transition">
                        تجربة مجانية
                    </a>
                </div>

                <!-- Agency / Fleet -->
                <div class="p-6 rounded-2xl border-2 border-amber-500 bg-amber-50/30 dark:bg-amber-500/10 space-y-4 relative">
                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-amber-500 text-slate-950 text-[10px] font-bold px-3 py-0.5 rounded-full">
                        الأكثر اختياراً للمكاتب
                    </span>
                    <div>
                        <h3 class="font-bold text-slate-900 dark:text-white text-lg">المكاتب والتعاونيات</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">من 3 إلى 10 مركبات</p>
                    </div>
                    <div class="text-3xl font-black text-slate-900 dark:text-white font-num">
                        4,500 <span class="text-xs font-normal text-slate-500 dark:text-slate-400">دج / شهرياً</span>
                    </div>
                    <ul class="text-xs text-slate-600 dark:text-slate-300 space-y-2 border-t border-amber-100 dark:border-amber-500/20 pt-3">
                        <li>✓ إدارة حتى 10 سيارات وسائقين</li>
                        <li>✓ نظام السائق البديل والـ Bordereau</li>
                        <li>✓ عقود العمل وطباعة التراخيص</li>
                        <li>✓ تتبع المداخيل والاشتراكات</li>
                        <li>✓ دعم هاتفي ذو أولوية</li>
                    </ul>
                    <a href="#register" class="block w-full py-2.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-center text-xs font-bold text-slate-950 transition shadow-sm">
                        تجربة مجانية لمدة 14 يوماً
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Clean Simple Registration Form -->
    <section id="register" class="py-16 bg-slate-50 dark:bg-[#111827] border-t border-slate-100 dark:border-slate-800/80">
        <div class="max-w-xl mx-auto px-4 sm:px-6">
            <div class="bg-white dark:bg-[#161d27] p-8 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm space-y-6">
                
                <div class="text-center space-y-1">
                    <h2 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">سجّل الآن لتفعيل حسابك التجريبي</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">املأ البيانات البسيطة وسنتواصل معك فوراً لتجهيز حسابك.</p>
                </div>

                <div id="formToast" class="hidden p-3 rounded-lg text-xs font-bold"></div>

                <form id="leadForm" class="space-y-4 text-right">
                    <div>
                        <label for="fullName" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">الاسم الكامل *</label>
                        <input type="text" id="fullName" required placeholder="مثال: محمد بن علي"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-white text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div>
                        <label for="phone" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">رقم الهاتف *</label>
                        <input type="tel" id="phone" required placeholder="05XX XX XX XX" dir="ltr"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-white text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-right">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="wilaya" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">الولاية *</label>
                            <input type="text" id="wilaya" required placeholder="مثال: الجزائر، وهران، سطيف..."
                                   class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-white text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                        </div>

                        <div>
                            <label for="fleetSize" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">عدد سيارات الأجرة</label>
                            <input type="number" id="fleetSize" min="1" value="1"
                                   class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-white text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-num">
                        </div>
                    </div>

                    <button type="submit" id="submitBtn"
                            class="w-full py-3 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-sm transition shadow-sm">
                        طلب التفعيل المجاني ←
                    </button>

                    <p class="text-[11px] text-center text-slate-400">
                        معلوماتك محمية ولن تتم مشاركتها مع أي طرف خارجي.
                    </p>
                </form>

            </div>
        </div>
    </section>

    <!-- Simple FAQ -->
    <section id="faq" class="py-16 bg-white dark:bg-[#0b0f14] border-t border-slate-100 dark:border-slate-800/80">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 space-y-6">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white text-center mb-8">أسئلة شائعة</h2>

            <div class="space-y-3 text-xs">
                <div class="border border-slate-200 dark:border-slate-800 rounded-xl p-4 bg-white dark:bg-[#161d27]">
                    <div class="font-bold text-slate-900 dark:text-white mb-1">هل يمكنني تجربة المنصة مجاناً؟</div>
                    <p class="text-slate-600 dark:text-slate-300 leading-relaxed">نعم، نوفر فترة تجربة مجانية كاملة لمدة 14 يوماً بدون أي التزام مالي مسبق.</p>
                </div>
                <div class="border border-slate-200 dark:border-slate-800 rounded-xl p-4 bg-white dark:bg-[#161d27]">
                    <div class="font-bold text-slate-900 dark:text-white mb-1">هل العقود مطابقة للقانون الجزائري 90-11؟</div>
                    <p class="text-slate-600 dark:text-slate-300 leading-relaxed">نعم، تم تدقيق بنود العقود ونظام العمل وفقاً لقانون العمل والتنظيمات المعتمدة من مفتشية العمل.</p>
                </div>
                <div class="border border-slate-200 dark:border-slate-800 rounded-xl p-4 bg-white dark:bg-[#161d27]">
                    <div class="font-bold text-slate-900 dark:text-white mb-1">هل تعمل على هاتفي؟</div>
                    <p class="text-slate-600 dark:text-slate-300 leading-relaxed">نعم، تعمل بسلاسة وسرعة على أي هاتف ذكي (أندرويد أو آيفون) من المتصفح مباشرة.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Clean Footer -->
    <footer class="border-t border-slate-100 dark:border-slate-800 py-8 bg-slate-50 dark:bg-[#0b0f14] text-xs text-slate-500 dark:text-slate-400">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div>
                © <?= date('Y') ?> منصة تاكسي نقيلو (TAXI-NAGUILOU). الجزائر.
            </div>
            <div class="flex items-center gap-4">
                <span>📞 هاتف: 0550 00 00 00</span>
                <span>✉️ contact@taxinaguilou.dz</span>
            </div>
        </div>
    </footer>

    <!-- Form Script -->
    <script>
        const leadForm = document.getElementById('leadForm');
        const formToast = document.getElementById('formToast');
        const submitBtn = document.getElementById('submitBtn');

        if (leadForm) {
            leadForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                submitBtn.disabled = true;
                submitBtn.textContent = 'جاري الإرسال...';

                const data = {
                    full_name: document.getElementById('fullName').value,
                    phone: document.getElementById('phone').value,
                    wilaya: document.getElementById('wilaya').value,
                    fleet_size: document.getElementById('fleetSize').value
                };

                try {
                    const res = await fetch('api/submit_lead.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data)
                    });
                    const result = await res.json();
                    
                    formToast.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'bg-emerald-50', 'text-emerald-700');
                    if (result.success) {
                        formToast.classList.add('bg-emerald-50', 'text-emerald-700');
                        formToast.textContent = '✓ ' + result.message;
                        leadForm.reset();
                    } else {
                        formToast.classList.add('bg-red-50', 'text-red-700');
                        formToast.textContent = '✕ ' + (result.message || 'حدث خطأ، يرجى المحاولة ثانية');
                    }
                } catch (err) {
                    formToast.classList.remove('hidden');
                    formToast.classList.add('bg-red-50', 'text-red-700');
                    formToast.textContent = '✕ تعذر الاتصال، يرجى التأكد من اتصال الإنترنت.';
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'طلب التفعيل المجاني ←';
                }
            });
        }
    </script>
</body>
</html>
