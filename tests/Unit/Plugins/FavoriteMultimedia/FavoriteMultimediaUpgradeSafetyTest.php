<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for v1.0.5 Upgrade Safety & Existing Installation Self-Healing.
 */
class FavoriteMultimediaUpgradeSafetyTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaAdminController $adminCtrl;
    private string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $_SESSION = [];
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'admin';
        $_SESSION['auth_user_email'] = 'admin@example.com';
        $_SESSION['_token'] = 'valid_test_token';

        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_multimedia_upgrade_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        // Create core auth & settings tables
        $this->db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(50), name VARCHAR(100), email VARCHAR(100), password VARCHAR(255), status VARCHAR(20), email_verified_at DATETIME, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE permissions (id INTEGER PRIMARY KEY, name VARCHAR(100), slug VARCHAR(100), description TEXT, group_name VARCHAR(50), created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));");
        $this->db->execute("CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, created_at DATETIME, PRIMARY KEY (role_id, permission_id));");
        $this->db->execute("CREATE TABLE settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");

        $now = gmdate('Y-m-d H:i:s');
        $rId = $this->db->insert('roles', ['name' => 'Admin', 'slug' => 'admin', 'created_at' => $now, 'updated_at' => $now]);
        $uId = $this->db->insert('users', ['username' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('user_roles', ['user_id' => $uId, 'role_id' => $rId]);

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        $pm = new PluginManager($this->app);
        $pm->activatePlugin('favorite-multimedia');
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->adminCtrl = $this->app->make(MultimediaAdminController::class);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    public function testFreshInstallBootstrapsDefaultSettings(): void
    {
        // Delete all multimedia settings to simulate pre-bootstrap state
        $this->db->execute("DELETE FROM settings WHERE group_name = 'multimedia'");
        Setting::clearCache();

        $this->assertNull(Setting::get('multimedia', 'trusted_embed_domains', null));

        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);

        // Verify all required default settings are bootstrapped
        $this->assertSame('', Setting::get('multimedia', 'trusted_embed_domains'));
        $this->assertSame('yes', Setting::get('multimedia', 'enable_downloads'));
        $this->assertSame('1080p', Setting::get('multimedia', 'default_video_resolution'));
        $this->assertSame('#2563eb', Setting::get('multimedia', 'player_theme_color'));
        $this->assertSame('yes', Setting::get('multimedia', 'enable_discovery'));
        $this->assertSame('yes', Setting::get('multimedia', 'enable_trending'));
        $this->assertSame('7', Setting::get('multimedia', 'trending_window_days'));
        $this->assertSame('10', Setting::get('multimedia', 'max_discovery_items'));
        $this->assertSame('auto_approve', Setting::get('multimedia', 'review_moderation_mode'));
        $this->assertSame('1.0.7', Setting::get('multimedia', 'installed_version'));
        $this->assertSame('1.0.7', Setting::get('multimedia', 'version'));
    }

    public function testUpgradeFromV104WithoutDeactivationReactivation(): void
    {
        // Set existing DB state as v1.0.4 with missing trusted_embed_domains
        $this->db->execute("DELETE FROM settings WHERE group_name = 'multimedia'");
        Setting::clearCache();

        Setting::set('multimedia', 'installed_version', '1.0.4');
        Setting::set('multimedia', 'version', '1.0.4');
        Setting::set('multimedia', 'player_theme_color', '#ff5500');

        $this->assertSame('1.0.4', Setting::get('multimedia', 'installed_version'));
        $this->assertNull(Setting::get('multimedia', 'trusted_embed_domains', null));

        // Simulate new request with upgraded v1.0.6 code WITHOUT calling onActivate() or onDeactivate()
        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);

        // Verify self-healing upgraded version and bootstrapped missing trusted_embed_domains
        $this->assertSame('1.0.7', Setting::get('multimedia', 'installed_version'));
        $this->assertSame('1.0.7', Setting::get('multimedia', 'version'));
        $this->assertSame('', Setting::get('multimedia', 'trusted_embed_domains'));
        // Verify custom pre-existing setting is preserved
        $this->assertSame('#ff5500', Setting::get('multimedia', 'player_theme_color'));
    }

    public function testAlreadyActivePluginWithMissingVersionSetting(): void
    {
        // Simulate older plugin state where installed_version was never tracked
        $this->db->execute("DELETE FROM settings WHERE group_name = 'multimedia'");
        Setting::clearCache();

        // Simulate booting up
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);

        $this->assertSame('1.0.7', Setting::get('multimedia', 'installed_version'));
        $this->assertSame('', Setting::get('multimedia', 'trusted_embed_domains'));
    }

    public function testCustomTrustedEmbedDomainsPreservedOnUpgrade(): void
    {
        $this->db->execute("DELETE FROM settings WHERE group_name = 'multimedia'");
        Setting::clearCache();

        $customDomains = "player.example.com\ncustom.example.net\ncdn.customstream.tv";
        Setting::set('multimedia', 'installed_version', '1.0.4');
        Setting::set('multimedia', 'trusted_embed_domains', $customDomains);
        Setting::set('multimedia', 'player_theme_color', '#123456');

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);

        // Ensure custom domains were NOT overwritten by default ''
        $this->assertSame($customDomains, Setting::get('multimedia', 'trusted_embed_domains'));
        $this->assertSame('#123456', Setting::get('multimedia', 'player_theme_color'));
        $this->assertSame('1.0.7', Setting::get('multimedia', 'installed_version'));
        // Missing default settings should still be seeded
        $this->assertSame('yes', Setting::get('multimedia', 'enable_downloads'));
    }

    public function testIntentionallyEmptyTrustedEmbedDomainsPreserved(): void
    {
        $this->db->execute("DELETE FROM settings WHERE group_name = 'multimedia'");
        Setting::clearCache();

        // Admin explicitly cleared trusted domains to empty string
        Setting::set('multimedia', 'installed_version', '1.0.5');
        Setting::set('multimedia', 'trusted_embed_domains', '');

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        FavoriteMultimediaPlugin::ensureDefaultSettings();

        // Empty string is distinct from null; must remain ''
        $this->assertSame('', Setting::get('multimedia', 'trusted_embed_domains'));
    }

    public function testSettingsBootstrapIsIdempotent(): void
    {
        FavoriteMultimediaPlugin::ensureDefaultSettings();
        FavoriteMultimediaPlugin::ensureDefaultSettings();
        FavoriteMultimediaPlugin::ensureDefaultSettings();

        $this->assertSame('yes', Setting::get('multimedia', 'enable_downloads'));
        $this->assertSame('1080p', Setting::get('multimedia', 'default_video_resolution'));
    }

    public function testExternalEmbedPublishAfterUpgradeWithoutReactivation(): void
    {
        // 1. Simulate v1.0.4 state with missing trusted_embed_domains
        $this->db->execute("DELETE FROM settings WHERE group_name = 'multimedia'");
        Setting::clearCache();
        Setting::set('multimedia', 'installed_version', '1.0.4');

        // 2. Boot v1.0.5 without deactivate/reactivate
        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();
        FavoriteMultimediaPlugin::bootstrap($this->app);

        $this->assertSame('1.0.7', Setting::get('multimedia', 'installed_version'));

        // 3. Admin visits Multimedia -> Settings and adds rasta428jem.com
        Setting::set('multimedia', 'trusted_embed_domains', 'rasta428jem.com');
        Setting::clearCache();

        // 4. Admin submits Movie with External Embed URL and clicks 'Publish Now'
        $postData = [
            '_token'         => 'valid_test_token',
            'action'         => 'create',
            'id'             => '0',
            'title'          => 'Upgrade Tested Movie',
            'slug'           => 'upgrade-tested-movie',
            'description'    => 'Testing external embed publish after v1.0.4 to v1.0.5 upgrade.',
            'access_mode'    => 'public',
            'download_policy'=> 'inherit',
            'status'         => 'published',
            'submit_action'  => 'publish',
            'source_type'    => 'embed',
            'video_url'      => 'https://rasta428jem.com/play/ftt29330744',
            'source_label'   => 'External Embed',
            'download_url'   => '',
        ];

        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);
        $response = $this->adminCtrl->movies($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNull($_SESSION['flash_error'] ?? null);

        // 5. Verify Movie is Published and MediaSource is stored as playable embed
        $movie = Movie::findBySlug('upgrade-tested-movie');
        $this->assertNotNull($movie);
        $this->assertSame('published', $movie->status);

        $sources = $movie->getSources(false);
        $this->assertCount(1, $sources);
        $source = $sources[0];
        $this->assertSame('embed', $source->source_type);
        $this->assertSame('https://rasta428jem.com/play/ftt29330744', $source->url_or_path);
        $this->assertTrue($source->isPlayable());

        $playableSources = MediaSource::getPlayableForContent('movie', (int)$movie->id);
        $this->assertCount(1, $playableSources);
    }

    public function testEditMovieTitleOnlyPreservesEmbedSourceAndPublishedStatus(): void
    {
        // Configure trusted domain
        Setting::set('multimedia', 'trusted_embed_domains', 'rasta428jem.com');
        Setting::clearCache();

        // Create published movie with embed source
        $mId = $this->db->insert('multimedia_movies', [
            'title'          => 'Initial Movie Title',
            'slug'           => 'initial-movie-title',
            'access_mode'    => 'public',
            'download_policy'=> 'inherit',
            'status'         => 'published',
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_type'  => 'embed',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://rasta428jem.com/play/ftt29330744',
            'label'        => 'External Embed',
            'mime_type'    => 'text/html',
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $movie = Movie::find($mId);
        $this->assertNotNull($movie);
        $this->assertSame('published', $movie->status);

        // Now edit title only and click Publish Now (video_url left empty)
        $editData = [
            '_token'         => 'valid_test_token',
            'action'         => 'edit',
            'id'             => (string)$mId,
            'title'          => 'Updated Movie Title',
            'slug'           => 'initial-movie-title',
            'access_mode'    => 'public',
            'download_policy'=> 'inherit',
            'status'         => 'published',
            'submit_action'  => 'publish',
            'source_type'    => 'embed',
            'video_url'      => '',
            'source_label'   => '',
            'download_url'   => '',
        ];

        $editRequest = new Request([], $editData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);
        $this->adminCtrl->movies($editRequest);

        $updatedMovie = Movie::find($mId);
        $this->assertSame('Updated Movie Title', $updatedMovie->title);
        $this->assertSame('published', $updatedMovie->status);

        $sources = $updatedMovie->getSources(false);
        $this->assertCount(1, $sources);
        $this->assertSame('embed', $sources[0]->source_type);
        $this->assertSame('https://rasta428jem.com/play/ftt29330744', $sources[0]->url_or_path);
    }

    public function testPublishNowPrecedenceRegression(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', 'rasta428jem.com');
        Setting::clearCache();

        // Form contains status='draft' in body, but user pressed Publish Now (submit_action='publish')
        $postData = [
            '_token'         => 'valid_test_token',
            'action'         => 'create',
            'id'             => '0',
            'title'          => 'Precedence Test Movie',
            'slug'           => 'precedence-test-movie',
            'description'    => 'Precedence test description.',
            'access_mode'    => 'public',
            'download_policy'=> 'inherit',
            'status'         => 'draft',
            'submit_action'  => 'publish',
            'source_type'    => 'embed',
            'video_url'      => 'https://rasta428jem.com/play/ftt29330744',
            'source_label'   => 'External Embed',
            'download_url'   => '',
        ];

        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);
        $this->adminCtrl->movies($request);

        $movie = Movie::findBySlug('precedence-test-movie');
        $this->assertNotNull($movie);
        // Authoritative button intent must win over dropdown status
        $this->assertSame('published', $movie->status);
    }

    public function testUnknownDomainWithoutAdminWhitelistFallsBackToDraft(): void
    {
        // trusted_embed_domains does not have untrusted-random-domain.com
        Setting::set('multimedia', 'trusted_embed_domains', 'rasta428jem.com');
        Setting::clearCache();

        $postData = [
            '_token'         => 'valid_test_token',
            'action'         => 'create',
            'id'             => '0',
            'title'          => 'Untrusted Movie',
            'slug'           => 'untrusted-movie',
            'description'    => 'Untrusted movie test description.',
            'access_mode'    => 'public',
            'download_policy'=> 'inherit',
            'status'         => 'published',
            'submit_action'  => 'publish',
            'source_type'    => 'embed',
            'video_url'      => 'https://untrusted-random-domain.com/video123',
            'source_label'   => 'External Embed',
            'download_url'   => '',
        ];

        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);
        $this->adminCtrl->movies($request);

        $movie = Movie::findBySlug('untrusted-movie');
        $this->assertNotNull($movie);
        // Falls back to draft because domain is not trusted
        $this->assertSame('draft', $movie->status);
        $this->assertStringContainsString('The domain for this embed player is not in the trusted allowlist', $_SESSION['flash_warning'] ?? '');
    }
}