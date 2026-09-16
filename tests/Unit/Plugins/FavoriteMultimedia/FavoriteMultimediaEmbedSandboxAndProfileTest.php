<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Http\Controllers\Admin\UserController;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Integrations\FavoritePayAdapter;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MediaSourcePlaybackService;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Combined Runtime Hardening:
 * 1. External Embed AdBlock/Sandbox Playback Fix
 * 2. /admin/users/profile 500 Regression Fix
 */
class FavoriteMultimediaEmbedSandboxAndProfileTest extends TestCase
{
    private Application $app;
    private Database $db;
    private string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_embed_prof_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->app = new Application(APP_ROOT);
        Application::setInstance($this->app);
        Container::setInstance($this->app);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $config = new Config([]);
        $this->app->instance(Config::class, $config);
        $this->app->instance('config', $config);
        $this->app->instance(Database::class, $this->db);
        $this->app->instance('db', $this->db);

        $this->createSchema($pdo);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_SESSION['_token'] = 'embed_prof_csrf_test_token';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function createSchema(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            username VARCHAR(50),
            name VARCHAR(100),
            email VARCHAR(100),
            password VARCHAR(255),
            status VARCHAR(20) DEFAULT 'active',
            role VARCHAR(50) DEFAULT 'subscriber',
            bio TEXT,
            email_verified_at DATETIME,
            created_at DATETIME,
            updated_at DATETIME
        );");
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY,
            name VARCHAR(50),
            slug VARCHAR(50),
            description TEXT,
            created_at DATETIME,
            updated_at DATETIME
        );");
        $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            slug VARCHAR(100),
            description TEXT,
            group_name VARCHAR(50),
            created_at DATETIME,
            updated_at DATETIME
        );");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
            user_id INTEGER,
            role_id INTEGER,
            PRIMARY KEY (user_id, role_id)
        );");
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER,
            permission_id INTEGER,
            created_at DATETIME,
            PRIMARY KEY (role_id, permission_id)
        );");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY,
            group_name VARCHAR(50),
            setting_key VARCHAR(50),
            value TEXT,
            type VARCHAR(20),
            is_public INTEGER DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            UNIQUE (group_name, setting_key)
        );");
        $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            title VARCHAR(255),
            content TEXT,
            type VARCHAR(20) DEFAULT 'post',
            status VARCHAR(20) DEFAULT 'published',
            published_at DATETIME,
            created_at DATETIME,
            updated_at DATETIME
        );");
        $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY,
            post_id INTEGER,
            user_id INTEGER,
            content TEXT,
            status VARCHAR(20) DEFAULT 'approved',
            created_at DATETIME,
            updated_at DATETIME
        );");

        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $pdo->exec("INSERT INTO roles (id, name, slug) VALUES 
            (1, 'Super Admin', 'super-admin'),
            (2, 'Administrator', 'admin'),
            (3, 'Editor', 'editor'),
            (4, 'Moderator', 'moderator'),
            (5, 'Author', 'author'),
            (6, 'Subscriber', 'subscriber')");

        $now = date('Y-m-d H:i:s');
        $users = [
            [1, 'superadmin', 'super@example.com', 'super-admin', 1],
            [2, 'adminuser',  'admin@example.com', 'admin', 2],
            [3, 'editoruser', 'editor@example.com', 'editor', 3],
            [4, 'moduser',    'mod@example.com',   'moderator', 4],
            [5, 'authoruser', 'author@example.com', 'author', 5],
            [6, 'subuser',    'sub@example.com',   'subscriber', 6],
        ];

        foreach ($users as $u) {
            $pdo->exec("INSERT INTO users (id, username, name, email, status, role, created_at, updated_at) 
                        VALUES ({$u[0]}, '{$u[1]}', '{$u[1]} Name', '{$u[2]}', 'active', '{$u[3]}', '{$now}', '{$now}')");
            $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES ({$u[0]}, {$u[4]})");
        }
    }

    // =========================================================================
    // PART A: Tests 1-12 (Player, Sandbox, Policy, AdBlock Resilience)
    // =========================================================================

    /**
     * Test 1: Unknown embed provider defaults to strict sandbox.
     */
    public function test01_unknownEmbedProviderDefaultsToStrictSandbox(): void
    {
        $url = 'https://unknown-embed-service.org/embed/vid123';
        $policy = MediaSourceResolver::getEmbedSandboxPolicy($url, 'compatible');
        
        $this->assertFalse(MediaSourceResolver::isTrustedEmbedDomain($url));
        $this->assertSame('allow-scripts allow-same-origin allow-forms', $policy);
    }

    /**
     * Test 2: Trusted embed provider gets compatible sandbox.
     */
    public function test02_trustedEmbedProviderGetsCompatibleSandbox(): void
    {
        $url = 'https://www.youtube.com/embed/dQw4w9WgXcQ';
        $policy = MediaSourceResolver::getEmbedSandboxPolicy($url, 'compatible');

        $this->assertTrue(MediaSourceResolver::isTrustedEmbedDomain($url));
        $this->assertSame(
            'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-presentation',
            $policy
        );
    }

    /**
     * Test 3: Trusted embed provider with optional sandbox disabled emits no sandbox attribute (null).
     */
    public function test03_trustedEmbedProviderWithOptionalSandboxDisabledEmitsNoSandboxAttribute(): void
    {
        $url = 'https://player.vimeo.com/video/12345678';
        $policy = MediaSourceResolver::getEmbedSandboxPolicy($url, 'off-for-trusted-only');

        $this->assertTrue(MediaSourceResolver::isTrustedEmbedDomain($url));
        $this->assertNull($policy);
    }

    /**
     * Test 4: Untrusted embed provider cannot request no-sandbox (always locked to strict sandbox).
     */
    public function test04_untrustedEmbedProviderCannotRequestNoSandbox(): void
    {
        $url = 'https://sketchy-untrusted-site.co/embed/test';
        $policy = MediaSourceResolver::getEmbedSandboxPolicy($url, 'off-for-trusted-only');

        $this->assertFalse(MediaSourceResolver::isTrustedEmbedDomain($url));
        // Even when 'off-for-trusted-only' is requested, untrusted gets strict sandbox, never null
        $this->assertSame('allow-scripts allow-same-origin allow-forms', $policy);
    }

    /**
     * Test 5: Feature policy allow attribute includes required directives.
     */
    public function test05_featurePolicyAllowAttributeIncludesRequiredDirectives(): void
    {
        $allow = MediaSourceResolver::getEmbedAllowAttribute();

        $this->assertStringContainsString('autoplay', $allow);
        $this->assertStringContainsString('fullscreen', $allow);
        $this->assertStringContainsString('encrypted-media', $allow);
        $this->assertStringContainsString('picture-in-picture', $allow);
    }

    /**
     * Test 6: Domain matching works for exact domain, subdomain, path URLs, and invalid domains.
     */
    public function test06_domainMatchingWorksForExactSubdomainPathAndInvalidDomains(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', "streamtape.com\ncdn.example.org");
        Setting::clearCache();

        // Exact match
        $this->assertTrue(MediaSourceResolver::isTrustedEmbedDomain('https://streamtape.com/e/abc123'));
        // Subdomain match
        $this->assertTrue(MediaSourceResolver::isTrustedEmbedDomain('https://sub.streamtape.com/e/abc123'));
        // Deep path match
        $this->assertTrue(MediaSourceResolver::isTrustedEmbedDomain('https://cdn.example.org/player/v2/stream?id=99'));
        // Phishing / suffix domain mismatch
        $this->assertFalse(MediaSourceResolver::isTrustedEmbedDomain('https://fake-streamtape.com/e/abc123'));
        // SSRF: localhost and direct IPs must be rejected even if entered
        $this->assertFalse(MediaSourceResolver::isTrustedEmbedDomain('http://localhost/embed'));
        $this->assertFalse(MediaSourceResolver::isTrustedEmbedDomain('http://127.0.0.1/embed'));
        $this->assertFalse(MediaSourceResolver::isTrustedEmbedDomain('http://192.168.1.100/embed'));
        $this->assertFalse(MediaSourceResolver::isTrustedEmbedDomain('http://[::1]/embed'));
    }

    /**
     * Test 7: JavaScript / data URI embed source is rejected safely.
     */
    public function test07_javascriptOrDataUriEmbedSourceIsRejectedSafely(): void
    {
        $resJs = MediaSourceResolver::validateUrlSecurity('javascript:alert(1)');
        $this->assertFalse($resJs['safe']);
        $this->assertStringContainsString('rejected', $resJs['reason']);

        $resData = MediaSourceResolver::validateUrlSecurity('data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==');
        $this->assertFalse($resData['safe']);
        $this->assertStringContainsString('rejected', $resData['reason']);

        $resInjection = MediaSourceResolver::validateUrlSecurity('https://example.com/embed" onload="alert(1)');
        $this->assertFalse($resInjection['safe']);
    }

    /**
     * Test 8: External embed player error/failure shows clean fallback UX card.
     */
    public function test08_externalEmbedPlayerErrorFailureShowsCleanFallbackUxCard(): void
    {
        $playerJsPath = APP_ROOT . '/plugins/favorite-multimedia/assets/js/multimedia-player.js';
        $this->assertFileExists($playerJsPath);
        $jsContent = file_get_contents($playerJsPath);

        $this->assertStringContainsString('showEmbedFallback', $jsContent);
        $this->assertStringContainsString('External Player Unavailable', $jsContent);
        $this->assertStringContainsString('Retry Player', $jsContent);
        $this->assertStringContainsString('Switch Source', $jsContent);
        $this->assertStringContainsString('Open Provider Page', $jsContent);
    }

    /**
     * Test 9: Fallback retry button retries safely with cache buster and rate limiting.
     */
    public function test09_fallbackRetryButtonRetriesSafely(): void
    {
        $playerJsPath = APP_ROOT . '/plugins/favorite-multimedia/assets/js/multimedia-player.js';
        $jsContent = file_get_contents($playerJsPath);

        $this->assertStringContainsString('retryEmbedPlayer', $jsContent);
        $this->assertStringContainsString('retry_fmm', $jsContent);
    }

    /**
     * Test 10: Fallback switch source button selects alternative source.
     */
    public function test10_fallbackSwitchSourceButtonSelectsAlternativeSource(): void
    {
        $playerJsPath = APP_ROOT . '/plugins/favorite-multimedia/assets/js/multimedia-player.js';
        $jsContent = file_get_contents($playerJsPath);

        $this->assertStringContainsString('switchPlaybackSource', $jsContent);
        $this->assertStringContainsString('fav-btn-switch-stream', $jsContent);
    }

    /**
     * Test 11: No ad blocker bypass, filter hiding, or anti-adblock circumvention executed.
     */
    public function test11_noAdBlockerBypassOrCircumventionExecuted(): void
    {
        $playerJsPath = APP_ROOT . '/plugins/favorite-multimedia/assets/js/multimedia-player.js';
        $jsContent = file_get_contents($playerJsPath);

        // Verification: benign detector checks element presence only, no DOM manipulation or anti-adblock bypass scripts
        $this->assertStringContainsString('isContentBlockerActive', $jsContent);
        $this->assertStringNotContainsString('adblock_killer', $jsContent);
        $this->assertStringNotContainsString('bypassAdBlock', $jsContent);
        $this->assertStringNotContainsString('circumvent', $jsContent);
    }

    /**
     * Test 12: Direct MP4/HLS native playback completely unaffected by sandbox rules.
     */
    public function test12_directMp4HlsNativePlaybackCompletelyUnaffectedBySandboxRules(): void
    {
        $mp4Url = 'https://example.com/videos/sample.mp4';
        $resolvedMp4 = MediaSourceResolver::resolve($mp4Url);
        $this->assertSame('video', $resolvedMp4['source_type']);
        $this->assertSame('video', $resolvedMp4['player_type']);
        $this->assertFalse($resolvedMp4['source_type'] === 'embed');

        $hlsUrl = 'https://example.com/videos/master.m3u8';
        $resolvedHls = MediaSourceResolver::resolve($hlsUrl);
        $this->assertSame('hls', $resolvedHls['source_type']);
        $this->assertSame('video', $resolvedHls['player_type']);
        $this->assertFalse($resolvedHls['source_type'] === 'embed');

        // Normalization returns null sandbox policy for direct video types
        $now = date('Y-m-d H:i:s');
        $mId = (int)$this->db->insert('multimedia_movies', [
            'title'        => 'Direct Video Movie',
            'slug'         => 'direct-video-movie',
            'status'       => 'published',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => $mp4Url,
            'label'        => 'Direct Server 1',
            'is_default'   => 1,
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $playable = MediaSourcePlaybackService::getPlayableSources(null, 'movie', $mId);
        $this->assertTrue($playable['allowed']);
        $this->assertCount(1, $playable['sources']);
        $this->assertSame('video', $playable['sources'][0]['player_type']);
        $this->assertNull($playable['sources'][0]['sandbox_policy']);
    }

    // =========================================================================
    // PART B: Tests 13-24 (Profile 500 Regression & Role Safety)
    // =========================================================================

    private function simulateProfileRequestForUser(int $userId): Response
    {
        $_SESSION['auth_user_id'] = $userId;
        $user = User::find($userId);
        $_SESSION['auth_user_name'] = $user->username;
        $_SESSION['auth_user_email'] = $user->email;

        // Set up AdminMenu for this request
        \FavoriteCMS\Core\AdminMenu::reset();
        \FavoriteCMS\Core\AdminMenu::addMenu('dashboard', 'Dashboard', 'dashboard', null, 'manage_options', 10);
        \FavoriteCMS\Core\AdminMenu::addMenu('profile', 'Profile', 'user', null, 'view', 20);
        \FavoriteCMS\Core\AdminMenu::addMenu('multimedia', 'Multimedia', '🎬', null, 'multimedia_create', 50);
        \FavoriteCMS\Core\AdminMenu::addSubMenu('multimedia', 'multimedia-movies', 'Movies', null, 'multimedia_create');
        \FavoriteCMS\Core\AdminMenu::addSubMenu('multimedia', 'multimedia-my-submissions', 'My Submissions', null, 'multimedia_create');
        \FavoriteCMS\Core\AdminMenu::addSubMenu('multimedia', 'multimedia-analytics', 'Analytics', null, 'multimedia_view_analytics');

        // Ensure enableSidebarTitle executes safely for this user
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = \FavoriteCMS\Core\AdminMenu::getMenus();
        $this->assertIsArray($menus);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $userController = new UserController($this->app);
        return $userController->profile($request);
    }

    /**
     * Test 13: Super Admin accesses /admin/users/profile -> 200 OK.
     */
    public function test13_superAdminAccessesUsersProfileReturns200(): void
    {
        $response = $this->simulateProfileRequestForUser(1); // superadmin
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Profile', $response->getContent());
    }

    /**
     * Test 14: Admin accesses /admin/users/profile -> 200 OK.
     */
    public function test14_adminAccessesUsersProfileReturns200(): void
    {
        $response = $this->simulateProfileRequestForUser(2); // admin
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Profile', $response->getContent());
    }

    /**
     * Test 15: Editor accesses /admin/users/profile -> 200 OK.
     */
    public function test15_editorAccessesUsersProfileReturns200(): void
    {
        $response = $this->simulateProfileRequestForUser(3); // editor
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Profile', $response->getContent());
    }

    /**
     * Test 16: Moderator accesses /admin/users/profile -> 200 OK.
     */
    public function test16_moderatorAccessesUsersProfileReturns200(): void
    {
        $response = $this->simulateProfileRequestForUser(4); // moderator
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Profile', $response->getContent());
    }

    /**
     * Test 17: Author accesses /admin/users/profile -> 200 OK.
     */
    public function test17_authorAccessesUsersProfileReturns200(): void
    {
        $response = $this->simulateProfileRequestForUser(5); // author
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Profile', $response->getContent());
    }

    /**
     * Test 18: Subscriber accesses /admin/users/profile -> 200 OK.
     */
    public function test18_subscriberAccessesUsersProfileReturns200(): void
    {
        $response = $this->simulateProfileRequestForUser(6); // subscriber
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Profile', $response->getContent());
    }

    /**
     * Test 19: Null/unauthenticated user during bootstrap/CLI does not crash menu/profile logic.
     */
    public function test19_nullUnauthenticatedUserDuringBootstrapDoesNotCrash(): void
    {
        $_SESSION = []; // No user logged in

        $this->assertNull(MultimediaPermission::resolveUser(null));
        $this->assertFalse(MultimediaPermission::isSuspendedUser(null));
        $this->assertFalse(MultimediaPermission::hasUserRole(null, 'admin'));
        $this->assertFalse(MultimediaPermission::can('view', null));

        \FavoriteCMS\Core\AdminMenu::reset();
        \FavoriteCMS\Core\AdminMenu::addMenu('multimedia', 'Multimedia', '🎬');

        // Should execute cleanly without error
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = \FavoriteCMS\Core\AdminMenu::getMenus();
        $this->assertIsArray($menus);
    }

    /**
     * Test 20: Partial user object missing roles/permissions does not crash menu/profile logic.
     */
    public function test20_partialUserObjectMissingRolesPermissionsDoesNotCrash(): void
    {
        $partialUser = (object)[
            'id' => 888,
            'name' => 'Partial User',
            // No hasRole(), no role property, no roles array
        ];

        $this->assertFalse(MultimediaPermission::hasUserRole($partialUser, 'admin'));
        $this->assertFalse(MultimediaPermission::isSuspendedUser($partialUser));
        $this->assertFalse(MultimediaPermission::can('manage_settings', $partialUser));
    }

    /**
     * Test 21: Favorite Digital plugin tables missing does not crash profile or multimedia.
     */
    public function test21_missingFavoriteDigitalTableDoesNotCrash(): void
    {
        // Table `favorite_digital_memberships` does NOT exist in our tempDb
        $this->assertFalse(FavoriteDigitalAdapter::isAvailable());
        $this->assertFalse(FavoriteDigitalAdapter::userHasEntitlement(1, 'movie', 1));
        $details = FavoriteDigitalAdapter::getMembershipDetails(1);
        $this->assertIsArray($details);
        $this->assertFalse($details['available']);
        $this->assertFalse($details['has_active']);
    }

    /**
     * Test 22: Favorite Pay plugin missing or payment service down does not crash profile or multimedia.
     */
    public function test22_favoritePayUnavailableDoesNotCrash(): void
    {
        $adapter = new FavoritePayAdapter();
        $this->assertFalse($adapter->isAvailable());
        $this->assertNull($adapter->getPaymentService());
    }

    /**
     * Test 23: Favorite CMS menu structure missing 'multimedia' key handled gracefully.
     */
    public function test23_missingMultimediaMenuKeyHandledGracefully(): void
    {
        \FavoriteCMS\Core\AdminMenu::reset();
        \FavoriteCMS\Core\AdminMenu::addMenu('dashboard', 'Dashboard', 'dashboard');
        \FavoriteCMS\Core\AdminMenu::addMenu('posts', 'Posts', 'posts');

        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = \FavoriteCMS\Core\AdminMenu::getMenus();
        $this->assertIsArray($menus);
        $this->assertArrayNotHasKey('multimedia', $menus);
    }

    /**
     * Test 24: Author Creator Menu items coexist cleanly without breaking core profile route.
     */
    public function test24_authorCreatorMenuItemsCoexistCleanlyWithProfile(): void
    {
        $_SESSION['auth_user_id'] = 5; // author
        $user = User::find(5);
        $_SESSION['auth_user_name'] = $user->username;
        $_SESSION['auth_user_email'] = $user->email;

        \FavoriteCMS\Core\AdminMenu::reset();
        \FavoriteCMS\Core\AdminMenu::addMenu('dashboard', 'Dashboard', 'dashboard', null, 'manage_options', 10);
        \FavoriteCMS\Core\AdminMenu::addMenu('profile', 'Profile', 'user', null, 'view', 20);
        \FavoriteCMS\Core\AdminMenu::addMenu('multimedia', 'Multimedia', '🎬', null, 'multimedia_create', 50);
        \FavoriteCMS\Core\AdminMenu::addSubMenu('multimedia', 'multimedia-movies', 'Movies', null, 'multimedia_create');
        \FavoriteCMS\Core\AdminMenu::addSubMenu('multimedia', 'multimedia-my-submissions', 'My Submissions', null, 'multimedia_create');
        \FavoriteCMS\Core\AdminMenu::addSubMenu('multimedia', 'multimedia-analytics', 'Analytics', null, 'multimedia_view_analytics');

        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = \FavoriteCMS\Core\AdminMenu::getMenus();

        // Core profile menu is intact
        $this->assertArrayHasKey('profile', $menus);
        $this->assertSame('profile', $menus['profile']['slug']);

        // Author multimedia submenus are scoped to creator items
        $this->assertArrayHasKey('multimedia', $menus);
        $rawSubs = $menus['multimedia']['submenus'];
        $this->assertInstanceOf(\FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection::class, $rawSubs);

        // Sidebar visible submenus show My Submissions
        $visibleSubs = $rawSubs->getVisible();
        $this->assertArrayHasKey('multimedia-my-submissions', $visibleSubs);

        // Routing access is preserved via collection ArrayAccess for analytics
        $this->assertTrue(isset($rawSubs['multimedia-analytics']));
        $allSubs = $rawSubs->getAll();
        $this->assertArrayHasKey('multimedia-analytics', $allSubs);
    }

    /**
     * Test 25: Subscriber creator submission MUST remain blocked even with legacy allow_subscriber_submissions = 'yes'.
     * The LOCKED access matrix strictly denies creator dashboard, my submissions, and uploads to subscribers.
     */
    public function test25_subscriberCreatorSubmissionBlockedEvenWithLegacyConfigOn(): void
    {
        $subscriber = User::find(6);
        $this->assertSame('subscriber', $subscriber->role);

        // Case A: Legacy config OFF
        Setting::set('multimedia', 'allow_subscriber_submissions', 'no');
        Setting::clearCache();

        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::CREATE, $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit(null, $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit('movie', $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit('series', $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit('song', $subscriber));

        // Case B: Legacy config ON
        Setting::set('multimedia', 'allow_subscriber_submissions', 'yes');
        Setting::clearCache();

        // Locked matrix MUST STILL WIN: subscriber has zero creator privileges
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::CREATE, $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit(null, $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit('movie', $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit('series', $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit('song', $subscriber));

        // Verify sidebar menu behavior: multimedia menu is completely stripped for Subscriber
        $_SESSION['auth_user_id'] = 6; // subscriber
        \FavoriteCMS\Core\AdminMenu::reset();
        \FavoriteCMS\Core\AdminMenu::addMenu('dashboard', 'Dashboard', 'dashboard', null, 'manage_options', 10);
        \FavoriteCMS\Core\AdminMenu::addMenu('profile', 'Profile', 'user', null, 'view', 20);
        \FavoriteCMS\Core\AdminMenu::addMenu('multimedia', 'Multimedia', '🎬', null, 'multimedia_create', 50);

        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = \FavoriteCMS\Core\AdminMenu::getMenus();

        $this->assertArrayHasKey('profile', $menus);
        $this->assertArrayNotHasKey('multimedia', $menus);
    }

    /**
     * Test 26: Unknown provider requires explicit administrator allowlisting before receiving trusted sandbox policy.
     */
    public function test26_administratorCustomTrustedDomainBehavior(): void
    {
        $untrustedUrl = 'https://streamtape.com/e/custom123';

        // 1. Without administrator allowlisting, external stream provider is NOT trusted by default
        Setting::set('multimedia', 'trusted_embed_domains', '');
        Setting::clearCache();

        $this->assertFalse(MediaSourceResolver::isTrustedEmbedDomain($untrustedUrl));
        // Strict sandbox enforced, cannot bypass even if off-for-trusted-only requested
        $this->assertSame('allow-scripts allow-same-origin allow-forms', MediaSourceResolver::getEmbedSandboxPolicy($untrustedUrl, 'off-for-trusted-only'));
        $this->assertSame('allow-scripts allow-same-origin allow-forms', MediaSourceResolver::getEmbedSandboxPolicy($untrustedUrl, 'compatible'));

        // 2. Administrator explicitly adds streamtape.com to trusted_embed_domains
        Setting::set('multimedia', 'trusted_embed_domains', "streamtape.com\nsuperembed.stream");
        Setting::clearCache();

        $this->assertTrue(MediaSourceResolver::isTrustedEmbedDomain($untrustedUrl));
        // Compatible sandbox granted
        $this->assertSame(
            'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-presentation',
            MediaSourceResolver::getEmbedSandboxPolicy($untrustedUrl, 'compatible')
        );
        // Off-for-trusted-only mode allows omitting sandbox
        $this->assertNull(MediaSourceResolver::getEmbedSandboxPolicy($untrustedUrl, 'off-for-trusted-only'));
    }
}

