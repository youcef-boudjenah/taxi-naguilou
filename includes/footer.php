        </main>

        <!-- Desktop ERP Global Footer -->
        <footer class="mt-auto bg-white border-t border-slate-200/90 py-4 px-4 sm:px-6">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span class="font-bold text-slate-800">تاكسي نقيلو (Taxi Naguilou ERP)</span>
                    <span>•</span>
                    <span>تسيير حظائر سيارات الأجرة وفق المرسوم التنفيذي 12-230 والقانون 90-11</span>
                </div>
                <div class="flex items-center gap-4 text-[11px] font-semibold text-slate-400">
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

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            const notifDropdown = document.getElementById('notifDropdown');
            const notifBtn = document.getElementById('notifBellBtn');
            if (notifDropdown && notifBtn && !notifDropdown.contains(e.target) && !notifBtn.contains(e.target)) {
                notifDropdown.classList.add('hidden');
            }
            const mobileMenu = document.getElementById('mobileNavDrawer');
            const mobileBtn = document.getElementById('mobileNavBtn');
            if (mobileMenu && mobileBtn && !mobileMenu.contains(e.target) && !mobileBtn.contains(e.target)) {
                mobileMenu.classList.add('hidden');
            }
            if (!e.target.closest('.table-dropdown-menu') && !e.target.closest('.table-dropdown-btn')) {
                document.querySelectorAll('.table-dropdown-menu:not(.hidden)').forEach(function(m) {
                    m.classList.add('hidden');
                });
            }
        });

        // Universal Table 3-Dots Action Dropdown
        window.toggleTableDropdown = function(e, btn) {
            e.stopPropagation();
            const parent = btn.closest('.relative') || btn.parentElement;
            const menu = parent ? parent.querySelector('.table-dropdown-menu') : null;
            if (!menu) return;
            const isHidden = menu.classList.contains('hidden');

            // Close any other open table dropdown
            document.querySelectorAll('.table-dropdown-menu:not(.hidden)').forEach(function(m) {
                if (m !== menu) m.classList.add('hidden');
            });

            if (isHidden) {
                menu.classList.remove('hidden');
                
                // Position fixed so parent overflow-x/overflow-y in tables never clips it
                const btnRect = btn.getBoundingClientRect();
                menu.style.position = 'fixed';
                menu.style.zIndex = '99999';

                const menuWidth = menu.offsetWidth || 192;
                let left = btnRect.left;
                if (left + menuWidth > window.innerWidth - 12) {
                    left = window.innerWidth - menuWidth - 12;
                }
                if (left < 12) left = 12;
                menu.style.left = left + 'px';
                menu.style.right = 'auto';

                const menuHeight = menu.offsetHeight || 160;
                if (btnRect.bottom + menuHeight > window.innerHeight - 15) {
                    menu.style.top = Math.max(10, btnRect.top - menuHeight - 4) + 'px';
                } else {
                    menu.style.top = (btnRect.bottom + 4) + 'px';
                }
            } else {
                menu.classList.add('hidden');
            }
        };

        // Close menu upon item selection
        document.addEventListener('click', function(e) {
            const item = e.target.closest('.table-dropdown-item');
            if (item) {
                const menu = item.closest('.table-dropdown-menu');
                if (menu) menu.classList.add('hidden');
            }
        });

        // Close table dropdowns on window scroll
        window.addEventListener('scroll', function() {
            document.querySelectorAll('.table-dropdown-menu:not(.hidden)').forEach(function(m) {
                m.classList.add('hidden');
            });
        }, true);

        // Close on Escape
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.table-dropdown-menu:not(.hidden)').forEach(function(m) {
                    m.classList.add('hidden');
                });
            }
        });
    </script>
</body>
</html>
