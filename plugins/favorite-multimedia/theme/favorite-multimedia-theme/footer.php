<?php
$siteTitle = $siteTitle ?? \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS');
?>
<!-- Persistent Mini Player Bar -->
<div id="fm-mini-player" class="fm-mini-player" style="display: none;" role="region" aria-label="Audio Playback Bar">
    <div class="fm-mini-track-info">
        <div class="fm-mini-art-wrap">
            <img id="fm-mini-player-art" src="" alt="Album Art" style="display: none;">
            <div id="fm-mini-player-art-fallback">🎵</div>
        </div>
        <div class="fm-mini-meta">
            <div id="fm-mini-player-title" class="fm-mini-title">Select Track</div>
            <div id="fm-mini-player-artist" class="fm-mini-artist">Favorite Multimedia</div>
        </div>
    </div>
    <div class="fm-mini-controls-center">
        <div class="fm-mini-buttons">
            <button type="button" id="fm-mini-player-prev-btn" class="fm-btn-icon" aria-label="Previous Track">⏮</button>
            <button type="button" id="fm-mini-player-play-btn" class="fm-btn-play" aria-label="Play / Pause">▶</button>
            <button type="button" id="fm-mini-player-next-btn" class="fm-btn-icon" aria-label="Next Track">⏭</button>
        </div>
        <div class="fm-mini-scrubber">
            <span id="fm-mini-player-current" class="fm-mini-time">0:00</span>
            <input type="range" id="fm-mini-player-progress" class="fm-slider" min="0" max="100" value="0" step="0.5" aria-label="Seek track position">
            <span id="fm-mini-player-duration" class="fm-mini-time">--:--</span>
        </div>
    </div>
    <div class="fm-mini-actions">
        <div class="fm-volume-wrap">
            <button type="button" id="fm-mini-player-mute-btn" class="fm-btn-icon" aria-label="Mute / Unmute">🔊</button>
            <input type="range" id="fm-mini-player-volume" class="fm-slider fm-mini-volume-slider" min="0" max="1" step="0.05" value="1" aria-label="Volume">
        </div>
        <button type="button" id="fm-mini-player-queue-btn" class="fm-btn-icon" aria-label="Playback Queue" title="Playback Queue">📑</button>
        <button type="button" id="fm-mini-player-close-btn" class="fm-btn-icon" aria-label="Dismiss Player" title="Dismiss Player">✕</button>
    </div>
</div>

<!-- Fullscreen Expanded Audio Player -->
<div id="fm-expanded-player" class="fm-expanded-player" role="dialog" aria-modal="true" aria-label="Now Playing Full View">
    <div class="fm-expanded-header">
        <button type="button" id="fm-expanded-player-close" class="fm-expanded-close" aria-label="Close expanded player">▼</button>
        <span class="fm-expanded-badge">Now Playing</span>
        <button type="button" id="fm-expanded-queue-btn" class="fm-expanded-close" aria-label="Open Queue" title="Open Queue">📑</button>
    </div>
    <div class="fm-expanded-body">
        <div class="fm-expanded-art-wrap">
            <img id="fm-expanded-player-art" src="" alt="Album Cover" style="display: none;">
            <div id="fm-expanded-player-art-empty" style="font-size: 64px;">🎵</div>
        </div>
        <div class="fm-expanded-meta">
            <h2 id="fm-expanded-player-title" class="fm-expanded-title">Ready to Play</h2>
            <div id="fm-expanded-player-artist" class="fm-expanded-artist">Favorite Multimedia</div>
            <div id="fm-expanded-player-album" class="fm-expanded-album"></div>
        </div>
        <div class="fm-expanded-scrubber">
            <span id="fm-expanded-player-current" class="fm-mini-time">0:00</span>
            <input type="range" id="fm-expanded-player-progress" class="fm-slider" min="0" max="100" value="0" step="0.5" aria-label="Seek position">
            <span id="fm-expanded-player-duration" class="fm-mini-time">--:--</span>
        </div>
        <div class="fm-expanded-buttons">
            <button type="button" id="fm-expanded-player-shuffle-btn" class="fm-btn-control-toggle" aria-label="Shuffle Queue" title="Shuffle">🔀</button>
            <button type="button" id="fm-expanded-player-prev-btn" class="fm-btn-icon" style="font-size: 24px;" aria-label="Previous Track">⏮</button>
            <button type="button" id="fm-expanded-player-play-btn" class="fm-btn-expanded-play" aria-label="Play / Pause">▶</button>
            <button type="button" id="fm-expanded-player-next-btn" class="fm-btn-icon" style="font-size: 24px;" aria-label="Next Track">⏭</button>
            <button type="button" id="fm-expanded-player-repeat-btn" class="fm-btn-control-toggle" aria-label="Repeat Mode" title="Repeat Off">🔁</button>
        </div>
    </div>
</div>

<!-- Audio Queue Drawer -->
<div id="fm-audio-queue-drawer" class="fm-audio-queue-drawer" role="complementary" aria-label="Playback Queue">
    <div class="fm-queue-header">
        <div class="fm-queue-header-left">
            <span class="fm-queue-title">Playing Next</span>
            <span id="fm-queue-drawer-count" class="fm-queue-count">0 tracks</span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <button type="button" id="fm-queue-clear-btn" class="fm-btn-icon" style="font-size: 13px; width: auto; padding: 4px 8px; border-radius: 4px;" aria-label="Clear Queue" title="Clear Queue">Clear</button>
            <button type="button" id="fm-queue-drawer-close" class="fm-btn-icon" aria-label="Close Queue">✕</button>
        </div>
    </div>
    <div id="fm-queue-drawer-list" class="fm-queue-list">
        <div class="fm-queue-empty">Queue is empty. Select a track or album to listen.</div>
    </div>
</div>

<!-- Accessible Global Toast System -->
<div id="fm-toast-container" class="fm-toast-container" role="status" aria-live="polite" aria-atomic="true"></div>

<?php
$reqUri = $_SERVER['REQUEST_URI'] ?? '/';
$reqPath = parse_url($reqUri, PHP_URL_PATH) ?? '/';

$activeNav = 'home';
if ($reqPath === '/movies' || str_starts_with($reqPath, '/movie/') || str_starts_with($reqPath, '/movies/') || $reqPath === '/multimedia/movies' || str_starts_with($reqPath, '/multimedia/movies/')) {
    $activeNav = 'movies';
} elseif ($reqPath === '/series' || str_starts_with($reqPath, '/series/') || $reqPath === '/multimedia/series' || str_starts_with($reqPath, '/multimedia/series/')) {
    $activeNav = 'series';
} elseif ($reqPath === '/multimedia/music' || str_starts_with($reqPath, '/multimedia/music/') || str_starts_with($reqPath, '/song/') || str_starts_with($reqPath, '/songs/') || str_starts_with($reqPath, '/album/') || str_starts_with($reqPath, '/albums/') || str_starts_with($reqPath, '/artist/') || str_starts_with($reqPath, '/artists/') || str_starts_with($reqPath, '/playlist/') || str_starts_with($reqPath, '/playlists/')) {
    $activeNav = 'music';
} elseif (str_starts_with($reqPath, '/multimedia/search') || str_starts_with($reqPath, '/search')) {
    $activeNav = 'search';
} elseif (str_starts_with($reqPath, '/multimedia/library') || str_starts_with($reqPath, '/multimedia/my-list') || str_starts_with($reqPath, '/multimedia/history') || str_starts_with($reqPath, '/multimedia/following') || str_starts_with($reqPath, '/multimedia/notifications')) {
    $activeNav = 'library';
} elseif ($reqPath === '/' || $reqPath === '/multimedia' || str_starts_with($reqPath, '/multimedia/hub')) {
    $activeNav = 'home';
}
$navCurrent = $currentNav ?? $activeNav;
?>
<!-- Mobile Bottom Navigation -->
<nav class="fm-mobile-nav" aria-label="Mobile Bottom Navigation">
    <a href="/" class="fm-mobile-nav-item <?php echo $navCurrent === 'home' ? 'active' : ''; ?>" <?php echo $navCurrent === 'home' ? 'aria-current="page"' : ''; ?>>
        <span class="fm-mobile-nav-icon" aria-hidden="true">
            <svg class="fm-mobile-nav-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
        </span>
        <span class="fm-mobile-nav-label">Home</span>
    </a>
    <a href="/movies" class="fm-mobile-nav-item <?php echo $navCurrent === 'movies' ? 'active' : ''; ?>" <?php echo $navCurrent === 'movies' ? 'aria-current="page"' : ''; ?>>
        <span class="fm-mobile-nav-icon" aria-hidden="true">
            <svg class="fm-mobile-nav-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect>
                <line x1="7" y1="2" x2="7" y2="22"></line>
                <line x1="17" y1="2" x2="17" y2="22"></line>
                <line x1="2" y1="12" x2="22" y2="12"></line>
                <line x1="2" y1="7" x2="7" y2="7"></line>
                <line x1="2" y1="17" x2="7" y2="17"></line>
                <line x1="17" y1="17" x2="22" y2="17"></line>
                <line x1="17" y1="7" x2="22" y2="7"></line>
            </svg>
        </span>
        <span class="fm-mobile-nav-label">Movies</span>
    </a>
    <a href="/multimedia/music" class="fm-mobile-nav-item <?php echo $navCurrent === 'music' ? 'active' : ''; ?>" <?php echo $navCurrent === 'music' ? 'aria-current="page"' : ''; ?>>
        <span class="fm-mobile-nav-icon" aria-hidden="true">
            <svg class="fm-mobile-nav-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 18V5l12-2v13"></path>
                <circle cx="6" cy="18" r="3"></circle>
                <circle cx="18" cy="16" r="3"></circle>
            </svg>
        </span>
        <span class="fm-mobile-nav-label">Music</span>
    </a>
    <a href="/multimedia/search" class="fm-mobile-nav-item <?php echo $navCurrent === 'search' ? 'active' : ''; ?>" <?php echo $navCurrent === 'search' ? 'aria-current="page"' : ''; ?>>
        <span class="fm-mobile-nav-icon" aria-hidden="true">
            <svg class="fm-mobile-nav-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </span>
        <span class="fm-mobile-nav-label">Search</span>
    </a>
    <?php if (favorite_multimedia_theme_is_logged_in()): ?>
        <a href="/multimedia/library" class="fm-mobile-nav-item <?php echo $navCurrent === 'library' ? 'active' : ''; ?>" <?php echo $navCurrent === 'library' ? 'aria-current="page"' : ''; ?>>
            <span class="fm-mobile-nav-icon" aria-hidden="true">
                <svg class="fm-mobile-nav-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
            </span>
            <span class="fm-mobile-nav-label">Account</span>
        </a>
    <?php else: ?>
        <a href="/admin/login" class="fm-mobile-nav-item">
            <span class="fm-mobile-nav-icon" aria-hidden="true">
                <svg class="fm-mobile-nav-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                    <polyline points="10 17 15 12 10 7"></polyline>
                    <line x1="15" y1="12" x2="3" y2="12"></line>
                </svg>
            </span>
            <span class="fm-mobile-nav-label">Sign In</span>
        </a>
    <?php endif; ?>
</nav>

<!-- Site Footer -->
<footer style="margin-top: auto; border-top: 1px solid var(--fm-color-border); padding: 32px 24px; text-align: center; color: var(--fm-color-text-muted); font-size: 0.85rem;">
    <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8'); ?>. Powered by Favorite CMS & Favorite Multimedia Theme.</p>
</footer>

<!-- Scripts -->
<script src="/themes/favorite-multimedia-theme/assets/js/audio-player/favorite-audio-state.js?v=1.0.2"></script>
<script src="/themes/favorite-multimedia-theme/assets/js/audio-player/favorite-audio-queue.js?v=1.0.2"></script>
<script src="/themes/favorite-multimedia-theme/assets/js/audio-player/favorite-audio-player.js?v=1.0.2"></script>
<script src="/themes/favorite-multimedia-theme/assets/js/audio-player/favorite-audio-ui.js?v=1.0.2"></script>
<script src="/themes/favorite-multimedia-theme/assets/js/frontend/multimedia-frontend.js?v=1.0.2"></script>
</body>
</html>
