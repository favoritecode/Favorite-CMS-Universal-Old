<?php
/**
 * Mobile Bottom Navigation Bar Component.
 *
 * Provides comfortable touch destinations respecting env(safe-area-inset-bottom).
 *
 * @var string|null $currentNav
 */

$user = current_user();

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
<!-- Persistent Audio Player System -->
<?php include __DIR__ . '/audio-player.php'; ?>

<!-- Accessible Global Toast System -->
<?php include __DIR__ . '/toast.php'; ?>

<nav class="fm-mobile-nav" aria-label="Mobile Bottom Navigation">
    <a href="/multimedia" class="fm-mobile-nav-item <?php echo $navCurrent === 'home' ? 'active' : ''; ?>" <?php echo $navCurrent === 'home' ? 'aria-current="page"' : ''; ?>>
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
    <a href="<?php echo $user ? '/multimedia/library' : '/admin/login'; ?>" class="fm-mobile-nav-item <?php echo $navCurrent === 'library' ? 'active' : ''; ?>" <?php echo $navCurrent === 'library' ? 'aria-current="page"' : ''; ?>>
        <span class="fm-mobile-nav-icon" aria-hidden="true">
            <?php if ($user): ?>
                <svg class="fm-mobile-nav-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                </svg>
            <?php else: ?>
                <svg class="fm-mobile-nav-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                    <polyline points="10 17 15 12 10 7"></polyline>
                    <line x1="15" y1="12" x2="3" y2="12"></line>
                </svg>
            <?php endif; ?>
        </span>
        <span class="fm-mobile-nav-label"><?php echo $user ? 'Library' : 'Sign In'; ?></span>
    </a>
</nav>
