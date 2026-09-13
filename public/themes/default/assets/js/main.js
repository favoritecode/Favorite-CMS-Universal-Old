/**
 * Favorite CMS — Default Theme JavaScript
 * Lightweight, zero dependencies, accessible interactions.
 */
document.addEventListener('DOMContentLoaded', function() {
    // Persisted light/dark theme. Falls back to the operating-system preference.
    const themeToggle = document.getElementById('site-theme-toggle');
    const themeIcon = document.getElementById('site-theme-icon');
    const renderThemeToggle = function() {
        const dark = document.documentElement.dataset.theme === 'dark';
        if (themeToggle) themeToggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
        if (themeIcon) themeIcon.innerHTML = dark ? '&#9788;' : '&#9790;';
    };
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            localStorage.setItem('favorite-cms-site-theme', next);
            renderThemeToggle();
        });
        renderThemeToggle();
    }

    // 1. Mobile Menu Toggle
    const navBtn = document.getElementById('mobile-nav-btn');
    const navWrap = document.getElementById('header-nav-wrap');

    if (navBtn && navWrap) {
        navBtn.addEventListener('click', function() {
            const isExpanded = navBtn.getAttribute('aria-expanded') === 'true';
            navBtn.setAttribute('aria-expanded', !isExpanded);
            navWrap.classList.toggle('is-open');
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && navWrap.classList.contains('is-open')) {
                navBtn.setAttribute('aria-expanded', 'false');
                navWrap.classList.remove('is-open');
                navBtn.focus();
            }
        });
    }

    // 2. Safe image fallback if broken or missing
    document.querySelectorAll('.post-card-thumb img, .single-featured-media img').forEach(function(img) {
        img.addEventListener('error', function() {
            const wrapper = img.closest('.post-card-thumb') || img.closest('.single-featured-media');
            if (wrapper) {
                wrapper.style.display = 'none';
            }
        });
    });
});
