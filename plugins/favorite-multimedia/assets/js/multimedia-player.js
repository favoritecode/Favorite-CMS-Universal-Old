/**
 * Favorite Multimedia — Universal Player Engine
 *
 * Implements a unified, centralized playback engine:
 * 1. Single permanent player host/surface.
 * 2. Runtime source classification: video (direct HTML5), HLS (native or hls.js), embed (iframe).
 * 3. Element reuse for direct/HLS with currentTime and sound state preservation.
 * 4. Dynamic source switching across all media technologies.
 * 5. Automatic failover for detectable fatal errors with loop protection.
 * 6. Clean embed iframe loading without scraping or fake failover.
 * 7. Compact source switcher displayed when >= 2 sources exist, hidden when <= 1.
 */

// Canonical Unified Volume Store (Shared by Audio and Video)
window.FavoriteMediaVolume = window.FavoriteMediaVolume || {
    KEY: 'fm_media_volume',
    LAST_NON_ZERO_KEY: 'fm_media_last_volume',

    getDefault() {
        if (window.FavoriteMultimediaConfig && typeof window.FavoriteMultimediaConfig.defaultVolume === 'number') {
            return Math.max(0.05, Math.min(1.0, window.FavoriteMultimediaConfig.defaultVolume));
        }
        return 0.25;
    },

    shouldRemember() {
        if (window.FavoriteMultimediaConfig && window.FavoriteMultimediaConfig.rememberVolume === false) {
            return false;
        }
        return true;
    },

    get() {
        if (!this.shouldRemember()) {
            return this.getDefault();
        }
        try {
            const val = localStorage.getItem(this.KEY);
            if (val !== null) {
                const parsed = parseFloat(val);
                if (!isNaN(parsed) && parsed >= 0 && parsed <= 1) {
                    return parsed;
                }
            }
        } catch (e) {}
        return this.getDefault();
    },

    set(vol) {
        const clamped = Math.max(0, Math.min(1, parseFloat(vol) || 0));
        if (clamped > 0) {
            try {
                localStorage.setItem(this.LAST_NON_ZERO_KEY, clamped.toString());
            } catch (e) {}
        }
        if (this.shouldRemember()) {
            try {
                localStorage.setItem(this.KEY, clamped.toString());
            } catch (e) {}
        }
        try {
            window.dispatchEvent(new CustomEvent('fm:volumechange', { detail: { volume: clamped } }));
        } catch (e) {}
        return clamped;
    },

    getUnmuteVolume() {
        try {
            const last = localStorage.getItem(this.LAST_NON_ZERO_KEY);
            if (last !== null) {
                const p = parseFloat(last);
                if (!isNaN(p) && p > 0 && p <= 1) return p;
            }
            const current = this.get();
            if (current > 0) return current;
        } catch (e) {}
        return this.getDefault();
    }
};

document.addEventListener('DOMContentLoaded', function () {
    const players = document.querySelectorAll('.fav-video-wrapper');
    players.forEach(initUniversalMediaPlayer);
});

function initUniversalMediaPlayer(wrapper) {
    let sources = [];
    try {
        const rawSources = wrapper.getAttribute('data-sources');
        if (rawSources) {
            sources = JSON.parse(rawSources);
        }
    } catch (e) {
        sources = [];
    }

    let video = wrapper.querySelector('video.fav-video-element');
    let iframe = wrapper.querySelector('iframe.fav-embed-element');
    const controls = wrapper.querySelector('.fav-player-controls');

    // If no sources configured and no media elements exist, nothing to play
    if (sources.length === 0 && !video && !iframe) return;

    // Fallback: If no structured sources array was provided, synthesize one from existing elements
    if (sources.length === 0) {
        if (iframe && iframe.getAttribute('src')) {
            sources.push({
                id: 1,
                label: 'Embed Player',
                source_type: 'embed',
                player_type: 'embed',
                url: iframe.getAttribute('src'),
                is_default: true,
                can_auto_failover: false
            });
        } else if (video) {
            const hlsSrc = wrapper.getAttribute('data-hls-src');
            const videoSrc = video.querySelector('source')?.getAttribute('src') || video.getAttribute('src');
            if (hlsSrc) {
                sources.push({
                    id: 1,
                    label: 'HLS Stream',
                    source_type: 'hls',
                    player_type: 'hls',
                    url: hlsSrc,
                    is_default: true,
                    can_auto_failover: true
                });
            } else if (videoSrc) {
                sources.push({
                    id: 1,
                    label: 'Main Server',
                    source_type: 'video',
                    player_type: 'video',
                    url: videoSrc,
                    is_default: true,
                    can_auto_failover: true
                });
            }
        }
    }

    let activeSource = (sources.length > 0)
        ? (sources.find(s => s.is_default) || sources[0])
        : null;

    // Loop protection: track tried sources in current playback cycle
    const triedSourceIds = new Set();
    if (activeSource) triedSourceIds.add(activeSource.id);

    let hlsInstance = null;
    let savedVolume = 1;
    let savedMuted = false;
    let hasSavedSoundState = false;
    let isSeeking = false;
    let hideControlsTimeout = null;

    // Control Elements
    const playBtn = wrapper.querySelector('.fav-btn-play');
    const playIcon = playBtn ? playBtn.querySelector('.fav-icon-play') : null;
    const pauseIcon = playBtn ? playBtn.querySelector('.fav-icon-pause') : null;

    const progressContainer = wrapper.querySelector('.fav-progress-bar-container');
    const progressBar = wrapper.querySelector('.fav-progress-filled');
    const timeDisplay = wrapper.querySelector('.fav-time-display');

    const volumeBtn = wrapper.querySelector('.fav-btn-volume');
    const volumeSlider = wrapper.querySelector('.fav-volume-slider');
    const speedSelect = wrapper.querySelector('.fav-speed-select');
    const qualitySelect = wrapper.querySelector('.fav-quality-select');
    const sourceSelect = wrapper.querySelector('.fav-source-select');
    const subtitleSelect = wrapper.querySelector('.fav-subtitle-select');
    const fullscreenBtn = wrapper.querySelector('.fav-btn-fullscreen');

    // Runtime Source Classification
    function classifySource(s) {
        if (!s) return 'none';
        const pType = (s.player_type || '').toLowerCase();
        const sType = (s.source_type || '').toLowerCase();
        const rawUrl = (s.url || s.raw_url || '').toLowerCase();

        if (pType === 'embed' || sType === 'embed' || sType === 'youtube' || sType === 'vimeo') {
            return 'embed';
        }
        if (pType === 'hls' || sType === 'hls' || /\.m3u8($|\?)/i.test(rawUrl)) {
            return 'hls';
        }
        if (pType === 'audio' || sType === 'audio' || s.media_kind === 'audio') {
            return 'audio';
        }
        return 'video';
    }

    // Load HLS Library on demand if not present
    function loadHlsLibrary(callback) {
        if (window.Hls && window.Hls.isSupported()) return callback();
        const existing = document.getElementById('fm-hls-js');
        if (existing) {
            existing.addEventListener('load', callback, { once: true });
            return;
        }
        const script = document.createElement('script');
        script.id = 'fm-hls-js';
        script.src = 'https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js';
        script.onload = callback;
        script.onerror = () => failoverToNextSource('Failed to load HLS engine');
        document.head.appendChild(script);
    }

    // Browser Autoplay Policy Guard (Never triggers failover on NotAllowedError)
    function safePlay(v) {
        if (!v) return Promise.resolve();
        return v.play().catch(err => {
            if (err && (err.name === 'NotAllowedError' || err.name === 'AbortError')) {
                showAutoplayPrompt();
            }
        });
    }

    function showAutoplayPrompt() {
        if (wrapper.querySelector('.fav-autoplay-prompt')) return;
        const prompt = document.createElement('div');
        prompt.className = 'fav-autoplay-prompt';
        prompt.innerHTML = `
            <button type="button" class="fav-autoplay-prompt-btn" aria-label="Click to Play">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="currentColor"><polygon points="6,4 20,12 6,20"/></svg>
                <span>Click to Play</span>
            </button>
        `;
        wrapper.appendChild(prompt);
        const btn = prompt.querySelector('.fav-autoplay-prompt-btn');
        if (btn) {
            btn.addEventListener('click', () => {
                prompt.remove();
                if (video) video.play().catch(() => {});
            });
        }
    }

    // Render & Switch Source in Universal Player Engine
    function renderSource(source, seekTime = 0, autoPlay = false) {
        if (!source) return;
        activeSource = source;
        const kind = classifySource(source);

        // Update Source Selectors (in controls bar and floating bar)
        if (sourceSelect) {
            sourceSelect.value = source.id;
        }
        updateFloatingSourceSelector(source.id);

        if (kind === 'embed') {
            // Destroy direct video/HLS player safely
            if (hlsInstance) {
                hlsInstance.destroy();
                hlsInstance = null;
            }
            if (document.pictureInPictureElement) {
                document.exitPictureInPicture().catch(() => {});
            }
            if (pipBtn) {
                pipBtn.style.display = 'none';
            }
            if (video) {
                savedVolume = video.volume;
                savedMuted = video.muted;
                hasSavedSoundState = true;
                video.pause();
                video.style.display = 'none';
            }
            if (controls) {
                controls.style.display = 'none';
            }

            // Ensure iframe exists and display it
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.className = 'fav-embed-element';
                iframe.setAttribute('allowfullscreen', '');
                wrapper.prepend(iframe);
            }
            iframe.style.display = 'block';

            // Apply dynamic capabilities, referrer policy, and sandbox policy
            iframe.setAttribute('allow', source.allow_attribute || 'autoplay; fullscreen; encrypted-media; picture-in-picture');
            iframe.setAttribute('referrerpolicy', source.referrer_policy || 'no-referrer-when-downgrade');
            if (source.sandbox_policy) {
                iframe.setAttribute('sandbox', source.sandbox_policy);
            } else {
                iframe.removeAttribute('sandbox');
            }

            hideEmbedFallback();
            hideExhaustionBanner();

            if (iframe.getAttribute('src') !== source.url) {
                iframe.setAttribute('src', source.url);
            }

            // Bind error fallback for blocked or failing embed scripts
            iframe.onerror = function () {
                showEmbedFallback(source, isContentBlockerActive() ? 'adblock' : 'error');
            };

            // Floating source switcher allows switching anytime during embed
            ensureFloatingSourceSwitcher(source.id);

        } else {
            // Native Direct Video or HLS
            if (iframe) {
                iframe.style.display = 'none';
                iframe.setAttribute('src', 'about:blank');
            }
            removeFloatingSourceSwitcher();

            // Ensure video element exists
            if (!video) {
                video = document.createElement('video');
                video.className = 'fav-video-element';
                video.setAttribute('playsinline', '');
                video.setAttribute('preload', 'metadata');
                wrapper.prepend(video);
                bindVideoEvents(video);
            }
            video.style.display = 'block';
            if (controls) {
                controls.style.display = 'flex';
            }

            if (hasSavedSoundState) {
                video.volume = savedVolume;
                video.muted = savedMuted;
            } else {
                video.volume = window.FavoriteMediaVolume.get();
                video.muted = false;
                savedVolume = video.volume;
                savedMuted = false;
                hasSavedSoundState = true;
            }
            if (volumeSlider) {
                volumeSlider.value = video.muted ? 0 : video.volume;
            }
            if (pipBtn && enablePipSetting && document.pictureInPictureEnabled) {
                pipBtn.style.display = 'inline-block';
            }

            if (kind === 'hls') {
                if (hlsInstance) {
                    hlsInstance.destroy();
                    hlsInstance = null;
                }

                if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = source.url;
                    if (seekTime > 0) {
                        video.addEventListener('loadedmetadata', function onMeta() {
                            video.currentTime = seekTime;
                            video.removeEventListener('loadedmetadata', onMeta);
                        }, { once: true });
                    }
                    if (autoPlay) safePlay(video);
                } else {
                    loadHlsLibrary(() => {
                        if (!window.Hls || !window.Hls.isSupported()) {
                            failoverToNextSource('HLS not supported on this browser');
                            return;
                        }
                        hlsInstance = new window.Hls({ enableWorker: true, lowLatencyMode: true });
                        hlsInstance.on(window.Hls.Events.ERROR, function (event, data) {
                            if (data && data.fatal) {
                                switch (data.type) {
                                    case window.Hls.ErrorTypes.NETWORK_ERROR:
                                        hlsInstance.startLoad();
                                        break;
                                    case window.Hls.ErrorTypes.MEDIA_ERROR:
                                        hlsInstance.recoverMediaError();
                                        break;
                                    default:
                                        hlsInstance.destroy();
                                        hlsInstance = null;
                                        failoverToNextSource('HLS unrecoverable playback error');
                                        break;
                                }
                            }
                        });
                        hlsInstance.loadSource(source.url);
                        hlsInstance.attachMedia(video);
                        hlsInstance.on(window.Hls.Events.MANIFEST_PARSED, function () {
                            if (seekTime > 0) video.currentTime = seekTime;
                            if (autoPlay) safePlay(video);
                        });
                    });
                }
            } else {
                // Direct Video (MP4, WebM, etc.)
                if (hlsInstance) {
                    hlsInstance.destroy();
                    hlsInstance = null;
                }
                video.src = source.url;
                video.load();
                if (seekTime > 0) {
                    video.addEventListener('loadedmetadata', function onMeta() {
                        video.currentTime = seekTime;
                        video.removeEventListener('loadedmetadata', onMeta);
                    }, { once: true });
                }
                if (autoPlay) safePlay(video);
            }
        }
    }

    // Manual Source Switcher
    function switchPlaybackSource(targetId, userInitiated = true) {
        const target = sources.find(s => parseInt(s.id, 10) === parseInt(targetId, 10));
        if (!target) return;

        if (userInitiated) {
            // User manually selected a server: clear failover history to allow fresh retry
            triedSourceIds.clear();
            triedSourceIds.add(target.id);
            hideExhaustionBanner();
        }

        const currentTime = (video && !isNaN(video.currentTime)) ? video.currentTime : 0;
        const wasPlaying = (video && !video.paused && !video.ended);
        const shouldAutoPlay = wasPlaying || userInitiated;

        renderSource(target, currentTime, shouldAutoPlay);
    }

    // Automatic Failover with Loop Protection
    function failoverToNextSource(reason) {
        if (!sources || sources.length === 0) return;

        // Find next untried source in configured order that supports automatic failover
        const candidate = sources.find(s => !triedSourceIds.has(s.id) && s.can_auto_failover !== false);
        if (candidate) {
            triedSourceIds.add(candidate.id);
            showFailoverToast(`Source unavailable. Switched to ${candidate.label}`);
            const currentTime = (video && !isNaN(video.currentTime)) ? video.currentTime : 0;
            renderSource(candidate, currentTime, true);
        } else {
            showExhaustionBanner();
        }
    }

    // Toast Banner for Failover Notification
    function showFailoverToast(message) {
        let toast = wrapper.querySelector('.fav-failover-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'fav-failover-toast';
            toast.style.cssText = 'position: absolute; top: 16px; left: 50%; transform: translateX(-50%); background: rgba(30, 58, 138, 0.95); color: #fff; padding: 8px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; z-index: 100; box-shadow: 0 4px 14px rgba(0,0,0,0.4); pointer-events: none; transition: opacity 0.4s ease; border: 1px solid #3b82f6;';
            wrapper.appendChild(toast);
        }
        toast.textContent = message;
        toast.style.opacity = '1';
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => { toast.style.display = 'none'; }, 400);
        }, 5000);
    }

    // Exhaustion Banner when all detectable sources fail
    function showExhaustionBanner() {
        let card = wrapper.querySelector('.fav-exhaustion-card');
        if (!card) {
            card = document.createElement('div');
            card.className = 'fav-exhaustion-card';
            card.style.cssText = 'position: absolute; inset: 0; background: rgba(15, 23, 42, 0.96); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 90; padding: 24px; text-align: center;';
            card.innerHTML = `
                <div style="font-size: 38px; margin-bottom: 8px;">⚠️</div>
                <h3 style="color: #f8fafc; font-size: 18px; margin: 0 0 8px 0; font-weight: 700;">This video is temporarily unavailable</h3>
                <p style="color: #94a3b8; font-size: 14px; max-width: 440px; margin: 0;">We were unable to load playback from available servers. Try another source or check back later.</p>
            `;
            wrapper.appendChild(card);
        }
        card.style.display = 'flex';
    }

    function hideExhaustionBanner() {
        const card = wrapper.querySelector('.fav-exhaustion-card');
        if (card) card.style.display = 'none';
    }

    // Embed Player Fallback & Content Blocker Handler
    let embedRetryCount = 0;

    function isContentBlockerActive() {
        try {
            const bait = document.createElement('div');
            bait.className = 'adsbox ad-placement pub_300x250 pub_300x250m pub_728x90 text-ad textAd text_ad text_ads text-ads text-ad-links';
            bait.style.cssText = 'position: absolute; top: -9999px; left: -9999px; width: 1px; height: 1px; pointer-events: none;';
            document.body.appendChild(bait);
            const isBlocked = (bait.offsetParent === null || bait.offsetHeight === 0 || bait.offsetLeft === 0 || window.getComputedStyle(bait).display === 'none');
            document.body.removeChild(bait);
            return isBlocked;
        } catch (e) {
            return false;
        }
    }

    function showEmbedFallback(source, reason = 'general') {
        let card = wrapper.querySelector('.fav-embed-fallback-card');
        if (!card) {
            card = document.createElement('div');
            card.className = 'fav-embed-fallback-card';
            card.style.cssText = 'position: absolute; inset: 0; background: rgba(15, 23, 42, 0.97); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 85; padding: 28px; text-align: center; color: #f8fafc; font-family: inherit;';
            wrapper.appendChild(card);
        }

        const isAdBlocked = (reason === 'adblock' || isContentBlockerActive());
        const messageText = isAdBlocked
            ? "This external video provider requires its advertising scripts to load before playback. Please allow this provider/site in your content blocker and reload the player."
            : "The external video player is currently unable to load or requires permissions not permitted by your browser. Try retrying or switching to an alternate stream.";

        const hasAlternateSource = sources && sources.length > 1;
        const safeProviderUrl = (source && source.url && (source.url.startsWith('https://') || source.url.startsWith('http://'))) ? source.url : '';

        card.innerHTML = `
            <div style="font-size: 42px; margin-bottom: 12px;">⚠️</div>
            <h3 style="color: #f8fafc; font-size: 20px; margin: 0 0 10px 0; font-weight: 700;">External Player Unavailable</h3>
            <p style="color: #94a3b8; font-size: 14px; max-width: 480px; margin: 0 0 20px 0; line-height: 1.5;">${messageText}</p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; align-items: center;">
                <button type="button" class="fav-btn-retry-player" style="background: #2563eb; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px;">Retry Player</button>
                ${hasAlternateSource ? `<button type="button" class="fav-btn-switch-stream" style="background: #334155; color: #f8fafc; border: 1px solid #475569; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px;">Switch Source</button>` : ''}
                ${safeProviderUrl ? `<a href="${safeProviderUrl}" target="_blank" rel="noopener noreferrer" style="background: transparent; color: #60a5fa; border: 1px solid #3b82f6; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 13px;">Open Provider Page ↗</a>` : ''}
            </div>
        `;

        card.querySelector('.fav-btn-retry-player')?.addEventListener('click', () => {
            hideEmbedFallback();
            retryEmbedPlayer(source);
        });

        card.querySelector('.fav-btn-switch-stream')?.addEventListener('click', () => {
            hideEmbedFallback();
            const currentIdx = sources.findIndex(s => parseInt(s.id, 10) === parseInt(source.id, 10));
            const nextIdx = (currentIdx + 1) % sources.length;
            switchPlaybackSource(sources[nextIdx].id, true);
        });

        card.style.display = 'flex';
    }

    function hideEmbedFallback() {
        const card = wrapper.querySelector('.fav-embed-fallback-card');
        if (card) card.style.display = 'none';
    }

    function retryEmbedPlayer(source) {
        if (!iframe) return;
        embedRetryCount++;
        const currentSrc = source.url;
        const delimiter = currentSrc.includes('?') ? '&' : '?';
        const refreshSrc = currentSrc.includes('retry_fmm=')
            ? currentSrc.replace(/retry_fmm=\d+/, `retry_fmm=${embedRetryCount}`)
            : `${currentSrc}${delimiter}retry_fmm=${embedRetryCount}`;
        iframe.src = refreshSrc;
    }

    // Floating source switcher for iframe/embed mode (auto-hidden when <= 1 source and no reload needed)
    function ensureFloatingSourceSwitcher(currentId) {
        let floatBar = wrapper.querySelector('.fav-embed-source-switcher');
        if (!floatBar) {
            floatBar = document.createElement('div');
            floatBar.className = 'fav-embed-source-switcher';
            floatBar.style.cssText = 'position: absolute; top: 12px; right: 12px; z-index: 80; background: rgba(15,23,42,0.85); padding: 4px 8px; border-radius: 6px; border: 1px solid #334155; display: flex; align-items: center; gap: 6px;';
            floatBar.innerHTML = `
                ${sources.length > 1 ? `<span style="font-size:11px; font-weight:600; color:#94a3b8;">Source:</span><select class="fav-float-select" style="background:#1e293b; color:#f8fafc; border:1px solid #475569; font-size:12px; padding:2px 6px; border-radius:4px; cursor:pointer;"></select>` : ''}
                <button type="button" class="fav-embed-reload-btn" title="Reload player" style="background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:12px; padding:2px 4px;">🔄</button>
            `;
            wrapper.appendChild(floatBar);

            const sel = floatBar.querySelector('.fav-float-select');
            if (sel) {
                sources.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.label;
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', (e) => {
                    switchPlaybackSource(parseInt(e.target.value, 10), true);
                });
            }

            const reloadBtn = floatBar.querySelector('.fav-embed-reload-btn');
            if (reloadBtn) {
                reloadBtn.addEventListener('click', () => {
                    const src = sources.find(s => parseInt(s.id, 10) === parseInt(currentId, 10)) || activeSource;
                    if (src) retryEmbedPlayer(src);
                });
            }
        }

        const sel = floatBar.querySelector('.fav-float-select');
        if (sel) sel.value = currentId;
        floatBar.style.display = 'flex';
    }

    function updateFloatingSourceSelector(currentId) {
        const sel = wrapper.querySelector('.fav-embed-source-switcher .fav-float-select');
        if (sel) sel.value = currentId;
    }

    function removeFloatingSourceSwitcher() {
        const floatBar = wrapper.querySelector('.fav-embed-source-switcher');
        if (floatBar) floatBar.style.display = 'none';
    }

    // Initialize Source Selector Dropdown in Controls Bar
    if (sourceSelect) {
        if (sources.length <= 1) {
            sourceSelect.style.display = 'none';
        } else {
            sourceSelect.style.display = 'inline-block';
            sourceSelect.innerHTML = '';
            sources.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.label;
                if (activeSource && activeSource.id === s.id) {
                    opt.selected = true;
                }
                sourceSelect.appendChild(opt);
            });
            sourceSelect.addEventListener('change', (e) => {
                switchPlaybackSource(parseInt(e.target.value, 10), true);
            });
        }
    }

    // Bind Native Video Events
    function bindVideoEvents(v) {
        if (!v) return;

        v.addEventListener('play', () => {
            if (playIcon) playIcon.style.display = 'none';
            if (pauseIcon) pauseIcon.style.display = 'inline-block';
            resetHideTimer();
            // Mutual exclusion: notify global audio player to pause
            window.dispatchEvent(new CustomEvent('fm:video:play'));
        });

        v.addEventListener('pause', () => {
            if (playIcon) playIcon.style.display = 'inline-block';
            if (pauseIcon) pauseIcon.style.display = 'none';
            wrapper.classList.remove('fav-controls-hidden');
        });

        v.addEventListener('enterpictureinpicture', () => {
            if (pipBtn) pipBtn.classList.add('fav-btn-active');
        });

        v.addEventListener('leavepictureinpicture', () => {
            if (pipBtn) pipBtn.classList.remove('fav-btn-active');
        });

        v.addEventListener('timeupdate', () => {
            if (!isSeeking && v.duration) {
                const percent = (v.currentTime / v.duration) * 100;
                if (progressBar) progressBar.style.width = percent + '%';
                updateTimeDisplay();
            }
        });

        v.addEventListener('loadedmetadata', () => {
            updateTimeDisplay();
        });

        // Detect genuine fatal HTML5 video errors and advance to next source
        v.addEventListener('error', () => {
            if (v.error && [2, 3, 4].includes(v.error.code)) {
                failoverToNextSource(`HTML5 video error code ${v.error.code}`);
            }
        });
    }

    // Bind video controls
    if (playBtn) {
        playBtn.addEventListener('click', togglePlay);
    }
    if (video) {
        video.addEventListener('click', togglePlay);
        bindVideoEvents(video);
    }

    function togglePlay() {
        if (!video) return;
        if (video.paused || video.ended) {
            video.play().catch(() => {});
        } else {
            video.pause();
        }
    }

    function updateTimeDisplay() {
        if (!timeDisplay || !video) return;
        const cur = formatTime(video.currentTime || 0);
        const dur = formatTime(video.duration || 0);
        timeDisplay.textContent = `${cur} / ${dur}`;
    }

    function formatTime(sec) {
        sec = Math.floor(sec);
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        const h = Math.floor(m / 60);
        const remainM = m % 60;
        if (h > 0) {
            return `${h}:${remainM < 10 ? '0' : ''}${remainM}:${s < 10 ? '0' : ''}${s}`;
        }
        return `${m}:${s < 10 ? '0' : ''}${s}`;
    }

    if (progressContainer && video) {
        progressContainer.addEventListener('click', (e) => {
            const rect = progressContainer.getBoundingClientRect();
            const pos = (e.clientX - rect.left) / rect.width;
            if (video.duration) {
                video.currentTime = pos * video.duration;
            }
        });
    }

    if (volumeSlider && video) {
        volumeSlider.addEventListener('input', (e) => {
            const vol = parseFloat(e.target.value);
            video.volume = vol;
            video.muted = (vol === 0);
            savedVolume = vol;
            savedMuted = video.muted;
            hasSavedSoundState = true;
            window.FavoriteMediaVolume.set(vol);
        });
    }
    if (volumeBtn && video) {
        volumeBtn.addEventListener('click', () => {
            if (video.muted || video.volume === 0) {
                const restore = window.FavoriteMediaVolume.getUnmuteVolume();
                video.volume = restore;
                video.muted = false;
                savedVolume = restore;
                savedMuted = false;
                hasSavedSoundState = true;
                if (volumeSlider) {
                    volumeSlider.value = restore;
                }
                window.FavoriteMediaVolume.set(restore);
            } else {
                video.muted = true;
                savedMuted = true;
                if (volumeSlider) {
                    volumeSlider.value = 0;
                }
            }
        });
    }

    // Cross-player & Cross-tab Unified Volume Synchronization
    window.addEventListener('fm:volumechange', (e) => {
        if (e.detail && typeof e.detail.volume === 'number' && video) {
            const newVol = e.detail.volume;
            if (Math.abs(video.volume - newVol) > 0.01) {
                video.volume = newVol;
                savedVolume = newVol;
                if (video.muted && newVol > 0) {
                    video.muted = false;
                    savedMuted = false;
                }
                if (volumeSlider) volumeSlider.value = video.muted ? 0 : newVol;
            }
        }
    });

    window.addEventListener('storage', (e) => {
        if (e.key === 'fm_media_volume' && e.newValue !== null && video) {
            const v = parseFloat(e.newValue);
            if (!isNaN(v) && v >= 0 && v <= 1 && Math.abs(video.volume - v) > 0.01) {
                video.volume = v;
                savedVolume = v;
                if (video.muted && v > 0) {
                    video.muted = false;
                    savedMuted = false;
                }
                if (volumeSlider) volumeSlider.value = video.muted ? 0 : v;
            }
        }
    });

    // Mutual exclusion: pause video if persistent audio begins playing
    window.addEventListener('fm:audio:play', () => {
        if (video && !video.paused) {
            video.pause();
        }
    });

    // Picture-in-Picture Button Initialization
    const enablePipSetting = window.FavoriteMultimediaConfig?.enablePip !== false;
    let pipBtn = wrapper.querySelector('.fav-btn-pip');

    if (enablePipSetting && document.pictureInPictureEnabled) {
        if (!pipBtn && fullscreenBtn && fullscreenBtn.parentNode) {
            pipBtn = document.createElement('button');
            pipBtn.type = 'button';
            pipBtn.className = 'fav-btn-control fav-btn-pip';
            pipBtn.title = 'Picture-in-Picture';
            pipBtn.setAttribute('aria-label', 'Picture-in-Picture');
            pipBtn.innerHTML = '&#x29C9;'; // ⧉ symbol
            fullscreenBtn.parentNode.insertBefore(pipBtn, fullscreenBtn);
        }
        if (pipBtn) {
            pipBtn.style.display = (activeSource && classifySource(activeSource) === 'embed') ? 'none' : 'inline-block';
            pipBtn.addEventListener('click', async () => {
                if (!video) return;
                try {
                    if (document.pictureInPictureElement) {
                        await document.exitPictureInPicture();
                    } else if (video.requestPictureInPicture) {
                        await video.requestPictureInPicture();
                    }
                } catch (err) {
                    console.warn('[FM Player] Picture-in-Picture request failed:', err);
                }
            });
        }
    } else if (pipBtn) {
        pipBtn.style.display = 'none';
    }

    if (speedSelect && video) {
        speedSelect.addEventListener('change', (e) => {
            video.playbackRate = parseFloat(e.target.value);
        });
    }

    if (qualitySelect && video) {
        qualitySelect.addEventListener('change', (e) => {
            const newUrl = e.target.value;
            const cur = video.currentTime;
            const wasPlaying = !video.paused;
            video.src = newUrl;
            video.currentTime = cur;
            if (wasPlaying) video.play().catch(() => {});
        });
    }

    if (subtitleSelect && video) {
        subtitleSelect.addEventListener('change', (e) => {
            const lang = e.target.value;
            for (let i = 0; i < video.textTracks.length; i++) {
                const track = video.textTracks[i];
                if (lang === 'off') {
                    track.mode = 'disabled';
                } else if (track.language === lang) {
                    track.mode = 'showing';
                } else {
                    track.mode = 'disabled';
                }
            }
        });
    }

    if (fullscreenBtn) {
        fullscreenBtn.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                if (wrapper.requestFullscreen) {
                    wrapper.requestFullscreen().catch(() => {});
                } else if (wrapper.webkitRequestFullscreen) {
                    wrapper.webkitRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen().catch(() => {});
                }
            }
        });
    }

    function resetHideTimer() {
        if (!controls || !video) return;
        wrapper.classList.remove('fav-controls-hidden');
        if (hideControlsTimeout) clearTimeout(hideControlsTimeout);
        if (!video.paused) {
            hideControlsTimeout = setTimeout(() => {
                wrapper.classList.add('fav-controls-hidden');
            }, 3000);
        }
    }

    wrapper.addEventListener('mousemove', resetHideTimer);
    wrapper.addEventListener('touchstart', resetHideTimer, { passive: true });

    // Initial Engine Bootstrap
    if (activeSource) {
        renderSource(activeSource, 0, false);
    }

    // Playback Progress & Resuming
    const contentType = wrapper.getAttribute('data-content-type');
    const contentId = parseInt(wrapper.getAttribute('data-content-id'), 10);
    const resumePosition = parseFloat(wrapper.getAttribute('data-resume-position') || 0);

    let hasResumed = false;
    const resumePrompt = document.getElementById('fmm-resume-prompt');
    const resumeAcceptBtn = document.getElementById('fmm-resume-accept-btn');
    const resumeStartOverBtn = document.getElementById('fmm-resume-start-over-btn');

    if (resumeAcceptBtn) {
        resumeAcceptBtn.addEventListener('click', () => {
            if (video && resumePosition > 0) {
                video.currentTime = resumePosition;
            }
            if (resumePrompt) resumePrompt.style.display = 'none';
            if (video) video.play().catch(() => {});
        });
    }

    if (resumeStartOverBtn) {
        resumeStartOverBtn.addEventListener('click', () => {
            if (video) video.currentTime = 0;
            if (resumePrompt) resumePrompt.style.display = 'none';
            if (video) video.play().catch(() => {});
        });
    }

    if (video) {
        video.addEventListener('loadedmetadata', () => {
            if (!hasResumed && resumePosition > 0 && !resumeAcceptBtn) {
                video.currentTime = resumePosition;
                hasResumed = true;
            }
        });

        let lastProgressPing = 0;
        function reportProgress(isCompleted = false) {
            if (!contentType || !contentId || isNaN(contentId) || !video) return;
            const currentPos = video.currentTime || 0;
            const totalDuration = video.duration || 0;

            fetch('/multimedia/api/progress', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    content_type: contentType,
                    content_id: contentId,
                    position: currentPos,
                    duration: totalDuration,
                    is_completed: isCompleted ? 1 : 0
                })
            }).catch(() => {});
        }

        video.addEventListener('timeupdate', () => {
            const now = video.currentTime;
            if (Math.abs(now - lastProgressPing) >= 15) {
                lastProgressPing = now;
                reportProgress(false);
            }
        });

        video.addEventListener('pause', () => {
            if (!video.ended) reportProgress(false);
        });

        video.addEventListener('seeked', () => {
            reportProgress(false);
        });

        video.addEventListener('ended', () => {
            reportProgress(true);
            handleNextMedia();
        });
    }

    // Auto Next Playback Controller for Series Episodes & Playlists
    function handleNextMedia() {
        const autoPlaySetting = wrapper.getAttribute('data-auto-play-next') || (window.FavoriteMultimediaSettings && window.FavoriteMultimediaSettings.autoPlayNext) || 'yes';
        const countdownSetting = parseInt(wrapper.getAttribute('data-auto-next-countdown') || (window.FavoriteMultimediaSettings && window.FavoriteMultimediaSettings.autoNextCountdown) || 5, 10);
        const playlistId = wrapper.getAttribute('data-playlist-id');

        if (contentType === 'episode') {
            fetch(`/multimedia/api/next-episode/${contentId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.has_next) {
                        showNextOverlay(data, autoPlaySetting === 'yes', countdownSetting);
                    } else if (data.completed || data.series_completed) {
                        showCompletedOverlay('series', data);
                    }
                })
                .catch(() => {});
        } else if (playlistId) {
            const isLoop = wrapper.getAttribute('data-playlist-loop') === '1';
            fetch(`/multimedia/api/next-playlist-item/${playlistId}/${contentId}?repeat=${isLoop ? '1' : '0'}`)
                .then(res => res.json())
                .then(data => {
                    if (data.has_next) {
                        showNextOverlay(data, autoPlaySetting === 'yes', countdownSetting);
                    } else if (data.completed || data.playlist_completed) {
                        showCompletedOverlay('playlist', data);
                    }
                })
                .catch(() => {});
        }
    }

    function showNextOverlay(data, shouldAutoPlay, countdownSec) {
        const existing = wrapper.querySelector('.fav-next-overlay, .fav-next-episode-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'fav-next-overlay';
        let countdown = countdownSec || 5;

        const title = data.title || 'Next Up';
        const url = data.url || '#';
        const poster = data.thumbnail || data.poster || data.cover || '';
        const badge = data.episode_number ? `Season ${data.season_number || 1} • Episode ${data.episode_number}` : (data.artist || 'Next Track');
        const isAccessible = data.is_accessible !== false;

        let countdownHtml = shouldAutoPlay && isAccessible
            ? `<div class="fav-next-countdown-badge">Up Next in <span class="fav-countdown-num">${countdown}</span>s</div>`
            : `<div class="fav-next-countdown-badge">Up Next</div>`;

        let actionHtml = '';
        if (isAccessible) {
            actionHtml = `
                <a href="${url}" class="fav-btn-next-play">Play Now</a>
                <button type="button" class="fav-btn-next-cancel" id="fav-cancel-next">Cancel</button>
            `;
        } else {
            const upUrl = data.upgrade_url || url;
            const upLabel = (data.access_state === 'PREMIUM_REQUIRED') ? '⭐ Unlock Premium' : '🔒 Sign In to Watch';
            actionHtml = `
                <a href="${upUrl}" class="fav-btn-next-play" style="background: #eab308; color: #0f172a;">${upLabel}</a>
                <button type="button" class="fav-btn-next-cancel" id="fav-cancel-next">Dismiss</button>
            `;
        }

        overlay.innerHTML = `
            <div class="fav-next-card">
                ${countdownHtml}
                <div class="fav-next-content-preview">
                    ${poster ? `<img src="${poster}" alt="" class="fav-next-thumbnail" onerror="this.style.display='none'">` : ''}
                    <div class="fav-next-details">
                        <span class="fav-next-meta-badge">${badge}</span>
                        <h4 class="fav-next-title">${title}</h4>
                    </div>
                </div>
                <div class="fav-next-actions">
                    ${actionHtml}
                </div>
            </div>
        `;

        wrapper.appendChild(overlay);

        let timer = null;
        if (shouldAutoPlay && isAccessible) {
            timer = setInterval(() => {
                countdown--;
                const numEl = overlay.querySelector('.fav-countdown-num');
                if (numEl) numEl.textContent = countdown;
                if (countdown <= 0) {
                    clearInterval(timer);
                    window.location.href = url;
                }
            }, 1000);
        }

        const cancelBtn = overlay.querySelector('#fav-cancel-next');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                if (timer) clearInterval(timer);
                overlay.remove();
            });
        }
    }

    function showCompletedOverlay(type, data) {
        const existing = wrapper.querySelector('.fav-next-overlay, .fav-next-episode-overlay, .fav-completed-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'fav-completed-overlay';
        const isSeries = (type === 'series');
        const title = isSeries ? 'Series Completed' : 'Playlist Completed';
        const msg = isSeries ? 'You have watched all available episodes in this series.' : 'You have reached the end of this playlist.';
        const backUrl = isSeries && data && data.series_slug ? `/series/${data.series_slug}` : '';

        overlay.innerHTML = `
            <div class="fav-completed-card">
                <div style="font-size: 36px; margin-bottom: 8px;">🎉</div>
                <h3 style="color: #fff; margin: 0 0 6px 0; font-size: 18px;">${title}</h3>
                <p style="color: #94a3b8; font-size: 13px; margin: 0 0 16px 0;">${msg}</p>
                <div style="display: flex; gap: 8px; justify-content: center;">
                    ${backUrl ? `<a href="${backUrl}" class="fav-btn-next-play" style="text-decoration:none;">View Series</a>` : ''}
                    <button type="button" class="fav-btn-next-cancel" id="fav-close-completed">Close</button>
                </div>
            </div>
        `;
        wrapper.appendChild(overlay);
        const closeBtn = overlay.querySelector('#fav-close-completed');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => overlay.remove());
        }
    }
}

// Global Handlers for Favorites and History
document.addEventListener('DOMContentLoaded', function () {
    // Favorite Toggle Buttons
    document.addEventListener('click', function (e) {
        const favBtn = e.target.closest('[data-fmm-fav-toggle]');
        if (!favBtn) return;
        e.preventDefault();

        const cType = favBtn.getAttribute('data-content-type');
        const cId = parseInt(favBtn.getAttribute('data-content-id'), 10);
        if (!cType || !cId) return;

        fetch('/multimedia/api/favorite/toggle', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ content_type: cType, content_id: cId })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const label = favBtn.querySelector('.fav-btn-label');
                if (data.favorited) {
                    favBtn.classList.add('active');
                    if (label) label.textContent = 'In My List';
                } else {
                    favBtn.classList.remove('active');
                    if (label) label.textContent = 'Add to My List';
                    const card = favBtn.closest('.fmm-favorite-card');
                    if (card && window.location.pathname.includes('/my-list')) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.95)';
                        setTimeout(() => card.remove(), 200);
                    }
                }
            } else if (data.status === 'unauthenticated') {
                window.location.href = '/admin/login?redirect=' + encodeURIComponent(window.location.pathname);
            }
        })
        .catch(() => {});
    });

    // History Remove Item
    document.addEventListener('click', function (e) {
        const removeBtn = e.target.closest('[data-fmm-history-remove]');
        if (!removeBtn) return;
        e.preventDefault();

        const cType = removeBtn.getAttribute('data-content-type');
        const cId = parseInt(removeBtn.getAttribute('data-content-id'), 10);
        if (!cType || !cId) return;

        fetch('/multimedia/api/history/remove', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ content_type: cType, content_id: cId })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const item = removeBtn.closest('.fmm-history-item, .fmm-card');
                if (item) {
                    item.style.opacity = '0';
                    item.style.transform = 'scale(0.95)';
                    setTimeout(() => item.remove(), 200);
                }
            }
        })
        .catch(() => {});
    });

    // History Clear All
    document.addEventListener('click', function (e) {
        const clearBtn = e.target.closest('[data-fmm-history-clear]');
        if (!clearBtn) return;
        e.preventDefault();

        if (!confirm('Are you sure you want to clear your entire watch and listening history?')) return;

        fetch('/multimedia/api/history/clear', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.reload();
            }
        })
        .catch(() => {});
    });

    // Close download dropdown menus on click outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.fav-download-dropdown')) {
            document.querySelectorAll('.fav-download-dropdown-menu.fav-show').forEach(function (menu) {
                menu.classList.remove('fav-show');
            });
        }
    });
});
