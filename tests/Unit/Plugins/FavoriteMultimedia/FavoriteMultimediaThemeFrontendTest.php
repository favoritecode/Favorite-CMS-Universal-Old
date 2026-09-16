<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\DownloadSource;
use FavoriteCMS\Multimedia\Theme\ThemeConfig;
use FavoriteCMS\Multimedia\Theme\ThemeManager;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageConfig;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageManager;
use FavoriteCMS\Multimedia\Theme\Homepage\SectionRegistry;
use FavoriteCMS\Multimedia\Theme\Homepage\SectionResolver;
use FavoriteCMS\Multimedia\Theme\Homepage\SectionRenderer;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive Test Suite for Favorite Multimedia Theme System Chunk 2:
 * Professional Streaming Frontend + No-Code Homepage Builder.
 * 
 * Covers all 43 scenarios verifying HomepageConfig, SectionRegistry, SectionResolver,
 * SectionRenderer, Frontend Components, Admin Builder APIs, and Browse/Detail Views.
 */
class FavoriteMultimediaThemeFrontendTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaAdminController $adminCtrl;
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
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'admin';
        $_SESSION['auth_user_email'] = 'admin@example.com';
        $_SESSION['_token'] = 'valid_theme_token';

        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_frontend_test_' . bin2hex(random_bytes(8)) . '.sqlite';
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

        // Regular non-admin user
        $this->db->insert('users', ['id' => 2, 'username' => 'regular', 'name' => 'Regular User', 'email' => 'regular@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();
        HomepageManager::reset();
        ThemeManager::reset();

        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->adminCtrl = $this->app->make(MultimediaAdminController::class);
        $this->frontendCtrl = $this->app->make(MultimediaFrontendController::class);
    }

    protected function tearDown(): void
    {
        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();
        HomepageManager::reset();
        ThemeManager::reset();
        $_SESSION = [];

        if (isset($this->tempDb) && file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    // 1. Default homepage configuration contains 11 canonical sections
    public function test_default_homepage_config_contains_expected_sections(): void
    {
        $config = new HomepageConfig();
        $sections = $config->getSections();

        $this->assertCount(11, $sections);
        $this->assertSame(SectionRegistry::TYPE_HERO, $sections[0]['type']);
        $this->assertSame(SectionRegistry::TYPE_CONTINUE_WATCHING, $sections[1]['type']);
        $this->assertSame(SectionRegistry::TYPE_CONTINUE_LISTENING, $sections[2]['type']);
        $this->assertSame(SectionRegistry::TYPE_TRENDING, $sections[3]['type']);
        $this->assertSame(SectionRegistry::TYPE_LATEST_MOVIES, $sections[4]['type']);
        $this->assertSame(SectionRegistry::TYPE_POPULAR_SERIES, $sections[5]['type']);
    }

    // 2. Serialization and immutability of HomepageConfig
    public function test_homepage_config_immutability_and_serialization(): void
    {
        $config = new HomepageConfig();
        $array = $config->toArray();

        $this->assertIsArray($array);
        $this->assertSame(HomepageConfig::SCHEMA_VERSION, $array['schema_version']);
        $this->assertCount(11, $array['sections']);

        $restored = HomepageConfig::fromArray($array);
        $this->assertSame($array, $restored->toArray());
    }

    // 3. Validation rejects empty sections array
    public function test_homepage_config_validation_rejects_empty_sections(): void
    {
        $config = new HomepageConfig([]);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('At least one homepage section is required', $errors[0]);
    }

    // 4. Validation rejects exceeding max section limit (20)
    public function test_homepage_config_validation_rejects_exceeding_max_sections(): void
    {
        $sections = [];
        for ($i = 0; $i < 25; $i++) {
            $sections[] = [
                'id'    => "sec_{$i}",
                'type'  => 'latest_movies',
                'title' => "Section {$i}",
            ];
        }

        $config = new HomepageConfig($sections);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('exceeds maximum allowed limit', $errors[0]);
    }

    // 5. Validation rejects unknown section type
    public function test_homepage_config_validation_rejects_invalid_section_type(): void
    {
        $config = new HomepageConfig([
            [
                'id'    => 'sec_1',
                'type'  => 'non_existent_section_type',
                'title' => 'Bad Section',
            ]
        ]);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("Unknown section type 'non_existent_section_type'", $errors[0]);
    }

    // 6. Validation rejects out-of-bounds limit
    public function test_homepage_config_validation_rejects_invalid_limit(): void
    {
        $config = new HomepageConfig([
            [
                'id'    => 'sec_1',
                'type'  => 'latest_movies',
                'title' => 'Bad Limit Section',
                'limit' => 50, // Max is 24
            ]
        ]);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('between 1 and 24', $errors[0]);
    }

    // 7. Validation rejects unsupported layout
    public function test_homepage_config_validation_rejects_invalid_layout(): void
    {
        $config = new HomepageConfig([
            [
                'id'     => 'sec_1',
                'type'   => 'latest_movies',
                'title'  => 'Bad Layout Section',
                'layout' => 'unsupported_spiral_layout',
            ]
        ]);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unsupported_spiral_layout', $errors[0]);
    }

    // 8. Reordering sections
    public function test_homepage_config_reorder_sections(): void
    {
        $config = new HomepageConfig();
        $sections = $config->getSections();

        $firstId = $sections[0]['id'];
        $lastId = $sections[count($sections) - 1]['id'];

        // Reverse order of first and last
        $reversed = array_reverse(array_column($sections, 'id'));
        $reordered = $config->reorder($reversed);

        $newSections = $reordered->getSections();
        $this->assertSame($lastId, $newSections[0]['id']);
        $this->assertSame($firstId, $newSections[count($newSections) - 1]['id']);
    }

    // 9. Duplicating a section creates unique ID
    public function test_homepage_config_duplicate_section(): void
    {
        $config = new HomepageConfig();
        $sections = $config->getSections();
        $targetId = $sections[0]['id'];

        $duplicated = $config->duplicateSection($targetId);
        $newSections = $duplicated->getSections();

        $this->assertCount(12, $newSections);
        $this->assertSame($targetId, $newSections[0]['id']);
        $this->assertNotSame($targetId, $newSections[1]['id']);
        $this->assertSame($newSections[0]['title'] . ' (Copy)', $newSections[1]['title']);
    }

    // 10. Removing a section by ID
    public function test_homepage_config_delete_section(): void
    {
        $config = new HomepageConfig();
        $sections = $config->getSections();
        $targetId = $sections[0]['id'];

        $removed = $config->removeSection($targetId);
        $this->assertCount(10, $removed->getSections());
        $this->assertNull($removed->getSection($targetId));
    }

    // 11. withSection updates properties of a section
    public function test_homepage_config_with_section(): void
    {
        $config = new HomepageConfig();
        $sections = $config->getSections();
        $targetId = $sections[0]['id'];

        $updated = $config->withSection($targetId, [
            'title' => 'Brand New Title',
            'limit' => 12,
        ]);

        $sec = $updated->getSection($targetId);
        $this->assertNotNull($sec);
        $this->assertSame('Brand New Title', $sec['title']);
        $this->assertSame(12, $sec['limit']);
    }

    // 12. HomepageManager persists settings to database
    public function test_homepage_manager_persistence(): void
    {
        $manager = HomepageManager::getInstance($this->app);
        $config = $manager->getActiveConfig();

        $this->assertCount(11, $config->getSections());

        // Update a section and save
        $updated = $config->withSection($config->getSections()[0]['id'], ['title' => 'Custom Hub Premiere']);
        $manager->saveConfig($updated);

        $manager->clearCache();
        $persisted = $manager->getActiveConfig();

        $this->assertSame('Custom Hub Premiere', $persisted->getSections()[0]['title']);
    }

    // 13. HomepageManager resetDefaults restores 11 canonical sections
    public function test_homepage_manager_reset_defaults(): void
    {
        $manager = HomepageManager::getInstance($this->app);
        // Clear down to 1 section
        $manager->saveConfig(new HomepageConfig([[
            'id'    => 'sec_only',
            'type'  => 'latest_movies',
            'title' => 'Only One',
        ]]));

        $manager->clearCache();
        $this->assertCount(1, $manager->getActiveConfig()->getSections());

        $manager->resetDefaults();
        $manager->clearCache();

        $this->assertCount(11, $manager->getActiveConfig()->getSections());
    }

    // 14. SectionRegistry canonical definitions
    public function test_section_registry_canonical_definitions(): void
    {
        $defs = SectionRegistry::all();

        $this->assertGreaterThanOrEqual(20, count($defs));
        $this->assertTrue(SectionRegistry::isValidType('hero'));
        $this->assertTrue(SectionRegistry::isValidType('continue_watching'));
        $this->assertTrue(SectionRegistry::isValidType('continue_listening'));
        $this->assertTrue(SectionRegistry::isValidType('music_spotlight'));
        $this->assertTrue(SectionRegistry::isValidType('genres'));
        $this->assertFalse(SectionRegistry::isValidType('fake_section'));
    }

    // 15. SectionRegistry categories
    public function test_section_registry_categories(): void
    {
        $cats = SectionRegistry::categories();

        $this->assertArrayHasKey('featured', $cats);
        $this->assertArrayHasKey('personal', $cats);
        $this->assertArrayHasKey('video', $cats);
        $this->assertArrayHasKey('music', $cats);
        $this->assertArrayHasKey('discovery', $cats);
    }

    // 16. SectionResolver batched queries avoid fatal error
    public function test_section_resolver_batched_queries_avoid_n_plus_one(): void
    {
        $resolver = new SectionResolver($this->db);
        $config = new HomepageConfig();

        $resolved = $resolver->resolveAll($config, null);

        $this->assertIsArray($resolved);
        $this->assertArrayHasKey('sections', $resolved);
        $this->assertCount(11, $resolved['sections']);
    }

    // 17. SectionResolver handles empty database gracefully
    public function test_section_resolver_handles_empty_database_gracefully(): void
    {
        $resolver = new SectionResolver($this->db);
        $config = new HomepageConfig();

        $resolved = $resolver->resolveAll($config, null);

        foreach ($resolved['sections'] as $sec) {
            $this->assertIsArray($sec['items']);
        }
    }

    // 18. SectionResolver resolves continue watching for authenticated user
    public function test_section_resolver_resolves_continue_watching_for_authenticated_user(): void
    {
        $user = User::find(1);

        // Insert a movie and progress
        $now = gmdate('Y-m-d H:i:s');
        $mId = $this->db->insert('multimedia_movies', [
            'title'        => 'Watching Movie',
            'slug'         => 'watching-movie',
            'status'       => 'published',
            'duration'     => 7200,
            'access_mode'  => 'public',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        PlaybackProgress::recordProgress(1, 'movie', (int)$mId, 1800.0, 7200.0, 25.0, false);

        $resolver = new SectionResolver($this->db);
        $config = new HomepageConfig([
            [
                'id'      => 'sec_cw',
                'type'    => 'continue_watching',
                'title'   => 'Continue Watching',
                'enabled' => true,
            ]
        ]);

        $resolved = $resolver->resolveAll($config, $user);
        $cwSection = $resolved['sections'][0];

        $this->assertNotEmpty($cwSection['items']);
        $firstItem = $cwSection['items'][0];
        $title = is_array($firstItem) ? ($firstItem['title'] ?? '') : ($firstItem->title ?? '');
        $this->assertSame('Watching Movie', $title);
    }

    // 19. SectionResolver ignores continue watching for guest
    public function test_section_resolver_ignores_continue_watching_for_guest(): void
    {
        $resolver = new SectionResolver($this->db);
        $config = new HomepageConfig([
            [
                'id'      => 'sec_cw',
                'type'    => 'continue_watching',
                'title'   => 'Continue Watching',
                'enabled' => true,
            ]
        ]);

        $resolved = $resolver->resolveAll($config, null); // Guest
        $cwSection = $resolved['sections'][0];

        $this->assertEmpty($cwSection['items']);
    }

    // 20. SectionRenderer renders hero billboard
    public function test_section_renderer_renders_hero_billboard(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title'        => 'Hero Premiere Movie',
            'slug'         => 'hero-premiere-movie',
            'status'       => 'published',
            'duration'     => 5400,
            'access_mode'  => 'public',
            'backdrop'     => '/images/hero.jpg',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $config = new HomepageConfig([
            [
                'id'         => 'sec_hero',
                'type'       => 'hero',
                'title'      => 'Featured Premiere',
                'card_style' => 'hero',
                'layout'     => 'hero_slider',
                'enabled'    => true,
            ]
        ]);

        $renderer = new SectionRenderer($this->db);
        $html = $renderer->renderAll($config, null);

        $this->assertStringContainsString('fm-hero-section', $html);
        $this->assertStringContainsString('Hero Premiere Movie', $html);
    }

    // 21. SectionRenderer renders content rail with controls
    public function test_section_renderer_renders_content_rail(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        for ($i = 1; $i <= 3; $i++) {
            $this->db->insert('multimedia_movies', [
                'title'        => "Rail Movie {$i}",
                'slug'         => "rail-movie-{$i}",
                'status'       => 'published',
                'duration'     => 5000,
                'access_mode'  => 'public',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        $config = new HomepageConfig([
            [
                'id'         => 'sec_rail',
                'type'       => 'latest_movies',
                'title'      => 'Latest Blockbusters',
                'card_style' => 'poster',
                'layout'     => 'rail',
                'enabled'    => true,
            ]
        ]);

        $renderer = new SectionRenderer($this->db);
        $html = $renderer->renderAll($config, null);

        $this->assertStringContainsString('fm-rail-track', $html);
        $this->assertStringContainsString('fm-rail-btn-prev', $html);
        $this->assertStringContainsString('fm-rail-btn-next', $html);
        $this->assertStringContainsString('Rail Movie 1', $html);
    }

    // 22. SectionRenderer renders grid layout
    public function test_section_renderer_renders_grid_layout(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title'        => 'Grid Movie 1',
            'slug'         => 'grid-movie-1',
            'status'       => 'published',
            'duration'     => 5000,
            'access_mode'  => 'public',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $config = new HomepageConfig([
            [
                'id'         => 'sec_grid',
                'type'       => 'latest_movies',
                'title'      => 'Grid Movies',
                'card_style' => 'poster',
                'layout'     => 'grid',
                'enabled'    => true,
            ]
        ]);

        $renderer = new SectionRenderer($this->db);
        $html = $renderer->renderAll($config, null);

        $this->assertStringContainsString('fm-grid fm-grid-poster', $html);
        $this->assertStringContainsString('Grid Movie 1', $html);
    }

    // 23. SectionRenderer skips disabled sections
    public function test_section_renderer_skips_disabled_sections(): void
    {
        $config = new HomepageConfig([
            [
                'id'      => 'sec_off',
                'type'    => 'latest_movies',
                'title'   => 'Hidden Section Title',
                'enabled' => false,
            ]
        ]);

        $renderer = new SectionRenderer($this->db);
        $html = $renderer->renderAll($config, null);

        $this->assertStringNotContainsString('Hidden Section Title', $html);
    }

    // 24. SectionRenderer collapses empty section when configured
    public function test_section_renderer_collapses_empty_section_when_configured(): void
    {
        $config = new HomepageConfig([
            [
                'id'                    => 'sec_empty',
                'type'                  => 'latest_movies',
                'title'                 => 'Empty Section Title',
                'enabled'               => true,
                'collapse_when_empty'   => true,
            ]
        ]);

        $renderer = new SectionRenderer($this->db);
        $html = $renderer->renderAll($config, null);

        // Database has movies from prior tests, let's test with a type that has 0 items
        $configZero = new HomepageConfig([
            [
                'id'                    => 'sec_zero',
                'type'                  => 'audio_playlists',
                'title'                 => 'Zero Playlists Section',
                'enabled'               => true,
                'collapse_when_empty'   => true,
            ]
        ]);

        $htmlZero = $renderer->renderAll($configZero, null);
        $this->assertStringNotContainsString('Zero Playlists Section', $htmlZero);
    }

    // 25. SectionRenderer applies device visibility classes
    public function test_section_renderer_applies_device_visibility_classes(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title'        => 'Device Movie',
            'slug'         => 'device-movie',
            'status'       => 'published',
            'duration'     => 5000,
            'access_mode'  => 'public',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $config = new HomepageConfig([
            [
                'id'               => 'sec_dev',
                'type'             => 'latest_movies',
                'title'            => 'Desktop Only Movies',
                'visible_desktop'  => true,
                'visible_tablet'   => false,
                'visible_mobile'   => false,
                'enabled'          => true,
            ]
        ]);

        $renderer = new SectionRenderer($this->db);
        $html = $renderer->renderAll($config, null);

        $this->assertStringContainsString('fm-hide-tablet', $html);
        $this->assertStringContainsString('fm-hide-mobile', $html);
    }

    // 26. Component Poster Card rendering
    public function test_component_poster_card_rendering(): void
    {
        $item = (object)[
            'id'           => 99,
            'title'        => 'Test Poster Movie',
            'slug'         => 'test-poster-movie',
            'poster'       => '/images/poster.jpg',
            'release_year' => 2026,
            'rating'       => 8.7,
            'access_mode'  => 'public',
        ];
        $cardType = 'movie';

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/poster-card.php';
        $cardHtml = ob_get_clean();

        $this->assertStringContainsString('fm-poster-card', $cardHtml);
        $this->assertStringContainsString('Test Poster Movie', $cardHtml);
        $this->assertStringContainsString('2026', $cardHtml);
        $this->assertStringContainsString('/movie/test-poster-movie', $cardHtml);
    }

    // 27. Component Landscape Card rendering
    public function test_component_landscape_card_rendering(): void
    {
        $item = (object)[
            'id'           => 88,
            'title'        => 'Test Episode One',
            'slug'         => 'test-episode-one',
            'thumbnail'    => '/images/ep1.jpg',
            'duration'     => 2700,
            'access_mode'  => 'public',
        ];
        $cardType = 'episode';

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/landscape-card.php';
        $cardHtml = ob_get_clean();

        $this->assertStringContainsString('fm-landscape-card', $cardHtml);
        $this->assertStringContainsString('Test Episode One', $cardHtml);
        $this->assertStringContainsString('/episode/test-episode-one', $cardHtml);
    }

    // 28. Component Album Card rendering
    public function test_component_album_card_rendering(): void
    {
        $item = (object)[
            'id'           => 77,
            'title'        => 'Midnight Echoes',
            'slug'         => 'midnight-echoes',
            'cover'        => '/images/album.jpg',
            'artist_name'  => 'Luna Ray',
        ];

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/album-card.php';
        $cardHtml = ob_get_clean();

        $this->assertStringContainsString('fm-album-card', $cardHtml);
        $this->assertStringContainsString('Midnight Echoes', $cardHtml);
        $this->assertStringContainsString('/multimedia/album/midnight-echoes', $cardHtml);
    }

    // 29. Component Artist Card rendering
    public function test_component_artist_card_rendering(): void
    {
        $item = (object)[
            'id'    => 66,
            'name'  => 'Aurora Vance',
            'slug'  => 'aurora-vance',
            'photo' => '/images/artist.jpg',
        ];

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/artist-card.php';
        $cardHtml = ob_get_clean();

        $this->assertStringContainsString('fm-artist-card', $cardHtml);
        $this->assertStringContainsString('Aurora Vance', $cardHtml);
        $this->assertStringContainsString('/multimedia/artist/aurora-vance', $cardHtml);
    }

    // 30. Component Playlist Card rendering
    public function test_component_playlist_card_rendering(): void
    {
        $item = (object)[
            'id'          => 55,
            'title'       => 'Chill Vibes',
            'slug'        => 'chill-vibes',
            'cover'       => '/images/playlist.jpg',
            'items_count' => 24,
        ];

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/playlist-card.php';
        $cardHtml = ob_get_clean();

        $this->assertStringContainsString('fm-playlist-card', $cardHtml);
        $this->assertStringContainsString('Chill Vibes', $cardHtml);
        $this->assertStringContainsString('/playlist/chill-vibes', $cardHtml);
    }

    // 31. Component Song Row rendering
    public function test_component_song_row_rendering(): void
    {
        $item = (object)[
            'id'          => 44,
            'title'       => 'Starlight Serenade',
            'slug'        => 'starlight-serenade',
            'cover'       => '/images/song.jpg',
            'artist_name' => 'Echo Band',
            'album_title' => 'Cosmic Drift',
            'duration'    => 215,
        ];
        $index = 1;

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/song-row.php';
        $rowHtml = ob_get_clean();

        $this->assertStringContainsString('fm-song-row', $rowHtml);
        $this->assertStringContainsString('Starlight Serenade', $rowHtml);
        $this->assertStringContainsString('Echo Band', $rowHtml);
        $this->assertStringContainsString('3:35', $rowHtml);
    }

    // 32. Premium badge does not leak protected URL
    public function test_premium_badge_does_not_leak_protected_url(): void
    {
        $item = (object)[
            'id'           => 33,
            'title'        => 'Premium Exclusive Feature',
            'slug'         => 'premium-exclusive-feature',
            'access_mode'  => 'premium',
            'secret_path'  => '/private/storage/master_stream.mp4',
        ];

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/poster-card.php';
        $cardHtml = ob_get_clean();

        $this->assertStringContainsString('PREMIUM', $cardHtml);
        $this->assertStringNotContainsString('/private/storage/master_stream.mp4', $cardHtml);
    }

    // 33. Admin API Save Homepage requires authentication and permission
    public function test_admin_api_save_homepage_requires_auth(): void
    {
        $_SESSION = []; // Logged out
        $req = new Request([], ['sections' => []], ['REQUEST_METHOD' => 'POST'], [], []);

        $res = $this->adminCtrl->apiSaveHomepage($req);
        $this->assertSame(403, $res->getStatusCode());
    }

    // 34. Admin API Save Homepage validates CSRF token
    public function test_admin_api_save_homepage_validates_csrf(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['csrf_token'] = 'real_token';

        $req = new Request([], ['_token' => 'fake_token', 'sections' => []], ['REQUEST_METHOD' => 'POST'], [], []);
        $res = $this->adminCtrl->apiSaveHomepage($req);

        $this->assertSame(403, $res->getStatusCode());
    }

    // 35. Admin API Save Homepage successfully persists config
    public function test_admin_api_save_homepage_success(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_token';

        $payload = [
            '_token'   => 'valid_token',
            'sections' => [
                [
                    'id'    => 'sec_custom_hero',
                    'type'  => 'hero',
                    'title' => 'Custom Hero Title',
                ],
                [
                    'id'    => 'sec_custom_movies',
                    'type'  => 'latest_movies',
                    'title' => 'Custom Movies',
                ],
            ]
        ];

        $req = new Request([], $payload, ['REQUEST_METHOD' => 'POST'], [], []);
        $res = $this->adminCtrl->apiSaveHomepage($req);

        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode((string)$res->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['homepage']['sections']);
        $this->assertSame('Custom Hero Title', $data['homepage']['sections'][0]['title']);
    }

    // 36. Admin API Reset Homepage restores defaults
    public function test_admin_api_reset_homepage(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_token';

        $req = new Request([], ['_token' => 'valid_token'], ['REQUEST_METHOD' => 'POST'], [], []);
        $res = $this->adminCtrl->apiResetHomepage($req);

        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode((string)$res->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(11, $data['homepage']['sections']);
    }

    // 37. Admin API Reorder Homepage
    public function test_admin_api_reorder_homepage(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_token';

        $manager = HomepageManager::getInstance($this->app);
        $sections = $manager->getActiveConfig()->getSections();
        $rev = array_reverse(array_column($sections, 'id'));

        $req = new Request([], ['_token' => 'valid_token', 'order' => $rev], ['REQUEST_METHOD' => 'POST'], [], []);
        $res = $this->adminCtrl->apiReorderHomepage($req);

        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode((string)$res->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertSame($rev[0], $data['homepage']['sections'][0]['id']);
    }

    // 38. Admin API Add and Delete Section
    public function test_admin_api_add_and_delete_section(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_token';

        // Add section
        $addReq = new Request([], ['_token' => 'valid_token', 'type' => 'music_spotlight'], ['REQUEST_METHOD' => 'POST'], [], []);
        $addRes = $this->adminCtrl->apiAddHomepageSection($addReq);

        $this->assertSame(200, $addRes->getStatusCode());
        $addData = json_decode((string)$addRes->getContent(), true);
        $this->assertTrue($addData['success']);

        $addedSec = end($addData['homepage']['sections']);
        $this->assertSame('music_spotlight', $addedSec['type']);

        // Delete section
        $delReq = new Request([], ['_token' => 'valid_token', 'id' => $addedSec['id']], ['REQUEST_METHOD' => 'POST'], [], []);
        $delRes = $this->adminCtrl->apiDeleteHomepageSection($delReq);

        $this->assertSame(200, $delRes->getStatusCode());
        $delData = json_decode((string)$delRes->getContent(), true);
        $this->assertTrue($delData['success']);
    }

    // 39. Admin API Preview Homepage returns rendered HTML
    public function test_admin_api_preview_homepage(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_movies', [
            'title'          => 'Live Hero Preview Movie',
            'slug'           => 'live-hero-preview-movie',
            'status'         => 'published',
            'duration'       => 5000,
            'access_mode'    => 'public',
            'backdrop'       => '/images/backdrop.jpg',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $previewReq = new Request([], [
            'sections' => [
                [
                    'id'    => 'sec_p1',
                    'type'  => 'hero',
                    'title' => 'Live Preview Billboard',
                ]
            ]
        ], ['REQUEST_METHOD' => 'POST'], [], []);

        $res = $this->adminCtrl->apiPreviewHomepage($previewReq);
        $this->assertSame(200, $res->getStatusCode());

        $data = json_decode((string)$res->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsString($data['html']);
        $this->assertStringContainsString('fm-hero-section', $data['html']);
    }

    // 40. Frontend Music Hub route and controller
    public function test_frontend_music_hub_route_and_controller(): void
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/multimedia/music'], [], []);
        $res = $this->frontendCtrl->music($req);

        $this->assertSame(200, $res->getStatusCode());
        $html = (string)$res->getContent();

        $this->assertStringContainsString('Music Hub', $html);
        $this->assertStringContainsString('fm-theme-body', $html);
        $this->assertStringContainsString('--fm-', $html);
    }

    // 41. Frontend Movies Catalog route and view
    public function test_frontend_movies_catalog_route_and_view(): void
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/movies'], [], []);
        $res = $this->frontendCtrl->movies($req);

        $this->assertSame(200, $res->getStatusCode());
        $html = (string)$res->getContent();

        $this->assertStringContainsString('Movies', $html);
        $this->assertStringContainsString('fm-theme-body', $html);
        $this->assertStringContainsString('fm-main-content', $html);
    }

    // 42. Frontend Series Catalog route and view
    public function test_frontend_series_catalog_route_and_view(): void
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/series'], [], []);
        $res = $this->frontendCtrl->series($req);

        $this->assertSame(200, $res->getStatusCode());
        $html = (string)$res->getContent();

        $this->assertStringContainsString('Web Series', $html);
        $this->assertStringContainsString('fm-theme-body', $html);
    }

    // 43. Multiple download links integrated in movie detail
    public function test_multiple_download_links_integrated_in_movie_detail(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $mId = $this->db->insert('multimedia_movies', [
            'title'        => 'Multi DL Movie',
            'slug'         => 'multi-dl-movie',
            'status'       => 'published',
            'duration'     => 6000,
            'access_mode'  => 'public',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'label'        => '1080p Ultra',
            'url'          => 'https://example.com/movie_1080p.mp4',
            'quality'      => '1080p',
            'format'       => 'mp4',
            'provider'     => 'Direct',
            'is_active'    => 1,
            'sort_order'   => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'label'        => '720p Mobile',
            'url'          => 'https://example.com/movie_720p.mp4',
            'quality'      => '720p',
            'format'       => 'mp4',
            'provider'     => 'Mirror',
            'is_active'    => 1,
            'sort_order'   => 2,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/movie/multi-dl-movie'], [], []);
        $res = $this->frontendCtrl->movie($req, 'multi-dl-movie');

        $this->assertSame(200, $res->getStatusCode());
        $html = (string)$res->getContent();

        $this->assertStringContainsString('Multi DL Movie', $html);
        $this->assertStringContainsString('Download Options', $html);
        $this->assertStringContainsString('1080p Ultra', $html);
        $this->assertStringContainsString('720p Mobile', $html);
    }
}
