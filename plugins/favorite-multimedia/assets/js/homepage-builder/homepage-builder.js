/**
 * Favorite Multimedia — No-Code Homepage Builder Client Engine
 * Version: 1.0.0
 * 
 * Vanilla JS drag-and-drop, inline configuration, modal picker,
 * live iframe preview synchronization, and AJAX persistence.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const builderTab = document.getElementById('tab-homepage');
        if (!builderTab) return;

        const sectionsList = document.getElementById('fm-hp-sections-list');
        const countBadge = document.getElementById('fm-section-count');
        const btnSave = document.getElementById('fm-btn-save-homepage');
        const btnReset = document.getElementById('fm-btn-reset-homepage');
        const btnAdd = document.getElementById('fm-btn-add-section');
        const modal = document.getElementById('fm-modal-add-section');
        const modalClose = document.getElementById('fm-modal-close');
        const previewIframe = document.getElementById('fm-preview-iframe');
        const csrfToken = document.getElementById('fm-csrf-token') ? document.getElementById('fm-csrf-token').value : '';

        let previewDebounceTimer = null;

        function showToast(message, isError) {
            const toast = document.getElementById('fm-toast');
            if (!toast) return;
            toast.textContent = message;
            toast.className = 'fm-toast show' + (isError ? ' error' : '');
            setTimeout(function () {
                toast.className = 'fm-toast';
            }, 3200);
        }

        // Collect all sections current state as an array of objects
        function getSectionsData() {
            const cards = sectionsList.querySelectorAll('.fm-builder-card');
            const sections = [];

            cards.forEach(function (card) {
                const id = card.getAttribute('data-id');
                const type = card.getAttribute('data-type');
                const enabled = card.querySelector('.fm-sec-toggle') ? card.querySelector('.fm-sec-toggle').checked : true;
                const title = card.querySelector('.fm-sec-input-title') ? card.querySelector('.fm-sec-input-title').value.trim() : '';
                const subtitle = card.querySelector('.fm-sec-input-subtitle') ? card.querySelector('.fm-sec-input-subtitle').value.trim() : '';
                const layout = card.querySelector('.fm-sec-input-layout') ? card.querySelector('.fm-sec-input-layout').value : 'rail';
                const cardStyle = card.querySelector('.fm-sec-input-card-style') ? card.querySelector('.fm-sec-input-card-style').value : 'poster';
                const limit = card.querySelector('.fm-sec-input-limit') ? parseInt(card.querySelector('.fm-sec-input-limit').value, 10) || 8 : 8;
                const sort = card.querySelector('.fm-sec-input-sort') ? card.querySelector('.fm-sec-input-sort').value : 'latest';
                const viewAllUrl = card.querySelector('.fm-sec-input-viewall') ? card.querySelector('.fm-sec-input-viewall').value.trim() : '';
                
                const visDesktop = card.querySelector('.fm-sec-input-vis-desktop') ? card.querySelector('.fm-sec-input-vis-desktop').checked : true;
                const visTablet = card.querySelector('.fm-sec-input-vis-tablet') ? card.querySelector('.fm-sec-input-vis-tablet').checked : true;
                const visMobile = card.querySelector('.fm-sec-input-vis-mobile') ? card.querySelector('.fm-sec-input-vis-mobile').checked : true;

                const sectionObj = {
                    id: id,
                    type: type,
                    title: title,
                    subtitle: subtitle,
                    layout: layout,
                    card_style: cardStyle,
                    limit: limit,
                    sort: sort,
                    view_all_url: viewAllUrl,
                    visible_desktop: visDesktop,
                    visible_tablet: visTablet,
                    visible_mobile: visMobile,
                    enabled: enabled
                };

                sections.push(sectionObj);
            });

            return sections;
        }

        // Live preview sync via debounced AJAX fetch and postMessage
        function triggerPreviewSync() {
            clearTimeout(previewDebounceTimer);
            previewDebounceTimer = setTimeout(function () {
                const sections = getSectionsData();
                fetch('/admin/api/multimedia/homepage/preview', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ sections: sections })
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success && previewIframe && previewIframe.contentWindow) {
                        previewIframe.contentWindow.postMessage({
                            type: 'FM_UPDATE_HOMEPAGE',
                            html: data.html
                        }, '*');
                    }
                })
                .catch(function (err) {
                    console.warn('Live preview sync error:', err);
                });
            }, 250);
        }

        // Update count badge
        function updateCountBadge() {
            if (!countBadge) return;
            const count = sectionsList.querySelectorAll('.fm-builder-card').length;
            countBadge.textContent = count + ' / 20';
        }

        // Reorder buttons (up / down)
        function attachCardEvents(card) {
            // Drag and Drop
            card.setAttribute('draggable', 'true');

            card.addEventListener('dragstart', function (e) {
                card.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.getAttribute('data-id'));
            });

            card.addEventListener('dragend', function () {
                card.classList.remove('is-dragging');
                sectionsList.querySelectorAll('.fm-builder-card').forEach(function (c) {
                    c.classList.remove('drag-over');
                });
                triggerPreviewSync();
            });

            card.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const draggingCard = sectionsList.querySelector('.is-dragging');
                if (draggingCard && draggingCard !== card) {
                    card.classList.add('drag-over');
                }
            });

            card.addEventListener('dragleave', function () {
                card.classList.remove('drag-over');
            });

            card.addEventListener('drop', function (e) {
                e.preventDefault();
                card.classList.remove('drag-over');
                const draggingCard = sectionsList.querySelector('.is-dragging');
                if (draggingCard && draggingCard !== card) {
                    const allCards = Array.from(sectionsList.querySelectorAll('.fm-builder-card'));
                    const droppedIndex = allCards.indexOf(card);
                    const draggedIndex = allCards.indexOf(draggingCard);

                    if (draggedIndex < droppedIndex) {
                        card.after(draggingCard);
                    } else {
                        card.before(draggingCard);
                    }
                    triggerPreviewSync();
                }
            });

            // Up / Down reorder
            const btnUp = card.querySelector('.fm-btn-move-up');
            const btnDown = card.querySelector('.fm-btn-move-down');

            if (btnUp) {
                btnUp.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const prev = card.previousElementSibling;
                    if (prev && prev.classList.contains('fm-builder-card')) {
                        prev.before(card);
                        triggerPreviewSync();
                    }
                });
            }

            if (btnDown) {
                btnDown.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const next = card.nextElementSibling;
                    if (next && next.classList.contains('fm-builder-card')) {
                        next.after(card);
                        triggerPreviewSync();
                    }
                });
            }

            // Toggle switch
            const toggle = card.querySelector('.fm-sec-toggle');
            if (toggle) {
                toggle.addEventListener('change', function () {
                    if (this.checked) {
                        card.classList.remove('is-disabled');
                    } else {
                        card.classList.add('is-disabled');
                    }
                    triggerPreviewSync();
                });
            }

            // Edit drawer open/close
            const btnEdit = card.querySelector('.fm-btn-edit-sec');
            const drawer = card.querySelector('.fm-sec-drawer');
            if (btnEdit && drawer) {
                btnEdit.addEventListener('click', function (e) {
                    e.stopPropagation();
                    drawer.classList.toggle('open');
                });
            }

            // Duplicate button
            const btnDuplicate = card.querySelector('.fm-btn-duplicate-sec');
            if (btnDuplicate) {
                btnDuplicate.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const currentCards = sectionsList.querySelectorAll('.fm-builder-card');
                    if (currentCards.length >= 20) {
                        showToast('Maximum limit of 20 sections reached.', true);
                        return;
                    }

                    const clone = card.cloneNode(true);
                    const newId = 'sec_' + Math.random().toString(36).substring(2, 9);
                    clone.setAttribute('data-id', newId);

                    const titleInput = clone.querySelector('.fm-sec-input-title');
                    if (titleInput) {
                        titleInput.value = titleInput.value + ' (Copy)';
                    }
                    const titleDisplay = clone.querySelector('.fm-sec-title-display');
                    if (titleDisplay && titleInput) {
                        titleDisplay.textContent = titleInput.value;
                    }

                    // Reset open drawer
                    const cloneDrawer = clone.querySelector('.fm-sec-drawer');
                    if (cloneDrawer) {
                        cloneDrawer.classList.remove('open');
                    }

                    card.after(clone);
                    attachCardEvents(clone);
                    updateCountBadge();
                    triggerPreviewSync();
                    showToast('Section duplicated.');
                });
            }

            // Delete button
            const btnDelete = card.querySelector('.fm-btn-delete-sec');
            if (btnDelete) {
                btnDelete.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const title = card.querySelector('.fm-sec-title-display') ? card.querySelector('.fm-sec-title-display').textContent : 'this section';
                    if (confirm('Are you sure you want to delete "' + title + '"?')) {
                        card.remove();
                        updateCountBadge();
                        triggerPreviewSync();
                        showToast('Section deleted.');
                    }
                });
            }

            // Form inputs inside drawer live sync
            const titleInput = card.querySelector('.fm-sec-input-title');
            const titleDisplay = card.querySelector('.fm-sec-title-display');
            if (titleInput && titleDisplay) {
                titleInput.addEventListener('input', function () {
                    titleDisplay.textContent = this.value || 'Untitled Section';
                    triggerPreviewSync();
                });
            }

            const subtitleInput = card.querySelector('.fm-sec-input-subtitle');
            const subtitleDisplay = card.querySelector('.fm-sec-subtitle-display');
            if (subtitleInput && subtitleDisplay) {
                subtitleInput.addEventListener('input', function () {
                    subtitleDisplay.textContent = this.value;
                    triggerPreviewSync();
                });
            }

            const layoutInput = card.querySelector('.fm-sec-input-layout');
            const layoutDisplay = card.querySelector('.fm-pill-layout');
            if (layoutInput && layoutDisplay) {
                layoutInput.addEventListener('change', function () {
                    layoutDisplay.textContent = this.value;
                    triggerPreviewSync();
                });
            }

            const limitInput = card.querySelector('.fm-sec-input-limit');
            const limitDisplay = card.querySelector('.fm-pill-limit');
            if (limitInput && limitDisplay) {
                limitInput.addEventListener('input', function () {
                    limitDisplay.textContent = this.value + ' items';
                    triggerPreviewSync();
                });
            }

            // Other inputs live preview trigger
            card.querySelectorAll('.fm-sec-input-card-style, .fm-sec-input-sort, .fm-sec-input-viewall, .fm-sec-input-vis-desktop, .fm-sec-input-vis-tablet, .fm-sec-input-vis-mobile').forEach(function (inp) {
                inp.addEventListener('change', triggerPreviewSync);
            });
        }

        // Attach events to all initial cards
        sectionsList.querySelectorAll('.fm-builder-card').forEach(attachCardEvents);

        // Add Section Modal Handling
        if (btnAdd && modal) {
            btnAdd.addEventListener('click', function () {
                const currentCards = sectionsList.querySelectorAll('.fm-builder-card');
                if (currentCards.length >= 20) {
                    showToast('Maximum limit of 20 sections reached.', true);
                    return;
                }
                modal.classList.add('open');
            });
        }

        if (modalClose && modal) {
            modalClose.addEventListener('click', function () {
                modal.classList.remove('open');
            });
        }

        // Modal category filtering
        if (modal) {
            const catTabs = modal.querySelectorAll('.fm-modal-tab');
            const pickerCards = modal.querySelectorAll('.fm-section-picker-card');

            catTabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    catTabs.forEach(function (t) { t.classList.remove('active'); });
                    tab.classList.add('active');

                    const category = tab.getAttribute('data-cat');
                    pickerCards.forEach(function (pCard) {
                        if (category === 'all' || pCard.getAttribute('data-cat') === category) {
                            pCard.style.display = 'flex';
                        } else {
                            pCard.style.display = 'none';
                        }
                    });
                });
            });

            // Modal Add Button Click
            modal.querySelectorAll('.fm-sec-picker-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const currentCards = sectionsList.querySelectorAll('.fm-builder-card');
                    if (currentCards.length >= 20) {
                        showToast('Maximum limit of 20 sections reached.', true);
                        modal.classList.remove('open');
                        return;
                    }

                    const type = this.getAttribute('data-type');
                    const defaultTitle = this.getAttribute('data-title') || 'New Section';
                    const defaultSubtitle = this.getAttribute('data-subtitle') || '';
                    const defaultLayout = this.getAttribute('data-layout') || 'rail';
                    const defaultCardStyle = this.getAttribute('data-card-style') || 'poster';
                    const defaultLimit = parseInt(this.getAttribute('data-limit'), 10) || 8;
                    const newId = 'sec_' + Math.random().toString(36).substring(2, 9);

                    // Create new Card element
                    const newCard = document.createElement('div');
                    newCard.className = 'fm-builder-card';
                    newCard.setAttribute('data-id', newId);
                    newCard.setAttribute('data-type', type);

                    newCard.innerHTML = `
                        <div class="fm-builder-card-head">
                            <div class="fm-reorder-ctrls">
                                <span class="fm-grip-handle" title="Drag to reorder">⋮⋮</span>
                                <button type="button" class="fm-reorder-btn fm-btn-move-up" title="Move Up">▲</button>
                                <button type="button" class="fm-reorder-btn fm-btn-move-down" title="Move Down">▼</button>
                            </div>
                            <div class="fm-card-main-info">
                                <div class="fm-card-title-row">
                                    <strong class="fm-sec-title-display">${defaultTitle}</strong>
                                    <span class="fm-pill fm-pill-type">${type}</span>
                                    <span class="fm-pill fm-pill-layout">${defaultLayout}</span>
                                    <span class="fm-pill fm-pill-limit">${defaultLimit} items</span>
                                </div>
                                <span class="fm-sec-subtitle-display">${defaultSubtitle}</span>
                            </div>
                            <div class="fm-card-ctrls">
                                <label class="fm-switch" title="Toggle Section Visibility">
                                    <input type="checkbox" class="fm-sec-toggle" checked>
                                    <span class="fm-switch-slider"></span>
                                </label>
                                <button type="button" class="fm-btn-icon fm-btn-edit-sec" title="Edit Settings">✎</button>
                                <button type="button" class="fm-btn-icon fm-btn-duplicate-sec" title="Duplicate">⎘</button>
                                <button type="button" class="fm-btn-icon fm-btn-icon-danger fm-btn-delete-sec" title="Delete">🗑</button>
                            </div>
                        </div>
                        <div class="fm-sec-drawer">
                            <div class="fm-drawer-grid">
                                <div class="fm-drawer-full">
                                    <label class="fm-drawer-label">Section Title</label>
                                    <input type="text" class="fm-drawer-input fm-sec-input-title" value="${defaultTitle}">
                                </div>
                                <div class="fm-drawer-full">
                                    <label class="fm-drawer-label">Subtitle</label>
                                    <input type="text" class="fm-drawer-input fm-sec-input-subtitle" value="${defaultSubtitle}">
                                </div>
                                <div>
                                    <label class="fm-drawer-label">Layout Style</label>
                                    <select class="fm-drawer-select fm-sec-input-layout">
                                        <option value="rail" ${defaultLayout === 'rail' ? 'selected' : ''}>Rail (Horizontal Scroll)</option>
                                        <option value="grid" ${defaultLayout === 'grid' ? 'selected' : ''}>Grid (Responsive Multiline)</option>
                                        <option value="hero_slider" ${defaultLayout === 'hero_slider' ? 'selected' : ''}>Hero Billboard Slider</option>
                                        <option value="list" ${defaultLayout === 'list' ? 'selected' : ''}>List</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="fm-drawer-label">Card Style</label>
                                    <select class="fm-drawer-select fm-sec-input-card-style">
                                        <option value="poster" ${defaultCardStyle === 'poster' ? 'selected' : ''}>Poster Card (2:3)</option>
                                        <option value="landscape" ${defaultCardStyle === 'landscape' ? 'selected' : ''}>Landscape Card (16:9)</option>
                                        <option value="album" ${defaultCardStyle === 'album' ? 'selected' : ''}>Album Square (1:1)</option>
                                        <option value="artist" ${defaultCardStyle === 'artist' ? 'selected' : ''}>Artist Avatar (Circle)</option>
                                        <option value="playlist" ${defaultCardStyle === 'playlist' ? 'selected' : ''}>Playlist Card</option>
                                        <option value="song_row" ${defaultCardStyle === 'song_row' ? 'selected' : ''}>Song Track Row</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="fm-drawer-label">Item Limit</label>
                                    <input type="number" class="fm-drawer-input fm-sec-input-limit" min="1" max="24" value="${defaultLimit}">
                                </div>
                                <div>
                                    <label class="fm-drawer-label">Sorting</label>
                                    <select class="fm-drawer-select fm-sec-input-sort">
                                        <option value="latest" selected>Latest Released</option>
                                        <option value="popular">Most Popular</option>
                                        <option value="rating">Top Rated</option>
                                        <option value="recent">Recently Added</option>
                                    </select>
                                </div>
                                <div class="fm-drawer-full">
                                    <label class="fm-drawer-label">View All URL</label>
                                    <input type="text" class="fm-drawer-input fm-sec-input-viewall" placeholder="/movies or /songs">
                                </div>
                                <div class="fm-drawer-full">
                                    <label class="fm-drawer-label">Device Visibility</label>
                                    <div class="fm-drawer-checkboxes">
                                        <label><input type="checkbox" class="fm-sec-input-vis-desktop" checked> 🖥 Desktop</label>
                                        <label><input type="checkbox" class="fm-sec-input-vis-tablet" checked> 📱 Tablet</label>
                                        <label><input type="checkbox" class="fm-sec-input-vis-mobile" checked> 📲 Mobile</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    sectionsList.appendChild(newCard);
                    attachCardEvents(newCard);
                    updateCountBadge();
                    triggerPreviewSync();
                    modal.classList.remove('open');
                    showToast(`Added section "${defaultTitle}"`);
                });
            });
        }

        // Save Layout button
        if (btnSave) {
            btnSave.addEventListener('click', function () {
                const sections = getSectionsData();
                btnSave.disabled = true;
                btnSave.textContent = '💾 Saving...';

                fetch('/admin/api/multimedia/homepage/save', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ sections: sections })
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    btnSave.disabled = false;
                    btnSave.textContent = '💾 Save Layout';
                    if (data.success) {
                        showToast('Homepage layout saved successfully!');
                        triggerPreviewSync();
                    } else {
                        showToast(data.error || 'Failed to save layout', true);
                    }
                })
                .catch(function (err) {
                    btnSave.disabled = false;
                    btnSave.textContent = '💾 Save Layout';
                    showToast('Error saving homepage layout', true);
                });
            });
        }

        // Reset Layout button
        if (btnReset) {
            btnReset.addEventListener('click', function () {
                if (!confirm('Are you sure you want to reset the homepage to the default 11 sections? Any customizations will be replaced.')) {
                    return;
                }

                btnReset.disabled = true;
                btnReset.textContent = '↺ Resetting...';

                fetch('/admin/api/multimedia/homepage/reset', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({})
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    btnReset.disabled = false;
                    btnReset.textContent = '↺ Reset Layout';
                    if (data.success) {
                        showToast('Homepage reset to defaults.');
                        window.location.reload();
                    } else {
                        showToast(data.error || 'Failed to reset', true);
                    }
                })
                .catch(function (err) {
                    btnReset.disabled = false;
                    btnReset.textContent = '↺ Reset Layout';
                    showToast('Error resetting layout', true);
                });
            });
        }

    });
})();

