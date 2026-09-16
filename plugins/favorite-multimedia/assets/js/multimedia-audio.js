/**
 * Favorite Multimedia — Audio Player & Playlist Controller
 */

document.addEventListener('DOMContentLoaded', function () {
    const audioBoxes = document.querySelectorAll('.fav-audio-player-box');
    audioBoxes.forEach(initFavoriteAudioPlayer);
});

function initFavoriteAudioPlayer(box) {
    const audio = box.querySelector('audio');
    if (!audio) return;

    const playBtn = box.querySelector('.fav-audio-btn-play');
    const prevBtn = box.querySelector('.fav-audio-btn-prev');
    const nextBtn = box.querySelector('.fav-audio-btn-next');
    const shuffleBtn = box.querySelector('.fav-audio-btn-shuffle');
    const loopBtn = box.querySelector('.fav-audio-btn-loop');
    const statusNotice = box.querySelector('.fav-audio-status-notice');

    const progressContainer = box.querySelector('.fav-progress-bar-container');
    const progressBar = box.querySelector('.fav-progress-filled');
    const timeDisplay = box.querySelector('.fav-time-display');
    const volumeSlider = box.querySelector('.fav-volume-slider');

    const titleEl = box.querySelector('.fav-audio-title');
    const artistEl = box.querySelector('.fav-audio-artist');
    const artworkEl = box.querySelector('.fav-audio-artwork');
    const downloadLink = box.querySelector('.fav-audio-download-link');

    const playlistRows = box.querySelectorAll('.fav-playlist-row');
    let currentIndex = 0;
    let isShuffle = false;
    let isLoop = false;

    // Toggle Shuffle
    if (shuffleBtn) {
        shuffleBtn.addEventListener('click', () => {
            isShuffle = !isShuffle;
            shuffleBtn.style.opacity = isShuffle ? '1' : '0.6';
            shuffleBtn.style.color = isShuffle ? '#38bdf8' : '';
        });
    }

    // Toggle Loop
    if (loopBtn) {
        loopBtn.addEventListener('click', () => {
            isLoop = !isLoop;
            loopBtn.style.opacity = isLoop ? '1' : '0.6';
            loopBtn.style.color = isLoop ? '#38bdf8' : '';
        });
    }

    // Load initial track (find first accessible track if available)
    if (playlistRows.length > 0) {
        let firstIndex = 0;
        for (let i = 0; i < playlistRows.length; i++) {
            if (playlistRows[i].getAttribute('data-stream-url') && playlistRows[i].getAttribute('data-video-only') !== '1') {
                firstIndex = i;
                break;
            }
        }
        loadTrack(firstIndex, false);
    }

    // Play / Pause
    if (playBtn) {
        playBtn.addEventListener('click', () => {
            const row = playlistRows[currentIndex];
            const access = row ? (row.getAttribute('data-access') || 'ALLOW') : 'ALLOW';
            const streamUrl = row ? (row.getAttribute('data-stream-url') || '') : '';
            const isVideoOnly = row ? (row.getAttribute('data-video-only') === '1') : false;

            if (isVideoOnly) {
                showNotice('🎬 This track is a Music Video. Open the song page to watch.');
                return;
            }

            if (access !== 'ALLOW' || !streamUrl) {
                showNotice('🔒 Premium Membership required to play this track.');
                return;
            }

            if (audio.paused) {
                audio.play().catch(() => {});
                playBtn.innerHTML = '&#10074;&#10074;';
            } else {
                audio.pause();
                playBtn.innerHTML = '&#9654;';
            }
        });
    }

    audio.addEventListener('play', () => {
        if (playBtn) playBtn.innerHTML = '&#10074;&#10074;';
        window.dispatchEvent(new CustomEvent('fm:audio:play'));
    });

    window.addEventListener('fm:video:play', () => {
        if (!audio.paused) {
            audio.pause();
            if (playBtn) playBtn.innerHTML = '&#9654;';
        }
    });

    audio.addEventListener('pause', () => {
        if (playBtn) playBtn.innerHTML = '&#9654;';
    });

    function isRowPlayable(idx) {
        const row = playlistRows[idx];
        if (!row) return false;
        const access = row.getAttribute('data-access') || 'ALLOW';
        const streamUrl = row.getAttribute('data-stream-url') || '';
        const isVideoOnly = row.getAttribute('data-video-only') === '1';
        return !isVideoOnly && access === 'ALLOW' && streamUrl.length > 0;
    }

    function getNextTrackIndex(startIdx, loop = false) {
        const total = playlistRows.length;
        if (total === 0) return -1;

        if (isShuffle && total > 1) {
            const playableIndices = [];
            for (let i = 0; i < total; i++) {
                if (i !== currentIndex && isRowPlayable(i)) {
                    playableIndices.push(i);
                }
            }
            if (playableIndices.length > 0) {
                return playableIndices[Math.floor(Math.random() * playableIndices.length)];
            }
        }

        // Sequential forward search
        for (let i = startIdx + 1; i < total; i++) {
            if (isRowPlayable(i)) return i;
        }

        if (loop) {
            for (let i = 0; i <= startIdx; i++) {
                if (isRowPlayable(i)) return i;
            }
        }

        return -1;
    }

    // Auto next track
    audio.addEventListener('ended', () => {
        reportSongProgress(true);

        if (isLoop) {
            audio.currentTime = 0;
            audio.play().catch(() => {});
            return;
        }

        const nextIdx = getNextTrackIndex(currentIndex, false);
        if (nextIdx !== -1) {
            loadTrack(nextIdx, true);
        } else {
            // Clean playlist completion
            showNotice('🎉 Playlist completed.');
            if (playBtn) playBtn.innerHTML = '&#9654;';
        }
    });

    // Next / Prev
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            const nextIdx = getNextTrackIndex(currentIndex, true);
            if (nextIdx !== -1) {
                loadTrack(nextIdx, true);
            } else if (playlistRows.length > 0) {
                const fallback = (currentIndex < playlistRows.length - 1) ? currentIndex + 1 : 0;
                loadTrack(fallback, true);
            }
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            let prevIdx = -1;
            for (let i = currentIndex - 1; i >= 0; i--) {
                if (isRowPlayable(i)) { prevIdx = i; break; }
            }
            if (prevIdx === -1) {
                for (let i = playlistRows.length - 1; i > currentIndex; i--) {
                    if (isRowPlayable(i)) { prevIdx = i; break; }
                }
            }
            if (prevIdx !== -1) {
                loadTrack(prevIdx, true);
            } else if (playlistRows.length > 0) {
                const fallback = (currentIndex > 0) ? currentIndex - 1 : playlistRows.length - 1;
                loadTrack(fallback, true);
            }
        });
    }

    // Playlist row click
    playlistRows.forEach((row, idx) => {
        row.addEventListener('click', () => {
            loadTrack(idx, true);
        });
    });

    function showNotice(msg) {
        if (statusNotice) {
            statusNotice.textContent = msg;
            statusNotice.style.display = 'block';
        }
    }

    function hideNotice() {
        if (statusNotice) {
            statusNotice.style.display = 'none';
        }
    }

    function loadTrack(index, autoPlay = true) {
        if (index < 0 || index >= playlistRows.length) return;
        currentIndex = index;

        playlistRows.forEach(r => r.classList.remove('fav-active-track'));
        const row = playlistRows[index];
        row.classList.add('fav-active-track');

        const streamUrl = row.getAttribute('data-stream-url') || '';
        const title = row.getAttribute('data-title') || '';
        const artist = row.getAttribute('data-artist') || '';
        const cover = row.getAttribute('data-cover') || '';
        const downloadUrl = row.getAttribute('data-download-url') || '';
        const canDownload = row.getAttribute('data-can-download') === '1';
        const access = row.getAttribute('data-access') || 'ALLOW';
        const isVideoOnly = row.getAttribute('data-video-only') === '1';

        if (titleEl) titleEl.textContent = title;
        if (artistEl) artistEl.textContent = artist;
        if (artworkEl && cover) artworkEl.src = cover;

        if (downloadLink) {
            if (canDownload && downloadUrl && !isVideoOnly) {
                downloadLink.style.display = 'inline-flex';
                downloadLink.href = downloadUrl;
            } else {
                downloadLink.style.display = 'none';
            }
        }

        if (isVideoOnly) {
            showNotice('🎬 "' + title + '" is a Music Video. Open song page to watch.');
            audio.pause();
            audio.src = '';
            if (playBtn) playBtn.innerHTML = '&#9654;';
            if (progressBar) progressBar.style.width = '0%';
            if (timeDisplay) timeDisplay.textContent = '0:00 / 0:00';
            if (autoPlay) {
                setTimeout(() => {
                    if (currentIndex < playlistRows.length - 1) {
                        loadTrack(currentIndex + 1, true);
                    } else {
                        loadTrack(0, false);
                    }
                }, 1200);
            }
            return;
        }

        if (access !== 'ALLOW' || !streamUrl) {
            showNotice('🔒 Premium Membership required to stream this track.');
            audio.pause();
            audio.src = '';
            if (playBtn) playBtn.innerHTML = '&#9654;';
            if (progressBar) progressBar.style.width = '0%';
            if (timeDisplay) timeDisplay.textContent = '0:00 / 0:00';
            return;
        }

        hideNotice();
        audio.src = streamUrl;
        if (autoPlay) {
            audio.play().catch(() => {});
        }
    }

    // Progress & Time
    audio.addEventListener('timeupdate', () => {
        if (audio.duration) {
            const percent = (audio.currentTime / audio.duration) * 100;
            if (progressBar) progressBar.style.width = percent + '%';
            if (timeDisplay) {
                const cur = formatTime(audio.currentTime);
                const dur = formatTime(audio.duration);
                timeDisplay.textContent = `${cur} / ${dur}`;
            }
        }
    });

    if (progressContainer) {
        progressContainer.addEventListener('click', (e) => {
            const rect = progressContainer.getBoundingClientRect();
            const pos = (e.clientX - rect.left) / rect.width;
            if (audio.duration) {
                audio.currentTime = pos * audio.duration;
            }
        });
    }

    // Volume
    if (window.FavoriteMediaVolume) {
        const uVol = window.FavoriteMediaVolume.get();
        audio.volume = uVol;
        if (volumeSlider) volumeSlider.value = uVol;
    }

    if (volumeSlider) {
        volumeSlider.addEventListener('input', (e) => {
            const vol = parseFloat(e.target.value);
            audio.volume = vol;
            if (window.FavoriteMediaVolume) {
                window.FavoriteMediaVolume.set(vol);
            }
        });
    }

    window.addEventListener('fm:volumechange', (e) => {
        if (e.detail && typeof e.detail.volume === 'number') {
            const newVol = e.detail.volume;
            if (Math.abs(audio.volume - newVol) > 0.01) {
                audio.volume = newVol;
                if (volumeSlider) volumeSlider.value = newVol;
            }
        }
    });

    window.addEventListener('storage', (e) => {
        if (e.key === 'fm_media_volume' && e.newValue !== null) {
            const v = parseFloat(e.newValue);
            if (!isNaN(v) && v >= 0 && v <= 1 && Math.abs(audio.volume - v) > 0.01) {
                audio.volume = v;
                if (volumeSlider) volumeSlider.value = v;
            }
        }
    });

    function formatTime(sec) {
        sec = Math.floor(sec || 0);
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return `${m}:${s < 10 ? '0' : ''}${s}`;
    }

    // Song Playback Progress Reporting (Phase 5)
    let lastSongPing = 0;
    function reportSongProgress(isCompleted = false) {
        const row = playlistRows[currentIndex];
        if (!row) return;
        const songId = parseInt(row.getAttribute('data-song-id'), 10);
        if (!songId || isNaN(songId)) return;

        const currentPos = audio.currentTime || 0;
        const totalDuration = audio.duration || 0;

        fetch('/multimedia/api/progress', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                content_type: 'song',
                content_id: songId,
                position: currentPos,
                duration: totalDuration,
                is_completed: isCompleted ? 1 : 0
            })
        }).catch(() => {});
    }

    audio.addEventListener('timeupdate', () => {
        const now = audio.currentTime;
        if (Math.abs(now - lastSongPing) >= 15) {
            lastSongPing = now;
            reportSongProgress(false);
        }
    });

    audio.addEventListener('pause', () => {
        if (!audio.ended) {
            reportSongProgress(false);
        }
    });

    audio.addEventListener('ended', () => {
        reportSongProgress(true);
    });
}
