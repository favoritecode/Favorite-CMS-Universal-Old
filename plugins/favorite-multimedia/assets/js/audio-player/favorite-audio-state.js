/**
 * Favorite Multimedia — Audio Player State Persistence (Chunk 3)
 * Manages sessionStorage for seamless page-to-page audio continuity.
 * Never stores or persists raw media stream URLs or sensitive tokens.
 */
(function (window) {
    'use strict';

    const STORAGE_KEY = 'favorite_multimedia_audio_state';
    const SCHEMA_VERSION = 1;

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

    const DefaultState = {
        songId: null,
        status: 'idle',
        isPlayingIntent: false,
        position: 0,
        duration: 0,
        volume: window.FavoriteMediaVolume.get(),
        isMuted: false,
        repeatMode: 'off', // 'off' | 'queue' | 'one'
        isShuffled: false,
        queueContext: 'manual',
        contextId: null,
        currentIndex: -1,
        queue: [],
        schemaVersion: SCHEMA_VERSION
    };

    const AudioState = {
        get() {
            try {
                const raw = sessionStorage.getItem(STORAGE_KEY);
                const unifiedVol = window.FavoriteMediaVolume.get();
                if (!raw) return { ...DefaultState, volume: unifiedVol };
                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object') return { ...DefaultState, volume: unifiedVol };

                return {
                    songId: parsed.songId ? parseInt(parsed.songId, 10) : null,
                    status: typeof parsed.status === 'string' ? parsed.status : 'idle',
                    isPlayingIntent: !!parsed.isPlayingIntent,
                    position: typeof parsed.position === 'number' ? Math.max(0, parsed.position) : 0,
                    duration: typeof parsed.duration === 'number' ? Math.max(0, parsed.duration) : 0,
                    volume: window.FavoriteMediaVolume.shouldRemember()
                        ? unifiedVol
                        : (typeof parsed.volume === 'number' ? Math.min(1, Math.max(0, parsed.volume)) : unifiedVol),
                    isMuted: !!parsed.isMuted,
                    repeatMode: ['off', 'queue', 'one'].includes(parsed.repeatMode) ? parsed.repeatMode : 'off',
                    isShuffled: !!parsed.isShuffled,
                    queueContext: typeof parsed.queueContext === 'string' ? parsed.queueContext : 'manual',
                    contextId: parsed.contextId ? parseInt(parsed.contextId, 10) : null,
                    currentIndex: typeof parsed.currentIndex === 'number' ? parsed.currentIndex : -1,
                    queue: Array.isArray(parsed.queue) ? parsed.queue.map(this.sanitizeQueueItem) : [],
                    schemaVersion: SCHEMA_VERSION
                };
            } catch (e) {
                console.warn('[FM AudioState] Failed to parse state from storage:', e);
                return { ...DefaultState };
            }
        },

        save(state) {
            try {
                const sanitized = {
                    songId: state.songId ? parseInt(state.songId, 10) : null,
                    status: state.status || 'idle',
                    isPlayingIntent: !!state.isPlayingIntent,
                    position: Math.max(0, parseFloat(state.position) || 0),
                    duration: Math.max(0, parseFloat(state.duration) || 0),
                    volume: Math.min(1, Math.max(0, parseFloat(state.volume) || 1.0)),
                    isMuted: !!state.isMuted,
                    repeatMode: ['off', 'queue', 'one'].includes(state.repeatMode) ? state.repeatMode : 'off',
                    isShuffled: !!state.isShuffled,
                    queueContext: state.queueContext || 'manual',
                    contextId: state.contextId ? parseInt(state.contextId, 10) : null,
                    currentIndex: typeof state.currentIndex === 'number' ? state.currentIndex : -1,
                    queue: Array.isArray(state.queue) ? state.queue.map(this.sanitizeQueueItem) : [],
                    schemaVersion: SCHEMA_VERSION
                };

                sessionStorage.setItem(STORAGE_KEY, JSON.stringify(sanitized));
            } catch (e) {
                console.warn('[FM AudioState] Failed to save state to storage:', e);
            }
        },

        clear() {
            try {
                sessionStorage.removeItem(STORAGE_KEY);
            } catch (e) {}
        },

        hasSession() {
            const s = this.get();
            return !!(s.songId || (s.queue && s.queue.length > 0));
        },

        sanitizeQueueItem(item) {
            if (!item || typeof item !== 'object') return null;
            return {
                id: parseInt(item.id, 10) || 0,
                content_type: 'song',
                title: String(item.title || 'Untitled Track'),
                artist: String(item.artist || item.artist_name || 'Unknown Artist'),
                cover: String(item.cover || item.artwork || ''),
                duration: parseInt(item.duration, 10) || 0,
                access_mode: String(item.access_mode || 'public'),
                album_id: item.album_id ? parseInt(item.album_id, 10) : null,
                artist_id: item.artist_id ? parseInt(item.artist_id, 10) : null
                // Note: stream_url is INTENTIONALLY omitted for zero-leak security
            };
        }
    };

    window.FavoriteAudioState = AudioState;
})(window);

