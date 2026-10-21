/**
 * Taxi Naguilou - Universal AJAX Live Search & Seamless Table Pagination
 * Features:
 * - Live debounced search as you type (260ms)
 * - Zero page reloads (smooth DOM morphing of table + pagination + counts)
 * - Race-condition safe with AbortController
 * - Inline animated SVG loading spinner & instant clear (✕) button
 * - Browser history & URL synchronization (history.replaceState)
 * - Seamless AJAX pagination support
 * - Auto-binds to any form containing input[name="search"]
 */

(function () {
    'use strict';

    let activeAbortController = null;
    let debounceTimer = null;

    function initAjaxSearch() {
        // Query all GET forms that contain a search input
        const forms = document.querySelectorAll('form[method="GET"]:has(input[name="search"]), form[method="get"]:has(input[name="search"]), form:has(input[name="search"])');

        forms.forEach(function (form) {
            const input = form.querySelector('input[name="search"], input[type="search"]');
            if (!input || input.dataset.ajaxSearchBound === 'true') return;

            input.dataset.ajaxSearchBound = 'true';
            setupSearchUI(form, input);

            // Live search on typing
            input.addEventListener('input', function () {
                toggleClearButton(input);
                triggerSearch(form, input, 260);
            });

            // Handle Enter key or search button submit
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                triggerSearch(form, input, 0);
            });

            // Handle Escape key to clear search
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && input.value.trim().length > 0) {
                    e.preventDefault();
                    input.value = '';
                    toggleClearButton(input);
                    triggerSearch(form, input, 0);
                }
            });
        });
    }

    function setupSearchUI(form, input) {
        const wrapper = input.closest('.relative') || input.parentElement;
        if (!wrapper) return;

        // 1. Clear Button (✕)
        let clearBtn = wrapper.querySelector('.ajax-search-clear-btn');
        if (!clearBtn) {
            clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'ajax-search-clear-btn absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-rose-500 dark:hover:text-rose-400 transition-colors p-1 rounded-md hidden cursor-pointer z-10';
            clearBtn.title = 'مسح البحث (Esc)';
            clearBtn.innerHTML = `
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            `;
            wrapper.appendChild(clearBtn);

            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                input.value = '';
                toggleClearButton(input);
                input.focus();
                triggerSearch(form, input, 0);
            });
        }

        // 2. Animated Spinner
        let spinner = wrapper.querySelector('.ajax-search-spinner');
        if (!spinner) {
            spinner = document.createElement('div');
            spinner.className = 'ajax-search-spinner absolute right-3 top-1/2 -translate-y-1/2 hidden pointer-events-none z-10';
            spinner.innerHTML = `
                <svg class="w-4 h-4 text-amber-500 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            `;
            wrapper.appendChild(spinner);
        }

        // Initial clear button visibility
        toggleClearButton(input);
    }

    function toggleClearButton(input) {
        const wrapper = input.closest('.relative') || input.parentElement;
        if (!wrapper) return;
        const clearBtn = wrapper.querySelector('.ajax-search-clear-btn');
        if (clearBtn) {
            if (input.value.trim().length > 0) {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }
        }
    }

    function triggerSearch(form, input, delay) {
        clearTimeout(debounceTimer);

        const execute = function () {
            const wrapper = input.closest('.relative') || input.parentElement;
            const spinner = wrapper ? wrapper.querySelector('.ajax-search-spinner') : null;
            const staticIcon = wrapper ? wrapper.querySelector('svg:not(.ajax-search-spinner svg):not(.ajax-search-clear-btn svg)') : null;

            if (spinner) spinner.classList.remove('hidden');
            if (staticIcon) staticIcon.classList.add('opacity-0');

            // Abort previous pending query
            if (activeAbortController) {
                activeAbortController.abort();
            }
            activeAbortController = new AbortController();

            // Build request URL
            const formData = new FormData(form);
            const params = new URLSearchParams();

            // Preserve non-empty fields
            for (const [key, value] of formData.entries()) {
                if (typeof value === 'string' && value.trim() !== '') {
                    params.set(key, value.trim());
                }
            }

            // Always reset to page 1 on search change
            params.set('page', '1');

            const action = form.getAttribute('action') || window.location.pathname;
            const targetUrl = action + (params.toString() ? '?' + params.toString() : '');

            fetch(targetUrl, {
                signal: activeAbortController.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                applySearchResults(html, targetUrl);
            })
            .catch(function (err) {
                if (err.name === 'AbortError') return; // Ignore cancelled requests
                console.warn('AJAX search notification:', err.message);
            })
            .finally(function () {
                if (spinner) spinner.classList.add('hidden');
                if (staticIcon) staticIcon.classList.remove('opacity-0');
            });
        };

        if (delay === 0) {
            execute();
        } else {
            debounceTimer = setTimeout(execute, delay);
        }
    }

    function applySearchResults(html, targetUrl) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        // 1. Locate primary table card or table container
        const currentContainer = findPrimaryTableContainer(document);
        const newContainer = findPrimaryTableContainer(doc);

        if (currentContainer && newContainer) {
            currentContainer.innerHTML = newContainer.innerHTML;
        } else {
            // Fallback: update tbody
            const currentTbody = document.querySelector('table tbody');
            const newTbody = doc.querySelector('table tbody');
            if (currentTbody && newTbody) {
                currentTbody.innerHTML = newTbody.innerHTML;
            }
        }

        // 2. Update stats and filter counts if available
        const countSelectors = [
            '.quick-stats-container',
            '[data-filter-tabs]',
            '#alertsManagementSection',
            '.page-counts-badge',
            '.total-records-count'
        ];

        countSelectors.forEach(function (sel) {
            const curr = document.querySelector(sel);
            const fresh = doc.querySelector(sel);
            if (curr && fresh) {
                curr.innerHTML = fresh.innerHTML;
            }
        });

        // 3. Sync client data maps (e.g. window.DRIVERS_MAP)
        const scripts = doc.querySelectorAll('script');
        scripts.forEach(function (s) {
            if (s.textContent.includes('window.DRIVERS_MAP')) {
                const match = s.textContent.match(/window\.DRIVERS_MAP\s*=\s*(\{.*?\});/s);
                if (match && match[1]) {
                    try {
                        window.DRIVERS_MAP = JSON.parse(match[1]);
                    } catch (e) {}
                }
            }
        });

        // 4. Update address bar
        if (targetUrl) {
            window.history.replaceState(null, '', targetUrl);
        }
    }

    function findPrimaryTableContainer(root) {
        // Look for the main container holding the table
        const stickyContainer = root.querySelector('.table-sticky-container');
        if (stickyContainer) {
            // If it has a parent card holding pagination, replace the card
            const parentCard = stickyContainer.closest('.rounded-2xl, .rounded-xl, .bg-white, .dark\\:bg-slate-900');
            if (parentCard) return parentCard;
            return stickyContainer;
        }

        const table = root.querySelector('table');
        if (table) {
            const card = table.closest('.rounded-2xl, .rounded-xl, .bg-white');
            if (card) return card;
            return table.parentElement;
        }

        return null;
    }

    // Intercept pagination clicks within tables to make paging AJAX too
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a');
        if (!link) return;

        const href = link.getAttribute('href');
        if (!href) return;

        // Check if link is a pagination link (contains page=) inside a table container
        const tableCard = findPrimaryTableContainer(document);
        if (tableCard && tableCard.contains(link) && href.includes('page=')) {
            e.preventDefault();

            // Subtle loading opacity
            tableCard.classList.add('opacity-60', 'pointer-events-none');

            fetch(href, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                applySearchResults(html, href);
            })
            .catch(console.error)
            .finally(function () {
                tableCard.classList.remove('opacity-60', 'pointer-events-none');
            });
        }
    });

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAjaxSearch);
    } else {
        initAjaxSearch();
    }

    window.addEventListener('load', initAjaxSearch);
})();
