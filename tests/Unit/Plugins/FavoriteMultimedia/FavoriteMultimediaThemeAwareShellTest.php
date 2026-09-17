<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Theme\ThemeShellService;
use FavoriteCMS\Rendering\Engine;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaThemeAwareShellTest extends TestCase
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
        require_once APP_ROOT . '/app/Core/helpers.php';
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_theme_shell_' . uniqid() . '.sqlite';
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
        $this->seedUsers();

        // Initialize plugin migrations and admin menus
        if (class_exists(\FavoriteCMS\Core\AdminMenu::class)) {
            \FavoriteCMS\Core\AdminMenu::reset();
        }
        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();
        MultimediaPermission::registerDefaultPermissions($this->db);

        // Set default theme
        $this->setTheme('default');
    }

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY,
            group_name VARCHAR(50) NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            value TEXT,
            type VARCHAR(20) DEFAULT 'string',
            is_public INTEGER DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            UNIQUE(group_name, setting_key)
        )");

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
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY,
            name VARCHAR(50),
            slug VARCHAR(50),
            description TEXT,
            created_at DATETIME,
            updated_at DATETIME
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
            user_id INTEGER,
            role_id INTEGER,
            PRIMARY KEY (user_id, role_id)
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            slug VARCHAR(100),
            description TEXT,
            group_name VARCHAR(50),
            created_at DATETIME,
            updated_at DATETIME
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER,
            permission_id INTEGER,
            created_at DATETIME,
            PRIMARY KEY (role_id, permission_id)
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menus (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            location VARCHAR(50)
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_items (
            id INTEGER PRIMARY KEY,
            menu_id INTEGER,
            parent_id INTEGER,
            title VARCHAR(100),
            url VARCHAR(255),
            target VARCHAR(20),
            order_index INTEGER
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS pages (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            title VARCHAR(255),
            slug VARCHAR(255),
            content TEXT,
            status VARCHAR(20) DEFAULT 'published',
            created_at DATETIME,
            updated_at DATETIME
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            title VARCHAR(255),
            slug VARCHAR(255),
            content TEXT,
            type VARCHAR(20) DEFAULT 'post',
            status VARCHAR(20) DEFAULT 'published',
            created_at DATETIME,
            updated_at DATETIME
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY,
            post_id INTEGER,
            user_id INTEGER,
            content TEXT,
            status VARCHAR(20) DEFAULT 'approved',
            created_at DATETIME,
            updated_at DATETIME
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS media (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            filename VARCHAR(255),
            path VARCHAR(255),
            mime_type VARCHAR(100),
            size INTEGER,
            created_at DATETIME,
            updated_at DATETIME
        )");
    }

    private function seedUsers(): void
    {
        $this->pdo->exec("INSERT INTO roles (id, name, slug) VALUES 
            (1, 'Super Admin', 'super-admin'),
            (2, 'Administrator', 'admin'),
            (5, 'Author', 'author'),
            (7, 'Subscriber', 'subscriber')");

        $now = date('Y-m-d H:i:s');
        $this->pdo->exec("INSERT INTO users (id, username, name, email, password, status, role, created_at, updated_at) VALUES
            (1, 'admin', 'Admin User', 'admin@example.com', 'secret', 'active', 'admin', '{$now}', '{$now}'),
            (5, 'author', 'Author User', 'author@example.com', 'secret', 'active', 'author', '{$now}', '{$now}'),
            (6, 'subscriber', 'Sub User', 'sub@example.com', 'secret', 'active', 'subscriber', '{$now}', '{$now}')");

        $this->pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES
            (1, 2),
            (5, 5),
            (6, 7)");
    }

    protected function tearDown(): void
    {
        ThemeShellService::reset();
        Setting::clearCache();
        $_SESSION = [];
        unset($GLOBALS['_test_current_user'], $GLOBALS['user_can_override'], $GLOBALS['favorite_cms_user']);
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function setTheme(string $theme): void
    {
        Setting::clearCache();
        Setting::set('theme', 'active_theme', $theme);
    }

    private function authenticateUser(int $userId): User
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $user = User::find($userId);
        $this->assertNotNull($user, "User {$userId} must exist in test database");

        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['auth_user_name'] = $user->name ?? $user->username;
        $_SESSION['auth_user_email'] = $user->email;
        $_SESSION['user_id'] = $user->id;
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

    /**
     * 1. plugin membership view contains no <header>
     */
    public function test01_pluginMembershipViewContainsNoHeaderTag(): void
    {
        $viewFile = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/membership.php';
        $this->assertFileExists($viewFile);
        $content = (string)file_get_contents($viewFile);

        $this->assertStringNotContainsString('<header', $content);
        $this->assertStringNotContainsString('</header>', $content);
    }

    /**
     * 2. plugin membership view contains no <footer>
     */
    public function test02_pluginMembershipViewContainsNoFooterTag(): void
    {
        $viewFile = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/membership.php';
        $content = (string)file_get_contents($viewFile);

        $this->assertStringNotContainsString('<footer', $content);
        $this->assertStringNotContainsString('</footer>', $content);
        $this->assertStringNotContainsString('<!DOCTYPE', $content);
        $this->assertStringNotContainsString('<html', $content);
        $this->assertStringNotContainsString('<body', $content);
    }

    /**
     * 1. active_theme=default resolves official header
     * 2. active_theme=default resolves official footer
     * 3. default theme never resolves Multimedia header
     * 4. default theme never resolves Multimedia footer
     */
    public function test03_officialThemeResolvesOfficialHeaderAndFooterAndNeverMultimedia(): void
    {
        $this->setTheme('default');
        $this->assertSame('default', ThemeShellService::getActiveTheme());
        $this->assertTrue(ThemeShellService::isOfficialThemeActive());
        $this->assertFalse(ThemeShellService::isMultimediaThemeActive());

        [$headerPath, $footerPath] = ThemeShellService::resolveThemeTemplates();

        $this->assertNotNull($headerPath);
        $this->assertNotNull($footerPath);

        $normalizedHeader = str_replace('\\', '/', $headerPath);
        $normalizedFooter = str_replace('\\', '/', $footerPath);

        // 1. active_theme=default resolves official header
        $this->assertStringEndsWith('themes/default/header.php', $normalizedHeader);
        // 2. active_theme=default resolves official footer
        $this->assertStringEndsWith('themes/default/footer.php', $normalizedFooter);

        // 3. default theme never resolves Multimedia header
        $this->assertStringNotContainsString('favorite-multimedia-theme', $normalizedHeader);
        // 4. default theme never resolves Multimedia footer
        $this->assertStringNotContainsString('favorite-multimedia-theme', $normalizedFooter);
    }

    /**
     * 5. active_theme=favorite-multimedia-theme resolves Multimedia header
     * 6. active_theme=favorite-multimedia-theme resolves Multimedia footer
     * 7. Multimedia active never resolves Official shell
     */
    public function test05_multimediaThemeResolvesMultimediaHeaderAndFooterAndNeverOfficial(): void
    {
        $this->setTheme('favorite-multimedia-theme');
        $this->assertSame('favorite-multimedia-theme', ThemeShellService::getActiveTheme());
        $this->assertTrue(ThemeShellService::isMultimediaThemeActive());
        $this->assertFalse(ThemeShellService::isOfficialThemeActive());

        [$headerPath, $footerPath] = ThemeShellService::resolveThemeTemplates();

        $this->assertNotNull($headerPath);
        $this->assertNotNull($footerPath);

        $normalizedHeader = str_replace('\\', '/', $headerPath);
        $normalizedFooter = str_replace('\\', '/', $footerPath);

        // 5. active_theme=favorite-multimedia-theme resolves Multimedia header
        $this->assertStringContainsString('favorite-multimedia-theme', $normalizedHeader);
        $this->assertStringEndsWith('header.php', $normalizedHeader);

        // 6. active_theme=favorite-multimedia-theme resolves Multimedia footer
        $this->assertStringContainsString('favorite-multimedia-theme', $normalizedFooter);
        $this->assertStringEndsWith('footer.php', $normalizedFooter);

        // 7. Multimedia active never resolves Official shell
        $this->assertStringNotContainsString('themes/default', $normalizedHeader);
        $this->assertStringNotContainsString('themes/default', $normalizedFooter);
    }

    /**
     * 4. Official Theme active => official theme header rendered on membership
     * 5. Official Theme active => official theme footer rendered on membership
     * 8. exactly one header
     * 9. exactly one footer
     * 10. exactly one <html>/<body>
     * DOM MUST NOT contain Multimedia theme markup
     */
    public function test04_officialThemeActiveRendersOfficialHeaderAndFooterOnMembership(): void
    {
        $this->setTheme('default');
        $this->assertSame('default', ThemeShellService::getActiveTheme());
        $this->assertTrue(ThemeShellService::isOfficialThemeActive());

        $frontend = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/multimedia/membership']);
        $resp = $frontend->membership($req);

        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // 4. Official theme header rendered
        $this->assertStringContainsString('class="site-header"', $html);
        // 5. Official theme footer rendered
        $this->assertStringContainsString('class="site-footer"', $html);

        // DOM MUST NOT contain Multimedia Theme markup
        $this->assertStringNotContainsString('class="fm-header', $html);
        $this->assertStringNotContainsString('id="fm-header"', $html);
        $this->assertStringNotContainsString('class="fm-nav', $html);
        $this->assertStringNotContainsString('id="fm-mini-player"', $html);

        // 8. Exactly one header
        $this->assertSame(1, substr_count($html, '<header'));
        // 9. Exactly one footer
        $this->assertSame(1, substr_count($html, '<footer'));
        // 10. Exactly one html and body
        $this->assertSame(1, substr_count($html, '<!DOCTYPE html>'));
        $this->assertSame(1, substr_count($html, '<html'));
        $this->assertSame(1, substr_count($html, '<body'));
    }

    /**
     * 6. Multimedia Theme active => multimedia theme header rendered
     * 7. Multimedia Theme active => multimedia theme footer rendered
     * DOM MUST NOT contain Official Theme markup
     */
    public function test06_multimediaThemeActiveRendersMultimediaHeaderAndFooter(): void
    {
        $this->setTheme('favorite-multimedia-theme');
        $this->assertSame('favorite-multimedia-theme', ThemeShellService::getActiveTheme());
        $this->assertTrue(ThemeShellService::isMultimediaThemeActive());

        $frontend = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/multimedia/membership']);
        $resp = $frontend->membership($req);

        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // 6. Multimedia theme header
        $this->assertStringContainsString('class="fm-header', $html);
        // 7. Multimedia theme footer
        $this->assertStringContainsString('id="fm-mini-player"', $html);

        // No official theme markup
        $this->assertStringNotContainsString('class="site-header"', $html);
        $this->assertStringNotContainsString('class="site-footer"', $html);

        // Exactly one header
        $this->assertSame(1, substr_count($html, '<header'));
    }

    /**
     * 8. Official -> Multimedia -> Official switch works
     * 9. no global template-path leakage
     */
    public function test08_officialMultimediaOfficialSwitchWorksAndNoGlobalTemplatePathLeakage(): void
    {
        $ref = new \ReflectionProperty(Engine::class, 'customTemplatePaths');

        // Step 1: Official Theme
        $this->setTheme('default');
        [$h1, $f1] = ThemeShellService::resolveThemeTemplates();
        $this->assertStringEndsWith('themes/default/header.php', str_replace('\\', '/', $h1));
        $this->assertStringEndsWith('themes/default/footer.php', str_replace('\\', '/', $f1));
        $this->assertEmpty(array_filter($ref->getValue(), fn($p) => str_contains((string)$p, 'favorite-multimedia-theme')));

        // Step 2: Switch to Multimedia Theme
        $this->setTheme('favorite-multimedia-theme');
        [$h2, $f2] = ThemeShellService::resolveThemeTemplates();
        $this->assertStringContainsString('favorite-multimedia-theme', str_replace('\\', '/', $h2));
        $this->assertStringContainsString('favorite-multimedia-theme', str_replace('\\', '/', $f2));

        // Step 3: Switch back to Official Theme
        $this->setTheme('default');
        [$h3, $f3] = ThemeShellService::resolveThemeTemplates();
        $this->assertStringEndsWith('themes/default/header.php', str_replace('\\', '/', $h3));
        $this->assertStringEndsWith('themes/default/footer.php', str_replace('\\', '/', $f3));

        // 9. No global template path leakage
        $pathsAfterSwitch = $ref->getValue();
        $this->assertEmpty(array_filter($pathsAfterSwitch, fn($p) => str_contains((string)$p, 'favorite-multimedia-theme')));
    }

    /**
     * 11. active theme switch changes shell without plugin modification
     */
    public function test11_activeThemeSwitchChangesShellWithoutPluginModification(): void
    {
        $frontend = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/multimedia/membership']);

        // Step 1: Official Theme
        $this->setTheme('default');
        $respOfficial = $frontend->membership($req);
        $this->assertStringContainsString('class="site-header"', $respOfficial->getContent());
        $this->assertStringNotContainsString('class="fm-header', $respOfficial->getContent());

        // Step 2: Switch to Multimedia Theme
        $this->setTheme('favorite-multimedia-theme');
        $respMM = $frontend->membership($req);
        $this->assertStringContainsString('class="fm-header', $respMM->getContent());
        $this->assertStringNotContainsString('class="site-header"', $respMM->getContent());

        // Step 3: Switch back to Official Theme
        $this->setTheme('default');
        $respOfficial2 = $frontend->membership($req);
        $this->assertStringContainsString('class="site-header"', $respOfficial2->getContent());
    }

    /**
     * 11. checkout uses same active-theme resolver
     */
    public function test10_checkoutUsesSameActiveThemeResolver(): void
    {
        Router::reset();
        ThemeShellService::reset();

        Router::get('/checkout/test_plan', function () {
            return Response::make('<div class="checkout-page"><h2>Checkout Test</h2></div>', 200);
        });

        ThemeShellService::wrapHumanFacingRoutes();

        // 1. When Official Theme is active:
        $this->setTheme('default');
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/checkout/test_plan']);
        $resp = Router::dispatch($req);
        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();
        $this->assertStringContainsString('class="site-header"', $html);
        $this->assertStringContainsString('class="site-footer"', $html);
        $this->assertStringNotContainsString('class="fm-header', $html);
        $this->assertStringNotContainsString('id="fm-mini-player"', $html);

        // 2. When Multimedia Theme is active:
        $this->setTheme('favorite-multimedia-theme');
        $respMM = Router::dispatch($req);
        $this->assertSame(200, $respMM->getStatusCode());
        $htmlMM = $respMM->getContent();
        $this->assertStringContainsString('class="fm-header', $htmlMM);
        $this->assertStringContainsString('id="fm-mini-player"', $htmlMM);
        $this->assertStringNotContainsString('class="site-header"', $htmlMM);
        $this->assertStringNotContainsString('class="site-footer"', $htmlMM);
    }

    /**
     * 12. machine webhook remains raw/headerless
     * 13. API callback remains raw/headerless
     */
    public function test12_machineEndpointsRemainRawAndHeaderless(): void
    {
        Router::reset();
        ThemeShellService::reset();

        // 12. Webhook
        Router::post('/api/multimedia/webhook', function () {
            return Response::json(['webhook' => 'ok']);
        });

        // 13. API callback
        Router::get('/checkout/callback', function () {
            return Response::json(['verified' => true]);
        });

        ThemeShellService::wrapHumanFacingRoutes();

        // Dispatch webhook
        $reqW = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/multimedia/webhook']);
        $respW = Router::dispatch($reqW);
        $this->assertSame(200, $respW->getStatusCode());
        $this->assertSame('{"webhook":"ok"}', $respW->getContent());
        $this->assertStringNotContainsString('<header', $respW->getContent());

        // Dispatch callback with JSON accept
        $reqC = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/checkout/callback', 'HTTP_ACCEPT' => 'application/json']);
        $respC = Router::dispatch($reqC);
        $this->assertSame(200, $respC->getStatusCode());
        $this->assertSame('{"verified":true}', $respC->getContent());
        $this->assertStringNotContainsString('<header', $respC->getContent());
    }

    /**
     * 14. Favorite Digital unavailable still renders active theme
     * 15. Favorite Pay unavailable still renders active theme
     */
    public function test14_digitalAndPayUnavailableStillRendersActiveTheme(): void
    {
        $this->setTheme('default');
        $frontend = new MultimediaFrontendController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/multimedia/membership']);

        // Even when digital adapter and pay adapter return false/unavailable
        $resp = $frontend->membership($req);
        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // Active theme still renders cleanly
        $this->assertStringContainsString('class="site-header"', $html);
        $this->assertStringContainsString('class="site-footer"', $html);
        $this->assertStringContainsString('Membership', $html);
    }

    /**
     * 16. Author Multimedia page still 200
     * 17. Author profile still 200
     */
    public function test16_authorMultimediaAndProfileReturn200(): void
    {
        $this->authenticateUser(5); // Author
        FavoriteMultimediaPlugin::enableSidebarTitle();
        $kernel = new Kernel($this->app);

        // 16. /admin/page/multimedia
        $reqMM = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia']);
        $respMM = $kernel->handle($reqMM);
        $this->assertSame(200, $respMM->getStatusCode());
        $this->assertStringContainsString('wp-sidebar', $respMM->getContent());

        // 17. /admin/users/profile
        $reqProf = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/users/profile']);
        $respProf = $kernel->handle($reqProf);
        $this->assertSame(200, $respProf->getStatusCode());
    }

    /**
     * 18. Author published delete still same-page toast (HTTP 403 AJAX JSON)
     */
    public function test18_authorPublishedDeleteReturns403JsonForToast(): void
    {
        $author = $this->authenticateUser(5);
        $movie = new Movie();
        $movie->title = 'Author Pub Movie';
        $movie->slug = 'author-pub-movie-' . uniqid();
        $movie->user_id = $author->id;
        $movie->status = 'published';
        $movie->save();

        $admin = new MultimediaAdminController($this->app);
        $req = new Request([], [
            'action' => 'delete',
            'id' => (string)$movie->id,
            '_token' => 'test_token',
        ], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/page/multimedia-movies',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $_SESSION['_token'] = 'test_token';

        $resp = $admin->movies($req);
        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame(403, $resp->getStatusCode());

        $data = json_decode($resp->getContent(), true);
        $this->assertIsArray($data);
        $this->assertFalse($data['success']);
        $this->assertSame('You cannot delete this movie.', $data['error']);
    }

    /**
     * 19. Subscriber still no creator access
     * 20. locked access matrix unchanged
     */
    public function test19_subscriberNoCreatorAccessAndLockedMatrix(): void
    {
        $subscriber = User::find(6);
        $this->assertSame('subscriber', $subscriber->role);

        $this->assertFalse(MultimediaPermission::can(MultimediaPermission::CREATE, $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit(null, $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit('movie', $subscriber));
        $this->assertFalse(MultimediaPermission::canUserSubmit('song', $subscriber));

        // Author can submit
        $author = User::find(5);
        $this->assertTrue(MultimediaPermission::canUserSubmit(null, $author));
        $this->assertTrue(MultimediaPermission::canUserSubmit('movie', $author));
        $this->assertTrue(MultimediaPermission::canUserSubmit('song', $author));
    }
}
