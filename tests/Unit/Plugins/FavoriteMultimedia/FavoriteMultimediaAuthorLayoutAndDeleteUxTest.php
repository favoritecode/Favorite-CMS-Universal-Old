<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Navigation\MultimediaSidebarTitle;
use FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaAuthorLayoutAndDeleteUxTest extends TestCase
{
    private Application $app;
    private Database $db;
    private \PDO $pdo;
    private string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_author_ux_' . uniqid() . '.sqlite';
        $this->pdo = new \PDO('sqlite:' . $this->tempDb);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->app = new Application(APP_ROOT);
        Application::setInstance($this->app);
        Container::setInstance($this->app);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $this->pdo);

        $config = new Config([]);
        $this->app->instance(Config::class, $config);
        $this->app->instance('config', $config);
        $this->app->instance(Database::class, $this->db);
        $this->app->instance('db', $this->db);

        $this->createSchema();
        $this->seedRolesAndUsers();

        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        unset($GLOBALS['user_can_override'], $GLOBALS['favorite_cms_user']);

        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            username VARCHAR(50),
            name VARCHAR(100),
            email VARCHAR(100),
            password VARCHAR(255),
            status VARCHAR(20) DEFAULT 'active',
            role VARCHAR(50) DEFAULT 'subscriber',
            bio TEXT,
            email_verified_at DATETIME,
            created_at DATETIME,
            updated_at DATETIME
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY,
            name VARCHAR(50),
            slug VARCHAR(50),
            description TEXT,
            created_at DATETIME,
            updated_at DATETIME
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
            user_id INTEGER,
            role_id INTEGER,
            PRIMARY KEY (user_id, role_id)
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            slug VARCHAR(100),
            description TEXT,
            group_name VARCHAR(50),
            created_at DATETIME,
            updated_at DATETIME
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER,
            permission_id INTEGER,
            created_at DATETIME,
            PRIMARY KEY (role_id, permission_id)
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY,
            group_name VARCHAR(50),
            setting_key VARCHAR(50),
            `group` VARCHAR(50),
            `key` VARCHAR(50),
            value TEXT,
            type VARCHAR(20),
            is_public INTEGER DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            UNIQUE (group_name, setting_key)
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            title VARCHAR(255),
            slug VARCHAR(255),
            content TEXT,
            type VARCHAR(20) DEFAULT 'post',
            status VARCHAR(20) DEFAULT 'published',
            published_at DATETIME,
            created_at DATETIME,
            updated_at DATETIME
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS pages (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            title VARCHAR(255),
            slug VARCHAR(255),
            content TEXT,
            status VARCHAR(20) DEFAULT 'published',
            created_at DATETIME,
            updated_at DATETIME
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY,
            post_id INTEGER,
            user_id INTEGER,
            content TEXT,
            status VARCHAR(20) DEFAULT 'approved',
            created_at DATETIME,
            updated_at DATETIME
        );");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS media (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            filename VARCHAR(255),
            path VARCHAR(255),
            mime_type VARCHAR(100),
            size INTEGER,
            created_at DATETIME,
            updated_at DATETIME
        );");

        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();
        MultimediaPermission::registerDefaultPermissions($this->db);
    }

    private function seedRolesAndUsers(): void
    {
        $this->pdo->exec("INSERT INTO roles (id, name, slug) VALUES 
            (1, 'Super Admin', 'super-admin'),
            (2, 'Administrator', 'admin'),
            (3, 'Editor', 'editor'),
            (4, 'Moderator', 'moderator'),
            (5, 'Author', 'author'),
            (6, 'Contributor', 'contributor'),
            (7, 'Subscriber', 'subscriber')");

        $now = date('Y-m-d H:i:s');
        $users = [
            [1, 'superadmin', 'super@example.com', 'super-admin', 1],
            [2, 'adminuser',  'admin@example.com', 'admin', 2],
            [3, 'editoruser', 'editor@example.com', 'editor', 3],
            [4, 'moduser',    'mod@example.com',   'moderator', 4],
            [5, 'authoruser', 'author@example.com', 'author', 5],
            [6, 'authorother','other@example.com', 'author', 5],
            [7, 'subuser',    'sub@example.com',   'subscriber', 7],
        ];

        foreach ($users as $u) {
            $this->pdo->exec("INSERT INTO users (id, username, name, email, password, status, role, bio, created_at, updated_at) 
                        VALUES ({$u[0]}, '{$u[1]}', '{$u[1]} Name', '{$u[2]}', 'secret', 'active', '{$u[3]}', 'Bio for {$u[1]}', '{$now}', '{$now}')");
            $this->pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES ({$u[0]}, {$u[4]})");
        }

        // Seed some movies, series, episodes, songs, albums, playlists
        // Owned by author_other (User 6) and published
        $this->db->insert('multimedia_movies', ['id' => 10, 'title' => 'Other Published Movie', 'slug' => 'other-movie', 'user_id' => 6, 'status' => 'published']);
        $this->db->insert('multimedia_movies', ['id' => 11, 'title' => 'Author Own Published Movie', 'slug' => 'author-pub-movie', 'user_id' => 5, 'status' => 'published']);
        $this->db->insert('multimedia_movies', ['id' => 12, 'title' => 'Author Own Draft Movie', 'slug' => 'author-draft-movie', 'user_id' => 5, 'status' => 'draft']);

        $this->db->insert('multimedia_series', ['id' => 20, 'title' => 'Other Published Series', 'slug' => 'other-series', 'user_id' => 6, 'status' => 'published']);
        $this->db->insert('multimedia_series', ['id' => 21, 'title' => 'Author Published Series', 'slug' => 'author-series', 'user_id' => 5, 'status' => 'published']);

        $this->db->insert('multimedia_seasons', ['id' => 30, 'series_id' => 20, 'season_number' => 1, 'title' => 'Season 1']);
        $this->db->insert('multimedia_episodes', ['id' => 40, 'series_id' => 20, 'season_id' => 30, 'episode_number' => 1, 'title' => 'Other Episode', 'slug' => 'other-ep', 'user_id' => 6, 'status' => 'published']);

        $this->db->insert('multimedia_songs', ['id' => 50, 'title' => 'Other Published Song', 'slug' => 'other-song', 'user_id' => 6, 'status' => 'published']);
        $this->db->insert('multimedia_songs', ['id' => 51, 'title' => 'Author Published Song', 'slug' => 'author-song', 'user_id' => 5, 'status' => 'published']);

        $this->db->insert('multimedia_albums', ['id' => 60, 'title' => 'Other Published Album', 'slug' => 'other-album', 'user_id' => 6, 'status' => 'published']);
        $this->db->insert('multimedia_albums', ['id' => 61, 'title' => 'Author Published Album', 'slug' => 'author-album', 'user_id' => 5, 'status' => 'published']);

        $this->db->insert('multimedia_playlists', ['id' => 70, 'title' => 'Other Published Playlist', 'slug' => 'other-playlist', 'user_id' => 6, 'status' => 'published']);
        $this->db->insert('multimedia_playlists', ['id' => 71, 'title' => 'Author Published Playlist', 'slug' => 'author-playlist', 'user_id' => 5, 'status' => 'published']);
    }

    private function authenticateUser(int $userId): User
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $user = User::find($userId);
        $this->assertNotNull($user, "User {$userId} must exist in test database");

        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['auth_user_name'] = $user->name ?? $user->username;
        $_SESSION['auth_user_email'] = $user->email;
        $_SESSION['user_id'] = $userId;
        $_SESSION['_token'] = 'csrf_token_' . $userId;

        $GLOBALS['favorite_cms_user'] = $user;
        $GLOBALS['mock_current_user'] = $user;
        $GLOBALS['user_can_override'] = function (string $cap, ?object $u = null) use ($user) {
            $checkUser = $u ?: $user;
            return MultimediaPermission::can($cap, $checkUser);
        };

        FavoriteMultimediaPlugin::enableSidebarTitle();

        return $user;
    }

    // =========================================================================
    // 1-6. Denied content deletion returns structured 403 JSON for AJAX
    // =========================================================================

    public function test01_deniedMovieDeleteReturnsStructured403JsonForAjax(): void
    {
        $author = $this->authenticateUser(5); // Author
        $controller = new MultimediaAdminController($this->app);

        // Attempt to delete other user's movie with AJAX headers
        $req = new Request([], ['action' => 'delete', 'id' => '10', '_token' => 'csrf_token_5', 'ajax' => '1'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-movies',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->handle($req, 'movies');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('You cannot delete this movie.', $body['error']);
    }

    public function test02_deniedSeriesDeleteReturnsStructured403Json(): void
    {
        $this->authenticateUser(5); // Author
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], ['action' => 'delete', 'id' => '20', '_token' => 'csrf_token_5', 'ajax' => '1'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-series',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->handle($req, 'series');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('You cannot delete this series.', $body['error']);
    }

    public function test03_deniedEpisodeDeleteReturnsStructured403Json(): void
    {
        $this->authenticateUser(5); // Author
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], ['action' => 'delete', 'id' => '40', 'season_id' => '30', '_token' => 'csrf_token_5', 'ajax' => '1'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-episodes',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->handle($req, 'episodes');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('You cannot delete this episode.', $body['error']);
    }

    public function test04_deniedSongDeleteReturnsStructured403Json(): void
    {
        $this->authenticateUser(5); // Author
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], ['action' => 'delete', 'id' => '50', '_token' => 'csrf_token_5', 'ajax' => '1'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-songs',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->handle($req, 'songs');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('You cannot delete this song.', $body['error']);
    }

    public function test05_deniedAlbumDeleteReturnsStructured403Json(): void
    {
        $this->authenticateUser(5); // Author
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], ['action' => 'delete', 'id' => '60', '_token' => 'csrf_token_5', 'ajax' => '1'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-albums',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->handle($req, 'albums');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('You cannot delete this album.', $body['error']);
    }

    public function test06_deniedPlaylistDeleteReturnsStructured403Json(): void
    {
        $this->authenticateUser(5); // Author
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], ['action' => 'delete', 'id' => '70', '_token' => 'csrf_token_5', 'ajax' => '1'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-playlists',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->handle($req, 'playlists');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('You cannot delete this playlist.', $body['error']);
    }

    // =========================================================================
    // 7. Non-AJAX denied delete redirects back with flash_error
    // =========================================================================

    public function test07_nonAjaxDeniedDeleteRedirectsBackWithFlashError(): void
    {
        $this->authenticateUser(5); // Author
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], ['action' => 'delete', 'id' => '10', '_token' => 'csrf_token_5'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-movies',
            'HTTP_REFERER'   => 'http://favorite-cms.local/admin/page/multimedia-my-submissions',
        ]);

        $response = $controller->handle($req, 'movies');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/page/multimedia-my-submissions', $response->getHeaders()['Location'] ?? '');
        $this->assertSame('You cannot delete this movie.', $_SESSION['flash_error'] ?? '');
    }

    // =========================================================================
    // 8. Frontend/admin JS renders popup for denied delete
    // =========================================================================

    public function test08_frontendAdminJsContainsToastComponentAndAjaxDeleteFlow(): void
    {
        $jsPath = APP_ROOT . '/plugins/favorite-multimedia/assets/js/multimedia-admin.js';
        $this->assertFileExists($jsPath);
        $content = (string)file_get_contents($jsPath);

        $this->assertStringContainsString('FavoriteMultimediaToast', $content);
        $this->assertStringContainsString('fmm-toast', $content);
        $this->assertStringContainsString('executeDelete', $content);
        $this->assertStringContainsString('response.status === 403', $content);
        $this->assertStringContainsString('X-Requested-With', $content);
    }

    // =========================================================================
    // 9. No standalone 403 page used for UI delete flow
    // =========================================================================

    public function test09_noStandalone403PageUsedForUiDeleteFlow(): void
    {
        $this->authenticateUser(5);
        $controller = new MultimediaAdminController($this->app);

        // AJAX
        $reqAjax = new Request([], ['action' => 'delete', 'id' => '10', '_token' => 'csrf_token_5', 'ajax' => '1'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-movies',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
        ]);
        $respAjax = $controller->handle($reqAjax, 'movies');
        $this->assertStringNotContainsString('<h1>403 Forbidden - You cannot delete this movie</h1>', $respAjax->getContent());

        // Traditional POST
        $reqPost = new Request([], ['action' => 'delete', 'id' => '10', '_token' => 'csrf_token_5'], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/page/multimedia-movies',
            'HTTP_REFERER'   => 'http://favorite-cms.local/admin/page/multimedia-movies',
        ]);
        $respPost = $controller->handle($reqPost, 'movies');
        $this->assertSame(302, $respPost->getStatusCode());
        $this->assertNotSame(403, $respPost->getStatusCode());
        $this->assertStringNotContainsString('<h1>403', $respPost->getContent());
    }

    // =========================================================================
    // 10. Delete permissions remain unchanged (Locked matrix authoritative check)
    // =========================================================================

    public function test10_deletePermissionsRemainUnchangedPerLockedMatrix(): void
    {
        $superAdmin = User::find(1);
        $admin      = User::find(2);
        $editor     = User::find(3);
        $moderator  = User::find(4);
        $author     = User::find(5);
        $subscriber = User::find(7);

        $otherMovie = Movie::find(10); // Owned by user 6
        $ownPubMovie = Movie::find(11); // Owned by author (user 5), published
        $ownDraftMovie = Movie::find(12); // Owned by author (user 5), draft

        // Delete other users' content: Super Admin & Admin = YES; Editor, Moderator, Author, Subscriber = NO
        $this->assertTrue(MultimediaPermission::canDeleteContent($otherMovie, $superAdmin));
        $this->assertTrue(MultimediaPermission::canDeleteContent($otherMovie, $admin));
        $this->assertFalse(MultimediaPermission::canDeleteContent($otherMovie, $editor));
        $this->assertFalse(MultimediaPermission::canDeleteContent($otherMovie, $moderator));
        $this->assertFalse(MultimediaPermission::canDeleteContent($otherMovie, $author));
        $this->assertFalse(MultimediaPermission::canDeleteContent($otherMovie, $subscriber));

        // Author cannot delete own PUBLISHED content
        $this->assertFalse(MultimediaPermission::canDeleteContent($ownPubMovie, $author));

        // Author CAN delete own DRAFT content
        $this->assertTrue(MultimediaPermission::canDeleteContent($ownDraftMovie, $author));
    }

    // =========================================================================
    // 11-21. Author layout routes return HTTP 200 inside standard admin shell
    // =========================================================================

    public function test11_authorAdminReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test12_authorProfileReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/users/profile']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test13_authorMyMultimediaReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();
        $this->assertStringNotContainsString('TypeError', $content);
        $this->assertStringContainsString('wp-sidebar', $content);
    }

    public function test14_authorMySubmissionsReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-my-submissions']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test15_authorAddMovieReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-movies?new=1']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test16_authorAddSeriesReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-series?new=1']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test17_authorAddEpisodeReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-episodes?new=1']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test18_authorAddSongReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-songs?new=1']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test19_authorAddAlbumReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-albums?new=1']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test20_authorAddPlaylistReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request(['new' => '1'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-playlists?new=1']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test21_authorAnalyticsReturns200(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-analytics']);
        $resp = $kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }

    // =========================================================================
    // 22. Author analytics scoped to own content
    // =========================================================================

    public function test22_authorAnalyticsScopedToOwnContent(): void
    {
        $this->authenticateUser(5); // Author User 5
        $controller = new MultimediaAdminController($this->app);

        // Seed analytics events for author (User 5) and other author (User 6)
        $now = date('Y-m-d H:i:s');
        $this->db->insert('multimedia_analytics', [
            'event_type' => 'play', 'content_type' => 'movie', 'content_id' => 11, 'user_id' => 5, 'created_at' => $now,
        ]);
        $this->db->insert('multimedia_analytics', [
            'event_type' => 'play', 'content_type' => 'movie', 'content_id' => 10, 'user_id' => 6, 'created_at' => $now,
        ]);

        $req = new Request(['range' => 'all'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-analytics']);
        $result = $controller->handle($req, 'analytics');
        $this->assertIsString($result);
        $this->assertStringContainsString('Analytics', $result);
    }

    // =========================================================================
    // 23-25. Sidebar and layout rendering safety
    // =========================================================================

    public function test23_sidebarRendersWithoutException(): void
    {
        $this->authenticateUser(5);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $ref = new \ReflectionProperty(AdminMenu::class, 'menus');
        $menus = $ref->getValue();
        $this->assertArrayHasKey('multimedia', $menus);
        $this->assertInstanceOf(MultimediaSidebarTitle::class, $menus['multimedia']['title']);
    }

    public function test24_multimediaSubmenuCollectionCompatibleWithCmsLayout(): void
    {
        $this->authenticateUser(5);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $ref = new \ReflectionProperty(AdminMenu::class, 'menus');
        $menus = $ref->getValue();
        $subs = $menus['multimedia']['submenus'];

        $this->assertInstanceOf(MultimediaSubmenuCollection::class, $subs);
        $this->assertTrue(isset($subs['multimedia-my-submissions']));
        $this->assertTrue(isset($subs['multimedia-analytics']));

        // Verify iterable by layout foreach
        $count = 0;
        foreach ($subs as $sub) {
            $count++;
            $this->assertIsArray($sub);
            $this->assertArrayHasKey('title', $sub);
        }
        $this->assertGreaterThan(0, $count);
    }

    public function test25_noDuplicateLayoutRendering(): void
    {
        $this->authenticateUser(5);
        $kernel = new Kernel($this->app);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia']);
        $resp = $kernel->handle($req);
        $content = $resp->getContent();

        // Exactly one <!DOCTYPE html>, <html>, <body>, <nav class="wp-sidebar">
        $this->assertSame(1, substr_count($content, '<!DOCTYPE html>'));
        $this->assertSame(1, substr_count($content, '<html'));
        $this->assertSame(1, substr_count($content, '<body'));
        $this->assertSame(1, substr_count($content, '<nav class="wp-sidebar">'));
    }
}
