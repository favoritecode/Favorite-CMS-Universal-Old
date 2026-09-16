/**
 * Favorite Multimedia — Admin Interactive Helper
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Automatic Media Type Detection for URL input
    const urlInput = document.getElementById('fav_source_url');
    const typeSelect = document.getElementById('fav_source_type');
    const detectionBox = document.getElementById('fav_detection_status');

    if (urlInput && typeSelect) {
        let debounceTimer = null;
        urlInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => detectSourceType(urlInput.value), 400);
        });

        function detectSourceType(url) {
            url = url.trim();
            if (!url) {
                if (detectionBox) detectionBox.style.display = 'none';
                return;
            }

            fetch('/multimedia/api/detect?url=' + encodeURIComponent(url))
                .then(res => res.json())
                .then(data => {
                    if (data.source_type && data.source_type !== 'unknown') {
                        typeSelect.value = data.source_type;
                        if (detectionBox) {
                            detectionBox.style.display = 'block';
                            detectionBox.style.borderColor = '#10b981';
                            detectionBox.style.color = '#065f46';
                            detectionBox.textContent = `Auto-detected: ${data.source_type.toUpperCase()} (${data.mime_type || data.player_type})`;
                        }
                    } else {
                        typeSelect.value = 'unknown';
                        if (detectionBox) {
                            detectionBox.style.display = 'block';
                            detectionBox.style.borderColor = '#f59e0b';
                            detectionBox.style.color = '#92400e';
                            detectionBox.textContent = 'Unknown Media Type. Please select source type manually.';
                        }
                    }
                })
                .catch(() => {
                    // Fallback client detection
                    clientDetect(url);
                });
        }

        function clientDetect(url) {
            const lower = url.toLowerCase();
            if (lower.includes('.m3u8')) {
                typeSelect.value = 'hls';
            } else if (lower.endsWith('.mp4') || lower.endsWith('.webm') || lower.endsWith('.ogv')) {
                typeSelect.value = 'video';
            } else if (lower.endsWith('.mp3') || lower.endsWith('.m4a') || lower.endsWith('.wav') || lower.endsWith('.ogg')) {
                typeSelect.value = 'audio';
            } else if (lower.includes('youtube.com') || lower.includes('youtu.be') || lower.includes('vimeo.com') || lower.includes('dailymotion.com') || lower.includes('soundcloud.com')) {
                typeSelect.value = 'embed';
            } else {
                typeSelect.value = 'unknown';
            }
        }
    }

    // 2. Multi-Select & Bulk Actions for Admin Tables (Movies, Songs, etc.)
    document.querySelectorAll('#movies-bulk-form, #songs-bulk-form').forEach(form => {
        const masterCb = form.querySelector('[data-select-all]');
        const rowCbs = form.querySelectorAll('.bulk-cb');
        const countBadge = form.querySelector('.bulk-count-badge');
        const actionSelect = form.querySelector('select[name="bulk_action"]');

        function updateSelectedState() {
            const checked = form.querySelectorAll('.bulk-cb:checked');
            const total = rowCbs.length;
            const count = checked.length;

            if (countBadge) {
                countBadge.textContent = count + ' selected';
            }

            if (masterCb) {
                if (count === 0) {
                    masterCb.checked = false;
                    masterCb.indeterminate = false;
                } else if (count === total && total > 0) {
                    masterCb.checked = true;
                    masterCb.indeterminate = false;
                } else {
                    masterCb.checked = false;
                    masterCb.indeterminate = true;
                }
            }
        }

        if (masterCb) {
            masterCb.addEventListener('change', function () {
                rowCbs.forEach(cb => {
                    cb.checked = masterCb.checked;
                });
                updateSelectedState();
            });
        }

        rowCbs.forEach(cb => {
            cb.addEventListener('change', updateSelectedState);
        });

        form.addEventListener('submit', function (e) {
            const action = actionSelect ? actionSelect.value : '';
            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                if (actionSelect) actionSelect.focus();
                return;
            }

            const checked = form.querySelectorAll('.bulk-cb:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Please select at least one item.');
                return;
            }

            if (action === 'delete') {
                const msg = `Are you sure you want to delete ${checked.length} selected item(s)? This action cannot be undone.`;
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            }
        });

        updateSelectedState();
    });

    // 3. Universal Reusable Toast Component for Multimedia Admin
    window.FavoriteMultimediaToast = (function () {
        let container = null;

        function getContainer() {
            if (!container || !document.body.contains(container)) {
                container = document.getElementById('fmm-toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'fmm-toast-container';
                    container.setAttribute('aria-live', 'assertive');
                    document.body.appendChild(container);
                }
            }
            return container;
        }

        function show(message, type = 'error', duration = 5000) {
            if (!message) return null;
            const cont = getContainer();

            const toast = document.createElement('div');
            toast.className = 'fmm-toast fmm-toast-' + type;
            toast.setAttribute('role', 'alert');

            const icon = document.createElement('span');
            icon.className = 'fmm-toast-icon';
            icon.textContent = (type === 'error') ? '✕' : '✓';

            const msgDiv = document.createElement('div');
            msgDiv.className = 'fmm-toast-message';
            msgDiv.textContent = message;

            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'fmm-toast-close';
            closeBtn.setAttribute('aria-label', 'Close');
            closeBtn.innerHTML = '&times;';

            function dismiss() {
                toast.classList.remove('fmm-toast-show');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }

            closeBtn.addEventListener('click', dismiss);

            toast.appendChild(icon);
            toast.appendChild(msgDiv);
            toast.appendChild(closeBtn);
            cont.appendChild(toast);

            // Animate in
            requestAnimationFrame(() => {
                toast.classList.add('fmm-toast-show');
            });

            if (duration > 0) {
                setTimeout(dismiss, duration);
            }

            return toast;
        }

        return { show };
    })();

    // Surface existing server notices via modern toast
    const existingError = document.querySelector('.notice.notice-error');
    if (existingError && existingError.textContent.trim()) {
        FavoriteMultimediaToast.show(existingError.textContent.trim(), 'error');
    }
    const existingSuccess = document.querySelector('.notice.notice-success');
    if (existingSuccess && existingSuccess.textContent.trim()) {
        FavoriteMultimediaToast.show(existingSuccess.textContent.trim(), 'success');
    }

    // 4. Graceful Same-Page Deletion Dispatcher
    function executeDelete(url, formData, rowElement, itemTitle, fallbackForm) {
        if (!formData.has('action')) {
            formData.append('action', 'delete');
        }
        formData.set('ajax', '1');

        fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(async response => {
            const data = await response.json().catch(() => null);
            if (response.status === 403 || (data && data.success === false)) {
                const errorMsg = (data && data.error) ? data.error : `You cannot delete ${itemTitle}.`;
                FavoriteMultimediaToast.show(errorMsg, 'error');
                return;
            }
            if (!response.ok) {
                const errorMsg = (data && data.error) ? data.error : 'An unexpected error occurred while deleting.';
                FavoriteMultimediaToast.show(errorMsg, 'error');
                return;
            }

            // Success
            FavoriteMultimediaToast.show((data && data.message) ? data.message : 'Deleted successfully.', 'success');
            if (rowElement) {
                rowElement.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                rowElement.style.opacity = '0';
                rowElement.style.transform = 'translateX(20px)';
                setTimeout(() => {
                    rowElement.remove();
                }, 300);
            } else {
                setTimeout(() => window.location.reload(), 600);
            }
        })
        .catch(() => {
            if (fallbackForm) {
                fallbackForm.submit();
            } else {
                FavoriteMultimediaToast.show('Network error while deleting.', 'error');
            }
        });
    }

    // Single item deletion button listener (.fmm-delete-single-btn)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.fmm-delete-single-btn');
        if (!btn) return;
        e.preventDefault();

        const id = btn.dataset.id;
        const title = btn.dataset.title || 'this item';
        const type = (btn.dataset.type || '').toLowerCase();
        if (!id) return;

        if (!confirm(`Are you sure you want to delete "${title}"? This cannot be undone.`)) {
            return;
        }

        const row = btn.closest('tr');
        const csrfTokenEl = document.querySelector('input[name="_token"], input[name="_csrf_token"]');
        const token = csrfTokenEl ? csrfTokenEl.value : '';

        let endpoint = '/admin/page/multimedia-movies';
        if (btn.closest('#songs-bulk-form') || type === 'song') {
            endpoint = '/admin/page/multimedia-songs';
        } else if (type === 'series') {
            endpoint = '/admin/page/multimedia-series';
        } else if (type === 'episode') {
            endpoint = '/admin/page/multimedia-episodes';
        } else if (type === 'album') {
            endpoint = '/admin/page/multimedia-albums';
        } else if (type === 'playlist') {
            endpoint = '/admin/page/multimedia-playlists';
        } else if (window.location.pathname.includes('my-submissions')) {
            endpoint = '/admin/page/multimedia-my-submissions';
        }

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        if (type) formData.append('content_type', type);
        if (token) formData.append('_token', token);

        const movieForm = document.getElementById('fmm-movie-single-delete-form');
        const songForm = document.getElementById('fmm-song-single-delete-form');
        const fallbackForm = (endpoint === '/admin/page/multimedia-movies' && movieForm) ? movieForm : ((endpoint === '/admin/page/multimedia-songs' && songForm) ? songForm : null);

        executeDelete(endpoint, formData, row, title, fallbackForm);
    });

    // Intercept form submissions that have action=delete (e.g. series, episodes, albums, playlists)
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form || !form.matches) return;

        // Skip bulk multi-select forms which have their own bulk handler
        if (form.id === 'movies-bulk-form' || form.id === 'songs-bulk-form') return;

        const isDeleteAction = form.querySelector('input[name="action"][value="delete"]') ||
            (e.submitter && e.submitter.name === 'action' && e.submitter.value === 'delete');

        if (!isDeleteAction) return;

        e.preventDefault();

        const row = form.closest('tr');
        const formData = new FormData(form);
        if (!formData.has('action')) {
            formData.append('action', 'delete');
        }

        const titleEl = row ? row.querySelector('strong, .title') : null;
        const title = titleEl ? titleEl.textContent.trim() : 'this item';
        const targetUrl = form.getAttribute('action') || window.location.pathname;

        executeDelete(targetUrl, formData, row, title, form);
    });
});

