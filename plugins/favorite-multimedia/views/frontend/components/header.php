<?php
/**
 * Global Responsive Frontend Header Component.
 *
 * @var string|null $currentNav Active navigation item ('home', 'movies', 'series', 'music', 'search', 'library')
 */

use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeFunctions = dirname(__DIR__, 3) . '/theme/favorite-multimedia-theme/functions.php';
if (file_exists($themeFunctions) && !function_exists('favorite_multimedia_theme_is_logged_in')) {
    require_once $themeFunctions;
}

$themeManager = ThemeManager::getInstance();
$themeConfig = $themeManager->getActiveConfig();
$headerStyle = (string)$themeConfig->get('header', 'style', 'glass');
$isSticky = (bool)$themeConfig->get('header', 'sticky', true);
$brandTitle = (string)$themeConfig->get('branding', 'brand_title', 'Favorite Multimedia');
$logoUrl = (string)$themeConfig->get('branding', 'logo_url', '');

$isLoggedIn = function_exists('favorite_multimedia_theme_is_logged_in') ? favorite_multimedia_theme_is_logged_in() : (function_exists('current_user') && current_user() !== null);
$currentUser = $isLoggedIn ? (function_exists('favorite_multimedia_theme_current_user') ? favorite_multimedia_theme_current_user() : current_user()) : null;
$userDisplayName = $isLoggedIn ? (function_exists('favorite_multimedia_theme_user_display_name') ? favorite_multimedia_theme_user_display_name($currentUser) : (string)($currentUser->name ?? $currentUser->username ?? 'User')) : '';
$userInitials = $isLoggedIn ? (function_exists('favorite_multimedia_theme_user_initials') ? favorite_multimedia_theme_user_initials($currentUser) : strtoupper(substr($userDisplayName ?: 'U', 0, 2))) : '';
$canAccessAdmin = $isLoggedIn ? (function_exists('favorite_multimedia_theme_can_access_admin') ? favorite_multimedia_theme_can_access_admin($currentUser) : (method_exists($currentUser, 'hasRole') && $currentUser->hasRole('admin'))) : false;
$canCreatePosts = $isLoggedIn ? (function_exists('favorite_multimedia_theme_can_create_posts') ? favorite_multimedia_theme_can_create_posts($currentUser) : ($currentUser && method_exists($currentUser, 'hasRole') && ($currentUser->hasRole('admin') || $currentUser->hasRole('super-admin') || $currentUser->hasRole('editor') || $currentUser->hasRole('author')))) : false;
$canSubmitMedia = false;
$canModerateMedia = false;
if ($isLoggedIn && function_exists('favorite_multimedia_theme_can_submit_media')) {
    $canSubmitMedia = favorite_multimedia_theme_can_submit_media($currentUser);
} elseif ($isLoggedIn && class_exists(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::class)) {
    $canSubmitMedia = \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::canUserSubmit(null, $currentUser);
}
if ($isLoggedIn && class_exists(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::class)) {
    $canModerateMedia = \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::can(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::MODERATE, $currentUser);
}
$digitalAvailable = \FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter::isAvailable();
$membershipUrl = $digitalAvailable ? \FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter::getSubscriptionUrl() : '';
$userEmail = $currentUser->email ?? ($_SESSION['auth_user_email'] ?? '');


// Active route resolution for navigation
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
<header class="fm-header fm-header-<?php echo htmlspecialchars($headerStyle, ENT_QUOTES, 'UTF-8'); ?> <?php echo $isSticky ? 'fm-header-sticky' : ''; ?>" style="z-index: 1000;">
    <div class="fm-header-inner" style="display: flex; align-items: center; justify-content: space-between; width: 100%; max-width: var(--fm-layout-max-width, 1440px); margin: 0 auto; padding: 0 24px; height: 68px; box-sizing: border-box;">
        <div style="display: flex; align-items: center; gap: 32px;">
            <!-- Brand Logo -->
            <a href="/multimedia" class="fm-brand-link" aria-label="<?php echo htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8'); ?>" class="fm-brand-logo" style="height: var(--fm-header-logo-height, 32px);">
                <?php else: ?>
                    <svg class="fm-brand-icon-svg" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="color: var(--fm-color-primary, #e50914);">
                        <rect x="2" y="2" width="20" height="20" rx="4" ry="4"></rect>
                        <polygon points="10 8 16 12 10 16 10 8" fill="var(--fm-color-primary, #e50914)"></polygon>
                    </svg>
                    <span class="fm-brand-text"><?php echo htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </a>

            <!-- Desktop Primary Nav -->
            <nav class="fm-desktop-nav" aria-label="Primary Navigation" style="display: flex; gap: 20px; font-weight: 600; font-size: 0.95rem;">
                <a href="/multimedia" class="fm-nav-link <?php echo $navCurrent === 'home' ? 'active' : ''; ?>" <?php echo $navCurrent === 'home' ? 'aria-current="page"' : ''; ?>>Home</a>
                <a href="/movies" class="fm-nav-link <?php echo $navCurrent === 'movies' ? 'active' : ''; ?>" <?php echo $navCurrent === 'movies' ? 'aria-current="page"' : ''; ?>>Movies</a>
                <a href="/series" class="fm-nav-link <?php echo $navCurrent === 'series' ? 'active' : ''; ?>" <?php echo $navCurrent === 'series' ? 'aria-current="page"' : ''; ?>>Series</a>
                <a href="/multimedia/music" class="fm-nav-link <?php echo $navCurrent === 'music' ? 'active' : ''; ?>" <?php echo $navCurrent === 'music' ? 'aria-current="page"' : ''; ?>>Music</a>
                <a href="/multimedia/search" class="fm-nav-link <?php echo $navCurrent === 'search' ? 'active' : ''; ?>" <?php echo $navCurrent === 'search' ? 'aria-current="page"' : ''; ?>>Search</a>
                <a href="/multimedia/library" class="fm-nav-link <?php echo $navCurrent === 'library' ? 'active' : ''; ?>" <?php echo $navCurrent === 'library' ? 'aria-current="page"' : ''; ?>>Library</a>
            </nav>
        </div>

        <!-- Header Actions -->
        <div class="fm-header-actions" style="display: flex; align-items: center; gap: 16px;">
            <!-- Expanding Search Pill -->
            <form action="/multimedia/search" method="GET" class="fm-header-search" id="fm-header-search" role="search">
                <div class="fm-search-pill" id="fm-search-pill">
                    <button type="button" class="fm-search-toggle" id="fm-search-toggle" aria-label="Open search input" aria-expanded="false">
                        <svg class="fm-icon fm-search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </button>
                    <input type="search" 
                           name="q" 
                           id="fm-header-search-input" 
                           class="fm-search-input" 
                           placeholder="Search movies, series, songs..." 
                           value="<?php echo htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                           aria-label="Search content" 
                           autocomplete="off">
                    <button type="submit" class="fm-search-submit" id="fm-search-submit" aria-label="Submit search">
                        <svg class="fm-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </button>
                </div>
            </form>

            <?php if ($isLoggedIn): ?>
                <div class="fm-profile-menu-container" style="position: relative;">
                    <button type="button" 
                            id="fm-profile-btn" 
                            class="fm-profile-btn" 
                            data-fm-profile-toggle="true"
                            aria-haspopup="true" 
                            aria-expanded="false" 
                            aria-controls="fm-profile-dropdown"
                            aria-label="User Account Menu"
                            style="display: inline-flex; align-items: center; gap: 8px; background: var(--fm-color-bg-surface, #121824); border: 1px solid var(--fm-color-border, #2A3548); padding: 4px 12px 4px 6px; border-radius: 24px; cursor: pointer; color: var(--fm-color-text-primary, #ffffff); font-family: inherit; font-size: 0.88rem; font-weight: 600;">
                        <span class="fm-user-avatar" style="width: 28px; height: 28px; border-radius: 50%; background: var(--fm-color-primary, #e50914); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; flex-shrink: 0;">
                            <?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="fm-user-name" style="max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo htmlspecialchars($userDisplayName, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <svg class="fm-dropdown-chevron" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="opacity: 0.7;">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>

                    <div id="fm-profile-dropdown" 
                         class="fm-profile-dropdown" 
                         data-fm-profile-menu="true"
                         role="menu" 
                         aria-labelledby="fm-profile-btn" 
                         hidden
                         style="position: absolute; right: 0; top: calc(100% + 8px); width: 220px; background: var(--fm-color-bg-elevated, #1c2436); border: 1px solid var(--fm-color-border, #2A3548); border-radius: var(--fm-radius-card, 12px); box-shadow: 0 10px 25px rgba(0,0,0,0.5); padding: 8px 0; z-index: 1050;">
                        <div class="fm-profile-header" style="padding: 8px 16px; border-bottom: 1px solid var(--fm-color-border, #2A3548); margin-bottom: 4px;">
                            <div class="fm-profile-name" style="font-weight: 700; font-size: 0.9rem; color: var(--fm-color-text-primary, #fff);"><?php echo htmlspecialchars($userDisplayName, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if (!empty($userEmail)): ?>
                                <div class="fm-profile-email" style="font-size: 0.75rem; color: var(--fm-color-text-muted, #64748B); text-overflow: ellipsis; overflow: hidden;"><?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>
                        <!-- Account Group -->
                        <a href="/admin/users/profile" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                            <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <span>My Profile</span>
                        </a>
                        <?php if ($digitalAvailable && !empty($membershipUrl)): ?>
                            <a href="<?php echo htmlspecialchars($membershipUrl, ENT_QUOTES, 'UTF-8'); ?>" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-accent, #ffb800); font-size: 0.88rem; text-decoration: none;">
                                <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                                </svg>
                                <span>Membership</span>
                            </a>
                        <?php endif; ?>

                        <!-- Media Group -->
                        <div class="fm-profile-divider" style="height: 1px; background: var(--fm-color-border, #2A3548); margin: 6px 0;"></div>
                        <a href="/multimedia/library" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                            <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                            <span>My Library</span>
                        </a>
                        <a href="/multimedia/my-list" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                            <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path>
                            </svg>
                            <span>My List</span>
                        </a>
                        <a href="/multimedia/history" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                            <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            <span>History</span>
                        </a>
                        <a href="/multimedia/notifications" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                            <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <span>Notifications</span>
                        </a>
                        <a href="/multimedia/following" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                            <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <span>Following</span>
                        </a>

                        <!-- Content Group -->
                        <?php if ($canCreatePosts): ?>
                            <div class="fm-profile-divider" style="height: 1px; background: var(--fm-color-border, #2A3548); margin: 6px 0;"></div>
                            <a href="/admin/posts" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                                <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                <span>My Posts</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($canSubmitMedia): ?>
                            <a href="/admin/page/multimedia" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                                <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="2" y="2" width="20" height="20" rx="4" ry="4"></rect>
                                    <polygon points="10 8 16 12 10 16 10 8" fill="currentColor"></polygon>
                                </svg>
                                <span>My Multimedia</span>
                            </a>
                            <a href="/admin/page/multimedia-my-submissions" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                                <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect>
                                    <line x1="7" y1="2" x2="7" y2="22"></line>
                                    <line x1="17" y1="2" x2="17" y2="22"></line>
                                    <line x1="2" y1="12" x2="22" y2="12"></line>
                                    <line x1="2" y1="7" x2="7" y2="7"></line>
                                    <line x1="2" y1="17" x2="7" y2="17"></line>
                                    <line x1="17" y1="17" x2="22" y2="17"></line>
                                    <line x1="17" y1="7" x2="22" y2="7"></line>
                                </svg>
                                <span>My Submissions</span>
                            </a>
                            <a href="/admin/page/multimedia-movies?new=1" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                                <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="8" x2="12" y2="16"></line>
                                    <line x1="8" y1="12" x2="16" y2="12"></line>
                                </svg>
                                <span>Submit Media</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($canModerateMedia): ?>
                            <a href="/admin/page/multimedia-moderation?tab=pending_content" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-accent, #3b82f6); font-size: 0.88rem; text-decoration: none;">
                                <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                </svg>
                                <span>Pending Reviews</span>
                            </a>
                        <?php endif; ?>

                        <!-- Admin Group -->
                        <?php if ($canAccessAdmin): ?>
                            <div class="fm-profile-divider" style="height: 1px; background: var(--fm-color-border, #2A3548); margin: 6px 0;"></div>
                            <a href="/admin" role="menuitem" class="fm-profile-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: var(--fm-color-text-secondary, #94a3b8); font-size: 0.88rem; text-decoration: none;">
                                <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                                <span>Dashboard</span>
                            </a>
                        <?php endif; ?>

                        <!-- Session Group -->
                        <div class="fm-profile-divider" style="height: 1px; background: var(--fm-color-border, #2A3548); margin: 6px 0;"></div>
                        <a href="/admin/logout" role="menuitem" class="fm-profile-item fm-profile-logout" style="display: flex; align-items: center; gap: 10px; padding: 8px 16px; color: #ef4444; font-size: 0.88rem; text-decoration: none;">
                            <svg class="fm-menu-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" y1="12" x2="9" y2="12"></line>
                            </svg>
                            <span>Log Out</span>
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="/admin/login" class="fm-btn-signin" style="font-size: 0.85rem; font-weight: 600; background: var(--fm-color-primary, #e50914); color: #fff; padding: 6px 16px; border-radius: 20px; text-decoration: none;">Sign In</a>
            <?php endif; ?>
        </div>
    </div>
</header>
