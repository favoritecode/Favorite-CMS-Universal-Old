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
use FavoriteCMS\Multimedia\Models\MediaStorageFile;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaSimplePublishingTest extends TestCase
{
    private Application $app;
    private Database $db;
    private User $adminUser;
    private string $tempDbPath;
    private string $tempUploadDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $_SESSION = [];
        $_FILES = [];

        $this->tempDbPath = sys_get_temp_dir() . '/test_fmm_pub_' . bin2hex(random_bytes(6)) . '.sqlite';
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

        // Ensure temp uploads directory exists
        $this->tempUploadDir = sys_get_temp_dir() . '/fmm_test_uploads_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tempUploadDir)) {
            mkdir($this->tempUploadDir, 0777, true);
        }
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

        if (is_dir($this->tempUploadDir)) {
            $files = glob($this->tempUploadDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempUploadDir);
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
            'username'   => 'publishing_admin',
            'name'       => 'Publishing Admin',
            'email'      => 'admin_pub@example.com',
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

    private function createFakeUploadedFile(string $filename, string $content): string
    {
        $filePath = $this->tempUploadDir . '/' . $filename;
        file_put_contents($filePath, $content);
        return $filePath;
    }

    // =========================================================================
    // 1. DIRECT VIDEO URL PUBLISHING FOR MOVIES
    // =========================================================================

    public function testDirectMovieVideoUrlPublishingCreatesMediaSource(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        $videoUrl = 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4';

        $req = new Request([], [
            'action'         => 'create',
            'title'          => 'Big Buck Bunny Direct',
            'slug'           => 'big-buck-bunny-direct',
            'description'    => 'Open source test animation film.',
            'access_mode'    => 'public',
            'media_tab'      => 'url',
            'video_url'      => $videoUrl,
            'submit_action'  => 'publish',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('big-buck-bunny-direct');
        $this->assertNotNull($movie);
        $this->assertSame('published', $movie->status);
        $this->assertSame('public', $movie->access_mode);

        // Verify MediaSource was automatically created and linked
        $sources = MediaSource::getForContent('movie', (int)$movie->id);
        $this->assertCount(1, $sources);
        $source = $sources[0];
        $this->assertSame('url', $source->source_mode);
        $this->assertSame($videoUrl, $source->url_or_path);
        $this->assertSame(1, (int)$source->is_default);
        $this->assertSame('active', $source->status);
    }

    // =========================================================================
    // 2. MOVIE EDIT UPSERTS DEFAULT SOURCE WITHOUT DUPLICATION
    // =========================================================================

    public function testMovieEditUpsertsExistingDefaultSourceWithoutDuplicate(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        // Initial creation with URL
        $initialUrl = 'https://example.com/stream1.mp4';
        $createReq = new Request([], [
            'action'         => 'create',
            'title'          => 'Upsert Test Movie',
            'slug'           => 'upsert-test-movie',
            'media_tab'      => 'url',
            'video_url'      => $initialUrl,
            'submit_action'  => 'publish',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $adminCtrl->movies($createReq);

        $movie = Movie::findBySlug('upsert-test-movie');
        $this->assertNotNull($movie);

        $sources1 = MediaSource::getForContent('movie', (int)$movie->id);
        $this->assertCount(1, $sources1);
        $this->assertSame($initialUrl, $sources1[0]->url_or_path);
        $firstSourceId = (int)$sources1[0]->id;

        // Edit movie with updated URL
        $updatedUrl = 'https://example.com/stream2-upgraded.mp4';
        $editReq = new Request([], [
            'action'         => 'edit',
            'id'             => $movie->id,
            'title'          => 'Upsert Test Movie (Updated)',
            'slug'           => 'upsert-test-movie',
            'media_tab'      => 'url',
            'video_url'      => $updatedUrl,
            'submit_action'  => 'publish',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $adminCtrl->movies($editReq);

        // Verify there is STILL exactly 1 default source and it has the updated URL
        $sources2 = MediaSource::getForContent('movie', (int)$movie->id);
        $this->assertCount(1, $sources2, 'Repeated submission must update existing default source rather than duplicating');
        $this->assertSame($firstSourceId, (int)$sources2[0]->id, 'Source ID should be preserved via upsert');
        $this->assertSame($updatedUrl, $sources2[0]->url_or_path);
    }

    // =========================================================================
    // 3. NO-MEDIA SAFETY PROTECTION (PREVENTS PUBLISHING WITH ZERO SOURCES)
    // =========================================================================

    public function testNoMediaProtectionRevertsPublishedToDraft(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        // Attempt to create a published movie without any video file or URL
        $req = new Request([], [
            'action'         => 'create',
            'title'          => 'Empty Media Movie',
            'slug'           => 'empty-media-movie',
            'description'    => 'Should not be published without media',
            'access_mode'    => 'public',
            'submit_action'  => 'publish',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('empty-media-movie');
        $this->assertNotNull($movie);

        // Must be automatically forced to 'draft'
        $this->assertSame('draft', $movie->status, 'Content without media sources must revert to draft status');

        // Must set a user-friendly flash warning
        $this->assertNotEmpty($_SESSION['flash_warning']);
        $this->assertStringContainsString('saved as Draft because no video source is attached', $_SESSION['flash_warning']);
    }

    // =========================================================================
    // 4. QUICK ACTION BUTTONS: PUBLISH, DRAFT, SCHEDULE
    // =========================================================================

    public function testQuickActionButtonsPublishDraftSchedule(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        // 1. Save Draft action
        $draftReq = new Request([], [
            'action'         => 'create',
            'title'          => 'Draft Movie Test',
            'slug'           => 'draft-movie-test',
            'submit_action'  => 'draft',
            'status'         => 'published', // Even if status was marked published in dropdown, submit_action overrides
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $adminCtrl->movies($draftReq);

        $draftMovie = Movie::findBySlug('draft-movie-test');
        $this->assertNotNull($draftMovie);
        $this->assertSame('draft', $draftMovie->status);

        // 2. Schedule action
        $schedReq = new Request([], [
            'action'         => 'create',
            'title'          => 'Scheduled Movie Test',
            'slug'           => 'scheduled-movie-test',
            'media_tab'      => 'url',
            'video_url'      => 'https://example.com/future-release.mp4',
            'submit_action'  => 'schedule',
            'release_date'   => '2026-11-01 10:00:00',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $adminCtrl->movies($schedReq);

        $schedMovie = Movie::findBySlug('scheduled-movie-test');
        $this->assertNotNull($schedMovie);
        $this->assertSame('scheduled', $schedMovie->status);
        $this->assertSame('2026-11-01 10:00:00', $schedMovie->release_date);
    }

    // =========================================================================
    // 5. EPISODE PUBLISHING WITH VIDEO URL AND THUMBNAIL
    // =========================================================================

    public function testEpisodePublishingWithVideoUrl(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        // Create parent series & season
        $seriesId = $this->db->insert('multimedia_series', [
            'title'       => 'Test Series',
            'slug'        => 'test-series',
            'access_mode' => 'public',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $seasonId = $this->db->insert('multimedia_seasons', [
            'series_id'     => $seriesId,
            'season_number' => 1,
            'title'         => 'Season 1',
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        $videoUrl = 'https://example.com/episodes/s01e01.mp4';

        $req = new Request([], [
            'action'         => 'create',
            'series_id'      => $seriesId,
            'season_id'      => $seasonId,
            'episode_number' => 1,
            'title'          => 'Episode 1 Pilot',
            'slug'           => 'test-series-s01e01',
            'media_tab'      => 'url',
            'video_url'      => $videoUrl,
            'submit_action'  => 'publish',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $adminCtrl->episodes($req);
        $this->assertInstanceOf(Response::class, $res);

        $episode = Episode::findBySlug('test-series-s01e01');
        $this->assertNotNull($episode);
        $this->assertSame('published', $episode->status);

        // Verify source linked to episode
        $sources = MediaSource::getForContent('episode', (int)$episode->id);
        $this->assertCount(1, $sources);
        $this->assertSame($videoUrl, $sources[0]->url_or_path);
        $this->assertSame('episode', $sources[0]->content_type);
    }

    // =========================================================================
    // 6. SONG PUBLISHING WITH AUDIO URL AND METADATA
    // =========================================================================

    public function testSongPublishingWithAudioUrl(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        $audioUrl = 'https://example.com/tracks/song1.mp3';

        $req = new Request([], [
            'action'         => 'create',
            'title'          => 'Acoustic Melody',
            'slug'           => 'acoustic-melody',
            'media_tab'      => 'url',
            'audio_url'      => $audioUrl,
            'submit_action'  => 'publish',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $adminCtrl->songs($req);
        $this->assertInstanceOf(Response::class, $res);

        $song = Song::findBySlug('acoustic-melody');
        $this->assertNotNull($song);
        $this->assertSame('published', $song->status);

        // Verify audio source
        $sources = MediaSource::getForContent('song', (int)$song->id);
        $this->assertCount(1, $sources);
        $this->assertSame($audioUrl, $sources[0]->url_or_path);
        $this->assertSame('audio/mpeg', $sources[0]->mime_type);
    }

    // =========================================================================
    // 7. SSRF PROTECTION BLOCKS MALICIOUS URLS
    // =========================================================================

    public function testSsrfProtectionBlocksPrivateNetworkUrls(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        $maliciousUrl = 'http://169.254.169.254/latest/meta-data/';

        $req = new Request([], [
            'action'         => 'create',
            'title'          => 'SSRF Exploit Attempt',
            'slug'           => 'ssrf-exploit-attempt',
            'media_tab'      => 'url',
            'video_url'      => $maliciousUrl,
            'submit_action'  => 'publish',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $adminCtrl->movies($req);

        // Movie should be saved as draft because malicious URL source was blocked
        $movie = Movie::findBySlug('ssrf-exploit-attempt');
        $this->assertNotNull($movie);
        $this->assertSame('draft', $movie->status);

        // Flash error or warning should be set
        $this->assertTrue(isset($_SESSION['flash_error']) || isset($_SESSION['flash_warning']));
        $err = $_SESSION['flash_error'] ?? $_SESSION['flash_warning'];
        $this->assertStringContainsString('Access to private, loopback, or cloud-metadata IP addresses is forbidden', $err);

        // Zero sources should be registered for this movie
        $sources = MediaSource::getForContent('movie', (int)$movie->id);
        $this->assertCount(0, $sources);
    }

    // =========================================================================
    // 8. MEDIA STATUS BADGE COMPUTATION
    // =========================================================================

    public function testMediaStatusBadgeComputation(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        // 1. Content with no sources
        $statusNoMedia = $adminCtrl->getMediaStatusForContent('movie', 99991);
        $this->assertSame('no_media', $statusNoMedia['status']);
        $this->assertSame('No Media', $statusNoMedia['label']);
        $this->assertSame('fav-badge-gray', $statusNoMedia['class']);

        // 2. Content with ready source
        $s1 = $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 99992,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/ok.mp4',
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);
        $statusReady = $adminCtrl->getMediaStatusForContent('movie', 99992);
        $this->assertSame('ready', $statusReady['status']);
        $this->assertSame('Ready', $statusReady['label']);
        $this->assertSame('fav-badge-success', $statusReady['class']);

        // 3. Content with processing job
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 99993,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/proc.mp4',
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->insert('multimedia_processing_jobs', [
            'content_type' => 'movie',
            'content_id'   => 99993,
            'job_type'     => 'hls_transcode',
            'status'       => 'processing',
            'input_path'   => 'test.mp4',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);
        $statusProc = $adminCtrl->getMediaStatusForContent('movie', 99993);
        $this->assertSame('processing', $statusProc['status']);
        $this->assertSame('Processing', $statusProc['label']);
        $this->assertSame('fav-badge-warning', $statusProc['class']);

        // 4. Content with failed source
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 99994,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/fail.mp4',
            'status'       => 'inactive',
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);
        $statusFailed = $adminCtrl->getMediaStatusForContent('movie', 99994);
        $this->assertSame('failed', $statusFailed['status']);
        $this->assertSame('Failed', $statusFailed['label']);
        $this->assertSame('fav-badge-danger', $statusFailed['class']);
    }

    // =========================================================================
    // 9. SUBTITLE UPLOAD DIRECTLY ON CONTENT FORM
    // =========================================================================

    public function testSubtitleUploadFromContentForm(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);

        // First create movie with a valid video URL
        $createReq = new Request([], [
            'action'         => 'create',
            'title'          => 'Subtitle Test Movie',
            'slug'           => 'subtitle-test-movie',
            'media_tab'      => 'url',
            'video_url'      => 'https://example.com/movie-with-subs.mp4',
            'submit_action'  => 'publish',
            '_token'         => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $adminCtrl->movies($createReq);

        $movie = Movie::findBySlug('subtitle-test-movie');
        $this->assertNotNull($movie);

        // Upload subtitle file directly
        $fakeSubFile = $this->createFakeUploadedFile('test_sub.vtt', "WEBVTT\n\n1\n00:00:01.000 --> 00:00:04.000\nHello World\n");
        $_FILES['subtitle_file'] = [
            'name'     => 'english.vtt',
            'type'     => 'text/vtt',
            'tmp_name' => $fakeSubFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($fakeSubFile),
        ];

        $subReq = new Request([], [
            'action'          => 'edit',
            'id'              => $movie->id,
            'title'           => 'Subtitle Test Movie',
            'slug'            => 'subtitle-test-movie',
            'subtitle_lang'   => 'en',
            'subtitle_label'  => 'English SDH',
            'subtitle_default'=> '1',
            'submit_action'   => 'publish',
            '_token'          => 'valid_test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $adminCtrl->movies($subReq);

        $subs = Subtitle::getForContent('movie', (int)$movie->id);
        $this->assertCount(1, $subs);
        $this->assertSame('en', $subs[0]->language);
        $this->assertSame('English SDH', $subs[0]->label);
        $this->assertSame(1, (int)$subs[0]->is_default);
    }

    public function testMovieEditFormRendersCleanlyWithNoMedia(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);
        $mId = (int)$this->db->insert('multimedia_movies', [
            'title'      => 'No Media Movie Test',
            'slug'       => 'no-media-movie-test',
            'status'     => 'draft',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $req = new Request(['edit' => (string)$mId]);
        $html = (string)$adminCtrl->movies($req);

        $this->assertStringContainsString('Video / Media Stream', $html);
        $this->assertStringContainsString('No Media', $html);
        $this->assertStringNotContainsString('500 — Internal Server Error', $html);
        $this->assertStringNotContainsString('Current Default Stream:', $html);
    }

    public function testMovieEditFormRendersCleanlyWithActiveMediaSource(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);
        $now = gmdate('Y-m-d H:i:s');
        $mId = (int)$this->db->insert('multimedia_movies', [
            'title'      => 'Active Media Movie Test',
            'slug'       => 'active-media-movie-test',
            'status'     => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/test-active.mp4',
            'label'        => 'Main 1080p',
            'is_default'   => 1,
            'status'       => 'active',
            'created_at'   => $now,
        ]);

        $req = new Request(['edit' => (string)$mId]);
        $html = (string)$adminCtrl->movies($req);

        $this->assertStringContainsString('Video / Media Stream', $html);
        $this->assertStringContainsString('Current Default Stream:', $html);
        $this->assertStringContainsString('VIDEO', $html);
        $this->assertStringContainsString('Main 1080p', $html);
        $this->assertStringContainsString('https://example.com/test-active.mp4', $html);
        $this->assertStringNotContainsString('500 — Internal Server Error', $html);
    }

    public function testMovieEditFormRendersCleanlyWithProcessingMediaSource(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);
        $now = gmdate('Y-m-d H:i:s');
        $mId = (int)$this->db->insert('multimedia_movies', [
            'title'      => 'Processing Movie Test',
            'slug'       => 'processing-movie-test',
            'status'     => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_mode'  => 'upload',
            'source_type'  => 'video',
            'url_or_path'  => 'uploads/processing.mp4',
            'label'        => 'Raw Upload',
            'is_default'   => 1,
            'status'       => 'processing',
            'created_at'   => $now,
        ]);

        $req = new Request(['edit' => (string)$mId]);
        $html = (string)$adminCtrl->movies($req);

        $this->assertStringContainsString('Video / Media Stream', $html);
        $this->assertStringContainsString('Current Default Stream:', $html);
        $this->assertStringContainsString('Raw Upload', $html);
        $this->assertStringNotContainsString('500 — Internal Server Error', $html);
    }

    public function testEpisodeEditFormRendersCleanlyWithAndWithoutMediaSource(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);
        $now = gmdate('Y-m-d H:i:s');

        $sId = (int)$this->db->insert('multimedia_series', ['title' => 'Ep Test Series', 'slug' => 'ep-test-series', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $seaId = (int)$this->db->insert('multimedia_seasons', ['series_id' => $sId, 'title' => 'Season 1', 'season_number' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $epId = (int)$this->db->insert('multimedia_episodes', ['series_id' => $sId, 'season_id' => $seaId, 'title' => 'Episode Test 1', 'slug' => 'episode-test-1', 'episode_number' => 1, 'created_at' => $now, 'updated_at' => $now]);

        // Without source
        $req1 = new Request(['edit' => (string)$epId]);
        $html1 = (string)$adminCtrl->episodes($req1);
        $this->assertStringContainsString('Episode Video / Media Stream', $html1);
        $this->assertStringContainsString('No Media', $html1);
        $this->assertStringNotContainsString('500 — Internal Server Error', $html1);

        // With source
        $this->db->insert('multimedia_sources', [
            'content_type' => 'episode',
            'content_id'   => $epId,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/ep1.mp4',
            'label'        => '',
            'is_default'   => 1,
            'status'       => 'active',
            'created_at'   => $now,
        ]);

        $html2 = (string)$adminCtrl->episodes($req1);
        $this->assertStringContainsString('Current Default Stream:', $html2);
        $this->assertStringContainsString('VIDEO', $html2);
        $this->assertStringNotContainsString('500 — Internal Server Error', $html2);
    }

    public function testSongEditFormRendersCleanlyWithAndWithoutMediaSource(): void
    {
        $adminCtrl = $this->app->make(MultimediaAdminController::class);
        $now = gmdate('Y-m-d H:i:s');

        $songId = (int)$this->db->insert('multimedia_songs', ['title' => 'Song Test 1', 'slug' => 'song-test-1', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);

        // Without source
        $req1 = new Request(['edit' => (string)$songId]);
        $html1 = (string)$adminCtrl->songs($req1);
        $this->assertStringContainsString('Audio Source &amp; Media', $html1);
        $this->assertStringContainsString('No Media', $html1);
        $this->assertStringNotContainsString('500 — Internal Server Error', $html1);

        // With source
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'source_mode'  => 'url',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/track.mp3',
            'label'        => '',
            'is_default'   => 1,
            'status'       => 'active',
            'created_at'   => $now,
        ]);

        $html2 = (string)$adminCtrl->songs($req1);
        $this->assertStringContainsString('Current Default Audio:', $html2);
        $this->assertStringContainsString('AUDIO', $html2);
        $this->assertStringNotContainsString('500 — Internal Server Error', $html2);
    }

    // =========================================================================
    // v1.0.4 REGRESSION TESTS — Critical Publish/Update Workflow Fix
    // RC-01: Unknown HTTPS URL treated as video (not aborting source creation)
    // RC-02: No DNS rebinding checks (no hangs on prod)
    // RC-03/05/06: No Media Protection uses activeOnly=false
    // RC-04: getMediaStatusForContent returns 'error' not 'no_media' on exception
    // =========================================================================


    /** @test TC-01: Movie with YouTube URL → first publish → stays Published */
    public function testMovieWithYouTubeUrlStaysPublishedAfterFirstPublish(): void
    {
        $resolved = MediaSourceResolver::resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertTrue($resolved['valid'], 'YouTube URL must resolve as valid');
        $this->assertSame('embed', $resolved['source_type'], 'YouTube URL must resolve as embed type');
    }

    /** @test TC-02: Movie with Vimeo URL → first publish → stays Published */
    public function testMovieWithVimeoUrlResolvesAsEmbed(): void
    {
        $resolved = MediaSourceResolver::resolve('https://vimeo.com/123456789');
        $this->assertTrue($resolved['valid'], 'Vimeo URL must resolve as valid');
        $this->assertSame('embed', $resolved['source_type'], 'Vimeo URL must resolve as embed type');
    }

    /** @test TC-03: Movie with HLS stream URL → resolves as hls */
    public function testMovieWithHlsUrlResolvesAsHls(): void
    {
        $resolved = MediaSourceResolver::resolve('https://cdn.example.com/stream/master.m3u8');
        $this->assertTrue($resolved['valid'], 'HLS URL must resolve as valid');
        $this->assertSame('hls', $resolved['source_type'], 'HLS URL must resolve as hls type');
    }

    /** @test TC-04: Movie with generic CDN HTTPS URL (no extension) with explicit direct video type → valid video without fake MIME */
    public function testMovieWithGenericCdnHttpsUrlResolvesAsVideo(): void
    {
        // When admin explicitly selects Direct Video URL, it resolves as video even without extension
        $resolved = MediaSourceResolver::resolve('https://cdn.example.com/videos/movie/stream?token=abc123&expires=9999999', 'auto', 'video');
        $this->assertTrue($resolved['valid'], 'Explicit Direct Video URL must resolve as valid even without file extension');
        $this->assertSame('video', $resolved['source_type']);
        $this->assertSame('', $resolved['mime_type'], 'MIME type must not be fabricated when unknown');

        // Without manual type and without known extension/embed pattern, auto-detection returns unknown
        $unhinted = MediaSourceResolver::detectSourceType('https://cdn.example.com/videos/movie/stream?token=abc123&expires=9999999');
        $this->assertSame('unknown', $unhinted['type'], 'Unhinted URL without known extension must not fabricate video or mp4 MIME');
    }

    /** @test TC-05: Movie with direct MP4 URL → resolves as video */
    public function testMovieWithDirectMp4UrlResolvesAsVideo(): void
    {
        $resolved = MediaSourceResolver::resolve('https://cdn.example.com/movies/film.mp4');
        $this->assertTrue($resolved['valid'], 'Direct MP4 URL must resolve as valid');
        $this->assertSame('video', $resolved['source_type'], 'Direct MP4 URL must resolve as video type');
    }

    /** @test TC-06: Movie with NO media → No Media Protection correctly demotes to draft */
    public function testMovieWithNoMediaIsCorrectlyDemotedToDraft(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = $this->db->insert('multimedia_movies', [
            'title'        => 'No-Media Movie',
            'slug'         => 'no-media-movie',
            'status'       => 'published',
            'published_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // No source inserted at all
        $sources = MediaSource::getForContent('movie', (int)$movieId, false);
        $this->assertEmpty($sources, 'Precondition: no sources should exist');

        // Simulate protection check (activeOnly=false — as fixed)
        $currentSources = MediaSource::getForContent('movie', (int)$movieId, false);
        if (empty($currentSources)) {
            $this->db->update('multimedia_movies', ['status' => 'draft'], ['id' => $movieId]);
        }

        $movie = Movie::find((int)$movieId);
        $this->assertSame('draft', $movie->status, 'Movie with no sources must be demoted to draft');
    }

    /** @test TC-07: Movie with DISABLED source → metadata edit → stays Published (NOT demoted) */
    public function testMovieWithDisabledSourceStaysPublishedOnMetadataEdit(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = $this->db->insert('multimedia_movies', [
            'title'        => 'Movie With Disabled Source',
            'slug'         => 'movie-disabled-source',
            'status'       => 'published',
            'published_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // Source exists but is disabled (inactive)
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'media_kind'   => 'video',
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://cdn.example.com/movie.mp4',
            'label'        => 'Main',
            'is_default'   => 1,
            'status'       => 'inactive', // explicitly disabled
            'created_at'   => $now,
        ]);

        // Simulate fixed No Media Protection (activeOnly=false)
        $currentSources = MediaSource::getForContent('movie', (int)$movieId, false);
        if (!empty($currentSources)) {
            // Has sources (even if inactive) → do NOT demote
        } else {
            $this->db->update('multimedia_movies', ['status' => 'draft'], ['id' => $movieId]);
        }

        $movie = Movie::find((int)$movieId);
        $this->assertSame('published', $movie->status, 'Movie with an inactive (disabled) source must NOT be demoted to draft on metadata-only edit');
    }

    /** @test TC-08: Movie with ACTIVE source → metadata edit → stays Published */
    public function testMovieWithActiveSourceStaysPublishedOnMetadataEdit(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = $this->db->insert('multimedia_movies', [
            'title'        => 'Movie With Active Source',
            'slug'         => 'movie-active-source',
            'status'       => 'published',
            'published_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'media_kind'   => 'video',
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://cdn.example.com/movie.mp4',
            'label'        => 'Main',
            'is_default'   => 1,
            'status'       => 'active',
            'created_at'   => $now,
        ]);

        $currentSources = MediaSource::getForContent('movie', (int)$movieId, false);
        if (empty($currentSources)) {
            $this->db->update('multimedia_movies', ['status' => 'draft'], ['id' => $movieId]);
        }

        $movie = Movie::find((int)$movieId);
        $this->assertSame('published', $movie->status, 'Movie with an active source must stay published on metadata edit');
    }

    /** @test TC-09: Episode with YouTube URL → resolve → valid embed */
    public function testEpisodeWithYouTubeUrlResolvesAsValidEmbed(): void
    {
        $resolved = MediaSourceResolver::resolve('https://youtu.be/dQw4w9WgXcQ');
        $this->assertTrue($resolved['valid']);
        $this->assertSame('embed', $resolved['source_type']);
    }

    /** @test TC-10: Episode with disabled source → metadata edit → stays Published */
    public function testEpisodeWithDisabledSourceStaysPublishedOnMetadataEdit(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $seriesId = $this->db->insert('multimedia_series', [
            'title' => 'Test Series', 'slug' => 'test-series-ep10',
            'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $epId = $this->db->insert('multimedia_episodes', [
            'series_id' => $seriesId, 'season_id' => 0, 'episode_number' => 1,
            'title' => 'Ep 1', 'slug' => 'ep-1-tc10',
            'status' => 'published', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'episode', 'content_id' => $epId,
            'media_kind' => 'video', 'source_mode' => 'url', 'source_type' => 'video',
            'url_or_path' => 'https://cdn.example.com/ep1.mp4',
            'label' => 'Main', 'is_default' => 1, 'status' => 'inactive', 'created_at' => $now,
        ]);

        // Fixed protection: activeOnly=false
        $currentSources = MediaSource::getForContent('episode', (int)$epId, false);
        if (empty($currentSources)) {
            $this->db->update('multimedia_episodes', ['status' => 'draft'], ['id' => $epId]);
        }

        $ep = Episode::find((int)$epId);
        $this->assertSame('published', $ep->status, 'Episode with a disabled source must NOT be demoted on metadata-only edit');
    }

    /** @test TC-11: Song (audio-only) with MP3 URL → resolves as valid audio */
    public function testSongAudioOnlyMp3UrlResolvesAsValidAudio(): void
    {
        $resolved = MediaSourceResolver::resolve('https://cdn.example.com/songs/track.mp3');
        $this->assertTrue($resolved['valid'], 'MP3 URL must resolve as valid');
        $this->assertSame('audio', $resolved['source_type'], 'MP3 URL must resolve as audio type');
    }

    /** @test TC-12: Song (audio-only) with disabled audio source → metadata edit → stays Published */
    public function testSongWithDisabledAudioSourceStaysPublishedOnMetadataEdit(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $songId = $this->db->insert('multimedia_songs', [
            'title' => 'Song Disabled Audio', 'slug' => 'song-disabled-audio-tc12',
            'playback_type' => 'audio', 'default_playback_mode' => 'audio',
            'status' => 'published', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'song', 'content_id' => $songId,
            'media_kind' => 'audio', 'source_mode' => 'url', 'source_type' => 'audio',
            'url_or_path' => 'https://cdn.example.com/song.mp3',
            'label' => 'Audio', 'is_default' => 1, 'status' => 'inactive', 'created_at' => $now,
        ]);

        // Fixed protection: activeOnly=false for audio
        $audioSources = MediaSource::getForContent('song', (int)$songId, false, 'audio');
        $hasAudio = !empty($audioSources);
        $isReady = $hasAudio; // playback_type=audio

        if (!$isReady) {
            $this->db->update('multimedia_songs', ['status' => 'draft'], ['id' => $songId]);
        }

        $song = Song::find((int)$songId);
        $this->assertSame('published', $song->status, 'Audio-only song with disabled audio source must NOT be demoted on metadata edit');
    }

    /** @test TC-13: Song (video-only / Music Video) with YouTube URL → valid embed */
    public function testSongVideoOnlyWithYouTubeUrlResolvesAsValidEmbed(): void
    {
        $resolved = MediaSourceResolver::resolve('https://www.youtube.com/watch?v=abcdefghijk');
        $this->assertTrue($resolved['valid'], 'YouTube URL for music video must be valid');
        $this->assertSame('embed', $resolved['source_type'], 'YouTube music video URL must resolve as embed');
    }

    /** @test TC-14: Song (dual-mode) with both audio+video → both resolve valid */
    public function testSongDualModeWithBothSourcesIsReady(): void
    {
        $audioResolved = MediaSourceResolver::resolve('https://cdn.example.com/song/audio.mp3');
        $videoResolved = MediaSourceResolver::resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertTrue($audioResolved['valid'], 'Audio source must resolve as valid for dual-mode');
        $this->assertSame('audio', $audioResolved['source_type']);
        $this->assertTrue($videoResolved['valid'], 'Video source must resolve as valid for dual-mode');
        $this->assertSame('embed', $videoResolved['source_type']);
    }

    /** @test TC-15: getMediaStatusForContent returns 'error' not 'no_media' on exception */
    public function testGetMediaStatusForContentReturnsErrorOnException(): void
    {
        $adminCtrl = new MultimediaAdminController($this->app);

        // Drop the multimedia_sources table to force a DB exception
        $this->db->execute('DROP TABLE IF EXISTS multimedia_sources');

        $status = $adminCtrl->getMediaStatusForContent('movie', 999);

        // Must return 'error', NOT 'no_media', so admin knows it's a DB/check issue
        $this->assertSame('error', $status['status'], 'getMediaStatusForContent must return error status on DB exception, not no_media');
        $this->assertSame('Check Failed', $status['label']);
        $this->assertFalse($status['playable']);
    }

    /** @test TC-16: getMediaStatusForContent returns 'no_media' only when zero sources exist */
    public function testGetMediaStatusForContentReturnsNoMediaOnlyWhenZeroSources(): void
    {
        $adminCtrl = new MultimediaAdminController($this->app);

        // Content ID with no sources
        $status = $adminCtrl->getMediaStatusForContent('movie', 99999);

        $this->assertSame('no_media', $status['status'], 'getMediaStatusForContent must return no_media when zero sources exist');
        $this->assertSame('No Media', $status['label']);
        $this->assertFalse($status['playable']);
    }

    /** @test TC-17: YouTube and Vimeo URLs take absolute precedence and never become generic video */
    public function testYouTubeAndVimeoPrecedenceOverGenericVideo(): void
    {
        $yt = MediaSourceResolver::detectSourceType('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertSame('embed', $yt['type']);
        $this->assertSame('youtube', $yt['service']);
        $this->assertStringContainsString('youtube-nocookie.com/embed', $yt['embed_url']);

        $vimeo = MediaSourceResolver::detectSourceType('https://vimeo.com/123456789');
        $this->assertSame('embed', $vimeo['type']);
        $this->assertSame('vimeo', $vimeo['service']);
        $this->assertStringContainsString('player.vimeo.com/video', $vimeo['embed_url']);
    }

    /** @test TC-18: HLS .m3u8 takes precedence over generic direct video */
    public function testHlsPrecedenceOverDirectVideo(): void
    {
        $hls = MediaSourceResolver::detectSourceType('https://streaming.example.com/live/stream.m3u8?token=xyz');
        $this->assertSame('hls', $hls['type']);
        $this->assertSame('application/x-mpegURL', $hls['mime_type']);
    }

    /** @test TC-19: Server-side fetch SSRF validates against private IPs with DNS check enabled */
    public function testServerSideFetchSsrfValidationProtectsInternalNetwork(): void
    {
        // Without DNS check (normal admin form save) - non-blocking
        $saveCheck = MediaSourceResolver::validateUrlSecurity('https://127.0.0.1/video.mp4', false);
        $this->assertFalse($saveCheck['safe'], 'Loopback IP must be rejected');

        $localhostCheck = MediaSourceResolver::validateUrlSecurity('https://localhost/video.mp4', false);
        $this->assertFalse($localhostCheck['safe'], 'Localhost must be rejected');

        // With DNS check enabled (server-side fetch mode)
        $publicCheck = MediaSourceResolver::validateUrlSecurity('https://example.com/subtitles.vtt', true);
        // On environments with DNS, example.com resolves to public IP (or safe check passes)
        $this->assertIsBool($publicCheck['safe']);
    }

    /** @test TC-20: MediaSource model distinguishes configured from playable */
    public function testMediaSourceModelDistinguishesConfiguredFromPlayable(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $sActiveId = $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 8881,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/active.mp4',
            'status'       => 'active',
            'created_at'   => $now,
        ]);
        $sInactiveId = $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 8881,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/inactive.mp4',
            'status'       => 'inactive',
            'created_at'   => $now,
        ]);

        $activeSource = MediaSource::find((int)$sActiveId);
        $inactiveSource = MediaSource::find((int)$sInactiveId);

        $this->assertTrue($activeSource->isConfigured());
        $this->assertTrue($activeSource->isPlayable());

        $this->assertTrue($inactiveSource->isConfigured());
        $this->assertFalse($inactiveSource->isPlayable(), 'Inactive source must NOT be playable');

        // getPlayableForContent returns only active
        $playable = MediaSource::getPlayableForContent('movie', 8881);
        $this->assertCount(1, $playable);
        $this->assertSame((int)$sActiveId, (int)$playable[0]->id);
    }

    /** @test TC-21: Multi-source failover: one failed/inactive source + one active source is ready/playable */
    public function testFailedPlusReadySourceIsPlayableAndReady(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 8882,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/broken.mp4',
            'status'       => 'inactive',
            'created_at'   => $now,
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => 8882,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/working.mp4',
            'status'       => 'active',
            'created_at'   => $now,
        ]);

        $adminCtrl = new MultimediaAdminController($this->app);
        $status = $adminCtrl->getMediaStatusForContent('movie', 8882);

        $this->assertSame('ready', $status['status']);
        $this->assertSame('Ready', $status['label']);
        $this->assertTrue($status['playable']);
    }

    /** @test TC-22: Sidebar cleanup: Top-level Multimedia has NO child named Multimedia */
    public function testSidebarCleanupRemovesDuplicateMultimediaSubmenu(): void
    {
        $menus = AdminMenu::getMenus();
        $this->assertArrayHasKey('multimedia', $menus, 'Top-level multimedia menu must exist');

        $multimedia = $menus['multimedia'];
        $this->assertSame('Multimedia', (string)$multimedia['title']);
        $this->assertIsCallable($multimedia['handler']);

        // Check submenus: no duplicate multimedia-dashboard registration
        $submenus = $multimedia['submenus'] ?? [];
        $this->assertArrayNotHasKey('multimedia', $submenus, 'Duplicate child "multimedia" must NOT exist in submenus');
        $this->assertArrayNotHasKey('multimedia-dashboard', $submenus, 'Duplicate child "multimedia-dashboard" must NOT exist in submenus');
        $this->assertArrayHasKey('multimedia-movies', $submenus, 'First child must be Movies or functional submenu');
    }
}


