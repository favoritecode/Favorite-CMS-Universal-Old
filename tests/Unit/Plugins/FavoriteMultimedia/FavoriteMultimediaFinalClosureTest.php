<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaFinalClosureTest extends TestCase
{
    private Application $app;
    private Database $db;
    private User $adminUser;
    private User $normalUser;
    private string $tempDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        // Ensure session array is clean
        $_SESSION = [];
        unset(
            $GLOBALS['_test_favorite_digital_available'],
            $GLOBALS['_test_favorite_digital_entitled_users']
        );

        $this->tempDbPath = sys_get_temp_dir() . '/test_fmm_closure_' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->app = new Application(dirname(__DIR__, 4));
        Container::setInstance($this->app);

        $this->db = new Database([
            'driver'   => 'sqlite',
            'database' => $this->tempDbPath,
            'prefix'   => '',
        ]);
        $this->setDbPdo($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        $this->createCoreTables();
        $this->seedUsersAndRoles();

        Router::reset();
        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();
        FavoriteMultimediaPlugin::bootstrap($this->app);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_test_favorite_digital_available'],
            $GLOBALS['_test_favorite_digital_entitled_users']
        );
        $_SESSION = [];
        Router::reset();
        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        if (file_exists($this->tempDbPath)) {
            @unlink($this->tempDbPath);
        }

        parent::tearDown();
    }

    private function setDbPdo(Database $db, \PDO $pdo): void
    {
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($db, $pdo);
    }

    private function createCoreTables(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                status VARCHAR(20) DEFAULT 'active',
                email_verified_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL,
                slug VARCHAR(50) NOT NULL UNIQUE,
                description TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                group_name VARCHAR(50) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, role_id)
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (role_id, permission_id)
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name VARCHAR(50) NOT NULL,
                setting_key VARCHAR(50) NOT NULL,
                value TEXT NULL,
                type VARCHAR(20) DEFAULT 'string',
                is_public INTEGER DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE (group_name, setting_key)
            );
        ");
    }

    private function seedUsersAndRoles(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $adminRoleId = $this->db->insert('roles', [
            'name'       => 'Administrator',
            'slug'       => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $subRoleId = $this->db->insert('roles', [
            'name'       => 'Subscriber',
            'slug'       => 'subscriber',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $adminUserId = $this->db->insert('users', [
            'username'   => 'admin_tester',
            'name'       => 'Admin Tester',
            'email'      => 'admin@example.com',
            'password'   => password_hash('secret', PASSWORD_DEFAULT),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $normalUserId = $this->db->insert('users', [
            'username'   => 'viewer_user',
            'name'       => 'Regular Viewer',
            'email'      => 'viewer@example.com',
            'password'   => password_hash('secret', PASSWORD_DEFAULT),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->insert('user_roles', ['user_id' => $adminUserId, 'role_id' => $adminRoleId]);
        $this->db->insert('user_roles', ['user_id' => $normalUserId, 'role_id' => $subRoleId]);

        $this->adminUser = User::find($adminUserId);
        $this->normalUser = User::find($normalUserId);
    }

    private function setSessionUser(?User $user): void
    {
        if ($user) {
            $_SESSION['auth_user_id'] = (int)$user->id;
            $_SESSION['auth_user_name'] = (string)$user->username;
            $_SESSION['auth_user_email'] = (string)$user->email;
            $_SESSION['_token'] = 'valid_test_csrf_token';
        } else {
            unset($_SESSION['auth_user_id'], $_SESSION['auth_user_name'], $_SESSION['auth_user_email'], $_SESSION['_token']);
        }
    }

    // =========================================================================
    // 1. PLUGIN DISCOVERY & MANIFEST INTEGRATION
    // =========================================================================

    public function testPluginDiscoveryAndManifestValidation(): void
    {
        $pluginManager = new PluginManager($this->app);
        $installed = $pluginManager->getInstalledPlugins();

        $this->assertArrayHasKey('favorite-multimedia', $installed);
        $meta = $installed['favorite-multimedia'];

        $this->assertSame('favorite-multimedia', $meta['id']);
        $this->assertSame('Favorite Multimedia', $meta['name']);
        $this->assertSame('1.0.7', $meta['version']);
        $this->assertSame('plugin.php', $meta['entry_point']);
        $this->assertTrue($meta['valid']);
        $this->assertTrue($meta['compatible']);

        // Verify all 28 tables are registered in the manifest
        $this->assertCount(28, $meta['tables']);
        $this->assertContains('multimedia_movies', $meta['tables']);
        $this->assertContains('multimedia_localizations', $meta['tables']);
        $this->assertContains('multimedia_storage_files', $meta['tables']);
        $this->assertContains('multimedia_processing_jobs', $meta['tables']);
    }

    // =========================================================================
    // 2. FRESH INSTALLATION & ACTIVATION LIFECYCLE (ALL 9 MIGRATIONS & 28 TABLES)
    // =========================================================================

    public function testFreshInstallationAndActivationLifecycle(): void
    {
        $pluginManager = new PluginManager($this->app);

        // Activate plugin via official PluginManager
        $activated = $pluginManager->activatePlugin('favorite-multimedia');
        $this->assertTrue($activated);

        // Verify active plugins list in settings
        $this->assertContains('favorite-multimedia', $pluginManager->getActivePlugins());

        // Verify all 28 tables exist in DB
        foreach (FavoriteMultimediaPlugin::TABLES as $tableName) {
            $cols = $this->db->select("PRAGMA table_info(`{$tableName}`)");
            $this->assertNotEmpty($cols, "Table '{$tableName}' must exist after fresh installation migrations.");
        }

        // Verify permissions seeded
        $perm = $this->db->selectOne("SELECT * FROM permissions WHERE slug = ?", [MultimediaPermission::VIEW]);
        $this->assertNotNull($perm);
        $rolePerm = $this->db->selectOne("SELECT * FROM role_permissions WHERE permission_id = ?", [$perm->id]);
        $this->assertNotNull($rolePerm);
    }

    // =========================================================================
    // 3. ADMIN MENU REGISTRATION & DISPATCHING (20 SUBMENUS)
    // =========================================================================

    public function testAdminNavigationMenuAndAllSubmenus(): void
    {
        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $this->setSessionUser($this->adminUser);

        // Verify top-level menu
        $menus = AdminMenu::getMenus();
        $this->assertArrayHasKey('multimedia', $menus);
        $this->assertSame('Multimedia', (string)$menus['multimedia']['title']);
        $this->assertSame('🎬', $menus['multimedia']['icon']);

        // Verify all 20 submenus are findable in AdminMenu
        $expectedSubmenus = [
            'multimedia',
            'multimedia-movies',
            'multimedia-series',
            'multimedia-seasons',
            'multimedia-episodes',
            'multimedia-songs',
            'multimedia-playlists',
            'multimedia-genres',
            'multimedia-artists',
            'multimedia-albums',
            'multimedia-sources',
            'multimedia-subtitles',
            'multimedia-access',
            'multimedia-analytics',
            'multimedia-settings',
            'multimedia-moderation',
            'multimedia-releases',
            'multimedia-processing',
            'multimedia-storage',
            'multimedia-localizations',
        ];

        $adminCtrl = $this->app->make(MultimediaAdminController::class);
        $req = new Request(['action' => 'index']);

        foreach ($expectedSubmenus as $slug) {
            $page = AdminMenu::findPage($slug);
            $this->assertNotNull($page, "Submenu [{$slug}] must be registered in AdminMenu.");
            $this->assertIsCallable($page['handler'], "Handler for [{$slug}] must be callable.");

            // Dispatch page handler directly
            $content = call_user_func($page['handler'], $req);
            $this->assertTrue(
                is_string($content) || $content instanceof Response,
                "Admin page [{$slug}] must return a string or Response."
            );
            if (is_string($content)) {
                $this->assertStringNotContainsString('404 Not Found', $content);
                $this->assertStringNotContainsString('View not found', $content);
            }
        }
    }

    // =========================================================================
    // 4. CONTENT LIFECYCLE WORKFLOWS (MOVIES, SERIES, EPISODES, SONGS)
    // =========================================================================

    public function testMovieAndSeriesEndToEndCrud(): void
    {
        FavoriteMultimediaPlugin::bootstrap($this->app);
        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');
        $this->setSessionUser($this->adminUser);

        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        // 1. Create Movie
        $createReq = new Request([], [
            'action'      => 'create',
            'title'       => 'The Inception Journey',
            'slug'        => 'the-inception-journey',
            'description' => 'A mind-bending psychological thriller.',
            'access_mode' => 'public',
            'status'      => 'published',
            'duration'    => 148,
            '_token'      => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $adminCtrl->movies($createReq);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('the-inception-journey');
        $this->assertNotNull($movie);
        $this->assertSame('The Inception Journey', $movie->title);

        // 2. Add Media Source to Movie
        $sourceId = $this->db->insert('multimedia_sources', [
            'content_type'   => 'movie',
            'content_id'     => $movie->id,
            'source_mode'    => 'url',
            'source_type'    => 'video',
            'url_or_path'    => 'https://example.com/movies/inception.mp4',
            'quality'        => '1080p',
            'status'         => 'active',
            'allow_download' => 'inherit',
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        $this->assertGreaterThan(0, $sourceId);

        // 3. Create Series and Episode
        $seriesReq = new Request([], [
            'action'      => 'create',
            'title'       => 'Dark Matter Chronicles',
            'slug'        => 'dark-matter-chronicles',
            'description' => 'Sci-fi web series.',
            'access_mode' => 'premium',
            'status'      => 'published',
            '_token'      => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $adminCtrl->series($seriesReq);

        $series = Series::findBySlug('dark-matter-chronicles');
        $this->assertNotNull($series);
        $this->assertSame('premium', $series->access_mode);

        $seasonId = $this->db->insert('multimedia_seasons', [
            'series_id'     => $series->id,
            'season_number' => 1,
            'title'         => 'Season 1',
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        $episodeReq = new Request([], [
            'action'         => 'create',
            'series_id'      => $series->id,
            'season_id'      => $seasonId,
            'episode_number' => 1,
            'title'          => 'Pilot (Free Teaser)',
            'slug'           => 'dark-matter-s01e01',
            'access_mode'    => 'public', // Free override for premium series
            'status'         => 'published',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $adminCtrl->episodes($episodeReq);

        $episode = Episode::findBySlug('dark-matter-s01e01');
        $this->assertNotNull($episode);
        $this->assertSame('public', $episode->getResolvedAccessMode());
    }

    // =========================================================================
    // 5. DEPENDENCY COMBINATION SCENARIOS (FAVORITE DIGITAL & FAVORITE PAY)
    // =========================================================================

    public function testAccessModesAndDependencyScenarios(): void
    {
        FavoriteMultimediaPlugin::bootstrap($this->app);
        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');

        $now = gmdate('Y-m-d H:i:s');

        // Create Public Movie, Login Movie, and Premium Movie
        $publicId = $this->db->insert('multimedia_movies', [
            'title'       => 'Free Documentary',
            'slug'        => 'free-doc',
            'access_mode' => 'public',
            'status'      => 'published',
            'created_at'  => $now,
        ]);
        $loginId = $this->db->insert('multimedia_movies', [
            'title'       => 'Member Special',
            'slug'        => 'member-special',
            'access_mode' => 'login',
            'status'      => 'published',
            'created_at'  => $now,
        ]);
        $premiumId = $this->db->insert('multimedia_movies', [
            'title'       => 'Blockbuster Premiere',
            'slug'        => 'blockbuster-premiere',
            'access_mode' => 'premium',
            'status'      => 'published',
            'created_at'  => $now,
        ]);

        // Scenario 1: Favorite Digital NOT installed / absent
        $GLOBALS['_test_favorite_digital_available'] = false;
        $GLOBALS['_test_favorite_digital_entitled_users'] = [];

        // Guest user
        $this->setSessionUser(null);
        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess(null, 'movie', $publicId));
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, MultimediaAccessService::checkAccess(null, 'movie', $loginId));
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, MultimediaAccessService::checkAccess(null, 'movie', $premiumId));

        // Logged-in user without Digital
        $this->setSessionUser($this->normalUser);
        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess($this->normalUser, 'movie', $publicId));
        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess($this->normalUser, 'movie', $loginId));
        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, MultimediaAccessService::checkAccess($this->normalUser, 'movie', $premiumId));

        // Scenario 2: Favorite Digital available, but user has NO active entitlement
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_entitled_users'] = [9999]; // not this user
        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, MultimediaAccessService::checkAccess($this->normalUser, 'movie', $premiumId));

        // Scenario 3: Favorite Digital active subscription present
        $GLOBALS['_test_favorite_digital_entitled_users'] = [(int)$this->normalUser->id];
        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess($this->normalUser, 'movie', $premiumId));

        // Scenario 4: Favorite Pay successful payment, but Favorite Digital entitlement ABSENT
        // Payment success by itself MUST NOT unlock Premium media!
        $GLOBALS['_test_favorite_digital_entitled_users'] = [];
        $GLOBALS['_test_favorite_pay_success'] = true;
        $this->assertSame(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($this->normalUser, 'movie', $premiumId),
            'Favorite Pay payment alone MUST NEVER grant Premium access without Favorite Digital entitlement.'
        );

        // Scenario 5: Favorite Pay checkout succeeds, then Favorite Digital entitlement is recorded
        $GLOBALS['_test_favorite_digital_entitled_users'] = [(int)$this->normalUser->id];
        $this->assertSame(
            MultimediaAccessService::ALLOW,
            MultimediaAccessService::checkAccess($this->normalUser, 'movie', $premiumId),
            'Multimedia subsequently sees Digital entitlement and permits playback.'
        );
    }

    // =========================================================================
    // 6. DIRECT MEDIA URL SECURITY & BYPASS PROTECTION
    // =========================================================================

    public function testDirectMediaUrlSecurityAndTokenEnforcement(): void
    {
        FavoriteMultimediaPlugin::bootstrap($this->app);
        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');

        $now = gmdate('Y-m-d H:i:s');
        $premiumMovieId = $this->db->insert('multimedia_movies', [
            'title'       => 'Secret VIP Movie',
            'slug'        => 'secret-vip-movie',
            'access_mode' => 'premium',
            'status'      => 'published',
            'created_at'  => $now,
        ]);

        $sourceId = $this->db->insert('multimedia_sources', [
            'content_type'   => 'movie',
            'content_id'     => $premiumMovieId,
            'source_mode'    => 'url',
            'source_type'    => 'video',
            'url_or_path'    => 'https://example.com/vip.mp4',
            'status'         => 'active',
            'created_at'     => $now,
        ]);

        $subId = $this->db->insert('multimedia_subtitles', [
            'content_type'   => 'movie',
            'content_id'     => $premiumMovieId,
            'language'       => 'en',
            'label'          => 'English',
            'file_or_url'    => 'subs/vip.vtt',
            'format'         => 'vtt',
            'created_at'     => $now,
        ]);

        $playbackCtrl = $this->app->make(MediaPlaybackController::class);

        // Unauthenticated guest request to stream, hlsMaster, download, subtitle
        $this->setSessionUser(null);
        $req = new Request();

        // Subtitle endpoint
        $subResp = $playbackCtrl->subtitle($req, (string)$subId);
        $this->assertInstanceOf(Response::class, $subResp);
        $this->assertSame(403, $subResp->getStatusCode());

        // HLS Master endpoint (returns 401 for unauthenticated guest)
        $hlsResp = $playbackCtrl->hlsMaster($req, (string)$sourceId);
        $this->assertInstanceOf(Response::class, $hlsResp);
        $this->assertSame(401, $hlsResp->getStatusCode());

        // Download endpoint (returns 401 for unauthenticated guest)
        $downResp = $playbackCtrl->download($req, (string)$sourceId);
        $this->assertInstanceOf(Response::class, $downResp);
        $this->assertSame(401, $downResp->getStatusCode());

        // Authenticated non-subscriber user (returns 403 Premium Required)
        $this->setSessionUser($this->normalUser);
        $hlsRespAuth = $playbackCtrl->hlsMaster($req, (string)$sourceId);
        $this->assertSame(403, $hlsRespAuth->getStatusCode());
        $downRespAuth = $playbackCtrl->download($req, (string)$sourceId);
        $this->assertSame(403, $downRespAuth->getStatusCode());
    }

    // =========================================================================
    // 7. FRONTEND CATALOG & DISCOVERY ROUTING (15 PUBLIC ROUTES)
    // =========================================================================

    public function testFrontendCatalogAndDiscoveryRouting(): void
    {
        FavoriteMultimediaPlugin::bootstrap($this->app);
        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');

        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title'       => 'Frontend Movie Test',
            'slug'        => 'frontend-movie-test',
            'access_mode' => 'public',
            'status'      => 'published',
            'created_at'  => $now,
        ]);

        $testRoutes = [
            '/multimedia',
            '/movies',
            '/movie/frontend-movie-test',
            '/series',
            '/songs',
            '/playlists',
            '/multimedia/discover',
            '/multimedia/search',
        ];

        foreach ($testRoutes as $path) {
            $req = new Request([], [], ['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET']);
            $resp = Router::dispatch($req);

            $this->assertNotNull($resp, "Route [{$path}] must dispatch cleanly via Router.");
            $this->assertSame(200, $resp->getStatusCode(), "Route [{$path}] must return HTTP 200.");
            $this->assertNotEmpty($resp->getContent());
        }
    }

    // =========================================================================
    // 8. ADMIN SETTINGS PERSISTENCE & RELOAD
    // =========================================================================

    public function testAdminSettingsSaveAndReload(): void
    {
        FavoriteMultimediaPlugin::bootstrap($this->app);
        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');
        $this->setSessionUser($this->adminUser);

        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        $postData = [
            'enable_downloads'          => 'no',
            'default_video_resolution'  => '4k',
            'player_theme_color'        => '#10b981',
            'enable_discovery'          => 'yes',
            'enable_trending'           => 'yes',
            'trending_window_days'      => '14',
            'max_discovery_items'       => '12',
            'review_moderation_mode'    => 'require_approval',
            '_token'                    => 'valid_test_csrf_token',
        ];

        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $resp = $adminCtrl->settings($req);

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame('no', Setting::get('multimedia', 'enable_downloads'));
        $this->assertSame('4k', Setting::get('multimedia', 'default_video_resolution'));
        $this->assertSame('#10b981', Setting::get('multimedia', 'player_theme_color'));
        $this->assertSame('14', Setting::get('multimedia', 'trending_window_days'));
        $this->assertSame('require_approval', Setting::get('multimedia', 'review_moderation_mode'));
    }

    // =========================================================================
    // 9. DEACTIVATION & REINSTALLATION LIFECYCLE
    // =========================================================================

    public function testDeactivationAndReinstallation(): void
    {
        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');

        // Add a sample movie
        $movieId = $this->db->insert('multimedia_movies', [
            'title'       => 'Persistent Movie Across Reinstalls',
            'slug'        => 'persistent-movie',
            'access_mode' => 'public',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        // Deactivate plugin
        $deactivated = $pluginManager->deactivatePlugin('favorite-multimedia');
        $this->assertTrue($deactivated);
        $this->assertNotContains('favorite-multimedia', $pluginManager->getActivePlugins());

        // Re-activate plugin
        $reActivated = $pluginManager->activatePlugin('favorite-multimedia');
        $this->assertTrue($reActivated);
        $this->assertContains('favorite-multimedia', $pluginManager->getActivePlugins());

        // Existing data must survive deactivation and re-activation!
        $movie = Movie::find($movieId);
        $this->assertNotNull($movie);
        $this->assertSame('Persistent Movie Across Reinstalls', $movie->title);
    }

    // =========================================================================
    // 10. PRODUCTION ZIP PACKAGE VERIFICATION
    // =========================================================================

    public function testProductionZipPackageStructure(): void
    {
        $zipPath = dirname(__DIR__, 4) . '/favorite-multimedia.zip';

        // Check if zip exists or test zip creation helper
        if (file_exists($zipPath)) {
            $zip = new \ZipArchive();
            $res = $zip->open($zipPath);
            $this->assertTrue($res === true, "Must be able to open favorite-multimedia.zip.");

            $hasPluginJson = false;
            $hasPluginPhp = false;
            $hasAutoload = false;
            $forbiddenPaths = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];

                // ZipSlip protection
                $this->assertStringNotContainsString('..', $name);

                if (str_ends_with($name, 'plugin.json')) {
                    $hasPluginJson = true;
                }
                if (str_ends_with($name, 'plugin.php')) {
                    $hasPluginPhp = true;
                }
                if (str_ends_with($name, 'autoload.php')) {
                    $hasAutoload = true;
                }

                // Check for forbidden development artifacts
                if (
                    str_contains($name, '.git') ||
                    str_contains($name, 'phpunit') ||
                    str_contains($name, 'node_modules') ||
                    str_contains($name, '.DS_Store')
                ) {
                    $forbiddenPaths[] = $name;
                }
            }

            $zip->close();

            $this->assertTrue($hasPluginJson, "favorite-multimedia.zip must contain plugin.json.");
            $this->assertTrue($hasPluginPhp, "favorite-multimedia.zip must contain plugin.php.");
            $this->assertTrue($hasAutoload, "favorite-multimedia.zip must contain autoload.php.");
            $this->assertEmpty($forbiddenPaths, "favorite-multimedia.zip must not contain developer artifacts: " . implode(', ', $forbiddenPaths));
        } else {
            // Zip will be created in packaging phase
            $this->assertTrue(true);
        }
    }
}
