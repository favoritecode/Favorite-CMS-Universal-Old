<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\UploadSecurityService;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaRolePublishWorkflowTest extends TestCase
{
    private Application $app;
    private Database $db;
    private string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_workflow_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->app = new Application(APP_ROOT);
        Application::setInstance($this->app);
        Container::setInstance($this->app);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $config = new Config([]);
        $this->app->instance(Config::class, $config);
        $this->app->instance('config', $config);
        $this->app->instance(Database::class, $this->db);
        $this->app->instance('db', $this->db);

        $this->createSchema($pdo);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['_token'] = 'workflow_csrf_test_token';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function createSchema(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username VARCHAR(50), name VARCHAR(100), email VARCHAR(100), password VARCHAR(255), status VARCHAR(20), role VARCHAR(50) DEFAULT 'subscriber', email_verified_at DATETIME, created_at DATETIME, updated_at DATETIME);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, created_at DATETIME, updated_at DATETIME);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");

        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        // Seed roles
        $pdo->exec("INSERT INTO roles (id, name, slug) VALUES 
            (1, 'Administrator', 'admin'),
            (2, 'Moderator', 'moderator'),
            (3, 'Author', 'author'),
            (4, 'Contributor', 'contributor'),
            (5, 'Subscriber', 'subscriber')");

        $now = date('Y-m-d H:i:s');
        // Admin (ID 10 - not ID 1, proving dynamic admin resolution)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (10, 'adminuser', 'Site Admin', 'admin@example.com', 'pwd', 'admin', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (10, 1)");

        // Moderator (ID 20)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (20, 'moduser', 'Content Moderator', 'mod@example.com', 'pwd', 'moderator', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (20, 2)");

        // Author (ID 30)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (30, 'authoruser', 'Creator Author', 'author@example.com', 'pwd', 'author', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (30, 3)");

        // Contributor (ID 40)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (40, 'contribuser', 'Community Contributor', 'contrib@example.com', 'pwd', 'contributor', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (40, 4)");

        // Subscriber (ID 50)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (50, 'subuser', 'Standard Subscriber', 'sub@example.com', 'pwd', 'subscriber', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (50, 5)");
    }

    private function setUser(int $userId): User
    {
        $_SESSION['auth_user_id'] = $userId;
        $user = User::find($userId);
        $this->assertNotNull($user, "User ID {$userId} should exist in test database");
        return $user;
    }

    // 1. Migration 013 Schema Verification
    public function testMigration013AddsOwnershipAndModerationColumns(): void
    {
        $tables = [
            'multimedia_movies',
            'multimedia_series',
            'multimedia_episodes',
            'multimedia_songs',
            'multimedia_albums',
            'multimedia_playlists',
        ];

        $requiredCols = [
            'user_id',
            'approved_by',
            'approved_at',
            'rejected_by',
            'rejected_at',
            'rejection_reason',
        ];

        foreach ($tables as $table) {
            $colsInfo = $this->db->select("PRAGMA table_info({$table})");
            $colNames = array_map(fn($c) => $c->name, $colsInfo);

            foreach ($requiredCols as $req) {
                $this->assertContains(
                    $req,
                    $colNames,
                    "Table {$table} must contain column {$req} after Migration 013"
                );
            }
        }
    }

    // 2. Canonical Admin Resolution (Never Hardcode ID 1)
    public function testCanonicalAdminResolverFindsAdminDynamically(): void
    {
        $migrationFile = APP_ROOT . '/plugins/favorite-multimedia/database/migrations/013_add_multimedia_ownership_and_moderation_fields.php';
        $this->assertFileExists($migrationFile);

        require_once $migrationFile;
        $migrationClass = new \AddMultimediaOwnershipAndModerationFields($this->db);
        $refMethod = new \ReflectionMethod($migrationClass, 'resolveCanonicalAdminId');
        
        $resolvedId = $refMethod->invoke($migrationClass, $this->db);
        // Admin was created at ID 10 in setUp, NOT ID 1
        $this->assertEquals(10, $resolvedId, "resolveCanonicalAdminId must resolve dynamic admin ID (10) rather than assuming 1");
    }

    // 3. Permission Matrix Tests
    public function testPermissionMatrixForRoles(): void
    {
        $admin = $this->setUser(10);
        $mod   = $this->setUser(20);
        $auth  = $this->setUser(30);
        $cnt   = $this->setUser(40);
        $sub   = $this->setUser(50);

        // Admin: can do everything
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::PUBLISH, $admin));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::MODERATE, $admin));
        $this->assertTrue(MultimediaPermission::canUserSubmit('movie', $admin));

        // Moderator: can publish, moderate, cannot change core settings without permission
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::PUBLISH, $mod));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::MODERATE, $mod));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::EDIT_OTHERS, $mod));

        // Author: can create own, edit own, CANNOT publish directly or moderate
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::CREATE, $auth));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::EDIT_OWN, $auth));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::PUBLISH, $auth));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MODERATE, $auth));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::EDIT_OTHERS, $auth));

        // Contributor: fail closed in locked matrix
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::CREATE, $cnt));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::PUBLISH, $cnt));

        // Subscriber: strictly NO public multimedia creation capability in locked matrix
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::CREATE, $sub));
        $this->assertFalse(MultimediaPermission::canUserSubmit('movie', $sub));

        // Per-type upload switch enforcement
        Setting::set('multimedia', 'allow_user_movie_upload', 'no');
        $this->assertFalse(MultimediaPermission::canUserSubmit('movie', $auth));
        $this->assertTrue(MultimediaPermission::canUserSubmit('song', $auth));
        Setting::set('multimedia', 'allow_user_movie_upload', 'yes');
        $this->assertTrue(MultimediaPermission::canUserSubmit('movie', $auth));
    }

    // 4. Ownership Scoping & Parent Ownership Enforcement
    public function testOwnershipScopingAndParentOwnership(): void
    {
        $admin = $this->setUser(10);
        $auth  = $this->setUser(30);
        $cnt   = $this->setUser(40);

        // Create movie owned by Author (ID 30) - pending status
        $movieId = $this->db->insert('multimedia_movies', [
            'user_id' => 30,
            'title'   => 'Author Movie',
            'slug'    => 'author-movie',
            'status'  => 'pending',
        ]);
        $movieObj = Movie::find($movieId);

        // Author can edit and delete own pending movie
        $this->assertTrue(MultimediaPermission::canEditContent($movieObj, $auth));
        $this->assertTrue(MultimediaPermission::canDeleteContent($movieObj, $auth));

        // Contributor cannot edit or delete Author's movie
        $this->assertFalse(MultimediaPermission::canEditContent($movieObj, $cnt));
        $this->assertFalse(MultimediaPermission::canDeleteContent($movieObj, $cnt));

        // Published movie: Author can edit (reverts to pending), but CANNOT delete directly
        $pubMovieId = $this->db->insert('multimedia_movies', [
            'user_id' => 30,
            'title'   => 'Author Published Movie',
            'slug'    => 'author-published-movie',
            'status'  => 'published',
        ]);
        $pubMovieObj = Movie::find($pubMovieId);
        $this->assertTrue(MultimediaPermission::canEditContent($pubMovieObj, $auth));
        $this->assertFalse(MultimediaPermission::canDeleteContent($pubMovieObj, $auth), "Published content cannot be deleted by non-moderator");
        $this->assertTrue(MultimediaPermission::canDeleteContent($pubMovieObj, $admin), "Admin can delete published content");

        // Movie::forUser scoping
        $authorMovies = Movie::forUser(30);
        $contribMovies = Movie::forUser(40);
        $this->assertCount(2, $authorMovies);
        $this->assertCount(0, $contribMovies);

        // Parent ownership: Author creates a series
        $seriesId = $this->db->insert('multimedia_series', [
            'user_id' => 30,
            'title'   => 'Author Series',
            'slug'    => 'author-series',
            'status'  => 'published',
        ]);

        // Contributor trying to add an episode under Author's series must fail
        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [
            'action'    => 'create',
            'series_id' => $seriesId,
            'title'     => 'Hacked Episode',
            '_token'    => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->setUser(40);
        $resp = $controller->episodes($req);
        $this->assertInstanceOf(Response::class, $resp);
        $this->assertEquals(403, $resp->getStatusCode(), "Adding episode under someone else's series must return 403 Forbidden");

        // Contributor trying to add source to Author's movie must fail
        $reqSource = new Request([], [
            'action'       => 'create',
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'video_url'    => 'https://example.com/video.mp4',
            '_token'       => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $respSource = $controller->sources($reqSource);
        $this->assertInstanceOf(Response::class, $respSource);
        $this->assertEquals(403, $respSource->getStatusCode(), "Adding source to someone else's movie must return 403 Forbidden");

        // Contributor trying to add subtitle to Author's movie must fail
        $reqSub = new Request([], [
            'action'       => 'create',
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'file_or_url'  => 'https://example.com/sub.vtt',
            '_token'       => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $respSub = $controller->subtitles($reqSub);
        $this->assertInstanceOf(Response::class, $respSub);
        $this->assertEquals(403, $respSub->getStatusCode(), "Adding subtitle to someone else's movie must return 403 Forbidden");
    }

    // 5. Forced Pending Workflow on Creation for Non-Publishers
    public function testForcedPendingOnCreationForNonPublishers(): void
    {
        $this->setUser(30); // Author (non-publisher)
        $controller = new MultimediaAdminController($this->app);

        // Create movie with submit_action = publish
        $req = new Request([], [
            'action'        => 'create',
            'title'         => 'Author Indie Film',
            'submit_action' => 'publish',
            'video_url'     => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4',
            '_token'        => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->movies($req);
        $this->assertInstanceOf(Response::class, $resp);
        $this->assertEquals(302, $resp->getStatusCode());

        $movie = $this->db->selectOne("SELECT * FROM multimedia_movies WHERE slug = 'author-indie-film'");
        $this->assertNotNull($movie);
        $this->assertEquals(30, (int)$movie->user_id, "user_id must match authenticated author");
        $this->assertEquals('pending', $movie->status, "Non-publisher submissions must be forced to 'pending' status");
        $this->assertNull($movie->approved_by, "approved_by must remain NULL while pending");

        // Create song with submit_action = publish
        $reqSong = new Request([], [
            'action'        => 'create',
            'title'         => 'Author Track',
            'submit_action' => 'publish',
            'audio_url'     => 'https://example.com/track.mp3',
            '_token'        => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $respSong = $controller->songs($reqSong);
        $this->assertInstanceOf(Response::class, $respSong);
        $song = $this->db->selectOne("SELECT * FROM multimedia_songs WHERE slug = 'author-track'");
        $this->assertNotNull($song);
        $this->assertEquals('pending', $song->status, "Song submissions from authors must be forced to pending");

        // Create album with submit_action = publish
        $reqAlbum = new Request([], [
            'action'        => 'create',
            'title'         => 'Author Debut Album',
            'submit_action' => 'publish',
            '_token'        => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $respAlbum = $controller->albums($reqAlbum);
        $this->assertInstanceOf(Response::class, $respAlbum);
        $album = $this->db->selectOne("SELECT * FROM multimedia_albums WHERE slug = 'author-debut-album'");
        $this->assertNotNull($album);
        $this->assertEquals('pending', $album->status, "Album submissions from authors must be forced to pending");

        // Create playlist with submit_action = publish
        $reqPlaylist = new Request([], [
            'action'        => 'create',
            'title'         => 'Author Chill Playlist',
            'submit_action' => 'publish',
            '_token'        => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $respPlaylist = $controller->playlists($reqPlaylist);
        $this->assertInstanceOf(Response::class, $respPlaylist);
        $playlist = $this->db->selectOne("SELECT * FROM multimedia_playlists WHERE slug = 'author-chill-playlist'");
        $this->assertNotNull($playlist);
        $this->assertEquals('pending', $playlist->status, "Playlist submissions from authors must be forced to pending");
    }

    // 6. Published-Edit Moderation Rule (Regression Test)
    public function testNonModeratorEditingPublishedContentRevertsToPendingWithWarning(): void
    {
        // 1. Seed a movie that is already PUBLISHED
        $movieId = $this->db->insert('multimedia_movies', [
            'user_id'      => 30, // Owned by author
            'title'        => 'Original Masterpiece',
            'slug'         => 'original-masterpiece',
            'status'       => 'published',
            'published_at' => date('Y-m-d H:i:s'),
            'approved_by'  => 10,
        ]);
        // Add a media source so it is valid
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_mode'  => 'url',
            'source_type'  => 'mp4',
            'url_or_path'  => 'https://example.com/video.mp4',
            'is_default'   => 1,
            'status'       => 'active',
        ]);

        // 2. Author (user 30) edits the published movie
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [
            'action'        => 'edit',
            'id'            => $movieId,
            'title'         => 'Original Masterpiece (Updated)',
            'submit_action' => 'publish',
            'video_url'     => 'https://example.com/video.mp4',
            '_token'        => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->movies($req);
        $this->assertInstanceOf(Response::class, $resp);

        // Verify status reverted from published to pending!
        $updatedMovie = Movie::find($movieId);
        $this->assertEquals('pending', $updatedMovie->status, "Editing published content by non-moderator MUST revert status to pending");
        $this->assertNull($updatedMovie->approved_by, "approved_by must be cleared upon revert to pending");

        // Verify user flash warning is set
        $this->assertNotEmpty($_SESSION['flash_warning'] ?? '');
        $this->assertStringContainsString('returned this item to Pending Review', $_SESSION['flash_warning']);

        // 3. Now verify Moderator editing keeps it PUBLISHED
        $this->setUser(20); // Moderator
        $reqMod = new Request([], [
            'action'        => 'edit',
            'id'            => $movieId,
            'title'         => 'Original Masterpiece (Mod Approved)',
            'submit_action' => 'publish',
            'video_url'     => 'https://example.com/video.mp4',
            '_token'        => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $respMod = $controller->movies($reqMod);
        $this->assertInstanceOf(Response::class, $respMod);

        $modMovie = Movie::find($movieId);
        $this->assertEquals('published', $modMovie->status, "Moderator editing published content must remain published");
    }

    // 7. Moderation Approval and Rejection Flow
    public function testModeratorApproveAndRejectActions(): void
    {
        // Seed pending movie
        $movieId = $this->db->insert('multimedia_movies', [
            'user_id' => 30,
            'title'   => 'Pending Movie For Moderation',
            'slug'    => 'pending-movie-for-moderation',
            'status'  => 'pending',
        ]);

        // Moderator (ID 20) approves the movie
        $this->setUser(20);
        $controller = new MultimediaAdminController($this->app);
        $approveReq = new Request([], [
            'action'       => 'approve',
            'target_type'  => 'content',
            'content_type' => 'movie',
            'target_id'    => $movieId,
            '_token'       => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $respApprove = $controller->moderation($approveReq);
        $this->assertInstanceOf(Response::class, $respApprove);

        $approvedMovie = Movie::find($movieId);
        $this->assertEquals('published', $approvedMovie->status);
        $this->assertEquals(20, (int)$approvedMovie->approved_by);
        $this->assertNotNull($approvedMovie->approved_at);
        $this->assertNull($approvedMovie->rejected_by);

        // Seed another pending song
        $songId = $this->db->insert('multimedia_songs', [
            'user_id' => 30,
            'title'   => 'Low Quality Track',
            'slug'    => 'low-quality-track',
            'status'  => 'pending',
        ]);

        // Moderator rejects the song with reason
        $rejectReq = new Request([], [
            'action'           => 'reject',
            'target_type'      => 'content',
            'content_type'     => 'song',
            'target_id'        => $songId,
            'rejection_reason' => 'Audio bitrate is too low and noisy.',
            '_token'           => 'workflow_csrf_test_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $respReject = $controller->moderation($rejectReq);
        $this->assertInstanceOf(Response::class, $respReject);

        $rejectedSong = Song::find($songId);
        $this->assertEquals('rejected', $rejectedSong->status);
        $this->assertEquals(20, (int)$rejectedSong->rejected_by);
        $this->assertNotNull($rejectedSong->rejected_at);
        $this->assertEquals('Audio bitrate is too low and noisy.', $rejectedSong->rejection_reason);
    }

    // 8. Public Leakage Protection
    public function testPublicLeakageProtectionForUnpublishedContent(): void
    {
        $movieId = $this->db->insert('multimedia_movies', [
            'user_id'     => 30,
            'title'       => 'Secret Unapproved Film',
            'slug'        => 'secret-unapproved-film',
            'status'      => 'pending',
            'access_mode' => 'public',
        ]);
        $movie = Movie::find($movieId);

        // Anonymous user / guest
        $this->assertFalse(MultimediaAccessService::canAccess(null, $movie), "Unpublished pending content must NOT be accessible to guests");

        // Other regular user (contributor ID 40)
        $otherUser = User::find(40);
        $this->assertFalse(MultimediaAccessService::canAccess($otherUser, $movie), "Unpublished pending content must NOT be accessible to other regular users");

        // Owner (author ID 30) CAN preview their own pending content
        $owner = User::find(30);
        $this->assertTrue(MultimediaAccessService::canAccess($owner, $movie), "Owner must be able to view their own pending submission");

        // Moderator (ID 20) CAN preview pending content
        $mod = User::find(20);
        $this->assertTrue(MultimediaAccessService::canAccess($mod, $movie), "Moderator must be able to view pending submission");
    }

    // 9. Upload Security Validation
    public function testUploadSecurityValidation(): void
    {
        $tempDir = sys_get_temp_dir();

        // A. Valid MP4 video
        $validVideo = $tempDir . '/test_valid.mp4';
        file_put_contents($validVideo, 'ftypisom' . str_repeat('0', 500));
        $res = UploadSecurityService::validate([
            'name'     => 'test_valid.mp4',
            'tmp_name' => $validVideo,
            'size'     => filesize($validVideo),
            'error'    => UPLOAD_ERR_OK,
        ], UploadSecurityService::CATEGORY_VIDEO);
        $this->assertTrue($res['valid']);
        @unlink($validVideo);

        // B. Dangerous extension (.php)
        $phpFile = $tempDir . '/shell.php';
        file_put_contents($phpFile, '<?php echo 1;');
        $resPhp = UploadSecurityService::validate([
            'name'     => 'shell.php',
            'tmp_name' => $phpFile,
            'size'     => filesize($phpFile),
            'error'    => UPLOAD_ERR_OK,
        ], UploadSecurityService::CATEGORY_VIDEO);
        $this->assertFalse($resPhp['valid']);
        $this->assertStringContainsString('strictly prohibited', $resPhp['error']);
        @unlink($phpFile);

        // C. Double-extension defense (.php.mp4)
        $doubleExtFile = $tempDir . '/exploit.php.mp4';
        file_put_contents($doubleExtFile, 'fake video');
        $resDouble = UploadSecurityService::validate([
            'name'     => 'exploit.php.mp4',
            'tmp_name' => $doubleExtFile,
            'size'     => filesize($doubleExtFile),
            'error'    => UPLOAD_ERR_OK,
        ], UploadSecurityService::CATEGORY_VIDEO);
        $this->assertFalse($resDouble['valid']);
        $this->assertStringContainsString('Double-extension', $resDouble['error']);
        @unlink($doubleExtFile);

        // D. Traversal attempt (../../malicious.mp4)
        $traversalFile = $tempDir . '/dummy.mp4';
        file_put_contents($traversalFile, 'data');
        $resTrav = UploadSecurityService::validate([
            'name'     => '../../etc/passwd.mp4',
            'tmp_name' => $traversalFile,
            'size'     => filesize($traversalFile),
            'error'    => UPLOAD_ERR_OK,
        ], UploadSecurityService::CATEGORY_VIDEO);
        $this->assertFalse($resTrav['valid']);
        $this->assertStringContainsString('traversal', $resTrav['error']);
        @unlink($traversalFile);

        // E. File size limit exceeded
        Setting::set('multimedia', 'max_image_upload_mb', '1');
        $bigImage = $tempDir . '/big.jpg';
        file_put_contents($bigImage, str_repeat('X', 2 * 1024 * 1024)); // 2MB
        $resBig = UploadSecurityService::validate([
            'name'     => 'big.jpg',
            'tmp_name' => $bigImage,
            'size'     => filesize($bigImage),
            'error'    => UPLOAD_ERR_OK,
        ], UploadSecurityService::CATEGORY_IMAGE);
        $this->assertFalse($resBig['valid']);
        $this->assertStringContainsString('exceeds the maximum limit', $resBig['error']);
        @unlink($bigImage);
    }
}

