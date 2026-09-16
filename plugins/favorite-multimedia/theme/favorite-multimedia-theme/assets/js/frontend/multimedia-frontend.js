/**
 * Favorite Multimedia Frontend Interactive Logic
 * Version: 1.0.0
 */

// 0. Accessible Global Toast System
window.FavoriteToast = (function () {
    let container = null;

    function getContainer() {
        if (!container) {
            container = document.getElementById('fm-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'fm-toast-container';
                container.className = 'fm-toast-container';
                container.setAttribute('role', 'status');
                container.setAttribute('aria-live', 'polite');
                container.setAttribute('aria-atomic', 'true');
                document.body.appendChild(container);
            }
        }
        return container;
    }

    const ICONS = {
        success: '✓',
        info: 'ℹ',
        warning: '⚠',
        error: '✕'
    };

    function show(message, type = 'info', duration = 3500) {
        const c = getContainer();
        const toast = document.createElement('div');
        toast.className = `fm-toast fm-toast-${type}`;
        
        const iconSpan = document.createElement('span');
        iconSpan.className = 'fm-toast-icon';
        iconSpan.setAttribute('aria-hidden', 'true');
        iconSpan.textContent = ICONS[type] || 'ℹ';
        
        const textSpan = document.createElement('span');
        textSpan.className = 'fm-toast-message';
        textSpan.textContent = message;

        const closeBtn = document.createElement('button');
        closeBtn.className = 'fm-toast-close';
        closeBtn.setAttribute('aria-label', 'Close notification');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => dismiss(toast));

        toast.appendChild(iconSpan);
        toast.appendChild(textSpan);
        toast.appendChild(closeBtn);

        c.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.classList.add('fm-toast-visible');
        });

        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => {
                dismiss(toast);
            }, duration);
        }

        return toast;
    }

    function dismiss(toast) {
        if (!toast || !toast.parentNode) return;
        toast.classList.remove('fm-toast-visible');
        toast.classList.add('fm-toast-hiding');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 250);
    }

    return {
        show: show,
        success: (msg, dur) => show(msg, 'success', dur),
        info: (msg, dur) => show(msg, 'info', dur),
        warning: (msg, dur) => show(msg, 'warning', dur),
        error: (msg, dur) => show(msg, 'error', dur),
    };
})();

function initMultimediaFrontend() {
    // 1. Hero Billboard Slider
    const heroContainer = document.querySelector('.fm-hero-container');
    if (heroContainer) {
        const slides = heroContainer.querySelectorAll('.fm-hero-slide');
        const dots = heroContainer.querySelectorAll('.fm-hero-dot');
        const prevBtn = heroContainer.querySelector('.fm-hero-prev');
        const nextBtn = heroContainer.querySelector('.fm-hero-next');
        const slideDuration = parseInt(heroContainer.getAttribute('data-slide-duration') || '6', 10) * 1000;
        const autoRotate = heroContainer.getAttribute('data-auto-rotate') === '1';
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        let currentIndex = 0;
        let timer = null;

        function showSlide(index) {
            if (index < 0) index = slides.length - 1;
            if (index >= slides.length) index = 0;

            slides.forEach((s, idx) => {
                s.classList.toggle('active', idx === index);
            });

            dots.forEach((d, idx) => {
                d.classList.toggle('active', idx === index);
                d.setAttribute('aria-selected', idx === index ? 'true' : 'false');
            });

            currentIndex = index;
        }

        function nextSlide() {
            showSlide(currentIndex + 1);
        }

        function prevSlide() {
            showSlide(currentIndex - 1);
        }

        function startTimer() {
            if (autoRotate && !prefersReducedMotion && slides.length > 1) {
                stopTimer();
                timer = setInterval(nextSlide, slideDuration);
            }
        }

        function stopTimer() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        if (nextBtn) nextBtn.addEventListener('click', () => { nextSlide(); startTimer(); });
        if (prevBtn) prevBtn.addEventListener('click', () => { prevSlide(); startTimer(); });

        dots.forEach((dot, idx) => {
            dot.addEventListener('click', () => {
                showSlide(idx);
                startTimer();
            });
        });

        heroContainer.addEventListener('mouseenter', stopTimer);
        heroContainer.addEventListener('mouseleave', startTimer);
        heroContainer.addEventListener('focusin', stopTimer);
        heroContainer.addEventListener('focusout', startTimer);

        startTimer();
    }

    // 2. Content Rails Smooth Scrolling & Auto-Overflow Controls
    function updateRailControls(track) {
        if (!track) return;
        const wrapper = track.closest('.fm-rail-wrapper');
        if (!wrapper) return;
        const controls = wrapper.querySelector('.fm-rail-controls');
        if (!controls) return;
        const prevBtn = controls.querySelector('.fm-rail-prev, .fm-rail-btn-prev');
        const nextBtn = controls.querySelector('.fm-rail-next, .fm-rail-btn-next');

        // If track has no horizontal overflow (content fits completely), hide controls
        const hasOverflow = track.scrollWidth > track.clientWidth + 5;
        if (!hasOverflow) {
            controls.style.display = 'none';
            return;
        }

        controls.style.display = 'flex';
        const atStart = track.scrollLeft <= 5;
        const atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 5;

        if (prevBtn) {
            prevBtn.style.opacity = atStart ? '0' : '1';
            prevBtn.style.pointerEvents = atStart ? 'none' : 'auto';
        }
        if (nextBtn) {
            nextBtn.style.opacity = atEnd ? '0' : '1';
            nextBtn.style.pointerEvents = atEnd ? 'none' : 'auto';
        }
    }

    const allRailTracks = document.querySelectorAll('.fm-rail-track');
    allRailTracks.forEach(track => {
        updateRailControls(track);
        track.addEventListener('scroll', () => {
            updateRailControls(track);
        }, { passive: true });
    });

    window.addEventListener('resize', () => {
        allRailTracks.forEach(updateRailControls);
    });

    document.querySelectorAll('.fm-rail-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const track = document.getElementById(targetId);
            if (!track) return;

            const isNext = this.classList.contains('fm-rail-next') || this.classList.contains('fm-rail-btn-next');
            const scrollAmount = Math.max(300, track.clientWidth * 0.75);
            track.scrollBy({
                left: isNext ? scrollAmount : -scrollAmount,
                behavior: 'smooth'
            });
            setTimeout(() => updateRailControls(track), 350);
        });
    });

    // 3. Content Rails Keyboard Navigation
    document.querySelectorAll('.fm-rail-track').forEach(track => {
        track.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') {
                e.preventDefault();
                track.scrollBy({ left: 240, behavior: 'smooth' });
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                track.scrollBy({ left: -240, behavior: 'smooth' });
            }
        });
    });

    // 4. Universal Search Autocomplete & Recent Searches
    const searchInput = document.getElementById('fm-search-input-main') || document.querySelector('.fm-search-input');
    const suggestionsBox = document.getElementById('fm-search-suggestions');
    const clearBtn = document.getElementById('fm-search-clear-btn');
    const recentBox = document.getElementById('fm-recent-searches-box');
    const recentList = document.getElementById('fm-recent-searches-list');
    const RECENT_KEY = '_fm_recent_searches';

    function getRecentSearches() {
        try {
            const raw = localStorage.getItem(RECENT_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function addRecentSearch(term) {
        const clean = term.trim();
        if (!clean) return;
        try {
            let list = getRecentSearches().filter(item => item.toLowerCase() !== clean.toLowerCase());
            list.unshift(clean);
            if (list.length > 6) list = list.slice(0, 6);
            localStorage.setItem(RECENT_KEY, JSON.stringify(list));
        } catch (e) {}
    }

    function renderRecentSearches() {
        if (!recentBox || !recentList) return;
        const list = getRecentSearches();
        if (list.length === 0) {
            recentBox.style.display = 'none';
            return;
        }
        recentList.innerHTML = '';
        list.forEach(term => {
            const tag = document.createElement('a');
            tag.href = `/multimedia/search?q=${encodeURIComponent(term)}`;
            tag.className = 'fm-recent-tag';
            tag.textContent = term;
            tag.style.cssText = 'background: var(--fm-color-bg-surface); color: var(--fm-color-text-secondary); border: 1px solid var(--fm-color-border); padding: 3px 10px; border-radius: 14px; font-size: 0.8rem; text-decoration: none;';
            recentList.appendChild(tag);
        });
        recentBox.style.display = 'block';
    }

    renderRecentSearches();

    if (searchInput) {
        let debounceTimer = null;
        let selectedIndex = -1;

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                if (suggestionsBox) suggestionsBox.style.display = 'none';
                searchInput.focus();
            });
        }

        searchInput.addEventListener('input', function () {
            const val = this.value.trim();
            if (clearBtn) clearBtn.style.display = val ? 'block' : 'none';

            if (!suggestionsBox) return;

            clearTimeout(debounceTimer);
            if (val.length < 2) {
                suggestionsBox.style.display = 'none';
                suggestionsBox.innerHTML = '';
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch(`/multimedia/api/search/suggestions?q=${encodeURIComponent(val)}`)
                    .then(res => res.json())
                    .then(data => {
                        const items = data.suggestions || [];
                        if (items.length === 0) {
                            suggestionsBox.style.display = 'none';
                            suggestionsBox.innerHTML = '';
                            return;
                        }

                        selectedIndex = -1;
                        suggestionsBox.innerHTML = items.map((it, idx) => `
                            <a href="${it.url}" class="fm-suggestion-item" data-index="${idx}" role="option" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; text-decoration: none; color: inherit; border-bottom: 1px solid var(--fm-color-border, #2a3548);">
                                <div style="width: 38px; height: 38px; border-radius: 4px; overflow: hidden; background: var(--fm-color-bg-elevated); flex-shrink: 0;">
                                    ${it.poster ? `<img src="${it.poster}" alt="" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'">` : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">📁</div>'}
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 600; font-size: 0.92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${it.title}</div>
                                    <div style="font-size: 0.78rem; color: var(--fm-color-text-muted);">${it.subtitle}</div>
                                </div>
                                <span style="font-size: 0.72rem; padding: 2px 8px; border-radius: 4px; background: rgba(255,255,255,0.08); color: var(--fm-color-text-secondary); text-transform: uppercase;">${it.type_label}</span>
                            </a>
                        `).join('');

                        suggestionsBox.style.display = 'block';
                    })
                    .catch(() => {
                        suggestionsBox.style.display = 'none';
                    });
            }, 250);
        });

        // Keyboard navigation in suggestions
        searchInput.addEventListener('keydown', function (e) {
            if (!suggestionsBox || suggestionsBox.style.display === 'none') return;
            const items = suggestionsBox.querySelectorAll('.fm-suggestion-item');
            if (items.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = (selectedIndex + 1) % items.length;
                updateSelection(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = (selectedIndex - 1 + items.length) % items.length;
                updateSelection(items);
            } else if (e.key === 'Enter') {
                if (selectedIndex >= 0 && items[selectedIndex]) {
                    e.preventDefault();
                    addRecentSearch(searchInput.value);
                    items[selectedIndex].click();
                } else if (searchInput.value.trim()) {
                    addRecentSearch(searchInput.value);
                }
            } else if (e.key === 'Escape') {
                suggestionsBox.style.display = 'none';
            }
        });

        function updateSelection(items) {
            items.forEach((item, idx) => {
                const active = idx === selectedIndex;
                item.style.backgroundColor = active ? 'var(--fm-color-bg-elevated, #1c2436)' : 'transparent';
                if (active) item.focus();
            });
        }

        // Close suggestions on outside click
        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
                suggestionsBox.style.display = 'none';
            }
        });
    }

    // Record recent search on form submit
    const searchForm = document.querySelector('.fm-search-page-form') || document.querySelector('.fm-search-form');
    if (searchForm && searchInput) {
        searchForm.addEventListener('submit', function () {
            if (searchInput.value.trim()) {
                addRecentSearch(searchInput.value);
            }
        });
    }

    // 5. Modal / Drawer Accessibility & Focus Trapping
    function setupModalAccessibility(modal, closeTriggers) {
        if (!modal) return;
        let lastFocusedElement = null;

        function openModal() {
            lastFocusedElement = document.activeElement;
            modal.setAttribute('aria-hidden', 'false');
            const focusable = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusable.length > 0) focusable[0].focus();
        }

        function closeModal() {
            modal.setAttribute('aria-hidden', 'true');
            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            }
        }

        modal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            } else if (e.key === 'Tab') {
                const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'));
                if (focusable.length === 0) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        closeTriggers.forEach(btn => {
            if (btn) btn.addEventListener('click', closeModal);
        });
    }

    const expandedModal = document.getElementById('fm-expanded-player');
    const closeExpandedBtn = document.getElementById('fm-btn-player-collapse');
    if (expandedModal && closeExpandedBtn) {
        setupModalAccessibility(expandedModal, [closeExpandedBtn]);
    }

    const queueDrawer = document.getElementById('fm-audio-queue-drawer');
    const closeQueueBtn = document.getElementById('fm-queue-drawer-close');
    if (queueDrawer && closeQueueBtn) {
        setupModalAccessibility(queueDrawer, [closeQueueBtn]);
    }

    // 6. Image Fallback Handling
    document.querySelectorAll('img').forEach(img => {
        img.addEventListener('error', function () {
            if (this.dataset.fmFallbackApplied) return;
            this.dataset.fmFallbackApplied = 'true';
            this.style.display = 'none';
            if (this.parentNode && !this.parentNode.querySelector('.fm-img-fallback-placeholder')) {
                const placeholder = document.createElement('div');
                placeholder.className = 'fm-card-placeholder fm-img-fallback-placeholder';
                placeholder.style.cssText = 'width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: var(--fm-color-bg-elevated, #1c2436); color: var(--fm-color-text-muted); font-size: 1.5rem;';
                placeholder.innerHTML = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="4"></rect><polygon points="10 8 16 12 10 16 10 8" fill="currentColor"></polygon></svg>';
                this.parentNode.appendChild(placeholder);
            }
        });
    });

    // 7. Sticky Header Scroll Effect
    const header = document.querySelector('.fm-header');
    if (header) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 20) {
                header.classList.add('fm-header-scrolled');
            } else {
                header.classList.remove('fm-header-scrolled');
            }
        }, { passive: true });
    }

    // 8. Expanding Search Pill Interactivity
    const headerSearchForm = document.getElementById('fm-header-search');
    const headerSearchPill = document.getElementById('fm-search-pill');
    const headerSearchInput = document.getElementById('fm-header-search-input');
    const headerSearchToggle = document.getElementById('fm-search-toggle');

    if (headerSearchForm && headerSearchPill && headerSearchInput && headerSearchToggle) {
        function expandSearch() {
            headerSearchPill.classList.add('fm-search-expanded');
            headerSearchToggle.setAttribute('aria-expanded', 'true');
            headerSearchInput.focus();
        }

        function collapseSearch() {
            if (!headerSearchInput.value.trim()) {
                headerSearchPill.classList.remove('fm-search-expanded');
                headerSearchToggle.setAttribute('aria-expanded', 'false');
            }
        }

        headerSearchToggle.addEventListener('click', function(e) {
            if (headerSearchPill.classList.contains('fm-search-expanded') && headerSearchInput.value.trim()) {
                headerSearchForm.submit();
            } else if (headerSearchPill.classList.contains('fm-search-expanded')) {
                collapseSearch();
            } else {
                e.preventDefault();
                expandSearch();
            }
        });

        headerSearchInput.addEventListener('focus', expandSearch);
        headerSearchInput.addEventListener('blur', function() {
            setTimeout(collapseSearch, 150);
        });

        headerSearchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                headerSearchInput.value = '';
                collapseSearch();
                headerSearchToggle.focus();
            }
        });

        document.addEventListener('click', function(e) {
            if (!headerSearchForm.contains(e.target)) {
                collapseSearch();
            }
        });
    }

    // 9. Profile Dropdown Menu Interactivity
    const profileBtn = document.querySelector('[data-fm-profile-toggle]') || document.getElementById('fm-profile-btn') || document.querySelector('.fm-profile-btn');
    const profileMenu = document.querySelector('[data-fm-profile-menu]') || document.getElementById('fm-profile-dropdown') || document.querySelector('.fm-profile-dropdown');
    if (profileBtn && profileMenu && !profileBtn.dataset.fmListenerAttached) {
        profileBtn.dataset.fmListenerAttached = 'true';

        function openProfileMenu() {
            profileBtn.setAttribute('aria-expanded', 'true');
            profileMenu.removeAttribute('hidden');
            profileMenu.style.display = 'block';
            profileMenu.classList.add('fm-dropdown-open');
            const firstItem = profileMenu.querySelector('[role="menuitem"]');
            if (firstItem) firstItem.focus();
        }

        function closeProfileMenu(restoreFocus) {
            profileBtn.setAttribute('aria-expanded', 'false');
            profileMenu.setAttribute('hidden', '');
            profileMenu.style.display = 'none';
            profileMenu.classList.remove('fm-dropdown-open');
            if (restoreFocus) profileBtn.focus();
        }

        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const expanded = profileBtn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                closeProfileMenu(false);
            } else {
                openProfileMenu();
            }
        });

        profileBtn.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openProfileMenu();
            }
        });

        profileMenu.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeProfileMenu(true);
                return;
            }
            const items = Array.prototype.slice.call(profileMenu.querySelectorAll('[role="menuitem"]'));
            const idx = items.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const next = items[(idx + 1) % items.length];
                if (next) next.focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prev = items[(idx - 1 + items.length) % items.length];
                if (prev) prev.focus();
            }
        });

        document.addEventListener('click', function(e) {
            if (!profileMenu.contains(e.target) && !profileBtn.contains(e.target)) {
                closeProfileMenu(false);
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMultimediaFrontend);
} else {
    initMultimediaFrontend();
}
