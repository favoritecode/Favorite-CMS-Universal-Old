/**
 * Favorite Multimedia — Audio Player State Persistence (Chunk 3)
 * Manages sessionStorage for seamless page-to-page audio continuity.
 * Never stores or persists raw media stream URLs or sensitive tokens.
 */
(function (window) {
    'use strict';

    const STORAGE_KEY = 'favorite_multimedia_audio_state';
    const SCHEMA_VERSION = 1;

    const DefaultState = {
        songId: null,
        status: 'idle',
        isPlayingIntent: false,
        position: 0,
        duration: 0,
        volume: 1.0,
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
                if (!raw) return { ...DefaultState };
                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object') return { ...DefaultState };

                return {
                    songId: parsed.songId ? parseInt(parsed.songId, 10) : null,
                    status: typeof parsed.status === 'string' ? parsed.status : 'idle',
                    isPlayingIntent: !!parsed.isPlayingIntent,
                    position: typeof parsed.position === 'number' ? Math.max(0, parsed.position) : 0,
                    duration: typeof parsed.duration === 'number' ? Math.max(0, parsed.duration) : 0,
                    volume: typeof parsed.volume === 'number' ? Math.min(1, Math.max(0, parsed.volume)) : 1.0,
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

