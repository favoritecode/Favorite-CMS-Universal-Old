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
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Integrations\FavoritePayAdapter;
use FavoriteCMS\Multimedia\Navigation\MultimediaSidebarTitle;
use FavoriteCMS\Multimedia\Navigation\MultimediaSubmenuCollection;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaMembershipAndMenuTest extends TestCase
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

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_mship_' . uniqid() . '.sqlite';
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

        // Clear mock injections
        unset(
            $GLOBALS['_test_favorite_digital_available'],
            $GLOBALS['_test_favorite_digital_active_membership'],
            $GLOBALS['_test_favorite_digital_membership_data'],
            $GLOBALS['_test_favorite_digital_entitled_users'],
            $GLOBALS['_test_favorite_pay_available']
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset(
            $GLOBALS['_test_favorite_digital_available'],
            $GLOBALS['_test_favorite_digital_active_membership'],
            $GLOBALS['_test_favorite_digital_membership_data'],
            $GLOBALS['_test_favorite_digital_entitled_users'],
            $GLOBALS['_test_favorite_pay_available']
        );
        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();

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

    // =========================================================================
    // 1. Creator Menu & Permissions Tests
    // =========================================================================

    public function testSubscriberSubmissionsOffHidesCreatorMenuFromSidebar(): void
    {
        Setting::set('multimedia', 'allow_subscriber_submissions', 'no');
        $this->setUser(50);

        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $menus = AdminMenu::getMenus();
        $this->assertArrayNotHasKey('multimedia', $menus, 'When subscriber submissions are OFF, multimedia menu must be hidden');
    }

    public function testSubscriberSubmissionsDefaultIsOnAndShowsCreatorMenuWithAllSubmenus(): void
    {
        // In locked matrix, subscriber has NO admin sidebar menu
        Setting::set('multimedia', 'allow_subscriber_submissions', 'yes');
        $this->setUser(50);

        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $menus = AdminMenu::getMenus();
        $this->assertArrayNotHasKey('multimedia', $menus, 'Subscriber has NO admin sidebar menu in locked matrix');
    }

    public function testSubscriberSubmissionsOnShowsCreatorMenuWithAllSubmenus(): void
    {
        // In locked matrix, subscriber has NO admin sidebar menu even if switch is yes
        Setting::set('multimedia', 'allow_subscriber_submissions', 'yes');
        $this->setUser(50);

        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $menus = AdminMenu::getMenus();
        $this->assertArrayNotHasKey('multimedia', $menus, 'When subscriber submissions are ON, subscriber still has NO admin menu in locked matrix');
    }

    public function testAuthorAndContributorSeeCreatorMenuByDefault(): void
    {
        // Author: sees creator menu
        $this->setUser(30);
        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $menus = AdminMenu::getMenus();
        $this->assertArrayHasKey('multimedia', $menus, 'Author must see multimedia creator menu by default');

        $submenus = $menus['multimedia']['submenus'];
        $this->assertInstanceOf(MultimediaSubmenuCollection::class, $submenus);
        $visible = $submenus->getVisible();

        $this->assertArrayHasKey('multimedia-my-submissions', $visible);
        $this->assertArrayHasKey('multimedia-movies?new=1', $visible);
        $this->assertArrayHasKey('multimedia-songs?new=1', $visible);

        // Contributor: fail-closed, does NOT see multimedia menu
        $this->setUser(40);
        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $menusContrib = AdminMenu::getMenus();
        $this->assertArrayNotHasKey('multimedia', $menusContrib, 'Contributor must NOT see multimedia menu in locked matrix');
    }

    public function testContentSettingsControlCreatorSubmenuItems(): void
    {
        // Disable movie and song uploads
        Setting::set('multimedia', 'allow_user_movie_upload', 'no');
        Setting::set('multimedia', 'allow_user_song_upload', 'no');
        $this->setUser(30);

        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $menus = AdminMenu::getMenus();
        $visible = $menus['multimedia']['submenus']->getVisible();

        $this->assertArrayNotHasKey('multimedia-movies?new=1', $visible, 'Add Movie must be omitted when movie upload is disabled');
        $this->assertArrayNotHasKey('multimedia-songs?new=1', $visible, 'Add Song must be omitted when song upload is disabled');
        $this->assertArrayHasKey('multimedia-series?new=1', $visible, 'Add Series must remain if series upload is enabled');

        // Restore settings
        Setting::set('multimedia', 'allow_user_movie_upload', 'yes');
        Setting::set('multimedia', 'allow_user_song_upload', 'yes');
    }

    public function testCreatorMenuDoesNotLeakAdminRoutes(): void
    {
        $this->setUser(30);

        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        FavoriteMultimediaPlugin::enableSidebarTitle();

        $menus = AdminMenu::getMenus();
        $visible = $menus['multimedia']['submenus']->getVisible();

        $adminOnlyRoutes = [
            'multimedia-settings',
            'multimedia-access',
            'multimedia-moderation',
            'multimedia-storage',
            'multimedia-processing',
            'multimedia-theme',
        ];

        foreach ($adminOnlyRoutes as $adminRoute) {
            $this->assertArrayNotHasKey($adminRoute, $visible, "Creator visible submenus must not contain admin route: {$adminRoute}");
        }
        $this->assertArrayHasKey('multimedia-analytics', $visible, 'Author must see My Analytics');
    }

    public function testTopLevelMenuRoutesCreatorsDirectlyToMySubmissions(): void
    {
        $this->setUser(30);

        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);

        $menus = AdminMenu::getMenus();
        $handler = $menus['multimedia']['handler'] ?? null;
        $this->assertIsCallable($handler);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = call_user_func($handler, $req);
        $content = ($resp instanceof Response) ? $resp->getContent() : (string)$resp;

        $this->assertStringContainsString('My Multimedia Submissions', $content);
        $this->assertStringNotContainsString('Analytics Overview', $content);
    }

    // =========================================================================
    // 2. Membership Status & UX Tests
    // =========================================================================

    public function testMembershipPageReturns200Never500WhenDigitalUnavailable(): void
    {
        $GLOBALS['_test_favorite_digital_available'] = false;
        $this->setUser(30);

        $frontendCtrl = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $frontendCtrl->membership($req);
        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        $this->assertStringContainsString('Membership', $content);
        $this->assertStringContainsString('Membership service is currently unavailable.', $content);
        $this->assertStringNotContainsString('500 Internal Server Error', $content);
    }

    public function testMembershipPageDisplaysActiveStatusAndHidesPrimaryBuyCta(): void
    {
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_active_membership'] = [
            'available'    => true,
            'has_active'   => true,
            'is_in_grace'  => false,
            'status'       => 'active',
            'status_label' => 'Active',
            'plan'         => 'VIP Gold Access',
            'expiry'       => 'December 31, 2026',
            'access'       => 'Premium',
            'manage_url'   => '/account/membership',
            'checkout_url' => '/store?product_type=membership',
        ];
        $this->setUser(30);

        $frontendCtrl = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $frontendCtrl->membership($req);
        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        // Displays active status and details
        $this->assertStringContainsString('Active', $content);
        $this->assertStringContainsString('VIP Gold Access', $content);
        $this->assertStringContainsString('December 31, 2026', $content);
        $this->assertStringContainsString('Premium', $content);

        // Does NOT show buy CTA as primary action
        $this->assertStringNotContainsString('Buy Membership Now', $content);
        $this->assertStringNotContainsString('Buy Membership / Upgrade', $content);
    }

    public function testMembershipPageDisplaysInactiveStatusAndShowsBuyCta(): void
    {
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_active_membership'] = false; // No active membership
        $GLOBALS['_test_favorite_pay_available'] = true;
        $this->setUser(30);

        $frontendCtrl = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $frontendCtrl->membership($req);
        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        // Displays inactive status
        $this->assertStringContainsString('No Active Membership', $content);

        // Shows Buy Membership CTA pointing to Favorite Pay store route
        $this->assertStringContainsString('Buy Membership', $content);
        $this->assertStringContainsString('/store?product_type=membership', $content);
    }

    public function testMembershipPageDisplaysExpiredStatusAndShowsRenewCta(): void
    {
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_membership_data'] = [
            'available'    => true,
            'has_active'   => false,
            'is_in_grace'  => false,
            'status'       => 'expired',
            'status_label' => 'Expired',
            'plan'         => 'VIP Gold Access',
            'expiry'       => 'August 01, 2026',
            'access'       => 'Standard',
            'manage_url'   => '/account/membership',
            'checkout_url' => '/store?product_type=membership',
        ];
        $GLOBALS['_test_favorite_pay_available'] = true;
        $this->setUser(30);

        $frontendCtrl = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $frontendCtrl->membership($req);
        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        $this->assertStringContainsString('Expired', $content);
        $this->assertStringContainsString('VIP Gold Access', $content);
        $this->assertStringContainsString('/store?product_type=membership', $content);
    }

    public function testMembershipFailsClosedWhenPayUnavailable(): void
    {
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_active_membership'] = false;
        $GLOBALS['_test_favorite_pay_available'] = false; // Pay disabled
        $this->setUser(30);

        $frontendCtrl = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $resp = $frontendCtrl->membership($req);
        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        $this->assertStringContainsString('Payment gateway is currently unavailable', $content);
    }

    public function testBothFrontendAndThemeHeadersContainCanonicalMembershipAndCreatorLinks(): void
    {
        $pluginHeader = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/header.php');
        $themeHeader  = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/header.php');

        $this->assertStringContainsString('/admin/page/multimedia', $pluginHeader);
        $this->assertStringContainsString('/admin/page/multimedia', $themeHeader);

        $this->assertStringContainsString('/admin/page/multimedia-my-submissions', $pluginHeader);
        $this->assertStringContainsString('/admin/page/multimedia-my-submissions', $themeHeader);

        $this->assertStringContainsString('Submit Media', $pluginHeader);
        $this->assertStringContainsString('Submit Media', $themeHeader);

        $this->assertStringContainsString('FavoriteDigitalAdapter::getSubscriptionUrl', $pluginHeader);
        $this->assertStringContainsString('FavoriteDigitalAdapter::getSubscriptionUrl', $themeHeader);
    }
}
