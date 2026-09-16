<?php
/**
 * Favorite Multimedia — Persistent Mini Audio Player Component.
 * Fixed bottom bar with track info, scrubber, playback controls, and drawer toggles.
 */
?>
<div id="fm-mini-player" class="fm-mini-player" role="region" aria-label="Audio Player">
    <!-- Left: Track Art & Title -->
    <div class="fm-mini-track-info" id="fm-mini-player-expand-btn" role="button" tabindex="0" aria-label="Expand player view">
        <div class="fm-mini-art-wrap">
            <img id="fm-mini-player-art" src="" alt="Album Artwork" style="display: none;">
            <div id="fm-mini-player-art-empty" class="fm-mini-art-empty">🎵</div>
        </div>
        <div class="fm-mini-meta">
            <div id="fm-mini-player-title" class="fm-mini-title">Ready to Play</div>
            <div id="fm-mini-player-artist" class="fm-mini-artist">Select a track to listen</div>
        </div>
    </div>

    <!-- Center: Playback Controls & Progress Scrubber -->
    <div class="fm-mini-controls-wrap">
        <div class="fm-mini-buttons">
            <button type="button" id="fm-mini-player-prev-btn" class="fm-btn-icon" aria-label="Previous Track">
                ⏮
            </button>
            <button type="button" id="fm-mini-player-play-btn" class="fm-btn-primary-play" aria-label="Play">
                ▶
            </button>
            <button type="button" id="fm-mini-player-next-btn" class="fm-btn-icon" aria-label="Next Track">
                ⏭
            </button>
        </div>
        <div class="fm-mini-scrubber">
            <span id="fm-mini-player-current" class="fm-mini-time">0:00</span>
            <input type="range" id="fm-mini-player-progress" class="fm-slider" min="0" max="100" value="0" step="0.5" aria-label="Seek track position">
            <span id="fm-mini-player-duration" class="fm-mini-time">--:--</span>
        </div>
    </div>

    <!-- Right: Volume, Queue & Dismiss -->
    <div class="fm-mini-actions">
        <div class="fm-volume-wrap">
            <button type="button" id="fm-mini-player-mute-btn" class="fm-btn-icon" aria-label="Mute / Unmute">
                🔊
            </button>
            <input type="range" id="fm-mini-player-volume" class="fm-slider fm-mini-volume-slider" min="0" max="1" step="0.05" value="1" aria-label="Volume">
        </div>
        <button type="button" id="fm-mini-player-queue-btn" class="fm-btn-icon" aria-label="Playback Queue" title="Playback Queue">
            📑
        </button>
        <button type="button" id="fm-mini-player-close-btn" class="fm-btn-icon" aria-label="Dismiss Player" title="Dismiss Player">
            ✕
        </button>
    </div>
</div>

