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
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Integrations\FavoritePayAdapter;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Navigation\MultimediaSidebarTitle;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use PHPUnit\Framework\TestCase;

/**
 * Authoritative unit tests enforcing the LOCKED Favorite Multimedia Access Matrix (Plugin v1.0.7 / Theme v1.0.2).
 */
class FavoriteMultimediaLockedAccessMatrixTest extends TestCase
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

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_locked_matrix_' . uniqid() . '.sqlite';
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
        $_SESSION['_token'] = 'matrix_csrf_test_token';

        unset(
            $GLOBALS['_test_favorite_digital_available'],
            $GLOBALS['_test_favorite_digital_active_membership'],
            $GLOBALS['_test_favorite_digital_membership_data'],
            $GLOBALS['_test_favorite_digital_entitled_users'],
            $GLOBALS['_test_favorite_pay_available'],
            $GLOBALS['mock_current_user']
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['mock_current_user']);
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

        $pdo->exec("INSERT INTO roles (id, name, slug) VALUES 
            (1, 'Super Admin', 'super-admin'),
            (2, 'Administrator', 'admin'),
            (3, 'Editor', 'editor'),
            (4, 'Moderator', 'moderator'),
            (5, 'Author', 'author'),
            (6, 'Subscriber', 'subscriber'),
            (7, 'Contributor', 'contributor')");

        $now = date('Y-m-d H:i:s');
        $users = [
            [1, 'superadmin', 'super@example.com', 'active', 1, 'super-admin'],
            [2, 'adminuser',  'admin@example.com', 'active', 2, 'admin'],
            [3, 'editoruser', 'editor@example.com', 'active', 3, 'editor'],
            [4, 'moduser',    'mod@example.com',   'active', 4, 'moderator'],
            [5, 'authoruser', 'author@example.com', 'active', 5, 'author'],
            [6, 'subuser',    'sub@example.com',   'active', 6, 'subscriber'],
            [7, 'contribuser','contrib@example.com','active', 7, 'contributor'],
            [8, 'suspendeduser','susp@example.com','suspended', 6, 'subscriber'],
            [9, 'banneduser',  'banned@example.com','banned', 6, 'subscriber'],
        ];

        foreach ($users as $u) {
            $pdo->exec("INSERT INTO users (id, username, email, status, role, created_at, updated_at) 
                        VALUES ({$u[0]}, '{$u[1]}', '{$u[2]}', '{$u[3]}', '{$u[5]}', '{$now}', '{$now}')");
            $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES ({$u[0]}, {$u[4]})");
        }
    }

    private function getUser(int $id): User
    {
        return User::find($id);
    }

    private function setCurrentUser(?User $user): void
    {
        $GLOBALS['mock_current_user'] = $user;
        if ($user) {
            $_SESSION['user_id'] = (int)$user->id;
            $_SESSION['auth_user_id'] = (int)$user->id;
        } else {
            unset($_SESSION['user_id'], $_SESSION['auth_user_id']);
        }
    }

    private function createRequest(string $uri, string $method = 'GET', array $post = []): Request
    {
        $get = [];
        $parsed = parse_url($uri);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $get);
        }
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $uri,
        ];
        return new Request($get, $post, $server);
    }

    // =========================================================================
    // 1. SUSPENDED USER IDENTIFICATION
    // =========================================================================

    public function test01_SuspendedUserIdentification(): void
    {
        $activeSub = $this->getUser(6);
        $suspendedUser = $this->getUser(8);
        $bannedUser = $this->getUser(9);

        $this->assertFalse(MultimediaPermission::isSuspendedUser($activeSub));
        $this->assertTrue(MultimediaPermission::isSuspendedUser($suspendedUser));
        $this->assertTrue(MultimediaPermission::isSuspendedUser($bannedUser));
        $this->assertFalse(MultimediaPermission::isSuspendedUser(null));
    }

    // =========================================================================
    // 2. PERMISSION MATRIX ENFORCEMENT (MultimediaPermission::can)
    // =========================================================================

    public function test02_SuperAdminAndAdminPermissions(): void
    {
        $superAdmin = $this->getUser(1);
        $admin = $this->getUser(2);

        foreach ([$superAdmin, $admin] as $u) {
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::VIEW, $u));
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::CREATE, $u));
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::EDIT, $u));
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::DELETE, $u));
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::PUBLISH, $u));
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::MODERATE, $u));
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $u));
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::MANAGE_ACCESS, $u));
            $this->assertTrue(MultimediaPermission::can(MultimediaPermission::VIEW_ANALYTICS, $u));
        }
    }

    public function test03_EditorPermissions(): void
    {
        $editor = $this->getUser(3);

        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::VIEW, $editor));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::CREATE, $editor));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::EDIT, $editor));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::EDIT_OWN, $editor));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::DELETE_OWN, $editor));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::DELETE, $editor), 'Editor CANNOT delete others content');
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::PUBLISH, $editor));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::MODERATE, $editor));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $editor), 'Editor CANNOT manage settings');
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::MANAGE_ACCESS, $editor), 'Editor has restricted access rules');
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::VIEW_ANALYTICS, $editor));
    }

    public function test04_ModeratorPermissions(): void
    {
        $mod = $this->getUser(4);

        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::VIEW, $mod));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::CREATE, $mod));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::EDIT, $mod));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::EDIT_OWN, $mod));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::DELETE_OWN, $mod));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::DELETE, $mod), 'Moderator CANNOT delete others content');
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::PUBLISH, $mod));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::MODERATE, $mod));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $mod), 'Moderator CANNOT manage settings');
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MANAGE_ACCESS, $mod), 'Moderator CANNOT manage access rules');
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::VIEW_ANALYTICS, $mod));
    }

    public function test05_AuthorPermissions(): void
    {
        $author = $this->getUser(5);

        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::VIEW, $author), 'Author cannot view full catalog admin');
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::CREATE, $author));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::EDIT, $author), 'Author cannot edit other users content');
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::EDIT_OWN, $author));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::DELETE_OWN, $author));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::DELETE, $author));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::PUBLISH, $author));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MODERATE, $author));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $author));
        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MANAGE_ACCESS, $author));
        $this->assertTrue(MultimediaPermission::can(MultimediaPermission::VIEW_ANALYTICS, $author), 'Author can view own analytics');
    }

    public function test06_SubscriberAndSuspendedAndContributorFailClosed(): void
    {
        $subscriber = $this->getUser(6);
        $contributor = $this->getUser(7);
        $suspended = $this->getUser(8);

        foreach ([$subscriber, $contributor, $suspended] as $u) {
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::VIEW, $u));
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::CREATE, $u));
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::EDIT, $u));
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::DELETE, $u));
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::PUBLISH, $u));
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MODERATE, $u));
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $u));
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::MANAGE_ACCESS, $u));
            $this->assertFalse(MultimediaPermission::can(MultimediaPermission::VIEW_ANALYTICS, $u));
            $this->assertFalse(MultimediaPermission::canUserSubmit(null, $u));
        }
    }

    // =========================================================================
    // 3. DELETE CONTENT PERMISSIONS (canDeleteContent)
    // =========================================================================

    public function test07_DeleteOtherUsersContentStrictlySuperAdminAndAdminOnly(): void
    {
        $owner = $this->getUser(5); // Author
        $movie = new Movie([
            'id'      => 10,
            'user_id' => $owner->id,
            'title'   => 'Author Movie',
            'status'  => 'published',
        ]);

        $superAdmin = $this->getUser(1);
        $admin = $this->getUser(2);
        $editor = $this->getUser(3);
        $mod = $this->getUser(4);
        $author = $this->getUser(5);
        $sub = $this->getUser(6);
        $suspended = $this->getUser(8);

        // Super Admin & Admin = YES
        $this->assertTrue(MultimediaPermission::canDeleteContent($movie, $superAdmin));
        $this->assertTrue(MultimediaPermission::canDeleteContent($movie, $admin));

        // Editor = NO
        $this->assertFalse(MultimediaPermission::canDeleteContent($movie, $editor));

        // Moderator = NO
        $this->assertFalse(MultimediaPermission::canDeleteContent($movie, $mod));

        // Other Author = NO
        $otherAuthor = new User(['id' => 99, 'role' => 'author']);
        $this->assertFalse(MultimediaPermission::canDeleteContent($movie, $otherAuthor));

        // Subscriber = NO
        $this->assertFalse(MultimediaPermission::canDeleteContent($movie, $sub));

        // Suspended = NO
        $this->assertFalse(MultimediaPermission::canDeleteContent($movie, $suspended));
    }

    public function test08_DeletePublishedOwnContent(): void
    {
        $superAdmin = $this->getUser(1);
        $admin = $this->getUser(2);
        $editor = $this->getUser(3);
        $mod = $this->getUser(4);
        $author = $this->getUser(5);
        $sub = $this->getUser(6);
        $suspended = $this->getUser(8);

        // Super Admin published own: YES
        $m1 = new Movie(['id' => 1, 'user_id' => $superAdmin->id, 'status' => 'published']);
        $this->assertTrue(MultimediaPermission::canDeleteContent($m1, $superAdmin));

        // Admin published own: YES
        $m2 = new Movie(['id' => 2, 'user_id' => $admin->id, 'status' => 'published']);
        $this->assertTrue(MultimediaPermission::canDeleteContent($m2, $admin));

        // Editor published own: YES
        $m3 = new Movie(['id' => 3, 'user_id' => $editor->id, 'status' => 'published']);
        $this->assertTrue(MultimediaPermission::canDeleteContent($m3, $editor));

        // Moderator published own: YES
        $m4 = new Movie(['id' => 4, 'user_id' => $mod->id, 'status' => 'published']);
        $this->assertTrue(MultimediaPermission::canDeleteContent($m4, $mod));

        // Author published own: NO (Locked matrix: Author = No for Delete Published Own Content)
        $m5 = new Movie(['id' => 5, 'user_id' => $author->id, 'status' => 'published']);
        $this->assertFalse(MultimediaPermission::canDeleteContent($m5, $author));

        // Subscriber published own: NO
        $m6 = new Movie(['id' => 6, 'user_id' => $sub->id, 'status' => 'published']);
        $this->assertFalse(MultimediaPermission::canDeleteContent($m6, $sub));

        // Suspended published own: NO
        $m7 = new Movie(['id' => 7, 'user_id' => $suspended->id, 'status' => 'published']);
        $this->assertFalse(MultimediaPermission::canDeleteContent($m7, $suspended));
    }

    public function test09_DeleteOwnDraftAndPending(): void
    {
        $superAdmin = $this->getUser(1);
        $admin = $this->getUser(2);
        $editor = $this->getUser(3);
        $mod = $this->getUser(4);
        $author = $this->getUser(5);
        $sub = $this->getUser(6);
        $suspended = $this->getUser(8);

        foreach (['draft', 'pending'] as $st) {
            $mAuthor = new Movie(['id' => 11, 'user_id' => $author->id, 'status' => $st]);
            $this->assertTrue(MultimediaPermission::canDeleteContent($mAuthor, $author), "Author CAN delete own {$st}");

            $mEditor = new Movie(['id' => 12, 'user_id' => $editor->id, 'status' => $st]);
            $this->assertTrue(MultimediaPermission::canDeleteContent($mEditor, $editor), "Editor CAN delete own {$st}");

            $mMod = new Movie(['id' => 13, 'user_id' => $mod->id, 'status' => $st]);
            $this->assertTrue(MultimediaPermission::canDeleteContent($mMod, $mod), "Mod CAN delete own {$st}");

            $mSub = new Movie(['id' => 14, 'user_id' => $sub->id, 'status' => $st]);
            $this->assertFalse(MultimediaPermission::canDeleteContent($mSub, $sub), "Sub CANNOT delete own {$st}");

            $mSusp = new Movie(['id' => 15, 'user_id' => $suspended->id, 'status' => $st]);
            $this->assertFalse(MultimediaPermission::canDeleteContent($mSusp, $suspended), "Suspended CANNOT delete own {$st}");
        }
    }

    // =========================================================================
    // 4. PLAYBACK & STREAMING ACCESS (MultimediaAccessService)
    // =========================================================================

    public function test10_PlayPublicContentAllowedForEveryoneIncludingSuspended(): void
    {
        $movie = Movie::create([
            'title'       => 'Public Movie',
            'slug'        => 'public-movie',
            'access_mode' => 'public',
            'status'      => 'published',
            'user_id'     => 1,
        ]);

        $roles = [
            $this->getUser(1), // Super Admin
            $this->getUser(2), // Admin
            $this->getUser(3), // Editor
            $this->getUser(4), // Moderator
            $this->getUser(5), // Author
            $this->getUser(6), // Subscriber
            $this->getUser(8), // Suspended User
            null,              // Guest
        ];

        foreach ($roles as $u) {
            $this->assertEquals(
                MultimediaAccessService::ALLOW,
                MultimediaAccessService::checkAccess($u, 'movie', $movie),
                'Public content MUST be playable by all users including suspended user and guests'
            );
        }
    }

    public function test11_PlayLoginContentRequiresLoginAndBlocksSuspended(): void
    {
        $movie = Movie::create([
            'title'       => 'Login Movie',
            'slug'        => 'login-movie',
            'access_mode' => 'login',
            'status'      => 'published',
            'user_id'     => 1,
        ]);

        // Guest: LOGIN_REQUIRED
        $this->assertEquals(
            MultimediaAccessService::LOGIN_REQUIRED,
            MultimediaAccessService::checkAccess(null, 'movie', $movie)
        );

        // Authenticated non-suspended: ALLOW
        foreach ([1, 2, 3, 4, 5, 6] as $uid) {
            $this->assertEquals(
                MultimediaAccessService::ALLOW,
                MultimediaAccessService::checkAccess($this->getUser($uid), 'movie', $movie)
            );
        }

        // Suspended User: FORBIDDEN
        $this->assertEquals(
            MultimediaAccessService::FORBIDDEN,
            MultimediaAccessService::checkAccess($this->getUser(8), 'movie', $movie)
        );
    }

    public function test12_PlayPremiumContentRoleAloneNeverGrantsAccess(): void
    {
        $movie = Movie::create([
            'title'       => 'Premium Movie',
            'slug'        => 'premium-movie',
            'access_mode' => 'premium',
            'status'      => 'published',
            'user_id'     => 1,
        ]);

        $GLOBALS['_test_favorite_digital_available'] = true;

        // Without entitlement: Super Admin and Admin MUST be denied (PREMIUM_REQUIRED)
        $superAdmin = $this->getUser(1);
        $admin = $this->getUser(2);
        $sub = $this->getUser(6);

        $this->assertEquals(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($superAdmin, 'movie', $movie),
            'Super Admin WITHOUT entitlement MUST NOT bypass Premium playback'
        );
        $this->assertEquals(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($admin, 'movie', $movie),
            'Admin WITHOUT entitlement MUST NOT bypass Premium playback'
        );
        $this->assertEquals(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($sub, 'movie', $movie)
        );

        // Grant entitlement via Favorite Digital mock
        $GLOBALS['_test_favorite_digital_entitled_users'] = [
            1 => true, // Super Admin entitled
            2 => true, // Admin entitled
            6 => true, // Subscriber entitled
            8 => true, // Suspended user entitled
        ];

        // With entitlement: Super Admin, Admin, Subscriber ALLOW
        $this->assertEquals(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess($superAdmin, 'movie', $movie));
        $this->assertEquals(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess($admin, 'movie', $movie));
        $this->assertEquals(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess($sub, 'movie', $movie));

        // Suspended user: ALWAYS FORBIDDEN even if entitled!
        $suspended = $this->getUser(8);
        $this->assertEquals(
            MultimediaAccessService::FORBIDDEN,
            MultimediaAccessService::checkAccess($suspended, 'movie', $movie),
            'Suspended user MUST be FORBIDDEN even if active entitlement exists'
        );
    }

    public function test13_DownloadMediaDeniedForSuspended(): void
    {
        $movie = Movie::create([
            'title'           => 'Downloadable Movie',
            'slug'            => 'downloadable-movie',
            'access_mode'     => 'public',
            'download_policy' => 'allow',
            'download_url'    => 'https://example.com/movie.mp4',
            'status'          => 'published',
            'user_id'         => 1,
        ]);

        $suspended = $this->getUser(8);
        $check = MultimediaAccessService::checkDownloadPermission($suspended, 'movie', $movie);
        $this->assertFalse($check['allowed']);
        $this->assertEquals(MultimediaAccessService::FORBIDDEN, $check['access_state']);
    }

    // =========================================================================
    // 5. ENGAGEMENT APIS DENIED FOR SUSPENDED USERS (MediaPlaybackController)
    // =========================================================================

    public function test14_SuspendedUserDeniedOnEngagementApis(): void
    {
        $suspended = $this->getUser(8);
        $this->setCurrentUser($suspended);

        $ctrl = new MediaPlaybackController($this->app);
        $req = $this->createRequest('/multimedia/api/rate', 'POST', [
            'content_type' => 'movie',
            'content_id'   => 1,
            'rating'       => 5,
            '_token'       => 'matrix_csrf_test_token',
        ]);

        $res = $ctrl->apiRate($req);
        $this->assertEquals(403, $res->getStatusCode());

        $favReq = $this->createRequest('/multimedia/api/favorite', 'POST', [
            'content_type' => 'movie',
            'content_id'   => 1,
            '_token'       => 'matrix_csrf_test_token',
        ]);
        $resFav = $ctrl->apiToggleFavorite($favReq);
        $this->assertEquals(403, $resFav->getStatusCode());

        $commentReq = $this->createRequest('/multimedia/api/comment', 'POST', [
            'content_type' => 'movie',
            'content_id'   => 1,
            'body'         => 'Nice movie',
            '_token'       => 'matrix_csrf_test_token',
        ]);
        $resComment = $ctrl->apiSaveComment($commentReq);
        $this->assertEquals(403, $resComment->getStatusCode());

        $progReq = $this->createRequest('/multimedia/api/progress', 'POST', [
            'content_type' => 'movie',
            'content_id'   => 1,
            'position'     => 100,
            'duration'     => 1000,
            '_token'       => 'matrix_csrf_test_token',
        ]);
        $resProg = $ctrl->apiSaveProgress($progReq);
        $this->assertEquals(403, $resProg->getStatusCode());
    }

    // =========================================================================
    // 6. ADMIN & CREATOR ROUTING (MultimediaAdminController::handle)
    // =========================================================================

    public function test15_AdminControllerAccessByRole(): void
    {
        $adminCtrl = new MultimediaAdminController($this->app);

        // 1. Suspended user: 403 immediately
        $this->setCurrentUser($this->getUser(8));
        $res = $adminCtrl->handle($this->createRequest('/admin/page/multimedia'), 'dashboard');
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(403, $res->getStatusCode());

        // 2. Subscriber: 403 immediately
        $this->setCurrentUser($this->getUser(6));
        $resSub = $adminCtrl->handle($this->createRequest('/admin/page/multimedia'), 'dashboard');
        $this->assertInstanceOf(Response::class, $resSub);
        $this->assertEquals(403, $resSub->getStatusCode());

        // 3. Contributor: 403 immediately (fail-closed)
        $this->setCurrentUser($this->getUser(7));
        $resContrib = $adminCtrl->handle($this->createRequest('/admin/page/multimedia'), 'dashboard');
        $this->assertInstanceOf(Response::class, $resContrib);
        $this->assertEquals(403, $resContrib->getStatusCode());

        // 4. Moderator: denied on genres, artists, access, settings
        $this->setCurrentUser($this->getUser(4));
        $resModGenres = $adminCtrl->handle($this->createRequest('/admin/page/multimedia-genres'), 'genres');
        $this->assertEquals(403, $resModGenres->getStatusCode(), 'Moderator CANNOT manage genres');

        $resModArtists = $adminCtrl->handle($this->createRequest('/admin/page/multimedia-artists'), 'artists');
        $this->assertEquals(403, $resModArtists->getStatusCode(), 'Moderator CANNOT manage artists');

        $resModAccess = $adminCtrl->handle($this->createRequest('/admin/page/multimedia-access'), 'access');
        $this->assertEquals(403, $resModAccess->getStatusCode(), 'Moderator CANNOT manage access rules');

        $resModSettings = $adminCtrl->handle($this->createRequest('/admin/page/multimedia-settings'), 'settings');
        $this->assertEquals(403, $resModSettings->getStatusCode(), 'Moderator CANNOT manage settings');

        // 5. Editor: allowed on genres, artists, access; denied on settings
        $this->setCurrentUser($this->getUser(3));
        $resEdSettings = $adminCtrl->handle($this->createRequest('/admin/page/multimedia-settings'), 'settings');
        $this->assertEquals(403, $resEdSettings->getStatusCode(), 'Editor CANNOT manage settings');

        // 6. Author: redirected to my_submissions from dashboard
        $this->setCurrentUser($this->getUser(5));
        $resAuthDash = $adminCtrl->handle($this->createRequest('/admin/page/multimedia'), 'dashboard');
        // Author either gets mySubmissions view (string) or redirect
        $this->assertTrue(is_string($resAuthDash) || ($resAuthDash instanceof Response && $resAuthDash->getStatusCode() !== 403));
    }

    // =========================================================================
    // 7. SIDEBAR MENU ENFORCEMENT (FavoriteMultimediaPlugin::enableSidebarTitle)
    // =========================================================================

    public function test16_SidebarMenuVisibility(): void
    {
        $ref = new \ReflectionProperty(AdminMenu::class, 'menus');

        // 1. Suspended User: no menu
        $this->setCurrentUser($this->getUser(8));
        FavoriteMultimediaPlugin::reset();
        FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = $ref->getValue();
        $this->assertArrayNotHasKey('multimedia', $menus, 'Suspended user must not see multimedia in sidebar');

        // 2. Subscriber: no menu
        $this->setCurrentUser($this->getUser(6));
        FavoriteMultimediaPlugin::reset();
        FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = $ref->getValue();
        $this->assertArrayNotHasKey('multimedia', $menus, 'Subscriber must not see multimedia in admin sidebar');

        // 3. Contributor: no menu
        $this->setCurrentUser($this->getUser(7));
        FavoriteMultimediaPlugin::reset();
        FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = $ref->getValue();
        $this->assertArrayNotHasKey('multimedia', $menus, 'Contributor must not see multimedia in admin sidebar');

        // 4. Author: creator sidebar
        $this->setCurrentUser($this->getUser(5));
        // Reset and re-register menu structure
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = $ref->getValue();
        $this->assertArrayHasKey('multimedia', $menus);
        $this->assertInstanceOf(MultimediaSidebarTitle::class, $menus['multimedia']['title']);
        $this->assertEquals('My Multimedia', $menus['multimedia']['title']->childText);

        // 5. Moderator: filtered submenus (no genres, artists, access, settings)
        $this->setCurrentUser($this->getUser(4));
        FavoriteMultimediaPlugin::reset();
        FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = $ref->getValue();
        $this->assertArrayHasKey('multimedia', $menus);
        $this->assertEquals('Dashboard', $menus['multimedia']['title']->childText);
        $submenus = $menus['multimedia']['submenus'] ?? [];
        $this->assertArrayNotHasKey('multimedia-genres', $submenus);
        $this->assertArrayNotHasKey('multimedia-artists', $submenus);
        $this->assertArrayNotHasKey('multimedia-access', $submenus);
        $this->assertArrayNotHasKey('multimedia-settings', $submenus);
        $this->assertArrayHasKey('multimedia-moderation', $submenus);

        // 6. Editor: genres & artists present, settings filtered out
        $this->setCurrentUser($this->getUser(3));
        FavoriteMultimediaPlugin::reset();
        FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $menus = $ref->getValue();
        $this->assertArrayHasKey('multimedia', $menus);
        $submenus = $menus['multimedia']['submenus'] ?? [];
        $this->assertArrayHasKey('multimedia-genres', $submenus);
        $this->assertArrayHasKey('multimedia-artists', $submenus);
        $this->assertArrayHasKey('multimedia-access', $submenus);
        $this->assertArrayNotHasKey('multimedia-settings', $submenus);
    }

    // =========================================================================
    // 8. FRONTEND CONTROLLER SUSPENDED CHECKS (MultimediaFrontendController)
    // =========================================================================

    public function test17_FrontendViewsDenySuspendedUser(): void
    {
        $suspended = $this->getUser(8);
        $this->setCurrentUser($suspended);

        $frontendCtrl = new MultimediaFrontendController($this->app);

        // Library: 403
        $resLib = $frontendCtrl->library($this->createRequest('/multimedia/library'));
        $this->assertEquals(403, $resLib->getStatusCode());

        // History: 403
        $resHist = $frontendCtrl->history($this->createRequest('/multimedia/history'));
        $this->assertEquals(403, $resHist->getStatusCode());

        // My List: 403
        $resMyList = $frontendCtrl->myList($this->createRequest('/multimedia/my-list'));
        $this->assertEquals(403, $resMyList->getStatusCode());

        // Notifications: 403
        $resNotif = $frontendCtrl->notifications($this->createRequest('/multimedia/notifications'));
        $this->assertEquals(403, $resNotif->getStatusCode());

        // Following: 403
        $resFollow = $frontendCtrl->following($this->createRequest('/multimedia/following'));
        $this->assertEquals(403, $resFollow->getStatusCode());

        // Membership purchase: 403
        $resMship = $frontendCtrl->membership($this->createRequest('/multimedia/membership'));
        $this->assertEquals(403, $resMship->getStatusCode());
    }

    // =========================================================================
    // 9. PRE-RELEASE EDGE CASES: EDITOR / MODERATOR DELETION & AUTHOR LIFECYCLE
    // =========================================================================

    public function test18_EditorAndModeratorCanDeleteOwnDraftPendingPublished_CannotDeleteOthers(): void
    {
        $editor = $this->getUser(3);
        $mod    = $this->getUser(4);
        $other  = $this->getUser(5); // Author

        // 1. Editor can delete OWN draft, pending, published
        foreach (['draft', 'pending', 'published'] as $st) {
            $mEditor = new Movie(['id' => 101, 'user_id' => $editor->id, 'status' => $st]);
            $this->assertTrue(
                MultimediaPermission::canDeleteContent($mEditor, $editor),
                "Editor MUST be able to delete own {$st} content"
            );
        }

        // 1b. Editor CANNOT delete another user's content (any status)
        foreach (['draft', 'pending', 'published'] as $st) {
            $mOther = new Movie(['id' => 102, 'user_id' => $other->id, 'status' => $st]);
            $this->assertFalse(
                MultimediaPermission::canDeleteContent($mOther, $editor),
                "Editor MUST NOT be able to delete another user's {$st} content"
            );
        }

        // 2. Moderator can delete OWN draft, pending, published
        foreach (['draft', 'pending', 'published'] as $st) {
            $mMod = new Movie(['id' => 103, 'user_id' => $mod->id, 'status' => $st]);
            $this->assertTrue(
                MultimediaPermission::canDeleteContent($mMod, $mod),
                "Moderator MUST be able to delete own {$st} content"
            );
        }

        // 2b. Moderator CANNOT delete another user's content (any status)
        foreach (['draft', 'pending', 'published'] as $st) {
            $mOther = new Movie(['id' => 104, 'user_id' => $other->id, 'status' => $st]);
            $this->assertFalse(
                MultimediaPermission::canDeleteContent($mOther, $mod),
                "Moderator MUST NOT be able to delete another user's {$st} content"
            );
        }
    }

    public function test19_AuthorEditLifecycleExhaustive(): void
    {
        $author = $this->getUser(5);
        $this->setCurrentUser($author);
        $adminCtrl = new MultimediaAdminController($this->app);

        // Case 1: published + edit => pending
        $mPublished = Movie::create([
            'user_id'     => $author->id,
            'title'       => 'Author Published Movie',
            'slug'        => 'author-published-movie',
            'status'      => 'published',
            'access_mode' => 'public',
        ]);
        $reqEditPub = $this->createRequest("/admin/page/multimedia-movies?edit={$mPublished->id}", 'POST', [
            'action'       => 'edit',
            'id'           => (int)$mPublished->id,
            'title'        => 'Author Published Movie (Edited)',
            'slug'         => 'author-published-movie-edited',
            '_token'       => 'matrix_csrf_test_token',
        ]);
        $adminCtrl->handle($reqEditPub, 'movies');
        $mPubReloaded = Movie::find((int)$mPublished->id);
        $this->assertSame('pending', $mPubReloaded->status, 'Author edit of published content MUST move to pending');

        // Case 2: draft + edit => remains draft
        $mDraft = Movie::create([
            'user_id'     => $author->id,
            'title'       => 'Author Draft Movie',
            'slug'        => 'author-draft-movie',
            'status'      => 'draft',
            'access_mode' => 'public',
        ]);
        $reqEditDraft = $this->createRequest("/admin/page/multimedia-movies?edit={$mDraft->id}", 'POST', [
            'action'        => 'edit',
            'id'            => (int)$mDraft->id,
            'title'         => 'Author Draft Movie (Edited)',
            'slug'          => 'author-draft-movie-edited',
            'submit_action' => 'draft',
            '_token'        => 'matrix_csrf_test_token',
        ]);
        $adminCtrl->handle($reqEditDraft, 'movies');
        $mDraftReloaded = Movie::find((int)$mDraft->id);
        $this->assertSame('draft', $mDraftReloaded->status, 'Author edit of draft content MUST remain draft');

        // Case 3: pending + edit => remains pending
        $mPending = Movie::create([
            'user_id'     => $author->id,
            'title'       => 'Author Pending Movie',
            'slug'        => 'author-pending-movie',
            'status'      => 'pending',
            'access_mode' => 'public',
        ]);
        $reqEditPending = $this->createRequest("/admin/page/multimedia-movies?edit={$mPending->id}", 'POST', [
            'action'       => 'edit',
            'id'           => (int)$mPending->id,
            'title'        => 'Author Pending Movie (Edited)',
            'slug'         => 'author-pending-movie-edited',
            '_token'       => 'matrix_csrf_test_token',
        ]);
        $adminCtrl->handle($reqEditPending, 'movies');
        $mPendingReloaded = Movie::find((int)$mPending->id);
        $this->assertSame('pending', $mPendingReloaded->status, 'Author edit of pending content MUST remain pending');

        // Case 4: rejected + edit => remains rejected
        $mRejected = Movie::create([
            'user_id'          => $author->id,
            'title'            => 'Author Rejected Movie',
            'slug'             => 'author-rejected-movie',
            'status'           => 'rejected',
            'rejection_reason' => 'Needs higher resolution poster',
            'access_mode'      => 'public',
        ]);
        $reqEditRejected = $this->createRequest("/admin/page/multimedia-movies?edit={$mRejected->id}", 'POST', [
            'action'       => 'edit',
            'id'           => (int)$mRejected->id,
            'title'        => 'Author Rejected Movie (Edited)',
            'slug'         => 'author-rejected-movie-edited',
            '_token'       => 'matrix_csrf_test_token',
        ]);
        $adminCtrl->handle($reqEditRejected, 'movies');
        $mRejectedReloaded = Movie::find((int)$mRejected->id);
        $this->assertSame('rejected', $mRejectedReloaded->status, 'Author edit of rejected content without explicit resubmit MUST remain rejected');

        // Case 5: rejected + explicit Resubmit => pending
        $reqResubmit = $this->createRequest('/admin/page/multimedia-my-submissions', 'POST', [
            'action'       => 'resubmit',
            'content_type' => 'movie',
            'id'           => (int)$mRejected->id,
            '_token'       => 'matrix_csrf_test_token',
        ]);
        $adminCtrl->handle($reqResubmit, 'my-submissions');
        $mResubmittedReloaded = Movie::find((int)$mRejected->id);
        $this->assertSame('pending', $mResubmittedReloaded->status, 'Rejected content with explicit resubmit MUST move to pending');
    }
}
