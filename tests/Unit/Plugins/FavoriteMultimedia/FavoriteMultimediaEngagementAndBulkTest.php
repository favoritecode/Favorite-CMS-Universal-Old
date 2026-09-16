<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\MultimediaComment;
use FavoriteCMS\Multimedia\Models\MultimediaRating;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Favorite Multimedia v1.0.7:
 * - Star Rating Submission & UX API (/multimedia/api/rate)
 * - Discussion Comments Submission & UX API (/multimedia/api/comment)
 * - Movies & Songs Multi-select and Bulk Actions (publish, draft, delete)
 */
class FavoriteMultimediaEngagementAndBulkTest extends TestCase
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

        $this->tempDb = sys_get_temp_dir() . '/fav_mm_eng_bulk_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        $this->createBaseSchema();

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        $pm = new PluginManager($this->app);
        $pm->activatePlugin('favorite-multimedia');
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->seedTestData();

        $this->adminCtrl = new MultimediaAdminController($this->app);
        $this->playbackCtrl = new MediaPlaybackController($this->app);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        $_SESSION = [];
        parent::tearDown();
    }

    private function createBaseSchema(): void
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            avatar TEXT,
            bio TEXT,
            status TEXT DEFAULT 'active',
            created_at DATETIME,
            updated_at DATETIME
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            description TEXT,
            created_at TEXT,
            updated_at TEXT
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            description TEXT,
            module TEXT,
            group_name TEXT,
            created_at TEXT,
            updated_at TEXT
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS user_roles (
            user_id INTEGER,
            role_id INTEGER,
            PRIMARY KEY (user_id, role_id)
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER,
            permission_id INTEGER,
            created_at TEXT,
            PRIMARY KEY (role_id, permission_id)
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_name TEXT,
            setting_key TEXT NOT NULL UNIQUE,
            value TEXT,
            type TEXT DEFAULT 'string',
            is_public INTEGER DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME
        )");
    }

    private function seedTestData(): void
    {
        $now = date('Y-m-d H:i:s');
        $rId = (int)$this->db->insert('roles', [
            'name' => 'Admin',
            'slug' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->execute("INSERT INTO users (id, username, name, email, password, role) VALUES
            (1, 'admin', 'Administrator', 'admin@example.com', 'hashed', 'admin'),
            (2, 'regular_user', 'John Doe', 'john@example.com', 'hashed', 'user')");

        $this->db->insert('user_roles', ['user_id' => 1, 'role_id' => $rId]);

        $permissions = [
            'multimedia_manage', 'multimedia_create', 'multimedia_edit', 'multimedia_delete',
            'multimedia_publish', 'multimedia_access_premium', 'multimedia_moderate'
        ];
        foreach ($permissions as $p) {
            $pId = (int)$this->db->insert('permissions', [
                'name' => ucfirst($p),
                'slug' => $p,
                'module' => 'multimedia',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->db->insert('role_permissions', ['role_id' => $rId, 'permission_id' => $pId]);
        }
    }

    // ==========================================
    // 1. Star Rating Tests
    // ==========================================

    public function testGuestRatingIsRejectedWith401(): void
    {
        $_SESSION = []; // Guest
        $req = new Request([], ['content_type' => 'movie', 'content_id' => 1, 'rating' => 5, '_token' => 'token'], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $res = $this->playbackCtrl->apiRate($req);
        $this->assertEquals(401, $res->getStatusCode());

        $data = json_decode($res->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertEquals('unauthenticated', $data['status']);
        $this->assertStringContainsString('Please sign in to rate.', $data['error']);
    }

    public function testAuthenticatedUserCanSubmitRating(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['_token'] = 'test_token';

        // Create movie
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title' => 'Inception',
            'slug' => 'inception',
            'status' => 'published',
            'access_mode' => 'public'
        ]);

        $req = new Request([], ['content_type' => 'movie', 'content_id' => $movieId, 'rating' => 5, '_token' => 'test_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $res = $this->playbackCtrl->apiRate($req);
        $this->assertEquals(200, $res->getStatusCode());

        $data = json_decode($res->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals(5, $data['user_rating']);
        $this->assertEquals(5.0, (float)$data['average_rating']);
        $this->assertEquals(1, (int)$data['rating_count']);
    }

    public function testInvalidRatingScoreOutOfBoundsIsRejected(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['_token'] = 'test_token';

        $req = new Request([], ['content_type' => 'movie', 'content_id' => 1, 'rating' => 6, '_token' => 'test_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $res = $this->playbackCtrl->apiRate($req);
        $this->assertEquals(400, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('1 and 5 stars', $data['error']);

        $reqZero = new Request([], ['content_type' => 'movie', 'content_id' => 1, 'rating' => 0, '_token' => 'test_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $resZero = $this->playbackCtrl->apiRate($reqZero);
        $this->assertEquals(400, $resZero->getStatusCode());
    }

    public function testUpdatingExistingRatingUpdatesAggregateWithoutDuplicatingCount(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['_token'] = 'test_token';

        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title' => 'Interstellar',
            'slug' => 'interstellar',
            'status' => 'published',
            'access_mode' => 'public'
        ]);

        // First rate 4
        $req1 = new Request([], ['content_type' => 'movie', 'content_id' => $movieId, 'rating' => 4, '_token' => 'test_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $res1 = $this->playbackCtrl->apiRate($req1);
        $data1 = json_decode($res1->getContent(), true);
        $this->assertEquals(4, $data1['user_rating']);
        $this->assertEquals(1, $data1['rating_count']);
        $this->assertEquals(4.0, (float)$data1['average_rating']);

        // Update to 2
        $req2 = new Request([], ['content_type' => 'movie', 'content_id' => $movieId, 'rating' => 2, '_token' => 'test_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $res2 = $this->playbackCtrl->apiRate($req2);
        $data2 = json_decode($res2->getContent(), true);
        $this->assertEquals(2, $data2['user_rating']);
        $this->assertEquals(1, $data2['rating_count']);
        $this->assertEquals(2.0, (float)$data2['average_rating']);
    }

    public function testRatingInvalidCsrfTokenIsForbidden(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['_token'] = 'secure_token';

        $req = new Request([], ['content_type' => 'movie', 'content_id' => 1, 'rating' => 5, '_token' => 'wrong_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $res = $this->playbackCtrl->apiRate($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    // ==========================================
    // 2. Comments Tests
    // ==========================================

    public function testGuestCommentIsRejectedWith401(): void
    {
        $_SESSION = [];
        $req = new Request([], ['content_type' => 'movie', 'content_id' => 1, 'body' => 'Great film!', '_token' => 'token'], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $res = $this->playbackCtrl->apiSaveComment($req);
        $this->assertEquals(401, $res->getStatusCode());

        $data = json_decode($res->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Please sign in to join the discussion.', $data['error']);
    }

    public function testEmptyOrWhitespaceCommentIsRejectedWith400(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['_token'] = 'test_token';

        $req = new Request([], ['content_type' => 'movie', 'content_id' => 1, 'body' => '   ', '_token' => 'test_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $res = $this->playbackCtrl->apiSaveComment($req);
        $this->assertEquals(400, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Comment cannot be empty.', $data['error']);
    }

    public function testCommentExceeding1000CharsIsRejectedWith400(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['_token'] = 'test_token';

        $longBody = str_repeat('a', 1001);
        $req = new Request([], ['content_type' => 'movie', 'content_id' => 1, 'body' => $longBody, '_token' => 'test_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $res = $this->playbackCtrl->apiSaveComment($req);
        $this->assertEquals(400, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Comment cannot exceed 1000 characters.', $data['error']);
    }

    public function testAuthenticatedUserCanSubmitCommentSuccessfully(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['_token'] = 'test_token';

        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title' => 'Avatar',
            'slug' => 'avatar',
            'status' => 'published',
            'access_mode' => 'public'
        ]);

        $req = new Request([], ['content_type' => 'movie', 'content_id' => $movieId, 'body' => 'Phenomenal visuals and score!', '_token' => 'test_token'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $res = $this->playbackCtrl->apiSaveComment($req);
        $this->assertEquals(200, $res->getStatusCode());

        $data = json_decode($res->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['comment']);
        $this->assertEquals('Phenomenal visuals and score!', $data['comment']['body']);
        $this->assertEquals($movieId, (int)$data['comment']['content_id']);
        $this->assertEquals('John Doe', $data['comment']['author']['display_name']);
    }

    public function testCommentReplyNestingWorks(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['_token'] = 'test_token';

        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title' => 'Dune',
            'slug' => 'dune',
            'status' => 'published',
            'access_mode' => 'public'
        ]);

        // Post top-level comment
        $res1 = $this->playbackCtrl->apiSaveComment(new Request([], [
            'content_type' => 'movie',
            'content_id' => $movieId,
            'body' => 'Top level comment',
            '_token' => 'test_token'
        ], [], [], [], ['REQUEST_METHOD' => 'POST']));
        $data1 = json_decode($res1->getContent(), true);
        $parentId = (int)$data1['comment']['id'];

        // Post reply
        $res2 = $this->playbackCtrl->apiSaveComment(new Request([], [
            'content_type' => 'movie',
            'content_id' => $movieId,
            'parent_id' => $parentId,
            'body' => 'I completely agree with you!',
            '_token' => 'test_token'
        ], [], [], [], ['REQUEST_METHOD' => 'POST']));
        $data2 = json_decode($res2->getContent(), true);
        $this->assertTrue($data2['success']);
        $this->assertEquals($parentId, (int)$data2['comment']['parent_id']);
    }

    // ==========================================
    // 3. Movies Bulk Actions Tests
    // ==========================================

    public function testMoviesBulkDelete(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_test_token';

        $m1 = (int)$this->db->insert('multimedia_movies', ['title' => 'Bulk Movie 1', 'slug' => 'bulk-1', 'status' => 'published']);
        $m2 = (int)$this->db->insert('multimedia_movies', ['title' => 'Bulk Movie 2', 'slug' => 'bulk-2', 'status' => 'draft']);
        $m3 = (int)$this->db->insert('multimedia_movies', ['title' => 'Keep Movie 3', 'slug' => 'keep-3', 'status' => 'published']);

        // Attach source to m1
        $this->db->insert('multimedia_sources', ['content_type' => 'movie', 'content_id' => $m1, 'source_type' => 'video', 'url_or_path' => 'https://example.com/v.mp4']);

        $req = new Request([], [
            'action' => 'bulk',
            'bulk_action' => 'delete',
            'ids' => [(string)$m1, (string)$m2],
            '_token' => 'valid_test_token'
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());

        $this->assertNull(Movie::find($m1));
        $this->assertNull(Movie::find($m2));
        $this->assertNotNull(Movie::find($m3));

        // Sources also deleted
        $this->assertEmpty(MediaSource::getForContent('movie', $m1, false));
        $this->assertStringContainsString('2 movie(s) deleted successfully', $_SESSION['flash_success'] ?? '');
    }

    public function testMoviesBulkDraft(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_test_token';

        $m1 = (int)$this->db->insert('multimedia_movies', ['title' => 'Movie 1', 'slug' => 'm-1', 'status' => 'published']);
        $m2 = (int)$this->db->insert('multimedia_movies', ['title' => 'Movie 2', 'slug' => 'm-2', 'status' => 'published']);

        $req = new Request([], [
            'action' => 'bulk',
            'bulk_action' => 'draft',
            'ids' => [$m1, $m2],
            '_token' => 'valid_test_token'
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());

        $this->assertEquals('draft', Movie::find($m1)->status);
        $this->assertEquals('draft', Movie::find($m2)->status);
        $this->assertStringContainsString('2 movie(s) moved to Draft', $_SESSION['flash_success'] ?? '');
    }

    public function testMoviesBulkPublishEnforcesPlayableSourceReadiness(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_test_token';

        // m1 has playable source
        $m1 = (int)$this->db->insert('multimedia_movies', ['title' => 'Ready Movie', 'slug' => 'ready-m', 'status' => 'draft']);
        $this->db->insert('multimedia_sources', ['content_type' => 'movie', 'content_id' => $m1, 'source_type' => 'video', 'url_or_path' => 'https://example.com/movie.mp4']);

        // m2 has NO playable source
        $m2 = (int)$this->db->insert('multimedia_movies', ['title' => 'Unready Movie', 'slug' => 'unready-m', 'status' => 'draft']);

        $req = new Request([], [
            'action' => 'bulk',
            'bulk_action' => 'publish',
            'ids' => [$m1, $m2],
            '_token' => 'valid_test_token'
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());

        $this->assertEquals('published', Movie::find($m1)->status);
        $this->assertEquals('draft', Movie::find($m2)->status);
        $this->assertStringContainsString('1 movie(s) kept as Draft', $_SESSION['flash_warning'] ?? '');
    }

    // ==========================================
    // 4. Songs Bulk Actions Tests
    // ==========================================

    public function testSongsBulkDelete(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_test_token';

        $s1 = (int)$this->db->insert('multimedia_songs', ['title' => 'Song 1', 'slug' => 'song-1', 'status' => 'published', 'playback_type' => 'audio']);
        $s2 = (int)$this->db->insert('multimedia_songs', ['title' => 'Song 2', 'slug' => 'song-2', 'status' => 'draft', 'playback_type' => 'audio']);
        $s3 = (int)$this->db->insert('multimedia_songs', ['title' => 'Song 3', 'slug' => 'song-3', 'status' => 'published', 'playback_type' => 'audio']);

        $req = new Request([], [
            'action' => 'bulk',
            'bulk_action' => 'delete',
            'ids' => [$s1, $s2],
            '_token' => 'valid_test_token'
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->adminCtrl->songs($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());

        $this->assertNull(Song::find($s1));
        $this->assertNull(Song::find($s2));
        $this->assertNotNull(Song::find($s3));
        $this->assertStringContainsString('2 song(s) deleted successfully', $_SESSION['flash_success'] ?? '');
    }

    public function testSongsBulkDraft(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_test_token';

        $s1 = (int)$this->db->insert('multimedia_songs', ['title' => 'Track 1', 'slug' => 'track-1', 'status' => 'published', 'playback_type' => 'audio']);
        $s2 = (int)$this->db->insert('multimedia_songs', ['title' => 'Track 2', 'slug' => 'track-2', 'status' => 'published', 'playback_type' => 'audio']);

        $req = new Request([], [
            'action' => 'bulk',
            'bulk_action' => 'draft',
            'ids' => [$s1, $s2],
            '_token' => 'valid_test_token'
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->adminCtrl->songs($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());

        $this->assertEquals('draft', Song::find($s1)->status);
        $this->assertEquals('draft', Song::find($s2)->status);
        $this->assertStringContainsString('2 song(s) moved to Draft', $_SESSION['flash_success'] ?? '');
    }

    public function testSongsBulkPublishEnforcesPlaybackTypeReadiness(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_test_token';

        // Audio song with audio source -> ready
        $s1 = (int)$this->db->insert('multimedia_songs', ['title' => 'Audio Ready', 'slug' => 'audio-ready', 'status' => 'draft', 'playback_type' => 'audio']);
        $this->db->insert('multimedia_sources', ['content_type' => 'song', 'content_id' => $s1, 'source_type' => 'audio', 'url_or_path' => 'https://example.com/a.mp3']);

        // Audio song with NO audio source -> unready
        $s2 = (int)$this->db->insert('multimedia_songs', ['title' => 'Audio Unready', 'slug' => 'audio-unready', 'status' => 'draft', 'playback_type' => 'audio']);

        // Dual-mode song with audio but NO video -> unready
        $s3 = (int)$this->db->insert('multimedia_songs', ['title' => 'Dual Unready', 'slug' => 'dual-unready', 'status' => 'draft', 'playback_type' => 'audio_video']);
        $this->db->insert('multimedia_sources', ['content_type' => 'song', 'content_id' => $s3, 'source_type' => 'audio', 'url_or_path' => 'https://example.com/dual.mp3']);

        $req = new Request([], [
            'action' => 'bulk',
            'bulk_action' => 'publish',
            'ids' => [$s1, $s2, $s3],
            '_token' => 'valid_test_token'
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->adminCtrl->songs($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());

        $this->assertEquals('published', Song::find($s1)->status);
        $this->assertEquals('draft', Song::find($s2)->status);
        $this->assertEquals('draft', Song::find($s3)->status);
        $this->assertStringContainsString('2 song(s) kept as Draft', $_SESSION['flash_warning'] ?? '');
    }

    public function testBulkActionWithNoSelectionReturnsFlashError(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_test_token';

        $req = new Request([], [
            'action' => 'bulk',
            'bulk_action' => 'delete',
            'ids' => [],
            '_token' => 'valid_test_token'
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());
        $this->assertEquals('No movies were selected for bulk action.', $_SESSION['flash_error'] ?? '');
    }
}
