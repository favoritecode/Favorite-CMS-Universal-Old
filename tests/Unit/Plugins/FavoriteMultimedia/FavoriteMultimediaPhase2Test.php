<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Integrations\FavoritePayAdapter;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Services\MediaDeliveryService;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase2Test extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;

    protected function setUp(): void
    {
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->app = new Application();
        Container::getInstance()->instance(Application::class, $this->app);

        // Isolated SQLite database
        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->db = new class($this->pdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };

        Container::getInstance()->instance(Database::class, $this->db);

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT,
                setting_key TEXT,
                value TEXT,
                type TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ");
        Setting::clearCache();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $migration = new CreateFavoriteMultimediaTables($this->db);
        $migration->up();
    }

    public function testRepeatedBootstrapIdempotencyAndRouteIntegrity(): void
    {
        FavoriteMultimediaPlugin::reset();
        Router::reset();

        $routesCountBefore = count(Router::getRoutes());

        // First bootstrap
        $plugin1 = FavoriteMultimediaPlugin::bootstrap($this->app);
        $routesCountFirst = count(Router::getRoutes());
        $this->assertGreaterThan($routesCountBefore, $routesCountFirst);

        // Second bootstrap (should be idempotent, no duplicate routes)
        $plugin2 = FavoriteMultimediaPlugin::bootstrap($this->app);
        $routesCountSecond = count(Router::getRoutes());
        $this->assertSame($routesCountFirst, $routesCountSecond, 'Repeated bootstrap must not register duplicate routes');
        $this->assertSame($plugin1, $plugin2);

        // Reset clears static state
        FavoriteMultimediaPlugin::reset();
    }

    public function testSsrfBypassRegressions(): void
    {
        // Decimal IP representation of 127.0.0.1
        $decRes = MediaSourceResolver::validateUrlSecurity('http://2130706433/internal.mp4');
        $this->assertFalse($decRes['safe'], 'Decimal IP 2130706433 (127.0.0.1) must be rejected');

        // Hex IP representation of 127.0.0.1
        $hexRes = MediaSourceResolver::validateUrlSecurity('http://0x7f000001/video.mp4');
        $this->assertFalse($hexRes['safe'], 'Hex IP 0x7f000001 (127.0.0.1) must be rejected');

        // Octal notation IP (0177.0.0.1 -> 127.0.0.1)
        $octRes = MediaSourceResolver::validateUrlSecurity('http://0177.0.0.1/private.mp4');
        $this->assertFalse($octRes['safe'], 'Octal notation 0177.0.0.1 (127.0.0.1) must be rejected');

        // Bracketed IPv6 loopback
        $ipv6Res = MediaSourceResolver::validateUrlSecurity('http://[::1]/secret.mp4');
        $this->assertFalse($ipv6Res['safe'], 'IPv6 loopback [::1] must be rejected');

        // Link-local IPv6 address
        $linkLocalRes = MediaSourceResolver::validateUrlSecurity('http://[fe80::1]/media.mp4');
        $this->assertFalse($linkLocalRes['safe'], 'Link-local IPv6 must be rejected');

        // Public legitimate domains must pass
        $validPublic = MediaSourceResolver::validateUrlSecurity('https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4');
        $this->assertTrue($validPublic['safe']);
    }

    public function testProtectedDirectEndpointAccessAndInvalidIds(): void
    {
        $controller = new MediaPlaybackController($this->app);

        // 1. Invalid / non-existent media source stream request
        $req1 = $this->createRequest('GET', '/multimedia/stream/99999');
        $res1 = $controller->stream($req1, '99999');
        $this->assertInstanceOf(Response::class, $res1);
        $this->assertSame(404, $res1->getStatusCode());

        // 2. Invalid download request
        $req2 = $this->createRequest('GET', '/multimedia/download/99999');
        $res2 = $controller->download($req2, '99999');
        $this->assertInstanceOf(Response::class, $res2);
        $this->assertSame(404, $res2->getStatusCode());

        // 3. Invalid subtitle request
        $req3 = $this->createRequest('GET', '/multimedia/subtitle/99999');
        $res3 = $controller->subtitle($req3, '99999');
        $this->assertInstanceOf(Response::class, $res3);
        $this->assertSame(404, $res3->getStatusCode());

        // 4. Invalid player embed request
        $req4 = $this->createRequest('GET', '/multimedia/play/99999');
        $res4 = $controller->player($req4, '99999');
        $this->assertInstanceOf(Response::class, $res4);
        $this->assertSame(404, $res4->getStatusCode());
    }

    public function testSubtitleAuthorizationAndContentProtection(): void
    {
        $controller = new MediaPlaybackController($this->app);

        // Create a premium movie
        $premiumMovie = Movie::create([
            'title' => 'VIP Feature Film',
            'slug' => 'vip-feature-film',
            'status' => 'published',
            'access_mode' => 'premium',
        ]);

        $sub = Subtitle::create([
            'content_type' => 'movie',
            'content_id' => $premiumMovie->id,
            'language' => 'en',
            'label' => 'English VIP',
            'file_or_url' => '/uploads/subtitles/vip_en.vtt',
            'format' => 'vtt',
            'is_default' => 1,
        ]);

        // Unauthenticated guest request to premium movie subtitle
        $req = $this->createRequest('GET', '/multimedia/subtitle/' . $sub->id);
        $res = $controller->subtitle($req, (string)$sub->id);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringContainsString('WEBVTT', $res->getContent());
        $this->assertStringContainsString('restricted', strtolower($res->getContent()));

        // Public movie subtitle access
        $publicMovie = Movie::create([
            'title' => 'Open Documentary',
            'slug' => 'open-documentary',
            'status' => 'published',
            'access_mode' => 'public',
        ]);

        $publicSub = Subtitle::create([
            'content_type' => 'movie',
            'content_id' => $publicMovie->id,
            'language' => 'en',
            'label' => 'English Open',
            'file_or_url' => 'https://example.com/open.vtt',
            'format' => 'vtt',
            'is_default' => 1,
        ]);

        $publicReq = $this->createRequest('GET', '/multimedia/subtitle/' . $publicSub->id);
        $publicRes = $controller->subtitle($publicReq, (string)$publicSub->id);
        $this->assertSame(200, $publicRes->getStatusCode());
        $this->assertSame('text/vtt; charset=utf-8', $publicRes->getHeaders()['Content-Type']);
    }

    public function testCascadeDeletionIntegrity(): void
    {
        // 1. Create a complete Series hierarchy
        $series = Series::create([
            'title' => 'Drama to Delete',
            'slug' => 'drama-to-delete',
            'status' => 'published',
        ]);

        $season = Season::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'title' => 'Season 1',
        ]);

        $episode = Episode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'episode_number' => 1,
            'title' => 'Episode 1',
            'slug' => 'episode-1-to-delete',
            'status' => 'published',
        ]);

        $source = MediaSource::create([
            'content_type' => 'episode',
            'content_id' => $episode->id,
            'source_mode' => 'url',
            'source_type' => 'video',
            'url_or_path' => 'https://example.com/ep1.mp4',
            'status' => 'active',
        ]);

        $sub = Subtitle::create([
            'content_type' => 'episode',
            'content_id' => $episode->id,
            'language' => 'en',
            'label' => 'English',
            'file_or_url' => 'https://example.com/ep1.vtt',
        ]);

        $this->assertNotNull(Series::find((int)$series->id));
        $this->assertNotNull(Season::find((int)$season->id));
        $this->assertNotNull(Episode::find((int)$episode->id));
        $this->assertNotNull(MediaSource::find((int)$source->id));
        $this->assertNotNull(Subtitle::find((int)$sub->id));

        // Simulate admin deletion of Series with cascade cleanup
        $episodes = $this->db->select("SELECT id FROM multimedia_episodes WHERE series_id = ?", [$series->id]);
        foreach ($episodes as $ep) {
            $this->db->delete('multimedia_sources', ['content_type' => 'episode', 'content_id' => $ep->id]);
            $this->db->delete('multimedia_subtitles', ['content_type' => 'episode', 'content_id' => $ep->id]);
        }
        $this->db->delete('multimedia_episodes', ['series_id' => $series->id]);
        $this->db->delete('multimedia_seasons', ['series_id' => $series->id]);
        $this->db->delete('multimedia_content_genres', ['content_type' => 'series', 'content_id' => $series->id]);
        $this->db->delete('multimedia_series', ['id' => $series->id]);

        // Verify everything was cleaned up
        $this->assertNull(Series::find((int)$series->id));
        $this->assertNull(Season::find((int)$season->id));
        $this->assertNull(Episode::find((int)$episode->id));
        $this->assertNull(MediaSource::find((int)$source->id));
        $this->assertNull(Subtitle::find((int)$sub->id));
    }

    public function testAnalyticsEventWhitelisting(): void
    {
        // Valid event
        AnalyticsEvent::logEvent('movie', 10, 'play', 1);
        $count1 = (int)($this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_analytics")->c ?? 0);
        $this->assertSame(1, $count1);

        // Invalid event_type (should be discarded)
        AnalyticsEvent::logEvent('movie', 10, 'arbitrary_event_hack', 1);
        $count2 = (int)($this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_analytics")->c ?? 0);
        $this->assertSame(1, $count2, 'Invalid event type must be rejected by analytics logger');

        // Invalid content_type (should be discarded)
        AnalyticsEvent::logEvent('unauthorized_type', 10, 'play', 1);
        $count3 = (int)($this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_analytics")->c ?? 0);
        $this->assertSame(1, $count3, 'Invalid content type must be rejected by analytics logger');
    }

    public function testFrontendSearchSecurity(): void
    {
        Movie::create([
            'title' => 'The Matrix Reloaded',
            'slug' => 'the-matrix-reloaded',
            'director' => 'Lana Wachowski',
            'status' => 'published',
        ]);

        Movie::create([
            'title' => 'Inception Sci-Fi',
            'slug' => 'inception-sci-fi',
            'director' => 'Christopher Nolan',
            'status' => 'published',
        ]);

        $frontendCtrl = new MultimediaFrontendController($this->app);

        // Standard search
        $req1 = $this->createRequest('GET', '/multimedia/search?q=Matrix', ['q' => 'Matrix']);
        $res1 = $frontendCtrl->search($req1);
        $this->assertSame(200, $res1->getStatusCode());
        $this->assertStringContainsString('The Matrix Reloaded', $res1->getContent());

        // Special characters search (test SQL injection resistance)
        $injectionQuery = "Matrix' OR '1'='1";
        $req2 = $this->createRequest('GET', '/multimedia/search?q=' . urlencode($injectionQuery), ['q' => $injectionQuery]);
        $res2 = $frontendCtrl->search($req2);
        $this->assertSame(200, $res2->getStatusCode());
        $this->assertStringNotContainsString('Inception Sci-Fi', $res2->getContent(), 'SQL injection attempt must not match un-related records');

        // Empty query
        $req3 = $this->createRequest('GET', '/multimedia/search?q=', ['q' => '']);
        $res3 = $frontendCtrl->search($req3);
        $this->assertSame(200, $res3->getStatusCode());
    }

    public function testPlaylistTrackDualAccessAndDownloadGating(): void
    {
        $playlist = Playlist::create([
            'title' => 'Mixed Access Playlist',
            'slug' => 'mixed-access-playlist',
            'access_mode' => 'public',
            'status' => 'published',
        ]);

        $freeSong = Song::create([
            'title' => 'Public Acoustic Track',
            'slug' => 'public-acoustic-track',
            'access_mode' => 'public',
            'download_policy' => 'allow',
            'status' => 'published',
        ]);

        $premiumSong = Song::create([
            'title' => 'VIP Studio Master',
            'slug' => 'vip-studio-master',
            'access_mode' => 'premium',
            'download_policy' => 'allow',
            'status' => 'published',
        ]);

        $freeSource = MediaSource::create([
            'content_type' => 'song',
            'content_id' => $freeSong->id,
            'source_mode' => 'url',
            'source_type' => 'audio',
            'url_or_path' => 'https://example.com/free.mp3',
            'status' => 'active',
        ]);

        $premiumSource = MediaSource::create([
            'content_type' => 'song',
            'content_id' => $premiumSong->id,
            'source_mode' => 'url',
            'source_type' => 'audio',
            'url_or_path' => 'https://example.com/premium.mp3',
            'status' => 'active',
        ]);

        $playlist->addSong((int)$freeSong->id, 0);
        $playlist->addSong((int)$premiumSong->id, 1);

        // Guest user access to tracks inside playlist
        $freeAccess = MultimediaAccessService::checkPlaylistTrackAccess(null, $playlist, $freeSong);
        $this->assertSame(MultimediaAccessService::ALLOW, $freeAccess);

        $premiumAccess = MultimediaAccessService::checkPlaylistTrackAccess(null, $playlist, $premiumSong);
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, $premiumAccess);

        // Download check: guest can download free track, but cannot download premium track
        $freeDownload = MultimediaAccessService::checkDownloadPermission(null, 'song', $freeSong, $freeSource);
        $this->assertTrue($freeDownload['allowed']);

        $premiumDownload = MultimediaAccessService::checkDownloadPermission(null, 'song', $premiumSong, $premiumSource);
        $this->assertFalse($premiumDownload['allowed']);
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, $premiumDownload['access_state']);
    }

    public function testGracefulDegradationWithoutOptionalPlugins(): void
    {
        // Force simulation of optional plugins absent
        $GLOBALS['_test_favorite_digital_available'] = false;
        $GLOBALS['_test_favorite_pay_available'] = false;

        $this->assertFalse(FavoriteDigitalAdapter::isAvailable());
        $this->assertFalse(FavoritePayAdapter::isAvailable());

        $user = new class(['id' => 99, 'username' => 'guest']) extends User {
            public function hasRole(string $role): bool { return false; }
            public function isBanned(): bool { return false; }
        };

        // Entitlement returns false gracefully without exception
        $this->assertFalse(FavoriteDigitalAdapter::userHasEntitlement($user, 'movie', 1));

        // Payment intent returns null gracefully without exception
        $intent = FavoritePayAdapter::createPaymentIntent('test_ref', 100);
        $this->assertNull($intent);

        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_pay_available']);
    }

    private function createRequest(string $method, string $uri, array $get = [], array $post = []): Request
    {
        return new Request($get, $post, [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI'    => $uri,
        ]);
    }
}
