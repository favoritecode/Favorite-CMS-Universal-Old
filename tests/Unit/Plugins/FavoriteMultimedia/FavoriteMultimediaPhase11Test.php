<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Models\MediaProcessingJob;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\MediaStorageFile;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Services\FFmpegService;
use FavoriteCMS\Multimedia\Services\MediaDeliveryService;
use FavoriteCMS\Multimedia\Services\MediaDeliveryTokenService;
use FavoriteCMS\Multimedia\Services\MediaProcessingService;
use FavoriteCMS\Multimedia\Services\MediaStorageService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Storage\LocalMultimediaStorage;
use FavoriteCMS\Multimedia\Storage\MediaStorageManager;
use FavoriteCMS\Multimedia\Storage\MultimediaStorageInterface;
use FavoriteCMS\Multimedia\Storage\S3CompatibleMultimediaStorage;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase11Test extends TestCase
{
    protected Application $app;
    protected Database $db;
    protected \PDO $pdo;
    protected string $tempStorageDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }

        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->app = new Application();
        Container::setInstance($this->app);

        // In-memory SQLite Database
        $this->pdo = new \PDO('sqlite::memory:', '', '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
        ]);

        $this->db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $ref = new \ReflectionProperty(Database::class, 'pdo');
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($this->db, $this->pdo);

        $this->app->singleton(Database::class, fn() => $this->db);

        // Core CMS Schema
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                name TEXT,
                email TEXT,
                password TEXT,
                role TEXT,
                status TEXT DEFAULT 'active',
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

        // Run migrations 001 through 007
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        (new \CreateFavoriteMultimediaTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/002_create_multimedia_user_library_tables.php';
        (new \CreateMultimediaUserLibraryTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/003_create_multimedia_engagement_tables.php';
        (new \CreateMultimediaEngagementTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/004_create_multimedia_subscription_notification_tables.php';
        (new \CreateMultimediaSubscriptionNotificationTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/005_add_multimedia_scheduling_fields.php';
        (new \AddMultimediaSchedulingFields($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/006_create_multimedia_processing_tables.php';
        (new \CreateMultimediaProcessingTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/007_add_multimedia_storage_fields.php';
        (new \AddMultimediaStorageFields($this->db))->up();

        $_SESSION = [];
        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        unset($GLOBALS['_test_ffmpeg_mock']);
        unset($GLOBALS['_test_probe_mock']);
        unset($GLOBALS['_test_processing_mock']);

        S3CompatibleMultimediaStorage::resetMockEnvironment();
        MediaStorageManager::resetInstances();
        MediaDeliveryTokenService::setSecretForTesting('test_secret_key_ph11');
        FFmpegService::resetCache();

        // Setup temporary test media and storage directories
        $this->tempStorageDir = sys_get_temp_dir() . '/fmm_st_test_' . uniqid();
        @mkdir($this->tempStorageDir, 0777, true);
        MediaStorageManager::setDisk('local', new LocalMultimediaStorage($this->tempStorageDir));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        unset($GLOBALS['_test_ffmpeg_mock']);
        unset($GLOBALS['_test_probe_mock']);
        unset($GLOBALS['_test_processing_mock']);

        S3CompatibleMultimediaStorage::resetMockEnvironment();
        MediaStorageManager::resetInstances();
        MediaDeliveryTokenService::setSecretForTesting(null);
        FFmpegService::resetCache();

        if (is_dir($this->tempStorageDir)) {
            $this->recursiveRmdir($this->tempStorageDir);
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
            'description'  => 'Sci-fi masterpiece',
            'access_mode'  => 'public',
            'status'       => 'published',
            'publish_at'   => null,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'unpublish_at' => null,
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], $overrides);

        $id = $this->db->insert('multimedia_movies', $data);
        return Movie::find((int)$id);
    }

    private function createMediaSource(string $type, int $id, string $status = 'active', string $srcType = 'video', string $srcMode = 'upload', ?string $path = null, array $extra = []): MediaSource
    {
        $data = array_merge([
            'content_type'   => $type,
            'content_id'     => $id,
            'source_mode'    => $srcMode,
            'source_type'    => $srcType,
            'url_or_path'    => $path ?: "storage/multimedia/{$type}/{$id}/sample.mp4",
            'label'          => 'Main',
            'quality'        => '720p',
            'mime_type'      => ($srcType === 'hls') ? 'application/vnd.apple.mpegurl' : 'video/mp4',
            'is_default'     => 1,
            'allow_download' => 'allow',
            'status'         => $status,
            'sort_order'     => 1,
            'storage_driver' => 'local',
            'storage_key'    => null,
            'is_migrated'    => 0,
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ], $extra);

        $srcId = $this->db->insert('multimedia_sources', $data);
        return MediaSource::find((int)$srcId);
    }

    // -------------------------------------------------------------------------
    // Test 1: Migration 007 applies successfully and extends multimedia_sources
    // -------------------------------------------------------------------------
    public function testMigration007CreatesStorageFieldsAndTrackingTable(): void
    {
        $cols = $this->pdo->query("PRAGMA table_info(multimedia_sources)")->fetchAll(\PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');

        $this->assertContains('storage_driver', $colNames);
        $this->assertContains('storage_key', $colNames);
        $this->assertContains('is_migrated', $colNames);
        $this->assertContains('storage_meta', $colNames);

        $fileCols = $this->pdo->query("PRAGMA table_info(multimedia_storage_files)")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($fileCols);
        $fileColNames = array_column($fileCols, 'name');
        $this->assertContains('storage_driver', $fileColNames);
        $this->assertContains('storage_key', $fileColNames);
        $this->assertContains('file_type', $fileColNames);
        $this->assertContains('file_size', $fileColNames);
    }

    // -------------------------------------------------------------------------
    // Test 2: Local storage adapter put, get, size, exists, delete
    // -------------------------------------------------------------------------
    public function testLocalStorageAdapterBasicOperations(): void
    {
        $storage = new LocalMultimediaStorage($this->tempStorageDir);

        $key = 'movies/1/renditions/720p.mp4';
        $content = "DUMMY_MP4_VIDEO_BINARY_DATA";

        $this->assertTrue($storage->put($key, $content));
        $this->assertTrue($storage->exists($key));
        $this->assertSame(strlen($content), $storage->size($key));
        $this->assertSame($content, $storage->get($key));

        // Read stream
        $stream = $storage->readStream($key);
        $this->assertIsResource($stream);
        $this->assertSame($content, stream_get_contents($stream));
        fclose($stream);

        // Delete
        $this->assertTrue($storage->delete($key));
        $this->assertFalse($storage->exists($key));
    }

    // -------------------------------------------------------------------------
    // Test 3: Local storage adapter strictly prevents path traversal
    // -------------------------------------------------------------------------
    public function testLocalStoragePreventsPathTraversal(): void
    {
        $storage = new LocalMultimediaStorage($this->tempStorageDir);

        // Attempt traversal outside root
        $this->assertSame('', $storage->sanitizeKey('../../../etc/passwd'));
        $this->assertSame('', $storage->sanitizeKey('movies/../../secret.txt'));
        $this->assertFalse($storage->put('../../../etc/malicious.txt', 'evil'));
        $this->assertFalse($storage->exists('../../../etc/malicious.txt'));
    }

    // -------------------------------------------------------------------------
    // Test 4: S3-compatible adapter put, get, exists, size with mock environment
    // -------------------------------------------------------------------------
    public function testS3CompatibleStorageAdapterWithMock(): void
    {
        S3CompatibleMultimediaStorage::setMockEnvironment([
            'available' => true,
            'storage'   => [],
        ]);

        $s3 = new S3CompatibleMultimediaStorage([
            'endpoint'    => 'https://s3.us-east-1.amazonaws.com',
            'bucket'      => 'test-bucket',
            'access_key'  => 'TEST_KEY',
            'secret_key'  => 'TEST_SECRET',
            'path_prefix' => 'media',
        ]);

        $this->assertSame('s3', $s3->getDriverName());

        $key = 'movies/5/rendition.mp4';
        $content = "MOCK_S3_PAYLOAD_DATA";

        $this->assertTrue($s3->put($key, $content, ['mime_type' => 'video/mp4']));
        $this->assertTrue($s3->exists($key));
        $this->assertSame(strlen($content), $s3->size($key));
        $this->assertSame($content, $s3->get($key));

        $this->assertTrue($s3->delete($key));
        $this->assertFalse($s3->exists($key));
    }

    // -------------------------------------------------------------------------
    // Test 5: S3-compatible adapter generates presigned SigV4 URLs
    // -------------------------------------------------------------------------
    public function testS3StorageGeneratesPresignedSigV4Url(): void
    {
        $s3 = new S3CompatibleMultimediaStorage([
            'endpoint'    => 'https://s3.us-west-2.amazonaws.com',
            'bucket'      => 'secure-vault',
            'access_key'  => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key'  => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'path_prefix' => 'multimedia',
        ]);

        $presigned = $s3->generatePresignedSigV4Url('GET', 'multimedia/movies/10/720p.mp4', 300);

        $this->assertStringContainsString('https://s3.us-west-2.amazonaws.com/secure-vault/multimedia/movies/10/720p.mp4', $presigned);
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $presigned);
        $this->assertStringContainsString('X-Amz-Credential=AKIAIOSFODNN7EXAMPLE', $presigned);
        $this->assertStringContainsString('X-Amz-Expires=300', $presigned);
        $this->assertStringContainsString('X-Amz-Signature=', $presigned);
    }

    // -------------------------------------------------------------------------
    // Test 6: Custom CDN base URL rewrites public object URLs
    // -------------------------------------------------------------------------
    public function testCustomCdnBaseUrlRewritesPublicUrl(): void
    {
        $s3 = new S3CompatibleMultimediaStorage([
            'endpoint'     => 'https://s3.amazonaws.com',
            'bucket'       => 'my-bucket',
            'cdn_base_url' => 'https://cdn.example.org',
            'path_prefix'  => 'assets',
        ]);

        $publicUrl = $s3->url('thumbnails/poster.jpg');
        $this->assertSame('https://cdn.example.org/assets/thumbnails/poster.jpg', $publicUrl);
    }

    // -------------------------------------------------------------------------
    // Test 7: S3 storage offline graceful degradation
    // -------------------------------------------------------------------------
    public function testS3StorageOfflineGracefulDegradation(): void
    {
        S3CompatibleMultimediaStorage::setMockEnvironment([
            'available' => false,
        ]);

        $s3 = new S3CompatibleMultimediaStorage(['bucket' => 'offline-bkt']);
        $health = $s3->testConnection();

        $this->assertFalse($health['success']);
        $this->assertStringContainsString('failed', strtolower($health['message']));
    }

    // -------------------------------------------------------------------------
    // Test 8: MediaStorageManager builds deterministic safe keys with Unicode/Bangla
    // -------------------------------------------------------------------------
    public function testMediaStorageManagerBanglaAndUnicodeKeySanitization(): void
    {
        $keyAscii = MediaStorageManager::buildKey('movie', 42, 'renditions', 'trailer_720p.mp4');
        $this->assertSame('movie/42/renditions/trailer_720p.mp4', $keyAscii);

        // Bangla title
        $banglaFilename = "হাওয়া_হাওয়া_1080p.mp4";
        $keyBangla = MediaStorageManager::buildKey('movie', 99, 'renditions', $banglaFilename);
        $this->assertStringContainsString('movie/99/renditions/', $keyBangla);
        $this->assertStringEndsWith('.mp4', $keyBangla);
        $this->assertFalse(str_contains($keyBangla, '..'));
        $this->assertFalse(str_contains($keyBangla, '\\'));
    }

    // -------------------------------------------------------------------------
    // Test 9: MediaStorageManager prevents SSRF vulnerabilities in endpoint
    // -------------------------------------------------------------------------
    public function testMediaStorageManagerEndpointSecurityValidation(): void
    {
        // Safe endpoint
        $res1 = MediaStorageManager::validateEndpointSecurity('https://s3.eu-central-1.amazonaws.com');
        $this->assertTrue($res1['valid']);

        // Forbidden cloud metadata IP
        $res2 = MediaStorageManager::validateEndpointSecurity('http://169.254.169.254/latest/meta-data');
        $this->assertFalse($res2['valid']);
        $this->assertStringContainsString('metadata', strtolower($res2['reason']));

        // Forbidden credentials embedded in URL
        $res3 = MediaStorageManager::validateEndpointSecurity('https://user:pass@s3.amazonaws.com');
        $this->assertFalse($res3['valid']);
    }

    // -------------------------------------------------------------------------
    // Test 10: MediaStorageFile model records assets and aggregates sizes
    // -------------------------------------------------------------------------
    public function testMediaStorageFileModelTrackingAndAggregation(): void
    {
        $file1 = MediaStorageFile::recordFile('local', 'movies/1/720p.mp4', MediaStorageFile::TYPE_RENDITION, 'movie', 1, 10, null, 5000000, 'video/mp4');
        $this->assertNotNull($file1);
        $this->assertSame(5000000, (int)$file1->file_size);

        $file2 = MediaStorageFile::recordFile('local', 'movies/1/thumb.jpg', MediaStorageFile::TYPE_THUMBNAIL, 'movie', 1, 10, null, 150000, 'image/jpeg');
        $this->assertNotNull($file2);

        $totalBytes = MediaStorageFile::getTotalStorageBytes('local');
        $this->assertSame(5150000, $totalBytes);

        $forMovie = MediaStorageFile::getForContent('movie', 1);
        $this->assertCount(2, $forMovie);
    }

    // -------------------------------------------------------------------------
    // Test 11: Media delivery tokens generate, validate, and expire
    // -------------------------------------------------------------------------
    public function testMediaDeliveryTokenGenerationAndValidation(): void
    {
        $token = MediaDeliveryTokenService::generateToken('movies/10/stream.mp4', 300, [
            'content_type' => 'movie',
            'content_id'   => 10,
        ]);

        $this->assertNotEmpty($token['sig']);
        $this->assertGreaterThan(time(), $token['exp']);

        // Valid check
        $this->assertTrue(MediaDeliveryTokenService::validateToken(
            $token['sig'],
            'movies/10/stream.mp4',
            $token['exp'],
            ['content_type' => 'movie', 'content_id' => 10]
        ));

        // Expired check
        $pastExp = time() - 10;
        $this->assertFalse(MediaDeliveryTokenService::validateToken(
            $token['sig'],
            'movies/10/stream.mp4',
            $pastExp,
            ['content_type' => 'movie', 'content_id' => 10]
        ));
    }

    // -------------------------------------------------------------------------
    // Test 12: Media delivery tokens resist tampering and cross-content reuse
    // -------------------------------------------------------------------------
    public function testMediaDeliveryTokenTamperingAndCrossContentIsolation(): void
    {
        $tokenA = MediaDeliveryTokenService::generateToken('movies/1/video.mp4', 300, [
            'content_type' => 'movie',
            'content_id'   => 1,
        ]);

        // 1. Tampered signature
        $tamperedSig = substr($tokenA['sig'], 0, -4) . 'ffff';
        $this->assertFalse(MediaDeliveryTokenService::validateToken(
            $tamperedSig,
            'movies/1/video.mp4',
            $tokenA['exp'],
            ['content_type' => 'movie', 'content_id' => 1]
        ));

        // 2. Cross-content reuse attempt: Token for movie 1 used for movie 2
        $this->assertFalse(MediaDeliveryTokenService::validateToken(
            $tokenA['sig'],
            'movies/2/video.mp4', // Changed key
            $tokenA['exp'],
            ['content_type' => 'movie', 'content_id' => 2]
        ));
    }

    // -------------------------------------------------------------------------
    // Test 13: Protected token stream endpoint serves object only with valid token
    // -------------------------------------------------------------------------
    public function testTokenStreamEndpointEnforcesValidToken(): void
    {
        // Write file in local storage
        $storage = new LocalMultimediaStorage($this->tempStorageDir);
        $key = 'episodes/5/audio.mp3';
        $storage->put($key, 'BINARY_MP3_STREAM_PAYLOAD');

        $ctrl = new MediaPlaybackController($this->app);

        // 1. Missing token
        $badReq = new Request(['key' => $key]);
        $resBad = $ctrl->tokenStream($badReq);
        $this->assertSame(400, $resBad->getStatusCode());

        // 2. Valid token
        $tok = MediaDeliveryTokenService::generateToken($key, 300);
        $goodReq = new Request([
            'key' => $key,
            'exp' => $tok['exp'],
            'sig' => $tok['sig'],
        ]);
        $resGood = $ctrl->tokenStream($goodReq);
        $this->assertSame(200, $resGood->getStatusCode());
        $this->assertSame('BINARY_MP3_STREAM_PAYLOAD', $resGood->getContent());
    }

    // -------------------------------------------------------------------------
    // Test 14: Public content delivers without requiring login or subscription
    // -------------------------------------------------------------------------
    public function testPublicContentDeliversToGuest(): void
    {
        $movie = $this->createMovie(['access_mode' => 'public']);
        $hlsDir = $this->tempStorageDir . '/hls';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        $res = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(200, $res->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 15: Login content denies guest and allows authenticated user
    // -------------------------------------------------------------------------
    public function testLoginContentProtectedFromGuestAllowedForUser(): void
    {
        $movie = $this->createMovie(['access_mode' => 'login']);
        $hlsDir = $this->tempStorageDir . '/hls_login';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');
        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // 1. Guest -> 401
        $resGuest = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(401, $resGuest->getStatusCode());

        // 2. User -> 200
        $this->authenticateUser(10, 'user');
        $resUser = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(200, $resUser->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 16: CRITICAL RULE — Premium content requires Favorite Digital entitlement
    // -------------------------------------------------------------------------
    public function testPremiumContentRequiresFavoriteDigitalEntitlement(): void
    {
        $movie = $this->createMovie(['access_mode' => 'premium']);
        $hlsDir = $this->tempStorageDir . '/hls_prem';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');
        $user = $this->authenticateUser(20, 'user');
        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Favorite Digital Active & Entitled
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_entitled_users'] = [(int)$user->id];

        $res = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(200, $res->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 17: CRITICAL RULE — Premium content fails closed if Favorite Digital down/missing
    // -------------------------------------------------------------------------
    public function testPremiumContentFailsClosedWhenFavoriteDigitalUnavailable(): void
    {
        $movie = $this->createMovie(['access_mode' => 'premium']);
        $hlsDir = $this->tempStorageDir . '/hls_down';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');
        $this->authenticateUser(21, 'user');
        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        // Favorite Digital unavailable
        $GLOBALS['_test_favorite_digital_available'] = false;
        unset($GLOBALS['_test_favorite_digital_entitled_users']);

        $res = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(403, $res->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 18: CRITICAL RULE — Favorite Pay payment alone NEVER grants access
    // -------------------------------------------------------------------------
    public function testFavoritePayPaymentAloneNeverGrantsPremiumAccess(): void
    {
        $movie = $this->createMovie(['access_mode' => 'premium']);
        $hlsDir = $this->tempStorageDir . '/hls_pay';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');
        $user = $this->authenticateUser(22, 'user');

        // Payment recorded in Favorite Pay, BUT no Favorite Digital entitlement
        $GLOBALS['_test_favorite_pay_completed_payments'] = [(int)$user->id => true];
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_entitled_users'] = []; // Not entitled!

        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        $res = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(403, $res->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 19: HLS delivery appends scoped delivery tokens to variants and segments
    // -------------------------------------------------------------------------
    public function testHlsDeliveryAppendsScopedDeliveryTokens(): void
    {
        $movie = $this->createMovie(['access_mode' => 'login']);
        $hlsDir = $this->tempStorageDir . '/hls_tokens';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        file_put_contents($hlsDir . '/720p/index.m3u8', "#EXTM3U\n#EXTINF:6.0,\nseg_000.ts\n");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $this->authenticateUser(1, 'admin');
        $ctrl = new MediaPlaybackController($this->app);
        $req = new Request();

        $resMaster = $ctrl->hlsMaster($req, (string)$source->id);
        $this->assertSame(200, $resMaster->getStatusCode());
        $masterBody = $resMaster->getContent();

        // Master rewrites variant with ?token=...&exp=...
        $this->assertStringContainsString("?token=", $masterBody);
        $this->assertStringContainsString("&exp=", $masterBody);

        // Fetch variant playlist
        $resVariant = $ctrl->hlsVariant($req, (string)$source->id, '720p');
        $this->assertSame(200, $resVariant->getStatusCode());
        $variantBody = $resVariant->getContent();

        // Variant rewrites segment with ?token=...&exp=...
        $this->assertStringContainsString("seg_000.ts?token=", $variantBody);
    }

    // -------------------------------------------------------------------------
    // Test 20: Direct segment guessing without token or session is denied
    // -------------------------------------------------------------------------
    public function testDirectSegmentGuessingDeniedWithoutToken(): void
    {
        $movie = $this->createMovie(['access_mode' => 'login']);
        $hlsDir = $this->tempStorageDir . '/hls_seg_prot';
        @mkdir($hlsDir . '/720p', 0777, true);
        file_put_contents($hlsDir . '/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        file_put_contents($hlsDir . '/720p/seg_000.ts', "TS_DATA_PAYLOAD");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'hls', 'hls', $hlsDir . '/master.m3u8');

        $ctrl = new MediaPlaybackController($this->app);

        // Guest attempts to fetch segment without token
        $reqNoToken = new Request();
        $resNoToken = $ctrl->hlsSegment($reqNoToken, (string)$source->id, '720p', 'seg_000.ts');
        $this->assertSame(401, $resNoToken->getStatusCode());

        // Guest with VALID scoped stream token is permitted
        $tok = MediaDeliveryTokenService::generateHlsStreamToken((int)$source->id, 300);
        $reqWithToken = new Request(['token' => $tok['sig'], 'exp' => $tok['exp']]);
        $resWithToken = $ctrl->hlsSegment($reqWithToken, (string)$source->id, '720p', 'seg_000.ts');
        $this->assertSame(200, $resWithToken->getStatusCode());
        $this->assertSame('TS_DATA_PAYLOAD', $resWithToken->getContent());
    }

    // -------------------------------------------------------------------------
    // Test 21: Processing pipeline registers assets in multimedia_storage_files
    // -------------------------------------------------------------------------
    public function testProcessingPipelineRecordsStorageFiles(): void
    {
        $movie = $this->createMovie();
        $inputPath = $this->tempStorageDir . '/input.mp4';
        file_put_contents($inputPath, "DUMMY_SOURCE_VIDEO");

        $job = MediaProcessingService::dispatchJob('movie', (int)$movie->id, $inputPath, MediaProcessingJob::TYPE_FULL_PIPELINE);
        $this->assertNotNull($job);

        $res = MediaProcessingService::processQueue(1);
        $this->assertSame(1, $res['completed']);

        // Check multimedia_storage_files entries
        $files = MediaStorageFile::getForContent('movie', (int)$movie->id);
        $this->assertNotEmpty($files);

        $types = array_map(fn($f) => $f->file_type, $files);
        $this->assertContains(MediaStorageFile::TYPE_HLS_MASTER, $types);
        $this->assertContains(MediaStorageFile::TYPE_THUMBNAIL, $types);
    }

    // -------------------------------------------------------------------------
    // Test 22: Local media source migration to remote object storage
    // -------------------------------------------------------------------------
    public function testMediaSourceMigrationToRemoteObjectStorage(): void
    {
        S3CompatibleMultimediaStorage::setMockEnvironment([
            'available' => true,
            'storage'   => [],
        ]);

        $movie = $this->createMovie();
        $localVideo = $this->tempStorageDir . '/local_movie.mp4';
        file_put_contents($localVideo, "ORIGINAL_VIDEO_DATA");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'video', 'upload', $localVideo);

        $migRes = MediaStorageService::migrateSourceToRemote($source, false);
        $this->assertTrue($migRes['success']);
        $this->assertSame(1, $migRes['uploaded']);

        // Refreshed source should now point to S3
        $refreshed = MediaSource::find((int)$source->id);
        $this->assertSame('s3', $refreshed->storage_driver);
        $this->assertSame(1, (int)$refreshed->is_migrated);
        $this->assertNotEmpty($refreshed->storage_key);

        // Local copy is preserved by default
        $this->assertFileExists($localVideo);
    }

    // -------------------------------------------------------------------------
    // Test 23: Source migration is resumeable and idempotent
    // -------------------------------------------------------------------------
    public function testSourceMigrationIsResumeable(): void
    {
        S3CompatibleMultimediaStorage::setMockEnvironment([
            'available' => true,
            'storage'   => [],
        ]);

        $movie = $this->createMovie();
        $localVideo = $this->tempStorageDir . '/resume_movie.mp4';
        file_put_contents($localVideo, "VIDEO_RESUME_DATA");

        $source = $this->createMediaSource('movie', (int)$movie->id, 'active', 'video', 'upload', $localVideo);

        // 1st run
        $res1 = MediaStorageService::migrateSourceToRemote($source, false);
        $this->assertTrue($res1['success']);

        // 2nd run
        $res2 = MediaStorageService::migrateSourceToRemote($source, false);
        $this->assertTrue($res2['success']);
    }

    // -------------------------------------------------------------------------
    // Test 24: Content cascade cleanup deletes associated storage objects
    // -------------------------------------------------------------------------
    public function testContentCascadeCleanupDeletesStorageObjects(): void
    {
        $movie = $this->createMovie();
        $storage = new LocalMultimediaStorage($this->tempStorageDir);

        $key1 = "movie/{$movie->id}/renditions/1080p.mp4";
        $key2 = "movie/{$movie->id}/thumbnails/thumb.jpg";
        $storage->put($key1, '1080p');
        $storage->put($key2, 'thumb');

        MediaStorageFile::recordFile('local', $key1, MediaStorageFile::TYPE_RENDITION, 'movie', (int)$movie->id, null, null, 5, 'video/mp4');
        MediaStorageFile::recordFile('local', $key2, MediaStorageFile::TYPE_THUMBNAIL, 'movie', (int)$movie->id, null, null, 5, 'image/jpeg');

        $this->assertTrue($storage->exists($key1));
        $this->assertTrue($storage->exists($key2));

        // Cascade cleanup
        $deleted = MediaStorageService::cleanupContentStorage('movie', (int)$movie->id);
        $this->assertSame(2, $deleted);

        $this->assertFalse($storage->exists($key1));
        $this->assertFalse($storage->exists($key2));
        $this->assertEmpty(MediaStorageFile::getForContent('movie', (int)$movie->id));
    }

    // -------------------------------------------------------------------------
    // Test 25: Orphan scanning and dry-run cleanup
    // -------------------------------------------------------------------------
    public function testOrphanScanningAndDryRunCleanup(): void
    {
        // Record orphan file (entity 99999 does not exist)
        MediaStorageFile::recordFile('local', 'movie/99999/orphaned.mp4', MediaStorageFile::TYPE_RENDITION, 'movie', 99999, null, null, 1048576, 'video/mp4');

        $scan = MediaStorageService::scanOrphans();
        $this->assertGreaterThanOrEqual(1, $scan['count']);
        $this->assertGreaterThanOrEqual(1048576, $scan['total_bytes']);

        // Dry run cleanup does NOT delete database records
        $dry = MediaStorageService::cleanupOrphans(true, 100);
        $this->assertTrue($dry['dry_run']);
        $this->assertGreaterThanOrEqual(1, $dry['count']);

        // Execute cleanup
        $exec = MediaStorageService::cleanupOrphans(false, 100);
        $this->assertFalse($exec['dry_run']);
        $this->assertGreaterThanOrEqual(1, $exec['count']);
    }

    // -------------------------------------------------------------------------
    // Test 26: Storage settings secret key is masked and never exposed in HTML
    // -------------------------------------------------------------------------
    public function testStorageSettingsSecretKeyMaskedInAdmin(): void
    {
        $this->authenticateUser(1, 'admin');
        Setting::set('multimedia', 's3_secret_key', 'SUPER_SECRET_AWS_KEY_123');

        $adminCtrl = new MultimediaAdminController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $res = $adminCtrl->storage($req);
        $html = is_string($res) ? $res : $res->getContent();

        $this->assertStringNotContainsString('SUPER_SECRET_AWS_KEY_123', $html);
        $this->assertStringContainsString('••••••••', $html);
    }

    // -------------------------------------------------------------------------
    // Test 27: Admin storage API mutation endpoints enforce CSRF & permissions
    // -------------------------------------------------------------------------
    public function testAdminStorageApiEnforcesCsrfAndPermissions(): void
    {
        $adminCtrl = new MultimediaAdminController($this->app);

        // 1. Non-admin denied
        $this->authenticateUser(50, 'user');
        $reqNonAdmin = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $res1 = $adminCtrl->apiSaveStorageSettings($reqNonAdmin);
        $this->assertSame(403, $res1->getStatusCode());

        // 2. Admin without CSRF token denied
        $this->authenticateUser(1, 'admin');
        $_SESSION['csrf_token'] = 'valid_token_ph11';
        $reqNoCsrf = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $res2 = $adminCtrl->apiSaveStorageSettings($reqNoCsrf);
        $this->assertSame(403, $res2->getStatusCode());

        // 3. Admin with valid CSRF token succeeds
        $reqValid = new Request([], ['_token' => 'valid_token_ph11', 'multimedia_storage_driver' => 'local'], ['REQUEST_METHOD' => 'POST']);
        $res3 = $adminCtrl->apiSaveStorageSettings($reqValid);
        $this->assertSame(200, $res3->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 28: Full multi-phase regression across all multimedia services
    // -------------------------------------------------------------------------
    public function testFullMultiPhaseRegression(): void
    {
        $movie = $this->createMovie();
        $source = $this->createMediaSource('movie', (int)$movie->id);

        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id));
        $this->assertNotNull(MediaProcessingJob::findPending());
        $this->assertNotNull(MediaStorageManager::getDisk('local'));
    }
}
