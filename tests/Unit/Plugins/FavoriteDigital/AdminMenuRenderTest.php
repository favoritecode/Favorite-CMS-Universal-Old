<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use PDO;
use PHPUnit\Framework\TestCase;

class AdminMenuRenderTest extends TestCase
{
    private Application $app;
    private Database $sqliteDb;
    private PDO $sqlitePdo;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }

        $this->app = new Application();
        $this->sqlitePdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->sqlitePdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, name TEXT, email TEXT, role TEXT, status TEXT, password_hash TEXT);");
        $this->sqlitePdo->exec("INSERT INTO users (id, username, name, email, role, status) VALUES (1, 'admin', 'Admin', 'admin@example.com', 'administrator', 'active');");
        $this->sqlitePdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, is_system INTEGER DEFAULT 0);");
        $this->sqlitePdo->exec("INSERT INTO roles (id, name, slug, is_system) VALUES (1, 'Administrator', 'administrator', 1);");
        $this->sqlitePdo->exec("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER);");
        $this->sqlitePdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1);");
        $this->sqlitePdo->exec("CREATE TABLE permissions (id INTEGER PRIMARY KEY, name TEXT, slug TEXT);");
        $this->sqlitePdo->exec("INSERT INTO permissions (id, name, slug) VALUES (1, 'Manage Options', 'manage_options');");
        $this->sqlitePdo->exec("CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER);");
        $this->sqlitePdo->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 1);");
        $this->sqlitePdo->exec("CREATE TABLE settings (id INTEGER PRIMARY KEY, `key` TEXT, `value` TEXT, section TEXT);");

        $this->sqliteDb = new class($this->sqlitePdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };

        Container::getInstance()->instance(Database::class, $this->sqliteDb);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_role'] = 'administrator';
        $_SESSION['auth_user_name'] = 'Admin';

        $adminUser = new class {
            public int $id = 1;
            public string $role = 'administrator';
            public function can(string $cap): bool { return true; }
            public function isActive(): bool { return true; }
            public function canCreatePosts(): bool { return true; }
            public function canUpdatePosts(): bool { return true; }
            public function canModeratePosts(): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $adminUser;
        $GLOBALS['_test_current_user_id'] = 1;

        AdminMenu::reset();
    }

    protected function tearDown(): void
    {
        AdminMenu::reset();
        parent::tearDown();
    }

    private function renderLayout(string $activeMenu = 'favorite-digital'): string
    {
        $siteName = 'Favorite CMS';
        $username = 'Admin';
        $pageTitle = 'Test Title';
        $flashSuccess = null;
        $flashError = null;
        $customHtml = '<p>Content</p>';
        $contentView = null;
        $isAdmin = true;
        $canManageUsers = true;

        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return (string) ob_get_clean();
    }

    public function testFavoriteDigitalAdminMenuStructure(): void
    {
        $plugin = new FavoriteDigitalPlugin($this->app);
        $plugin->boot();

        $menus = AdminMenu::getMenus();
        $this->assertArrayHasKey('favorite-digital', $menus);

        $fd = $menus['favorite-digital'];
        $this->assertSame('favorite-digital', $fd['slug']);
        $this->assertSame('Digital Store', $fd['title']);
        $this->assertSame('📦', $fd['icon']);
        $this->assertSame('manage_options', $fd['capability']);
        $this->assertSame(56, $fd['position']);

        $submenus = $fd['submenus'];
        $this->assertCount(5, $submenus);

        $expectedSubmenus = [
            'favorite-digital'             => 'Digital Products',
            'favorite-digital-services'    => 'Services',
            'favorite-digital-packages'    => 'Packages',
            'favorite-digital-memberships' => 'Memberships',
            'favorite-digital-orders'      => 'Orders',
        ];

        foreach ($expectedSubmenus as $expectedSlug => $expectedTitle) {
            $this->assertArrayHasKey($expectedSlug, $submenus);
            $this->assertSame($expectedSlug, $submenus[$expectedSlug]['slug']);
            $this->assertSame($expectedTitle, $submenus[$expectedSlug]['title']);
            $this->assertSame('manage_options', $submenus[$expectedSlug]['capability']);
        }
    }

    public function testRenderedSidebarContainsExactlyOneDigitalStoreAndZeroDuplicateSubmenus(): void
    {
        $plugin = new FavoriteDigitalPlugin($this->app);
        $plugin->boot();

        $html = $this->renderLayout('favorite-digital');

        // Extract the full favorite-digital menu <li> item
        $pattern = '/<li class="[^"]*wp-menu-item[^"]*has-submenu[^"]*">.*?<a href="\/admin\/page\/favorite-digital"[^>]*>.*?<span>Digital Store<\/span>.*?<ul class="wp-submenu"[^>]*>(.*?)<\/ul>\s*<\/li>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Digital Store top-level menu item with submenu must be present');

        $submenuHtml = $matches[1];

        // 1. Assert exactly ONE "Digital Store" appears in the entire dynamic menu block (the parent title)
        $this->assertStringNotContainsString('Digital Store', $submenuHtml, 'Submenu must NOT contain any "Digital Store" link');

        // 2. Count occurrences of "Digital Store" across the entire HTML within navigation
        if (preg_match('/<nav class="wp-sidebar">(.*?)<\/nav>/s', $html, $navMatch)) {
            $navHtml = $navMatch[1];
            $count = substr_count($navHtml, 'Digital Store');
            $this->assertSame(1, $count, "Exactly ONE 'Digital Store' must appear in the admin navigation (parent link only)");
        }

        // 3. Assert "Digital Products" is present and points to /admin/page/favorite-digital
        $this->assertStringContainsString('href="/admin/page/favorite-digital"', $submenuHtml);
        $this->assertStringContainsString('Digital Products', $submenuHtml);

        // 4. Assert all other submenus are present with correct URLs
        $this->assertStringContainsString('href="/admin/page/favorite-digital-services"', $submenuHtml);
        $this->assertStringContainsString('Services', $submenuHtml);

        $this->assertStringContainsString('href="/admin/page/favorite-digital-packages"', $submenuHtml);
        $this->assertStringContainsString('Packages', $submenuHtml);

        $this->assertStringContainsString('href="/admin/page/favorite-digital-memberships"', $submenuHtml);
        $this->assertStringContainsString('Memberships', $submenuHtml);

        $this->assertStringContainsString('href="/admin/page/favorite-digital-orders"', $submenuHtml);
        $this->assertStringContainsString('Orders', $submenuHtml);

        // 5. Assert submenu item order: Digital Products, Services, Packages, Memberships, Orders
        preg_match_all('/<a href="([^"]*)"[^>]*>(.*?)<\/a>/s', $submenuHtml, $linkMatches, PREG_SET_ORDER);
        $this->assertCount(5, $linkMatches);
        $this->assertSame('/admin/page/favorite-digital', $linkMatches[0][1]);
        $this->assertSame('Digital Products', trim(strip_tags($linkMatches[0][2])));

        $this->assertSame('/admin/page/favorite-digital-services', $linkMatches[1][1]);
        $this->assertSame('Services', trim(strip_tags($linkMatches[1][2])));

        $this->assertSame('/admin/page/favorite-digital-packages', $linkMatches[2][1]);
        $this->assertSame('Packages', trim(strip_tags($linkMatches[2][2])));

        $this->assertSame('/admin/page/favorite-digital-memberships', $linkMatches[3][1]);
        $this->assertSame('Memberships', trim(strip_tags($linkMatches[3][2])));

        $this->assertSame('/admin/page/favorite-digital-orders', $linkMatches[4][1]);
        $this->assertSame('Orders', trim(strip_tags($linkMatches[4][2])));
    }

    public function testActiveHighlightingAcrossAllFiveSubpages(): void
    {
        $plugin = new FavoriteDigitalPlugin($this->app);
        $plugin->boot();

        $subpages = [
            'favorite-digital'             => 'Digital Products',
            'favorite-digital-services'    => 'Services',
            'favorite-digital-packages'    => 'Packages',
            'favorite-digital-memberships' => 'Memberships',
            'favorite-digital-orders'      => 'Orders',
        ];

        foreach ($subpages as $activeSlug => $activeTitle) {
            $html = $this->renderLayout($activeSlug);

            // Parent <li> must be active and is-expanded
            $this->assertMatchesRegularExpression(
                '/<li class="[^"]*wp-menu-item[^"]*active[^"]*is-expanded[^"]*">.*?<span>Digital Store<\/span>/s',
                $html,
                "Parent Digital Store menu item must remain active and expanded when on '{$activeSlug}'"
            );

            // The specific submenu link must have class="active"
            $this->assertMatchesRegularExpression(
                '/<a href="\/admin\/page\/' . preg_quote($activeSlug, '/') . '" class="active">' . preg_quote($activeTitle, '/') . '<\/a>/',
                $html,
                "Submenu item '{$activeTitle}' must have class='active' when on '{$activeSlug}'"
            );
        }
    }

    public function testPluginWithoutCustomSubmenuRetainsParentLink(): void
    {
        // Register a plugin where submenus do NOT use the parent's slug
        AdminMenu::addMenu('generic-plugin', 'Generic Parent', '🔌', fn() => 'ok', 'manage_options', 70);
        AdminMenu::addSubMenu('generic-plugin', 'generic-sub-one', 'Sub Item 1', fn() => 'ok', 'manage_options');
        AdminMenu::addSubMenu('generic-plugin', 'generic-sub-two', 'Sub Item 2', fn() => 'ok', 'manage_options');

        $html = $this->renderLayout('generic-sub-one');

        // Extract generic-plugin submenu
        $pattern = '/<li class="[^"]*wp-menu-item[^"]*has-submenu[^"]*">.*?<a href="\/admin\/page\/generic-plugin"[^>]*>.*?<span>Generic Parent<\/span>.*?<ul class="wp-submenu"[^>]*>(.*?)<\/ul>\s*<\/li>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches));

        $submenuHtml = $matches[1];
        // Parent link SHOULD be generated because none of the submenus have slug 'generic-plugin'
        $this->assertStringContainsString('href="/admin/page/generic-plugin"', $submenuHtml);
        $this->assertStringContainsString('Generic Parent', $submenuHtml);
        $this->assertStringContainsString('Sub Item 1', $submenuHtml);
        $this->assertStringContainsString('Sub Item 2', $submenuHtml);
    }
}
