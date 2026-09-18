<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Currency;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\WalletService;
use FavoriteCMS\Models\Setting;
use PDO;
use PHPUnit\Framework\TestCase;

class FrontendHeaderIndicatorsTest extends TestCase
{
    private Application $app;
    private PDO $sqlitePdo;
    private Database $sqliteDb;
    private ProductRepository $productRepo;
    private WalletRepository $walletRepo;
    private MembershipLifecycleService $membershipService;
    private WalletService $walletService;
    private FavoriteDigitalPlugin $plugin;

    protected function setUp(): void
    {
        Hook::reset();
        AccountMenu::reset();
        FavoriteDigitalPlugin::reset();

        $this->app = new Application();

        $this->sqlitePdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->sqliteDb = new class($this->sqlitePdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };

        $this->sqliteDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        // Run migrations
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(test_plugin_directory('favorite-digital') . '/database/migrations');

        $this->sqlitePdo->exec("
            CREATE TABLE IF NOT EXISTS `settings` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `group_name` VARCHAR(64) NOT NULL,
                `setting_key` VARCHAR(128) NOT NULL,
                `value` TEXT NULL,
                `type` VARCHAR(32) NOT NULL DEFAULT 'string',
                `is_public` INTEGER NOT NULL DEFAULT 0,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                UNIQUE(`group_name`, `setting_key`)
            );
        ");

        \FavoriteCMS\Core\Container::getInstance()->instance(Database::class, $this->sqliteDb);
        Setting::clearCache();
        Setting::set('general', 'primary_currency', 'BDT', 'string');

        $this->app->instance(Database::class, $this->sqliteDb);

        // Bootstrap plugin
        $this->plugin = FavoriteDigitalPlugin::bootstrap($this->app);

        $this->productRepo = $this->app->make(ProductRepository::class);
        $this->walletRepo = $this->app->make(WalletRepository::class);
        $this->membershipService = $this->app->make(MembershipLifecycleService::class);
        $this->walletService = $this->app->make(WalletService::class);
    }

    protected function tearDown(): void
    {
        Hook::reset();
        AccountMenu::reset();
        FavoriteDigitalPlugin::reset();
    }

    private function createMockUser(int $id = 42, string $name = 'Test Member', string $username = 'testmember'): object
    {
        return (object)[
            'id'       => $id,
            'name'     => $name,
            'username' => $username,
            'avatar'   => null,
            'roles'    => [(object)['name' => 'Member', 'slug' => 'member']],
            'getRoles' => fn() => [(object)['name' => 'Member', 'slug' => 'member']],
            'hasPermission' => fn(string $cap) => false,
        ];
    }

    private function createActiveMembership(int $userId): void
    {
        $planId = $this->membershipService->createPlan([
            'title'          => 'VIP Premium Plan',
            'slug'           => 'vip-premium-plan',
            'original_price' => '299.00',
            'product_type'   => ProductType::MEMBERSHIP,
        ], [
            'plan_type'           => 'monthly',
            'duration_count'      => 1,
            'duration_unit'       => 'month',
            'grace_period_days'   => 3,
            'allows_auto_renewal' => false,
        ]);

        $now = new DateTimeImmutable();
        $this->membershipService->activateMembership($userId, $planId, false, $now);
    }

    /**
     * Scenario A: Favorite Digital installed + logged in + active Premium Membership
     * → wallet balance visible
     * → diamond icon visible
     */
    public function testScenarioA_ActivePremiumMembershipAndWalletBalanceVisible(): void
    {
        $user = $this->createMockUser(101, 'Premium User');
        $this->walletService->credit(101, '500.00', 'credit_a1', 'Top-up');
        $this->createActiveMembership(101);

        // 1. Theme helper fw_wallet_balance() returns formatted balance
        $balance = fdig_get_wallet_balance(101);
        $this->assertSame('৳500.00', $balance);

        // 2. Active membership check returns true
        $this->assertTrue(fdig_is_premium_active(101));

        // 3. render_account_menu produces diamond icon beside profile trigger
        $html = AccountMenu::render(['user' => $user]);
        $this->assertStringContainsString('class="cms-premium-badge"', $html);
        $this->assertStringContainsString('icon-premium-diamond', $html);
        $this->assertStringContainsString('title="Active Premium Member"', $html);

        // Exact occurrence verification: exactly 1 diamond badge and 1 wallet pill
        $this->assertSame(1, substr_count($html, 'cms-premium-badge'));
        $this->assertSame(1, substr_count($html, 'icon-premium-diamond'));
        $this->assertSame(1, substr_count($html, 'header-wallet-pill'));
        $this->assertStringContainsString('৳500.00', $html);
    }

    /**
     * Scenario B: Favorite Digital installed + logged in + no active Premium Membership
     * → wallet balance visible
     * → diamond icon hidden
     */
    public function testScenarioB_NoActiveMembershipWalletVisibleDiamondHidden(): void
    {
        $user = $this->createMockUser(102, 'Standard User');
        $this->walletService->credit(102, '250.00', 'credit_b1', 'Top-up');

        // No membership activated for user 102
        $this->assertFalse(fdig_is_premium_active(102));

        $html = AccountMenu::render(['user' => $user]);
        $this->assertStringNotContainsString('cms-premium-badge', $html);
        $this->assertStringNotContainsString('icon-premium-diamond', $html);
        $this->assertSame(0, substr_count($html, 'cms-premium-badge'));
        $this->assertSame(0, substr_count($html, 'icon-premium-diamond'));

        // Wallet is still visible (exactly 1)
        $this->assertStringContainsString('header-wallet-pill', $html);
        $this->assertSame(1, substr_count($html, 'header-wallet-pill'));
        $this->assertStringContainsString('৳250.00', $html);
    }

    /**
     * Scenario C: Premium Membership expired
     * → diamond icon hidden
     */
    public function testScenarioC_ExpiredMembershipDiamondHidden(): void
    {
        $user = $this->createMockUser(103, 'Expired User');
        $this->createActiveMembership(103);

        // Manually update membership to expired
        $this->sqliteDb->execute(
            "UPDATE favorite_digital_memberships SET status = 'expired', expires_at = '2020-01-01 00:00:00' WHERE user_id = ?",
            [103]
        );

        $this->assertFalse(fdig_is_premium_active(103));

        $html = AccountMenu::render(['user' => $user]);
        $this->assertStringNotContainsString('cms-premium-badge', $html);
        $this->assertStringNotContainsString('icon-premium-diamond', $html);
        $this->assertSame(0, substr_count($html, 'cms-premium-badge'));
        $this->assertSame(0, substr_count($html, 'icon-premium-diamond'));
    }

    /**
     * Scenario D: Premium Membership cancelled/inactive
     * → diamond icon hidden
     */
    public function testScenarioD_CancelledMembershipDiamondHidden(): void
    {
        $user = $this->createMockUser(104, 'Cancelled User');
        $this->createActiveMembership(104);

        // Manually update status to cancelled
        $this->sqliteDb->execute(
            "UPDATE favorite_digital_memberships SET status = 'cancelled' WHERE user_id = ?",
            [104]
        );

        $this->assertFalse(fdig_is_premium_active(104));

        $html = AccountMenu::render(['user' => $user]);
        $this->assertStringNotContainsString('cms-premium-badge', $html);
        $this->assertStringNotContainsString('icon-premium-diamond', $html);
        $this->assertSame(0, substr_count($html, 'cms-premium-badge'));
        $this->assertSame(0, substr_count($html, 'icon-premium-diamond'));
    }

    /**
     * Scenario E: Favorite Digital installed but wallet unavailable
     * → header must not crash
     */
    public function testScenarioE_WalletUnavailableHeaderDoesNotCrash(): void
    {
        $user = $this->createMockUser(105, 'Error User');

        // Invalid negative user ID or broken wallet repository query fails safely
        $invalidBalance = fdig_get_wallet_balance(-999);
        $this->assertNull($invalidBalance);

        // Rendering header still succeeds safely without fatal errors
        $html = AccountMenu::render(['user' => $user]);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('class="cms-account-menu"', $html);
        $this->assertStringContainsString('Error User', $html);
    }

    /**
     * Scenario F: Favorite Digital NOT installed
     * → no wallet balance
     * → no fatal error
     * → existing Core profile/header works normally
     */
    public function testScenarioF_FavoriteDigitalNotInstalledSafeFallback(): void
    {
        AccountMenu::reset();
        FavoriteDigitalPlugin::reset();

        $user = $this->createMockUser(106, 'Core User');

        // AccountMenu renders cleanly without any plugin filters
        $html = AccountMenu::render(['user' => $user]);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('class="cms-account-menu"', $html);
        $this->assertStringContainsString('Core User', $html);
        $this->assertStringNotContainsString('cms-premium-badge', $html);
        $this->assertStringNotContainsString('icon-premium-diamond', $html);
    }

    /**
     * Scenario G: Logged out (guest)
     * → no wallet balance
     * → no premium icon
     */
    public function testScenarioG_LoggedOutNoWalletNoPremium(): void
    {
        $this->assertNull(fdig_get_wallet_balance(0));
        $this->assertFalse(fdig_is_premium_active(0));

        $rendered = AccountMenu::render(['user' => null]);
        $this->assertSame('', $rendered);
    }

    /**
     * Scenario H: Profile dropdown
     * → ALL existing options remain exactly unchanged
     * → same order, same links, same permissions, same logout behavior
     */
    public function testScenarioH_ProfileDropdownOptionsRemainExactlyUnchanged(): void
    {
        $user = $this->createMockUser(108, 'Regular User');
        $items = AccountMenu::getItems($user);

        // Core items: profile (order 10), digital_membership (order 12), logout (order 100)
        $this->assertArrayHasKey('profile', $items);
        $this->assertArrayHasKey('digital_membership', $items);
        $this->assertArrayHasKey('logout', $items);

        $this->assertSame('Profile', $items['profile']['label']);
        $this->assertSame('/admin/users/profile', $items['profile']['url']);

        $this->assertSame('Membership', $items['digital_membership']['label']);
        $this->assertSame('/account/membership', $items['digital_membership']['url']);

        $this->assertSame('Log Out', $items['logout']['label']);
        $this->assertSame('/admin/logout', $items['logout']['url']);

        // Order preserved
        $keys = array_keys($items);
        $this->assertSame(['profile', 'digital_membership', 'logout'], $keys);
    }

    /**
     * Scenario I: Currency
     * → verify displayed wallet amount follows Core Primary Currency formatter
     */
    public function testScenarioI_WalletAmountFollowsCorePrimaryCurrency(): void
    {
        $user = $this->createMockUser(109, 'Currency User');
        $this->walletService->credit(109, '1000.00', 'credit_curr_1', 'Initial');

        // 1. Default currency BDT
        Setting::clearCache();
        Setting::set('general', 'primary_currency', 'BDT', 'string');
        $bdtBalance = fdig_get_wallet_balance(109);
        $this->assertSame('৳1,000.00', $bdtBalance);

        // 2. Switch to INR
        Setting::clearCache();
        Setting::set('general', 'primary_currency', 'INR', 'string');
        $inrBalance = fdig_get_wallet_balance(109);
        $this->assertSame('₹1,000.00', $inrBalance);

        // 3. Switch to USD
        Setting::clearCache();
        Setting::set('general', 'primary_currency', 'USD', 'string');
        $usdBalance = fdig_get_wallet_balance(109);
        $this->assertSame('$1,000.00', $usdBalance);

        // 4. Switch to EUR
        Setting::clearCache();
        Setting::set('general', 'primary_currency', 'EUR', 'string');
        $eurBalance = fdig_get_wallet_balance(109);
        $this->assertSame('€1,000.00', $eurBalance);
    }

    /**
     * Scenario J: Desktop + mobile structure
     * → verify header DOM elements and classes
     */
    public function testScenarioJ_DesktopAndMobileStructureAndAriaAttributes(): void
    {
        $user = $this->createMockUser(110, 'Mobile User');
        $this->walletService->credit(110, '750.00', 'credit_j1', 'Credit');
        $this->createActiveMembership(110);

        $html = AccountMenu::render(['user' => $user]);

        // Trigger button has accessible ARIA attributes
        $this->assertStringContainsString('class="cms-account-trigger"', $html);
        $this->assertStringContainsString('aria-haspopup="true"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);

        // Diamond badge is present with label and tooltip
        $this->assertStringContainsString('class="cms-premium-badge"', $html);
        $this->assertStringContainsString('aria-label="Active Premium Member"', $html);

        // Wallet pill is present
        $this->assertStringContainsString('class="header-wallet-pill"', $html);
        $this->assertStringContainsString('class="header-wallet-amount"', $html);
    }

    /**
     * Scenario K: Light + dark mode styling
     * → verify diamond icon styling uses resilient high-contrast golden color
     */
    public function testScenarioK_LightAndDarkModeStyling(): void
    {
        $user = $this->createMockUser(111, 'Themed User');
        $this->createActiveMembership(111);

        $html = AccountMenu::render(['user' => $user]);

        // Amber-500 (#f59e0b) provides high contrast against both light (#fff) and dark (#1e293b) surfaces
        $this->assertStringContainsString('color:#f59e0b;', $html);
        $this->assertStringContainsString('icon-premium-diamond', $html);
    }

    /**
     * Theme Integration test: When theme calls fw_wallet_balance(), filter avoids duplicate pill.
     */
    public function testThemeRenderDeduplication(): void
    {
        $user = $this->createMockUser(112, 'Theme User');
        $this->walletService->credit(112, '300.00', 'credit_theme_1', 'Credit');

        // Theme calls fw_wallet_balance() in header.php
        $walletBal = fw_wallet_balance(112);
        $this->assertSame('৳300.00', $walletBal);

        // Next, theme renders AccountMenu::render(['user' => $user])
        $html = AccountMenu::render(['user' => $user]);

        // Because theme already rendered wallet pill, filter must NOT add duplicate
        $this->assertStringNotContainsString('header-wallet-pill', $html);
        $this->assertSame(0, substr_count($html, 'header-wallet-pill'));
    }

    /**
     * Regression Test 1: Favorite Web + logged in + wallet balance
     * → Theme renders 1 pill outside menu, menu HTML contains 0 pills
     * → Total on page = exactly 1 wallet pill
     */
    public function testFavoriteWebThemeLoggedinWalletExactOnePill(): void
    {
        $user = $this->createMockUser(113, 'FW Wallet User');
        $this->walletService->credit(113, '650.00', 'cr_fw_1', 'Top-up');

        // Favorite Web header.php calls fw_wallet_balance()
        $fwThemeBal = fw_wallet_balance(113);
        $this->assertSame('৳650.00', $fwThemeBal);

        // Favorite Web header.php renders wallet pill outside AccountMenu:
        $headerPillHtml = '<a class="header-wallet-pill" href="/account/wallet"><span class="header-wallet-amount">' . $fwThemeBal . '</span></a>';

        // Then Favorite Web header.php calls render_account_menu()
        $menuHtml = AccountMenu::render(['user' => $user]);

        // Menu HTML must NOT contain a second wallet pill
        $this->assertSame(0, substr_count($menuHtml, 'header-wallet-pill'));

        // Combined page HTML contains EXACTLY 1 wallet pill
        $pageHtml = $headerPillHtml . "\n" . $menuHtml;
        $this->assertSame(1, substr_count($pageHtml, 'header-wallet-pill'));
    }

    /**
     * Regression Test 2: Favorite Web + active premium membership
     * → AccountMenu contains EXACTLY 1 diamond badge
     */
    public function testFavoriteWebActivePremiumExactOneDiamond(): void
    {
        $user = $this->createMockUser(114, 'FW Premium User');
        $this->createActiveMembership(114);

        // Favorite Web header.php renders menu
        $menuHtml = AccountMenu::render(['user' => $user]);

        // Exactly 1 diamond badge and 1 diamond SVG icon
        $this->assertSame(1, substr_count($menuHtml, 'cms-premium-badge'));
        $this->assertSame(1, substr_count($menuHtml, 'icon-premium-diamond'));
    }

    /**
     * Regression Test 3: Non-Favorite-Web theme + Favorite Digital
     * → Menu HTML contains EXACTLY 1 wallet pill
     * → Menu HTML contains EXACTLY 1 premium diamond
     */
    public function testNonFavoriteWebThemeExactOneWalletAndOneDiamond(): void
    {
        $user = $this->createMockUser(115, 'Non-FW User');
        $this->walletService->credit(115, '400.00', 'cr_non_fw_1', 'Credit');
        $this->createActiveMembership(115);

        // Non-Favorite-Web theme does NOT call fw_wallet_balance(), calls AccountMenu directly
        $menuHtml = AccountMenu::render(['user' => $user]);

        // Fallback wallet pill is added EXACTLY 1 time
        $this->assertSame(1, substr_count($menuHtml, 'header-wallet-pill'));
        $this->assertSame(1, substr_count($menuHtml, 'cms-premium-badge'));
        $this->assertSame(1, substr_count($menuHtml, 'icon-premium-diamond'));
    }

    /**
     * Regression Test 4: Idempotency on repeated filter/render execution
     * (e.g. desktop header + mobile drawer on the same page)
     * → Neither wallet pill nor diamond badge can ever be duplicated
     */
    public function testRepeatedFilterExecutionIdempotency(): void
    {
        $user = $this->createMockUser(116, 'Drawer User');
        $this->walletService->credit(116, '800.00', 'cr_rep_1', 'Credit');
        $this->createActiveMembership(116);

        // First render (Desktop header)
        $desktopHtml = AccountMenu::render(['user' => $user]);
        $this->assertSame(1, substr_count($desktopHtml, 'header-wallet-pill'));
        $this->assertSame(1, substr_count($desktopHtml, 'cms-premium-badge'));
        $this->assertSame(1, substr_count($desktopHtml, 'icon-premium-diamond'));

        // Second render (Mobile drawer) in same request lifecycle
        $mobileHtml = AccountMenu::render(['user' => $user]);
        $this->assertSame(1, substr_count($mobileHtml, 'header-wallet-pill'));
        $this->assertSame(1, substr_count($mobileHtml, 'cms-premium-badge'));
        $this->assertSame(1, substr_count($mobileHtml, 'icon-premium-diamond'));

        // Direct filter re-application on already formatted HTML must not duplicate
        $doubleFiltered = $this->plugin->filterRenderAccountMenu($desktopHtml, [], $user);
        $this->assertSame(1, substr_count($doubleFiltered, 'header-wallet-pill'));
        $this->assertSame(1, substr_count($doubleFiltered, 'cms-premium-badge'));
        $this->assertSame(1, substr_count($doubleFiltered, 'icon-premium-diamond'));
    }

    /**
     * Regression Test 5: Plugin bootstrap singleton
     * → Multiple bootstrap calls return the same instance and do not duplicate filter hooks
     */
    public function testPluginBootstrapSingletonPreventsDuplicateHooks(): void
    {
        $app = new Application();
        $instance1 = FavoriteDigitalPlugin::bootstrap($app);
        $instance2 = FavoriteDigitalPlugin::bootstrap($app);

        $this->assertSame($instance1, $instance2);
    }
}
