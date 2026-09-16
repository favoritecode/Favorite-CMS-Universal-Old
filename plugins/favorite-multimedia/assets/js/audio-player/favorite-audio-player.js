/**
 * Favorite Multimedia — Global Audio Player Engine (Chunk 3)
 * One canonical persistent HTMLAudioElement.
 * Coordinates playback, access authorization, auto-next, and video mutual exclusion.
 */
(function (window) {
    'use strict';

    class GlobalAudioPlayer {
        constructor() {
            if (window.__FM_GLOBAL_AUDIO_PLAYER_INSTANCE__) {
                return window.__FM_GLOBAL_AUDIO_PLAYER_INSTANCE__;
            }

            this.audio = new Audio();
            this.audio.preload = 'metadata';

            this.queue = new window.FavoriteAudioQueue();
            this.state = window.FavoriteAudioState;
            this.activeSong = null; // Currently authorized track details
            this.requiresInteraction = false;
            this.progressTimer = null;
            this.lastProgressSave = 0;
            this.isResolving = false;

            this.listeners = new Map();

            this.bindAudioEvents();
            this.bindMutualExclusion();
            this.restoreSession();

            window.__FM_GLOBAL_AUDIO_PLAYER_INSTANCE__ = this;
        }

        bindAudioEvents() {
            const a = this.audio;

            a.addEventListener('play', () => {
                this.requiresInteraction = false;
                this.emit('play', this.activeSong);
                this.saveState(true);
                // Mutual exclusion: notify any video players to pause
                window.dispatchEvent(new CustomEvent('fm:audio:play', { detail: { song: this.activeSong } }));
                this.pauseActiveVideos();
            });

            a.addEventListener('pause', () => {
                this.emit('pause', this.activeSong);
                this.saveState(false);
            });

            a.addEventListener('timeupdate', () => {
                const pos = a.currentTime || 0;
                const dur = a.duration || (this.activeSong ? this.activeSong.duration : 0);
                this.emit('timeupdate', { position: pos, duration: dur });

                // Throttle progress heartbeat to server (every 5 seconds)
                const now = Date.now();
                if (now - this.lastProgressSave > 5000 && this.activeSong && pos > 2) {
                    this.lastProgressSave = now;
                    this.reportProgress(false);
                    this.saveState(true);
                }
            });

            a.addEventListener('ended', () => {
                this.emit('ended', this.activeSong);
                this.reportProgress(true); // Mark completed on track end
                this.autoNext();
            });

            a.addEventListener('error', (e) => {
                console.warn('[FM Audio Player] Playback error:', a.error);
                this.emit('error', { error: a.error, song: this.activeSong });
                // If source fails, attempt next queue item safely
                this.handleSourceError();
            });

            a.addEventListener('waiting', () => {
                this.emit('buffering', true);
            });

            a.addEventListener('playing', () => {
                this.emit('buffering', false);
            });
        }

        bindMutualExclusion() {
            // If any video begins playing, pause audio immediately
            window.addEventListener('fm:video:play', () => {
                if (!this.audio.paused) {
                    this.audio.pause();
                }
            });

            // Page navigation event: save state before unload
            window.addEventListener('beforeunload', () => {
                this.saveState(!this.audio.paused);
            });

            // Sync volume across audio/video players and browser tabs
            window.addEventListener('fm:volumechange', (e) => {
                if (e.detail && typeof e.detail.volume === 'number') {
                    const newVol = e.detail.volume;
                    if (Math.abs(this.audio.volume - newVol) > 0.01) {
                        this.audio.volume = newVol;
                        this.emit('volumechange', { volume: newVol, isMuted: this.audio.muted });
                    }
                }
            });

            window.addEventListener('storage', (e) => {
                if (e.key === 'fm_media_volume' && e.newValue !== null) {
                    const v = parseFloat(e.newValue);
                    if (!isNaN(v) && v >= 0 && v <= 1 && Math.abs(this.audio.volume - v) > 0.01) {
                        this.audio.volume = v;
                        this.emit('volumechange', { volume: v, isMuted: this.audio.muted });
                    }
                }
            });
        }

        pauseActiveVideos() {
            document.querySelectorAll('video').forEach(v => {
                if (!v.paused) v.pause();
            });
        }

        restoreSession() {
            const saved = this.state.get();
            const initialVol = window.FavoriteMediaVolume
                ? window.FavoriteMediaVolume.get()
                : (saved && saved.volume !== undefined ? saved.volume : 0.25);
            this.setVolume(initialVol);

            if (!saved || !saved.songId) return;

            this.queue.initFromState(saved);
            if (saved.isMuted) this.audio.muted = true;
            this.queue.setRepeat(saved.repeatMode || 'off');

            // Find current item in queue
            const item = this.queue.getCurrentItem();
            if (item && item.id === saved.songId) {
                this.activeSong = item;
                this.emit('session_restored', {
                    song: item,
                    position: saved.position,
                    isPlayingIntent: saved.isPlayingIntent
                });

                // If user was actively listening, attempt seamless resume
                if (saved.isPlayingIntent) {
                    this.resolveAndPlay(saved.songId, saved.position, false);
                }
            }
        }

        saveState(isPlaying = !this.audio.paused) {
            const currentItem = this.queue.getCurrentItem() || this.activeSong;
            this.state.save({
                songId: currentItem ? currentItem.id : null,
                status: this.audio.paused ? 'paused' : 'playing',
                isPlayingIntent: isPlaying,
                position: this.audio.currentTime || 0,
                duration: this.audio.duration || (currentItem ? currentItem.duration : 0),
                volume: this.audio.volume,
                isMuted: this.audio.muted,
                repeatMode: this.queue.getRepeatMode(),
                isShuffled: this.queue.isShuffled(),
                queueContext: this.queue.queueContext,
                contextId: this.queue.contextId,
                currentIndex: this.queue.getCurrentIndex(),
                queue: this.queue.getItems()
            });
        }

        async playSong(songId, options = {}) {
            const id = parseInt(songId, 10);
            if (!id || id <= 0) return;

            // If context provided, set queue
            if (options.queue && Array.isArray(options.queue)) {
                const startIndex = options.queue.findIndex(it => it.id === id);
                this.queue.setQueue(options.queue, startIndex !== -1 ? startIndex : 0, options.context || 'manual', options.contextId || null);
            } else if (this.queue.isEmpty()) {
                // Single song play: fetch metadata and set as 1-item queue
                try {
                    const ctxRes = await fetch(`/multimedia/api/audio/context/song/${id}`);
                    const ctxData = await ctxRes.json();
                    if (ctxData.success && Array.isArray(ctxData.items)) {
                        this.queue.setQueue(ctxData.items, 0, 'manual', null);
                    }
                } catch (e) {
                    console.warn('[FM Audio Player] Context fetch fallback:', e);
                }
            }

            const startPos = options.position !== undefined ? parseFloat(options.position) : 0;
            return this.resolveAndPlay(id, startPos, true);
        }

        async playAll(contextType, contextId, startSongId = null) {
            const id = parseInt(contextId, 10);
            if (!id || !contextType) return;

            try {
                this.emit('loading', true);
                const url = `/multimedia/api/audio/context/${encodeURIComponent(contextType)}/${id}` +
                    (startSongId ? `?start_song_id=${encodeURIComponent(startSongId)}` : '');
                const res = await fetch(url);
                const data = await res.json();
                this.emit('loading', false);

                if (!data.success || !Array.isArray(data.items) || data.items.length === 0) {
                    this.emit('notice', { type: 'error', message: 'No playable tracks found in this collection.' });
                    return;
                }

                this.queue.setQueue(data.items, data.start_index || 0, data.context || contextType, id);
                const target = this.queue.getCurrentItem();
                if (target) {
                    return this.resolveAndPlay(target.id, 0, true);
                }
            } catch (e) {
                this.emit('loading', false);
                console.error('[FM Audio Player] Failed to Play All:', e);
                this.emit('notice', { type: 'error', message: 'Failed to load audio collection.' });
            }
        }

        async resolveAndPlay(songId, startPosition = 0, userInitiated = true) {
            if (this.isResolving) return;
            this.isResolving = true;

            try {
                this.emit('loading', true);
                const res = await fetch(`/multimedia/api/audio/resolve/${songId}`);
                const data = await res.json();
                this.emit('loading', false);

                if (!data.success || !data.audio || !data.audio.allowed) {
                    this.handleAccessDenial(data);
                    this.isResolving = false;
                    return false;
                }

                const audioData = data.audio;
                this.activeSong = audioData;

                // Load source into single HTMLAudioElement
                this.audio.src = audioData.stream_url;
                if (startPosition > 0) {
                    this.audio.currentTime = startPosition;
                }

                this.emit('track_changed', audioData);
                this.updateMediaSession(audioData);

                // Attempt playback
                const playPromise = this.audio.play();
                if (playPromise !== undefined) {
                    playPromise.catch(err => {
                        if (err.name === 'NotAllowedError') {
                            // Autoplay blocked by browser policy
                            this.requiresInteraction = true;
                            this.emit('autoplay_blocked', { song: audioData });
                            console.info('[FM Audio Player] Browser blocked autoplay. Awaiting user interaction.');
                        } else {
                            console.warn('[FM Audio Player] Play promise rejected:', err);
                        }
                    });
                }

                this.isResolving = false;
                this.saveState(true);
                return true;
            } catch (e) {
                this.emit('loading', false);
                this.isResolving = false;
                console.error('[FM Audio Player] Resolve error:', e);
                this.emit('notice', { type: 'error', message: 'Error establishing audio stream.' });
                return false;
            }
        }

        togglePlayPause() {
            if (this.audio.paused) {
                this.play();
            } else {
                this.pause();
            }
        }

        play() {
            this.requiresInteraction = false;
            if (!this.audio.src && this.queue.getCurrentItem()) {
                const item = this.queue.getCurrentItem();
                this.resolveAndPlay(item.id, 0, true);
                return;
            }
            this.audio.play().catch(err => {
                if (err.name === 'NotAllowedError') {
                    this.requiresInteraction = true;
                    this.emit('autoplay_blocked', { song: this.activeSong });
                }
            });
        }

        pause() {
            this.audio.pause();
        }

        next() {
            const nextItem = this.queue.next();
            if (nextItem) {
                this.resolveAndPlay(nextItem.id, 0, true);
            } else {
                this.emit('queue_ended');
                this.audio.pause();
            }
        }

        previous() {
            const result = this.queue.previous(this.audio.currentTime || 0, 3.0);
            if (result.action === 'restart') {
                this.audio.currentTime = 0;
                this.audio.play().catch(() => {});
            } else if (result.item) {
                this.resolveAndPlay(result.item.id, 0, true);
            }
        }

        autoNext() {
            const nextItem = this.queue.next();
            if (nextItem) {
                // Auto-advance without throwing loud alerts on browser autoplay block
                this.resolveAndPlay(nextItem.id, 0, false);
            } else {
                this.emit('queue_ended');
                this.saveState(false);
            }
        }

        handleSourceError() {
            // Safely advance to next playable item without infinite loops
            const nextItem = this.queue.next();
            if (nextItem && (!this.activeSong || nextItem.id !== this.activeSong.song_id)) {
                setTimeout(() => this.resolveAndPlay(nextItem.id, 0, false), 500);
            } else {
                this.emit('notice', { type: 'error', message: 'Unable to play current track.' });
            }
        }

        handleAccessDenial(response) {
            const reason = response.reason || 'forbidden';
            const message = response.error || 'Access denied.';

            if (reason === 'login_required') {
                this.emit('notice', {
                    type: 'auth',
                    message: message,
                    actionUrl: response.details ? response.details.login_url : '/admin/login'
                });
            } else if (reason === 'premium_required') {
                this.emit('notice', {
                    type: 'premium',
                    message: message,
                    actionUrl: response.details ? response.details.subscription_url : '/pricing'
                });
            } else {
                this.emit('notice', { type: 'error', message: message });
            }
        }

        seek(seconds) {
            if (this.audio.duration) {
                this.audio.currentTime = Math.max(0, Math.min(this.audio.duration, seconds));
            }
        }

        seekRelative(deltaSeconds) {
            const target = (this.audio.currentTime || 0) + deltaSeconds;
            this.seek(target);
        }

        setVolume(volume) {
            const vol = Math.max(0, Math.min(1, parseFloat(volume) || 0));
            this.audio.volume = vol;
            this.audio.muted = (vol === 0);
            if (window.FavoriteMediaVolume) {
                window.FavoriteMediaVolume.set(vol);
            }
            this.emit('volumechange', { volume: vol, isMuted: this.audio.muted });
            this.saveState();
        }

        toggleMute() {
            if (this.audio.muted) {
                const restoreVol = window.FavoriteMediaVolume ? window.FavoriteMediaVolume.getUnmuteVolume() : 0.25;
                this.audio.muted = false;
                this.setVolume(restoreVol);
            } else {
                this.audio.muted = true;
                this.emit('volumechange', { volume: this.audio.volume, isMuted: true });
                this.saveState();
            }
        }

        updateMediaSession(song) {
            if (!('mediaSession' in navigator) || !song) return;
            try {
                navigator.mediaSession.metadata = new MediaMetadata({
                    title: song.title || 'Unknown Title',
                    artist: song.artist || song.artist_name || 'Various Artists',
                    album: song.album || song.album_name || '',
                    artwork: song.cover ? [{ src: song.cover, sizes: '512x512', type: 'image/jpeg' }] : []
                });

                navigator.mediaSession.setActionHandler('play', () => this.play());
                navigator.mediaSession.setActionHandler('pause', () => this.pause());
                navigator.mediaSession.setActionHandler('previoustrack', () => this.previous());
                navigator.mediaSession.setActionHandler('nexttrack', () => this.next());
                navigator.mediaSession.setActionHandler('seekto', (details) => {
                    if (details.seekTime !== undefined) {
                        this.seek(details.seekTime);
                    }
                });
            } catch (e) {}
        }

        toggleShuffle() {
            const shuffled = this.queue.toggleShuffle();
            this.emit('shuffle_changed', shuffled);
            this.saveState();
            return shuffled;
        }

        setRepeat(mode) {
            this.queue.setRepeat(mode);
            this.emit('repeat_changed', mode);
            this.saveState();
        }

        async reportProgress(isCompleted = false) {
            if (!this.activeSong) return;
            const songId = this.activeSong.song_id || this.activeSong.id;
            const pos = this.audio.currentTime || 0;
            const dur = this.audio.duration || (this.activeSong.duration || 0);

            try {
                await fetch('/multimedia/api/audio/progress', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        song_id: songId,
                        position: pos,
                        duration: dur,
                        is_completed: isCompleted ? 1 : 0
                    })
                });
            } catch (e) {}
        }

        // Event Emitter
        on(event, callback) {
            if (!this.listeners.has(event)) {
                this.listeners.set(event, []);
            }
            this.listeners.get(event).push(callback);
        }

        emit(event, data) {
            if (this.listeners.has(event)) {
                this.listeners.get(event).forEach(cb => {
                    try { cb(data); } catch (e) { console.error(e); }
                });
            }
        }
    }

    // Initialize Global Audio Player on window load
    document.addEventListener('DOMContentLoaded', () => {
        window.FavoriteAudioPlayer = new GlobalAudioPlayer();
    });
})(window);

