<?php
/**
 * Favorite Multimedia — Expanded Audio Player Modal Component.
 * Immersive playback view with high-res artwork, complete metadata, lyrics, and extended queue controls.
 */
?>
<div id="fm-expanded-player" class="fm-expanded-player" role="dialog" aria-modal="true" aria-label="Now Playing Full View">
    <div class="fm-expanded-header">
        <button type="button" id="fm-expanded-player-close" class="fm-expanded-close" aria-label="Close expanded player">
            ▼
        </button>
        <span class="fm-expanded-badge">Now Playing</span>
        <button type="button" id="fm-expanded-queue-btn" class="fm-expanded-close" aria-label="Open Queue" title="Open Queue">
            📑
        </button>
    </div>

    <div class="fm-expanded-body">
        <!-- Artwork -->
        <div class="fm-expanded-art-wrap">
            <img id="fm-expanded-player-art" src="" alt="Album Cover" style="display: none;">
            <div id="fm-expanded-player-art-empty" style="font-size: 64px;">🎵</div>
        </div>

        <!-- Track Info -->
        <div class="fm-expanded-meta">
            <h2 id="fm-expanded-player-title" class="fm-expanded-title">Ready to Play</h2>
            <div id="fm-expanded-player-artist" class="fm-expanded-artist">Favorite Multimedia</div>
            <div id="fm-expanded-player-album" class="fm-expanded-album"></div>
        </div>

        <!-- Scrubber -->
        <div class="fm-expanded-scrubber">
            <span id="fm-expanded-player-current" class="fm-mini-time">0:00</span>
            <input type="range" id="fm-expanded-player-progress" class="fm-slider" min="0" max="100" value="0" step="0.5" aria-label="Seek position">
            <span id="fm-expanded-player-duration" class="fm-mini-time">--:--</span>
        </div>

        <!-- Main Buttons -->
        <div class="fm-expanded-buttons">
            <button type="button" id="fm-expanded-player-shuffle-btn" class="fm-btn-control-toggle" aria-label="Shuffle Queue" title="Shuffle">
                🔀
            </button>
            <button type="button" id="fm-expanded-player-prev-btn" class="fm-btn-icon" style="font-size: 24px;" aria-label="Previous Track">
                ⏮
            </button>
            <button type="button" id="fm-expanded-player-play-btn" class="fm-btn-expanded-play" aria-label="Play / Pause">
                ▶
            </button>
            <button type="button" id="fm-expanded-player-next-btn" class="fm-btn-icon" style="font-size: 24px;" aria-label="Next Track">
                ⏭
            </button>
            <button type="button" id="fm-expanded-player-repeat-btn" class="fm-btn-control-toggle" aria-label="Repeat Mode" title="Repeat Off">
                🔁
            </button>
        </div>

        <!-- Utilities (Volume & Lyrics Toggle) -->
        <div class="fm-expanded-utilities">
            <div class="fm-volume-wrap" style="width: 140px;">
                <span>🔉</span>
                <input type="range" id="fm-expanded-player-volume" class="fm-slider" min="0" max="1" step="0.05" value="1" aria-label="Volume">
            </div>
            <button type="button" id="fm-expanded-lyrics-toggle" class="fm-btn-icon" style="font-size: 14px; width: auto; padding: 0 12px; border-radius: 16px; background: rgba(255,255,255,0.08); display: none;" aria-label="Toggle Lyrics">
                📝 Lyrics
            </button>
        </div>

        <!-- Lyrics Container -->
        <div id="fm-expanded-lyrics-content" class="fm-expanded-lyrics"></div>
    </div>
</div>

