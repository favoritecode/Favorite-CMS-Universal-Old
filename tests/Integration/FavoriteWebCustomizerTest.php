<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\CustomizeController;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Themes\ThemeLayoutService;
use PHPUnit\Framework\TestCase;

class FavoriteWebCustomizerTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    protected ThemeLayoutService $layoutService;
    private static int $adminUserId = 0;
    private static ?string $originalActiveTheme = null;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);

        static::$originalActiveTheme = (string)Setting::get('theme', 'active_theme', 'default');

        // Ensure admin role exists
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");

        // Create test admin user
        $username = 'cust_admin_' . bin2hex(random_bytes(4));
        $email = "{$username}@example.com";
        static::$db->execute(
            "INSERT INTO `users` (`username`, `name`, `email`, `password`, `email_verified_at`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, NOW(), 'active', NOW(), NOW())",
            [$username, 'Customizer Admin', $email, password_hash('Secret123!', PASSWORD_DEFAULT)]
        );
        static::$adminUserId = (int)static::$db->lastInsertId();

        $roleRow = static::$db->selectOne("SELECT `id` FROM `roles` WHERE `slug` = 'admin'");
        if ($roleRow) {
            static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [static::$adminUserId, (int)$roleRow->id]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$adminUserId > 0) {
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [static::$adminUserId]);
            static::$db->execute("DELETE FROM `users` WHERE `id` = ?", [static::$adminUserId]);
        }
        // Clean up theme mods and layout for favorite-web
        static::$db->execute("DELETE FROM `settings` WHERE `group_name` LIKE 'theme_mods_favorite-web%'");
        static::$db->execute("DELETE FROM `settings` WHERE `group_name` LIKE 'theme_sections_favorite-web%'");

        // Restore original active theme
        Setting::set('theme', 'active_theme', static::$originalActiveTheme ?: 'default');
        Setting::clearCache();
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [
            'auth_user_id' => static::$adminUserId,
            '_token'       => 'test_csrf_token',
        ];
        Setting::clearCache();

        // Ensure active theme is favorite-web
        Setting::set('theme', 'active_theme', 'favorite-web');

        $this->layoutService = new ThemeLayoutService(static::$app);
    }

    public function testCustomizerViewLoadsForAuthorizedAdmin(): void
    {
        $controller = new CustomizeController(static::$app);
        $response = $controller->index(Request::create('GET', '/admin/customize'));

        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getContent();

        // Check customizer root wrapper
        $this->assertStringContainsString('fw-customizer-root', $html);
        $this->assertStringContainsString('Favorite Web', $html);

        // Check essential panel titles
        $this->assertStringContainsString('Site Identity', $html);
        $this->assertStringContainsString('Header Navigation', $html);
        $this->assertStringContainsString('Homepage Sections &amp; Order', $html);
        $this->assertStringContainsString('Hero Section', $html);
        $this->assertStringContainsString('Trust &amp; Stats Section', $html);
        $this->assertStringContainsString('About Section', $html);
        $this->assertStringContainsString('Professional Services', $html);
        $this->assertStringContainsString('Digital Products', $html);
        $this->assertStringContainsString('Packages &amp; Solutions', $html);
        $this->assertStringContainsString('Memberships', $html);
        $this->assertStringContainsString('Latest Articles', $html);
        $this->assertStringContainsString('Call to Action', $html);
        $this->assertStringContainsString('Footer', $html);
        $this->assertStringContainsString('Colors — Light Theme', $html);
        $this->assertStringContainsString('Colors — Dark Theme', $html);
        $this->assertStringContainsString('Typography &amp; Layout', $html);
        $this->assertStringContainsString('Custom CSS', $html);

        // Check preview iframe and media modal
        $this->assertStringContainsString('fw-preview-iframe', $html);
        $this->assertStringContainsString('fw-media-modal', $html);
    }

    public function testDefaultConfigMatchesHomepageDesignWhenZeroModsSaved(): void
    {
        // Clear all mods for favorite-web
        static::$db->execute("DELETE FROM `settings` WHERE `group_name` = 'theme_mods_favorite-web'");
        Setting::clearCache();

        require_once APP_ROOT . '/themes/favorite-web/inc/config.php';
        $config = \fw_get_config();

        $this->assertNotEmpty($config);
        $this->assertSame('Official Digital Platform', $config['hero_eyebrow']);
        $this->assertSame('Learn, create, and grow your digital presence.', $config['hero_title']);
        $this->assertSame('/store', $config['hero_primary_url']);
        $this->assertSame('#2563eb', $config['accent_color']);
        $this->assertSame('right', $config['site_layout']);
        $this->assertSame(38, $config['logo_width']);
        $this->assertCount(6, $config['services_items']);
    }

    public function testSaveThemeModsPersistsValuesAndAppliesToTheme(): void
    {
        $controller = new CustomizeController(static::$app);

        $request = Request::create('POST', '/admin/customize/save', [
            '_token' => 'test_csrf_token',
            'mods'   => [
                'hero_title'        => 'Elevate Your Digital Empire Today',
                'hero_eyebrow'      => 'Next-Gen Platform',
                'accent_color'      => '#10b981',
                'site_layout'       => 'none',
                'footer_brand_name' => 'Custom Web Corp',
                'footer_copyright'  => 'Custom 2026 Test Copyright',
                'logo_width'        => '56',
            ],
        ]);

        $response = $controller->save($request);
        $this->assertSame(302, $response->getStatusCode());

        Setting::clearCache();
        require_once APP_ROOT . '/themes/favorite-web/functions.php';
        $cfg = \fw_get_config();

        $this->assertSame('Elevate Your Digital Empire Today', $cfg['hero_title']);
        $this->assertSame('Next-Gen Platform', $cfg['hero_eyebrow']);
        $this->assertSame('#10b981', $cfg['accent_color']);
        $this->assertSame('none', $cfg['site_layout']);
        $this->assertSame('Custom Web Corp', $cfg['footer_brand_name']);
        $this->assertSame('Custom 2026 Test Copyright', $cfg['footer_copyright']);
        $this->assertEquals(56, $cfg['logo_width']);

        // Check helpers
        $this->assertSame('#10b981', \fw_accent_color());
        $this->assertSame('none', \fw_site_layout());
    }

    public function testBatchSectionReorderAndVisibilityToggles(): void
    {
        $controller = new CustomizeController(static::$app);

        // Reorder sections: put cta and services first, and disable trust-stats
        $newOrder = ['cta', 'services', 'hero', 'about', 'digital-products', 'packages', 'memberships', 'latest-posts', 'trust-stats'];
        $request = Request::create('POST', '/admin/customize/save', [
            '_token'        => 'test_csrf_token',
            'section_order' => $newOrder,
            'sections'      => [
                'cta'              => ['enabled' => '1'],
                'services'         => ['enabled' => '1'],
                'hero'             => ['enabled' => '1'],
                'trust-stats'      => ['enabled' => '0'], // disabled
            ],
        ]);

        $response = $controller->save($request);
        $this->assertSame(302, $response->getStatusCode());

        Setting::clearCache();
        $sections = $this->layoutService->getSections('favorite-web');
        $sectionIds = array_column($sections, 'id');

        $this->assertSame('cta', $sectionIds[0]);
        $this->assertSame('services', $sectionIds[1]);
        $this->assertSame('hero', $sectionIds[2]);

        $statsSection = array_values(array_filter($sections, static fn($s) => $s['id'] === 'trust-stats'))[0] ?? null;
        $this->assertNotNull($statsSection);
        $this->assertFalse($statsSection['enabled']);
    }

    public function testCustomServicesJsonPersistsAndLoadsInOfficialServices(): void
    {
        $controller = new CustomizeController(static::$app);

        $customServices = [
            [
                'title'       => 'Cloud Infrastructure',
                'description' => 'High scalability architecture and cloud migrations.',
                'icon'        => 'cube',
                'url'         => '/services/cloud',
                'enabled'     => true,
            ],
            [
                'title'       => 'Security Audits',
                'description' => 'Zero-day vulnerability scanning and penetration tests.',
                'icon'        => 'support',
                'url'         => '/services/security',
                'enabled'     => true,
            ],
        ];

        $request = Request::create('POST', '/admin/customize/save', [
            '_token' => 'test_csrf_token',
            'mods'   => [
                'services_json' => json_encode($customServices),
            ],
        ]);

        $controller->save($request);
        Setting::clearCache();

        require_once APP_ROOT . '/themes/favorite-web/functions.php';
        $services = \fw_official_services();

        $this->assertCount(2, $services);
        $this->assertSame('Cloud Infrastructure', $services[0]['title']);
        $this->assertSame('Security Audits', $services[1]['title']);
        $this->assertSame('/services/cloud', $services[0]['url']);
    }

    public function testCustomCssSanitizationStripsUnsafeConstructs(): void
    {
        require_once APP_ROOT . '/themes/favorite-web/inc/config.php';

        $unsafeCss = '
            body { font-size: 16px; }
            <script>alert("xss")</script>
            @import url("https://evil.com/leak.css");
            .card { behavior: url(xss.htc); color: expression(alert(1)); background: #ffffff; }
        ';

        $sanitized = \fw_sanitize_custom_css($unsafeCss);

        $this->assertStringNotContainsString('<script', $sanitized);
        $this->assertStringNotContainsString('@import', $sanitized);
        $this->assertStringNotContainsString('behavior:', $sanitized);
        $this->assertStringNotContainsString('expression(', $sanitized);
        $this->assertStringContainsString('font-size: 16px;', $sanitized);
        $this->assertStringContainsString('background: #ffffff;', $sanitized);
    }

    public function testResetRestoresThemeDefaults(): void
    {
        // First set some non-default mods
        $this->layoutService->setThemeMod('hero_title', 'Temporary Changed Title', 'favorite-web');
        $this->layoutService->setThemeMod('accent_color', '#ff0000', 'favorite-web');
        Setting::clearCache();

        $this->assertSame('Temporary Changed Title', $this->layoutService->getThemeMod('hero_title', '', 'favorite-web'));

        // Perform reset
        $controller = new CustomizeController(static::$app);
        $request = Request::create('POST', '/admin/customize/reset', [
            '_token' => 'test_csrf_token',
        ]);
        $response = $controller->reset($request);

        $this->assertSame(302, $response->getStatusCode());

        Setting::clearCache();
        $this->assertNull($this->layoutService->getThemeMod('hero_title', null, 'favorite-web'));

        require_once APP_ROOT . '/themes/favorite-web/inc/config.php';
        $cfg = \fw_get_config();
        $this->assertSame('Learn, create, and grow your digital presence.', $cfg['hero_title']);
        $this->assertSame('#2563eb', $cfg['accent_color']);
    }

    public function testCustomizerRequiresAuthentication(): void
    {
        // Simulate guest user
        $_SESSION = [];
        $request = Request::create('GET', '/admin/customize');
        $response = static::$kernel->handle($request);

        // Kernel auth middleware redirects unauthenticated users to login
        $this->assertTrue(in_array($response->getStatusCode(), [302, 401, 403], true));
        if ($response->getStatusCode() === 302) {
            $this->assertStringContainsString('/admin/login', (string)($response->getHeader('Location') ?? ''));
        }
    }

    public function testVideoIdExtractors(): void
    {
        require_once APP_ROOT . '/themes/favorite-web/inc/config.php';

        $this->assertSame('dQw4w9WgXcQ', \fw_extract_youtube_id('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', \fw_extract_youtube_id('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', \fw_extract_youtube_id('https://www.youtube.com/embed/dQw4w9WgXcQ'));
        $this->assertSame('123456789', \fw_extract_vimeo_id('https://vimeo.com/123456789'));
        $this->assertSame('123456789', \fw_extract_vimeo_id('https://player.vimeo.com/video/123456789'));
        $this->assertNull(\fw_extract_youtube_id('https://example.com/not-youtube'));
        $this->assertNull(\fw_extract_vimeo_id('https://example.com/not-vimeo'));
    }

    public function testCustomStylesTokenGeneration(): void
    {
        require_once APP_ROOT . '/themes/favorite-web/inc/config.php';

        $this->layoutService->setThemeMod('accent_color', '#10b981', 'favorite-web');
        $this->layoutService->setThemeMod('dark_accent_color', '#34d399', 'favorite-web');
        $this->layoutService->setThemeMod('container_width', 1360, 'favorite-web');
        $this->layoutService->setThemeMod('custom_css', '.test-banner { padding: 40px; }', 'favorite-web');
        Setting::clearCache();

        $styleTag = \fw_render_custom_styles();

        $this->assertStringContainsString('<style id="fw-theme-tokens">', $styleTag);
        $this->assertStringContainsString('--accent: #10b981;', $styleTag);
        $this->assertStringContainsString('--container: 1360px;', $styleTag);
        $this->assertStringContainsString('[data-theme="dark"] {', $styleTag);
        $this->assertStringContainsString('--accent: #34d399;', $styleTag);
        $this->assertStringContainsString('.test-banner { padding: 40px; }', $styleTag);
    }

    public function testHomepageRendersCustomizedContentEndToEnd(): void
    {
        $this->layoutService->setThemeMod('hero_title', 'Enterprise Digital Solutions', 'favorite-web');
        $this->layoutService->setThemeMod('hero_eyebrow', 'Proven & Verified', 'favorite-web');
        $this->layoutService->setThemeMod('hero_primary_text', 'Start Project Now', 'favorite-web');
        $this->layoutService->setThemeMod('footer_brand_name', 'Acme Digital Global', 'favorite-web');
        Setting::clearCache();

        $isHome = true;
        $posts = [];
        $currentPage = 1;
        $totalPages = 1;
        $totalPosts = 0;

        $level = ob_get_level();
        ob_start();
        $html = '';
        try {
            include APP_ROOT . '/themes/favorite-web/index.php';
            $html = (string)ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertStringContainsString('Enterprise Digital Solutions', $html);
        $this->assertStringContainsString('Proven &amp; Verified', $html);
        $this->assertStringContainsString('Start Project Now', $html);
        $this->assertStringContainsString('Acme Digital Global', $html);
    }
}
