/**
 * Taxi Naguilou - Global Theme Controller (Light/Dark Mode)
 * Accessible, instant toggle without reloads, persistent via localStorage & OS sync
 */
(function() {
    'use strict';

    const STORAGE_KEY = 'tn_theme';

    window.TaxiTheme = {
        getPreferredTheme: function() {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored === 'dark' || stored === 'light') {
                return stored;
            }
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                return 'dark';
            }
            return 'light';
        },

        applyTheme: function(theme) {
            const root = document.documentElement;
            if (theme === 'dark') {
                root.classList.add('dark');
            } else {
                root.classList.remove('dark');
            }
            this.updateIcons();
            try {
                window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
            } catch (e) {}
        },

        setTheme: function(theme) {
            if (theme !== 'dark' && theme !== 'light') return;
            localStorage.setItem(STORAGE_KEY, theme);
            this.applyTheme(theme);
        },

        toggle: function() {
            const current = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            this.setTheme(next);
            return next;
        },

        updateIcons: function() {
            const isDark = document.documentElement.classList.contains('dark');
            const buttons = document.querySelectorAll('.theme-toggle-btn');
            buttons.forEach(function(btn) {
                const sunIcon = btn.querySelector('.theme-icon-sun');
                const moonIcon = btn.querySelector('.theme-icon-moon');
                const label = btn.querySelector('.theme-toggle-label');
                
                if (isDark) {
                    if (sunIcon) sunIcon.classList.remove('hidden');
                    if (moonIcon) moonIcon.classList.add('hidden');
                    btn.setAttribute('title', 'التبديل إلى الوضع الفاتح (Light Mode)');
                    btn.setAttribute('aria-label', 'التبديل إلى الوضع الفاتح');
                    if (label) label.textContent = 'الوضع الفاتح';
                } else {
                    if (sunIcon) sunIcon.classList.add('hidden');
                    if (moonIcon) moonIcon.classList.remove('hidden');
                    btn.setAttribute('title', 'التبديل إلى الوضع الليلي (Dark Mode)');
                    btn.setAttribute('aria-label', 'التبديل إلى الوضع الليلي');
                    if (label) label.textContent = 'الوضع الليلي';
                }
            });
        },

        init: function() {
            const theme = this.getPreferredTheme();
            this.applyTheme(theme);

            // Listen for system changes when no manual override was set
            if (window.matchMedia) {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
                    if (!localStorage.getItem(STORAGE_KEY)) {
                        TaxiTheme.applyTheme(e.matches ? 'dark' : 'light');
                    }
                });
            }

            // Sync icons on DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    TaxiTheme.updateIcons();
                });
            } else {
                this.updateIcons();
            }
        }
    };

    // Auto-initialize immediately
    window.TaxiTheme.init();
})();
