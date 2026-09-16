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
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Theme\ThemeAssetManager;
use FavoriteCMS\Multimedia\Theme\ThemeConfig;
use FavoriteCMS\Multimedia\Theme\ThemeManager;
use FavoriteCMS\Multimedia\Theme\ThemePresetRegistry;
use FavoriteCMS\Multimedia\Theme\ThemePreviewService;
use FavoriteCMS\Multimedia\Theme\ThemeTokenResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit & Integration tests for Favorite Multimedia Theme System v1.0.0 (CHUNK 1).
 * Tests all 27 critical requirements across Theme Engine, Presets, Token Resolver,
 * No-Code Studio, Storage, and Asset Path Correction.
 */
class FavoriteMultimediaThemeEngineTest extends TestCase
{
    private Application $app;
    private Database $db;
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
        $_SESSION['_token'] = 'valid_theme_token';

        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_theme_test_' . bin2hex(random_bytes(8)) . '.sqlite';
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

        // Regular user without admin permission
        $this->db->insert('users', ['id' => 2, 'username' => 'regular', 'name' => 'Regular User', 'email' => 'regular@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        FavoriteMultimediaPlugin::reset();
        ThemeManager::reset();
        Setting::clearCache();

        MultimediaPermission::registerDefaultPermissions($this->db);

        $this->adminCtrl = new MultimediaAdminController($this->app);
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

    // 1. ThemeConfig schema version and defaults
    public function testThemeConfigHasCorrectSchemaVersionAndDefaults(): void
    {
        $config = new ThemeConfig();
        $this->assertSame('1.0.0', $config->getSchemaVersion());
        $this->assertTrue($config->get('general', 'active'));
        $this->assertSame('dark', $config->get('general', 'default_mode'));
        $this->assertSame('#E50914', $config->get('colors', 'primary'));
        $this->assertSame('#0B0E14', $config->get('colors', 'bg_page'));
        $this->assertSame('comfortable', $config->get('layout', 'density'));
    }

    // 2. ThemeConfig immutability and with method
    public function testThemeConfigImmutabilityAndWithMethod(): void
    {
        $config1 = new ThemeConfig();
        $config2 = $config1->with('colors', 'primary', '#6366F1');

        $this->assertSame('#E50914', $config1->get('colors', 'primary'));
        $this->assertSame('#6366F1', $config2->get('colors', 'primary'));
        $this->assertNotSame($config1, $config2);
    }

    // 3. ThemeConfig deep merge
    public function testThemeConfigDeepMergeWithOverrides(): void
    {
        $config = new ThemeConfig();
        $overridden = $config->merge([
            'colors' => [
                'primary' => '#10B981',
                'accent' => '#3B82F6',
            ],
            'layout' => [
                'density' => 'compact',
            ],
        ]);

        $this->assertSame('#10B981', $overridden->get('colors', 'primary'));
        $this->assertSame('#3B82F6', $overridden->get('colors', 'accent'));
        $this->assertSame('#0B0E14', $overridden->get('colors', 'bg_page')); // Unchanged default preserved
        $this->assertSame('compact', $overridden->get('layout', 'density'));
    }

    // 4. ThemeConfig toArray and fromArray round-trip
    public function testThemeConfigToArrayAndFromArrayRoundTrip(): void
    {
        $original = new ThemeConfig(['branding' => ['brand_title' => 'Custom Streaming']]);
        $arr = $original->toArray();

        $restored = ThemeConfig::fromArray($arr);
        $this->assertSame('Custom Streaming', $restored->get('branding', 'brand_title'));
        $this->assertSame($arr, $restored->toArray());
    }

    // 5. Sanitization strips harmful XSS and CSS expressions
    public function testThemeConfigSanitizationStripsHarmfulXssAndExpressions(): void
    {
        $dirty = new ThemeConfig([
            'branding' => [
                'brand_title' => 'My Stream <script>alert(1)</script>',
                'logo_url' => 'javascript:evil()',
            ],
        ]);
        $clean = $dirty->sanitize();

        $this->assertSame('My Stream', $clean->get('branding', 'brand_title'));
        $this->assertSame('', $clean->get('branding', 'logo_url'));
    }

    // 6. Sanitization corrects invalid color hex
    public function testThemeConfigSanitizationFixesInvalidColorHex(): void
    {
        $dirty = new ThemeConfig([
            'colors' => [
                'primary' => 'NOT_A_COLOR',
            ],
        ]);
        $clean = $dirty->sanitize();

        // Defaults back to fallback
        $this->assertSame('#E50914', $clean->get('colors', 'primary'));
    }

    // 7. Validation reports invalid color format
    public function testThemeConfigValidationReportsInvalidColors(): void
    {
        $invalid = new ThemeConfig([
            'colors' => [
                'primary' => 'red; background: blue',
            ],
        ]);
        $errors = $invalid->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid color format', $errors[0]);
    }

    // 8. Validation reports invalid mode
    public function testThemeConfigValidationReportsInvalidMode(): void
    {
        $invalid = new ThemeConfig([
            'general' => [
                'default_mode' => 'invalid_neon_mode',
            ],
        ]);
        $errors = $invalid->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid default mode', $errors[0]);
    }

    // 9. Preset Registry has 5 distinct presets
    public function testThemePresetRegistryHasFiveDistinctPresets(): void
    {
        $all = ThemePresetRegistry::all();
        $this->assertCount(5, $all);
        $this->assertArrayHasKey(ThemePresetRegistry::PRESET_CINEMATIC, $all);
        $this->assertArrayHasKey(ThemePresetRegistry::PRESET_MODERN, $all);
        $this->assertArrayHasKey(ThemePresetRegistry::PRESET_COMPACT, $all);
        $this->assertArrayHasKey(ThemePresetRegistry::PRESET_MUSIC_FOCUS, $all);
        $this->assertArrayHasKey(ThemePresetRegistry::PRESET_FAMILY, $all);
    }

    // 10. Cinematic preset characteristics
    public function testThemePresetRegistryCinematicPresetValues(): void
    {
        $preset = ThemePresetRegistry::get('cinematic');
        $this->assertNotNull($preset);
        $this->assertSame('Cinematic', $preset['name']);
        $this->assertSame('#E50914', $preset['config']['colors']['primary']);
        $this->assertSame('#0B0E14', $preset['config']['colors']['bg_page']);
    }

    // 11. Modern preset characteristics
    public function testThemePresetRegistryModernPresetValues(): void
    {
        $preset = ThemePresetRegistry::get('modern');
        $this->assertNotNull($preset);
        $this->assertSame('Modern', $preset['name']);
        $this->assertSame('#6366F1', $preset['config']['colors']['primary']);
        $this->assertSame('#0F172A', $preset['config']['colors']['bg_page']);
    }

    // 12. Compact preset characteristics
    public function testThemePresetRegistryCompactPresetValues(): void
    {
        $preset = ThemePresetRegistry::get('compact');
        $this->assertNotNull($preset);
        $this->assertSame('Compact', $preset['name']);
        $this->assertSame('compact', $preset['config']['layout']['density']);
        $this->assertSame('16px', $preset['config']['layout']['page_padding']);
    }

    // 13. Music Focus preset characteristics
    public function testThemePresetRegistryMusicFocusPresetValues(): void
    {
        $preset = ThemePresetRegistry::get('music_focus');
        $this->assertNotNull($preset);
        $this->assertSame('Music Focus', $preset['name']);
        $this->assertSame('#1DB954', $preset['config']['colors']['primary']);
        $this->assertSame('#121212', $preset['config']['colors']['bg_page']);
        $this->assertSame('9999px', $preset['config']['buttons']['radius']);
    }

    // 14. Family preset characteristics
    public function testThemePresetRegistryFamilyPresetValues(): void
    {
        $preset = ThemePresetRegistry::get('family');
        $this->assertNotNull($preset);
        $this->assertSame('Family', $preset['name']);
        $this->assertSame('#FF5376', $preset['config']['colors']['primary']);
        $this->assertSame('spacious', $preset['config']['layout']['density']);
    }

    // 15. Apply preset to ThemeConfig
    public function testThemePresetRegistryApplyToConfig(): void
    {
        $base = new ThemeConfig();
        $applied = ThemePresetRegistry::applyTo($base, 'modern');

        $this->assertSame('#6366F1', $applied->get('colors', 'primary'));
        $this->assertSame('#0F172A', $applied->get('colors', 'bg_page'));
    }

    // 16. Token Resolver generates CSS custom properties array
    public function testThemeTokenResolverGeneratesCssVariablesArray(): void
    {
        $config = new ThemeConfig();
        $resolver = new ThemeTokenResolver($config);
        $vars = $resolver->toCssVariables();

        $this->assertArrayHasKey('--fm-color-primary', $vars);
        $this->assertSame('#E50914', $vars['--fm-color-primary']);
        $this->assertArrayHasKey('--fm-color-bg-page', $vars);
        $this->assertArrayHasKey('--fm-layout-max-width', $vars);
        $this->assertArrayHasKey('--fm-radius-card', $vars);
        $this->assertArrayHasKey('--fm-btn-primary-bg', $vars);
    }

    // 17. Token Resolver generates inline CSS
    public function testThemeTokenResolverGeneratesInlineCssWithLightAndDarkSelectors(): void
    {
        $config = new ThemeConfig();
        $resolver = new ThemeTokenResolver($config);
        $css = $resolver->toInlineCss();

        $this->assertStringContainsString(':root {', $css);
        $this->assertStringContainsString('--fm-color-primary: #E50914;', $css);
        $this->assertStringContainsString('html.fm-theme-light', $css);
        $this->assertStringContainsString('html.fm-theme-dark', $css);
    }

    // 18. Token Resolver system mode includes media query
    public function testThemeTokenResolverSystemModeIncludesPrefersColorSchemeMediaQuery(): void
    {
        $config = new ThemeConfig(['general' => ['default_mode' => 'system']]);
        $resolver = new ThemeTokenResolver($config);
        $css = $resolver->toInlineCss();

        $this->assertStringContainsString('@media (prefers-color-scheme: light)', $css);
    }

    // 19. Render HTML style tag when active
    public function testThemeTokenResolverRenderHtmlStyleTagWhenActive(): void
    {
        $config = new ThemeConfig(['general' => ['active' => true]]);
        $resolver = new ThemeTokenResolver($config);
        $tag = $resolver->renderHtmlStyleTag();

        $this->assertStringStartsWith('<style id="fm-theme-tokens">', $tag);
        $this->assertStringEndsWith('</style>', trim($tag));
    }

    // 20. Render HTML style tag when disabled returns empty
    public function testThemeTokenResolverRenderHtmlStyleTagWhenDisabledReturnsEmpty(): void
    {
        $config = new ThemeConfig(['general' => ['active' => false]]);
        $resolver = new ThemeTokenResolver($config);
        $tag = $resolver->renderHtmlStyleTag();

        $this->assertSame('', $tag);
    }

    // 21. ThemeAssetManager enforces strict theme-assets release path
    public function testThemeAssetManagerEnforcesStrictThemeAssetsReleasePath(): void
    {
        $assetMgr = new ThemeAssetManager('/test/app');
        $distPath = $assetMgr->getThemeReleaseDistPath('/test/Favorite-CMS-Assets');

        $this->assertStringContainsString('theme-assets/favorite-multimedia/release', str_replace('\\', '/', $distPath));
        $this->assertTrue($assetMgr->validateDistributionPath($distPath, 'theme'));
    }

    // 22. ThemeAssetManager rejects theme assets in plugin-assets
    public function testThemeAssetManagerRejectsThemeAssetsInPluginAssets(): void
    {
        $assetMgr = new ThemeAssetManager();
        $invalidPath = 'Favorite-CMS-Assets/plugin-assets/favorite-multimedia/release/theme.zip';

        $this->assertFalse($assetMgr->validateDistributionPath($invalidPath, 'theme'));
    }

    // 23. ThemeManager persistence in settings table
    public function testThemeManagerPersistenceInSettingsTable(): void
    {
        $themeMgr = ThemeManager::getInstance($this->app);
        $newConfig = (new ThemeConfig())->with('colors', 'primary', '#00FF00');

        $saved = $themeMgr->saveConfig($newConfig);
        $this->assertTrue($saved);

        ThemeManager::reset();
        Setting::clearCache();

        $reloaded = ThemeManager::getInstance($this->app)->getActiveConfig();
        $this->assertSame('#00FF00', $reloaded->get('colors', 'primary'));
    }

    // 24. ThemeManager reset section
    public function testThemeManagerResetSection(): void
    {
        $themeMgr = ThemeManager::getInstance($this->app);
        $custom = (new ThemeConfig())->with('layout', 'density', 'compact');
        $themeMgr->saveConfig($custom);

        $this->assertSame('compact', $themeMgr->getActiveConfig()->get('layout', 'density'));

        $reset = $themeMgr->resetSection('layout');
        $this->assertSame('comfortable', $reset->get('layout', 'density'));
    }

    // 25. ThemeManager reset all
    public function testThemeManagerResetAll(): void
    {
        $themeMgr = ThemeManager::getInstance($this->app);
        $custom = (new ThemeConfig())->with('colors', 'primary', '#990000')->with('layout', 'density', 'spacious');
        $themeMgr->saveConfig($custom);

        $reset = $themeMgr->resetAll();
        $this->assertSame('#E50914', $reset->get('colors', 'primary'));
        $this->assertSame('comfortable', $reset->get('layout', 'density'));
    }

    // 26. ThemeAdminController permission denied for regular user
    public function testThemeAdminControllerThemeStudioPermissionDenied(): void
    {
        $_SESSION['auth_user_id'] = 2; // non-admin
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-theme']);
        $res = $this->adminCtrl->handle($req, 'theme');

        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(403, $res->getStatusCode());
    }

    // 27. ThemeAdminController apply preset and save actions
    public function testThemeAdminControllerThemeStudioActionsAndPresetApply(): void
    {
        $_SESSION['auth_user_id'] = 1; // admin

        // Apply preset action
        $postData = [
            'action' => 'apply_preset',
            'preset_id' => 'modern',
            '_token' => 'valid_theme_token',
        ];
        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-theme']);
        $res = $this->adminCtrl->handle($req, 'theme');

        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(200, $res->getStatusCode());

        $content = json_decode($res->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertSame('#6366F1', $content['config']['colors']['primary']);
    }
}
