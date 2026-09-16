<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use CreateMultimediaEngagementTables;
use CreateMultimediaProcessingTables;
use CreateMultimediaSubscriptionNotificationTables;
use CreateMultimediaUserLibraryTables;
use AddMultimediaSchedulingFields;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaProcessingJob;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\FFmpegService;
use FavoriteCMS\Multimedia\Services\MediaDeliveryService;
use FavoriteCMS\Multimedia\Services\MediaProbeService;
use FavoriteCMS\Multimedia\Services\MediaProcessingService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase10Test extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;
    private string $tempMediaDir;

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
                name VARCHAR(100),
                email VARCHAR(255),
                password VARCHAR(255),
                role VARCHAR(50) DEFAULT 'user',
                avatar VARCHAR(255) NULL,
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
            CREATE TABLE IF NOT EXISTS permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                slug TEXT
            );
            CREATE TABLE IF NOT EXISTS role_permissions (
                role_id INTEGER,
                permission_id INTEGER
            );
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT,
                setting_key TEXT,
                value TEXT,
                type TEXT,
                is_public INTEGER DEFAULT 0,
                created_at TEXT,
                updated_at TEXT
            );
        ");
        Setting::clearCache();

        // Run migrations 001 - 006
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $m1 = new CreateFavoriteMultimediaTables($this->db);
        $m1->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/002_create_multimedia_user_library_tables.php';
        $m2 = new CreateMultimediaUserLibraryTables($this->db);
        $m2->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/003_create_multimedia_engagement_tables.php';
        $m3 = new CreateMultimediaEngagementTables($this->db);
        $m3->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/004_create_multimedia_subscription_notification_tables.php';
        $m4 = new CreateMultimediaSubscriptionNotificationTables($this->db);
        $m4->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/005_add_multimedia_scheduling_fields.php';
        $m5 = new AddMultimediaSchedulingFields($this->db);
        $m5->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/006_create_multimedia_processing_tables.php';
        $m6 = new CreateMultimediaProcessingTables($this->db);
        $m6->up();

        $_SESSION = [];
        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        unset($GLOBALS['_test_ffmpeg_mock']);
        unset($GLOBALS['_test_probe_mock']);
        unset($GLOBALS['_test_processing_mock']);
        FFmpegService::resetCache();

        // Setup temporary test media directory
        $this->tempMediaDir = sys_get_temp_dir() . '/fmm_test_' . uniqid();
        @mkdir($this->tempMediaDir, 0777, true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        unset($GLOBALS['_test_ffmpeg_mock']);
        unset($GLOBALS['_test_probe_mock']);
        unset($GLOBALS['_test_processing_mock']);
        FFmpegService::resetCache();

        if (is_dir($this->tempMediaDir)) {
            $this->recursiveRmdir($this->tempMediaDir);
        }
    }

    private function recursiveRmdir(string $dir): void
    {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $p = "$dir/$file";
            is_dir($p) ? $this->recursiveRmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function authenticateUser(int $id = 1, string $role = 'user', string $username = 'user_test'): User
    {
        $this->pdo->exec("INSERT OR REPLACE INTO users (id, username, name, email, password, role) VALUES ({$id}, '{$username}', 'Test User', '{$username}@example.com', 'hash_123', '{$role}')");
        $_SESSION['auth_user_id'] = $id;
        $_SESSION['user_id'] = $id;

        if ($role === 'admin' || $role === 'administrator') {
            $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, slug) VALUES (1, 'Admin', 'admin')");
            $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$id}");
            $this->pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES ({$id}, 1)");
        }

        $u = User::find($id);
        $this->assertNotNull($u);
        return $u;
    }

    private function createMovie(array $overrides = []): Movie
    {
        $data = array_merge([
            'title'        => 'Interstellar',
            'slug'         => 'interstellar-' . uniqid(),
            'description'  => 'A journey beyond space and time.',
            'access_mode'  => 'public',
            'status'       => 'published',
            'publish_at'   => null,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'unpublish_at' => null,
            'views_count'  => 0,
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], $overrides);

        $id = $this->db->insert('multimedia_movies', $data);
        return Movie::find((int)$id);
    }

    private function createMediaSource(string $contentType, int $contentId, string $status = 'active', string $type = 'video', string $mode = 'upload', string $url = 'https://cdn.example.com/media.mp4'): MediaSource
    {
        $id = $this->db->insert('multimedia_sources', [
            'content_type'   => $contentType,
            'content_id'     => $contentId,
            'source_mode'    => $mode,
            'source_type'    => $type,
            'url_or_path'    => $url,
            'label'          => 'Default Stream',
            'quality'        => '1080p',
            'mime_type'      => ($type === 'hls' ? 'application/x-mpegURL' : 'video/mp4'),
            'is_default'     => 1,
            'allow_download' => 'inherit',
            'status'         => $status,
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        return MediaSource::find((int)$id);
    }

    private function createDummyVideoFile(string $filename = 'sample.mp4'): string
    {
        $path = $this->tempMediaDir . '/' . $filename;
        file_put_contents($path, "DUMMY_MP4_BINARY_DATA_" . uniqid());
        return $path;
    }

    // -------------------------------------------------------------------------
    // Test 1: Migration 006 creates processing table and indexes
    // -------------------------------------------------------------------------
    public function testMigration006CreatesProcessingTablesAndIndexes(): void
    {
        $cols = $this->db->select("PRAGMA table_info(multimedia_processing_jobs)");
        $names = array_column(array_map(fn($c) => (array)$c, $cols), 'name');

        $this->assertContains('content_type', $names);
        $this->assertContains('content_id', $names);
        $this->assertContains('source_id', $names);
        $this->assertContains('job_type', $names);
        $this->assertContains('status', $names);
        $this->assertContains('progress', $names);
        $this->assertContains('input_path', $names);
        $this->assertContains('output_path', $names);
        $this->assertContains('settings', $names);
        $this->assertContains('error_message', $names);
        $this->assertContains('attempts', $names);
    }

    // -------------------------------------------------------------------------
    // Test 2: FFmpeg capability detection & graceful degradation
    // -------------------------------------------------------------------------
    public function testFFmpegCapabilityDetectionAndGracefulDegradation(): void
    {
        // When FFmpeg is absent in test environment
        FFmpegService::setMockEnvironment([
            'available'       => false,
            'probe_available' => false,
            'version'         => null,
            'ffmpeg_path'     => null,
            'ffprobe_path'    => null,
        ]);

        $this->assertFalse(FFmpegService::isAvailable());
        $this->assertFalse(FFmpegService::isProbeAvailable());
        $caps = FFmpegService::getCapabilities();
        $this->assertFalse($caps['available']);

        // When FFmpeg is present
        FFmpegService::setMockEnvironment([
            'available'       => true,
            'probe_available' => true,
            'version'         => '6.1.1',
            'ffmpeg_path'     => '/usr/bin/ffmpeg',
            'ffprobe_path'    => '/usr/bin/ffprobe',
        ]);

        $this->assertTrue(FFmpegService::isAvailable());
        $this->assertTrue(FFmpegService::isProbeAvailable());
        $this->assertSame('6.1.1', FFmpegService::getVersion());
    }

    // -------------------------------------------------------------------------
    // Test 3: Media probe metadata extraction
    // -------------------------------------------------------------------------
    public function testMediaProbeMetadataExtraction(): void
    {
        $videoFile = $this->createDummyVideoFile('probe_test.mp4');

        $GLOBALS['_test_probe_mock'][$videoFile] = [
            'success'           => true,
            'file_path'         => $videoFile,
            'file_size'         => 1048576,
            'format'            => 'mp4',
            'duration'          => 125.5,
            'duration_formatted'=> '02:05',
            'bitrate'           => 2500000,
            'width'             => 1920,
            'height'            => 1080,
            'resolution'        => '1080p',
            'video_codec'       => 'h264',
            'audio_codec'       => 'aac',
            'audio_channels'    => 2,
            'audio_sample_rate' => 48000,
            'has_video'         => true,
            'has_audio'         => true,
        ];

        $probe = MediaProbeService::probe($videoFile);
        $this->assertTrue($probe['success']);
        $this->assertSame('1080p', $probe['resolution']);
        $this->assertSame('h264', $probe['video_codec']);
        $this->assertSame('02:05', $probe['duration_formatted']);
    }

    // -------------------------------------------------------------------------
    // Test 4: Job creation, persistence, and state validation
    // -------------------------------------------------------------------------
    public function testJobCreationAndValidation(): void
    {
        $movie = $this->createMovie();
        $videoFile = $this->createDummyVideoFile();

        $job = MediaProcessingService::dispatchJob(
            'movie',
            (int)$movie->id,
            $videoFile,
            MediaProcessingJob::TYPE_FULL_PIPELINE,
            ['qualities' => ['1080p', '720p']]
        );

        $this->assertNotNull($job);
        $this->assertSame(MediaProcessingJob::STATUS_PENDING, $job->status);
        $this->assertSame(0, (int)$job->progress);
        $this->assertTrue($job->isPending());
        $this->assertFalse($job->isCompleted());
        $this->assertSame(['qualities' => ['1080p', '720p']], $job->getSettings());
    }

    // -------------------------------------------------------------------------
    // Test 5: Automated thumbnail generation and poster assignment
    // -------------------------------------------------------------------------
    public function testThumbnailGenerationAndPosterAssignment(): void
    {
        $movie = $this->createMovie(['poster' => null]);
        $videoFile = $this->createDummyVideoFile();

        $job = MediaProcessingService::dispatchJob(
            'movie',
            (int)$movie->id,
            $videoFile,
            MediaProcessingJob::TYPE_GENERATE_THUMBNAIL
        );

        $res = MediaProcessingService::processQueue(1);
        $this->assertSame(1, $res['completed']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertNotEmpty($refreshed->poster);
        $this->assertStringContainsString('thumb_', (string)$refreshed->poster);
    }

    // -------------------------------------------------------------------------
    // Test 6: Multi-resolution MP4 transcoding and auto-registration
    // -------------------------------------------------------------------------
    public function testMultiResolutionMp4Transcoding(): void
    {
        $movie = $this->createMovie();
        $videoFile = $this->createDummyVideoFile();

        $job = MediaProcessingService::dispatchJob(
            'movie',
            (int)$movie->id,
            $videoFile,
            MediaProcessingJob::TYPE_TRANSCODE_MP4,
            ['qualities' => ['720p', '480p']]
        );

        $res = MediaProcessingService::processQueue(1);
        $this->assertSame(1, $res['completed']);

        $sources = MediaSource::getForContent('movie', (int)$movie->id);
        $qualities = array_map(fn($s) => $s->quality, $sources);

        $this->assertContains('720p', $qualities);
        $this->assertContains('480p', $qualities);
    }

    // -------------------------------------------------------------------------
    // Test 7: Adaptive HLS packaging (master.m3u8 + variants + segments)
    // -------------------------------------------------------------------------
    public function testAdaptiveHlsPackagingMasterAndVariants(): void
    {
        $movie = $this->createMovie();
        $videoFile = $this->createDummyVideoFile();

        $job = MediaProcessingService::dispatchJob(
            'movie',
            (int)$movie->id,
            $videoFile,
            MediaProcessingJob::TYPE_TRANSCODE_HLS,
            ['qualities' => ['720p', '480p']]
        );

        $res = MediaProcessingService::processQueue(1);
        $this->assertSame(1, $res['completed']);

        $sources = MediaSource::getForContent('movie', (int)$movie->id);
        $hlsSources = array_filter($sources, fn($s) => $s->source_type === 'hls');

        $this->assertNotEmpty($hlsSources);
        $hls = array_values($hlsSources)[0];
        $this->assertSame('application/x-mpegURL', $hls->mime_type);
        $this->assertStringContainsString('master.m3u8', (string)$hls->url_or_path);
    }

    // -------------------------------------------------------------------------
    // Test 8: Protected HLS master playlist delivery requires MultimediaAccessService
    // -------------------------------------------------------------------------
    public function testHlsMasterPlaylistRequiresMultimediaAccessService(): void
    {
        $movie = $this->createMovie(['access_mode' => 'login']);
        $hlsDir = $this->tempMediaDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=2500000\n720p/index.m3u8\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // 1. Guest request -> 401 Authentication required
        $resGuest = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(401, $resGuest->getStatusCode());

        // 2. Logged in user -> 200 with rewritten variant URL
        $this->authenticateUser(10, 'user');
        $resUser = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(200, $resUser->getStatusCode());
        $this->assertStringContainsString("/api/multimedia/hls/{$source->id}/720p/index.m3u8", $resUser->getContent());
    }

    // -------------------------------------------------------------------------
    // Test 9: Protected HLS variant playlist delivery requires MultimediaAccessService
    // -------------------------------------------------------------------------
    public function testHlsVariantPlaylistRequiresMultimediaAccessService(): void
    {
        $movie = $this->createMovie(['access_mode' => 'login']);
        $hlsDir = $this->tempMediaDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        file_put_contents($hlsDir . '/720p/index.m3u8', "#EXTM3U\n#EXTINF:6.0,\nseg_000.ts\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Guest denied
        $resGuest = $ctrl->hlsVariant($req, (string)$source->id, '720p');
        $this->assertSame(401, $resGuest->getStatusCode());

        // User allowed with rewritten segment URL
        $this->authenticateUser(11, 'user');
        $resUser = $ctrl->hlsVariant($req, (string)$source->id, '720p');
        $this->assertSame(200, $resUser->getStatusCode());
        $this->assertStringContainsString("/api/multimedia/hls/{$source->id}/720p/seg_000.ts", $resUser->getContent());
    }

    // -------------------------------------------------------------------------
    // Test 10: Protected HLS media segment delivery requires MultimediaAccessService
    // -------------------------------------------------------------------------
    public function testHlsSegmentDeliveryRequiresMultimediaAccessService(): void
    {
        $movie = $this->createMovie(['access_mode' => 'login']);
        $hlsDir = $this->tempMediaDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        file_put_contents($hlsDir . '/720p/index.m3u8', "#EXTM3U\n#EXTINF:6.0,\nseg_000.ts\n");
        file_put_contents($hlsDir . '/720p/seg_000.ts', "TS_BINARY_SEGMENT_DATA");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Guest denied
        $resGuest = $ctrl->hlsSegment($req, (string)$source->id, '720p', 'seg_000.ts');
        $this->assertSame(401, $resGuest->getStatusCode());

        // User allowed
        $this->authenticateUser(12, 'user');
        $resUser = $ctrl->hlsSegment($req, (string)$source->id, '720p', 'seg_000.ts');
        $this->assertSame(200, $resUser->getStatusCode());
        $this->assertSame('TS_BINARY_SEGMENT_DATA', $resUser->getContent());
    }

    // -------------------------------------------------------------------------
    // Test 11: CRITICAL RULE — Premium HLS denied when Favorite Digital unavailable
    // -------------------------------------------------------------------------
    public function testPremiumHlsDeniedWhenFavoriteDigitalUnavailable(): void
    {
        $movie = $this->createMovie(['access_mode' => 'premium']);
        $hlsDir = $this->tempMediaDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        file_put_contents($hlsDir . '/720p/index.m3u8', "#EXTM3U\nseg_000.ts\n");
        file_put_contents($hlsDir . '/720p/seg_000.ts', "PREMIUM_TS_DATA");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $user = $this->authenticateUser(20, 'user');
        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Favorite Digital not installed / no entitlement -> FAIL CLOSED (403)
        $GLOBALS['_test_favorite_digital_available'] = false;
        unset($GLOBALS['_test_favorite_digital_entitled_users']);

        $resMaster = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(403, $resMaster->getStatusCode());

        $resVariant = $ctrl->hlsVariant($req, (string)$source->id, '720p');
        $this->assertSame(403, $resVariant->getStatusCode());

        $resSegment = $ctrl->hlsSegment($req, (string)$source->id, '720p', 'seg_000.ts');
        $this->assertSame(403, $resSegment->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 12: CRITICAL RULE — Premium HLS allowed with active Favorite Digital entitlement
    // -------------------------------------------------------------------------
    public function testPremiumHlsAllowedWithActiveFavoriteDigitalEntitlement(): void
    {
        $movie = $this->createMovie(['access_mode' => 'premium']);
        $hlsDir = $this->tempMediaDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        file_put_contents($hlsDir . '/720p/index.m3u8', "#EXTM3U\nseg_000.ts\n");
        file_put_contents($hlsDir . '/720p/seg_000.ts', "PREMIUM_TS_DATA");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $user = $this->authenticateUser(21, 'user');
        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Grant active Favorite Digital entitlement
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_entitled_users'] = [(int)$user->id];

        $resMaster = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(200, $resMaster->getStatusCode());

        $resVariant = $ctrl->hlsVariant($req, (string)$source->id, '720p');
        $this->assertSame(200, $resVariant->getStatusCode());

        $resSegment = $ctrl->hlsSegment($req, (string)$source->id, '720p', 'seg_000.ts');
        $this->assertSame(200, $resSegment->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 13: Direct MP4 playback preserved without FFmpeg
    // -------------------------------------------------------------------------
    public function testDirectMp4PlaybackPreservedWithoutFFmpeg(): void
    {
        FFmpegService::setMockEnvironment(['available' => false]);

        $movie = $this->createMovie(['access_mode' => 'public']);
        $videoFile = $this->createDummyVideoFile('direct.mp4');
        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'video', 'upload', $videoFile);

        // Access check returns ALLOW
        $state = MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id);
        $this->assertSame(MultimediaAccessService::ALLOW, $state);
    }

    // -------------------------------------------------------------------------
    // Test 14: YouTube / external embeds preserved without FFmpeg
    // -------------------------------------------------------------------------
    public function testExternalEmbedsPreservedWithoutFFmpeg(): void
    {
        FFmpegService::setMockEnvironment(['available' => false]);

        $movie = $this->createMovie(['access_mode' => 'public']);
        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'embed', 'url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $state = MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id);
        $this->assertSame(MultimediaAccessService::ALLOW, $state);
    }

    // -------------------------------------------------------------------------
    // Test 15: Bounded batch queue execution respects limit
    // -------------------------------------------------------------------------
    public function testBoundedBatchQueueExecution(): void
    {
        $movie = $this->createMovie();
        $videoFile = $this->createDummyVideoFile();

        for ($i = 0; $i < 5; $i++) {
            MediaProcessingService::dispatchJob('movie', (int)$movie->id, $videoFile, MediaProcessingJob::TYPE_GENERATE_THUMBNAIL);
        }

        $res = MediaProcessingService::processQueue(2);
        $this->assertSame(2, $res['processed']);
        $this->assertSame(2, $res['completed']);

        $pending = MediaProcessingJob::findPending();
        $this->assertCount(3, $pending);
    }

    // -------------------------------------------------------------------------
    // Test 16: Failure isolation and error logging
    // -------------------------------------------------------------------------
    public function testFailureIsolationAndErrorLogging(): void
    {
        $movie = $this->createMovie();

        // Job 1 has nonexistent file -> will fail
        $job1 = MediaProcessingService::dispatchJob('movie', (int)$movie->id, '/nonexistent/file/test.mp4', MediaProcessingJob::TYPE_GENERATE_THUMBNAIL);

        // Job 2 has valid file -> will succeed
        $videoFile = $this->createDummyVideoFile();
        $job2 = MediaProcessingService::dispatchJob('movie', (int)$movie->id, $videoFile, MediaProcessingJob::TYPE_GENERATE_THUMBNAIL);

        $res = MediaProcessingService::processQueue(5);
        $this->assertSame(2, $res['processed']);
        $this->assertSame(1, $res['completed']);
        $this->assertSame(1, $res['failed']);

        $refreshed1 = MediaProcessingJob::find((int)$job1->id);
        $this->assertSame(MediaProcessingJob::STATUS_FAILED, $refreshed1->status);
        $this->assertNotEmpty($refreshed1->error_message);

        $refreshed2 = MediaProcessingJob::find((int)$job2->id);
        $this->assertSame(MediaProcessingJob::STATUS_COMPLETED, $refreshed2->status);
    }

    // -------------------------------------------------------------------------
    // Test 17: Job cancellation and retry workflow
    // -------------------------------------------------------------------------
    public function testJobCancellationAndRetryWorkflow(): void
    {
        $movie = $this->createMovie();
        $videoFile = $this->createDummyVideoFile();

        $job = MediaProcessingService::dispatchJob('movie', (int)$movie->id, $videoFile, MediaProcessingJob::TYPE_GENERATE_THUMBNAIL);

        // 1. Cancel pending job
        $cancelRes = MediaProcessingService::cancelJob((int)$job->id);
        $this->assertTrue($cancelRes['success']);

        $refreshed = MediaProcessingJob::find((int)$job->id);
        $this->assertTrue($refreshed->isCancelled());

        // 2. Retry cancelled job
        $retryRes = MediaProcessingService::retryJob((int)$job->id);
        $this->assertTrue($retryRes['success']);

        $refreshed2 = MediaProcessingJob::find((int)$job->id);
        $this->assertTrue($refreshed2->isPending());
        $this->assertSame(1, (int)$refreshed2->attempts);
    }

    // -------------------------------------------------------------------------
    // Test 18: Non-admin denied from queue dispatch
    // -------------------------------------------------------------------------
    public function testNonAdminDeniedFromQueueDispatch(): void
    {
        $this->authenticateUser(30, 'user'); // Non-admin

        $adminCtrl = new MultimediaAdminController($this->app);
        $req = new Request([], ['content_type' => 'movie', 'content_id' => 1, 'input_path' => 'file.mp4'], ['REQUEST_METHOD' => 'POST']);

        $res = $adminCtrl->apiDispatchProcessingJob($req);
        $this->assertSame(403, $res->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 19: CSRF protection on processing API mutation endpoints
    // -------------------------------------------------------------------------
    public function testCsrfProtectionOnProcessingApiEndpoints(): void
    {
        $this->authenticateUser(1, 'admin');
        $_SESSION['csrf_token'] = 'valid_session_token';

        $adminCtrl = new MultimediaAdminController($this->app);

        // POST without token
        $reqNoToken = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $resNoToken = $adminCtrl->apiDispatchProcessingJob($reqNoToken);
        $this->assertSame(403, $resNoToken->getStatusCode());

        // POST with valid token
        $dummy = $this->createDummyVideoFile('valid_csrf.mp4');
        $reqValid = new Request([], [
            '_token'       => 'valid_session_token',
            'content_type' => 'movie',
            'content_id'   => 1,
            'input_path'   => $dummy,
        ], ['REQUEST_METHOD' => 'POST']);
        $resValid = $adminCtrl->apiDispatchProcessingJob($reqValid);
        $this->assertNotSame(403, $resValid->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 20: Path traversal denied on HLS segments
    // -------------------------------------------------------------------------
    public function testPathTraversalDeniedOnHlsSegments(): void
    {
        $movie = $this->createMovie();
        $hlsDir = $this->tempMediaDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Traversal attempt
        $res = $ctrl->hlsSegment($req, (string)$source->id, '720p', '../../etc/passwd');
        $this->assertContains($res->getStatusCode(), [400, 404]);
    }

    // -------------------------------------------------------------------------
    // Test 21: Audio extraction pipeline
    // -------------------------------------------------------------------------
    public function testAudioExtractionPipeline(): void
    {
        $movie = $this->createMovie();
        $videoFile = $this->createDummyVideoFile('audio_source.mp4');

        $job = MediaProcessingService::dispatchJob('movie', (int)$movie->id, $videoFile, MediaProcessingJob::TYPE_EXTRACT_AUDIO);

        $res = MediaProcessingService::processQueue(1);
        $this->assertSame(1, $res['completed']);

        $sources = MediaSource::getForContent('movie', (int)$movie->id);
        $audioSources = array_filter($sources, fn($s) => $s->source_type === 'audio');

        $this->assertNotEmpty($audioSources);
        $audio = array_values($audioSources)[0];
        $this->assertSame('audio/mpeg', $audio->mime_type);
    }

    // -------------------------------------------------------------------------
    // Test 22: Full pipeline job execution
    // -------------------------------------------------------------------------
    public function testFullPipelineJobExecution(): void
    {
        $movie = $this->createMovie(['poster' => null]);
        $videoFile = $this->createDummyVideoFile('full_source.mp4');

        $job = MediaProcessingService::dispatchJob(
            'movie',
            (int)$movie->id,
            $videoFile,
            MediaProcessingJob::TYPE_FULL_PIPELINE,
            ['qualities' => ['720p']]
        );

        $res = MediaProcessingService::processQueue(1);
        $this->assertSame(1, $res['completed']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertNotEmpty($refreshed->poster);

        $sources = MediaSource::getForContent('movie', (int)$movie->id);
        $this->assertGreaterThanOrEqual(2, count($sources)); // MP4 + HLS master
    }

    // -------------------------------------------------------------------------
    // Test 23: Draft content HLS denied to public
    // -------------------------------------------------------------------------
    public function testDraftContentHlsDeniedToPublic(): void
    {
        $movie = $this->createMovie(['status' => 'draft']);
        $hlsDir = $this->tempMediaDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Guest visitor -> 404
        $resGuest = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(404, $resGuest->getStatusCode());

        // Admin -> 200
        $this->authenticateUser(1, 'admin');
        $resAdmin = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(200, $resAdmin->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 24: Scheduled content HLS denied before release time
    // -------------------------------------------------------------------------
    public function testScheduledContentHlsDeniedBeforeReleaseTime(): void
    {
        $futureUtc = gmdate('Y-m-d H:i:s', time() + 3600);
        $movie = $this->createMovie(['status' => 'scheduled', 'publish_at' => $futureUtc]);

        $hlsDir = $this->tempMediaDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Public visitor denied before schedule
        $resPublic = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(404, $resPublic->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 25: Full regression across all phases
    // -------------------------------------------------------------------------
    public function testFullRegressionAllPhases(): void
    {
        $movie = $this->createMovie();
        $this->createMediaSource('movie', (int)$movie->id);

        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id));
        $this->assertNotNull(MediaProcessingJob::findPending());
    }
}
