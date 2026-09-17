<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia;

use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\Navigation\MultimediaSidebarTitle;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Theme\ThemeManager;

final class FavoriteMultimediaPlugin
{
    public const VERSION = '1.0.8';

    public const TABLES = [
        'multimedia_genres',
        'multimedia_artists',
        'multimedia_albums',
        'multimedia_movies',
        'multimedia_series',
        'multimedia_seasons',
        'multimedia_episodes',
        'multimedia_songs',
        'multimedia_playlists',
        'multimedia_playlist_items',
        'multimedia_content_genres',
        'multimedia_sources',
        'multimedia_subtitles',
        'multimedia_analytics',
        'multimedia_playback_progress',
        'multimedia_favorites',
        'multimedia_ratings',
        'multimedia_reviews',
        'multimedia_review_helpful',
        'multimedia_comments',
        'multimedia_reports',
        'multimedia_subscriptions',
        'multimedia_notifications',
        'multimedia_notification_preferences',
        'multimedia_processing_jobs',
        'multimedia_storage_files',
        'multimedia_localizations',
        'multimedia_user_language_preferences',
        'multimedia_download_sources',
    ];

    private static ?self $instance = null;
    private static bool $routesRegistered = false;
    private static bool $upgradeChecked = false;
    private static array $masterSubmenus = [];
    private Application $app;
    private bool $booted = false;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public static function getInstance(?Application $app = null): ?self
    {
        if (self::$instance === null && $app !== null) {
            self::$instance = new self($app);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
        self::$routesRegistered = false;
        self::$upgradeChecked = false;
        self::$masterSubmenus = [];
    }

    public static function bootstrap(Application $app): self
    {
        if (self::$instance !== null && self::$instance->booted) {
            return self::$instance;
        }

        $plugin = new self($app);
        $plugin->register();
        $plugin->boot();
        self::$instance = $plugin;
        return $plugin;
    }

    public function register(): void
    {
        // 1. Register prefixable tables with Database
        if ($this->app->has(Database::class)) {
            $db = $this->app->make(Database::class);
            if (method_exists($db, 'registerPrefixableTables')) {
                $db->registerPrefixableTables(self::TABLES);
            }
        }

        // 2. Bind Controllers
        $this->app->singleton(MultimediaAdminController::class, function ($app) {
            return new MultimediaAdminController($app);
        });

        $this->app->singleton(MultimediaFrontendController::class, function ($app) {
            return new MultimediaFrontendController($app);
        });

        $this->app->singleton(MediaPlaybackController::class, function ($app) {
            return new MediaPlaybackController($app);
        });

        $this->app->singleton(ThemeManager::class, function ($app) {
            return ThemeManager::getInstance($app);
        });
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Register Admin Navigation
        $this->registerAdminMenus();

        // Register Dynamic Frontend and Player Routes
        $this->registerRoutes();

        // Hook lifecycle actions
        if (function_exists('add_action')) {
            add_action('plugin.activated', function (string $pluginId): void {
                if ($pluginId === 'favorite-multimedia') {
                    $this->onActivate();
                }
            });

            add_action('plugin.deactivated', function (string $pluginId): void {
                if ($pluginId === 'favorite-multimedia') {
                    $this->onDeactivate();
                }
            });

            add_action('multimedia_process_due_releases', function (int $limit = 100): array {
                return \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::processDueReleases($limit);
            });

            add_action('multimedia_process_media_queue', function (int $limit = 5): array {
                return \FavoriteCMS\Multimedia\Services\MediaProcessingService::processQueue($limit);
            });

            add_action('frontend_head', function (): void {
                echo ThemeManager::getInstance()->renderHeadTokens();
            });
        }

        if (class_exists(\FavoriteCMS\Core\Hook::class)) {
            \FavoriteCMS\Core\Hook::addAction('plugin.activated', function (string $pluginId): void {
                if ($pluginId === 'favorite-multimedia') {
                    $this->onActivate();
                }
            });

            \FavoriteCMS\Core\Hook::addAction('plugin.deactivated', function (string $pluginId): void {
                if ($pluginId === 'favorite-multimedia') {
                    $this->onDeactivate();
                }
            });

            \FavoriteCMS\Core\Hook::addAction('multimedia_process_due_releases', function (int $limit = 100): array {
                return \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::processDueReleases($limit);
            });

            \FavoriteCMS\Core\Hook::addAction('multimedia_process_media_queue', function (int $limit = 5): array {
                return \FavoriteCMS\Multimedia\Services\MediaProcessingService::processQueue($limit);
            });

            \FavoriteCMS\Core\Hook::addAction('frontend_head', function (): void {
                echo ThemeManager::getInstance()->renderHeadTokens();
            });
        }

        // Ensure Multimedia Theme path is NEVER registered globally on Engine boot.
        // Active theme resolution is strictly isolated to prevent cross-theme template pollution.
        \FavoriteCMS\Multimedia\Theme\ThemeShellService::purgeGlobalTemplatePathPollution();

        \FavoriteCMS\Multimedia\Theme\ThemeShellService::wrapHumanFacingRoutes();
        if (function_exists('add_action')) {
            add_action('init', static function (): void {
                \FavoriteCMS\Multimedia\Theme\ThemeShellService::wrapHumanFacingRoutes();
            }, 20);
        }

        // Register default permissions if DB is ready
        if ($this->app->has(Database::class)) {
            try {
                MultimediaPermission::registerDefaultPermissions($this->app->make(Database::class));
            } catch (\Throwable) {
                // Table might not be migrated yet
            }
        }

        // Run idempotent upgrade / missing-setting self-heal if needed
        $this->ensureUpgrade();

        $this->booted = true;
    }

    public function onActivate(): void
    {
        try {
            $this->runMigrations();
        } catch (\Throwable $e) {
            if (function_exists('cms_log')) {
                cms_log("Favorite Multimedia migration failed: " . $e->getMessage(), 'error', ['plugin' => 'favorite-multimedia']);
            }
        }

        if ($this->app->has(Database::class)) {
            try {
                MultimediaPermission::registerDefaultPermissions($this->app->make(Database::class));
            } catch (\Throwable) {
            }
        }

        self::ensureDefaultSettings();

        try {
            \FavoriteCMS\Models\Setting::set('multimedia', 'installed_version', self::VERSION);
            \FavoriteCMS\Models\Setting::set('multimedia', 'version', self::VERSION);
        } catch (\Throwable) {}
        self::$upgradeChecked = true;

        if (function_exists('cms_log')) {
            cms_log('Favorite Multimedia plugin activated.', 'info', ['plugin' => 'favorite-multimedia']);
        }
    }

    /**
     * Canonical, non-destructive settings bootstrapper.
     * Ensures all required multimedia settings exist without overwriting
     * any existing administrator-configured values or custom trusted domains.
     */
    public static function ensureDefaultSettings(): void
    {
        if (!class_exists(\FavoriteCMS\Models\Setting::class)) {
            return;
        }

        $defaults = [
            'enable_downloads'              => 'yes',
            'default_video_resolution'      => '1080p',
            'player_theme_color'            => '#2563eb',
            'enable_discovery'              => 'yes',
            'enable_trending'               => 'yes',
            'trending_window_days'          => '7',
            'max_discovery_items'           => '10',
            'review_moderation_mode'        => 'auto_approve',
            'trusted_embed_domains'         => '',
            'embed_sandbox_mode'            => 'compatible',
            'user_submissions_enabled'     => 'yes',
            'allow_subscriber_submissions' => 'yes',
            'allow_contributor_submissions'=> 'yes',
            'allow_author_submissions'     => 'yes',
            'require_moderation'           => 'yes',
            'allow_user_movie_upload'      => 'yes',
            'allow_user_series_upload'     => 'yes',
            'allow_user_episode_upload'    => 'yes',
            'allow_user_song_upload'       => 'yes',
            'allow_user_album_upload'      => 'yes',
            'allow_user_playlist_creation' => 'yes',
            'max_video_upload_mb'          => '500',
            'max_audio_upload_mb'          => '100',
            'max_image_upload_mb'          => '10',
            'max_subtitle_upload_mb'       => '5',
        ];

        foreach ($defaults as $key => $defaultVal) {
            try {
                $current = \FavoriteCMS\Models\Setting::get('multimedia', $key, null);
                // Only seed if setting is completely missing/uninitialized in database
                if ($current === null) {
                    \FavoriteCMS\Models\Setting::set('multimedia', $key, $defaultVal);
                }
            } catch (\Throwable) {
                // Table might not be ready yet
            }
        }
    }

    /**
     * Backwards-compatible alias for ensureDefaultSettings.
     */
    public static function seedDefaultSettings(): void
    {
        self::ensureDefaultSettings();
    }

    /**
     * Safe, idempotent version-upgrade / missing-setting self-heal.
     * Runs automatically during boot on both fresh installs and existing active installations
     * without requiring deactivation/reactivation.
     */
    public function ensureUpgrade(): void
    {
        if (self::$upgradeChecked) {
            return;
        }

        if (!$this->app->has(Database::class)) {
            return;
        }

        try {
            $db = $this->app->make(Database::class);
            $installedVersion = (string)\FavoriteCMS\Models\Setting::get('multimedia', 'installed_version', '');
            $needsUpgrade = ($installedVersion === '' || version_compare($installedVersion, self::VERSION, '<'));
            $trustedMissing = (\FavoriteCMS\Models\Setting::get('multimedia', 'trusted_embed_domains', null) === null);

            $ownershipMissing = false;
            if ($db->tableExists('multimedia_movies')) {
                try {
                    $cols = $db->select("SHOW COLUMNS FROM `multimedia_movies` LIKE 'user_id'");
                    $ownershipMissing = empty($cols);
                } catch (\Throwable) {
                    $ownershipMissing = false;
                }
            }

            if ($needsUpgrade || $trustedMissing || $ownershipMissing) {
                // 1. Run migrations for any newly introduced tables/fields
                $this->runMigrations();

                // 2. Register any missing default permissions
                MultimediaPermission::registerDefaultPermissions($db);

                // 3. Ensure all required default settings exist without overwriting existing
                self::ensureDefaultSettings();

                // 4. Update stored version
                \FavoriteCMS\Models\Setting::set('multimedia', 'installed_version', self::VERSION);
                \FavoriteCMS\Models\Setting::set('multimedia', 'version', self::VERSION);

                if (function_exists('cms_log')) {
                    cms_log('Favorite Multimedia upgraded to v' . self::VERSION, 'info', ['plugin' => 'favorite-multimedia']);
                }
            }
            self::$upgradeChecked = true;
        } catch (\Throwable) {
            // DB might not be ready yet
        }
    }

    public function onDeactivate(): void
    {
        if (function_exists('cms_log')) {
            cms_log('Favorite Multimedia plugin deactivated.', 'info', ['plugin' => 'favorite-multimedia']);
        }
    }

    public function runMigrations(): array
    {
        if (!$this->app->has(Database::class)) {
            return [];
        }

        $db = $this->app->make(Database::class);
        if (method_exists($db, 'registerPrefixableTables')) {
            $db->registerPrefixableTables(self::TABLES);
        }

        $migrator = new Migrator($db);
        $migrationsPath = __DIR__ . '/../database/migrations';
        return $migrator->migrate($migrationsPath);
    }

    private function registerAdminMenus(): void
    {
        if (!function_exists('add_admin_menu')) {
            return;
        }

        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        // Top level: Multimedia (routes to canonical Dashboard: /admin/page/multimedia)
        add_admin_menu(
            'multimedia',
            'Multimedia',
            '🎬',
            function (Request $req) use ($adminCtrl) {
                // When /admin/page/multimedia is being dispatched, findPage() has already
                // completed. Enable MultimediaSidebarTitle for sidebar consistency and wrap
                // content cleanly in Favorite CMS admin layout returning a Response to avoid
                // strict-type errors with Stringable titles in Kernel::renderAdminPage.
                self::enableSidebarTitle();
                $user = function_exists('current_user') ? current_user() : null;
                $isAdmin = $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
                $isMod = $user && ($user->hasRole('moderator') || $user->hasRole('editor') || MultimediaPermission::can(MultimediaPermission::MODERATE, $user));
                if (!$isAdmin && !$isMod) {
                    $content = $adminCtrl->handle($req, 'my_submissions');
                    $title = 'My Multimedia';
                } else {
                    $content = $adminCtrl->handle($req, 'dashboard');
                    $title = 'Multimedia';
                }
                if ($content instanceof Response) {
                    return $content;
                }
                return self::renderAdminPageLayout('multimedia', $title, (string)$content);
            },
            MultimediaPermission::CREATE,
            52
        );

        if (function_exists('add_admin_submenu')) {
            // Note: multimedia-dashboard submenu is NOT registered because CMS core
            // (resources/views/admin/layout.php line 440) automatically generates the first
            // child submenu linking to /admin/page/multimedia. MultimediaSidebarTitle ensures
            // line 440 renders as "Dashboard", eliminating the duplicate child.
            add_admin_submenu('multimedia', 'multimedia-my-submissions', 'My Submissions', fn(Request $r) => $adminCtrl->handle($r, 'my_submissions'), MultimediaPermission::CREATE);
            add_admin_submenu('multimedia', 'multimedia-movies', 'Movies', fn(Request $r) => $adminCtrl->handle($r, 'movies'), MultimediaPermission::CREATE);
            add_admin_submenu('multimedia', 'multimedia-series', 'Web Series', fn(Request $r) => $adminCtrl->handle($r, 'series'), MultimediaPermission::CREATE);
            add_admin_submenu('multimedia', 'multimedia-seasons', 'Seasons', fn(Request $r) => $adminCtrl->handle($r, 'seasons'), MultimediaPermission::CREATE);
            add_admin_submenu('multimedia', 'multimedia-episodes', 'Episodes', fn(Request $r) => $adminCtrl->handle($r, 'episodes'), MultimediaPermission::CREATE);
            add_admin_submenu('multimedia', 'multimedia-songs', 'Songs', fn(Request $r) => $adminCtrl->handle($r, 'songs'), MultimediaPermission::CREATE);
            add_admin_submenu('multimedia', 'multimedia-playlists', 'Playlists', fn(Request $r) => $adminCtrl->handle($r, 'playlists'), MultimediaPermission::CREATE);
            add_admin_submenu('multimedia', 'multimedia-genres', 'Genres', fn(Request $r) => $adminCtrl->handle($r, 'genres'), MultimediaPermission::VIEW);
            add_admin_submenu('multimedia', 'multimedia-artists', 'Artists', fn(Request $r) => $adminCtrl->handle($r, 'artists'), MultimediaPermission::VIEW);
            add_admin_submenu('multimedia', 'multimedia-albums', 'Albums', fn(Request $r) => $adminCtrl->handle($r, 'albums'), MultimediaPermission::CREATE);
            add_admin_submenu('multimedia', 'multimedia-sources', 'Advanced Sources', fn(Request $r) => $adminCtrl->handle($r, 'sources'), MultimediaPermission::MANAGE_SOURCES);
            add_admin_submenu('multimedia', 'multimedia-subtitles', 'Subtitles', fn(Request $r) => $adminCtrl->handle($r, 'subtitles'), MultimediaPermission::MANAGE_SUBTITLES);
            add_admin_submenu('multimedia', 'multimedia-access', 'Access Control', fn(Request $r) => $adminCtrl->handle($r, 'access'), MultimediaPermission::MANAGE_ACCESS);
            add_admin_submenu('multimedia', 'multimedia-analytics', 'Analytics', fn(Request $r) => $adminCtrl->handle($r, 'analytics'), MultimediaPermission::VIEW_ANALYTICS);
            add_admin_submenu('multimedia', 'multimedia-settings', 'Settings', fn(Request $r) => $adminCtrl->handle($r, 'settings'), MultimediaPermission::MANAGE_SETTINGS);
            add_admin_submenu('multimedia', 'multimedia-moderation', 'Moderation', fn(Request $r) => $adminCtrl->handle($r, 'moderation'), MultimediaPermission::MODERATE);
            add_admin_submenu('multimedia', 'multimedia-releases', 'Release Calendar', fn(Request $r) => $adminCtrl->handle($r, 'releases'), MultimediaPermission::VIEW);
            add_admin_submenu('multimedia', 'multimedia-processing', 'Media Processing', fn(Request $r) => $adminCtrl->handle($r, 'processing'), MultimediaPermission::MANAGE_SOURCES);
            add_admin_submenu('multimedia', 'multimedia-storage', 'Storage & CDN', fn(Request $r) => $adminCtrl->handle($r, 'storage'), MultimediaPermission::MANAGE_SETTINGS);
            add_admin_submenu('multimedia', 'multimedia-localizations', 'Localizations', fn(Request $r) => $adminCtrl->handle($r, 'localizations'), MultimediaPermission::MANAGE_SETTINGS);
            add_admin_submenu('multimedia', 'multimedia-theme', 'Theme Studio', fn(Request $r) => $adminCtrl->handle($r, 'theme'), MultimediaPermission::MANAGE_SETTINGS);
        }

        if (class_exists(\FavoriteCMS\Core\AdminMenu::class)) {
            $ref = new \ReflectionProperty(\FavoriteCMS\Core\AdminMenu::class, 'menus');
            $registeredMenus = $ref->getValue();
            if (is_array($registeredMenus) && isset($registeredMenus['multimedia']['submenus']) && is_array($registeredMenus['multimedia']['submenus'])) {
                self::$masterSubmenus = $registeredMenus['multimedia']['submenus'];
            }
        }

        // On any screen other than /admin/page/multimedia, enable MultimediaSidebarTitle immediately
        // so all other admin screens render the sidebar cleanly.
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH) ?? '';
        if ($path !== '/admin/page/multimedia' && $path !== '/admin/page/multimedia/') {
            self::enableSidebarTitle();
        }

        if (function_exists('add_action')) {
            add_action('init', static function (): void {
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                $path = parse_url($uri, PHP_URL_PATH) ?? '';
                if ($path !== '/admin/page/multimedia' && $path !== '/admin/page/multimedia/') {
                    self::enableSidebarTitle();
                }
            });
        }
    }

    public static function enableSidebarTitle(): void
    {
        try {
            if (!class_exists(\FavoriteCMS\Core\AdminMenu::class)) {
                return;
            }
            $ref = new \ReflectionProperty(\FavoriteCMS\Core\AdminMenu::class, 'menus');
            $menus = $ref->getValue();
            if (!is_array($menus) || !isset($menus['multimedia']) || !is_array($menus['multimedia'])) {
                return;
            }

            if (empty(self::$masterSubmenus) && isset($menus['multimedia']['submenus'])) {
                $raw = $menus['multimedia']['submenus'];
                if ($raw instanceof \FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection) {
                    self::$masterSubmenus = $raw->getAll();
                } elseif (is_array($raw)) {
                    self::$masterSubmenus = $raw;
                }
            }

            $user = function_exists('current_user') ? current_user() : null;
            if ($user === null) {
                // If user is not yet hydrated, defer and do not strip menus
                return;
            }

            // Suspended users have zero access to admin/creator features
            if (MultimediaPermission::isSuspendedUser($user)) {
                unset($menus['multimedia']);
                $ref->setValue(null, $menus);
                return;
            }

            $isAdmin = (MultimediaPermission::hasUserRole($user, 'super-admin') || MultimediaPermission::hasUserRole($user, 'admin'));
            $isEditor = MultimediaPermission::hasUserRole($user, 'editor');
            $isModerator = MultimediaPermission::hasUserRole($user, 'moderator');

            if ($isAdmin) {
                // Admin Navigation: show Dashboard
                $menus['multimedia']['title'] = new MultimediaSidebarTitle('Multimedia', 'Dashboard');
                if (!empty(self::$masterSubmenus)) {
                    $menus['multimedia']['submenus'] = self::$masterSubmenus;
                } elseif (isset($menus['multimedia']['submenus']) && $menus['multimedia']['submenus'] instanceof \FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection) {
                    $menus['multimedia']['submenus'] = $menus['multimedia']['submenus']->getAll();
                }
            } elseif ($isEditor || $isModerator) {
                // Editor / Moderator Navigation: show Dashboard with strictly restricted submenus
                $menus['multimedia']['title'] = new MultimediaSidebarTitle('Multimedia', 'Dashboard');

                $all = !empty(self::$masterSubmenus)
                    ? self::$masterSubmenus
                    : (isset($menus['multimedia']['submenus'])
                        ? (($menus['multimedia']['submenus'] instanceof \FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection)
                            ? $menus['multimedia']['submenus']->getAll()
                            : (is_array($menus['multimedia']['submenus']) ? $menus['multimedia']['submenus'] : []))
                        : []);

                $visible = [];
                foreach ($all as $k => $sub) {
                    if (!is_array($sub)) continue;
                    $slug = (string)($sub['slug'] ?? $k);
                    $cap = (string)($sub['capability'] ?? '');

                    // Settings / Storage / Theme / Localizations: Editor = NO, Moderator = NO
                    if (in_array($slug, ['multimedia-settings', 'multimedia-storage', 'multimedia-theme', 'multimedia-localizations'], true)) {
                        continue;
                    }

                    // Genres, Artists, Access Control: Moderator = NO (Editor = YES / Restricted)
                    if ($isModerator && in_array($slug, ['multimedia-genres', 'multimedia-artists', 'multimedia-access'], true)) {
                        continue;
                    }

                    // Check capability if present
                    if ($cap !== '' && !MultimediaPermission::can($cap, $user)) {
                        continue;
                    }

                    $visible[$k] = $sub;
                }
                $menus['multimedia']['submenus'] = $visible;
            } else {
                // If user cannot submit any multimedia (e.g. Subscriber, Contributor), hide Multimedia menu completely
                $canSubmit = MultimediaPermission::canUserSubmit(null, $user);
                if (!$canSubmit) {
                    unset($menus['multimedia']);
                    $ref->setValue(null, $menus);
                    return;
                }

                // Creator Navigation for Authors
                $menus['multimedia']['title'] = new MultimediaSidebarTitle('My Multimedia', 'My Submissions');

                $rawSubmenus = $menus['multimedia']['submenus'] ?? [];
                if ($rawSubmenus instanceof \FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection) {
                    $allSubmenus = $rawSubmenus->getAll();
                } elseif (is_array($rawSubmenus)) {
                    $allSubmenus = $rawSubmenus;
                } else {
                    $allSubmenus = [];
                }

                $visibleSubmenus = [];
                // 1. My Submissions (accessible via getVisible() and direct slug routing; omitted from layout iteration to prevent duplicate child links)
                $visibleSubmenus['multimedia-my-submissions'] = [
                    'slug'       => 'multimedia-my-submissions',
                    'title'      => 'My Submissions',
                    'handler'    => is_array($allSubmenus['multimedia-my-submissions'] ?? null) ? ($allSubmenus['multimedia-my-submissions']['handler'] ?? null) : null,
                    'capability' => MultimediaPermission::CREATE,
                ];

                // 2. Add Movie (if enabled)
                if (MultimediaPermission::canUserSubmit('movie', $user)) {
                    $visibleSubmenus['multimedia-movies?new=1'] = [
                        'slug'       => 'multimedia-movies?new=1',
                        'title'      => 'Add Movie',
                        'handler'    => is_array($allSubmenus['multimedia-movies'] ?? null) ? ($allSubmenus['multimedia-movies']['handler'] ?? null) : null,
                        'capability' => MultimediaPermission::CREATE,
                    ];
                }

                // 3. Add Series (if enabled)
                if (MultimediaPermission::canUserSubmit('series', $user)) {
                    $visibleSubmenus['multimedia-series?new=1'] = [
                        'slug'       => 'multimedia-series?new=1',
                        'title'      => 'Add Series',
                        'handler'    => is_array($allSubmenus['multimedia-series'] ?? null) ? ($allSubmenus['multimedia-series']['handler'] ?? null) : null,
                        'capability' => MultimediaPermission::CREATE,
                    ];
                }

                // 4. Add Episode (if enabled)
                if (MultimediaPermission::canUserSubmit('episode', $user)) {
                    $visibleSubmenus['multimedia-episodes?new=1'] = [
                        'slug'       => 'multimedia-episodes?new=1',
                        'title'      => 'Add Episode',
                        'handler'    => is_array($allSubmenus['multimedia-episodes'] ?? null) ? ($allSubmenus['multimedia-episodes']['handler'] ?? null) : null,
                        'capability' => MultimediaPermission::CREATE,
                    ];
                }

                // 5. Add Song (if enabled)
                if (MultimediaPermission::canUserSubmit('song', $user)) {
                    $visibleSubmenus['multimedia-songs?new=1'] = [
                        'slug'       => 'multimedia-songs?new=1',
                        'title'      => 'Add Song',
                        'handler'    => is_array($allSubmenus['multimedia-songs'] ?? null) ? ($allSubmenus['multimedia-songs']['handler'] ?? null) : null,
                        'capability' => MultimediaPermission::CREATE,
                    ];
                }

                // 6. Add Album (if enabled)
                if (MultimediaPermission::canUserSubmit('album', $user)) {
                    $visibleSubmenus['multimedia-albums?new=1'] = [
                        'slug'       => 'multimedia-albums?new=1',
                        'title'      => 'Add Album',
                        'handler'    => is_array($allSubmenus['multimedia-albums'] ?? null) ? ($allSubmenus['multimedia-albums']['handler'] ?? null) : null,
                        'capability' => MultimediaPermission::CREATE,
                    ];
                }

                // 7. Add Playlist (if enabled)
                if (MultimediaPermission::canUserSubmit('playlist', $user)) {
                    $visibleSubmenus['multimedia-playlists?new=1'] = [
                        'slug'       => 'multimedia-playlists?new=1',
                        'title'      => 'Add Playlist',
                        'handler'    => is_array($allSubmenus['multimedia-playlists'] ?? null) ? ($allSubmenus['multimedia-playlists']['handler'] ?? null) : null,
                        'capability' => MultimediaPermission::CREATE,
                    ];
                }

                // 8. My Analytics
                if (MultimediaPermission::can(MultimediaPermission::VIEW_ANALYTICS, $user)) {
                    $visibleSubmenus['multimedia-analytics'] = [
                        'slug'       => 'multimedia-analytics',
                        'title'      => 'My Analytics',
                        'handler'    => is_array($allSubmenus['multimedia-analytics'] ?? null) ? ($allSubmenus['multimedia-analytics']['handler'] ?? null) : null,
                        'capability' => MultimediaPermission::VIEW_ANALYTICS,
                    ];
                }

                // Keep all registered routes hidden from sidebar iteration but accessible to AdminMenu::findPage()
                $hiddenSubmenus = [];
                foreach ($allSubmenus as $k => $v) {
                    if (!isset($visibleSubmenus[$k])) {
                        $hiddenSubmenus[$k] = $v;
                    }
                }

                $menus['multimedia']['submenus'] = new \FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection(
                    $visibleSubmenus,
                    $hiddenSubmenus
                );
            }

            $ref->setValue(null, $menus);
        } catch (\Throwable) {
            // Fallback gracefully if reflection is restricted
        }
    }

    /**
     * Safely wraps admin page HTML inside the standard Favorite CMS admin layout shell,
     * ensuring proper string casting of page titles and seamless integration with top admin bar and sidebar.
     */
    public static function renderAdminPageLayout(string $slug, string $pageTitle, string $content): Response
    {
        $siteName = class_exists(\FavoriteCMS\Models\Setting::class)
            ? (string)\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS')
            : 'Favorite CMS';
        $username = $_SESSION['auth_user_name'] ?? 'Admin';
        $activeMenu = $slug;
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        ob_start();
        ?>
        <div class="page-header">
            <h1 class="page-title"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>
        <div class="plugin-page-card" style="background: #fff; padding: 24px; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <?php echo $content; ?>
        </div>
        <?php
        $customHtml = (string)ob_get_clean();

        $viewData = [
            'siteName'     => $siteName,
            'username'     => $username,
            'activeMenu'   => $activeMenu,
            'pageTitle'    => $pageTitle,
            'flashSuccess' => $flashSuccess,
            'flashError'   => $flashError,
            'contentView'  => null,
            'customHtml'   => $customHtml,
        ];
        $obLevel = ob_get_level();
        try {
            extract($viewData, EXTR_SKIP);
            ob_start();
            include APP_ROOT . '/resources/views/admin/layout.php';
            return Response::make((string)ob_get_clean(), 200);
        } catch (\Throwable) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            return Response::make($customHtml, 200);
        }
    }

    private function registerRoutes(): void
    {
        foreach (Router::getRoutes() as $existing) {
            if ($existing['path'] === '/multimedia') {
                self::$routesRegistered = true;
                return;
            }
        }

        $frontendCtrl = $this->app->make(MultimediaFrontendController::class);
        $playbackCtrl = $this->app->make(MediaPlaybackController::class);

        // Public Catalog routes
        Router::get('/multimedia', fn(Request $r) => $frontendCtrl->hub($r));
        Router::get('/multimedia/search', fn(Request $r) => $frontendCtrl->search($r));
        Router::get('/multimedia/api/search/suggestions', fn(Request $r) => $frontendCtrl->apiSearchSuggestions($r));
        Router::get('/multimedia/music', fn(Request $r) => $frontendCtrl->music($r));
        Router::get('/music', fn(Request $r) => $frontendCtrl->music($r));

        Router::get('/movies', fn(Request $r) => $frontendCtrl->movies($r));
        Router::get('/movie/{slug}', fn(Request $r, string $slug) => $frontendCtrl->movie($r, $slug));

        Router::get('/series', fn(Request $r) => $frontendCtrl->series($r));
        Router::get('/series/{slug}', fn(Request $r, string $slug) => $frontendCtrl->seriesSingle($r, $slug));
        Router::get('/episode/{slug}', fn(Request $r, string $slug) => $frontendCtrl->episode($r, $slug));

        Router::get('/songs', fn(Request $r) => $frontendCtrl->songs($r));
        Router::get('/song/{slug}', fn(Request $r, string $slug) => $frontendCtrl->song($r, $slug));

        Router::get('/playlists', fn(Request $r) => $frontendCtrl->playlists($r));
        Router::get('/playlist/{slug}', fn(Request $r, string $slug) => $frontendCtrl->playlist($r, $slug));

        // Personal Library & User Progress routes
        Router::get('/multimedia/library', fn(Request $r) => $frontendCtrl->library($r));
        Router::get('/multimedia/history', fn(Request $r) => $frontendCtrl->history($r));
        Router::get('/multimedia/my-list', fn(Request $r) => $frontendCtrl->myList($r));
        Router::get('/multimedia/membership', fn(Request $r) => $frontendCtrl->membership($r));
        Router::get('/membership', fn(Request $r) => $frontendCtrl->membership($r));

        // Discovery & Taxonomy routes
        Router::get('/multimedia/discover', fn(Request $r) => $frontendCtrl->discover($r));
        Router::get('/multimedia/genre/{slug}', fn(Request $r, string $slug) => $frontendCtrl->genre($r, $slug));
        Router::get('/multimedia/artist/{slug}', fn(Request $r, string $slug) => $frontendCtrl->artist($r, $slug));
        Router::get('/multimedia/album/{slug}', fn(Request $r, string $slug) => $frontendCtrl->album($r, $slug));

        // Global Audio Player API routes (Chunk 3)
        Router::get('/multimedia/api/audio/resolve/{id}', fn(Request $r, string $id) => $frontendCtrl->apiAudioResolve($r, $id));
        Router::get('/multimedia/api/audio/context/{type}/{id}', fn(Request $r, string $type, string $id) => $frontendCtrl->apiAudioContext($r, $type, $id));
        Router::post('/multimedia/api/audio/progress', fn(Request $r) => $frontendCtrl->apiAudioProgress($r));

        Router::get('/multimedia/play/{id}', fn(Request $r, string $id) => $playbackCtrl->player($r, $id));
        Router::get('/multimedia/stream/{id}', fn(Request $r, string $id) => $playbackCtrl->stream($r, $id));
        Router::get('/multimedia/download/{id}', fn(Request $r, string $id) => $playbackCtrl->download($r, $id));
        Router::get('/multimedia/download-source/{id}', fn(Request $r, string $id) => $playbackCtrl->downloadSource($r, $id));
        Router::get('/multimedia/download-content/{contentType}/{id}', fn(Request $r, string $contentType, string $id) => $playbackCtrl->downloadContent($r, $contentType, $id));
        Router::get('/multimedia/subtitle/{id}', fn(Request $r, string $id) => $playbackCtrl->subtitle($r, $id));
        Router::get('/api/multimedia/subtitles/{id}', fn(Request $r, string $id) => $playbackCtrl->subtitle($r, $id));

        // API routes
        Router::get('/multimedia/api/detect', fn(Request $r) => $playbackCtrl->apiDetect($r));
        Router::get('/multimedia/api/progress', fn(Request $r) => $playbackCtrl->apiGetProgress($r));
        Router::post('/multimedia/api/progress', fn(Request $r) => $playbackCtrl->apiSaveProgress($r));
        Router::post('/multimedia/api/favorite/toggle', fn(Request $r) => $playbackCtrl->apiToggleFavorite($r));
        Router::post('/multimedia/api/history/remove', fn(Request $r) => $playbackCtrl->apiRemoveHistory($r));
        Router::post('/multimedia/api/history/clear', fn(Request $r) => $playbackCtrl->apiClearHistory($r));
        Router::get('/multimedia/api/next-episode/{id}', fn(Request $r, string $id) => $playbackCtrl->apiNextEpisode($r, $id));
        Router::get('/multimedia/api/next-playlist-item/{playlistId}/{currentItemId}', fn(Request $r, string $pId, string $cId) => $playbackCtrl->apiNextPlaylistItem($r, $pId, $cId));
        Router::get('/multimedia/api/user-language-preferences', fn(Request $r) => $playbackCtrl->apiGetUserLanguagePreferences($r));
        Router::post('/multimedia/api/user-language-preferences', fn(Request $r) => $playbackCtrl->apiSaveUserLanguagePreferences($r));

        // Community & Engagement API routes
        Router::post('/multimedia/api/rate', fn(Request $r) => $playbackCtrl->apiRate($r));
        Router::post('/multimedia/api/rate/remove', fn(Request $r) => $playbackCtrl->apiRemoveRating($r));
        Router::post('/multimedia/api/review', fn(Request $r) => $playbackCtrl->apiSaveReview($r));
        Router::post('/multimedia/api/review/{id}/delete', fn(Request $r, string $id) => $playbackCtrl->apiDeleteReview($r, $id));
        Router::post('/multimedia/api/review/{id}/helpful', fn(Request $r, string $id) => $playbackCtrl->apiHelpfulReview($r, $id));
        Router::post('/multimedia/api/comment', fn(Request $r) => $playbackCtrl->apiSaveComment($r));
        Router::post('/multimedia/api/comment/{id}/delete', fn(Request $r, string $id) => $playbackCtrl->apiDeleteComment($r, $id));
        Router::post('/multimedia/api/report', fn(Request $r) => $playbackCtrl->apiReport($r));
        Router::get('/multimedia/api/reviews', fn(Request $r) => $playbackCtrl->apiGetReviews($r));
        Router::get('/multimedia/api/comments', fn(Request $r) => $playbackCtrl->apiGetComments($r));

        // Subscriptions & Notifications routes
        Router::get('/multimedia/notifications', fn(Request $r) => $frontendCtrl->notifications($r));
        Router::get('/multimedia/following', fn(Request $r) => $frontendCtrl->following($r));
        Router::post('/multimedia/api/subscribe/toggle', fn(Request $r) => $playbackCtrl->apiToggleSubscribe($r));
        Router::get('/multimedia/api/notifications', fn(Request $r) => $playbackCtrl->apiGetNotifications($r));
        Router::get('/multimedia/api/notifications/unread-count', fn(Request $r) => $playbackCtrl->apiGetUnreadCount($r));
        Router::post('/multimedia/api/notifications/{id}/read', fn(Request $r, string $id) => $playbackCtrl->apiMarkNotificationRead($r, $id));
        Router::post('/multimedia/api/notifications/mark-all-read', fn(Request $r) => $playbackCtrl->apiMarkAllNotificationsRead($r));
        Router::get('/multimedia/api/notification-preferences', fn(Request $r) => $playbackCtrl->apiGetNotificationPreferences($r));
        Router::post('/multimedia/api/notification-preferences', fn(Request $r) => $playbackCtrl->apiSaveNotificationPreferences($r));

        // Release Automation & Calendar Admin API routes
        $adminCtrl = $this->app->make(MultimediaAdminController::class);
        Router::post('/multimedia/api/releases/publish-now', fn(Request $r) => $adminCtrl->apiPublishNow($r));
        Router::post('/multimedia/api/releases/schedule', fn(Request $r) => $adminCtrl->apiScheduleRelease($r));
        Router::post('/multimedia/api/releases/cancel', fn(Request $r) => $adminCtrl->apiCancelSchedule($r));
        Router::post('/multimedia/api/releases/run-due', fn(Request $r) => $adminCtrl->apiRunDueReleases($r));
        Router::get('/multimedia/api/releases/calendar', fn(Request $r) => $adminCtrl->apiGetCalendar($r));
        Router::get('/multimedia/api/releases/queue', fn(Request $r) => $adminCtrl->apiGetQueue($r));

        // HLS Adaptive Streaming routes
        Router::get('/api/multimedia/hls/{id}/master.m3u8', fn(Request $r, string $id) => $playbackCtrl->hlsMaster($r, $id));
        Router::get('/api/multimedia/hls/{id}/audio/{audioId}/index.m3u8', fn(Request $r, string $id, string $audioId) => $playbackCtrl->hlsAudioVariant($r, $id, $audioId));
        Router::get('/api/multimedia/hls/{id}/audio/{audioId}/stream.mp3', fn(Request $r, string $id, string $audioId) => $playbackCtrl->hlsAudioStream($r, $id, $audioId));
        Router::get('/api/multimedia/hls/{id}/{quality}/index.m3u8', fn(Request $r, string $id, string $quality) => $playbackCtrl->hlsVariant($r, $id, $quality));
        Router::get('/api/multimedia/hls/{id}/{quality}/{segment}', fn(Request $r, string $id, string $quality, string $segment) => $playbackCtrl->hlsSegment($r, $id, $quality, $segment));

        // Media Processing Admin API routes
        Router::post('/multimedia/api/processing/dispatch', fn(Request $r) => $adminCtrl->apiDispatchProcessingJob($r));
        Router::post('/multimedia/api/processing/retry', fn(Request $r) => $adminCtrl->apiRetryProcessingJob($r));
        Router::post('/multimedia/api/processing/cancel', fn(Request $r) => $adminCtrl->apiCancelProcessingJob($r));
        Router::post('/multimedia/api/processing/run-queue', fn(Request $r) => $adminCtrl->apiRunProcessingQueue($r));
        Router::get('/multimedia/api/processing/status', fn(Request $r) => $adminCtrl->apiGetProcessingStatus($r));

        // Storage & Token Stream routes
        Router::get('/api/multimedia/storage/token-stream', fn(Request $r) => $playbackCtrl->tokenStream($r));
        Router::post('/api/multimedia/admin/storage/save-settings', fn(Request $r) => $adminCtrl->apiSaveStorageSettings($r));
        Router::post('/api/multimedia/admin/storage/test-connection', fn(Request $r) => $adminCtrl->apiTestStorageConnection($r));
        Router::post('/api/multimedia/admin/storage/migrate', fn(Request $r) => $adminCtrl->apiMigrateStorage($r));
        Router::post('/api/multimedia/admin/storage/cleanup-orphans', fn(Request $r) => $adminCtrl->apiCleanupOrphans($r));
        Router::get('/api/multimedia/admin/storage/stats', fn(Request $r) => $adminCtrl->apiGetStorageStats($r));

        // Localization Admin API routes
        Router::get('/admin/api/multimedia/localizations', fn(Request $r) => $adminCtrl->apiGetLocalizations($r));
        Router::post('/admin/api/multimedia/localizations', fn(Request $r) => $adminCtrl->apiSaveLocalization($r));
        Router::post('/admin/api/multimedia/localizations/delete', fn(Request $r) => $adminCtrl->apiDeleteLocalization($r));

        // Theme Studio & Homepage Builder Admin API routes
        Router::post('/admin/api/multimedia/homepage/save', fn(Request $r) => $adminCtrl->apiSaveHomepage($r));
        Router::post('/admin/api/multimedia/homepage/reset', fn(Request $r) => $adminCtrl->apiResetHomepage($r));
        Router::post('/admin/api/multimedia/homepage/reorder', fn(Request $r) => $adminCtrl->apiReorderHomepage($r));
        Router::post('/admin/api/multimedia/homepage/add-section', fn(Request $r) => $adminCtrl->apiAddHomepageSection($r));
        Router::post('/admin/api/multimedia/homepage/update-section', fn(Request $r) => $adminCtrl->apiUpdateHomepageSection($r));
        Router::post('/admin/api/multimedia/homepage/delete-section', fn(Request $r) => $adminCtrl->apiDeleteHomepageSection($r));
        Router::post('/admin/api/multimedia/homepage/duplicate-section', fn(Request $r) => $adminCtrl->apiDuplicateHomepageSection($r));
        Router::post('/admin/api/multimedia/homepage/preview', fn(Request $r) => $adminCtrl->apiPreviewHomepage($r));
        Router::get('/admin/api/multimedia/homepage/preview', fn(Request $r) => $adminCtrl->apiPreviewHomepage($r));

        self::$routesRegistered = true;
    }
}

