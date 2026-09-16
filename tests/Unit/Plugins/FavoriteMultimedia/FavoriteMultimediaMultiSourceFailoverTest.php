<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Services\MediaSourcePlaybackService;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaMultiSourceFailoverTest extends TestCase
{
    private Application $app;
    private Database $db;
    private User $adminUser;
    private string $tempDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $_SESSION = [];
        $_FILES = [];

        $this->tempDbPath = sys_get_temp_dir() . '/test_fmm_multi_' . bin2hex(random_bytes(6)) . '.sqlite';
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

        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');
        FavoriteMultimediaPlugin::bootstrap($this->app);

        $this->setSessionUser($this->adminUser);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_FILES = [];
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

        $adminUserId = $this->db->insert('users', [
            'username'   => 'multisource_admin',
            'name'       => 'MultiSource Admin',
            'email'      => 'admin_multi@example.com',
            'password'   => password_hash('secret', PASSWORD_DEFAULT),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->insert('user_roles', ['user_id' => $adminUserId, 'role_id' => $adminRoleId]);
        $this->adminUser = User::find($adminUserId);
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
    // 1. YouTube & Vimeo Canonical Embed Normalization Tests
    // =========================================================================

    public function testYouTubeUrlNormalizationToNocookieEmbed(): void
    {
        $resolver = new MediaSourceResolver();

        // Standard watch URL
        $res1 = $resolver->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertTrue($res1['valid']);
        $this->assertSame('embed', $res1['source_type']);
        $this->assertSame('embed', $res1['player_type']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $res1['playable_url']);

        // Short URL youtu.be
        $res2 = $resolver->resolve('https://youtu.be/dQw4w9WgXcQ?t=42');
        $this->assertTrue($res2['valid']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $res2['playable_url']);

        // Mobile URL
        $res3 = $resolver->resolve('https://m.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertTrue($res3['valid']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $res3['playable_url']);

        // Already embed URL
        $res4 = $resolver->resolve('https://www.youtube.com/embed/dQw4w9WgXcQ');
        $this->assertTrue($res4['valid']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $res4['playable_url']);

        // Direct detectEmbedService verification
        $svc = $resolver->detectEmbedService('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertNotNull($svc);
        $this->assertSame('youtube', $svc['service']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $svc['embed_url']);
    }

    public function testVimeoUrlNormalizationToPlayerEmbed(): void
    {
        $resolver = new MediaSourceResolver();

        // Standard vimeo URL
        $res1 = $resolver->resolve('https://vimeo.com/76979871');
        $this->assertTrue($res1['valid']);
        $this->assertSame('embed', $res1['source_type']);
        $this->assertSame('embed', $res1['player_type']);
        $this->assertSame('https://player.vimeo.com/video/76979871', $res1['playable_url']);

        // Player URL already
        $res2 = $resolver->resolve('https://player.vimeo.com/video/76979871?title=0');
        $this->assertTrue($res2['valid']);
        $this->assertSame('https://player.vimeo.com/video/76979871', $res2['playable_url']);

        // Direct detectEmbedService verification
        $svc = $resolver->detectEmbedService('https://vimeo.com/76979871');
        $this->assertNotNull($svc);
        $this->assertSame('vimeo', $svc['service']);
        $this->assertSame('https://player.vimeo.com/video/76979871', $svc['embed_url']);
    }

    public function testInvalidHostnamesRejectedForYouTubeAndVimeo(): void
    {
        $resolver = new MediaSourceResolver();

        // Fake youtube domain
        $res1 = $resolver->detectEmbedService('https://fakeyoutube.com/watch?v=12345');
        $this->assertNull($res1);

        // Fake vimeo domain
        $res2 = $resolver->detectEmbedService('https://fakevimeo.com/12345');
        $this->assertNull($res2);
    }

    // =========================================================================
    // 2. Generic External Embed & Domain Allowlist Tests
    // =========================================================================

    public function testEmbedDomainAllowlistValidation(): void
    {
        $resolver = new MediaSourceResolver();

        // Configure trusted domains via Setting::set
        Setting::set('multimedia', 'trusted_embed_domains', "player.vdo.ninja\n*.streamhoster.com\ncdn.embedly.com");
        Setting::clearCache();

        // Exact match
        $this->assertTrue($resolver->isEmbedDomainAllowed('https://player.vdo.ninja/?v=abc'));
        $this->assertTrue($resolver->isEmbedDomainAllowed('https://cdn.embedly.com/widgets/media.html'));

        // Wildcard subdomain match
        $this->assertTrue($resolver->isEmbedDomainAllowed('https://live.streamhoster.com/embed/123'));
        $this->assertTrue($resolver->isEmbedDomainAllowed('https://sub.live.streamhoster.com/embed/123'));

        // Disallowed domain
        $this->assertFalse($resolver->isEmbedDomainAllowed('https://evil-hacker.com/malicious.html'));
        $this->assertFalse($resolver->isEmbedDomainAllowed('https://streamhoster.com.evil.com/embed'));

        // Private / loopback IPs must be blocked
        $this->assertFalse($resolver->isEmbedDomainAllowed('http://127.0.0.1/admin'));
        $this->assertFalse($resolver->isEmbedDomainAllowed('http://localhost:8080/embed'));
        $this->assertFalse($resolver->isEmbedDomainAllowed('http://192.168.1.1/video'));
        $this->assertFalse($resolver->isEmbedDomainAllowed('http://10.0.0.1/stream'));
    }

    public function testResolveEmbedTypeWithAllowlist(): void
    {
        $resolver = new MediaSourceResolver();

        Setting::set('multimedia', 'trusted_embed_domains', "trusted-cdn.com");
        Setting::clearCache();

        // Allowed embed URL
        $resAllowed = $resolver->resolve('https://trusted-cdn.com/embed/movie-123', 'url', 'embed');
        $this->assertTrue($resAllowed['valid']);
        $this->assertSame('embed', $resAllowed['source_type']);
        $this->assertSame('https://trusted-cdn.com/embed/movie-123', $resAllowed['playable_url']);

        // Disallowed embed URL
        $resDenied = $resolver->resolve('https://untrusted-site.org/player', 'url', 'embed');
        $this->assertFalse($resDenied['valid']);
        $this->assertStringContainsString('not in the trusted allowlist', $resDenied['error']);
    }

    // =========================================================================
    // 3. MediaSource Single Default Enforcement & Fallback Promotion
    // =========================================================================

    public function testSingleDefaultEnforcement(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Default Source Test Movie',
            'slug'        => 'default-source-test',
            'status'      => 'published',
            'access_mode' => 'public',
            'created_at'  => $now,
        ]);

        $src1 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'label'        => 'Source 1 (Initial Default)',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/video1.mp4',
            'is_default'   => 1,
            'status'       => 'active',
            'sort_order'   => 0,
        ]);

        $src2 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'hls',
            'label'        => 'Source 2 (New Default)',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/stream.m3u8',
            'is_default'   => 0,
            'status'       => 'active',
            'sort_order'   => 1,
        ]);

        // Enforce src2 as single default
        MediaSource::enforceSingleDefault('movie', $movieId, (int)$src2->id);

        $reloaded1 = MediaSource::find((int)$src1->id);
        $reloaded2 = MediaSource::find((int)$src2->id);

        $this->assertSame(0, (int)$reloaded1->is_default);
        $this->assertSame(1, (int)$reloaded2->is_default);
    }

    public function testPromoteNextDefaultWhenDefaultRemovedOrDisabled(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Promotion Test Movie',
            'slug'        => 'promotion-test',
            'status'      => 'published',
            'access_mode' => 'public',
            'created_at'  => $now,
        ]);

        $src1 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'label'        => 'Source 1 (Disabled)',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/v1.mp4',
            'is_default'   => 0,
            'status'       => 'inactive',
            'sort_order'   => 0,
        ]);

        $src2 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'youtube',
            'label'        => 'Source 2 (Active)',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'is_default'   => 0,
            'status'       => 'active',
            'sort_order'   => 1,
        ]);

        $src3 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'vimeo',
            'label'        => 'Source 3 (Active)',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://vimeo.com/76979871',
            'is_default'   => 0,
            'status'       => 'active',
            'sort_order'   => 2,
        ]);

        // Promote next default
        MediaSource::promoteNextDefault('movie', $movieId);

        $reloaded2 = MediaSource::find((int)$src2->id);
        $reloaded3 = MediaSource::find((int)$src3->id);

        $this->assertSame(1, (int)$reloaded2->is_default, 'Source 2 should have been promoted to default');
        $this->assertSame(0, (int)$reloaded3->is_default);
    }

    // =========================================================================
    // 4. MediaSourcePlaybackService Gating & Payload Normalization
    // =========================================================================

    public function testMediaSourcePlaybackServiceReturnsNormalizedSources(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Playback Service Movie',
            'slug'        => 'playback-service-movie',
            'status'      => 'published',
            'access_mode' => 'public',
            'created_at'  => $now,
        ]);

        $movie = Movie::find($movieId);

        // Add 3 sources: 1 HLS (default), 1 Direct MP4, 1 Inactive Embed
        MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'hls',
            'label'        => 'Primary HLS',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/live/master.m3u8',
            'is_default'   => 1,
            'status'       => 'active',
            'sort_order'   => 0,
        ]);

        MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'label'        => 'Backup 1080p MP4',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/fallback.mp4',
            'is_default'   => 0,
            'status'       => 'active',
            'sort_order'   => 1,
        ]);

        MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'embed',
            'label'        => 'Disabled Embed',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://partner.com/embed/123',
            'is_default'   => 0,
            'status'       => 'inactive',
            'sort_order'   => 2,
        ]);

        $playbackData = MediaSourcePlaybackService::getPlayableSources($this->adminUser, 'movie', $movie);

        $this->assertTrue($playbackData['allowed']);
        $this->assertSame(MultimediaAccessService::ALLOW, $playbackData['access']);

        $playbackSources = $playbackData['sources'];

        // Inactive source must be excluded -> count should be 2
        $this->assertCount(2, $playbackSources);

        // Verify structure of first source (default HLS)
        $s1 = $playbackSources[0];
        $this->assertSame('Primary HLS', $s1['label']);
        $this->assertSame('hls', $s1['source_type']);
        $this->assertSame('hls', $s1['player_type']);
        $this->assertTrue($s1['is_default']);
        $this->assertTrue($s1['can_auto_failover']);

        // Verify structure of second source (MP4)
        $s2 = $playbackSources[1];
        $this->assertSame('Backup 1080p MP4', $s2['label']);
        $this->assertSame('url', $s2['source_type']);
        $this->assertSame('video', $s2['player_type']);
        $this->assertFalse($s2['is_default']);
        $this->assertTrue($s2['can_auto_failover']);
    }

    public function testMediaSourcePlaybackServiceFailsClosedOnUnauthorizedAccess(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Premium Exclusive Movie',
            'slug'        => 'premium-movie',
            'status'      => 'published',
            'access_mode' => 'premium',
            'created_at'  => $now,
        ]);

        $movie = Movie::find($movieId);

        MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'label'        => 'Secret Premium Stream',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/premium.mp4',
            'is_default'   => 1,
            'status'       => 'active',
            'sort_order'   => 0,
        ]);

        // Unauthenticated guest user
        $this->setSessionUser(null);

        $playbackData = MediaSourcePlaybackService::getPlayableSources(null, 'movie', $movie);
        $this->assertFalse($playbackData['allowed']);
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, $playbackData['access']);
        $this->assertEmpty($playbackData['sources'], 'Access check must fail-closed for unauthorized guest on premium content');
    }

    // =========================================================================
    // 5. MultimediaAdminController Multi-Source Management Actions
    // =========================================================================

    public function testAdminReorderSources(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Admin Reorder Movie',
            'slug'        => 'admin-reorder-movie',
            'status'      => 'draft',
            'access_mode' => 'public',
            'created_at'  => $now,
        ]);

        $src1 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'label'        => 'Source Alpha',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/alpha.mp4',
            'sort_order'   => 0,
            'status'       => 'active',
        ]);

        $src2 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'label'        => 'Source Beta',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/beta.mp4',
            'sort_order'   => 1,
            'status'       => 'active',
        ]);

        $controller = new MultimediaAdminController($this->app);

        // Move Beta UP
        $postData = [
            'action'    => 'reorder',
            'source_id' => $src2->id,
            'direction' => 'up',
            '_token'    => 'valid_test_csrf_token',
        ];
        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $response = $controller->sources($request);

        $this->assertSame(302, $response->getStatusCode());

        $reloaded1 = MediaSource::find((int)$src1->id);
        $reloaded2 = MediaSource::find((int)$src2->id);

        $this->assertSame(1, (int)$reloaded1->sort_order);
        $this->assertSame(0, (int)$reloaded2->sort_order);
    }

    public function testAdminToggleSourceStatus(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Toggle Status Movie',
            'slug'        => 'toggle-status-movie',
            'status'      => 'draft',
            'access_mode' => 'public',
            'created_at'  => $now,
        ]);

        $src = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'label'        => 'Toggle Source',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/video.mp4',
            'status'       => 'active',
            'is_default'   => 1,
            'sort_order'   => 0,
        ]);

        $controller = new MultimediaAdminController($this->app);

        // Toggle to inactive
        $postData = [
            'action'    => 'toggle_status',
            'source_id' => $src->id,
            '_token'    => 'valid_test_csrf_token',
        ];
        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $response = $controller->sources($request);

        $this->assertSame(302, $response->getStatusCode());

        $reloaded = MediaSource::find((int)$src->id);
        $this->assertSame('inactive', $reloaded->status);
    }

    public function testAdminSetDefaultSource(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Set Default Movie',
            'slug'        => 'set-default-movie',
            'status'      => 'draft',
            'access_mode' => 'public',
            'created_at'  => $now,
        ]);

        $src1 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'label'        => 'Source 1',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/1.mp4',
            'status'       => 'active',
            'is_default'   => 1,
            'sort_order'   => 0,
        ]);

        $src2 = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'url',
            'source_mode'  => 'url',
            'url_or_path'  => 'https://example.com/2.mp4',
            'status'       => 'active',
            'is_default'   => 0,
            'sort_order'   => 1,
        ]);

        $controller = new MultimediaAdminController($this->app);

        // Make Source 2 default
        $postData = [
            'action'    => 'set_default',
            'source_id' => $src2->id,
            '_token'    => 'valid_test_csrf_token',
        ];
        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $response = $controller->sources($request);

        $this->assertSame(302, $response->getStatusCode());

        $reloaded1 = MediaSource::find((int)$src1->id);
        $reloaded2 = MediaSource::find((int)$src2->id);

        $this->assertSame(0, (int)$reloaded1->is_default);
        $this->assertSame(1, (int)$reloaded2->is_default);
    }

    public function testAdminSettingsSavesTrustedEmbedDomains(): void
    {
        $controller = new MultimediaAdminController($this->app);

        $postData = [
            'trusted_embed_domains' => "cdn.streamhoster.com\nplayer.vimeo.com\n*.partnercdn.org",
            '_token'                => 'valid_test_csrf_token',
        ];
        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $response = $controller->settings($request);

        $this->assertSame(302, $response->getStatusCode());

        $setting = Setting::get('multimedia', 'trusted_embed_domains', '');
        $this->assertStringContainsString('cdn.streamhoster.com', $setting);
        $this->assertStringContainsString('*.partnercdn.org', $setting);
    }
}
