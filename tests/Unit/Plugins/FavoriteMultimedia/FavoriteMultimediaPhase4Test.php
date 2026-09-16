<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
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

class FavoriteMultimediaPhase4Test extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;
    private string $tempLogFile;

    protected function setUp(): void
    {
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->app = new Application();
        Container::getInstance()->instance(Application::class, $this->app);

        // In-memory SQLite database
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

        // Core tables
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(100),
                email VARCHAR(255),
                password VARCHAR(255),
                role VARCHAR(50) DEFAULT 'user',
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                slug TEXT
            );
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id INTEGER,
                role_id INTEGER
            );
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT,
                setting_key TEXT,
                value TEXT,
                type TEXT,
                created_at TEXT,
                updated_at TEXT
            );
        ");
        Setting::clearCache();

        // Multimedia plugin tables
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $migration = new CreateFavoriteMultimediaTables($this->db);
        $migration->up();

        // Set isolated log file for test run
        $this->tempLogFile = sys_get_temp_dir() . '/multimedia_phase4_test_' . uniqid() . '.log';
        Logger::setLogFile($this->tempLogFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (file_exists($this->tempLogFile)) {
            @unlink($this->tempLogFile);
        }
    }

    /**
     * 1. Test Plugin Lifecycle & Zero-Data-Loss Table Preservation
     */
    public function testPluginLifecycleAndTablePreservation(): void
    {
        $plugin = new FavoriteMultimediaPlugin($this->app);
        $plugin->boot();

        // Populate sample data
        $movie = Movie::create([
            'title'       => 'Preservation Movie',
            'slug'        => 'preservation-movie',
            'status'      => 'published',
            'access_mode' => 'public',
        ]);
        $this->assertGreaterThan(0, $movie->id);

        $series = Series::create([
            'title'       => 'Preservation Series',
            'slug'        => 'preservation-series',
            'status'      => 'published',
            'access_mode' => 'premium',
        ]);
        $this->assertGreaterThan(0, $series->id);

        // Verify all 14 multimedia tables exist
        $tables = [
            'multimedia_movies', 'multimedia_series', 'multimedia_seasons',
            'multimedia_episodes', 'multimedia_songs', 'multimedia_playlists',
            'multimedia_playlist_items', 'multimedia_genres', 'multimedia_artists',
            'multimedia_albums', 'multimedia_sources', 'multimedia_subtitles',
            'multimedia_analytics', 'multimedia_content_genres'
        ];

        foreach ($tables as $table) {
            $check = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch();
            $this->assertNotEmpty($check, "Table {$table} should exist");
        }

        // Simulate deactivation & re-boot: records must remain intact
        $plugin->boot();

        $foundMovie = Movie::find((int)$movie->id);
        $this->assertNotNull($foundMovie);
        $this->assertSame('Preservation Movie', $foundMovie->title);

        $foundSeries = Series::find((int)$series->id);
        $this->assertNotNull($foundSeries);
        $this->assertSame('Preservation Series', $foundSeries->title);
    }

    /**
     * 2. Test HTTP HEAD Request Support & Access Enforcement
     */
    public function testHeadRequestSupportAndAccessEnforcement(): void
    {
        // Create a temporary media file in workspace storage to test streaming
        $mediaDir = APP_ROOT . '/storage/media';
        if (!is_dir($mediaDir)) {
            @mkdir($mediaDir, 0775, true);
        }
        $testFilePath = $mediaDir . '/phase4_test_video.mp4';
        file_put_contents($testFilePath, str_repeat('A', 2048)); // 2KB file

        $relPath = 'storage/media/phase4_test_video.mp4';

        // 2a. Test stream headers with HEAD method
        $streamMeta = MediaDeliveryService::buildStreamHeaders($relPath, 'video/mp4', false, null, 'HEAD');
        $this->assertSame(200, $streamMeta['status']);
        $this->assertTrue($streamMeta['is_head']);
        $this->assertSame('2048', $streamMeta['headers']['Content-Length']);
        $this->assertSame('bytes', $streamMeta['headers']['Accept-Ranges']);
        $this->assertSame('video/mp4', $streamMeta['headers']['Content-Type']);

        // 2b. Test download headers with HEAD method
        $downloadMeta = MediaDeliveryService::buildDownloadHeaders($relPath, 'phase4_test.mp4', 'video/mp4', true, 'HEAD');
        $this->assertSame(200, $downloadMeta['status']);
        $this->assertTrue($downloadMeta['is_head']);
        $this->assertSame('2048', $downloadMeta['headers']['Content-Length']);
        $this->assertStringContainsString('attachment;', $downloadMeta['headers']['Content-Disposition']);

        // 2c. Test HEAD request on protected content enforces access checks
        $movie = Movie::create([
            'title'       => 'Protected Movie',
            'slug'        => 'protected-movie',
            'status'      => 'published',
            'access_mode' => 'login',
        ]);

        $source = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'source_type'  => 'video',
            'source_mode'  => 'upload',
            'url_or_path'  => $relPath,
            'status'       => 'active',
        ]);

        $controller = new MediaPlaybackController($this->app);
        $headRequest = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'HEAD']);

        // Unauthenticated stream HEAD request must return 401
        $response = $controller->stream($headRequest, (string)$source->id);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        // Unauthenticated download HEAD request must also return 401
        $downloadResponse = $controller->download($headRequest, (string)$source->id);
        $this->assertInstanceOf(Response::class, $downloadResponse);
        $this->assertSame(401, $downloadResponse->getStatusCode());

        // Clean up test file
        @unlink($testFilePath);
    }

    /**
     * 3. Test Cache-Control Differentiation on Public vs Protected Media
     */
    public function testCacheControlDifferentiatingProtectedVsPublicMedia(): void
    {
        $mediaDir = APP_ROOT . '/storage/media';
        if (!is_dir($mediaDir)) {
            @mkdir($mediaDir, 0775, true);
        }
        $testFile = $mediaDir . '/cache_test.mp3';
        file_put_contents($testFile, str_repeat('M', 1024));

        $relPath = 'storage/media/cache_test.mp3';

        // Public stream: cacheable
        $publicMeta = MediaDeliveryService::buildStreamHeaders($relPath, 'audio/mpeg', false);
        $this->assertSame('public, max-age=3600', $publicMeta['headers']['Cache-Control']);
        $this->assertArrayNotHasKey('Pragma', $publicMeta['headers']);

        // Protected stream: strictly non-cacheable
        $protectedMeta = MediaDeliveryService::buildStreamHeaders($relPath, 'audio/mpeg', true);
        $this->assertSame('private, no-cache, no-store, must-revalidate', $protectedMeta['headers']['Cache-Control']);
        $this->assertSame('no-cache', $protectedMeta['headers']['Pragma']);

        // Subtitle delivery Cache-Control
        $sub = Subtitle::create([
            'content_type' => 'movie',
            'content_id'   => 1,
            'language'     => 'en',
            'label'        => 'English',
            'file_or_url'  => 'storage/media/cache_test.mp3', // points to real file
            'format'       => 'vtt',
        ]);

        $publicSub = MediaDeliveryService::deliverSubtitle($sub, false);
        $this->assertSame('public, max-age=3600', $publicSub->getHeaders()['Cache-Control']);

        $protectedSub = MediaDeliveryService::deliverSubtitle($sub, true);
        $this->assertSame('private, no-cache, no-store, must-revalidate', $protectedSub->getHeaders()['Cache-Control']);
        $this->assertSame('no-cache', $protectedSub->getHeaders()['Pragma']);

        @unlink($testFile);
    }

    /**
     * 4. Test SSRF, DNS & Alternate-Format IP Protection
     */
    public function testSsrfDnsAndMultiFormatIpProtection(): void
    {
        // Hex representation of 127.0.0.1
        $hex = MediaSourceResolver::validateUrlSecurity('http://0x7f000001/stream.mp4');
        $this->assertFalse($hex['safe']);
        $this->assertStringContainsString('forbidden', $hex['reason']);

        // Octal representation of 127.0.0.1
        $oct = MediaSourceResolver::validateUrlSecurity('http://0177.0.0.1/stream.mp4');
        $this->assertFalse($oct['safe']);
        $this->assertStringContainsString('forbidden', $oct['reason']);

        // Decimal integer representation of 127.0.0.1
        $dec = MediaSourceResolver::validateUrlSecurity('http://2130706433/stream.mp4');
        $this->assertFalse($dec['safe']);
        $this->assertStringContainsString('forbidden', $dec['reason']);

        // Bracketed IPv6 loopback
        $ipv6 = MediaSourceResolver::validateUrlSecurity('http://[::1]/stream.mp4');
        $this->assertFalse($ipv6['safe']);
        $this->assertStringContainsString('forbidden', $ipv6['reason']);

        // Cloud metadata service
        $meta = MediaSourceResolver::validateUrlSecurity('http://169.254.169.254/latest/meta-data/');
        $this->assertFalse($meta['safe']);
        $this->assertStringContainsString('forbidden', $meta['reason']);

        // Valid external HTTPS URL
        $valid = MediaSourceResolver::validateUrlSecurity('https://cdn.example.com/media/audio.mp3');
        $this->assertTrue($valid['safe']);
    }

    /**
     * 5. Test Operational Logging Diagnostics Integration
     */
    public function testOperationalLoggingDiagnostics(): void
    {
        // 5a. Trigger security block in subtitle delivery to verify Logger::warning
        $subBlocked = Subtitle::create([
            'content_type' => 'movie',
            'content_id'   => 99,
            'language'     => 'fr',
            'label'        => 'French',
            'file_or_url'  => 'http://127.0.0.1/malicious.vtt',
            'format'       => 'vtt',
        ]);

        $res = MediaDeliveryService::deliverSubtitle($subBlocked);
        $this->assertSame(403, $res->getStatusCode());

        // Verify that log file was created and contains warning
        $this->assertFileExists($this->tempLogFile);
        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('WARNING', $logContent);
        $this->assertStringContainsString('Remote subtitle blocked by security policy', $logContent);

        // 5b. Verify sensitive parameters (passwords, tokens) are NOT in the log
        $this->assertStringNotContainsString('password', $logContent);
        $this->assertStringNotContainsString('session_id', $logContent);
        $this->assertStringNotContainsString('bearer', strtolower($logContent));
    }

    /**
     * 6. Test Graceful Error Handling for Missing / Corrupted Media
     */
    public function testGracefulMissingMediaHandling(): void
    {
        $controller = new MediaPlaybackController($this->app);
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);

        // 6a. Nonexistent source ID returns 404 response
        $response = $controller->stream($request, '99999');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode());

        // 6b. Missing local file returns 404 status in buildStreamHeaders without throwing fatal
        $streamMeta = MediaDeliveryService::buildStreamHeaders('uploads/multimedia/does_not_exist.mp4', 'video/mp4');
        $this->assertSame(404, $streamMeta['status']);
        $this->assertSame('Media file not found.', $streamMeta['error']);

        // 6c. Path traversal in subtitle returns safe fallback WebVTT
        $subMissing = Subtitle::create([
            'content_type' => 'movie',
            'content_id'   => 1,
            'language'     => 'es',
            'label'        => 'Spanish',
            'file_or_url'  => 'uploads/nonexistent_sub.vtt',
            'format'       => 'vtt',
        ]);
        $subRes = MediaDeliveryService::deliverSubtitle($subMissing);
        $this->assertSame(200, $subRes->getStatusCode());
        $this->assertStringContainsString('Subtitle file not found', $subRes->getContent());
    }

    /**
     * 7. Test Unicode Multibyte & Bengali Search Catalog Queries
     */
    public function testUnicodeAndBanglaSearchCatalogQueries(): void
    {
        // Insert items with Bengali titles and Unicode metadata
        $m1 = Movie::create([
            'title'       => 'পথের পাঁচালী',
            'slug'        => 'pather-panchali',
            'status'      => 'published',
            'director'    => 'সত্যজিৎ রায়',
            'access_mode' => 'public',
        ]);

        $m2 = Movie::create([
            'title'       => 'অপু সংসার',
            'slug'        => 'apur-sansar',
            'status'      => 'published',
            'director'    => 'সত্যজিৎ রায়',
            'access_mode' => 'public',
        ]);

        $s1 = Series::create([
            'title'       => 'ব্যোমকেশ বক্সী সমগ্র',
            'slug'        => 'byomkesh-bakshi',
            'status'      => 'published',
            'access_mode' => 'public',
        ]);

        $song1 = Song::create([
            'title'       => 'আমার সোনার বাংলা',
            'slug'        => 'amar-shonar-bangla',
            'status'      => 'published',
            'lyrics'      => 'চিরদিন তোমার আকাশ তোমার বাতাস',
            'access_mode' => 'public',
        ]);

        // Search Movie by Bengali title keyword
        $movieResults = Movie::published(10, 0, null, 'পাঁচালী');
        $this->assertCount(1, $movieResults);
        $this->assertSame('পথের পাঁচালী', $movieResults[0]->title);
        $this->assertSame(1, Movie::countPublished(null, 'পাঁচালী'));

        // Search Movie by Bengali director keyword
        $directorResults = Movie::published(10, 0, null, 'সত্যজিৎ');
        $this->assertCount(2, $directorResults);
        $this->assertSame(2, Movie::countPublished(null, 'সত্যজিৎ'));

        // Search Series by Bengali title keyword
        $seriesResults = Series::published(10, 0, null, 'ব্যোমকেশ');
        $this->assertCount(1, $seriesResults);
        $this->assertSame('ব্যোমকেশ বক্সী সমগ্র', $seriesResults[0]->title);
        $this->assertSame(1, Series::countPublished(null, 'ব্যোমকেশ'));

        // Search Song by Bengali lyrics keyword
        $songResults = Song::published(10, 0, null, null, null, 'আকাশ');
        $this->assertCount(1, $songResults);
        $this->assertSame('আমার সোনার বাংলা', $songResults[0]->title);
    }
}
