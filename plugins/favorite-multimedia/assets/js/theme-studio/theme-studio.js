/**
 * Favorite Multimedia Theme Studio Admin Logic
 * Version: 1.0.0
 */

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('fm-theme-form');
    const iframe = document.getElementById('fm-preview-iframe');
    const toast = document.getElementById('fm-toast');
    let isDirty = false;

    // Toast Notification Helper
    function showToast(message, isError = false) {
        if (!toast) return;
        toast.textContent = message;
        toast.className = 'fm-toast show' + (isError ? ' error' : '');
        setTimeout(() => {
            toast.className = 'fm-toast';
        }, 3200);
    }

    // PostMessage to Preview iframe
    function sendTokensToPreview(tokens) {
        if (iframe && iframe.contentWindow) {
            iframe.contentWindow.postMessage({
                type: 'FM_UPDATE_TOKENS',
                tokens: tokens
            }, '*');
        }
    }

    // 1. Tab Switching
    const tabBtns = document.querySelectorAll('.fm-tab-btn');
    const tabPanels = document.querySelectorAll('.fm-tab-panel');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const target = this.getAttribute('data-tab');
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanels.forEach(p => p.classList.remove('active'));

            this.classList.add('active');
            const targetPanel = document.getElementById('tab-' + target);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        });
    });

    // 2. Viewport Switcher
    const viewportBtns = document.querySelectorAll('.fm-viewport-btn');
    const frameContainer = document.getElementById('fm-preview-container');

    viewportBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            viewportBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const mode = this.getAttribute('data-viewport');
            if (frameContainer) {
                frameContainer.className = 'fm-preview-frame-container viewport-' + mode;
            }
        });
    });

    // 3. Color Picker Synchronizer
    document.querySelectorAll('.fm-color-row').forEach(row => {
        const picker = row.querySelector('.fm-color-input');
        const text = row.querySelector('.fm-color-hex');
        const tokenKey = row.getAttribute('data-token');

        if (picker && text) {
            picker.addEventListener('input', function () {
                text.value = this.value.toUpperCase();
                isDirty = true;
                if (tokenKey) {
                    sendTokensToPreview({ [tokenKey]: this.value });
                }
            });

            text.addEventListener('input', function () {
                let val = this.value.trim();
                if (val.startsWith('#') && (val.length === 4 || val.length === 7)) {
                    picker.value = val;
                    isDirty = true;
                    if (tokenKey) {
                        sendTokensToPreview({ [tokenKey]: val });
                    }
                }
            });
        }
    });

    // 4. Range Slider Synchronizer
    document.querySelectorAll('.fm-range-row').forEach(row => {
        const slider = row.querySelector('.fm-range-slider');
        const valDisplay = row.querySelector('.fm-range-val');
        const tokenKey = row.getAttribute('data-token');
        const unit = row.getAttribute('data-unit') || '';

        if (slider && valDisplay) {
            slider.addEventListener('input', function () {
                valDisplay.textContent = this.value + unit;
                isDirty = true;
                if (tokenKey) {
                    sendTokensToPreview({ [tokenKey]: this.value + unit });
                }
            });
        }
    });

    // 5. Brand Title Synchronizer
    const brandTitleInput = document.getElementById('branding-brand-title');
    if (brandTitleInput) {
        brandTitleInput.addEventListener('input', function () {
            isDirty = true;
            if (iframe && iframe.contentWindow) {
                iframe.contentWindow.postMessage({
                    type: 'FM_UPDATE_BRAND_TITLE',
                    title: this.value
                }, '*');
            }
        });
    }

    // 6. Mode Switcher Synchronizer
    const defaultModeSelect = document.getElementById('general-default-mode');
    if (defaultModeSelect) {
        defaultModeSelect.addEventListener('change', function () {
            isDirty = true;
            if (iframe && iframe.contentWindow) {
                iframe.contentWindow.postMessage({
                    type: 'FM_TOGGLE_MODE',
                    mode: this.value
                }, '*');
            }
        });
    }

    // 7. Apply Preset via AJAX
    document.querySelectorAll('.fm-preset-card').forEach(card => {
        card.addEventListener('click', function () {
            const presetId = this.getAttribute('data-preset-id');
            const csrf = document.getElementById('fm-csrf-token')?.value || '';

            if (!presetId) return;

            fetch('/admin/page/multimedia-theme', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: new URLSearchParams({
                    action: 'apply_preset',
                    preset_id: presetId,
                    _token: csrf,
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.fm-preset-card').forEach(c => c.classList.remove('active'));
                    card.classList.add('active');
                    showToast(data.message || 'Preset applied successfully.');
                    if (data.tokens) {
                        sendTokensToPreview(data.tokens);
                    }
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to apply preset', true);
                }
            })
            .catch(err => {
                showToast('Network error applying preset.', true);
            });
        });
    });

    // 8. Reset Section via AJAX
    const resetSectionBtn = document.getElementById('fm-btn-reset-section');
    if (resetSectionBtn) {
        resetSectionBtn.addEventListener('click', function () {
            const activeTab = document.querySelector('.fm-tab-btn.active')?.getAttribute('data-tab');
            const csrf = document.getElementById('fm-csrf-token')?.value || '';

            if (!activeTab) return;
            if (!confirm(`Reset "${activeTab}" section to default settings?`)) return;

            fetch('/admin/page/multimedia-theme', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: new URLSearchParams({
                    action: 'reset_section',
                    section: activeTab,
                    _token: csrf,
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Section reset successfully.');
                    if (data.tokens) {
                        sendTokensToPreview(data.tokens);
                    }
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to reset section.', true);
                }
            })
            .catch(err => {
                showToast('Network error resetting section.', true);
            });
        });
    }

    // 9. Reset All via AJAX
    const resetAllBtn = document.getElementById('fm-btn-reset-all');
    if (resetAllBtn) {
        resetAllBtn.addEventListener('click', function () {
            const csrf = document.getElementById('fm-csrf-token')?.value || '';
            if (!confirm('Are you sure you want to reset ALL theme settings to default Cinematic values? This cannot be undone.')) return;

            fetch('/admin/page/multimedia-theme', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: new URLSearchParams({
                    action: 'reset_all',
                    _token: csrf,
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'All theme settings reset to default.');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to reset settings.', true);
                }
            })
            .catch(err => {
                showToast('Network error resetting settings.', true);
            });
        });
    }

    // 9b. Export Theme Configuration
    const exportBtn = document.getElementById('fm-btn-export-theme');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            const csrf = document.getElementById('fm-csrf-token')?.value || '';
            fetch('/admin/page/multimedia-theme?action=export_theme', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                }
            })
            .then(res => res.json())
            .then(data => {
                const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'favorite-multimedia-theme-config.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                showToast('Theme configuration exported successfully.');
            })
            .catch(err => {
                showToast('Failed to export theme configuration.', true);
            });
        });
    }

    // 9c. Import Theme Configuration
    const importBtn = document.getElementById('fm-btn-import-theme');
    const importInput = document.getElementById('fm-theme-import-file');
    if (importBtn && importInput) {
        importBtn.addEventListener('click', function () {
            importInput.click();
        });

        importInput.addEventListener('change', function (e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (event) {
                try {
                    const parsed = JSON.parse(event.target.result);
                    if (!parsed || typeof parsed !== 'object') {
                        showToast('Invalid JSON file.', true);
                        return;
                    }

                    if (!confirm('Import Theme Configuration: This will overwrite visual ThemeConfig and HomepageBuilder settings. A backup of current settings will be created automatically. Proceed?')) {
                        importInput.value = '';
                        return;
                    }

                    const csrf = document.getElementById('fm-csrf-token')?.value || '';
                    fetch('/admin/page/multimedia-theme', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            action: 'import_theme',
                            _token: csrf,
                            config: parsed,
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        importInput.value = '';
                        if (data.success) {
                            showToast(data.message || 'Theme imported successfully.');
                            setTimeout(() => location.reload(), 600);
                        } else {
                            showToast(data.message || data.error || 'Import validation failed.', true);
                        }
                    })
                    .catch(err => {
                        importInput.value = '';
                        showToast('Network error during import.', true);
                    });
                } catch (err) {
                    showToast('Could not parse JSON file.', true);
                    importInput.value = '';
                }
            };
            reader.readAsText(file);
        });
    }

    // 10. AJAX Form Submission
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = document.getElementById('fm-btn-save');
            const originalText = submitBtn ? submitBtn.textContent : 'Save Changes';
            if (submitBtn) submitBtn.textContent = 'Saving...';

            const formData = new FormData(form);

            fetch(form.getAttribute('action') || '/admin/page/multimedia-theme', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                },
                body: new URLSearchParams(formData)
            })
            .then(res => res.json())
            .then(data => {
                if (submitBtn) submitBtn.textContent = originalText;
                if (data.success) {
                    isDirty = false;
                    showToast(data.message || 'Theme settings saved successfully.');
                    if (data.tokens) {
                        sendTokensToPreview(data.tokens);
                    }
                } else {
                    showToast(data.error || 'Validation error saving theme.', true);
                }
            })
            .catch(err => {
                if (submitBtn) submitBtn.textContent = originalText;
                showToast('Network error saving theme settings.', true);
            });
        });
    }
});

