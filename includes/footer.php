        </main>

        <!-- Desktop ERP Global Footer -->
        <footer class="mt-auto bg-white dark:bg-[#161d27] border-t border-slate-200/90 dark:border-slate-800 py-4 px-4 sm:px-6 no-print">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span class="font-bold text-slate-800 dark:text-slate-200">تاكسي نقيلو (Taxi Naguilou ERP)</span>
                    <span>•</span>
                    <span>تسيير حظائر سيارات الأجرة وفق المرسوم التنفيذي 12-230 والقانون 90-11</span>
                </div>
                <div class="flex items-center gap-4 text-[11px] font-semibold text-slate-400 dark:text-slate-500">
                    <span>الجمهورية الجزائرية الديمقراطية الشعبية</span>
                    <span>•</span>
                    <span class="font-num">v2.4.0 ERP</span>
                </div>
            </div>
        </footer>

    </div><!-- /flex-1 flex flex-col container -->

    <!-- Global Scripts: Search (Ctrl+K), Dropdown dismissing, Mobile Drawer -->
    <script>
        // Keyboard Shortcut: Ctrl+K or Cmd+K to focus search
        window.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                const searchInput = document.getElementById('launcherSearch') || document.getElementById('globalSearchInput');
                if (searchInput) {
                    e.preventDefault();
                    searchInput.focus();
                    if (searchInput.select) searchInput.select();
                }
            }
        });

        // State tracking for dropdowns
        let tableDropdownOpenedAt = 0;
        let tableDropdownScrollOrigin = { x: 0, y: 0 };

        // Universal App Bar Module Dropdown Toggle (Desktop Navigation)
        window.toggleAppBarDropdown = function(e, btn) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            const dropdown = btn.closest('.app-bar-dropdown');
            if (!dropdown) return;
            const isOpen = dropdown.classList.contains('is-open');

            // Close all other app bar dropdowns
            document.querySelectorAll('.app-bar-dropdown.is-open').forEach(function(d) {
                if (d !== dropdown) d.classList.remove('is-open');
            });

            if (isOpen) {
                dropdown.classList.remove('is-open');
            } else {
                dropdown.classList.add('is-open');
            }
        };

        // Close all open table dropdowns and return them to their original parent
        window.closeAllTableDropdowns = function() {
            document.querySelectorAll('.table-dropdown-menu:not(.hidden)').forEach(function(m) {
                m.classList.add('hidden');
                if (m._originalParent) {
                    m._originalParent.appendChild(m);
                    delete m._originalParent;
                }
                if (m._attachedBtn) {
                    delete m._attachedBtn._activeMenu;
                    delete m._attachedBtn;
                }
            });
        };

        // Universal Table 3-Dots Action Dropdown with Body Teleportation (Fixes Stacking Context & Slicing)
        window.toggleTableDropdown = function(e, btn) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            // If already open for this button, close it
            if (btn._activeMenu && !btn._activeMenu.classList.contains('hidden')) {
                closeAllTableDropdowns();
                return;
            }

            // Close any other open dropdowns first
            closeAllTableDropdowns();

            const parent = btn.closest('.relative') || btn.parentElement;
            const menu = parent ? parent.querySelector('.table-dropdown-menu') : null;
            if (!menu) return;

            // Teleport to document.body so sticky table header (z-index 25/30),
            // stacking context (td z-index 12), and table overflow NEVER slice or clip the menu
            menu._originalParent = parent;
            menu._attachedBtn = btn;
            btn._activeMenu = menu;
            document.body.appendChild(menu);

            menu.classList.remove('hidden');
            tableDropdownOpenedAt = Date.now();
            tableDropdownScrollOrigin = { 
                x: window.scrollX || window.pageXOffset || 0, 
                y: window.scrollY || window.pageYOffset || 0 
            };
            
            menu.style.position = 'fixed';
            menu.style.zIndex = '999999';

            const btnRect = btn.getBoundingClientRect();
            const menuWidth = Math.max(240, menu.offsetWidth || 240);
            
            // RTL alignment: if button is near left edge (sticky action col in RTL table), open to the right from btn.left
            let left = btnRect.right - menuWidth;
            if (left < 12) {
                left = Math.max(12, btnRect.left);
            }
            if (left + menuWidth > window.innerWidth - 12) {
                left = window.innerWidth - menuWidth - 12;
            }
            menu.style.left = left + 'px';
            menu.style.right = 'auto';

            // Accurate menu height measurement
            const itemCount = menu.querySelectorAll('.table-dropdown-item').length;
            const computedHeight = menu.offsetHeight && menu.offsetHeight > 40 ? menu.offsetHeight : (itemCount * 42 + 20);
            const menuHeight = Math.max(80, computedHeight);
            const spaceBelow = window.innerHeight - btnRect.bottom - 12;
            const spaceAbove = btnRect.top - 12;

            if (spaceBelow >= menuHeight || spaceBelow >= spaceAbove) {
                // Open downwards
                menu.style.top = (btnRect.bottom + 6) + 'px';
                menu.style.maxHeight = Math.max(140, spaceBelow) + 'px';
            } else {
                // Open upwards with exact height alignment
                const targetTop = Math.max(12, btnRect.top - menuHeight - 6);
                menu.style.top = targetTop + 'px';
                menu.style.maxHeight = Math.max(140, btnRect.top - 18) + 'px';
            }
            menu.style.overflowY = 'auto';
        };

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            // 1. Notification dropdown
            const notifDropdown = document.getElementById('notifDropdown');
            const notifBtn = document.getElementById('notifBellBtn');
            if (notifDropdown && notifBtn && !notifDropdown.contains(e.target) && !notifBtn.contains(e.target)) {
                notifDropdown.classList.add('hidden');
            }

            // 2. Mobile Nav Drawer
            const mobileMenu = document.getElementById('mobileNavDrawer');
            const mobileBtn = document.getElementById('mobileNavBtn');
            if (mobileMenu && mobileBtn && !mobileMenu.contains(e.target) && !mobileBtn.contains(e.target)) {
                mobileMenu.classList.add('hidden');
            }

            // 3. Table Action dropdowns
            if (!e.target.closest('.table-dropdown-menu') && !e.target.closest('.table-dropdown-btn')) {
                closeAllTableDropdowns();
            }

            // 4. App Bar module dropdowns
            if (!e.target.closest('.app-bar-dropdown')) {
                document.querySelectorAll('.app-bar-dropdown.is-open').forEach(function(d) {
                    d.classList.remove('is-open');
                });
            }
        });

        // Close menu upon item selection
        document.addEventListener('click', function(e) {
            const item = e.target.closest('.table-dropdown-item');
            if (item) {
                closeAllTableDropdowns();
            }
            const appBarItem = e.target.closest('.app-bar-dropdown-item');
            if (appBarItem) {
                const dropdown = appBarItem.closest('.app-bar-dropdown');
                if (dropdown) dropdown.classList.remove('is-open');
            }
        });

        // Close table dropdowns on window scroll or table horizontal/vertical scrolling
        window.addEventListener('scroll', function() {
            if (Date.now() - tableDropdownOpenedAt < 250) {
                return; // Grace period immediately after opening
            }
            const curX = window.scrollX || window.pageXOffset || 0;
            const curY = window.scrollY || window.pageYOffset || 0;
            const dx = Math.abs(curX - tableDropdownScrollOrigin.x);
            const dy = Math.abs(curY - tableDropdownScrollOrigin.y);

            if (dx > 30 || dy > 30) {
                closeAllTableDropdowns();
            }
        }, false);

        // Catch table container internal scrolling
        document.addEventListener('scroll', function(e) {
            if (e.target && (
                (e.target.classList && (e.target.classList.contains('table-sticky-container') || e.target.classList.contains('overflow-x-auto'))) || 
                (e.target.closest && e.target.closest('.table-sticky-container'))
            )) {
                closeAllTableDropdowns();
            }
        }, true);

        // Close all on Escape key
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllTableDropdowns();
                document.querySelectorAll('.app-bar-dropdown.is-open').forEach(function(d) {
                    d.classList.remove('is-open');
                });
                const notifDropdown = document.getElementById('notifDropdown');
                if (notifDropdown) notifDropdown.classList.add('hidden');
                const mobileMenu = document.getElementById('mobileNavDrawer');
                if (mobileMenu) mobileMenu.classList.add('hidden');
            }
        });
    </script>

    <!-- Live Global Activity Notifications (أسفل الصفحة في اليسار - Strict Bottom-Left Dock) -->
    <div id="liveActivityToastContainer" 
         class="no-print" 
         dir="rtl" 
         aria-live="polite">
    </div>

    <style>
    /* Absolute Rock-Solid Bottom-Left Toast Dock (Never overlaps navbar or cards) */
    #liveActivityToastContainer {
        position: fixed !important;
        bottom: 24px !important;
        left: 24px !important;
        right: auto !important;
        top: auto !important;
        z-index: 999999 !important;
        display: flex !important;
        flex-direction: column-reverse !important;
        align-items: flex-start !important;
        gap: 10px !important;
        width: 350px !important;
        max-width: calc(100vw - 32px) !important;
        pointer-events: none !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }
    @media (max-width: 640px) {
        #liveActivityToastContainer {
            bottom: 12px !important;
            left: 12px !important;
            right: 12px !important;
            width: auto !important;
            max-width: calc(100vw - 24px) !important;
        }
    }

    /* Impeccable Toast Card Design */
    .live-toast-card {
        pointer-events: auto !important;
        width: 100% !important;
        max-width: 350px !important;
        box-sizing: border-box !important;
        background: #ffffff !important;
        color: #0f172a !important;
        border-radius: 14px !important;
        border: 1px solid #e2e8f0 !important;
        border-right: 4px solid #64748b !important;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.12), 0 8px 10px -6px rgba(0, 0, 0, 0.08) !important;
        padding: 10px 12px !important;
        margin: 0 !important;
        direction: rtl !important;
        text-align: right !important;
        position: relative !important;
        overflow: hidden !important;
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease, opacity 0.25s ease !important;
    }
    .dark .live-toast-card,
    [data-theme="dark"] .live-toast-card {
        background: #182230 !important;
        color: #f8fafc !important;
        border: 1px solid #334155 !important;
        box-shadow: 0 14px 30px -6px rgba(0, 0, 0, 0.5), 0 8px 12px -6px rgba(0, 0, 0, 0.35) !important;
    }
    .live-toast-card:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 16px 32px -4px rgba(0, 0, 0, 0.2) !important;
    }

    /* Actor Accent Colors */
    .live-toast-card.actor-driver { border-right-color: #f59e0b !important; }
    .live-toast-card.actor-client { border-right-color: #0284c7 !important; }
    .live-toast-card.actor-admin  { border-right-color: #8b5cf6 !important; }
    .live-toast-card.actor-system { border-right-color: #10b981 !important; }

    /* Progress bar */
    .toast-progress-bar {
        position: absolute !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        height: 3px !important;
        background: linear-gradient(to right, #f59e0b, #e11d48) !important;
        transition: width 0.05s linear !important;
        transform-origin: right !important;
    }

    /* Clear All Button Pill */
    .toast-clear-all-wrap {
        pointer-events: auto !important;
        display: flex !important;
        justify-content: flex-start !important;
        width: 100% !important;
        margin-bottom: 2px !important;
    }
    .toast-clear-all-btn {
        background: rgba(15, 23, 42, 0.85) !important;
        color: #ffffff !important;
        border: 1px solid rgba(255, 255, 255, 0.2) !important;
        border-radius: 9999px !important;
        padding: 3px 10px !important;
        font-size: 10px !important;
        font-weight: 700 !important;
        cursor: pointer !important;
        backdrop-filter: blur(8px) !important;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15) !important;
        transition: all 0.15s ease !important;
    }
    .toast-clear-all-btn:hover {
        background: rgba(15, 23, 42, 1) !important;
        transform: scale(1.04) !important;
    }
    .dark .toast-clear-all-btn,
    [data-theme="dark"] .toast-clear-all-btn {
        background: rgba(255, 255, 255, 0.9) !important;
        color: #0f172a !important;
    }

    @keyframes toastSlideInBottomLeft {
        0% {
            opacity: 0;
            transform: translate3d(-20px, 15px, 0) scale(0.95);
        }
        100% {
            opacity: 1;
            transform: translate3d(0, 0, 0) scale(1);
        }
    }
    @keyframes toastSlideOutBottomLeft {
        0% {
            opacity: 1;
            transform: translate3d(0, 0, 0) scale(1);
        }
        100% {
            opacity: 0;
            transform: translate3d(-30px, 5px, 0) scale(0.92);
        }
    }
    .toast-animate-in {
        animation: toastSlideInBottomLeft 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
    }
    .toast-animate-out {
        animation: toastSlideOutBottomLeft 0.2s cubic-bezier(0.7, 0, 0.84, 0) forwards !important;
    }
    </style>

    <script>
    (function() {
        let lastActivityLogId = 0;
        let isPolling = false;
        let pollIntervalMs = 4500;
        let pollTimer = null;
        let isAudioUnlocked = false;

        // Unlock Web Audio API on first user interaction
        function unlockAudio() {
            isAudioUnlocked = true;
            window.removeEventListener('click', unlockAudio);
            window.removeEventListener('keydown', unlockAudio);
            window.removeEventListener('touchstart', unlockAudio);
        }
        window.addEventListener('click', unlockAudio, { once: true });
        window.addEventListener('keydown', unlockAudio, { once: true });
        window.addEventListener('touchstart', unlockAudio, { once: true });

        // Pleasant synthesized chime via Web Audio API (Zero external audio file dependency)
        function playChime() {
            try {
                if (localStorage.getItem('taxi_toast_sound') === 'off') return;
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return;
                const ctx = new AudioCtx();
                const now = ctx.currentTime;
                
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                
                // Two-tone bell melody (D5 -> A5)
                osc.frequency.setValueAtTime(587.33, now);
                osc.frequency.exponentialRampToValueAtTime(880.00, now + 0.12);
                
                gain.gain.setValueAtTime(0.08, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.40);
                
                osc.connect(gain);
                gain.connect(ctx.destination);
                
                osc.start(now);
                osc.stop(now + 0.40);
            } catch(e) {}
        }

        // Manage "Dismiss All" button pill
        function updateClearAllButton() {
            const container = document.getElementById('liveActivityToastContainer');
            if (!container) return;
            const existingBtn = container.querySelector('.toast-clear-all-wrap');
            const toastCount = container.querySelectorAll('.live-toast-card').length;

            if (toastCount >= 2) {
                if (!existingBtn) {
                    const wrap = document.createElement('div');
                    wrap.className = 'toast-clear-all-wrap';
                    wrap.innerHTML = `<button type="button" class="toast-clear-all-btn">مسح الإشعارات (${toastCount}) ✕</button>`;
                    wrap.querySelector('button').addEventListener('click', () => {
                        container.querySelectorAll('.live-toast-card').forEach(t => removeToast(t));
                    });
                    container.appendChild(wrap);
                } else {
                    const btn = existingBtn.querySelector('button');
                    if (btn) btn.textContent = `مسح الإشعارات (${toastCount}) ✕`;
                }
            } else if (existingBtn) {
                existingBtn.remove();
            }
        }

        // Render toast element in the bottom-left container
        window.showLiveToast = function(item, playSound = true) {
            const container = document.getElementById('liveActivityToastContainer');
            if (!container) return;

            // Cap visible toasts to max 3 to keep screen clean
            const currentToasts = container.querySelectorAll('.live-toast-card');
            if (currentToasts.length >= 3) {
                removeToast(currentToasts[0]);
            }

            // Theme styling based on actor role
            const colors = {
                driver: {
                    badgeStyle: 'background: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.3);',
                    dotColor: '#f59e0b',
                },
                client: {
                    badgeStyle: 'background: rgba(2, 132, 199, 0.15); color: #0284c7; border: 1px solid rgba(2, 132, 199, 0.3);',
                    dotColor: '#0284c7',
                },
                admin: {
                    badgeStyle: 'background: rgba(139, 92, 246, 0.15); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.3);',
                    dotColor: '#8b5cf6',
                },
                system: {
                    badgeStyle: 'background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3);',
                    dotColor: '#10b981',
                }
            };
            const theme = colors[item.actor_type] || colors.admin;

            const toast = document.createElement('div');
            toast.className = `live-toast-card actor-${item.actor_type || 'admin'} toast-animate-in`;

            const linkHtml = item.link ? `
                <a href="${item.link}" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px; font-weight: 700; color: #d97706; text-decoration: none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                    <span>معاينة التفاصيل</span>
                    <span dir="ltr">↗</span>
                </a>
            ` : '';

            toast.innerHTML = `
                <!-- Progress bar countdown -->
                <div class="toast-progress-bar" style="width: 100%;"></div>

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding-bottom: 6px; border-bottom: 1px solid rgba(148, 163, 184, 0.2); font-size: 11px;">
                    <div style="display: flex; align-items: center; gap: 6px; min-width: 0;">
                        <span style="width: 7px; height: 7px; border-radius: 50%; background: ${theme.dotColor}; display: inline-block;"></span>
                        <span style="padding: 1px 7px; border-radius: 9999px; font-size: 10px; font-weight: 700; ${theme.badgeStyle}">
                            ${escapeHtml(item.actor_badge || 'إشعار')}
                        </span>
                        <span style="font-weight: 700; color: inherit; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 140px;">
                            ${escapeHtml(item.actor_name || '')}
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px; shrink: 0;">
                        <span style="font-size: 10px; opacity: 0.6; font-family: monospace;">${escapeHtml(item.time || 'الآن')}</span>
                        <button type="button" class="toast-close-btn" style="background: none; border: none; padding: 2px 5px; font-size: 13px; opacity: 0.5; cursor: pointer; color: inherit; border-radius: 4px;" title="إغلاق">✕</button>
                    </div>
                </div>

                <div style="padding-top: 6px; display: flex; flex-direction: column; gap: 3px;">
                    <div style="font-size: 12px; font-weight: 700; color: inherit; display: flex; align-items: center; gap: 5px;">
                        <span>${item.icon || '📌'}</span>
                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(item.title || '')}</span>
                    </div>
                    <p style="margin: 0; font-size: 11px; line-height: 1.35; opacity: 0.8; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                        ${escapeHtml(item.details || '')}
                    </p>
                </div>

                ${linkHtml ? `<div style="display: flex; justify-content: flex-end; padding-top: 4px;">${linkHtml}</div>` : ''}
            `;

            container.appendChild(toast);
            updateClearAllButton();
            if (playSound) playChime();

            // Progress bar and auto-dismiss timer (6 seconds)
            const duration = 6000;
            let remaining = duration;
            let timerId = null;
            let isPaused = false;
            const bar = toast.querySelector('.toast-progress-bar');

            function startTimer() {
                const interval = 50;
                timerId = setInterval(() => {
                    if (isPaused) return;
                    remaining -= interval;
                    if (bar) {
                        const pct = Math.max(0, (remaining / duration) * 100);
                        bar.style.width = pct + '%';
                    }
                    if (remaining <= 0) {
                        clearInterval(timerId);
                        removeToast(toast);
                    }
                }, interval);
            }

            // Pause on hover
            toast.addEventListener('mouseenter', () => { isPaused = true; });
            toast.addEventListener('mouseleave', () => { isPaused = false; });

            // Manual close button
            const closeBtn = toast.querySelector('.toast-close-btn');
            if (closeBtn) {
                closeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    clearInterval(timerId);
                    removeToast(toast);
                });
            }

            startTimer();
        };

        function removeToast(toast) {
            if (!toast || toast._removing) return;
            toast._removing = true;
            toast.classList.remove('toast-animate-in');
            toast.classList.add('toast-animate-out');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
                updateClearAllButton();
            }, 250);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Live Polling Routine
        function pollLiveActivity() {
            if (isPolling) return;
            isPolling = true;

            const endpoint = '<?= url('/dashboard/ajax_live_activity.php') ?>?since_id=' + encodeURIComponent(lastActivityLogId);

            fetch(endpoint, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                isPolling = false;
                if (!data || !data.success) return;

                // Sync cursor
                if (lastActivityLogId === 0) {
                    lastActivityLogId = data.current_max_id || 0;
                    return;
                }

                if (Array.isArray(data.events) && data.events.length > 0) {
                    // Deduplicate identical events in this batch (prevent duplicate spam)
                    const deduplicated = [];
                    const seenKeys = new Set();
                    for (let i = data.events.length - 1; i >= 0; i--) {
                        const ev = data.events[i];
                        const key = (ev.title || '') + '|' + (ev.actor_type || '');
                        if (!seenKeys.has(key)) {
                            seenKeys.add(key);
                            deduplicated.unshift(ev);
                        }
                    }

                    // Display up to 2 items max per poll cycle to avoid flooding
                    const toShow = deduplicated.slice(-2);
                    let playedChime = false;
                    toShow.forEach(ev => {
                        window.showLiveToast(ev, !playedChime);
                        playedChime = true;
                    });

                    // Advance cursor to highest ID seen
                    data.events.forEach(ev => {
                        if (ev.id > lastActivityLogId) {
                            lastActivityLogId = ev.id;
                        }
                    });
                } else if (data.current_max_id > lastActivityLogId) {
                    lastActivityLogId = data.current_max_id;
                }
            })
            .catch(err => {
                isPolling = false;
                // Soft fail silently on connection hiccup
            });
        }

        // Initialize polling when DOM is ready
        function initLiveActivity() {
            pollLiveActivity(); // Initial cursor sync
            pollTimer = setInterval(pollLiveActivity, pollIntervalMs);

            // Handle tab focus / blur to save resources
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    clearInterval(pollTimer);
                    pollTimer = setInterval(pollLiveActivity, 15000); // Slower polling in background
                } else {
                    clearInterval(pollTimer);
                    pollLiveActivity(); // Immediate check on returning to tab
                    pollTimer = setInterval(pollLiveActivity, pollIntervalMs);
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initLiveActivity);
        } else {
            initLiveActivity();
        }
    })();
    </script>
    <!-- Universal AJAX Live Search & Pagination -->
    <script src="<?= url('/assets/js/ajax-search.js') ?>"></script>
</body>
</html>
