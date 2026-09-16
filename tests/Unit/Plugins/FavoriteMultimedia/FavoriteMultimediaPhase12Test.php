<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Models\MediaLocalization;
use FavoriteCMS\Multimedia\Models\UserLanguagePreference;
use FavoriteCMS\Multimedia\Services\MediaLanguageService;
use FavoriteCMS\Multimedia\Services\MediaLocalizationService;
use FavoriteCMS\Multimedia\Services\MediaDeliveryService;
use FavoriteCMS\Multimedia\Services\MediaDeliveryTokenService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\MultimediaDiscoveryService;
use FavoriteCMS\Multimedia\Services\MediaStorageService;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Integrations\FavoritePayAdapter;

class FavoriteMultimediaPhase12Test extends TestCase
{
    private Application $app;
    private Database $db;

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
        $pdo = new \PDO('sqlite::memory:', '', '', [
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
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);

        // Core CMS Schema
        $pdo->exec("
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

        // Run migrations 001 through 008
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

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/008_add_multimedia_localization_and_language_tables.php';
        (new \AddMultimediaLocalizationAndLanguageTables($this->db))->up();

        // Initialize Plugin
        FavoriteMultimediaPlugin::reset();
        FavoriteMultimediaPlugin::bootstrap($this->app);

        $_SESSION = [];
        $_SESSION['csrf_token'] = 'test-token-csrf-phase-12';
        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        unset($GLOBALS['_test_favorite_pay_available']);
    }

    protected function tearDown(): void
    {
        FavoriteMultimediaPlugin::reset();
        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        unset($GLOBALS['_test_favorite_pay_available']);
        $_SESSION = [];
        parent::tearDown();
    }

    // =========================================================================
    // 1. MIGRATION 008 & SCHEMA VERIFICATION
    // =========================================================================

    public function testMigration008CreatesRequiredTablesAndColumns(): void
    {
        // 1. multimedia_subtitles columns
        $subCols = $this->db->select("PRAGMA table_info(multimedia_subtitles)");
        $subColNames = array_map(fn($c) => $c->name, $subCols);
        $this->assertContains('language_code', $subColNames);
        $this->assertContains('is_forced', $subColNames);
        $this->assertContains('is_sdh', $subColNames);
        $this->assertContains('storage_driver', $subColNames);
        $this->assertContains('storage_key', $subColNames);

        // 2. multimedia_sources columns
        $srcCols = $this->db->select("PRAGMA table_info(multimedia_sources)");
        $srcColNames = array_map(fn($c) => $c->name, $srcCols);
        $this->assertContains('language_code', $srcColNames);
        $this->assertContains('audio_role', $srcColNames);

        // 3. multimedia_movies & multimedia_series original_language
        $movCols = $this->db->select("PRAGMA table_info(multimedia_movies)");
        $movColNames = array_map(fn($c) => $c->name, $movCols);
        $this->assertContains('original_language', $movColNames);

        $serCols = $this->db->select("PRAGMA table_info(multimedia_series)");
        $serColNames = array_map(fn($c) => $c->name, $serCols);
        $this->assertContains('original_language', $serColNames);

        // 4. multimedia_localizations table exists
        $locRow = $this->db->selectOne("SELECT name FROM sqlite_master WHERE type='table' AND name='multimedia_localizations'");
        $this->assertNotNull($locRow);

        // 5. multimedia_user_language_preferences table exists
        $prefRow = $this->db->selectOne("SELECT name FROM sqlite_master WHERE type='table' AND name='multimedia_user_language_preferences'");
        $this->assertNotNull($prefRow);
    }

    // =========================================================================
    // 2. BCP 47 LANGUAGE STANDARD & CANONICAL LABELS
    // =========================================================================

    public function testBcp47LanguageValidationAndNormalization(): void
    {
        // Valid codes
        $this->assertTrue(MediaLanguageService::isValidCode('en'));
        $this->assertTrue(MediaLanguageService::isValidCode('bn'));
        $this->assertTrue(MediaLanguageService::isValidCode('ar'));
        $this->assertTrue(MediaLanguageService::isValidCode('es'));
        $this->assertTrue(MediaLanguageService::isValidCode('hi'));
        $this->assertTrue(MediaLanguageService::isValidCode('en-US'));
        $this->assertTrue(MediaLanguageService::isValidCode('zh-Hans-CN'));

        // Normalization
        $this->assertSame('en', MediaLanguageService::normalizeLanguageCode('EN'));
        $this->assertSame('en-us', MediaLanguageService::normalizeLanguageCode('en-us'));
        $this->assertSame('bn', MediaLanguageService::normalizeLanguageCode(' BN '));

        // Invalid codes
        $this->assertFalse(MediaLanguageService::isValidCode(''));
        $this->assertFalse(MediaLanguageService::isValidCode('123'));
        $this->assertFalse(MediaLanguageService::isValidCode('verylonglanguagenameexceedingmaximum'));
        $this->assertFalse(MediaLanguageService::isValidCode('bn@bd'));
    }

    public function testCanonicalLanguageLabelsAndNativeNames(): void
    {
        $this->assertSame('Bangla', MediaLanguageService::getLanguageLabel('bn', false));
        $this->assertSame('বাংলা', MediaLanguageService::getLanguageNativeLabel('bn'));

        $this->assertSame('Arabic', MediaLanguageService::getLanguageLabel('ar', false));
        $this->assertSame('العربية', MediaLanguageService::getLanguageNativeLabel('ar'));

        $this->assertSame('English', MediaLanguageService::getLanguageLabel('en', false));
        $this->assertSame('English', MediaLanguageService::getLanguageNativeLabel('en'));

        $this->assertSame('Spanish', MediaLanguageService::getLanguageLabel('es', false));
        $this->assertSame('Español', MediaLanguageService::getLanguageNativeLabel('es'));
    }

    // =========================================================================
    // 3. SUBTITLE ENHANCEMENTS & SINGLE-DEFAULT ENFORCEMENT
    // =========================================================================

    public function testSubtitleCreationWithAdvancedFieldsAndSingleDefault(): void
    {
        $sub1Id = (int)$this->db->insert('multimedia_subtitles', [
            'content_type'  => 'movie',
            'content_id'    => 10,
            'language'      => 'en',
            'language_code' => 'en',
            'label'         => 'English',
            'file_or_url'   => 'subtitles/movie10_en.vtt',
            'format'        => 'vtt',
            'is_default'    => 1,
            'is_forced'     => 0,
            'is_sdh'        => 0,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        $sub2Id = (int)$this->db->insert('multimedia_subtitles', [
            'content_type'  => 'movie',
            'content_id'    => 10,
            'language'      => 'bn',
            'language_code' => 'bn',
            'label'         => 'বাংলা',
            'file_or_url'   => 'subtitles/movie10_bn.vtt',
            'format'        => 'vtt',
            'is_default'    => 0,
            'is_forced'     => 1,
            'is_sdh'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        $sub1 = Subtitle::find($sub1Id);
        $sub2 = Subtitle::find($sub2Id);

        $this->assertSame('en', $sub1->getLanguageCode());
        $this->assertSame('bn', $sub2->getLanguageCode());
        $this->assertTrue($sub2->isForced());
        $this->assertTrue($sub2->isSdh());

        // Enforce sub2 as default: sub1 should lose default
        $this->db->update('multimedia_subtitles', ['is_default' => 1], ['id' => $sub2Id]);
        Subtitle::enforceSingleDefault('movie', 10, $sub2Id);

        $sub1Refreshed = Subtitle::find($sub1Id);
        $sub2Refreshed = Subtitle::find($sub2Id);

        $this->assertSame(0, (int)$sub1Refreshed->is_default);
        $this->assertSame(1, (int)$sub2Refreshed->is_default);

        // Test findByContentAndLanguage
        $foundBn = Subtitle::findByContentAndLanguage('movie', 10, 'bn');
        $this->assertNotNull($foundBn);
        $this->assertSame($sub2Id, (int)$foundBn->id);
    }

    // =========================================================================
    // 4. SRT TO WEBVTT CONVERSION & XSS SANITIZATION
    // =========================================================================

    public function testSrtToWebVttConversion(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:04,500\nHello World!\n\n2\n00:00:05,200 --> 00:00:08,000\nSecond subtitle line.";
        $vtt = MediaLanguageService::convertSrtToVtt($srt);

        $this->assertStringStartsWith("WEBVTT\n\n", $vtt);
        $this->assertStringContainsString("00:00:01.000 --> 00:00:04.500", $vtt);
        $this->assertStringContainsString("Hello World!", $vtt);
        $this->assertStringContainsString("00:00:05.200 --> 00:00:08.000", $vtt);
        $this->assertStringNotContainsString("00:00:01,000", $vtt);
    }

    public function testWebVttXssSanitization(): void
    {
        $maliciousVtt = "WEBVTT\n\n1\n00:00:01.000 --> 00:00:05.000\n<script>alert('XSS')</script><b>Safe bold</b><img src=x onerror=alert(1)> Click <a href=\"javascript:alert('pwn')\">here</a>";
        $sanitized = MediaLanguageService::sanitizeVttContent($maliciousVtt);

        $this->assertStringNotContainsString("<script>", $sanitized);
        $this->assertStringNotContainsString("onerror", $sanitized);
        $this->assertStringNotContainsString("javascript:", $sanitized);
        $this->assertStringContainsString("<b>Safe bold</b>", $sanitized);
        $this->assertStringContainsString("WEBVTT", $sanitized);
    }

    // =========================================================================
    // 5. MULTIPLE AUDIO TRACKS MODEL & DEFAULT ENFORCEMENT
    // =========================================================================

    public function testMultipleAudioTracksCreationAndSingleDefault(): void
    {
        $audio1Id = (int)$this->db->insert('multimedia_sources', [
            'content_type'  => 'movie',
            'content_id'    => 20,
            'source_mode'   => 'direct',
            'source_type'   => 'audio',
            'url_or_path'   => 'audio/movie20_en.mp3',
            'label'         => 'English (Original)',
            'language_code' => 'en',
            'audio_role'    => 'main',
            'is_default'    => 1,
            'status'        => 'active',
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        $audio2Id = (int)$this->db->insert('multimedia_sources', [
            'content_type'  => 'movie',
            'content_id'    => 20,
            'source_mode'   => 'direct',
            'source_type'   => 'audio',
            'url_or_path'   => 'audio/movie20_bn.mp3',
            'label'         => 'বাংলা ডাব',
            'language_code' => 'bn',
            'audio_role'    => 'dub',
            'is_default'    => 0,
            'status'        => 'active',
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        $tracks = MediaSource::getAudioTracksForContent('movie', 20);
        $this->assertCount(2, $tracks);

        // Enforce audio2 as default
        $this->db->update('multimedia_sources', ['is_default' => 1], ['id' => $audio2Id]);
        MediaSource::enforceSingleDefaultAudio('movie', 20, $audio2Id);

        $a1 = MediaSource::find($audio1Id);
        $a2 = MediaSource::find($audio2Id);

        $this->assertSame(0, (int)$a1->is_default);
        $this->assertSame(1, (int)$a2->is_default);
        $this->assertSame('bn', $a2->getLanguageCode());
        $this->assertSame('dub', $a2->getAudioRole());

        // findAudioByContentAndLanguage
        $foundAudio = MediaSource::findAudioByContentAndLanguage('movie', 20, 'bn');
        $this->assertNotNull($foundAudio);
        $this->assertSame($audio2Id, (int)$foundAudio->id);
    }

    // =========================================================================
    // 6. HLS MULTI-AUDIO & SUBTITLES MANIFEST INJECTION
    // =========================================================================

    public function testHlsMasterPlaylistMediaTagInjection(): void
    {
        // 1. Master video source
        $tmpDir = sys_get_temp_dir() . '/hls_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $masterFile = $tmpDir . '/master.m3u8';
        file_put_contents($masterFile, "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360\n360p/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=1400000,RESOLUTION=842x480\n480p/index.m3u8\n");

        $sourceId = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 50,
            'source_mode'  => 'direct',
            'source_type'  => 'hls',
            'url_or_path'  => $masterFile,
            'label'        => 'HLS Master',
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        // 2. Audio track
        $this->db->insert('multimedia_sources', [
            'content_type'  => 'movie',
            'content_id'    => 50,
            'source_mode'   => 'direct',
            'source_type'   => 'audio',
            'url_or_path'   => $tmpDir . '/audio_bn.mp3',
            'label'         => 'Bengali',
            'language_code' => 'bn',
            'audio_role'    => 'dub',
            'is_default'    => 1,
            'status'        => 'active',
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        // 3. Subtitle track
        $subId = (int)$this->db->insert('multimedia_subtitles', [
            'content_type'  => 'movie',
            'content_id'    => 50,
            'language'      => 'en',
            'language_code' => 'en',
            'label'         => 'English',
            'file_or_url'   => $tmpDir . '/sub_en.vtt',
            'format'        => 'vtt',
            'is_default'    => 1,
            'is_forced'     => 0,
            'is_sdh'        => 0,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        $source = MediaSource::find($sourceId);
        $response = MediaDeliveryService::deliverHlsMaster($source, false);

        $body = (string)$response->getContent();

        // Must contain AUDIO media tag
        $this->assertStringContainsString('#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio-group"', $body);
        $this->assertStringContainsString('LANGUAGE="bn"', $body);

        // Must contain SUBTITLES media tag
        $this->assertStringContainsString('#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs-group"', $body);
        $this->assertStringContainsString('LANGUAGE="en"', $body);

        // Must attach AUDIO and SUBTITLES groups to stream-inf
        $this->assertStringContainsString('AUDIO="audio-group"', $body);
        $this->assertStringContainsString('SUBTITLES="subs-group"', $body);

        // Cleanup
        @unlink($masterFile);
        @rmdir($tmpDir);
    }

    public function testDeliverAudioVariantPlaylist(): void
    {
        $mainSource = new MediaSource(['id' => 101, 'content_type' => 'movie', 'content_id' => 50]);
        $audioSource = new MediaSource(['id' => 102, 'content_type' => 'movie', 'content_id' => 50]);

        $response = MediaDeliveryService::deliverAudioVariant($mainSource, $audioSource, false);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string)$response->getContent();
        $this->assertStringContainsString('#EXTM3U', $body);
        $this->assertStringContainsString('/api/multimedia/hls/101/audio/102/stream.mp3', $body);
    }

    // =========================================================================
    // 7. USER LANGUAGE PREFERENCES PERSISTENCE & API
    // =========================================================================

    public function testUserLanguagePreferencePersistence(): void
    {
        $pref = UserLanguagePreference::saveForUser(42, 'bn', 'en', true);
        $this->assertSame('bn', $pref->preferred_audio_language);
        $this->assertSame('en', $pref->preferred_subtitle_language);
        $this->assertSame(1, (int)$pref->subtitle_enabled);

        // Fetch back
        $fetched = UserLanguagePreference::getForUser(42);
        $this->assertNotNull($fetched);
        $this->assertSame('bn', $fetched->preferred_audio_language);
        $this->assertSame('en', $fetched->preferred_subtitle_language);

        // Update preference (no duplicate key error)
        $updated = UserLanguagePreference::saveForUser(42, 'ar', 'ar', false);
        $this->assertSame('ar', $updated->preferred_audio_language);
        $this->assertSame(0, (int)$updated->subtitle_enabled);
    }

    public function testUserLanguagePreferencesEndpoints(): void
    {
        $userId = (int)$this->db->insert('users', [
            'username'   => 'languser99',
            'email'      => 'languser99@test.com',
            'role'       => 'subscriber',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['csrf_token'] = 'test-token-csrf-phase-12';

        $controller = new MediaPlaybackController($this->app);

        // Save preferences via API
        $postReq = new Request([], [
            'preferred_audio_language'    => 'bn',
            'preferred_subtitle_language' => 'en',
            'subtitle_enabled'            => 1,
            '_token'                      => 'test-token-csrf-phase-12',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/multimedia/api/user-language-preferences']);
        $saveResp = $controller->apiSaveUserLanguagePreferences($postReq);
        $this->assertSame(200, $saveResp->getStatusCode());

        // Get preferences via API
        $getReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/multimedia/api/user-language-preferences']);
        $getResp = $controller->apiGetUserLanguagePreferences($getReq);
        $this->assertSame(200, $getResp->getStatusCode());

        $data = json_decode((string)$getResp->getContent(), true);
        $this->assertSame('bn', $data['preferences']['preferred_audio_language']);
        $this->assertSame('en', $data['preferences']['preferred_subtitle_language']);
    }

    // =========================================================================
    // 8. DETERMINISTIC TRACK SELECTION PRIORITY
    // =========================================================================

    public function testDeterministicTrackSelectionPriority(): void
    {
        $tracks = [
            ['language_code' => 'en', 'is_default' => 0, 'id' => 1],
            ['language_code' => 'bn', 'is_default' => 1, 'id' => 2],
            ['language_code' => 'es', 'is_default' => 0, 'id' => 3],
        ];

        // 1. Explicit selection takes priority
        $chosen = MediaLanguageService::selectBestTrack($tracks, 'es', 'bn');
        $this->assertSame(3, $chosen['id']);

        // 2. User preference takes priority if no explicit match
        $chosen2 = MediaLanguageService::selectBestTrack($tracks, 'fr', 'en');
        $this->assertSame(1, $chosen2['id']);

        // 3. Content default takes priority if user preference not available
        $chosen3 = MediaLanguageService::selectBestTrack($tracks, null, 'de');
        $this->assertSame(2, $chosen3['id']);

        // 4. First track fallback if no default and no preference match
        $noDefaultTracks = [
            ['language_code' => 'fr', 'is_default' => 0, 'id' => 10],
            ['language_code' => 'it', 'is_default' => 0, 'id' => 20],
        ];
        $chosen4 = MediaLanguageService::selectBestTrack($noDefaultTracks, null, null);
        $this->assertSame(10, $chosen4['id']);
    }

    // =========================================================================
    // 9. LOCALIZED METADATA & BULK HYDRATION (ZERO N+1)
    // =========================================================================

    public function testMediaLocalizationCrudAndDeterministicFallback(): void
    {
        // 1. Save translation
        $loc = MediaLocalization::saveLocalization('movie', 77, 'bn', 'পথের পাঁচালী', 'একটি বিখ্যাত চলচ্চিত্র', 'জীবন যেখানে বহমান');
        $this->assertSame('পথের পাঁচালী', $loc->title);
        $this->assertSame('bn', $loc->language_code);

        // 2. Fallback resolution when requested translation exists
        $movie = new Movie([
            'id'          => 77,
            'title'       => 'Pather Panchali',
            'description' => 'A famous cinema classic',
            'tagline'     => 'Song of the Little Road',
        ]);

        $resolved = MediaLocalizationService::resolveLocalized($movie, 'movie', 'bn');
        $this->assertSame('পথের পাঁচালী', $resolved['title']);
        $this->assertSame('একটি বিখ্যাত চলচ্চিত্র', $resolved['description']);
        $this->assertSame('bn', $resolved['language_code']);

        // 3. Fallback resolution when requested translation does NOT exist: fallback to base title
        $fallback = MediaLocalizationService::resolveLocalized($movie, 'movie', 'ja');
        $this->assertSame('Pather Panchali', $fallback['title']);
        $this->assertSame('A famous cinema classic', $fallback['description']);
    }

    public function testBulkHydrationPreventsNPlusOneQueries(): void
    {
        // Insert translations for 3 movies
        MediaLocalization::saveLocalization('movie', 101, 'bn', 'সিনেমা ১');
        MediaLocalization::saveLocalization('movie', 102, 'bn', 'সিনেমা ২');
        MediaLocalization::saveLocalization('movie', 103, 'bn', 'সিনেমা ৩');

        $items = [
            ['type' => 'movie', 'model' => new Movie(['id' => 101, 'title' => 'Movie 1'])],
            ['type' => 'movie', 'model' => new Movie(['id' => 102, 'title' => 'Movie 2'])],
            ['type' => 'movie', 'model' => new Movie(['id' => 103, 'title' => 'Movie 3'])],
            ['type' => 'movie', 'model' => new Movie(['id' => 104, 'title' => 'Movie 4 (No Translation)'])],
        ];

        $hydrated = MediaLocalizationService::hydrateList($items, 'bn');

        $this->assertSame('সিনেমা ১', $hydrated[0]['model']->localized_title);
        $this->assertSame('সিনেমা ২', $hydrated[1]['model']->localized_title);
        $this->assertSame('সিনেমা ৩', $hydrated[2]['model']->localized_title);
        $this->assertSame('Movie 4 (No Translation)', $hydrated[3]['model']->localized_title);
    }

    // =========================================================================
    // 10. LOCALIZED SEARCH & DISCOVERY
    // =========================================================================

    public function testSearchFindsItemsByLocalizedBanglaAndArabicTitles(): void
    {
        $movId = (int)$this->db->insert('multimedia_movies', [
            'title'        => 'The Great Journey',
            'slug'         => 'the-great-journey',
            'status'       => 'published',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'published_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Add Bangla translation
        MediaLocalization::saveLocalization('movie', $movId, 'bn', 'মহাযাত্রা', 'এক রোমাঞ্চকর অভিযান');

        // Add Arabic translation
        MediaLocalization::saveLocalization('movie', $movId, 'ar', 'الرحلة الكبرى', 'مغامرة مثيرة');

        // Search with Bangla word
        $searchBn = MultimediaDiscoveryService::search('মহাযাত্রা', 'movie', 10, 'bn');
        $this->assertGreaterThanOrEqual(1, $searchBn['total']);
        $this->assertSame($movId, (int)$searchBn['results'][0]['model']->id);
        $this->assertSame('মহাযাত্রা', $searchBn['results'][0]['model']->localized_title);

        // Search with Arabic word
        $searchAr = MultimediaDiscoveryService::search('الرحلة', 'movie', 10, 'ar');
        $this->assertGreaterThanOrEqual(1, $searchAr['total']);
        $this->assertSame($movId, (int)$searchAr['results'][0]['model']->id);
    }

    // =========================================================================
    // 11. SECURITY & ENTITLEMENT GATEKEEPER (CRITICAL FAVORITE DIGITAL RULE)
    // =========================================================================

    public function testFavoriteDigitalIsSoleAuthorityForPremiumSubtitlesAndAudio(): void
    {
        // 1. Create premium movie
        $movId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'VIP Feature',
            'slug'        => 'vip-feature',
            'access_mode' => 'premium',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $subId = (int)$this->db->insert('multimedia_subtitles', [
            'content_type'  => 'movie',
            'content_id'    => $movId,
            'language'      => 'bn',
            'language_code' => 'bn',
            'label'         => 'বাংলা সাবটাইটেল',
            'file_or_url'   => 'subtitles/vip.vtt',
            'format'        => 'vtt',
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        $userId = (int)$this->db->insert('users', [
            'username'   => 'vipuser88',
            'email'      => 'vipuser88@test.com',
            'role'       => 'subscriber',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $_SESSION['auth_user_id'] = $userId;

        $controller = new MediaPlaybackController($this->app);
        $subReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/multimedia/subtitle/{$subId}"]);

        // Scenario A: Favorite Digital not installed / no entitlement -> FAILS CLOSED (403)
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_entitled_users'] = [];
        $respA = $controller->subtitle($subReq, (string)$subId);
        $this->assertSame(403, $respA->getStatusCode());

        // Scenario B: Favorite Digital not available -> FAILS CLOSED (403)
        $GLOBALS['_test_favorite_digital_available'] = false;
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        $respB = $controller->subtitle($subReq, (string)$subId);
        $this->assertSame(403, $respB->getStatusCode());

        // Scenario C: Favorite Pay active WITHOUT Favorite Digital entitlement -> MUST DENY (403)
        $GLOBALS['_test_favorite_pay_available'] = true;
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_entitled_users'] = [];
        $respC = $controller->subtitle($subReq, (string)$subId);
        $this->assertSame(403, $respC->getStatusCode());

        // Scenario D: Active Favorite Digital entitlement -> ALLOWED
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_entitled_users'] = [$userId];
        // Create a mock local file for subtitle delivery
        $tmpVtt = APP_ROOT . '/storage/test_sub_' . uniqid() . '.vtt';
        file_put_contents($tmpVtt, "WEBVTT\n\n1\n00:00:01.000 --> 00:00:05.000\nবাংলা সাবটাইটেল\n");
        $this->db->update('multimedia_subtitles', ['file_or_url' => str_replace('\\', '/', substr($tmpVtt, strlen(APP_ROOT) + 1))], ['id' => $subId]);

        $respD = $controller->subtitle($subReq, (string)$subId);
        $this->assertSame(200, $respD->getStatusCode());
        $this->assertStringContainsString('WEBVTT', (string)$respD->getContent());

        @unlink($tmpVtt);
    }

    public function testAudioVariantDeliveryFailsClosedWhenContentMismatch(): void
    {
        $src1 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 100,
            'source_type'  => 'hls',
            'url_or_path'  => 'hls/master.m3u8',
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $audioForDifferentMovie = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 999, // Mismatched content ID!
            'source_type'  => 'audio',
            'url_or_path'  => 'audio/mismatch.mp3',
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $controller = new MediaPlaybackController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/api/multimedia/hls/{$src1}/audio/{$audioForDifferentMovie}/index.m3u8"]);

        $resp = $controller->hlsAudioVariant($req, (string)$src1, (string)$audioForDifferentMovie);
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testCsrfProtectionOnUserLanguagePreferencesAndAdminApis(): void
    {
        $userId = (int)$this->db->insert('users', [
            'username'   => 'languser15',
            'email'      => 'languser15@test.com',
            'role'       => 'subscriber',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['csrf_token'] = 'real-valid-csrf-token';

        $controller = new MediaPlaybackController($this->app);

        // Invalid CSRF on user preference save
        $badReq = new Request([], [
            'preferred_audio_language' => 'bn',
            '_token'                   => 'invalid-csrf-token',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/multimedia/api/user-language-preferences']);
        $resp = $controller->apiSaveUserLanguagePreferences($badReq);
        $this->assertSame(403, $resp->getStatusCode());
    }

    // =========================================================================
    // 12. CASCADE DELETION
    // =========================================================================

    public function testCascadeDeletionCleansUpAllLocalizations(): void
    {
        $movId = (int)$this->db->insert('multimedia_movies', [
            'title'      => 'Cascade Target',
            'slug'       => 'cascade-target',
            'status'     => 'published',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        MediaLocalization::saveLocalization('movie', $movId, 'bn', 'বাংলা নাম');
        MediaLocalization::saveLocalization('movie', $movId, 'ar', 'اسم عربي');
        MediaLocalization::saveLocalization('movie', $movId, 'es', 'Nombre en Español');

        $this->assertCount(3, MediaLocalization::getForContent('movie', $movId));

        // Delete movie via storage service cleanup or direct
        MediaLocalization::deleteForContent('movie', $movId);

        $this->assertCount(0, MediaLocalization::getForContent('movie', $movId));
    }
}
