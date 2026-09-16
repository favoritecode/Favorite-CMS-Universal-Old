/**
 * Favorite Multimedia — Audio UI Controller (Chunk 3)
 * Coordinates Mini-Player, Expanded Player Modal, Queue Drawer,
 * Song Row Active Highlighting, Toast Feedback, and MediaSession API.
 */
(function (window, document) {
    'use strict';

    class AudioUIController {
        constructor() {
            this.player = null;
            this.elements = {};
            this.isSeeking = false;
            this.expandedLyricsOpen = false;

            document.addEventListener('DOMContentLoaded', () => {
                this.init();
            });
        }

        init() {
            this.player = window.FavoriteAudioPlayer;
            if (!this.player) {
                setTimeout(() => this.init(), 50);
                return;
            }

            this.cacheElements();
            this.bindPlayerEvents();
            this.bindUIEvents();
            this.bindPageDelegations();
            this.bindKeyboardShortcuts();
            this.bindMediaSession();

            // Check if player has active song or session restored
            if (this.player.activeSong) {
                this.updateTrackInfo(this.player.activeSong);
                this.showMiniPlayer();
            }
        }

        cacheElements() {
            // Mini Player Elements
            this.elements.miniPlayer = document.getElementById('fm-mini-player');
            this.elements.miniArt = document.getElementById('fm-mini-player-art');
            this.elements.miniArtEmpty = document.getElementById('fm-mini-player-art-empty');
            this.elements.miniTitle = document.getElementById('fm-mini-player-title');
            this.elements.miniArtist = document.getElementById('fm-mini-player-artist');
            this.elements.miniPlayBtn = document.getElementById('fm-mini-player-play-btn');
            this.elements.miniPrevBtn = document.getElementById('fm-mini-player-prev-btn');
            this.elements.miniNextBtn = document.getElementById('fm-mini-player-next-btn');
            this.elements.miniProgress = document.getElementById('fm-mini-player-progress');
            this.elements.miniCurrent = document.getElementById('fm-mini-player-current');
            this.elements.miniDuration = document.getElementById('fm-mini-player-duration');
            this.elements.miniVolume = document.getElementById('fm-mini-player-volume');
            this.elements.miniMuteBtn = document.getElementById('fm-mini-player-mute-btn');
            this.elements.miniExpandBtn = document.getElementById('fm-mini-player-expand-btn');
            this.elements.miniQueueBtn = document.getElementById('fm-mini-player-queue-btn');
            this.elements.miniCloseBtn = document.getElementById('fm-mini-player-close-btn');

            // Expanded Player Modal Elements
            this.elements.expandedPlayer = document.getElementById('fm-expanded-player');
            this.elements.expandedCloseBtn = document.getElementById('fm-expanded-player-close');
            this.elements.expandedArt = document.getElementById('fm-expanded-player-art');
            this.elements.expandedArtEmpty = document.getElementById('fm-expanded-player-art-empty');
            this.elements.expandedTitle = document.getElementById('fm-expanded-player-title');
            this.elements.expandedArtist = document.getElementById('fm-expanded-player-artist');
            this.elements.expandedAlbum = document.getElementById('fm-expanded-player-album');
            this.elements.expandedProgress = document.getElementById('fm-expanded-player-progress');
            this.elements.expandedCurrent = document.getElementById('fm-expanded-player-current');
            this.elements.expandedDuration = document.getElementById('fm-expanded-player-duration');
            this.elements.expandedPlayBtn = document.getElementById('fm-expanded-player-play-btn');
            this.elements.expandedPrevBtn = document.getElementById('fm-expanded-player-prev-btn');
            this.elements.expandedNextBtn = document.getElementById('fm-expanded-player-next-btn');
            this.elements.expandedShuffleBtn = document.getElementById('fm-expanded-player-shuffle-btn');
            this.elements.expandedRepeatBtn = document.getElementById('fm-expanded-player-repeat-btn');
            this.elements.expandedVolume = document.getElementById('fm-expanded-player-volume');
            this.elements.expandedLyricsToggle = document.getElementById('fm-expanded-lyrics-toggle');
            this.elements.expandedLyricsContent = document.getElementById('fm-expanded-lyrics-content');
            this.elements.expandedQueueBtn = document.getElementById('fm-expanded-queue-btn');

            // Queue Drawer Elements
            this.elements.queueDrawer = document.getElementById('fm-audio-queue-drawer');
            this.elements.queueCloseBtn = document.getElementById('fm-queue-drawer-close');
            this.elements.queueList = document.getElementById('fm-queue-drawer-list');
            this.elements.queueCount = document.getElementById('fm-queue-drawer-count');
            this.elements.queueClearBtn = document.getElementById('fm-queue-clear-btn');

            // Global Toast Notification
            this.elements.toastContainer = document.getElementById('fm-audio-toast-container');
        }

        bindPlayerEvents() {
            const p = this.player;

            p.on('play', (song) => {
                this.setPlayState(true);
                this.showMiniPlayer();
                if (song) {
                    this.highlightActiveSong(song.song_id || song.id, true);
                    this.updateMediaSession(song);
                }
            });

            p.on('pause', (song) => {
                this.setPlayState(false);
                if (song) {
                    this.highlightActiveSong(song.song_id || song.id, false);
                }
            });

            p.on('track_changed', (song) => {
                this.updateTrackInfo(song);
                this.showMiniPlayer();
                this.highlightActiveSong(song.song_id || song.id, !p.audio.paused);
                this.updateMediaSession(song);
                this.renderQueue();
            });

            p.on('timeupdate', (data) => {
                if (!this.isSeeking) {
                    this.updateProgress(data.position, data.duration);
                }
            });

            p.on('session_restored', (data) => {
                if (data.song) {
                    this.updateTrackInfo(data.song);
                    this.showMiniPlayer();
                    this.updateProgress(data.position || 0, data.song.duration || 0);
                    this.setPlayState(data.isPlayingIntent);
                    this.highlightActiveSong(data.song.id, data.isPlayingIntent);
                }
                this.renderQueue();
            });

            p.on('volumechange', (data) => {
                this.updateVolume(data.volume, data.isMuted);
            });

            p.on('shuffle_changed', (isShuffled) => {
                this.updateShuffleUI(isShuffled);
                this.renderQueue();
            });

            p.on('repeat_changed', (mode) => {
                this.updateRepeatUI(mode);
            });

            p.on('queue_ended', () => {
                this.setPlayState(false);
                this.showToast('Queue finished', 'info');
                this.resetSongRows();
            });

            p.on('notice', (notice) => {
                this.showToast(notice.message, notice.type, notice.actionUrl);
            });

            p.on('autoplay_blocked', (data) => {
                this.setPlayState(false);
                this.showToast('Playback paused. Click Play to listen.', 'warning');
            });
        }

        bindUIEvents() {
            const p = this.player;

            // Mini Player Controls
            if (this.elements.miniPlayBtn) {
                this.elements.miniPlayBtn.addEventListener('click', () => p.togglePlayPause());
            }
            if (this.elements.miniPrevBtn) {
                this.elements.miniPrevBtn.addEventListener('click', () => p.previous());
            }
            if (this.elements.miniNextBtn) {
                this.elements.miniNextBtn.addEventListener('click', () => p.next());
            }
            if (this.elements.miniCloseBtn) {
                this.elements.miniCloseBtn.addEventListener('click', () => {
                    p.pause();
                    this.hideMiniPlayer();
                });
            }

            // Mini Player Seek Scrubber
            if (this.elements.miniProgress) {
                this.setupScrubber(this.elements.miniProgress);
            }

            // Mini Player Volume
            if (this.elements.miniVolume) {
                this.elements.miniVolume.addEventListener('input', (e) => {
                    p.setVolume(parseFloat(e.target.value));
                });
            }
            if (this.elements.miniMuteBtn) {
                this.elements.miniMuteBtn.addEventListener('click', () => p.toggleMute());
            }

            // Drawer & Modal Triggers
            if (this.elements.miniExpandBtn) {
                this.elements.miniExpandBtn.addEventListener('click', () => this.openExpandedPlayer());
            }
            if (this.elements.miniQueueBtn) {
                this.elements.miniQueueBtn.addEventListener('click', () => this.toggleQueueDrawer());
            }

            // Expanded Player Controls
            if (this.elements.expandedCloseBtn) {
                this.elements.expandedCloseBtn.addEventListener('click', () => this.closeExpandedPlayer());
            }
            if (this.elements.expandedPlayBtn) {
                this.elements.expandedPlayBtn.addEventListener('click', () => p.togglePlayPause());
            }
            if (this.elements.expandedPrevBtn) {
                this.elements.expandedPrevBtn.addEventListener('click', () => p.previous());
            }
            if (this.elements.expandedNextBtn) {
                this.elements.expandedNextBtn.addEventListener('click', () => p.next());
            }
            if (this.elements.expandedShuffleBtn) {
                this.elements.expandedShuffleBtn.addEventListener('click', () => p.toggleShuffle());
            }
            if (this.elements.expandedRepeatBtn) {
                this.elements.expandedRepeatBtn.addEventListener('click', () => {
                    const current = p.queue.getRepeatMode();
                    const nextMode = current === 'off' ? 'queue' : (current === 'queue' ? 'one' : 'off');
                    p.setRepeat(nextMode);
                });
            }
            if (this.elements.expandedProgress) {
                this.setupScrubber(this.elements.expandedProgress);
            }
            if (this.elements.expandedVolume) {
                this.elements.expandedVolume.addEventListener('input', (e) => {
                    p.setVolume(parseFloat(e.target.value));
                });
            }
            if (this.elements.expandedLyricsToggle) {
                this.elements.expandedLyricsToggle.addEventListener('click', () => this.toggleLyrics());
            }
            if (this.elements.expandedQueueBtn) {
                this.elements.expandedQueueBtn.addEventListener('click', () => {
                    this.closeExpandedPlayer();
                    this.openQueueDrawer();
                });
            }

            // Queue Drawer Controls
            if (this.elements.queueCloseBtn) {
                this.elements.queueCloseBtn.addEventListener('click', () => this.closeQueueDrawer());
            }
            if (this.elements.queueClearBtn) {
                this.elements.queueClearBtn.addEventListener('click', () => {
                    p.queue.clear();
                    this.renderQueue();
                    this.showToast('Queue cleared', 'info');
                });
            }
        }

        bindPageDelegations() {
            const p = this.player;

            // Delegated click handler on document for instant action triggers
            document.addEventListener('click', (e) => {
                // 1. Play Single Song [data-fm-play-song]
                const playBtn = e.target.closest('[data-fm-play-song]');
                if (playBtn) {
                    e.preventDefault();
                    const songId = playBtn.getAttribute('data-fm-play-song') || playBtn.getAttribute('data-song-id');
                    const contextType = playBtn.getAttribute('data-context-type') || 'manual';
                    const contextId = playBtn.getAttribute('data-context-id') || null;

                    // If song is currently playing, toggle pause
                    if (p.activeSong && (p.activeSong.song_id == songId || p.activeSong.id == songId)) {
                        p.togglePlayPause();
                        return;
                    }

                    // Collect sibling song rows on page to form intelligent queue
                    const siblingRows = document.querySelectorAll('[data-fm-play-song], .fm-song-row[data-id]');
                    let pageQueue = [];
                    if (siblingRows.length > 1) {
                        siblingRows.forEach(row => {
                            const rId = row.getAttribute('data-fm-play-song') || row.getAttribute('data-id') || row.getAttribute('data-song-id');
                            const rTitle = row.getAttribute('data-title') || row.querySelector('.fm-song-title')?.textContent?.trim() || 'Track';
                            const rArtist = row.getAttribute('data-artist') || row.querySelector('.fm-song-artist')?.textContent?.trim() || '';
                            const rCover = row.getAttribute('data-cover') || row.querySelector('.fm-song-thumb')?.getAttribute('src') || '';
                            const rDur = parseInt(row.getAttribute('data-duration') || '0', 10);

                            if (rId && !pageQueue.some(q => q.id === parseInt(rId, 10))) {
                                pageQueue.push({
                                    id: parseInt(rId, 10),
                                    title: rTitle,
                                    artist: rArtist,
                                    cover: rCover,
                                    duration: rDur
                                });
                            }
                        });
                    }

                    if (pageQueue.length > 0) {
                        p.playSong(songId, { queue: pageQueue, context: contextType, contextId: contextId });
                    } else {
                        p.playSong(songId);
                    }
                    return;
                }

                // 2. Play All in Collection [data-fm-play-all]
                const playAllBtn = e.target.closest('[data-fm-play-all]');
                if (playAllBtn) {
                    e.preventDefault();
                    const ctxType = playAllBtn.getAttribute('data-context-type') || playAllBtn.getAttribute('data-fm-play-all');
                    const ctxId = playAllBtn.getAttribute('data-context-id');
                    const startSongId = playAllBtn.getAttribute('data-start-song-id') || null;

                    if (ctxType && ctxId) {
                        p.playAll(ctxType, ctxId, startSongId);
                    }
                    return;
                }

                // 3. Add Track to Queue [data-fm-queue-add]
                const queueAddBtn = e.target.closest('[data-fm-queue-add]');
                if (queueAddBtn) {
                    e.preventDefault();
                    const sId = parseInt(queueAddBtn.getAttribute('data-fm-queue-add') || queueAddBtn.getAttribute('data-song-id'), 10);
                    const title = queueAddBtn.getAttribute('data-title') || 'Track';
                    const artist = queueAddBtn.getAttribute('data-artist') || '';
                    const cover = queueAddBtn.getAttribute('data-cover') || '';
                    const duration = parseInt(queueAddBtn.getAttribute('data-duration') || '0', 10);

                    if (sId) {
                        p.queue.add({ id: sId, title, artist, cover, duration });
                        this.renderQueue();
                        this.showToast(`"${title}" added to queue`, 'success');
                    }
                    return;
                }

                // 4. Play Track Next [data-fm-play-next]
                const playNextBtn = e.target.closest('[data-fm-play-next]');
                if (playNextBtn) {
                    e.preventDefault();
                    const sId = parseInt(playNextBtn.getAttribute('data-fm-play-next') || playNextBtn.getAttribute('data-song-id'), 10);
                    const title = playNextBtn.getAttribute('data-title') || 'Track';
                    const artist = playNextBtn.getAttribute('data-artist') || '';
                    const cover = playNextBtn.getAttribute('data-cover') || '';
                    const duration = parseInt(playNextBtn.getAttribute('data-duration') || '0', 10);

                    if (sId) {
                        p.queue.insertNext({ id: sId, title, artist, cover, duration });
                        this.renderQueue();
                        this.showToast(`"${title}" will play next`, 'success');
                    }
                    return;
                }
            });
        }

        bindKeyboardShortcuts() {
            const p = this.player;

            window.addEventListener('keydown', (e) => {
                // Suppress if typing in inputs or textareas
                const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
                if (['input', 'textarea', 'select'].includes(activeTag) || document.activeElement?.isContentEditable) {
                    return;
                }

                switch (e.code) {
                    case 'Space':
                        e.preventDefault();
                        p.togglePlayPause();
                        break;
                    case 'ArrowLeft':
                        e.preventDefault();
                        p.seekRelative(-5);
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        p.seekRelative(5);
                        break;
                    case 'KeyM':
                        e.preventDefault();
                        p.toggleMute();
                        break;
                    case 'Escape':
                        if (this.elements.expandedPlayer && this.elements.expandedPlayer.classList.contains('fm-expanded-open')) {
                            this.closeExpandedPlayer();
                        }
                        if (this.elements.queueDrawer && this.elements.queueDrawer.classList.contains('fm-queue-open')) {
                            this.closeQueueDrawer();
                        }
                        break;
                }
            });
        }

        bindMediaSession() {
            if (!('mediaSession' in navigator)) return;

            const p = this.player;

            try {
                navigator.mediaSession.setActionHandler('play', () => p.play());
                navigator.mediaSession.setActionHandler('pause', () => p.pause());
                navigator.mediaSession.setActionHandler('previoustrack', () => p.previous());
                navigator.mediaSession.setActionHandler('nexttrack', () => p.next());
                navigator.mediaSession.setActionHandler('seekto', (details) => {
                    if (details.seekTime !== undefined) p.seek(details.seekTime);
                });
                navigator.mediaSession.setActionHandler('seekbackward', (details) => {
                    p.seekRelative(-(details.seekOffset || 10));
                });
                navigator.mediaSession.setActionHandler('seekforward', (details) => {
                    p.seekRelative(details.seekOffset || 10);
                });
            } catch (e) {
                console.warn('[FM Audio UI] MediaSession handler warning:', e);
            }
        }

        updateMediaSession(song) {
            if (!('mediaSession' in navigator) || !song) return;

            const title = song.title || 'Untitled';
            const artist = song.artist || song.artist_name || 'Favorite Multimedia';
            const album = song.album || song.album_title || '';
            const cover = song.cover || song.cover_image || '';

            const artwork = cover ? [
                { src: cover, sizes: '96x96', type: 'image/jpeg' },
                { src: cover, sizes: '256x256', type: 'image/jpeg' },
                { src: cover, sizes: '512x512', type: 'image/jpeg' }
            ] : [];

            navigator.mediaSession.metadata = new MediaMetadata({
                title: title,
                artist: artist,
                album: album,
                artwork: artwork
            });
        }

        setupScrubber(inputEl) {
            const p = this.player;

            inputEl.addEventListener('mousedown', () => { this.isSeeking = true; });
            inputEl.addEventListener('touchstart', () => { this.isSeeking = true; }, { passive: true });

            inputEl.addEventListener('input', (e) => {
                const targetSec = parseFloat(e.target.value);
                this.updateTimeDisplay(targetSec, p.audio.duration || 0);
            });

            const onRelease = (e) => {
                if (this.isSeeking) {
                    this.isSeeking = false;
                    const targetSec = parseFloat(e.target.value);
                    p.seek(targetSec);
                }
            };

            inputEl.addEventListener('change', onRelease);
            inputEl.addEventListener('mouseup', onRelease);
            inputEl.addEventListener('touchend', onRelease);
        }

        updateTrackInfo(song) {
            if (!song) return;

            const title = song.title || 'Untitled Track';
            const artist = song.artist || song.artist_name || 'Unknown Artist';
            const album = song.album || song.album_title || '';
            const cover = song.cover || song.cover_image || '';
            const lyrics = song.lyrics || '';

            // Mini Player Track Info
            if (this.elements.miniTitle) this.elements.miniTitle.textContent = title;
            if (this.elements.miniArtist) this.elements.miniArtist.textContent = artist;
            if (this.elements.miniArt && this.elements.miniArtEmpty) {
                if (cover) {
                    this.elements.miniArt.src = cover;
                    this.elements.miniArt.style.display = 'block';
                    this.elements.miniArtEmpty.style.display = 'none';
                } else {
                    this.elements.miniArt.style.display = 'none';
                    this.elements.miniArtEmpty.style.display = 'flex';
                }
            }

            // Expanded Player Track Info
            if (this.elements.expandedTitle) this.elements.expandedTitle.textContent = title;
            if (this.elements.expandedArtist) this.elements.expandedArtist.textContent = artist;
            if (this.elements.expandedAlbum) this.elements.expandedAlbum.textContent = album ? `• ${album}` : '';
            if (this.elements.expandedArt && this.elements.expandedArtEmpty) {
                if (cover) {
                    this.elements.expandedArt.src = cover;
                    this.elements.expandedArt.style.display = 'block';
                    this.elements.expandedArtEmpty.style.display = 'none';
                } else {
                    this.elements.expandedArt.style.display = 'none';
                    this.elements.expandedArtEmpty.style.display = 'flex';
                }
            }

            // Lyrics
            if (this.elements.expandedLyricsContent) {
                if (lyrics) {
                    this.elements.expandedLyricsContent.innerHTML = lyrics.replace(/\n/g, '<br>');
                    if (this.elements.expandedLyricsToggle) this.elements.expandedLyricsToggle.style.display = 'inline-flex';
                } else {
                    this.elements.expandedLyricsContent.innerHTML = '<p class="fm-lyrics-empty">No lyrics available for this track.</p>';
                    if (this.elements.expandedLyricsToggle) this.elements.expandedLyricsToggle.style.display = 'none';
                }
            }
        }

        setPlayState(isPlaying) {
            const playIcon = '▶';
            const pauseIcon = '⏸';

            // Mini Button
            if (this.elements.miniPlayBtn) {
                this.elements.miniPlayBtn.innerHTML = isPlaying ? pauseIcon : playIcon;
                this.elements.miniPlayBtn.setAttribute('aria-label', isPlaying ? 'Pause' : 'Play');
                this.elements.miniPlayBtn.classList.toggle('fm-playing', isPlaying);
            }

            // Expanded Button
            if (this.elements.expandedPlayBtn) {
                this.elements.expandedPlayBtn.innerHTML = isPlaying ? pauseIcon : playIcon;
                this.elements.expandedPlayBtn.setAttribute('aria-label', isPlaying ? 'Pause' : 'Play');
                this.elements.expandedPlayBtn.classList.toggle('fm-playing', isPlaying);
            }
        }

        updateProgress(position, duration) {
            const pos = Math.max(0, position || 0);
            const dur = Math.max(0, duration || 0);

            // Mini Scrubber
            if (this.elements.miniProgress) {
                this.elements.miniProgress.max = dur > 0 ? dur : 100;
                this.elements.miniProgress.value = pos;
            }

            // Expanded Scrubber
            if (this.elements.expandedProgress) {
                this.elements.expandedProgress.max = dur > 0 ? dur : 100;
                this.elements.expandedProgress.value = pos;
            }

            this.updateTimeDisplay(pos, dur);
        }

        updateTimeDisplay(pos, dur) {
            const curStr = this.formatTime(pos);
            const durStr = dur > 0 ? this.formatTime(dur) : '--:--';

            if (this.elements.miniCurrent) this.elements.miniCurrent.textContent = curStr;
            if (this.elements.miniDuration) this.elements.miniDuration.textContent = durStr;
            if (this.elements.expandedCurrent) this.elements.expandedCurrent.textContent = curStr;
            if (this.elements.expandedDuration) this.elements.expandedDuration.textContent = durStr;
        }

        updateVolume(volume, isMuted) {
            const val = isMuted ? 0 : volume;

            if (this.elements.miniVolume) this.elements.miniVolume.value = val;
            if (this.elements.expandedVolume) this.elements.expandedVolume.value = val;

            const icon = isMuted || val === 0 ? '🔇' : (val < 0.5 ? '🔉' : '🔊');
            if (this.elements.miniMuteBtn) this.elements.miniMuteBtn.textContent = icon;
        }

        updateShuffleUI(isShuffled) {
            if (this.elements.expandedShuffleBtn) {
                this.elements.expandedShuffleBtn.classList.toggle('active', isShuffled);
                this.elements.expandedShuffleBtn.setAttribute('aria-pressed', isShuffled ? 'true' : 'false');
            }
        }

        updateRepeatUI(mode) {
            if (!this.elements.expandedRepeatBtn) return;
            const btn = this.elements.expandedRepeatBtn;

            btn.classList.remove('active', 'repeat-one');
            if (mode === 'queue') {
                btn.classList.add('active');
                btn.setAttribute('title', 'Repeat Queue (Active)');
                btn.innerHTML = '🔁';
            } else if (mode === 'one') {
                btn.classList.add('active', 'repeat-one');
                btn.setAttribute('title', 'Repeat Track (Active)');
                btn.innerHTML = '🔂';
            } else {
                btn.setAttribute('title', 'Repeat Off');
                btn.innerHTML = '🔁';
            }
        }

        highlightActiveSong(songId, isPlaying) {
            if (!songId) return;

            // Reset all previous rows
            document.querySelectorAll('.fm-song-row, [data-song-id]').forEach(row => {
                const rId = row.getAttribute('data-id') || row.getAttribute('data-song-id') || row.getAttribute('data-fm-play-song');
                if (rId == songId) {
                    row.classList.add('fm-active-track');
                    row.classList.toggle('fm-is-playing', isPlaying);
                    row.classList.toggle('fm-is-paused', !isPlaying);

                    const iconEl = row.querySelector('.fm-song-thumb-play, .fm-song-play-btn span');
                    if (iconEl) {
                        iconEl.textContent = isPlaying ? '⏸' : '▶';
                    }
                } else {
                    row.classList.remove('fm-active-track', 'fm-is-playing', 'fm-is-paused');
                    const iconEl = row.querySelector('.fm-song-thumb-play, .fm-song-play-btn span');
                    if (iconEl) {
                        iconEl.textContent = '▶';
                    }
                }
            });
        }

        resetSongRows() {
            document.querySelectorAll('.fm-song-row, [data-song-id]').forEach(row => {
                row.classList.remove('fm-active-track', 'fm-is-playing', 'fm-is-paused');
                const iconEl = row.querySelector('.fm-song-thumb-play, .fm-song-play-btn span');
                if (iconEl) iconEl.textContent = '▶';
            });
        }

        renderQueue() {
            if (!this.elements.queueList) return;

            const q = this.player.queue;
            const items = q.getItems();
            const curIdx = q.getCurrentIndex();

            if (this.elements.queueCount) {
                this.elements.queueCount.textContent = `${items.length} track${items.length === 1 ? '' : 's'}`;
            }

            if (items.length === 0) {
                this.elements.queueList.innerHTML = '<div class="fm-queue-empty">Queue is empty. Select a track or album to listen.</div>';
                return;
            }

            let html = '';
            items.forEach((item, idx) => {
                const isCurrent = (idx === curIdx);
                const activeClass = isCurrent ? 'fm-queue-item-current' : '';
                const title = this.escapeHtml(item.title || 'Track');
                const artist = this.escapeHtml(item.artist || '');
                const durStr = item.duration ? this.formatTime(item.duration) : '--:--';
                const cover = item.cover || '';

                html += `
                    <div class="fm-queue-item ${activeClass}" data-index="${idx}" data-id="${item.id}">
                        <div class="fm-queue-item-left">
                            <span class="fm-queue-item-idx">${isCurrent ? '▶' : idx + 1}</span>
                            <div class="fm-queue-item-art">
                                ${cover ? `<img src="${this.escapeHtml(cover)}" alt="" loading="lazy">` : '<div class="fm-queue-art-empty">🎵</div>'}
                            </div>
                            <div class="fm-queue-item-meta">
                                <div class="fm-queue-item-title">${title}</div>
                                <div class="fm-queue-item-artist">${artist}</div>
                            </div>
                        </div>
                        <div class="fm-queue-item-right">
                            <span class="fm-queue-item-dur">${durStr}</span>
                            <button type="button" class="fm-queue-item-remove" data-action="remove" aria-label="Remove from queue">✕</button>
                        </div>
                    </div>
                `;
            });

            this.elements.queueList.innerHTML = html;

            // Bind click to jump and remove buttons
            this.elements.queueList.querySelectorAll('.fm-queue-item').forEach(el => {
                el.addEventListener('click', (e) => {
                    const removeBtn = e.target.closest('[data-action="remove"]');
                    const idx = parseInt(el.getAttribute('data-index'), 10);

                    if (removeBtn) {
                        e.stopPropagation();
                        q.remove(idx);
                        this.renderQueue();
                    } else {
                        // Jump to track
                        const target = q.jumpTo(idx);
                        if (target) {
                            this.player.resolveAndPlay(target.id, 0, true);
                        }
                    }
                });
            });
        }

        showMiniPlayer() {
            if (this.elements.miniPlayer) {
                this.elements.miniPlayer.classList.add('fm-mini-visible');
                document.body.classList.add('fm-has-mini-player');
            }
        }

        hideMiniPlayer() {
            if (this.elements.miniPlayer) {
                this.elements.miniPlayer.classList.remove('fm-mini-visible');
                document.body.classList.remove('fm-has-mini-player');
            }
        }

        openExpandedPlayer() {
            if (this.elements.expandedPlayer) {
                this.elements.expandedPlayer.classList.add('fm-expanded-open');
                document.body.style.overflow = 'hidden';
            }
        }

        closeExpandedPlayer() {
            if (this.elements.expandedPlayer) {
                this.elements.expandedPlayer.classList.remove('fm-expanded-open');
                document.body.style.overflow = '';
            }
        }

        openQueueDrawer() {
            if (this.elements.queueDrawer) {
                this.renderQueue();
                this.elements.queueDrawer.classList.add('fm-queue-open');
            }
        }

        closeQueueDrawer() {
            if (this.elements.queueDrawer) {
                this.elements.queueDrawer.classList.remove('fm-queue-open');
            }
        }

        toggleQueueDrawer() {
            if (this.elements.queueDrawer) {
                if (this.elements.queueDrawer.classList.contains('fm-queue-open')) {
                    this.closeQueueDrawer();
                } else {
                    this.openQueueDrawer();
                }
            }
        }

        toggleLyrics() {
            this.expandedLyricsOpen = !this.expandedLyricsOpen;
            if (this.elements.expandedPlayer) {
                this.elements.expandedPlayer.classList.toggle('fm-lyrics-active', this.expandedLyricsOpen);
            }
        }

        showToast(message, type = 'info', actionUrl = null) {
            if (!this.elements.toastContainer) {
                let container = document.getElementById('fm-audio-toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'fm-audio-toast-container';
                    container.className = 'fm-audio-toast-container';
                    document.body.appendChild(container);
                }
                this.elements.toastContainer = container;
            }

            const toast = document.createElement('div');
            toast.className = `fm-audio-toast fm-toast-${type}`;

            let content = `<span>${this.escapeHtml(message)}</span>`;
            if (actionUrl) {
                const label = type === 'auth' ? 'Sign In' : (type === 'premium' ? 'Subscribe' : 'Open');
                content += ` <a href="${this.escapeHtml(actionUrl)}" class="fm-toast-action">${label}</a>`;
            }

            toast.innerHTML = content;
            this.elements.toastContainer.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('fm-toast-fade');
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        formatTime(seconds) {
            const sec = Math.floor(seconds || 0);
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            return `${m}:${s < 10 ? '0' : ''}${s}`;
        }

        escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    }

    // Initialize UI Controller
    window.FavoriteAudioUI = new AudioUIController();
})(window, document);

