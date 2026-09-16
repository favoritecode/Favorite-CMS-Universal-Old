<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Theme\ThemeConfig;
use FavoriteCMS\Multimedia\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

/**
 * Hosting Layout & Polish Acceptance Tests for Favorite Multimedia Theme v1.0.2 + Plugin v1.0.7.
 */
class FavoriteMultimediaThemeHostingLayoutTest extends TestCase
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

        $_SESSION = [];
        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_theme_hl_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        FavoriteMultimediaPlugin::reset();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    public function testThemeManifestAndVersionIntegrity(): void
    {
        $manifestPath = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/theme.json';
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame('1.0.2', $manifest['version'], 'Theme version must strictly remain v1.0.2');
        $this->assertSame('favorite-multimedia-theme', $manifest['id']);
        $this->assertSame('favorite-multimedia', $manifest['requires_plugin']);
    }

    public function testThemeStylesheetsSyncedAndContainEngagementRules(): void
    {
        $themeCss1 = APP_ROOT . '/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css';
        $themeCss2 = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/assets/css/multimedia-frontend.css';

        $this->assertFileExists($themeCss1);
        $this->assertFileExists($themeCss2);

        $content1 = file_get_contents($themeCss1);
        $content2 = file_get_contents($themeCss2);

        $this->assertSame(
            $content1,
            $content2,
            'Bundled theme css and standalone theme package css must be completely synchronized.'
        );

        // Required Engagement Classes
        $this->assertStringContainsString('.fav-engagement-container', $content1);
        $this->assertStringContainsString('.fav-rating-shelf', $content1);
        $this->assertStringContainsString('.fav-star-btn', $content1);
        $this->assertStringContainsString('.fav-star-btn.active', $content1);
        $this->assertStringContainsString('.fav-star-btn:hover', $content1);
        $this->assertStringContainsString('.fav-form-textarea', $content1);
        $this->assertStringContainsString('.fav-btn-action', $content1);
        $this->assertStringContainsString('.fav-rating-count-label', $content1);
        $this->assertStringContainsString('.fav-rating-number', $content1);
        $this->assertStringContainsString('.fav-comment-card', $content1);
    }

    public function testSidebarCssEliminatesForcedIndependentScrollbar(): void
    {
        $themeCss = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/assets/css/multimedia-frontend.css');

        // Verify .fm-sticky-sidebar does NOT have overflow-y: auto or max-height calc(100vh) in sticky state
        $this->assertDoesNotMatchRegularExpression(
            '/\.fm-sticky-sidebar\.is-sticky\s*\{[^}]*overflow-y\s*:\s*auto/i',
            $themeCss,
            'Sticky sidebar must not force overflow-y: auto causing secondary scrollbar'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.fm-sticky-sidebar\.is-sticky\s*\{[^}]*max-height\s*:\s*calc/i',
            $themeCss,
            'Sticky sidebar must not restrict max-height to calc(100vh)'
        );

        // Verify natural height and alignment
        $this->assertMatchesRegularExpression(
            '/\.fm-sticky-sidebar\s*\{[^}]*height\s*:\s*fit-content/i',
            $themeCss,
            'Sidebar should use height: fit-content'
        );
        $this->assertMatchesRegularExpression(
            '/\.fm-sticky-sidebar\s*\{[^}]*align-self\s*:\s*start/i',
            $themeCss,
            'Sidebar should use align-self: start for natural page scrolling'
        );
    }

    public function testGridDetailLayoutAndMobileResponsiveBreakpoints(): void
    {
        $themeCss = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/assets/css/multimedia-frontend.css');

        // Verify CSS Grid with minmax(0, 1fr)
        $this->assertStringContainsString('minmax(0, 1fr)', $themeCss);
        $this->assertStringContainsString('.fm-content-layout', $themeCss);

        // Mobile responsive rule
        $this->assertStringContainsString('@media (max-width: 1024px)', $themeCss);
        $this->assertMatchesRegularExpression(
            '/\.fm-sticky-sidebar\s*\{[^}]*position\s*:\s*static\s*!important/s',
            $themeCss,
            'Sidebar must un-stick to static on mobile breakpoint'
        );
    }

    public function testEngagementSectionEmptyRatingStateMarkup(): void
    {
        $partialPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/_engagement_section.php';
        $this->assertFileExists($partialPath);
        $content = file_get_contents($partialPath);

        // Verify empty state display "No ratings yet" when $ratingCnt === 0
        $this->assertStringContainsString('No ratings yet', $content);
        $this->assertStringContainsString('fav-rating-count-label', $content);
        $this->assertStringContainsString('fav-rating-title', $content);
        $this->assertStringContainsString('fav-user-rating-label', $content);
    }

    public function testDetailPagesPlayerStageAndDeduplication(): void
    {
        $movieDetailPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/movie-detail.php';
        $this->assertFileExists($movieDetailPath);
        $movieContent = file_get_contents($movieDetailPath);

        // Verify player wrapper uses .fm-player-stage instead of hardcoded 960px margin auto
        $this->assertStringContainsString('fm-player-stage', $movieContent);
        $this->assertStringNotContainsString('max-width: 960px; margin: 0 auto 32px auto;', $movieContent);

        // Verify deduplication check for Add to My List
        $this->assertStringContainsString('$sidebarHasFavorite', $movieContent);

        // Episode detail has player stage
        $episodeDetailPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/episode-detail.php';
        $this->assertFileExists($episodeDetailPath);
        $episodeContent = file_get_contents($episodeDetailPath);
        $this->assertStringContainsString('fm-player-stage', $episodeContent);

        // Series detail has deduplication check
        $seriesDetailPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/series-detail.php';
        $this->assertFileExists($seriesDetailPath);
        $seriesContent = file_get_contents($seriesDetailPath);
        $this->assertStringContainsString('$sidebarHasActions', $seriesContent);

        // Song detail has engagement wrap
        $songDetailPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/song-detail.php';
        $this->assertFileExists($songDetailPath);
        $songContent = file_get_contents($songDetailPath);
        $this->assertStringContainsString('fm-song-engagement-wrap', $songContent);
    }

    public function testHeroComponentNoBackdropCompactFallback(): void
    {
        $heroPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/hero.php';
        $this->assertFileExists($heroPath);
        $heroContent = file_get_contents($heroPath);

        // Verify compact hero class check
        $this->assertStringContainsString('has-compact-hero', $heroContent);
        $this->assertStringContainsString('is-compact-fallback', $heroContent);
    }

    public function testMediaRowRailControlsHiddenOnSingleItem(): void
    {
        $railPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/media-row.php';
        $this->assertFileExists($railPath);
        $railContent = file_get_contents($railPath);

        // Verify count check for controls inclusion
        $this->assertMatchesRegularExpression('/count\(\$items\)\s*>\s*1/', $railContent);
    }

    public function testFrontendJsAutoOverflowDetectionForRailControls(): void
    {
        $jsFiles = [
            APP_ROOT . '/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js',
            APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/assets/js/multimedia-frontend.js',
            APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/assets/js/frontend/multimedia-frontend.js',
        ];

        foreach ($jsFiles as $jsFile) {
            $this->assertFileExists($jsFile);
            $jsContent = file_get_contents($jsFile);
            $this->assertStringContainsString('track.scrollWidth > track.clientWidth + 5', $jsContent);
            $this->assertStringContainsString("controls.style.display = 'none'", $jsContent);
        }
    }
}
