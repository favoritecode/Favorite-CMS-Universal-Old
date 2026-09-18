<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Digital\Controllers\CustomerAccountController;
use FavoriteCMS\Digital\Controllers\CustomerCheckoutController;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Services\CustomerAccountService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\DownloadService;
use FavoriteCMS\Digital\Services\FavoritePayWalletInterceptor;
use FavoriteCMS\Digital\Services\FulfillmentService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Digital\Services\WalletReconciliationService;
use FavoriteCMS\Digital\Services\WalletService as DigitalWalletService;
use FavoriteCMS\Digital\Support\MembershipPeriodCalculator;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\WalletLedgerEntry;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Controllers\CustomerAccountController as FavoritePayCustomerAccountController;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteDigitalMembershipUxAndWalletAccountingTest extends TestCase
{
    private Application $app;
    private PDO $sqlitePdo;
    public Database $sqliteDb;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;
    private WalletRepository $walletRepo;
    private EntitlementRepository $entitlementRepo;
    private MembershipLifecycleService $membershipService;
    private DigitalWalletService $digitalWalletService;
    private OrderService $orderService;
    private FulfillmentService $fulfillmentService;
    private CheckoutService $checkoutService;
    private CustomerCheckoutController $checkoutController;
    private CustomerAccountController $accountController;
    private CustomerAccountService $accountService;
    private DownloadService $downloadService;
    private WalletReconciliationService $reconciliationService;
    private WalletServiceInterface $mockPayWalletService;
    private PaymentServiceInterface $mockPayService;
    private FavoritePayWalletInterceptor $interceptor;

    /** @var array<string, PaymentIntent> */
    private array $intents = [];

    /** @var array<int, int> in minor units (Poisha) */
    private array $payBalances = [];

    protected function setUp(): void
    {
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }

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

        // Create Settings and Favorite Pay mock tables for reconciliation and payment intents
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
            CREATE TABLE IF NOT EXISTS `favorite_pay_wallets` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id` INTEGER NOT NULL,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'BDT',
                `balance_amount` BIGINT NOT NULL DEFAULT 0,
                `available_amount` BIGINT NOT NULL DEFAULT 0,
                `held_amount` BIGINT NOT NULL DEFAULT 0,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL
            );
            CREATE TABLE IF NOT EXISTS `favorite_pay_wallet_entries` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `entry_id` VARCHAR(64) NOT NULL,
                `wallet_id` INTEGER NOT NULL,
                `user_id` INTEGER NOT NULL,
                `type` VARCHAR(20) NOT NULL,
                `amount` BIGINT NOT NULL,
                `balance_after` BIGINT NOT NULL,
                `reference_type` VARCHAR(32) NOT NULL,
                `reference_id` VARCHAR(128) NOT NULL,
                `idempotency_key` VARCHAR(128) NULL,
                `description` TEXT NULL,
                `metadata` TEXT NULL,
                `created_at` DATETIME NULL
            );
            CREATE TABLE IF NOT EXISTS `favorite_pay_transactions` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `transaction_id` VARCHAR(64) NOT NULL,
                `user_id` INTEGER NULL,
                `source_plugin` VARCHAR(64) NOT NULL,
                `source_reference` VARCHAR(128) NOT NULL,
                `base_amount` BIGINT NOT NULL,
                `base_currency` VARCHAR(10) NOT NULL DEFAULT 'BDT',
                `charge_amount` BIGINT NOT NULL,
                `charge_currency` VARCHAR(10) NOT NULL DEFAULT 'BDT',
                `status` VARCHAR(32) NOT NULL DEFAULT 'succeeded',
                `created_at` DATETIME NULL
            );
        ");

        $this->app->singleton(Database::class, fn () => $this->sqliteDb);

        $this->productRepo = new ProductRepository($this->sqliteDb);
        $this->orderRepo = new OrderRepository($this->sqliteDb);
        $this->walletRepo = new WalletRepository($this->sqliteDb);
        $this->entitlementRepo = new EntitlementRepository($this->sqliteDb);
        $this->membershipService = new MembershipLifecycleService($this->productRepo);

        // Mock Favorite Pay Wallet Service
        $this->payBalances = [];
        $this->mockPayWalletService = new class($this->sqliteDb, $this->payBalances) implements WalletServiceInterface {
            private Database $db;
            private array $balances;

            public function __construct(Database $db, array &$balances)
            {
                $this->db = $db;
                $this->balances = &$balances;
            }

            public function getBalance(int $userId): Money
            {
                $minor = $this->balances[$userId] ?? 0;
                return new Money($minor, 'BDT');
            }

            public function getAvailableBalance(int $userId): Money
            {
                return $this->getBalance($userId);
            }

            public function deposit(int $userId, Money $amount, string $referenceId, string $description = ''): WalletLedgerEntry
            {
                $curr = $this->balances[$userId] ?? 0;
                $new = $curr + $amount->getAmount();
                $this->balances[$userId] = $new;
                return new WalletLedgerEntry(
                    'led_' . bin2hex(random_bytes(6)),
                    $userId,
                    'credit',
                    $amount,
                    new Money($new, 'BDT'),
                    'deposit',
                    $referenceId,
                    $description
                );
            }

            public function debit(int $userId, Money $amount, string $referenceId, string $description = ''): WalletLedgerEntry
            {
                $curr = $this->balances[$userId] ?? 0;
                if ($curr < $amount->getAmount()) {
                    throw new \RuntimeException("Insufficient funds in Favorite Pay wallet.");
                }
                $new = $curr - $amount->getAmount();
                $this->balances[$userId] = $new;
                return new WalletLedgerEntry(
                    'led_' . bin2hex(random_bytes(6)),
                    $userId,
                    'debit',
                    $amount,
                    new Money($new, 'BDT'),
                    'purchase',
                    $referenceId,
                    $description
                );
            }

            public function hold(int $userId, Money $amount, string $referenceId): WalletLedgerEntry
            {
                return new WalletLedgerEntry('led_h', $userId, 'hold', $amount, $this->getBalance($userId), 'hold', $referenceId);
            }

            public function releaseHold(int $userId, Money $amount, string $referenceId): WalletLedgerEntry
            {
                return new WalletLedgerEntry('led_r', $userId, 'release', $amount, $this->getBalance($userId), 'release', $referenceId);
            }

            public function finalizeHold(int $userId, Money $amount, string $referenceId, string $description = 'completed'): WalletLedgerEntry
            {
                return new WalletLedgerEntry('led_f', $userId, 'debit', $amount, $this->getBalance($userId), 'withdrawal', $referenceId, $description);
            }

            public function settleSuccessfulPayment(string $transactionId): WalletLedgerEntry
            {
                $row = $this->db->selectOne("SELECT user_id, base_amount FROM favorite_pay_transactions WHERE transaction_id = ?", [$transactionId]);
                $userId = $row ? (int)$row->user_id : 1;
                $amount = $row ? new Money((int)$row->base_amount, 'BDT') : new Money(30000, 'BDT');
                return $this->deposit($userId, $amount, $transactionId, "Wallet settlement for payment {$transactionId}");
            }

            public function getLedgerHistory(int $userId, int $limit = 50, int $offset = 0): array { return []; }
            public function getHeldBalance(int $userId): Money { return new Money(0, 'BDT'); }
            public function getTotalBalance(int $userId): Money { return $this->getBalance($userId); }
            public function getFilteredLedgerHistory(int $userId, array $filters = [], int $limit = 20, int $offset = 0): array { return []; }
            public function getFilteredLedgerCount(int $userId, array $filters = []): int { return 0; }
            public function getLedgerEntry(string $entryId, ?int $userId = null): ?WalletLedgerEntry { return null; }
            public function getGlobalWalletOverview(): array { return ['total_wallets' => 1, 'total_balance' => new Money(0, 'BDT'), 'available_balance' => new Money(0, 'BDT'), 'held_balance' => new Money(0, 'BDT'), 'currency' => 'BDT']; }
            public function searchCustomerWallets(string $query, int $limit = 20): array { return []; }
            public function getGlobalRecentActivity(int $limit = 15): array { return []; }
            public function getWalletCurrency(int $userId): string { return 'BDT'; }
            public function getPrimaryCurrency(): string { return 'BDT'; }
            public function hasActivity(): bool { return true; }
            public function hasWallets(): bool { return true; }
            public function hasLedgerEntries(): bool { return true; }
            public string $testDynamicProp = 'prop_val_123';
            public function arbitraryConcreteMethod(string $arg): string { return 'delegated_' . $arg; }
        };

        $this->interceptor = new FavoritePayWalletInterceptor($this->mockPayWalletService, $this->app);
        $this->app->instance(WalletServiceInterface::class, $this->interceptor);

        // Mock PaymentServiceInterface
        $this->intents = [];
        $this->mockPayService = new class($this->intents) implements PaymentServiceInterface {
            public array $intents;
            public function __construct(array &$intents) { $this->intents = &$intents; }
            public function createIntent(string $sourcePlugin, string $sourceReference, Money $baseAmount, array $options = []): PaymentIntent
            {
                $id = 'tx_' . bin2hex(random_bytes(6));
                $intent = new PaymentIntent(
                    $id,
                    $sourcePlugin,
                    $sourceReference,
                    $baseAmount,
                    $baseAmount,
                    PaymentStatus::PENDING,
                    null,
                    $options['customer_id'] ?? null,
                    null,
                    $options['metadata'] ?? []
                );
                $this->intents[$id] = $intent;
                return $intent;
            }
            public function getIntent(string $intentId): ?PaymentIntent { return $this->intents[$intentId] ?? null; }
            public function updateIntentStatus(string $intentId, PaymentStatus $newStatus): PaymentIntent
            {
                $intent = $this->intents[$intentId] ?? null;
                if (!$intent) {
                    throw new \InvalidArgumentException("Intent not found: {$intentId}");
                }
                $updated = $intent->withStatus($newStatus);
                $this->intents[$intentId] = $updated;
                return $updated;
            }
            public function initiatePayment(string $intentId, string $gatewayId, array $params = []): PaymentAttempt
            {
                return new PaymentAttempt('att_' . bin2hex(random_bytes(6)), $intentId, $gatewayId, $this->intents[$intentId]->getChargeAmount(), PaymentStatus::PENDING, 'prov_1', null, null, null, null, null, null, ['redirect_url' => 'https://gateway.example.com/pay']);
            }
            public function submitManualVerification(string $intentId, string $gatewayId, string $transactionReference, array $details = []): PaymentAttempt
            {
                return new PaymentAttempt('att_m_' . bin2hex(random_bytes(6)), $intentId, $gatewayId, $this->intents[$intentId]->getChargeAmount(), PaymentStatus::AWAITING_VERIFICATION, $transactionReference);
            }
            public function approveManualPayment(string $attemptId, int $operatorUserId, ?string $notes = null): PaymentAttempt
            {
                return new PaymentAttempt($attemptId, 'tx_appr', 'manual_bkash', new Money(30000, 'BDT'), PaymentStatus::SUCCEEDED, null, null);
            }
            public function rejectManualPayment(string $attemptId, int $operatorUserId, string $reason): PaymentAttempt
            {
                return new PaymentAttempt($attemptId, 'tx_rej', 'manual_bkash', new Money(30000, 'BDT'), PaymentStatus::FAILED, null, null);
            }
            public function getAttempt(string $attemptId): ?PaymentAttempt { return null; }
            public function getAvailablePaymentMethods(?string $currency = null): array { return [['id' => 'manual_bkash', 'label' => 'bKash']]; }
            public function getCheckoutCalculation(PaymentIntent $intent, string $gatewayId): array
            {
                return [
                    'gateway_id'      => $gatewayId,
                    'base_amount'     => $intent->getBaseAmount()->toMajorUnit(),
                    'charge_amount'   => $intent->getChargeAmount()->toMajorUnit(),
                    'base_currency'   => $intent->getBaseAmount()->getCurrency(),
                    'charge_currency' => $intent->getChargeAmount()->getCurrency(),
                ];
            }
            public function getCurrencyService(): CurrencyServiceInterface { return new class implements CurrencyServiceInterface {
                public function getBaseCurrency(): string { return 'BDT'; }
                public function convert(Money $amount, string $targetCurrency): Money { return $amount; }
                public function getRate(string $from, string $to): float { return 1.0; }
                public function getRates(): array { return []; }
                public function updateRates(array $rates): void {}
                public function isSupported(string $currency): bool { return true; }
            }; }
        };
        $this->app->instance(PaymentServiceInterface::class, $this->mockPayService);

        $this->digitalWalletService = new DigitalWalletService($this->walletRepo, $this->sqliteDb, $this->interceptor);
        $this->fulfillmentService = new FulfillmentService(
            $this->orderRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            $this->sqliteDb
        );

        $this->checkoutService = new CheckoutService(
            $this->orderRepo,
            $this->digitalWalletService,
            $this->mockPayService,
            $this->sqliteDb,
            $this->fulfillmentService
        );

        $this->checkoutController = new CustomerCheckoutController($this->app, $this->checkoutService);

        $storage = new DigitalFileStorageService(sys_get_temp_dir());
        $downloadRepo = new \FavoriteCMS\Digital\Repositories\DownloadRepository($this->sqliteDb);
        $this->downloadService = new DownloadService(
            $downloadRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            new DefaultEntitlementChecker($this->sqliteDb, $this->entitlementRepo, $this->membershipService, $this->productRepo),
            $storage,
            $this->sqliteDb
        );

        $this->orderService = new OrderService($this->orderRepo, $this->productRepo, $this->membershipService, new DefaultEntitlementChecker($this->sqliteDb), $this->sqliteDb);
        $this->accountService = new CustomerAccountService(
            $this->entitlementRepo,
            $this->productRepo,
            $this->orderRepo,
            $this->orderService,
            $this->membershipService,
            $this->downloadService,
            new \FavoriteCMS\Digital\Repositories\RefundRepository($this->sqliteDb),
            $this->digitalWalletService,
            $this->sqliteDb
        );

        $this->accountController = new CustomerAccountController($this->app, $this->accountService);
        $this->reconciliationService = new WalletReconciliationService($this->sqliteDb);

        $this->app->instance(MembershipLifecycleService::class, $this->membershipService);
        $this->app->instance(DigitalWalletService::class, $this->digitalWalletService);
        $this->app->instance(WalletReconciliationService::class, $this->reconciliationService);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user_id']);
        \FavoriteCMS\Models\Setting::clearCache();
        \FavoriteCMS\Core\Currency::setPrimaryCurrency('BDT');
    }

    private function createMembershipPlan(string $title, float $price, string $unit = 'month', int $count = 1, bool $autoRenew = true): object
    {
        $productId = $this->membershipService->createPlan(
            [
                'title'          => $title,
                'slug'           => 'plan-' . bin2hex(random_bytes(4)),
                'original_price' => $price,
                'status'         => ProductStatus::PUBLISHED,
            ],
            [
                'plan_type'           => $unit === 'week' ? 'weekly' : 'monthly',
                'duration_unit'       => $unit,
                'duration_count'      => $count,
                'grace_period_days'   => 3,
                'allows_auto_renewal' => $autoRenew ? 1 : 0,
            ]
        );

        $plan = $this->membershipService->getPlanByProductId($productId);
        $product = $this->productRepo->findProduct($productId);
        $plan->product = $product;
        return $plan;
    }

    private function createOrderForCustomer(int $userId, object $plan, float $amount): object
    {
        return $this->orderService->createOrder($userId, [
            [
                'product_id' => (int)$plan->product_id,
                'quantity'   => 1,
            ]
        ]);
    }

    // =========================================================================
    // Test 1: Wallet Payment debits BDT 300 from BDT 500 to BDT 200 without duplicate credit
    // =========================================================================
    public function testWalletPaymentDebitsBalanceAndActivatesMembershipWithoutDuplicateCredit(): void
    {
        $userId = 101;
        $GLOBALS['_test_current_user_id'] = $userId;

        // Customer wallet balance = BDT 500.00 (50000 Poisha)
        $this->mockPayWalletService->deposit($userId, new Money(50000, 'BDT'), 'wrc_init', 'Initial wallet deposit');
        $this->digitalWalletService->credit($userId, '500.00', 'wrc_init', 'Initial deposit', null, 'recharge');

        $this->assertSame('500.00', $this->digitalWalletService->getBalance($userId));
        $this->assertSame(50000, $this->interceptor->getBalance($userId)->getAmount());

        // Create membership plan for BDT 300.00
        $plan = $this->createMembershipPlan('VIP Membership', 300.00);
        $order = $this->createOrderForCustomer($userId, $plan, 300.00);

        // Process payment using wallet balance
        $paidOrder = $this->checkoutService->processWalletPayment((int)$order->id, $userId);

        // 1. Order status must be complete
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $paidOrder->payment_status);

        // 2. Favorite Pay wallet balance MUST be exactly BDT 200.00 (deducted 300.00, not credited back)
        $this->assertSame('200.00', $this->digitalWalletService->getBalance($userId));
        $this->assertSame(20000, $this->interceptor->getBalance($userId)->getAmount());

        // 3. Membership must be ACTIVE
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->assertNotNull($activeMem);
        $this->assertSame(MembershipStatus::ACTIVE, $activeMem->status);
    }

    // =========================================================================
    // Test 2: External Gateway Payment does NOT credit customer wallet
    // =========================================================================
    public function testExternalGatewayPaymentActivatesMembershipWithoutWalletCredit(): void
    {
        $userId = 102;
        $GLOBALS['_test_current_user_id'] = $userId;

        // Customer wallet starts at BDT 0.00
        $this->assertSame('0.00', $this->digitalWalletService->getBalance($userId));
        $this->assertSame(0, $this->interceptor->getBalance($userId)->getAmount());

        // Create membership for BDT 300.00
        $plan = $this->createMembershipPlan('Gold Tier', 300.00);
        $order = $this->createOrderForCustomer($userId, $plan, 300.00);

        // Initiate payment through Favorite Pay gateway
        $result = $this->checkoutService->processFavoritePayPayment((int)$order->id, $userId, 'manual_bkash', ['trx_id' => 'BK123456']);
        $intent = $result['intent'];

        // Mark intent as SUCCEEDED in transaction repository
        $this->sqliteDb->insert('favorite_pay_transactions', [
            'transaction_id'  => $intent->getId(),
            'user_id'         => $userId,
            'source_plugin'   => 'favorite-digital',
            'source_reference'=> (string)$order->id,
            'base_amount'     => 30000,
            'base_currency'   => 'BDT',
            'charge_amount'   => 30000,
            'charge_currency' => 'BDT',
            'status'          => 'succeeded',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // Settle payment in digital checkout
        $this->mockPayService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);
        $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intent->getId());

        // Now trigger the event that Favorite Pay fires: settleSuccessfulPayment()
        $entry = $this->interceptor->settleSuccessfulPayment($intent->getId());

        // Verification:
        // 1. Membership is ACTIVE
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->assertNotNull($activeMem);
        $this->assertSame(MembershipStatus::ACTIVE, $activeMem->status);

        // 2. Customer Wallet Balance MUST STILL BE 0.00 (BDT 300 was spent on the membership!)
        $this->assertSame('0.00', $this->digitalWalletService->getBalance($userId));
        $this->assertSame(0, $this->interceptor->getBalance($userId)->getAmount());
    }

    // =========================================================================
    // Test 3: Genuine Wallet Recharge Still Credits Normally
    // =========================================================================
    public function testGenuineRechargeStillCreditsWalletNormally(): void
    {
        $userId = 103;
        $rechargeRef = 'wrc_' . bin2hex(random_bytes(6));

        // Create recharge transaction
        $this->sqliteDb->insert('favorite_pay_transactions', [
            'transaction_id'  => 'tx_recharge_99',
            'user_id'         => $userId,
            'source_plugin'   => 'favorite-digital',
            'source_reference'=> $rechargeRef,
            'base_amount'     => 30000,
            'base_currency'   => 'BDT',
            'charge_amount'   => 30000,
            'charge_currency' => 'BDT',
            'status'          => 'succeeded',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // Settle through interceptor
        $entry = $this->interceptor->settleSuccessfulPayment('tx_recharge_99');

        // Verification: It is a recharge (wrc_*), so it delegates to Favorite Pay inner wallet service and credits
        $this->assertSame(30000, $this->interceptor->getBalance($userId)->getAmount());
        $this->assertSame('300.00', $this->digitalWalletService->getBalance($userId));
    }

    // =========================================================================
    // Test 4: Active Membership allows content access with BDT 0 wallet balance
    // =========================================================================
    public function testActiveMembershipAllowsContentAccessWithZeroWalletBalance(): void
    {
        $userId = 104;
        $GLOBALS['_test_current_user_id'] = $userId;

        // Customer has BDT 0.00 in wallet
        $this->assertSame('0.00', $this->digitalWalletService->getBalance($userId));

        // Create membership and activate
        $plan = $this->createMembershipPlan('VIP Pass', 300.00);
        $this->membershipService->activateMembership($userId, (int)$plan->id);

        // Create digital resource covered by membership
        $digitalId = $this->productRepo->createProduct([
            'title'          => 'Exclusive E-Book',
            'slug'           => 'exclusive-ebook',
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => 50.00,
            'final_price'    => 50.00,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $this->productRepo->saveProductDetails($digitalId, [
            'file_name'              => 'book.pdf',
            'file_size'              => 1024,
            'file_path'              => 'book.pdf',
            'is_membership_eligible' => 1,
        ]);

        $checker = new DefaultEntitlementChecker($this->sqliteDb, $this->entitlementRepo, $this->membershipService, $this->productRepo);
        $this->assertTrue($checker->hasAccess($userId, $digitalId));
    }

    // =========================================================================
    // Test 5: Inactive / No Membership Denies Content Access
    // =========================================================================
    public function testNoActiveMembershipDeniesExclusiveAccess(): void
    {
        $userId = 105;
        $GLOBALS['_test_current_user_id'] = $userId;

        $digitalId = $this->productRepo->createProduct([
            'title'          => 'VIP Guide',
            'slug'           => 'vip-guide',
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => 100.00,
            'final_price'    => 100.00,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $this->productRepo->saveProductDetails($digitalId, [
            'file_name'              => 'vip.pdf',
            'file_size'              => 2048,
            'file_path'              => 'vip.pdf',
            'is_membership_eligible' => 1,
        ]);

        $checker = new DefaultEntitlementChecker($this->sqliteDb, $this->entitlementRepo, $this->membershipService, $this->productRepo);
        $this->assertFalse($checker->hasAccess($userId, $digitalId));
    }

    // =========================================================================
    // Test 6: Account Menu Registers Membership at Order 12 (Between Profile 10 and Wallet 14)
    // =========================================================================
    public function testAccountMenuRegistersMembershipAtOrder12(): void
    {
        $plugin = new FavoriteDigitalPlugin($this->app);
        $plugin->registerAccountMenuItems();

        $items = AccountMenu::getAllItems();
        $this->assertArrayHasKey('digital_membership', $items);
        $item = $items['digital_membership'];

        $this->assertSame('Membership', $item['label']);
        $this->assertSame('/account/membership', $item['url']);
        $this->assertSame(12, $item['order']);
        $this->assertSame('favorite-digital', $item['plugin']);
        $this->assertStringContainsString('<svg', (string)$item['icon']);
    }

    // =========================================================================
    // Test 7: Customer Membership Page Renders Empty State When Inactive
    // =========================================================================
    public function testCustomerMembershipPageRendersEmptyStateWhenInactive(): void
    {
        $userId = 106;
        $GLOBALS['_test_current_user_id'] = $userId;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/membership']);
        $html = (string)$this->accountController->membership($request);

        $this->assertStringContainsString('No active membership', $html);
        $this->assertStringContainsString('Choose a Membership', $html);
        $this->assertStringContainsString('/store?product_type=membership', $html);
        $this->assertNotEmpty($html);
    }

    // =========================================================================
    // Test 8: Customer Membership Page Renders Active State with Remaining Days & Auto-Renewal
    // =========================================================================
    public function testCustomerMembershipPageRendersActiveStateDetails(): void
    {
        $userId = 107;
        $GLOBALS['_test_current_user_id'] = $userId;

        $plan = $this->createMembershipPlan('Platinum VIP Pass', 500.00, 'month', 1, true);
        $this->membershipService->activateMembership($userId, (int)$plan->id, true);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/membership']);
        $html = (string)$this->accountController->membership($request);

        $this->assertStringContainsString('Platinum VIP Pass', $html);
        $this->assertStringContainsString('ACTIVE', $html);
        $this->assertStringContainsString('Auto-renewal is ON', $html);
        $this->assertStringContainsString('Turn Off Auto-Renewal', $html);
        $this->assertStringContainsString('/account/membership/toggle-auto-renew', $html);
        $this->assertStringContainsString('days remaining', $html);
        $this->assertNotEmpty($html);
    }

    // =========================================================================
    // Test 9: Auto-Renewal Toggle POST with CSRF and Ownership Protection
    // =========================================================================
    public function testToggleAutoRenewRequiresCsrfAndTogglesPreference(): void
    {
        $userId = 108;
        $GLOBALS['_test_current_user_id'] = $userId;

        $plan = $this->createMembershipPlan('VIP Monthly', 300.00, 'month', 1, true);
        $mem = $this->membershipService->activateMembership($userId, (int)$plan->id, false);
        $this->assertSame(0, (int)$mem->auto_renew);

        // 1. Invalid CSRF token fails and does not toggle
        $_SESSION['_token'] = 'valid-token';
        $badReq = new Request([], ['_token' => 'invalid-attack'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/membership/toggle-auto-renew']);
        $resp = $this->accountController->toggleAutoRenew($badReq);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertStringContainsString('CSRF failure', $_SESSION['flash_error'] ?? '');
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->assertSame(0, (int)$activeMem->auto_renew);

        // 2. Valid CSRF token toggles to ON
        $goodReq = new Request([], ['_token' => 'valid-token'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/membership/toggle-auto-renew']);
        $resp = $this->accountController->toggleAutoRenew($goodReq);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('Auto-renewal has been turned on.', $_SESSION['flash_success']);
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->assertSame(1, (int)$activeMem->auto_renew);

        // 3. Valid CSRF token toggles back to OFF
        $goodReq2 = new Request([], ['_token' => 'valid-token'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/membership/toggle-auto-renew']);
        $resp2 = $this->accountController->toggleAutoRenew($goodReq2);

        $this->assertSame(302, $resp2->getStatusCode());
        $this->assertSame('Auto-renewal has been turned off.', $_SESSION['flash_success']);
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->assertSame(0, (int)$activeMem->auto_renew);
    }

    // =========================================================================
    // Test 10: Safe Idempotent Reconciliation of Prior Erroneous Membership Credits
    // =========================================================================
    public function testWalletReconciliationServiceIdempotency(): void
    {
        $userId = 109;

        // Setup customer wallet in favorite_pay_wallets with erroneous 30000 (300 BDT) balance
        $this->sqliteDb->insert('favorite_pay_wallets', [
            'user_id'          => $userId,
            'currency'         => 'BDT',
            'balance_amount'   => 30000,
            'available_amount' => 30000,
            'held_amount'      => 0,
            'status'           => 'active',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $walletId = $this->sqliteDb->lastInsertId();

        // Create membership order
        $plan = $this->createMembershipPlan('VIP Pass', 300.00);
        $order = $this->createOrderForCustomer($userId, $plan, 300.00);

        // Insert transaction record
        $this->sqliteDb->insert('favorite_pay_transactions', [
            'transaction_id'  => 'tx_erroneous_mem_1',
            'user_id'         => $userId,
            'source_plugin'   => 'favorite-digital',
            'source_reference'=> (string)$order->id,
            'base_amount'     => 30000,
            'base_currency'   => 'BDT',
            'charge_amount'   => 30000,
            'charge_currency' => 'BDT',
            'status'          => 'succeeded',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // Insert the erroneous credit entry into favorite_pay_wallet_entries
        $this->sqliteDb->insert('favorite_pay_wallet_entries', [
            'entry_id'        => 'led_erroneous_credit_1',
            'wallet_id'       => (int)$walletId,
            'user_id'         => $userId,
            'type'            => 'credit',
            'amount'          => 30000,
            'balance_after'   => 30000,
            'reference_type'  => 'payment',
            'reference_id'    => 'tx_erroneous_mem_1',
            'description'     => 'Erroneous credit for membership payment',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // Run reconciliation
        $result = $this->reconciliationService->reconcile();
        $this->assertSame(1, $result['reconciled_count']);

        // Assert wallet balance was corrected to 0
        $wallet = $this->sqliteDb->selectOne("SELECT * FROM favorite_pay_wallets WHERE id = ?", [$walletId]);
        $this->assertSame(0, (int)$wallet->balance_amount);
        $this->assertSame(0, (int)$wallet->available_amount);

        // Assert compensating debit entry was recorded
        $recEntry = $this->sqliteDb->selectOne("SELECT * FROM favorite_pay_wallet_entries WHERE reference_type = 'reconciliation' LIMIT 1");
        $this->assertNotNull($recEntry);
        $this->assertSame('debit', $recEntry->type);
        $this->assertSame(30000, (int)$recEntry->amount);
        $this->assertSame(0, (int)$recEntry->balance_after);

        // Run reconciliation a second time: MUST BE IDEMPOTENT (0 reconciled)
        $resultSecond = $this->reconciliationService->reconcile();
        $this->assertSame(0, $resultSecond['reconciled_count']);

        // Wallet remains 0
        $walletAfter = $this->sqliteDb->selectOne("SELECT * FROM favorite_pay_wallets WHERE id = ?", [$walletId]);
        $this->assertSame(0, (int)$walletAfter->balance_amount);
    }

    public function testFavoritePayWalletInterceptorExplicitAndDynamicDelegation(): void
    {
        // 1. Explicit delegation methods
        $this->assertSame('BDT', $this->interceptor->getWalletCurrency(1));
        $this->assertSame('BDT', $this->interceptor->getPrimaryCurrency());
        $this->assertTrue($this->interceptor->hasActivity());
        $this->assertTrue($this->interceptor->hasWallets());
        $this->assertTrue($this->interceptor->hasLedgerEntries());

        // method_exists check for static/framework introspection
        $this->assertTrue(method_exists($this->interceptor, 'getWalletCurrency'));
        $this->assertTrue(method_exists($this->interceptor, 'getPrimaryCurrency'));
        $this->assertTrue(method_exists($this->interceptor, 'hasActivity'));
        $this->assertTrue(method_exists($this->interceptor, 'hasWallets'));
        $this->assertTrue(method_exists($this->interceptor, 'hasLedgerEntries'));

        // 2. Dynamic __call delegation for arbitrary concrete methods
        $this->assertSame('delegated_test_arg', $this->interceptor->arbitraryConcreteMethod('test_arg'));

        // 3. Dynamic __get and __isset delegation
        $this->assertTrue(isset($this->interceptor->testDynamicProp));
        $this->assertSame('prop_val_123', $this->interceptor->testDynamicProp);

        // 4. Fallback behavior when inner service lacks concrete methods
        $minimalInner = new class implements WalletServiceInterface {
            public function getBalance(int $id): Money { return new Money(0, 'BDT'); }
            public function getAvailableBalance(int $id): Money { return new Money(0, 'BDT'); }
            public function deposit(int $id, Money $m, string $r, string $d = ''): WalletLedgerEntry { return new WalletLedgerEntry('1', $id, 'credit', $m, $m, 'dep', $r); }
            public function debit(int $id, Money $m, string $r, string $d = ''): WalletLedgerEntry { return new WalletLedgerEntry('1', $id, 'debit', $m, $m, 'deb', $r); }
            public function hold(int $id, Money $m, string $r): WalletLedgerEntry { return new WalletLedgerEntry('1', $id, 'hold', $m, $m, 'hld', $r); }
            public function releaseHold(int $id, Money $m, string $r): WalletLedgerEntry { return new WalletLedgerEntry('1', $id, 'rel', $m, $m, 'rel', $r); }
            public function finalizeHold(int $id, Money $m, string $r, string $d = ''): WalletLedgerEntry { return new WalletLedgerEntry('1', $id, 'fin', $m, $m, 'fin', $r); }
            public function settleSuccessfulPayment(string $tx): WalletLedgerEntry { return new WalletLedgerEntry('1', 1, 'credit', new Money(0, 'BDT'), new Money(0, 'BDT'), 'set', $tx); }
            public function getLedgerHistory(int $id, int $l = 50, int $o = 0): array { return []; }
            public function getHeldBalance(int $id): Money { return new Money(0, 'BDT'); }
            public function getTotalBalance(int $id): Money { return new Money(0, 'BDT'); }
            public function getFilteredLedgerHistory(int $id, array $f = [], int $l = 20, int $o = 0): array { return []; }
            public function getFilteredLedgerCount(int $id, array $f = []): int { return 0; }
            public function getLedgerEntry(string $e, ?int $u = null): ?WalletLedgerEntry { return null; }
            public function getGlobalWalletOverview(): array { return []; }
            public function searchCustomerWallets(string $q, int $l = 20): array { return []; }
            public function getGlobalRecentActivity(int $l = 15): array { return []; }
        };

        $fallbackInterceptor = new FavoritePayWalletInterceptor($minimalInner, $this->app);
        $this->assertSame('BDT', $fallbackInterceptor->getWalletCurrency(1));
        $this->assertSame('BDT', $fallbackInterceptor->getPrimaryCurrency());
        $this->assertFalse($fallbackInterceptor->hasActivity());
        $this->assertFalse($fallbackInterceptor->hasWallets());
        $this->assertFalse($fallbackInterceptor->hasLedgerEntries());
    }

    public function testFavoritePayCustomerAccountControllerWalletRendersSuccessfullyWithInterceptorBound(): void
    {
        require_once dirname(__DIR__, 4) . '/plugins/favorite-pay/autoload.php';

        $currencyService = new CurrencyService();
        $gatewayRegistry = new GatewayRegistry();
        $paymentService = new \FavoriteCMS\Pay\Services\PaymentService($currencyService, $gatewayRegistry);

        $payController = new FavoritePayCustomerAccountController(
            $this->app,
            $this->interceptor,
            $paymentService,
            $gatewayRegistry,
            $currencyService,
            $this->sqliteDb
        );

        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'customer1';
        $_SESSION['_csrf_token'] = 'test_token_123';

        $GLOBALS['_test_current_user'] = new class extends User {
            public function __construct() {
                $this->attributes = ['id' => 1, 'username' => 'customer1', 'email' => 'customer1@example.com', 'status' => 'active'];
            }
            public function isSuspended(): bool { return false; }
            public function isBanned(): bool { return false; }
            public function hasRole(string $r): bool { return true; }
        };

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);

        $response = $payController->wallet($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Recharge Wallet', $response->getContent());
        $this->assertStringContainsString('Total Wallet Balance', $response->getContent());
    }

    /**
     * Verifies that when CMS Primary Currency changes to INR:
     * 1. Active wallet denomination changes to INR while numeric balance is preserved (500 BDT -> 500 INR, no FX conversion).
     * 2. Historical transactions preserve their original recorded currency.
     * 3. Membership purchase with wallet: 500 INR - 300 INR = 200 INR balance + ACTIVE membership (zero duplicate credit).
     * 4. Currency symbols format accurately (INR = ₹).
     */
    public function testGlobalCurrencyChangeDenominatesWalletToInrAndMaintainsAccountingSeparation(): void
    {
        $userId = 105;
        $GLOBALS['_test_current_user_id'] = $userId;

        // Register hook listener
        \FavoriteCMS\Core\Hook::addAction('currency.primary_changed', [\FavoriteCMS\Digital\FavoriteDigitalPlugin::class, 'handlePrimaryCurrencyChanged']);

        // 1. Initial state: BDT wallet balance = 500.00
        \FavoriteCMS\Core\Currency::setPrimaryCurrency('BDT');
        $this->assertSame('BDT', \FavoriteCMS\Core\Currency::getPrimaryCurrency());
        $this->assertSame('৳', \FavoriteCMS\Core\Currency::getSymbol('BDT'));

        $this->mockPayWalletService->deposit($userId, new Money(50000, 'BDT'), 'wrc_init_105', 'Initial deposit');
        $this->digitalWalletService->credit($userId, '500.00', 'wrc_init_105', 'Initial deposit', null, 'recharge');

        $this->assertSame('500.00', $this->digitalWalletService->getBalance($userId));
        $walletRowBefore = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_wallets` WHERE `user_id` = ?", [$userId]);
        $this->assertSame('BDT', $walletRowBefore->currency);
        $this->assertEquals('500.00', $walletRowBefore->balance_amount);

        // 2. Change CMS Primary Currency to INR
        \FavoriteCMS\Core\Currency::setPrimaryCurrency('INR');
        $this->assertSame('INR', \FavoriteCMS\Core\Currency::getPrimaryCurrency());
        $this->assertSame('₹', \FavoriteCMS\Core\Currency::getSymbol('INR'));

        // 3. Denomination changes to INR, numeric balance is NOT multiplied or divided by FX
        $walletRowAfter = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_wallets` WHERE `user_id` = ?", [$userId]);
        $this->assertSame('INR', $walletRowAfter->currency);
        $this->assertEquals('500.00', $walletRowAfter->balance_amount);

        // 4. Create membership plan for 300.00 INR
        $plan = $this->createMembershipPlan('Developer Pro INR', 300.00);
        $this->assertSame('INR', $plan->product->currency);

        $order = $this->createOrderForCustomer($userId, $plan, 300.00);
        $this->assertSame('INR', $order->currency);

        // 5. Purchase membership using wallet balance
        $paidOrder = $this->checkoutService->processWalletPayment((int)$order->id, $userId);

        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $paidOrder->payment_status);
        $this->assertSame('200.00', $this->digitalWalletService->getBalance($userId));

        // 6. Membership is active, zero duplicate credit
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->assertNotNull($activeMem);
        $this->assertSame(MembershipStatus::ACTIVE, $activeMem->status);

        // Historical deposit transaction retains original reference
        $tx = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_wallet_transactions` WHERE `reference_id` = 'wrc_init_105'");
        $this->assertNotNull($tx);
        $this->assertEquals('500.00', $tx->amount);
    }
}

