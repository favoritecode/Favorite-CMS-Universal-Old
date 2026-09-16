<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 4));
}
require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Multimedia\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaThemeHeaderPolishTest extends TestCase
{
    private string $themeDir;
    private string $pluginViewsDir;

    protected function setUp(): void
    {
        $this->themeDir = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme';
        $this->pluginViewsDir = APP_ROOT . '/plugins/favorite-multimedia/views/frontend';
        require_once $this->themeDir . '/functions.php';

        $container = Container::getInstance();
        if (!$container->has(Config::class)) {
            $container->instance(Config::class, new Config(APP_ROOT . '/config'));
        }

        $_SESSION = [];
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_SERVER['REQUEST_URI'] = '/';
        unset($_GET['q']);
    }

    private function renderHeader(?string $currentNav = null): string
    {
        ob_start();
        try {
            $siteTitle = 'Favorite Multimedia';
            $siteTagline = 'Stream movies & music';
            require $this->themeDir . '/header.php';
        } finally {
            $content = ob_get_clean();
        }
        return (string)$content;
    }

    private function renderFooter(?string $currentNav = null): string
    {
        ob_start();
        try {
            $siteTitle = 'Favorite Multimedia';
            require $this->themeDir . '/footer.php';
        } finally {
            $content = ob_get_clean();
        }
        return (string)$content;
    }

    private function renderComponentHeader(?string $currentNav = null): string
    {
        ob_start();
        try {
            require $this->pluginViewsDir . '/components/header.php';
        } finally {
            $content = ob_get_clean();
        }
        return (string)$content;
    }

    private function renderComponentMobileNav(?string $currentNav = null): string
    {
        ob_start();
        try {
            require $this->pluginViewsDir . '/components/mobile-nav.php';
        } finally {
            $content = ob_get_clean();
        }
        return (string)$content;
    }

    public function test01_ThemeHeaderRendersStickyWithZIndex1000(): void
    {
        $html = $this->renderHeader();
        $this->assertStringContainsString('fm-header-sticky', $html);
        $this->assertStringContainsString('z-index: 1000', $html);
    }

    public function test02_ThemeHeaderHasSvgBrandIconWithoutEmoji(): void
    {
        $html = $this->renderHeader();
        $this->assertStringContainsString('fm-brand-icon-svg', $html);
        $this->assertStringNotContainsString('🎬</span>', $html);
    }

    public function test03_ThemeHeaderExpandingSearchPillStructure(): void
    {
        $html = $this->renderHeader();
        $this->assertStringContainsString('id="fm-header-search"', $html);
        $this->assertStringContainsString('action="/multimedia/search"', $html);
        $this->assertStringContainsString('id="fm-search-pill"', $html);
        $this->assertStringContainsString('id="fm-search-toggle"', $html);
        $this->assertStringContainsString('id="fm-header-search-input"', $html);
        $this->assertStringContainsString('id="fm-search-submit"', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    public function test04_ThemeHeaderSearchPillSvgWithoutEmoji(): void
    {
        $html = $this->renderHeader();
        $this->assertStringContainsString('fm-search-icon', $html);
        $this->assertStringNotContainsString('>🔍<', $html);
    }

    public function test05_ActiveNavMappingHomeRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $html = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html);

        $_SERVER['REQUEST_URI'] = '/multimedia';
        $html2 = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html2);
    }

    public function test06_ActiveNavMappingMoviesRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/movies';
        $html = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/movies"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html);

        $_SERVER['REQUEST_URI'] = '/movie/inception-2010';
        $html2 = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/movies"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html2);
    }

    public function test07_ActiveNavMappingSeriesRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/series';
        $html = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/series"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html);

        $_SERVER['REQUEST_URI'] = '/series/breaking-bad';
        $html2 = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/series"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html2);
    }

    public function test08_ActiveNavMappingMusicRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/multimedia/music';
        $html = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/multimedia\/music"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html);

        $_SERVER['REQUEST_URI'] = '/song/42';
        $html2 = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/multimedia\/music"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html2);

        $_SERVER['REQUEST_URI'] = '/album/10';
        $html3 = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/multimedia\/music"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html3);
    }

    public function test09_ActiveNavMappingSearchRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/multimedia/search?q=interstellar';
        $html = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/multimedia\/search"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html);
    }

    public function test10_ActiveNavMappingLibraryRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/multimedia/library';
        $html = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/multimedia\/library"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html);

        $_SERVER['REQUEST_URI'] = '/multimedia/my-list';
        $html2 = $this->renderHeader();
        $this->assertMatchesRegularExpression('/href="\/multimedia\/library"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html2);
    }

    public function test11_ExplicitCurrentNavOverridesRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $html = $this->renderHeader('series');
        $this->assertMatchesRegularExpression('/href="\/series"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $html);
    }

    public function test12_HeaderProfileDropdownChevronSvgWithoutEmoji(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['auth_user_name'] = 'Alice Viewer';

        $html = $this->renderHeader();
        $this->assertStringContainsString('fm-dropdown-chevron', $html);
        $this->assertStringNotContainsString('▼', $html);
    }

    public function test13_HeaderProfileMenuItemsHaveSvgsWithoutEmojis(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['auth_user_name'] = 'Alice Viewer';

        $html = $this->renderHeader();
        $this->assertStringContainsString('fm-menu-icon', $html);
        $this->assertStringNotContainsString('👤', $html);
        $this->assertStringNotContainsString('📚', $html);
        $this->assertStringNotContainsString('❤️', $html);
        $this->assertStringNotContainsString('⏱️', $html);
        $this->assertStringNotContainsString('🚪', $html);
    }

    public function test14_ThemeFooterMobileNavHasSvgsWithoutEmojis(): void
    {
        $footerHtml = $this->renderFooter();
        preg_match('/<nav class="fm-mobile-nav".*?<\/nav>/s', $footerHtml, $m);
        $mobileNav = $m[0] ?? '';
        $this->assertNotEmpty($mobileNav, 'Mobile nav must be rendered in footer');
        $this->assertStringContainsString('fm-mobile-nav-svg', $mobileNav);
        $this->assertStringNotContainsString('🏠', $mobileNav);
        $this->assertStringNotContainsString('🎬', $mobileNav);
        $this->assertStringNotContainsString('🎵', $mobileNav);
        $this->assertStringNotContainsString('🔍', $mobileNav);
        $this->assertStringNotContainsString('👤', $mobileNav);
        $this->assertStringNotContainsString('🔑', $mobileNav);
    }

    public function test15_ThemeFooterMobileNavActiveRouteMapping(): void
    {
        $_SERVER['REQUEST_URI'] = '/movies';
        $footerHtml = $this->renderFooter();
        $this->assertMatchesRegularExpression('/href="\/movies"[^>]*class="[^"]*active[^"]*"[^>]*aria-current="page"/', $footerHtml);
    }

    public function test16_ThemeStudioStickyHeaderConfig(): void
    {
        $themeManager = ThemeManager::getInstance();
        $config = $themeManager->getActiveConfig();
        $isSticky = (bool)$config->get('header', 'sticky', true);
        $this->assertTrue($isSticky);

        $html = $this->renderHeader();
        $this->assertStringContainsString('fm-header-sticky', $html);
    }

    public function test17_PluginComponentHeaderParity(): void
    {
        $html = $this->renderComponentHeader();
        $this->assertStringContainsString('fm-header', $html);
        $this->assertStringContainsString('fm-header-sticky', $html);
        $this->assertStringContainsString('fm-brand-icon-svg', $html);
        $this->assertStringContainsString('fm-search-pill', $html);
        $this->assertStringContainsString('id="fm-search-toggle"', $html);
    }

    public function test18_PluginComponentMobileNavParity(): void
    {
        $html = $this->renderComponentMobileNav();
        $this->assertStringContainsString('fm-mobile-nav', $html);
        $this->assertStringContainsString('fm-mobile-nav-svg', $html);
        $this->assertStringNotContainsString('🏠', $html);
        $this->assertStringNotContainsString('🎬', $html);
    }

    public function test19_LayeringAudioMiniPlayerZIndex900(): void
    {
        $css = file_get_contents($this->themeDir . '/assets/css/audio-player.css');
        $this->assertMatchesRegularExpression('/\.fm-mini-player\s*\{[^}]*z-index:\s*900;/s', $css);
    }

    public function test20_LayeringAudioExpandedPlayerZIndex2000(): void
    {
        $css = file_get_contents($this->themeDir . '/assets/css/audio-player.css');
        $this->assertMatchesRegularExpression('/\.fm-expanded-player\s*\{[^}]*z-index:\s*2000;/s', $css);
        $this->assertMatchesRegularExpression('/\.fm-audio-queue-drawer\s*\{[^}]*z-index:\s*2000;/s', $css);
    }

    public function test21_LayeringHeaderZIndex1000AndDropdownZIndex1050(): void
    {
        $css = file_get_contents($this->themeDir . '/assets/css/multimedia-frontend.css');
        $this->assertMatchesRegularExpression('/\.fm-header\s*\{[^}]*z-index:\s*1000;/s', $css);
        $this->assertMatchesRegularExpression('/\.fm-profile-dropdown\s*\{[^}]*z-index:\s*1050;/s', $css);
    }

    public function test22_AllFrontendViewsIncludeGlobalHeader(): void
    {
        $viewsToCheck = [
            'hub.php',
            'movies-list.php',
            'series-list.php',
            'music.php',
            'search.php',
            'library.php',
            'my-list.php',
            'history.php',
            'movie-detail.php',
            'series-detail.php',
            'episode-detail.php',
            'song-detail.php',
            'album.php',
            'artist.php',
            'playlist-detail.php',
            'playlists-list.php',
            'songs-list.php',
            'discover.php',
            'genre.php',
            'following.php',
            'notifications.php',
        ];

        foreach ($viewsToCheck as $viewFile) {
            $filePath = $this->pluginViewsDir . '/' . $viewFile;
            $this->assertFileExists($filePath, "View file {$viewFile} must exist");
            $content = file_get_contents($filePath);
            $this->assertStringContainsString(
                "include \$componentsDir . '/header.php'",
                $content,
                "View file {$viewFile} must include the global frontend header"
            );
        }
    }
}
