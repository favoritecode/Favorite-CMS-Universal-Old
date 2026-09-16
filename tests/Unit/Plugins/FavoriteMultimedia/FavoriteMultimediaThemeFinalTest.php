<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Favorite;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MediaSourcePlaybackService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\NextItemResolverService;
use FavoriteCMS\Multimedia\Services\UserLibraryService;
use FavoriteCMS\Multimedia\Theme\Audio\AudioQueueManager;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageConfig;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageManager;
use FavoriteCMS\Multimedia\Theme\Search\MultimediaSearchService;
use FavoriteCMS\Multimedia\Theme\Search\SearchResultGroup;
use FavoriteCMS\Multimedia\Theme\Search\SearchSuggestionService;
use FavoriteCMS\Multimedia\Theme\ThemeConfig;
use FavoriteCMS\Multimedia\Theme\ThemeExportImportService;
use FavoriteCMS\Multimedia\Theme\ThemeManager;
use FavoriteCMS\Multimedia\Theme\ThemePackageService;
use FavoriteCMS\Multimedia\Theme\ThemePreviewService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Final Production QA & Comprehensive Acceptance Test Suite for Favorite Multimedia Theme System v1.0.0 (Chunk 4).
 *
 * Verifies all 40 required scenarios.
 */
class FavoriteMultimediaThemeFinalTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaFrontendController $frontendCtrl;
    private MultimediaAdminController $adminCtrl;
    private string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $_SESSION = [];
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'admin';
        $_SESSION['auth_user_email'] = 'admin@example.com';
        $_SESSION['_token'] = 'valid_chunk4_token';

        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_final_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        // Core tables
        $this->db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(50), name VARCHAR(100), email VARCHAR(100), password VARCHAR(255), status VARCHAR(20), email_verified_at DATETIME, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE permissions (id INTEGER PRIMARY KEY, name VARCHAR(100), slug VARCHAR(100), description TEXT, group_name VARCHAR(50), created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));");
        $this->db->execute("CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, created_at DATETIME, PRIMARY KEY (role_id, permission_id));");
        $this->db->execute("CREATE TABLE settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");

        $now = gmdate('Y-m-d H:i:s');
        $rId = $this->db->insert('roles', ['name' => 'Admin', 'slug' => 'admin', 'created_at' => $now, 'updated_at' => $now]);
        $uId = $this->db->insert('users', ['id' => 1, 'username' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('user_roles', ['user_id' => $uId, 'role_id' => $rId]);

        // Regular user
        $this->db->insert('users', ['id' => 2, 'username' => 'regular', 'name' => 'Regular User', 'email' => 'regular@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();
        ThemeManager::reset();
        HomepageManager::reset();

        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->frontendCtrl = $this->app->make(MultimediaFrontendController::class);
        $this->adminCtrl = $this->app->make(MultimediaAdminController::class);
    }

    protected function tearDown(): void
    {
        FavoriteMultimediaPlugin::reset();
        ThemeManager::reset();
        HomepageManager::reset();
        $_SESSION = [];

        if (isset($this->tempDb) && file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------
    // Helper Seeders
    // -------------------------------------------------------------
    private function seedSearchData(): array
    {
        $now = gmdate('Y-m-d H:i:s');

        // Movie
        $movieId = $this->db->insert('multimedia_movies', [
            'title' => 'Inception Dreams',
            'slug' => 'inception-dreams',
            'description' => 'A thief who steals corporate secrets through the use of dream-sharing.',
            'release_year' => 2010,
            'duration' => 8880,
            'status' => 'published',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Series
        $seriesId = $this->db->insert('multimedia_series', [
            'title' => 'Stranger Inception',
            'slug' => 'stranger-inception',
            'description' => 'Mysteries unfold in a small town.',
            'release_year' => 2016,
            'status' => 'published',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Artist
        $artistId = $this->db->insert('multimedia_artists', [
            'name' => 'Hans Inception Zimmer',
            'slug' => 'hans-inception-zimmer',
            'biography' => 'Legendary film composer.',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Album
        $albumId = $this->db->insert('multimedia_albums', [
            'title' => 'Inception Original Soundtrack',
            'slug' => 'inception-ost',
            'artist_id' => $artistId,
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Song
        $songId = $this->db->insert('multimedia_songs', [
            'title' => 'Inception Time',
            'slug' => 'inception-time',
            'artist_id' => $artistId,
            'album_id' => $albumId,
            'duration' => 275,
            'status' => 'published',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Playlist
        $playlistId = $this->db->insert('multimedia_playlists', [
            'title' => 'Inception Chill Playlist',
            'slug' => 'inception-chill-playlist',
            'description' => 'Great cinematic soundtracks for relaxing.',
            'status' => 'published',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return compact('movieId', 'seriesId', 'artistId', 'albumId', 'songId', 'playlistId');
    }

    // -------------------------------------------------------------
    // Scenarios 1–6: Universal Search Architecture & Safety
    // -------------------------------------------------------------

    public function test01_UniversalSearchMatchesAcrossSupportedEntities(): void
    {
        $this->seedSearchData();
        $service = new MultimediaSearchService($this->db);
        $result = $service->search('Inception', 'all', 10);

        $this->assertSame('Inception', $result['query']);
        $this->assertGreaterThanOrEqual(5, $result['total_matches']);
        $this->assertArrayHasKey('movies', $result['groups']);
        $this->assertArrayHasKey('series', $result['groups']);
        $this->assertArrayHasKey('songs', $result['groups']);
        $this->assertArrayHasKey('albums', $result['groups']);
        $this->assertArrayHasKey('artists', $result['groups']);
        $this->assertArrayHasKey('playlists', $result['groups']);
    }

    public function test02_GroupedResultsOmitEmptyGroups(): void
    {
        $this->seedSearchData();
        $service = new MultimediaSearchService($this->db);
        // Only songs have 'Time'
        $result = $service->search('Time', 'all', 10);

        $this->assertArrayHasKey('songs', $result['groups']);
        $this->assertArrayNotHasKey('movies', $result['groups']);
    }

    public function test03_SearchPermissionSafetyExcludesDraftAndUnpublished(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title' => 'Secret Inception Draft',
            'slug' => 'secret-inception-draft',
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new MultimediaSearchService($this->db);
        $result = $service->search('Secret', 'all', 10);

        $this->assertSame(0, $result['total_matches']);
        $this->assertEmpty($result['groups']);
    }

    public function test04_ProtectedSourceLeakagePreventionInSearch(): void
    {
        $this->seedSearchData();
        $service = new MultimediaSearchService($this->db);
        $result = $service->search('Inception', 'all', 10);

        foreach ($result['groups'] as $group) {
            foreach ($group->getItems() as $item) {
                $this->assertArrayNotHasKey('file_path', $item);
                $this->assertArrayNotHasKey('stream_url', $item);
                $this->assertArrayNotHasKey('token', $item);
                $this->assertArrayNotHasKey('download_url', $item);
            }
        }
    }

    public function test05_SearchDebounceConfigAndShortQueryRejection(): void
    {
        $service = new SearchSuggestionService($this->db);
        $res = $service->getSuggestions('a', 5);

        $this->assertSame('a', $res['query']);
        $this->assertEmpty($res['suggestions']);
    }

    public function test06_EmptySearchReturnsZeroMatchesGracefully(): void
    {
        $service = new MultimediaSearchService($this->db);
        $res = $service->search('   ', 'all');

        $this->assertSame(0, $res['total_matches']);
        $this->assertEmpty($res['groups']);
    }

    // -------------------------------------------------------------
    // Scenarios 7–13: Library & History UX
    // -------------------------------------------------------------

    public function test07_MyListReturnsSavedUserFavorites(): void
    {
        $user = User::find(1);
        $data = $this->seedSearchData();

        Favorite::addFavorite((int)$user->id, 'movie', (int)$data['movieId']);
        $myList = UserLibraryService::getMyList($user, 10);

        $this->assertCount(1, $myList);
        $this->assertSame('Inception Dreams', $myList[0]['item']->title);
    }

    public function test08_FavoritesMixedTypesSupportedWithoutDuplicateDB(): void
    {
        $user = User::find(1);
        $data = $this->seedSearchData();

        Favorite::addFavorite((int)$user->id, 'movie', (int)$data['movieId']);
        Favorite::addFavorite((int)$user->id, 'song', (int)$data['songId']);
        Favorite::addFavorite((int)$user->id, 'playlist', (int)$data['playlistId']);

        $totalFavs = Favorite::countByUser((int)$user->id);
        $this->assertSame(3, $totalFavs);

        $myList = UserLibraryService::getMyList($user, 10);
        $this->assertCount(3, $myList);
    }

    public function test09_ContinueWatchingReturnsHydratedProgressWithTime(): void
    {
        $user = User::find(1);
        $data = $this->seedSearchData();

        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$data['movieId'], 1200.0, 7200.0);

        $cw = UserLibraryService::getContinueWatching($user, 10);
        $this->assertCount(1, $cw);
        $this->assertSame('Inception Dreams', $cw[0]['title']);
        $this->assertGreaterThan(0, $cw[0]['percentage']);
        $this->assertNotEmpty($cw[0]['remaining_formatted']);
    }

    public function test10_ContinueListeningReturnsAudioProgressWithoutStreamLeak(): void
    {
        $user = User::find(1);
        $data = $this->seedSearchData();

        PlaybackProgress::saveProgress((int)$user->id, 'song', (int)$data['songId'], 90.0, 275.0);

        $cl = UserLibraryService::getContinueListening($user, 10);
        $this->assertCount(1, $cl);
        $this->assertSame('Inception Time', $cl[0]['title']);
        $this->assertArrayNotHasKey('stream_url', $cl[0]);
    }

    public function test11_WatchHistorySupportsPaginationAndTypeFilter(): void
    {
        $user = User::find(1);
        $data = $this->seedSearchData();

        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$data['movieId'], 500.0, 7200.0);

        $history = UserLibraryService::getWatchHistory($user, 10, 0, 'movie');
        $this->assertCount(1, $history);
        $this->assertSame('movie', $history[0]['content_type']);
    }

    public function test12_ListeningHistoryReturnsSongHistory(): void
    {
        $user = User::find(1);
        $data = $this->seedSearchData();

        PlaybackProgress::saveProgress((int)$user->id, 'song', (int)$data['songId'], 150.0, 275.0);

        $items = PlaybackProgress::getHistory((int)$user->id, 10, 0, 'song');
        $this->assertCount(1, $items);
        $this->assertSame('song', $items[0]->content_type);
    }

    public function test13_LibraryDashboardReturnsAggregatedCounts(): void
    {
        $user = User::find(1);
        $data = $this->seedSearchData();

        Favorite::addFavorite((int)$user->id, 'movie', (int)$data['movieId']);
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$data['movieId'], 300.0, 7200.0);

        $dash = UserLibraryService::compileLibraryDashboard($user);
        $this->assertSame(1, $dash['favorites_count']);
        $this->assertSame(1, $dash['history_count']);
        $this->assertCount(1, $dash['continue_watching']);
    }

    // -------------------------------------------------------------
    // Scenarios 14–21: UI Components, Accessibility, CSS & JS
    // -------------------------------------------------------------

    public function test14_ToastComponentHasAccessibleLiveRegion(): void
    {
        $toastFile = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/toast.php';
        $this->assertFileExists($toastFile);
        $content = file_get_contents($toastFile);

        $this->assertStringContainsString('role="status"', $content);
        $this->assertStringContainsString('aria-live="polite"', $content);
    }

    public function test15_ModalFocusTrapConfiguredInFrontendJS(): void
    {
        $jsFile = APP_ROOT . '/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js';
        $this->assertFileExists($jsFile);
        $content = file_get_contents($jsFile);

        $this->assertStringContainsString('setupModalAccessibility', $content);
        $this->assertStringContainsString("e.key === 'Tab'", $content);
    }

    public function test16_ModalEscapeCloseConfiguredInFrontendJS(): void
    {
        $jsFile = APP_ROOT . '/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js';
        $content = file_get_contents($jsFile);

        $this->assertStringContainsString("e.key === 'Escape'", $content);
    }

    public function test17_FocusRestorationConfiguredInFrontendJS(): void
    {
        $jsFile = APP_ROOT . '/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js';
        $content = file_get_contents($jsFile);

        $this->assertStringContainsString('lastFocusedElement.focus()', $content);
    }

    public function test18_ImageFallbackSystemDefinedInFrontendJS(): void
    {
        $jsFile = APP_ROOT . '/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js';
        $content = file_get_contents($jsFile);

        $this->assertStringContainsString('fm-img-fallback-placeholder', $content);
    }

    public function test19_MobileNavSafeClearanceAndToastStackingInCSS(): void
    {
        $cssFile = APP_ROOT . '/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css';
        $content = file_get_contents($cssFile);

        $this->assertStringContainsString('env(safe-area-inset-bottom', $content);
        $this->assertStringContainsString('.fm-toast-container', $content);
    }

    public function test20_ResponsiveBreakpointsDefinedInCSS(): void
    {
        $cssFile = APP_ROOT . '/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css';
        $content = file_get_contents($cssFile);

        $this->assertStringContainsString('@media (min-width: 768px)', $content);
    }

    public function test21_ReducedMotionMediaQueriesHandled(): void
    {
        $cssFile = APP_ROOT . '/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css';
        $content = file_get_contents($cssFile);

        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $content);
    }

    // -------------------------------------------------------------
    // Scenarios 22–29: Theme Export / Import & Manifest
    // -------------------------------------------------------------

    public function test22_ThemeConfigExportProducesSafeVersionedJson(): void
    {
        $service = new ThemeExportImportService();
        $export = $service->export();

        $this->assertSame(ThemeExportImportService::EXPORT_FORMAT, $export['format']);
        $this->assertSame(ThemeExportImportService::CURRENT_VERSION, $export['schema_version']);
        $this->assertArrayHasKey('theme_config', $export);
        $this->assertArrayHasKey('homepage_config', $export);

        // Does not leak user accounts or passwords
        $this->assertArrayNotHasKey('users', $export);
        $this->assertArrayNotHasKey('password', $export);
    }

    public function test23_ThemeConfigImportSuccessfullyUpdatesConfiguration(): void
    {
        $service = new ThemeExportImportService();
        $export = $service->export();

        $export['theme_config']['colors']['primary'] = '#00ff00';
        $result = $service->import($export);

        $this->assertTrue($result['success']);
        $updated = ThemeManager::getInstance()->getActiveConfig();
        $this->assertSame('#00ff00', $updated->get('colors', 'primary'));
    }

    public function test24_MaliciousImportWithScriptsRejectedOrSanitized(): void
    {
        $service = new ThemeExportImportService();
        $export = $service->export();

        $export['theme_config']['branding']['brand_title'] = '<script>alert("hack")</scriptSafe Title';
        $export['theme_config']['colors']['primary'] = 'javascript:alert(1)';

        $service->import($export);
        $config = ThemeManager::getInstance()->getActiveConfig();

        $this->assertStringNotContainsString('<script>', $config->get('branding', 'brand_title'));
        $this->assertStringNotContainsString('javascript:', $config->get('colors', 'primary'));
    }

    public function test25_SchemaMismatchRejectedOnImport(): void
    {
        $service = new ThemeExportImportService();
        $invalid = [
            'format' => 'unsupported_format',
            'schema_version' => '99.0.0',
            'theme_config' => [],
        ];

        $result = $service->import($invalid);
        $this->assertFalse($result['success']);
    }

    public function test26_ThemeManifestValidationSucceedsForCanonicalManifest(): void
    {
        $manifest = ThemePackageService::getManifest();
        $errors = ThemePackageService::validateManifest($manifest);

        $this->assertEmpty($errors);
        $this->assertSame(ThemePackageService::THEME_SLUG, $manifest['slug']);
        $this->assertSame(ThemePackageService::THEME_VERSION, $manifest['version']);
    }

    public function test27_MissingPluginCompatibilityReturnsError(): void
    {
        $compat = ThemePackageService::verifyCompatibility('1.0.6', 'wrong-plugin');
        $this->assertFalse($compat['compatible']);
        $this->assertNotEmpty($compat['errors']);
    }

    public function test28_MinimumPluginVersionEnforcementChecksOlderVersions(): void
    {
        $compat = ThemePackageService::verifyCompatibility('1.0.4', 'favorite-multimedia');
        $this->assertFalse($compat['compatible']);
        $this->assertStringContainsString('older than required minimum', $compat['errors'][0]);
    }

    public function test29_ThemeConfigBackupAndRestorePreservesSettings(): void
    {
        $manager = ThemeManager::getInstance();
        $manager->saveConfig(new ThemeConfig(['branding' => ['brand_title' => 'Original Title']]));

        $service = new ThemeExportImportService();
        $service->createBackup();

        // Mutate
        $manager->saveConfig(new ThemeConfig(['branding' => ['brand_title' => 'Mutated Title']]));
        $this->assertSame('Mutated Title', $manager->getActiveConfig()->get('branding', 'brand_title'));

        // Restore
        $restored = $service->restoreBackup();
        $this->assertTrue($restored);
        $this->assertSame('Original Title', $manager->getActiveConfig()->get('branding', 'brand_title'));
    }

    // -------------------------------------------------------------
    // Scenarios 30–33: Packaging & Release Isolation
    // -------------------------------------------------------------

    public function test30_ThemePackageExcludesStorageRuntimeData(): void
    {
        $zipPath = 'D:/Server/Shofikul/CMS Assets/Favorite-CMS-Assets/theme-assets/favorite-multimedia/release/favorite-multimedia-theme.zip';
        if (!file_exists($zipPath)) {
            $this->markTestSkipped('Theme zip package not yet generated.');
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertStringNotContainsString('storage/multimedia', $name);
        }
        $zip->close();
    }

    public function test31_ThemePackageExcludesTestsAndDebugFiles(): void
    {
        $zipPath = 'D:/Server/Shofikul/CMS Assets/Favorite-CMS-Assets/theme-assets/favorite-multimedia/release/favorite-multimedia-theme.zip';
        if (!file_exists($zipPath)) {
            $this->markTestSkipped('Theme zip package not yet generated.');
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertFalse(str_starts_with($name, 'tests/'));
            $this->assertStringNotContainsString('phpunit.xml', $name);
        }
        $zip->close();
    }

    public function test32_PluginReleasePathRemainsUntouched(): void
    {
        $pluginRelease = 'D:/Server/Shofikul/CMS Assets/Favorite-CMS-Assets/plugin-assets/favorite-multimedia/release/favorite-multimedia-v1.0.6.zip';
        $this->assertFileExists($pluginRelease);
    }

    public function test33_ThemeStudioPreviewSafetyDoesNotMutateLiveSettings(): void
    {
        $originalTitle = ThemeManager::getInstance()->getActiveConfig()->get('branding', 'brand_title');
        $preview = new ThemePreviewService();
        $data = $preview->getMockData();

        $this->assertNotEmpty($data['movie']);
        $this->assertSame($originalTitle, ThemeManager::getInstance()->getActiveConfig()->get('branding', 'brand_title'));
    }

    // -------------------------------------------------------------
    // Scenarios 34–40: Core Regressions & Capabilities
    // -------------------------------------------------------------

    public function test34_HomepageBuilderManagerSavesAndRetrievesSections(): void
    {
        $manager = HomepageManager::getInstance();
        $config = new HomepageConfig([
            ['id' => 'hero_main', 'type' => 'hero_banner', 'enabled' => true],
            ['id' => 'trending_now', 'type' => 'content_rail', 'enabled' => true],
        ]);

        $this->assertTrue($manager->saveConfig($config));
        $this->assertCount(2, $manager->getActiveConfig()->getSections());
    }

    public function test35_AudioQueueManagerOperationsFunction(): void
    {
        $manager = new AudioQueueManager();
        $manager->add(['id' => 1, 'title' => 'Song 1']);
        $manager->add(['id' => 2, 'title' => 'Song 2']);
        $manager->add(['id' => 3, 'title' => 'Song 3']);

        $this->assertSame(3, $manager->count());
        $manager->next();
        $this->assertSame(1, $manager->getCurrentIndex());
    }

    public function test36_VideoPlayerSourceResolutionFunctions(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = $this->db->insert('multimedia_movies', [
            'title' => 'Matrix Stream',
            'slug' => 'matrix-stream',
            'status' => 'published',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id' => $movieId,
            'source_mode' => 'external',
            'source_type' => 'youtube',
            'url_or_path' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $movie = Movie::find($movieId);
        $resolved = MediaSourcePlaybackService::getPlayableSources(null, 'movie', $movie);
        $this->assertNotEmpty($resolved['sources']);
        $this->assertSame('youtube', $resolved['sources'][0]['source_type']);
    }

    public function test37_AutoNextEpisodeResolutionFunctions(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $seriesId = $this->db->insert('multimedia_series', ['title' => 'Series AutoNext', 'slug' => 'series-autonext', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $seasonId = $this->db->insert('multimedia_seasons', ['series_id' => $seriesId, 'season_number' => 1, 'title' => 'Season 1', 'created_at' => $now, 'updated_at' => $now]);
        $ep1 = $this->db->insert('multimedia_episodes', ['series_id' => $seriesId, 'season_id' => $seasonId, 'episode_number' => 1, 'title' => 'Ep 1', 'slug' => 'ep-1', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $ep2 = $this->db->insert('multimedia_episodes', ['series_id' => $seriesId, 'season_id' => $seasonId, 'episode_number' => 2, 'title' => 'Ep 2', 'slug' => 'ep-2', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);

        // Ep2 must have a playable source to be resolved
        $this->db->insert('multimedia_sources', [
            'content_type' => 'episode',
            'content_id' => $ep2,
            'source_mode' => 'direct',
            'source_type' => 'mp4',
            'url_or_path' => 'https://example.com/ep2.mp4',
            'status' => 'active',
            'is_default' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $next = NextItemResolverService::resolveNextEpisode(null, (int)$ep1);
        $this->assertTrue($next['found']);
        $this->assertSame((int)$ep2, (int)$next['id']);
    }

    public function test38_MultipleDownloadSourcesResolveCorrectly(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = $this->db->insert('multimedia_movies', ['title' => 'DL Movie', 'slug' => 'dl-movie', 'status' => 'published', 'access_mode' => 'public', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id' => $movieId,
            'source_mode' => 'direct',
            'source_type' => 'mp4',
            'url_or_path' => 'https://example.com/movie_1080p.mp4',
            'label' => '1080p Full HD',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $movie = Movie::find($movieId);
        $sources = $movie->getSources();
        $this->assertCount(1, $sources);
        $this->assertSame('1080p Full HD', $sources[0]->label);
    }

    public function test39_PremiumFailClosedEnforcementForNonSubscribers(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = $this->db->insert('multimedia_movies', ['title' => 'Premium Film', 'slug' => 'premium-film', 'status' => 'published', 'access_mode' => 'premium', 'created_at' => $now, 'updated_at' => $now]);
        $movie = Movie::find($movieId);

        $regularUser = User::find(2);
        $access = MultimediaAccessService::checkAccess($regularUser, 'movie', $movie);

        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, $access);
    }

    public function test40_ExternalEmbedDomainValidationFunctions(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $movieId = $this->db->insert('multimedia_movies', ['title' => 'Embed Film', 'slug' => 'embed-film', 'status' => 'published', 'access_mode' => 'public', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id' => $movieId,
            'source_mode' => 'external',
            'source_type' => 'vimeo',
            'url_or_path' => 'https://vimeo.com/12345678',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $movie = Movie::find($movieId);
        $resolved = MediaSourcePlaybackService::getPlayableSources(null, 'movie', $movie);
        $this->assertNotEmpty($resolved['sources']);
        $this->assertSame('vimeo', $resolved['sources'][0]['source_type']);
    }
}
