<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Http\Controllers\Admin\UserController;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaCreatorAccessTest extends TestCase
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

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_creator_' . uniqid() . '.sqlite';
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
        $_SESSION = [];
        $_SESSION['_token'] = 'test_csrf_token';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, group_name VARCHAR(50));");
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (role_id INTEGER, permission_id INTEGER, permission_key VARCHAR(100), PRIMARY KEY (role_id, permission_id));");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");
        $pdo->exec("CREATE TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY, title VARCHAR(255), slug VARCHAR(255), content TEXT, type VARCHAR(50) DEFAULT 'post', status VARCHAR(20), user_id INTEGER, created_at DATETIME, updated_at DATETIME);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY, post_id INTEGER, user_id INTEGER, content TEXT, status VARCHAR(20), created_at DATETIME, updated_at DATETIME);");

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

        // Admin (ID 10)
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

        FavoriteMultimediaPlugin::ensureDefaultSettings();
        MultimediaPermission::registerDefaultPermissions($this->db);
    }

    private function setUser(int $userId): User
    {
        $_SESSION['auth_user_id'] = $userId;
        $user = User::find($userId);
        $this->assertNotNull($user, "User ID {$userId} must exist in test database");
        return $user;
    }

    private function getResponseStatus(Response|string $resp): int
    {
        return ($resp instanceof Response) ? $resp->getStatusCode() : 200;
    }

    private function getResponseContent(Response|string $resp): string
    {
        return ($resp instanceof Response) ? $resp->getContent() : (string)$resp;
    }

    private function getResponseLocation(Response|string $resp): ?string
    {
        if ($resp instanceof Response) {
            return $resp->getHeaders()['Location'] ?? null;
        }
        return null;
    }

    // 1. Normal user reaches profile without 500
    public function testNormalUserReachesProfileWithout500(): void
    {
        $normalRoles = [30 => 'author', 40 => 'contributor', 50 => 'subscriber'];

        foreach ($normalRoles as $userId => $roleName) {
            $this->setUser($userId);
            $userController = new UserController($this->app);
            $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

            $resp = $userController->profile($req);
            $this->assertEquals(200, $resp->getStatusCode(), "Role {$roleName} must reach profile with HTTP 200");
            $content = $resp->getContent();
            $this->assertStringContainsString('Profile', $content);
            $this->assertStringNotContainsString('500 — Internal Server Error', $content);
        }
    }

    // 2. Author can access My Submissions (/admin/page/multimedia-my-submissions)
    public function testAuthorCanAccessMySubmissions(): void
    {
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'my-submissions');
        $this->assertEquals(200, $this->getResponseStatus($resp));
        $this->assertStringContainsString('My Multimedia Submissions', $this->getResponseContent($resp));
    }

    // 3. Contributor fails closed on My Submissions (not in locked matrix)
    public function testContributorCanAccessMySubmissions(): void
    {
        $this->setUser(40);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'my-submissions');
        $this->assertEquals(403, $this->getResponseStatus($resp));
    }

    // 4. Subscriber receives 403 on My Submissions and catalog routes
    public function testSubscriberDisabledReceives403(): void
    {
        Setting::set('multimedia', 'allow_subscriber_submissions', 'no');
        $this->setUser(50);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $respSubmissions = $controller->handle($req, 'my-submissions');
        $this->assertEquals(403, $this->getResponseStatus($respSubmissions));
        $this->assertStringContainsString('Access Denied', $this->getResponseContent($respSubmissions));

        $respMovies = $controller->handle($req, 'movies');
        $this->assertEquals(403, $this->getResponseStatus($respMovies));
    }

    // 5. Subscriber has NO creator dashboard access in locked matrix (fails closed)
    public function testSubscriberEnabledCanAccessMySubmissions(): void
    {
        Setting::set('multimedia', 'allow_subscriber_submissions', 'yes');
        $this->setUser(50);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'my-submissions');
        $this->assertEquals(403, $this->getResponseStatus($resp));
    }

    // 6. Creator profile link targets /admin/page/multimedia-my-submissions
    public function testCreatorProfileLinkTargetsMySubmissions(): void
    {
        $pluginHeader = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/header.php';
        $themeHeader  = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/header.php';

        $this->assertFileExists($pluginHeader);
        $this->assertFileExists($themeHeader);

        $pluginContent = file_get_contents($pluginHeader);
        $themeContent  = file_get_contents($themeHeader);

        $this->assertStringContainsString('/admin/page/multimedia-my-submissions', $pluginContent);
        $this->assertStringContainsString('/admin/page/multimedia-my-submissions', $themeContent);
    }

    // 7. Creator can open Movie create form (/admin/page/multimedia-movies?new=1)
    public function testCreatorCanOpenMovieCreateForm(): void
    {
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'movies');
        $this->assertEquals(200, $this->getResponseStatus($resp));
        $this->assertStringContainsString('Add New Movie', $this->getResponseContent($resp));
    }

    // 8. Creator can open Song create form (/admin/page/multimedia-songs?new=1)
    public function testCreatorCanOpenSongCreateForm(): void
    {
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'songs');
        $this->assertEquals(200, $this->getResponseStatus($resp));
        $this->assertStringContainsString('Add New Song', $this->getResponseContent($resp));
    }

    // 9. Creator can open Series create form (/admin/page/multimedia-series?new=1)
    public function testCreatorCanOpenSeriesCreateForm(): void
    {
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'series');
        $this->assertEquals(200, $this->getResponseStatus($resp));
        $this->assertStringContainsString('Add New Series', $this->getResponseContent($resp));
    }

    // 10. Creator can open Playlist create form (/admin/page/multimedia-playlists?new=1)
    public function testCreatorCanOpenPlaylistCreateForm(): void
    {
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'playlists');
        $this->assertEquals(200, $this->getResponseStatus($resp));
        $this->assertStringContainsString('Create New Playlist', $this->getResponseContent($resp));
    }

    // 11. Creator submission is forced to pending review
    public function testCreatorSubmissionIsForcedToPending(): void
    {
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action' => 'create',
            'title'  => 'Author Test Movie',
            'slug'   => 'author-test-movie',
            'status' => 'published', // Attempting to self-publish
            '_token' => 'test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->handle($req, 'movies');
        $this->assertEquals(302, $this->getResponseStatus($resp));

        $movie = $this->db->selectOne("SELECT * FROM multimedia_movies WHERE slug = ?", ['author-test-movie']);
        $this->assertNotNull($movie);
        $this->assertEquals('pending', $movie->status, 'Creator submitted content must be forced to pending');
        $this->assertEquals(30, (int)$movie->user_id);
    }

    // 12. Creator cannot publish directly (multimedia.publish = false)
    public function testCreatorCannotPublishDirectly(): void
    {
        $author = $this->setUser(30);
        $contrib = $this->setUser(40);

        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::PUBLISH, $author));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::PUBLISH, $contrib));
    }

    // 13. Creator cannot moderate (multimedia.moderate = false)
    public function testCreatorCannotModerate(): void
    {
        $author = $this->setUser(30);
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MODERATE, $author));

        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'moderation');
        $this->assertEquals(302, $this->getResponseStatus($resp));
        $this->assertEquals('/admin/page/multimedia-my-submissions', $this->getResponseLocation($resp));

        // Contributor is fail-closed
        $contrib = $this->setUser(40);
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MODERATE, $contrib));
        $respContrib = $controller->handle($req, 'moderation');
        $this->assertEquals(403, $this->getResponseStatus($respContrib));
    }

    // 14. Creator cannot view/edit another user's content
    public function testCreatorCannotViewOrEditAnotherUsersContent(): void
    {
        // Contributor (ID 40) creates a movie
        $otherMovieId = $this->db->insert('multimedia_movies', [
            'user_id' => 40,
            'title'   => 'Contributor Movie',
            'slug'    => 'contributor-movie',
            'status'  => 'pending',
        ]);

        // Author (ID 30) attempts to open edit form for Contributor's movie
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request(['edit' => (string)$otherMovieId], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'movies');
        $this->assertEquals(302, $this->getResponseStatus($resp));
        $this->assertEquals('/admin/page/multimedia-my-submissions', $this->getResponseLocation($resp));
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');

        // Author attempts to POST an edit to Contributor's movie
        $reqPost = new Request([], [
            'action' => 'edit',
            'id'     => $otherMovieId,
            'title'  => 'Hacked Title',
            '_token' => 'test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $respPost = $controller->handle($reqPost, 'movies');
        $this->assertEquals(403, $this->getResponseStatus($respPost));

        $movieAfter = Movie::find($otherMovieId);
        $this->assertEquals('Contributor Movie', $movieAfter->title, 'Unauthorized update must be rejected');
    }

    // 15. Creator direct full-catalog access safely redirects to My Submissions with flash notice
    public function testCreatorDirectFullCatalogAccessRedirectsWithNotice(): void
    {
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);

        $catalogRoutes = ['movies', 'series', 'songs', 'albums', 'playlists'];

        foreach ($catalogRoutes as $route) {
            $_SESSION = ['auth_user_id' => 30, '_token' => 'test_csrf_token'];
            $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
            $resp = $controller->handle($req, $route);

            $this->assertEquals(302, $this->getResponseStatus($resp), "Route {$route} should redirect creator");
            $this->assertEquals('/admin/page/multimedia-my-submissions', $this->getResponseLocation($resp));
            $this->assertEquals('You can manage your own multimedia submissions here.', $_SESSION['flash_info'] ?? '');
        }
    }

    // 16. Creator can edit own pending content
    public function testCreatorCanEditOwnPendingContent(): void
    {
        $movieId = $this->db->insert('multimedia_movies', [
            'user_id' => 30,
            'title'   => 'Author Pending Movie',
            'slug'    => 'author-pending-movie',
            'status'  => 'pending',
        ]);

        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);

        // Can open edit form
        $reqGet = new Request(['edit' => (string)$movieId], [], ['REQUEST_METHOD' => 'GET']);
        $respGet = $controller->handle($reqGet, 'movies');
        $this->assertEquals(200, $this->getResponseStatus($respGet));
        $this->assertStringContainsString('Author Pending Movie', $this->getResponseContent($respGet));

        // Can submit update
        $reqPost = new Request([], [
            'action' => 'edit',
            'id'     => $movieId,
            'title'  => 'Author Pending Movie Updated',
            'slug'   => 'author-pending-movie-updated',
            '_token' => 'test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $respPost = $controller->handle($reqPost, 'movies');
        $this->assertEquals(302, $this->getResponseStatus($respPost));

        $updated = Movie::find($movieId);
        $this->assertEquals('Author Pending Movie Updated', $updated->title);
        $this->assertEquals('pending', $updated->status);
    }

    // 17. Creator can edit own draft content
    public function testCreatorCanEditOwnDraftContent(): void
    {
        $movieId = $this->db->insert('multimedia_movies', [
            'user_id' => 30,
            'title'   => 'Author Draft Movie',
            'slug'    => 'author-draft-movie',
            'status'  => 'draft',
        ]);

        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);

        $reqGet = new Request(['edit' => (string)$movieId], [], ['REQUEST_METHOD' => 'GET']);
        $respGet = $controller->handle($reqGet, 'movies');
        $this->assertEquals(200, $this->getResponseStatus($respGet));
        $this->assertStringContainsString('Author Draft Movie', $this->getResponseContent($respGet));

        $reqPost = new Request([], [
            'action'        => 'edit',
            'id'            => $movieId,
            'title'         => 'Author Draft Movie Updated',
            'slug'          => 'author-draft-movie-updated',
            'submit_action' => 'draft',
            '_token'        => 'test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $respPost = $controller->handle($reqPost, 'movies');
        $this->assertEquals(302, $this->getResponseStatus($respPost));

        $updated = Movie::find($movieId);
        $this->assertEquals('Author Draft Movie Updated', $updated->title);
        $this->assertEquals('draft', $updated->status);
    }

    // 18. Editing published returns status to pending and flashes 'Your changes were submitted for review.'
    public function testEditingPublishedReturnsStatusToPendingAndFlashesNotice(): void
    {
        $movieId = $this->db->insert('multimedia_movies', [
            'user_id'      => 30,
            'title'        => 'Author Published Movie',
            'slug'         => 'author-published-movie',
            'status'       => 'published',
            'published_at' => date('Y-m-d H:i:s'),
        ]);

        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);

        $reqPost = new Request([], [
            'action' => 'edit',
            'id'     => $movieId,
            'title'  => 'Author Published Movie Edited',
            'slug'   => 'author-published-movie-edited',
            '_token' => 'test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $respPost = $controller->handle($reqPost, 'movies');
        $this->assertEquals(302, $this->getResponseStatus($respPost));

        $updated = Movie::find($movieId);
        $this->assertEquals('Author Published Movie Edited', $updated->title);
        $this->assertEquals('pending', $updated->status, 'Editing published content must return status to pending');
        $this->assertEquals('Your changes were submitted for review.', $_SESSION['flash_success'] ?? '');
    }

    // 19. Resubmitting rejected content sets status back to pending, clears rejection reason, and flashes 'Your changes were submitted for review.'
    public function testResubmittingRejectedContentSetsPendingAndFlashesNotice(): void
    {
        $movieId = $this->db->insert('multimedia_movies', [
            'user_id'          => 30,
            'title'            => 'Author Rejected Movie',
            'slug'             => 'author-rejected-movie',
            'status'           => 'rejected',
            'rejection_reason' => 'Please provide higher resolution poster and backdrop',
            'rejected_by'      => 20,
            'rejected_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);

        $reqPost = new Request([], [
            'action'       => 'resubmit',
            'content_type' => 'movie',
            'id'           => $movieId,
            '_token'       => 'test_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $respPost = $controller->handle($reqPost, 'my-submissions');
        $this->assertEquals(302, $this->getResponseStatus($respPost));
        $this->assertEquals('/admin/page/multimedia-my-submissions', $this->getResponseLocation($respPost));

        $updated = Movie::find($movieId);
        $this->assertEquals('pending', $updated->status, 'Resubmitted item must transition to pending');
        $this->assertNull($updated->rejection_reason, 'Rejection reason must be cleared');
        $this->assertNull($updated->rejected_by, 'Rejected by must be cleared');
        $this->assertEquals('Your changes were submitted for review.', $_SESSION['flash_success'] ?? '');
    }

    // 20. Admin full Movies list remains unchanged
    public function testAdminFullMoviesListRemainsAccessible(): void
    {
        $this->setUser(10);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'movies');
        $this->assertEquals(200, $this->getResponseStatus($resp));
        $this->assertStringContainsString('Movies', $this->getResponseContent($resp));
    }

    // 21. Moderator full list remains unchanged
    public function testModeratorFullListRemainsAccessible(): void
    {
        $this->setUser(20);
        $controller = new MultimediaAdminController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $controller->handle($req, 'movies');
        $this->assertEquals(200, $this->getResponseStatus($resp));
        $this->assertStringContainsString('Movies', $this->getResponseContent($resp));
    }

    // 22. No other-user content leakage
    public function testNoOtherUserContentLeakage(): void
    {
        // Author (30) content
        $this->db->insert('multimedia_movies', [
            'user_id' => 30,
            'title'   => 'Author Secret Film',
            'slug'    => 'author-secret-film',
            'status'  => 'pending',
        ]);

        // Contributor (40) content
        $this->db->insert('multimedia_movies', [
            'user_id' => 40,
            'title'   => 'Contributor Draft Film',
            'slug'    => 'contributor-draft-film',
            'status'  => 'draft',
        ]);

        $authorMovies = Movie::forUser(30);
        $contribMovies = Movie::forUser(40);

        $this->assertCount(1, $authorMovies);
        $this->assertEquals('Author Secret Film', $authorMovies[0]->title);

        $this->assertCount(1, $contribMovies);
        $this->assertEquals('Contributor Draft Film', $contribMovies[0]->title);
    }

    // 23. Raw 403 is not shown for legitimate creator navigation; genuine unauthorized is still denied
    public function testRaw403NotShownForLegitimateCreatorNavigationAndGenuineUnauthorizedDenied(): void
    {
        // Legitimate creator navigation
        $this->setUser(30);
        $controller = new MultimediaAdminController($this->app);

        $routes = ['multimedia', 'movies', 'series', 'songs', 'albums', 'playlists', 'my-submissions'];

        foreach ($routes as $route) {
            $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
            $resp = $controller->handle($req, $route);

            $this->assertNotEquals(403, $this->getResponseStatus($resp), "Route {$route} must not return raw 403 for legitimate creator");
        }

        // Unauthenticated guest redirected to login
        $_SESSION = [];
        $reqGuest = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $respGuest = $controller->handle($reqGuest, 'movies');
        $this->assertEquals(302, $this->getResponseStatus($respGuest));
        $this->assertEquals('/admin/login', $this->getResponseLocation($respGuest));

        // Ineligible subscriber receives 403 Forbidden
        Setting::set('multimedia', 'allow_subscriber_submissions', 'no');
        $this->setUser(50);
        $reqSub = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $respSub = $controller->handle($reqSub, 'movies');
        $this->assertEquals(403, $this->getResponseStatus($respSub));

        $respSubMy = $controller->handle($reqSub, 'my-submissions');
        $this->assertEquals(403, $this->getResponseStatus($respSubMy));
    }
}
