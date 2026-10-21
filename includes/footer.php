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
    <!-- Universal AJAX Live Search & Pagination -->
    <script src="<?= url('/assets/js/ajax-search.js') ?>"></script>
</body>
</html>
