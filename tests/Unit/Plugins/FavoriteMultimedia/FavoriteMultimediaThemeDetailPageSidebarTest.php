<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Theme\ThemeConfig;
use FavoriteCMS\Multimedia\Theme\ThemeManager;
use FavoriteCMS\Multimedia\Theme\ThemeTokenResolver;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaThemeDetailPageSidebarTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaFrontendController $frontendCtrl;
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

        $this->tempDb = sys_get_temp_dir() . '/fav_detail_sb_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        // Core schema
        $this->db->execute("CREATE TABLE settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");
        $this->db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(50), name VARCHAR(100), email VARCHAR(100), password VARCHAR(255), status VARCHAR(20), email_verified_at DATETIME, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE permissions (id INTEGER PRIMARY KEY, name VARCHAR(100), slug VARCHAR(100), description TEXT, group_name VARCHAR(50), created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));");
        $this->db->execute("CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, created_at DATETIME, PRIMARY KEY (role_id, permission_id));");

        FavoriteMultimediaPlugin::reset();
        ThemeManager::reset();
        Setting::clearCache();

        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->frontendCtrl = new MultimediaFrontendController($this->app);
    }

    protected function tearDown(): void
    {
        ThemeManager::reset();
        Setting::clearCache();
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    public function testSingleContentConfigDefaults(): void
    {
        $config = new ThemeConfig();
        $singleCfg = $config->get('single_content');

        $this->assertIsArray($singleCfg);
        $this->assertFalse($singleCfg['show_back_link']);
        $this->assertTrue($singleCfg['sidebar_enabled']);
        $this->assertSame('right', $singleCfg['sidebar_position']);
        $this->assertSame('320px', $singleCfg['sidebar_width']);
        $this->assertTrue($singleCfg['sidebar_sticky']);
        $this->assertSame('20px', $singleCfg['sidebar_sticky_offset']);
        $this->assertSame('surface', $singleCfg['sidebar_surface']);
        $this->assertTrue($singleCfg['enabled_types']['movie']);
        $this->assertTrue($singleCfg['enabled_types']['series']);
        $this->assertTrue($singleCfg['enabled_types']['episode']);
        $this->assertTrue($singleCfg['enabled_types']['song']);
        $this->assertTrue($singleCfg['enabled_types']['album']);
        $this->assertTrue($singleCfg['enabled_types']['artist']);
        $this->assertTrue($singleCfg['enabled_types']['playlist']);
        $this->assertTrue($singleCfg['blocks']['poster']);
        $this->assertTrue($singleCfg['blocks']['actions']);
        $this->assertTrue($singleCfg['blocks']['metadata']);
        $this->assertTrue($singleCfg['blocks']['download']);
    }

    public function testSingleContentConfigSanitization(): void
    {
        $config = new ThemeConfig([
            'single_content' => [
                'sidebar_position' => 'INVALID_POS',
                'sidebar_surface' => 'HACK_SURFACE',
                'show_back_link' => 1,
                'sidebar_enabled' => 'yes',
                'sidebar_sticky' => '1',
            ],
        ]);
        $clean = $config->sanitize();

        $this->assertSame('right', $clean->get('single_content', 'sidebar_position'));
        $this->assertSame('surface', $clean->get('single_content', 'sidebar_surface'));
        $this->assertTrue($clean->get('single_content', 'show_back_link'));
        $this->assertTrue($clean->get('single_content', 'sidebar_enabled'));
        $this->assertTrue($clean->get('single_content', 'sidebar_sticky'));
    }

    public function testThemeTokenResolverExportsSidebarProperties(): void
    {
        $config = new ThemeConfig([
            'single_content' => [
                'sidebar_width' => '300px',
                'sidebar_sticky_offset' => '24px',
            ],
        ]);
        $resolver = new ThemeTokenResolver($config);
        $vars = $resolver->toCssVariables();

        $this->assertArrayHasKey('--fm-sidebar-width', $vars);
        $this->assertSame('300px', $vars['--fm-sidebar-width']);
        $this->assertArrayHasKey('--fm-sidebar-sticky-offset', $vars);
        $this->assertSame('24px', $vars['--fm-sidebar-sticky-offset']);
    }

    public function testBackLinkDefaultDisabledAndConfigurableEnabled(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title' => 'BackLink Movie',
            'slug' => 'backlink-movie',
            'status' => 'published',
            'duration' => 7200,
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Default: show_back_link is false
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/movie/backlink-movie'], [], []);
        $res = $this->frontendCtrl->movie($req, 'backlink-movie');
        $html = (string)$res->getContent();
        $this->assertStringNotContainsString('&larr; Back to Movies', $html);

        // Enabled: show_back_link is true
        $mgr = ThemeManager::getInstance();
        $cfg = $mgr->getActiveConfig()->with('single_content', 'show_back_link', true);
        $mgr->saveConfig($cfg);

        $res2 = $this->frontendCtrl->movie($req, 'backlink-movie');
        $html2 = (string)$res2->getContent();
        $this->assertStringContainsString('fm-back-link', $html2);
        $this->assertStringContainsString('&larr; Back to Movies', $html2);
    }

    public function testStickySidebarRendersWithThemeTokensAndBlocks(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $mId = $this->db->insert('multimedia_movies', [
            'title' => 'Sidebar Movie',
            'slug' => 'sidebar-movie',
            'poster' => '/images/sidebar-poster.jpg',
            'release_year' => 2026,
            'duration' => 7500,
            'language' => 'English',
            'status' => 'published',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id' => $mId,
            'label' => '1080p Web',
            'url' => 'https://example.com/movie.mp4',
            'is_active' => 1,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/movie/sidebar-movie'], [], []);
        $res = $this->frontendCtrl->movie($req, 'sidebar-movie');
        $html = (string)$res->getContent();

        $this->assertStringContainsString('fm-sticky-sidebar', $html);
        $this->assertStringContainsString('surface-surface', $html);
        $this->assertStringContainsString('is-sticky', $html);
        $this->assertStringContainsString('fm-sidebar-block-poster', $html);
        $this->assertStringContainsString('fm-sidebar-block-actions', $html);
        $this->assertStringContainsString('fm-sidebar-block-download', $html);
        $this->assertStringContainsString('fm-sidebar-block-metadata', $html);
        $this->assertStringContainsString('1080p Web', $html);
        $this->assertStringContainsString('English', $html);
    }

    public function testSidebarPositionCanBeChangedToLeft(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title' => 'Left Sidebar Movie',
            'slug' => 'left-sidebar-movie',
            'status' => 'published',
            'duration' => 5000,
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mgr = ThemeManager::getInstance();
        $cfg = $mgr->getActiveConfig()->with('single_content', 'sidebar_position', 'left');
        $mgr->saveConfig($cfg);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/movie/left-sidebar-movie'], [], []);
        $res = $this->frontendCtrl->movie($req, 'left-sidebar-movie');
        $html = (string)$res->getContent();

        $this->assertStringContainsString('sidebar-left', $html);
        $this->assertStringContainsString('pos-left', $html);
    }

    public function testSidebarCanBeDisabled(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title' => 'No Sidebar Movie',
            'slug' => 'no-sidebar-movie',
            'status' => 'published',
            'duration' => 5000,
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mgr = ThemeManager::getInstance();
        $cfg = $mgr->getActiveConfig()->with('single_content', 'sidebar_enabled', false);
        $mgr->saveConfig($cfg);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/movie/no-sidebar-movie'], [], []);
        $res = $this->frontendCtrl->movie($req, 'no-sidebar-movie');
        $html = (string)$res->getContent();

        $this->assertStringNotContainsString('fm-sticky-sidebar', $html);
        $this->assertStringContainsString('no-sidebar', $html);
    }

    public function testSeriesDetailWithSidebarAndThemeTokens(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $sId = $this->db->insert('multimedia_series', [
            'title' => 'Test Series',
            'slug' => 'test-series',
            'status' => 'published',
            'release_year' => 2026,
            'language' => 'English',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_seasons', [
            'series_id' => $sId,
            'season_number' => 1,
            'title' => 'Season 1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/series/test-series'], [], []);
        $res = $this->frontendCtrl->seriesSingle($req, 'test-series');
        $html = (string)$res->getContent();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('fm-sticky-sidebar', $html);
        $this->assertStringContainsString('var(--fm-color-bg-surface)', $html);
        $this->assertStringNotContainsString('#1e293b', $html);
    }

    public function testEpisodeDetailWithSidebar(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $sId = $this->db->insert('multimedia_series', [
            'title' => 'Ep Series',
            'slug' => 'ep-series',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $seaId = $this->db->insert('multimedia_seasons', [
            'series_id' => $sId,
            'season_number' => 1,
            'title' => 'Season 1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_episodes', [
            'series_id' => $sId,
            'season_id' => $seaId,
            'episode_number' => 1,
            'title' => 'First Episode',
            'slug' => 'first-episode',
            'duration' => 2400,
            'status' => 'published',
            'access_mode' => 'inherit',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/episode/first-episode'], [], []);
        $res = $this->frontendCtrl->episode($req, 'first-episode');
        $html = (string)$res->getContent();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('fm-sticky-sidebar', $html);
        $this->assertStringContainsString('Ep Series', $html);
    }

    public function testSongDetailWithSidebar(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $artId = $this->db->insert('multimedia_artists', [
            'name' => 'Song Artist',
            'slug' => 'song-artist',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_songs', [
            'title' => 'Single Track',
            'slug' => 'single-track',
            'artist_id' => $artId,
            'duration' => 210,
            'status' => 'published',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/song/single-track'], [], []);
        $res = $this->frontendCtrl->song($req, 'single-track');
        $html = (string)$res->getContent();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('fm-sticky-sidebar', $html);
        $this->assertStringContainsString('Song Artist', $html);
    }

    public function testClickablePosterInDiscoveryCardWithoutNestedAnchors(): void
    {
        $item = [
            'content_type' => 'movie',
            'title' => 'Discovery Item',
            'poster' => 'https://example.com/poster.jpg',
            'detail_url' => '/movie/discovery-item',
            'meta_label' => '2026',
            'badge' => 'Free',
            'badge_class' => 'fmm-badge-free',
            'has_access' => true,
        ];

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/_discovery_card.php';
        $html = ob_get_clean();

        // 1. Poster must be clickable with link to detail URL
        $this->assertStringContainsString('class="fav-discovery-poster-link"', $html);
        $this->assertStringContainsString('href="/movie/discovery-item"', $html);

        // 2. Validate valid HTML5: no <a> tag inside another <a> tag
        // Matches an anchor opened inside an unclosed anchor
        $hasNestedAnchors = (bool)preg_match('/<a\b[^>]*>(?:(?!<\/a>).)*?<a\b/is', $html);
        $this->assertFalse($hasNestedAnchors, 'Found nested <a> tag inside another <a> tag');
    }

    public function testDownloadDeduplicationBetweenSidebarAndPage(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $mId = $this->db->insert('multimedia_movies', [
            'title' => 'Deduplicate Movie',
            'slug' => 'deduplicate-movie',
            'status' => 'published',
            'duration' => 3600,
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id' => $mId,
            'label' => 'Single Download Link',
            'url' => 'https://example.com/dl.mp4',
            'is_active' => 1,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/movie/deduplicate-movie'], [], []);
        $res = $this->frontendCtrl->movie($req, 'deduplicate-movie');
        $html = (string)$res->getContent();

        // Sidebar download block is present
        $this->assertStringContainsString('data-sidebar-download', $html);
        // The separate download button bar below the player should NOT be rendered when sidebar download is active
        $this->assertStringNotContainsString('<!-- Download Button Bar', $html);
    }
}
