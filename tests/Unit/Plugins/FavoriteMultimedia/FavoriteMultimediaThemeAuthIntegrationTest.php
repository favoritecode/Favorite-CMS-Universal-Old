<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 4));
}
require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Theme\ThemePackageService;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaThemeAuthIntegrationTest extends TestCase
{
    private string $themeDir;

    protected function setUp(): void
    {
        $this->themeDir = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme';
        require_once $this->themeDir . '/functions.php';

        $container = Container::getInstance();
        if (!$container->has(Config::class)) {
            $container->instance(Config::class, new Config(APP_ROOT . '/config'));
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function renderHeader(): string
    {
        ob_start();
        try {
            $siteTitle = 'Test Site';
            $siteTagline = 'Tagline';
            require $this->themeDir . '/header.php';
        } finally {
            $content = ob_get_clean();
        }
        return (string)$content;
    }

    private function renderFooter(): string
    {
        ob_start();
        try {
            $siteTitle = 'Test Site';
            require $this->themeDir . '/footer.php';
        } finally {
            $content = ob_get_clean();
        }
        return (string)$content;
    }

    public function test01_GuestSeesSignIn(): void
    {
        $_SESSION = [];
        $html = $this->renderHeader();

        $this->assertStringContainsString('Sign In', $html);
        $this->assertStringContainsString('/admin/login', $html);
        $this->assertStringNotContainsString('fm-profile-dropdown', $html);
        $this->assertFalse(favorite_multimedia_theme_is_logged_in());
    }

    public function test02_LoggedInUserDoesNotSeeSignIn(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['auth_user_name'] = 'Alice Viewer';
        $_SESSION['auth_user_email'] = 'alice@example.com';

        $html = $this->renderHeader();

        $this->assertStringNotContainsString('>Sign In<', $html);
        $this->assertTrue(favorite_multimedia_theme_is_logged_in());
    }

    public function test03_LoggedInUserSeesProfileMenu(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['auth_user_name'] = 'Alice Viewer';

        $html = $this->renderHeader();

        $this->assertStringContainsString('id="fm-profile-btn"', $html);
        $this->assertStringContainsString('id="fm-profile-dropdown"', $html);
        $this->assertStringContainsString('fm-profile-menu-container', $html);
    }

    public function test04_DisplayNameRenders(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['auth_user_name'] = 'Johnathan Doe';

        $html = $this->renderHeader();

        $this->assertStringContainsString('Johnathan Doe', $html);
        $this->assertSame('Johnathan Doe', favorite_multimedia_theme_user_display_name());
    }

    public function test05_AvatarFallbackInitialsRender(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['auth_user_name'] = 'Sarah Connor';

        $initials = favorite_multimedia_theme_user_initials();
        $this->assertSame('SC', $initials);

        $html = $this->renderHeader();
        $this->assertStringContainsString('SC', $html);
        $this->assertStringContainsString('fm-user-avatar', $html);
    }

    public function test06_ProfileRouteValid(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $html = $this->renderHeader();

        $this->assertStringContainsString('/admin/users/profile', $html);
        $this->assertStringContainsString('My Profile', $html);
    }

    public function test07_LibraryRouteValid(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $html = $this->renderHeader();

        $this->assertStringContainsString('/multimedia/library', $html);
        $this->assertStringContainsString('My Library', $html);
    }

    public function test08_MyListRouteValid(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $html = $this->renderHeader();

        $this->assertStringContainsString('/multimedia/my-list', $html);
        $this->assertStringContainsString('My List', $html);
    }

    public function test09_HistoryRouteValid(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $html = $this->renderHeader();

        $this->assertStringContainsString('/multimedia/history', $html);
        $this->assertStringContainsString('History', $html);
    }

    public function test10_LogoutRouteUsesCmsMechanism(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $html = $this->renderHeader();

        $this->assertStringContainsString('/admin/logout', $html);
        $this->assertStringContainsString('Log Out', $html);
    }

    public function test11_AdminSeesDashboardIfPermitted(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'Admin';

        // Mock User with super-admin role
        $mockUser = $this->createMock(User::class);
        $mockUser->method('hasRole')->with($this->logicalOr('super-admin', 'admin', 'editor', 'moderator'))->willReturn(true);
        $mockUser->name = 'Admin User';
        $mockUser->email = 'admin@example.com';

        $canAccess = favorite_multimedia_theme_can_access_admin($mockUser);
        $this->assertTrue($canAccess);
    }

    public function test12_NormalUserDoesNotSeeAdminOnlyOption(): void
    {
        $_SESSION['auth_user_id'] = 99;
        $_SESSION['auth_user_name'] = 'Normal Subscriber';

        // Mock normal subscriber
        $mockUser = $this->createMock(User::class);
        $mockUser->method('hasRole')->willReturn(false);

        $canAccess = favorite_multimedia_theme_can_access_admin($mockUser);
        $this->assertFalse($canAccess);
    }

    public function test13_MobileAuthenticatedState(): void
    {
        // 1. Logged out mobile footer
        $_SESSION = [];
        $guestFooter = $this->renderFooter();
        $this->assertStringContainsString('/admin/login', $guestFooter);
        $this->assertStringContainsString('Sign In', $guestFooter);

        // 2. Logged in mobile footer
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['auth_user_name'] = 'Member';
        $memberFooter = $this->renderFooter();
        $this->assertStringContainsString('/multimedia/library', $memberFooter);
        $this->assertStringContainsString('Account', $memberFooter);
    }

    public function test14_DropdownAccessibility(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $html = $this->renderHeader();

        $this->assertStringContainsString('aria-haspopup="true"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('aria-controls="fm-profile-dropdown"', $html);
        $this->assertStringContainsString('role="menu"', $html);
        $this->assertStringContainsString('role="menuitem"', $html);
        $this->assertStringContainsString('hidden', $html);
    }

    public function test15_FavoriteDefaultAuthHelperParity(): void
    {
        $_SESSION = [];
        $this->assertFalse(favorite_multimedia_theme_is_logged_in());

        $_SESSION['auth_user_id'] = 10;
        $this->assertTrue(favorite_multimedia_theme_is_logged_in());
    }
}
