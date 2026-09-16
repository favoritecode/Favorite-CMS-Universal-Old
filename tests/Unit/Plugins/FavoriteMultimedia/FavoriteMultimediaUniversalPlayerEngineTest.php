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
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Services\MediaSourceService;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Multimedia\Services\MediaSourcePlaybackService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaUniversalPlayerEngineTest extends TestCase
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

        $this->tempDb = sys_get_temp_dir() . '/fav_universal_player_' . bin2hex(random_bytes(8)) . '.sqlite';
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

        // Activate plugin migrations
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

    // 1. Direct external embed URL persistence via canonical MediaSourceService
    public function testDirectExternalEmbedSaveViaMediaSourceService(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', "rasta428jem.com\nvidsrc.to");
        Setting::clearCache();

        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Embed Movie Test',
            'slug'        => 'embed-movie-test',
            'status'      => 'draft',
            'access_mode' => 'public',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $res = MediaSourceService::saveSource([
            'content_type'   => 'movie',
            'content_id'     => $movieId,
            'media_kind'     => 'video',
            'source_mode'    => 'url',
            'source_type'    => 'embed',
            'url_or_path'    => 'https://rasta428jem.com/e/xxyz123',
            'label'          => 'VIP Embed Server',
            'is_default'     => 1,
            'allow_download' => 'deny',
        ]);

        $this->assertTrue($res['success']);
        $this->assertNotNull($res['source_id']);

        $source = MediaSource::find((int)$res['source_id']);
        $this->assertNotNull($source);
        $this->assertSame('movie', $source->content_type);
        $this->assertSame($movieId, (int)$source->content_id);
        $this->assertSame('embed', $source->source_type);
        $this->assertSame('text/html', $source->mime_type);
        $this->assertSame('https://rasta428jem.com/e/xxyz123', $source->url_or_path);
        $this->assertSame('VIP Embed Server', $source->label);
        $this->assertSame(1, (int)$source->is_default);
        $this->assertSame('deny', $source->allow_download);
    }

    // 2. Iframe snippet extraction and normalization
    public function testIframeSnippetNormalization(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', 'rasta428jem.com');
        Setting::clearCache();

        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title' => 'Iframe Movie', 'slug' => 'iframe-movie', 'status' => 'draft', 'access_mode' => 'public',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $snippet = '<iframe src="https://rasta428jem.com/embed/player99" width="100%" height="480" frameborder="0" allowfullscreen></iframe>';

        $res = MediaSourceService::saveSource([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'media_kind'   => 'video',
            'source_mode'  => 'url',
            'source_type'  => 'embed',
            'url_or_path'  => $snippet,
            'is_default'   => 1,
        ]);

        $this->assertTrue($res['success']);
        $source = MediaSource::find((int)$res['source_id']);
        $this->assertSame('https://rasta428jem.com/embed/player99', $source->url_or_path);
        $this->assertSame('embed', $source->source_type);
    }

    // 3. Parity: Main Form inline save and Advanced Sources save produce identical source rows
    public function testParityBetweenMainFormAndAdvancedSources(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', 'rasta428jem.com');
        Setting::clearCache();

        // 1) Save via Main Movie Form
        $req1 = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'title'         => 'Form Saved Movie',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => 'https://rasta428jem.com/e/shared100',
            'source_label'  => 'External Stream',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res1 = $this->adminCtrl->movies($req1);
        $this->assertInstanceOf(Response::class, $res1);

        $movie1 = Movie::findBySlug('form-saved-movie');
        $this->assertNotNull($movie1);
        $sources1 = $movie1->getSources(false);
        $this->assertCount(1, $sources1);

        // 2) Save via Advanced Sources action
        $movie2Id = (int)$this->db->insert('multimedia_movies', [
            'title' => 'Advanced Source Movie', 'slug' => 'adv-source-movie', 'status' => 'published', 'access_mode' => 'public',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $req2 = new Request([], [
            '_token'         => 'valid_test_token',
            'action'         => 'add_source',
            'content_type'   => 'movie',
            'content_id'     => $movie2Id,
            'source_mode'    => 'url',
            'source_type'    => 'embed',
            'url_or_path'    => 'https://rasta428jem.com/e/shared100',
            'label'          => 'External Stream',
            'is_default'     => '1',
            'allow_download' => 'inherit',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-sources']);

        $res2 = $this->adminCtrl->sources($req2);
        $this->assertInstanceOf(Response::class, $res2);

        $movie2 = Movie::find($movie2Id);
        $sources2 = $movie2->getSources(false);
        $this->assertCount(1, $sources2);

        // Both sources must match in key persistence semantics
        $this->assertSame($sources1[0]->source_type, $sources2[0]->source_type);
        $this->assertSame($sources1[0]->mime_type, $sources2[0]->mime_type);
        $this->assertSame($sources1[0]->url_or_path, $sources2[0]->url_or_path);
        $this->assertSame($sources1[0]->label, $sources2[0]->label);
        $this->assertSame((int)$sources1[0]->is_default, (int)$sources2[0]->is_default);
    }

    // 4. Untrusted embed domain rejection with explicit error
    public function testUntrustedEmbedDomainRejection(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', 'trusted-partner.com');
        Setting::clearCache();

        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title' => 'Untrusted Movie', 'slug' => 'untrusted-movie', 'status' => 'draft', 'access_mode' => 'public',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $res = MediaSourceService::saveSource([
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_mode'  => 'url',
            'source_type'  => 'embed',
            'url_or_path'  => 'https://untrusted-shady-site.com/embed/123',
        ]);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('The domain for this embed player is not in the trusted allowlist', $res['error']);

        $movieUntrusted = Movie::find($movieId);
        $this->assertCount(0, $movieUntrusted->getSources(false));
    }

    // 5. SSRF / Security validation blocks unsafe protocols and localhost
    public function testSsrfProtectionOnSaveSource(): void
    {
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title' => 'SSRF Movie', 'slug' => 'ssrf-movie', 'status' => 'draft', 'access_mode' => 'public',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $unsafeUrls = [
            'http://127.0.0.1/video.mp4',
            'http://localhost:8080/stream',
            'file:///etc/passwd',
            'javascript:alert(1)',
        ];

        foreach ($unsafeUrls as $badUrl) {
            $res = MediaSourceService::saveSource([
                'content_type' => 'movie',
                'content_id'   => $movieId,
                'source_mode'  => 'url',
                'url_or_path'  => $badUrl,
            ]);
            $this->assertFalse($res['success'], "URL '$badUrl' should have been blocked");
        }
    }

    // 6. Multi-Source playback contract for Universal Player Engine
    public function testMediaSourcePlaybackServiceMultiSourceContract(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', 'rasta428jem.com');
        Setting::clearCache();

        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Multi-Source Feature',
            'slug'        => 'multi-source-feature',
            'status'      => 'published',
            'access_mode' => 'public',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        // Source 1: Direct MP4 (Primary)
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie', 'content_id' => $movieId, 'media_kind' => 'video',
            'source_mode' => 'url', 'source_type' => 'video', 'url_or_path' => 'https://cdn.example.com/video.mp4',
            'label' => 'Server 1 (Direct)', 'is_default' => 1, 'status' => 'active', 'sort_order' => 1,
        ]);

        // Source 2: HLS Stream
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie', 'content_id' => $movieId, 'media_kind' => 'video',
            'source_mode' => 'url', 'source_type' => 'hls', 'url_or_path' => 'https://cdn.example.com/hls/master.m3u8',
            'label' => 'Server 2 (HLS)', 'is_default' => 0, 'status' => 'active', 'sort_order' => 2,
        ]);

        // Source 3: External Embed
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie', 'content_id' => $movieId, 'media_kind' => 'video',
            'source_mode' => 'url', 'source_type' => 'embed', 'url_or_path' => 'https://rasta428jem.com/e/fast123',
            'label' => 'Server 3 (Embed)', 'is_default' => 0, 'status' => 'active', 'sort_order' => 3,
        ]);

        $playbackData = MediaSourcePlaybackService::getPlayableSources(null, 'movie', $movieId);

        $this->assertTrue($playbackData['allowed']);
        $this->assertSame(MultimediaAccessService::ALLOW, $playbackData['access']);
        $this->assertCount(3, $playbackData['sources']);

        // Check Source 1
        $s1 = $playbackData['sources'][0];
        $this->assertSame('video', $s1['player_type']);
        $this->assertTrue($s1['is_default']);
        $this->assertTrue($s1['can_auto_failover']);

        // Check Source 2
        $s2 = $playbackData['sources'][1];
        $this->assertSame('hls', $s2['player_type']);
        $this->assertFalse($s2['is_default']);
        $this->assertTrue($s2['can_auto_failover']);

        // Check Source 3
        $s3 = $playbackData['sources'][2];
        $this->assertSame('embed', $s3['player_type']);
        $this->assertFalse($s3['is_default']);
        // Embed source MUST NOT auto-failover
        $this->assertFalse($s3['can_auto_failover']);
    }

    // 7. Fail-closed access control: Zero source leakage when access denied
    public function testFailClosedZeroSourceLeakage(): void
    {
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Premium Blockbuster',
            'slug'        => 'premium-blockbuster',
            'status'      => 'published',
            'access_mode' => 'premium', // Requires premium membership
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie', 'content_id' => $movieId, 'media_kind' => 'video',
            'source_mode' => 'url', 'source_type' => 'video', 'url_or_path' => 'https://cdn.example.com/secret.mp4',
            'label' => 'Secret Server', 'is_default' => 1, 'status' => 'active', 'sort_order' => 1,
        ]);

        // Anonymous guest access
        $playbackData = MediaSourcePlaybackService::getPlayableSources(null, 'movie', $movieId);

        $this->assertFalse($playbackData['allowed']);
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, $playbackData['access']);
        $this->assertEmpty($playbackData['sources']);
        $this->assertNull($playbackData['default_source']);
    }

    // 8. Song audio/video dual player configuration
    public function testSongDualPlayerAudioAndVideoSeparation(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', 'rasta428jem.com');
        Setting::clearCache();

        $songId = (int)$this->db->insert('multimedia_songs', [
            'title'       => 'Hit Song',
            'slug'        => 'hit-song',
            'status'      => 'published',
            'access_mode' => 'public',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        // Audio track
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song', 'content_id' => $songId, 'media_kind' => 'audio',
            'source_mode' => 'url', 'source_type' => 'audio', 'url_or_path' => 'https://cdn.example.com/audio.mp3',
            'label' => 'Audio Master', 'is_default' => 1, 'status' => 'active', 'sort_order' => 1,
        ]);

        // Music Video (Embed)
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song', 'content_id' => $songId, 'media_kind' => 'video',
            'source_mode' => 'url', 'source_type' => 'embed', 'url_or_path' => 'https://rasta428jem.com/e/mv123',
            'label' => 'Official Music Video', 'is_default' => 1, 'status' => 'active', 'sort_order' => 1,
        ]);

        $playbackData = MediaSourcePlaybackService::getPlayableSources(null, 'song', $songId);

        $this->assertTrue($playbackData['allowed']);
        $this->assertTrue($playbackData['has_audio']);
        $this->assertTrue($playbackData['has_video']);
        $this->assertCount(1, $playbackData['audio_sources']);
        $this->assertCount(1, $playbackData['video_sources']);
        $this->assertSame('audio', $playbackData['audio_sources'][0]['player_type']);
        $this->assertSame('embed', $playbackData['video_sources'][0]['player_type']);
    }

    // 9. Frontend template integration: co-located iframe and video hosts
    public function testFrontendTemplatesContainUniversalPlayerElements(): void
    {
        $movieView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/movie-detail.php');
        $episodeView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/episode-detail.php');
        $songView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/song-detail.php');

        // Movie Detail
        $this->assertStringContainsString('class="fav-embed-element"', $movieView);
        $this->assertStringContainsString('class="fav-video-element"', $movieView);
        $this->assertStringContainsString('class="fav-player-controls"', $movieView);

        // Episode Detail
        $this->assertStringContainsString('class="fav-embed-element"', $episodeView);
        $this->assertStringContainsString('class="fav-video-element"', $episodeView);
        $this->assertStringContainsString('class="fav-player-controls"', $episodeView);

        // Song Detail (Video Container)
        $this->assertStringContainsString('id="fav_song_video_container"', $songView);
        $this->assertStringContainsString('class="fav-embed-element"', $songView);
        $this->assertStringContainsString('class="fav-video-element"', $songView);
    }
}
