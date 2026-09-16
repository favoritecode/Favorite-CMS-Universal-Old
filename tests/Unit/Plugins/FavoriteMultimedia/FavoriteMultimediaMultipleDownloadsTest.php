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
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\DownloadSource;
use FavoriteCMS\Multimedia\Services\DownloadSourceService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Favorite Multimedia v1.0.6 Multiple Download Links System.
 * Covers 27 comprehensive scenarios verifying schema, models, services, admin CRUD,
 * access control, deduplication, security, and backward compatibility.
 */
class FavoriteMultimediaMultipleDownloadsTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaAdminController $adminCtrl;
    private MediaPlaybackController $playbackCtrl;
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

        $this->tempDb = sys_get_temp_dir() . '/fav_multimedia_multidl_' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $this->db->execute("CREATE TABLE settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");

        $now = gmdate('Y-m-d H:i:s');
        $rId = $this->db->insert('roles', ['name' => 'Admin', 'slug' => 'admin', 'created_at' => $now, 'updated_at' => $now]);
        $uId = $this->db->insert('users', ['username' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('user_roles', ['user_id' => $uId, 'role_id' => $rId]);

        // Regular non-admin user
        $this->db->insert('users', ['id' => 2, 'username' => 'regular', 'name' => 'Regular User', 'email' => 'regular@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->adminCtrl = $this->app->make(MultimediaAdminController::class);
        $this->playbackCtrl = $this->app->make(MediaPlaybackController::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function createTestMovie(array $overrides = []): Movie
    {
        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'title'           => 'Test Movie',
            'slug'            => 'test-movie-' . bin2hex(random_bytes(4)),
            'status'          => 'published',
            'access_mode'     => 'public',
            'download_policy' => 'inherit',
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $overrides);

        $id = $this->db->insert('multimedia_movies', $data);
        return Movie::find($id);
    }

    private function createTestEpisode(array $overrides = []): Episode
    {
        $now = gmdate('Y-m-d H:i:s');
        $seriesId = $this->db->insert('multimedia_series', [
            'title'       => 'Test Series',
            'slug'        => 'test-series-' . bin2hex(random_bytes(4)),
            'access_mode' => 'public',
            'status'      => 'published',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $seasonId = $this->db->insert('multimedia_seasons', [
            'series_id'     => $seriesId,
            'season_number' => 1,
            'title'         => 'Season 1',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $data = array_merge([
            'series_id'       => $seriesId,
            'season_id'       => $seasonId,
            'episode_number'  => 1,
            'title'           => 'Test Episode 1',
            'slug'            => 'test-ep-1-' . bin2hex(random_bytes(4)),
            'status'          => 'published',
            'access_mode'     => 'inherit',
            'download_policy' => 'inherit',
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $overrides);

        $id = $this->db->insert('multimedia_episodes', $data);
        return Episode::find($id);
    }

    private function createTestSong(array $overrides = []): Song
    {
        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'title'           => 'Test Song',
            'slug'            => 'test-song-' . bin2hex(random_bytes(4)),
            'playback_type'   => 'audio',
            'status'          => 'published',
            'access_mode'     => 'public',
            'download_policy' => 'inherit',
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $overrides);

        $id = $this->db->insert('multimedia_songs', $data);
        return Song::find($id);
    }

    // 1. Multiple manual download sources saved for Movie
    public function testMultipleManualDownloadSourcesSavedForMovie(): void
    {
        $movie = $this->createTestMovie();

        $req = new Request([], [
            'action'         => 'edit',
            'id'             => $movie->id,
            'title'          => $movie->title,
            'slug'           => $movie->slug,
            '_token'         => 'valid_test_token',
            'download_sources' => [
                ['id' => 0, 'label' => '1080p — Google Drive', 'url' => 'https://drive.google.com/file/d/test1', 'quality' => '1080p', 'format' => 'MP4', 'provider' => 'Google Drive', 'is_active' => 1, 'sort_order' => 1],
            ],
            'new_download_sources' => [
                1 => ['label' => '720p — Direct', 'url' => 'https://cdn.example.com/movie-720p.mp4', 'quality' => '720p', 'format' => 'MP4', 'provider' => 'Direct', 'is_active' => 1, 'sort_order' => 2],
            ],
        ], ['REQUEST_METHOD' => 'POST']);

        $this->adminCtrl->movies($req);

        $sources = DownloadSource::getForContent('movie', (int)$movie->id, false);
        $this->assertCount(2, $sources);
        $this->assertEquals('1080p — Google Drive', $sources[0]->label);
        $this->assertEquals('720p — Direct', $sources[1]->label);
    }

    // 2. Multiple manual download sources saved for Episode
    public function testMultipleManualDownloadSourcesSavedForEpisode(): void
    {
        $episode = $this->createTestEpisode();

        $req = new Request([], [
            'action'    => 'edit',
            'id'        => $episode->id,
            'season_id' => $episode->season_id,
            'title'     => $episode->title,
            'slug'      => $episode->slug,
            '_token'    => 'valid_test_token',
            'new_download_sources' => [
                1 => ['label' => '1080p Web', 'url' => 'https://cdn.example.com/ep-1080p.mkv', 'quality' => '1080p', 'format' => 'MKV', 'provider' => 'Direct', 'is_active' => 1, 'sort_order' => 1],
                2 => ['label' => '720p Mobile', 'url' => 'https://cdn.example.com/ep-720p.mp4', 'quality' => '720p', 'format' => 'MP4', 'provider' => 'Direct', 'is_active' => 1, 'sort_order' => 2],
            ],
        ], ['REQUEST_METHOD' => 'POST']);

        $this->adminCtrl->episodes($req);

        $sources = DownloadSource::getForContent('episode', (int)$episode->id, false);
        $this->assertCount(2, $sources);
        $this->assertEquals('1080p Web', $sources[0]->label);
        $this->assertEquals('720p Mobile', $sources[1]->label);
    }

    // 3. Multiple manual download sources saved for Song
    public function testMultipleManualDownloadSourcesSavedForSong(): void
    {
        $song = $this->createTestSong();

        $req = new Request([], [
            'action' => 'edit',
            'id'     => $song->id,
            'title'  => $song->title,
            'slug'   => $song->slug,
            '_token' => 'valid_test_token',
            'new_download_sources' => [
                1 => ['label' => 'Lossless FLAC', 'url' => 'https://cdn.example.com/song.flac', 'quality' => 'Lossless', 'format' => 'FLAC', 'provider' => 'Direct', 'is_active' => 1, 'sort_order' => 1],
                2 => ['label' => 'MP3 320kbps', 'url' => 'https://cdn.example.com/song.mp3', 'quality' => '320kbps', 'format' => 'MP3', 'provider' => 'Direct', 'is_active' => 1, 'sort_order' => 2],
            ],
        ], ['REQUEST_METHOD' => 'POST']);

        $this->adminCtrl->songs($req);

        $sources = DownloadSource::getForContent('song', (int)$song->id, false);
        $this->assertCount(2, $sources);
        $this->assertEquals('Lossless FLAC', $sources[0]->label);
        $this->assertEquals('MP3 320kbps', $sources[1]->label);
    }

    // 4. Ordering preserved by sort_order
    public function testOrderingPreservedBySortOrder(): void
    {
        $movie = $this->createTestMovie();

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Second Link',
            'url'          => 'https://cdn.example.com/2.mp4',
            'sort_order'   => 20,
            'is_active'    => 1,
        ]);

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'First Link',
            'url'          => 'https://cdn.example.com/1.mp4',
            'sort_order'   => 5,
            'is_active'    => 1,
        ]);

        $sources = DownloadSource::getForContent('movie', (int)$movie->id, true);
        $this->assertCount(2, $sources);
        $this->assertEquals('First Link', $sources[0]->label);
        $this->assertEquals('Second Link', $sources[1]->label);
    }

    // 5. Inactive source hidden from frontend options
    public function testInactiveSourceHiddenFromFrontendOptions(): void
    {
        $movie = $this->createTestMovie();

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Active Source',
            'url'          => 'https://cdn.example.com/active.mp4',
            'is_active'    => 1,
        ]);

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Inactive Source',
            'url'          => 'https://cdn.example.com/inactive.mp4',
            'is_active'    => 0,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertTrue($result['allowed']);
        $this->assertCount(1, $result['options']);
        $this->assertEquals('Active Source', $result['options'][0]['label']);
    }

    // 6. Deleted source removed cleanly
    public function testDeletedSourceRemovedCleanly(): void
    {
        $movie = $this->createTestMovie();

        $dlId = $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Source To Delete',
            'url'          => 'https://cdn.example.com/delete-me.mp4',
            'is_active'    => 1,
        ]);

        $req = new Request([], [
            'action'                  => 'edit',
            'id'                      => $movie->id,
            'title'                   => $movie->title,
            'slug'                    => $movie->slug,
            '_token'                  => 'valid_test_token',
            'delete_download_sources' => [$dlId],
        ], ['REQUEST_METHOD' => 'POST']);

        $this->adminCtrl->movies($req);

        $this->assertNull(DownloadSource::find($dlId));
    }

    // 7. Metadata-only edit preserves download rows
    public function testMetadataOnlyEditPreservesDownloadRows(): void
    {
        $movie = $this->createTestMovie();

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Preserved Source',
            'url'          => 'https://cdn.example.com/preserved.mp4',
            'is_active'    => 1,
        ]);

        // Edit title only without passing download_sources
        $req = new Request([], [
            'action' => 'edit',
            'id'     => $movie->id,
            'title'  => 'Updated Title Only',
            'slug'   => $movie->slug,
            '_token' => 'valid_test_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $this->adminCtrl->movies($req);

        $sources = DownloadSource::getForContent('movie', (int)$movie->id, false);
        $this->assertCount(1, $sources);
        $this->assertEquals('Preserved Source', $sources[0]->label);
    }

    // 8. Legacy download_url preserved and migrated
    public function testLegacyDownloadUrlPreservedAndMigrated(): void
    {
        $movie = $this->createTestMovie(['download_url' => 'https://cdn.example.com/legacy-movie.mp4']);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertTrue($result['allowed']);
        $this->assertCount(1, $result['options']);
        $this->assertStringContainsString('download-content/movie/' . $movie->id, $result['download_url']);
    }

    // 9. Migration idempotency (no duplicates on second run)
    public function testMigrationIdempotent(): void
    {
        $movie = $this->createTestMovie(['download_url' => 'https://cdn.example.com/idempotent.mp4']);

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/012_create_multimedia_download_sources_table.php';
        $migration = new \CreateMultimediaDownloadSourcesTable($this->db);
        $migration->up();
        $migration->up(); // Second execution

        $rows = $this->db->select(
            "SELECT id FROM multimedia_download_sources WHERE content_type = 'movie' AND content_id = ? AND url = ?",
            [$movie->id, 'https://cdn.example.com/idempotent.mp4']
        );
        $this->assertCount(1, $rows);
    }

    // 10. Duplicate URLs deduplicated
    public function testDuplicateUrlsDeduplicated(): void
    {
        $movie = $this->createTestMovie(['download_url' => 'https://cdn.example.com/shared.mp4']);

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Manual Row',
            'url'          => 'https://cdn.example.com/shared.mp4',
            'is_active'    => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertTrue($result['allowed']);
        $this->assertCount(1, $result['options'], 'Exact matching URLs should be deduplicated.');
    }

    // 11. Uploaded/direct automatic download still works
    public function testUploadedDirectAutomaticDownloadStillWorks(): void
    {
        $movie = $this->createTestMovie();

        $sId = $this->db->insert('multimedia_sources', [
            'content_type'   => 'movie',
            'content_id'     => $movie->id,
            'source_mode'    => 'upload',
            'source_type'    => 'video',
            'url_or_path'    => '/storage/media/movie-feature.mp4',
            'label'          => 'Main Feature',
            'quality'        => '1080p',
            'status'         => 'active',
            'allow_download' => 'inherit',
            'is_default'     => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertTrue($result['allowed']);
        $this->assertCount(1, $result['options']);
        $this->assertEquals("/multimedia/download/{$sId}", $result['download_url']);
    }

    // 12. Embed does not auto-create download
    public function testEmbedDoesNotAutoCreateDownload(): void
    {
        $movie = $this->createTestMovie();

        $this->db->insert('multimedia_sources', [
            'content_type'   => 'movie',
            'content_id'     => $movie->id,
            'source_mode'    => 'embed',
            'source_type'    => 'embed',
            'url_or_path'    => 'https://player.example.com/embed/12345',
            'status'         => 'active',
            'allow_download' => 'inherit',
            'is_default'     => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertFalse($result['allowed']);
        $this->assertCount(0, $result['options']);
    }

    // 13. YouTube does not auto-create download
    public function testYouTubeDoesNotAutoCreateDownload(): void
    {
        $movie = $this->createTestMovie();

        $this->db->insert('multimedia_sources', [
            'content_type'   => 'movie',
            'content_id'     => $movie->id,
            'source_mode'    => 'url',
            'source_type'    => 'embed',
            'url_or_path'    => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'status'         => 'active',
            'allow_download' => 'inherit',
            'is_default'     => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertFalse($result['allowed']);
    }

    // 14. Vimeo does not auto-create download
    public function testVimeoDoesNotAutoCreateDownload(): void
    {
        $movie = $this->createTestMovie();

        $this->db->insert('multimedia_sources', [
            'content_type'   => 'movie',
            'content_id'     => $movie->id,
            'source_mode'    => 'url',
            'source_type'    => 'embed',
            'url_or_path'    => 'https://player.vimeo.com/video/123456789',
            'status'         => 'active',
            'allow_download' => 'inherit',
            'is_default'     => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertFalse($result['allowed']);
    }

    // 15. HLS does not auto-create fake direct download
    public function testHlsDoesNotAutoCreateFakeDirectDownload(): void
    {
        $movie = $this->createTestMovie();

        $this->db->insert('multimedia_sources', [
            'content_type'   => 'movie',
            'content_id'     => $movie->id,
            'source_mode'    => 'url',
            'source_type'    => 'hls',
            'url_or_path'    => 'https://cdn.example.com/master.m3u8',
            'status'         => 'active',
            'allow_download' => 'inherit',
            'is_default'     => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertFalse($result['allowed']);
    }

    // 16. Manual link allowed alongside Embed/YouTube/Vimeo/HLS
    public function testManualLinkAllowedAlongsideEmbedYouTubeHls(): void
    {
        $movie = $this->createTestMovie();

        // Add YouTube embed stream
        $this->db->insert('multimedia_sources', [
            'content_type'   => 'movie',
            'content_id'     => $movie->id,
            'source_mode'    => 'url',
            'source_type'    => 'embed',
            'url_or_path'    => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'status'         => 'active',
            'allow_download' => 'inherit',
            'is_default'     => 1,
        ]);

        // Add lawful manual download link
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Official 1080p Download',
            'url'          => 'https://cdn.example.com/official-movie.mp4',
            'is_active'    => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertTrue($result['allowed']);
        $this->assertCount(1, $result['options']);
        $this->assertEquals('Official 1080p Download', $result['options'][0]['label']);
    }

    // 17. PUBLIC download access
    public function testPublicDownloadAccessAllowedForGuests(): void
    {
        $movie = $this->createTestMovie(['access_mode' => 'public']);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Public Download',
            'url'          => 'https://cdn.example.com/public.mp4',
            'is_active'    => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertTrue($result['allowed']);
        $this->assertEquals(MultimediaAccessService::ALLOW, $result['access_state']);
    }

    // 18. LOGIN download access
    public function testLoginDownloadAccessEnforced(): void
    {
        $movie = $this->createTestMovie(['access_mode' => 'login']);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Login Download',
            'url'          => 'https://cdn.example.com/login.mp4',
            'is_active'    => 1,
        ]);

        // Guest check
        $guestCheck = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertFalse($guestCheck['allowed']);
        $this->assertEquals(MultimediaAccessService::LOGIN_REQUIRED, $guestCheck['access_state']);
        $this->assertEmpty($guestCheck['options']);
        $this->assertNull($guestCheck['download_url']);

        // Authenticated user check
        $user = User::find(2);
        $userCheck = DownloadSourceService::resolveDownloadOptions($user, 'movie', $movie);
        $this->assertTrue($userCheck['allowed']);
        $this->assertCount(1, $userCheck['options']);
    }

    // 19. PREMIUM entitlement success
    public function testPremiumEntitlementSuccess(): void
    {
        $movie = $this->createTestMovie(['access_mode' => 'premium']);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Premium Download',
            'url'          => 'https://cdn.example.com/premium.mp4',
            'is_active'    => 1,
        ]);

        $user = User::find(2);
        $GLOBALS['_test_favorite_digital_entitled_users'] = [(int)$user->id];

        $result = DownloadSourceService::resolveDownloadOptions($user, 'movie', $movie);
        $this->assertTrue($result['allowed']);
        $this->assertEquals(MultimediaAccessService::ALLOW, $result['access_state']);
        $this->assertCount(1, $result['options']);
    }

    // 20. PREMIUM fail-closed
    public function testPremiumFailClosedWithoutEntitlement(): void
    {
        $movie = $this->createTestMovie(['access_mode' => 'premium']);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Premium Download',
            'url'          => 'https://cdn.example.com/premium.mp4',
            'is_active'    => 1,
        ]);

        $user = User::find(2);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);

        $result = DownloadSourceService::resolveDownloadOptions($user, 'movie', $movie);
        $this->assertFalse($result['allowed']);
        $this->assertEquals(MultimediaAccessService::PREMIUM_REQUIRED, $result['access_state']);
        $this->assertEmpty($result['options']);
        $this->assertNull($result['download_url']);
    }

    // 21. Protected URL not leaked before authorization
    public function testProtectedUrlNotLeakedBeforeAuthorization(): void
    {
        $movie = $this->createTestMovie(['access_mode' => 'premium']);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Top Secret',
            'url'          => 'https://cdn.example.com/super-secret-video-url.mp4',
            'is_active'    => 1,
        ]);

        unset($GLOBALS['_test_favorite_digital_entitled_users']);

        $result = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie);
        $this->assertFalse($result['allowed']);
        $this->assertNull($result['download_url']);
        $this->assertEmpty($result['options']);

        // Raw URL must not be present anywhere in serialized JSON
        $json = json_encode($result);
        $this->assertStringNotContainsString('super-secret-video-url', $json);
    }

    // 22. Invalid/unsafe URL rejected
    public function testInvalidUnsafeUrlRejected(): void
    {
        $dl = new DownloadSource([
            'url' => 'javascript:alert(1)',
        ]);
        $this->assertFalse($dl->isValidUrl(), 'javascript: scheme must be rejected.');

        $dl2 = new DownloadSource([
            'url' => 'data:text/html,<script>alert(1)</script>',
        ]);
        $this->assertFalse($dl2->isValidUrl(), 'data: scheme must be rejected.');

        $dl3 = new DownloadSource([
            'url' => 'file:///etc/passwd',
        ]);
        $this->assertFalse($dl3->isValidUrl(), 'file: scheme must be rejected.');

        $dl4 = new DownloadSource([
            'url' => 'https://cdn.example.com/movie.mp4',
        ]);
        $this->assertTrue($dl4->isValidUrl(), 'Valid HTTPS URL must be accepted.');
    }

    // 23. Sort order respected across multiple sources
    public function testSortOrderRespectedAcrossSources(): void
    {
        $movie = $this->createTestMovie();

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Third Option',
            'url'          => 'https://cdn.example.com/3.mp4',
            'sort_order'   => 30,
            'is_active'    => 1,
        ]);

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'First Option',
            'url'          => 'https://cdn.example.com/1.mp4',
            'sort_order'   => 10,
            'is_active'    => 1,
        ]);

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Second Option',
            'url'          => 'https://cdn.example.com/2.mp4',
            'sort_order'   => 20,
            'is_active'    => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertCount(3, $result['options']);
        $this->assertEquals('First Option', $result['options'][0]['label']);
        $this->assertEquals('Second Option', $result['options'][1]['label']);
        $this->assertEquals('Third Option', $result['options'][2]['label']);
    }

    // 24. One option renders single download button data
    public function testOneOptionRendersSingleDownloadButtonData(): void
    {
        $movie = $this->createTestMovie();
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Direct Download',
            'url'          => 'https://cdn.example.com/single.mp4',
            'is_active'    => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertEquals(1, $result['count']);
        $this->assertNotEmpty($result['download_url']);
    }

    // 25. Multiple options provide count > 1 for selector rendering
    public function testMultipleOptionsProvideCountGreaterThanOne(): void
    {
        $movie = $this->createTestMovie();
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => '1080p',
            'url'          => 'https://cdn.example.com/1080p.mp4',
            'is_active'    => 1,
        ]);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => '720p',
            'url'          => 'https://cdn.example.com/720p.mp4',
            'is_active'    => 1,
        ]);

        $result = DownloadSourceService::resolveDownloadOptions(null, 'movie', $movie);
        $this->assertGreaterThan(1, $result['count']);
        $this->assertCount(2, $result['options']);
    }

    // 26. Old single-download content still works after upgrade
    public function testOldSingleDownloadContentStillWorksAfterUpgrade(): void
    {
        // Simulate v1.0.5 database with only download_url on movie
        $movie = $this->createTestMovie(['download_url' => 'https://cdn.example.com/legacy-only.mp4']);

        $result = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie);
        $this->assertTrue($result['allowed']);
        $this->assertNotEmpty($result['download_url']);
        $this->assertEquals(1, $result['count']);
    }

    // 27. Controlled route /multimedia/download-source/{id} authorization
    public function testControlledRouteDownloadSourceAuthorization(): void
    {
        $movie = $this->createTestMovie(['access_mode' => 'public']);
        $dlId = $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'label'        => 'Public Video',
            'url'          => 'https://cdn.example.com/stream-dest.mp4',
            'is_active'    => 1,
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $this->playbackCtrl->downloadSource($req, (string)$dlId);

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertEquals(302, $resp->getStatusCode());
        $headers = $resp->getHeaders();
        $this->assertEquals('https://cdn.example.com/stream-dest.mp4', $headers['Location'] ?? null);
    }
}
