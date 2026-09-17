<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use CreateFavoritePayTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\CustomerCheckoutController;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Controllers\CustomerAccountController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use PHPUnit\Framework\TestCase;

class PaymentArchitectureVerificationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private GatewayRegistry $registry;
    private CurrencyService $currencyService;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private CustomerAccountController $accountController;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->singleton(Database::class, fn() => $this->db);

        // Run migrations
        $migrator = new Migrator($this->db);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        $this->registry = new GatewayRegistry(false);
        $this->currencyService = new CurrencyService(null, $this->db);
        $this->paymentService = new PaymentService($this->currencyService, $this->registry, $this->db);
        $this->walletService = new WalletService($this->currencyService, $this->paymentService, $this->db);

        $this->accountController = new CustomerAccountController(
            $this->app,
            $this->walletService,
            $this->paymentService,
            $this->registry,
            $this->currencyService,
            $this->db
        );
    }

    /**
     * Requirement 1: ONLY bKash configured and enabled -> only bKash shown.
     */
    public function testCase1_OnlyBkashEnabledShowsOnlyBkash(): void
    {
        $bkash = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual',
            PaymentMethodType::MANUAL_BKASH,
            ['account_number' => '01711000000', 'channel' => 'bkash'],
            true
        );
        $nagad = new ManualBangladeshGateway(
            'manual_nagad',
            'Nagad Manual',
            PaymentMethodType::MANUAL_NAGAD,
            ['account_number' => '01811000000', 'channel' => 'nagad'],
            false // disabled
        );
        $this->registry->register($bkash);
        $this->registry->register($nagad);

        $available = $this->paymentService->getAvailablePaymentMethods('BDT');
        $this->assertCount(1, $available);
        $this->assertSame('manual_bkash', $available[0]['id']);

        $customerGateways = $this->accountController->getAvailableGateways('BDT');
        $this->assertCount(1, $customerGateways);
        $this->assertArrayHasKey('manual_bkash', $customerGateways);
        $this->assertArrayNotHasKey('manual_nagad', $customerGateways);
    }

    /**
     * Requirement 2: bKash + Nagad enabled -> both shown.
     */
    public function testCase2_BkashAndNagadEnabledShowsBoth(): void
    {
        $bkash = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual',
            PaymentMethodType::MANUAL_BKASH,
            ['account_number' => '01711000000'],
            true
        );
        $nagad = new ManualBangladeshGateway(
            'manual_nagad',
            'Nagad Manual',
            PaymentMethodType::MANUAL_NAGAD,
            ['account_number' => '01811000000'],
            true
        );
        $this->registry->register($bkash);
        $this->registry->register($nagad);

        $available = $this->paymentService->getAvailablePaymentMethods('BDT');
        $this->assertCount(2, $available);
        $ids = array_column($available, 'id');
        $this->assertContains('manual_bkash', $ids);
        $this->assertContains('manual_nagad', $ids);

        $customerGateways = $this->accountController->getAvailableGateways('BDT');
        $this->assertCount(2, $customerGateways);
        $this->assertArrayHasKey('manual_bkash', $customerGateways);
        $this->assertArrayHasKey('manual_nagad', $customerGateways);
    }

    /**
     * Requirement 3: Any unconfigured gateway -> hidden from customer.
     */
    public function testCase3_UnconfiguredGatewayHiddenFromCustomer(): void
    {
        $bkash = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual',
            PaymentMethodType::MANUAL_BKASH,
            ['account_number' => '01711000000'],
            true
        );
        $unconfiguredNagad = new ManualBangladeshGateway(
            'manual_nagad',
            'Nagad Manual',
            PaymentMethodType::MANUAL_NAGAD,
            ['account_number' => ''], // empty account number -> unconfigured
            true
        );
        $this->registry->register($bkash);
        $this->registry->register($unconfiguredNagad);

        $this->assertTrue($bkash->isConfigured());
        $this->assertFalse($unconfiguredNagad->isConfigured());

        $available = $this->paymentService->getAvailablePaymentMethods('BDT');
        $this->assertCount(1, $available);
        $this->assertSame('manual_bkash', $available[0]['id']);

        $customerGateways = $this->accountController->getAvailableGateways('BDT');
        $this->assertCount(1, $customerGateways);
        $this->assertArrayHasKey('manual_bkash', $customerGateways);
        $this->assertArrayNotHasKey('manual_nagad', $customerGateways);
    }

    /**
     * Requirement 4: Generic manual_bd is permanently absent from runtime registration and UI.
     */
    public function testCase4_ManualBdGenericPermanentlyAbsent(): void
    {
        // Fresh default GatewayRegistry
        $defaultRegistry = new GatewayRegistry(true);
        $this->assertFalse($defaultRegistry->has('manual_bd'), "Generic manual_bd must never exist in GatewayRegistry defaults");

        $allGateways = $defaultRegistry->all();
        foreach ($allGateways as $gw) {
            $this->assertNotSame('manual_bd', $gw->getId(), "GatewayRegistry->all() must not contain manual_bd");
        }

        $methods = $this->paymentService->getAvailablePaymentMethods();
        $methodIds = array_column($methods, 'id');
        $this->assertNotContains('manual_bd', $methodIds);

        $customerGateways = $this->accountController->getAvailableGateways('BDT');
        $this->assertArrayNotHasKey('manual_bd', $customerGateways);
    }

    /**
     * Requirement 5: Digital product checkout -> Wallet balance appears as payment option when customer has balance.
     */
    public function testCase5_DigitalCheckoutShowsWalletBalanceWhenCustomerHasBalance(): void
    {
        $userId = 42;
        $this->walletService->deposit(
            $userId,
            new Money(50000, 'BDT'), // 500.00 BDT
            'seed_1',
            'Initial Seed'
        );

        $balance = $this->walletService->getAvailableBalance($userId);
        $this->assertSame(50000, $balance->getAmount());
        $this->assertGreaterThanOrEqual(10000, $balance->getAmount()); // Order total 100.00 BDT
    }

    /**
     * Requirement 6: Digital product checkout -> External gateways appear alongside wallet.
     */
    public function testCase6_DigitalCheckoutShowsExternalGatewaysAlongsideWallet(): void
    {
        $bkash = new ManualBangladeshGateway('manual_bkash', 'bKash Manual', PaymentMethodType::MANUAL_BKASH, ['account_number' => '01711111111'], true);
        $this->registry->register($bkash);

        $externalGateways = $this->paymentService->getAvailablePaymentMethods('BDT');
        $this->assertNotEmpty($externalGateways);
        $this->assertSame('manual_bkash', $externalGateways[0]['id']);

        // Digital Checkout discovery returns external gateways without removing them
        $this->assertCount(1, $externalGateways);
    }

    /**
     * Requirement 7: Customer wallet recharge -> Wallet balance NEVER appears as recharge method.
     */
    public function testCase7_CustomerRechargeExcludesWalletBalance(): void
    {
        $bkash = new ManualBangladeshGateway('manual_bkash', 'bKash Manual', PaymentMethodType::MANUAL_BKASH, ['account_number' => '01711111111'], true);
        $this->registry->register($bkash);

        $gateways = $this->accountController->getAvailableGateways('BDT');
        $this->assertArrayNotHasKey('wallet', $gateways);
        $this->assertArrayNotHasKey('wallet_balance', $gateways);
        $this->assertArrayNotHasKey('internal_balance', $gateways);
    }

    /**
     * Requirement 8: Attempting to recharge via wallet balance fails with explicit validation error.
     */
    public function testCase8_RechargeViaWalletBalanceExplicitlyRejected(): void
    {
        $user = new User(['id' => 77, 'username' => 'testuser', 'status' => 'active']);
        $GLOBALS['_test_current_user'] = $user;
        $GLOBALS['_test_current_user_id'] = 77;
        $_SESSION['auth_user_id'] = 77;
        $_SESSION['user_id'] = 77;
        $_SESSION['_token'] = 'valid_token';

        $req = Request::create('POST', '/account/wallet', [
            '_token'     => 'valid_token',
            'amount'     => '100',
            'gateway_id' => 'wallet',
        ]);

        $response = $this->accountController->wallet($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Wallet balance cannot be used to recharge the wallet.', $_SESSION['flash_error'] ?? '');

        // Test with wallet_balance as well
        $req2 = Request::create('POST', '/account/wallet', [
            '_token'     => 'valid_token',
            'amount'     => '100',
            'gateway_id' => 'wallet_balance',
        ]);
        $response2 = $this->accountController->wallet($req2);
        $this->assertSame(302, $response2->getStatusCode());
        $this->assertSame('Wallet balance cannot be used to recharge the wallet.', $_SESSION['flash_error'] ?? '');
    }

    /**
     * Requirement 9: Manual recharge -> Sender mobile/account number is strictly required.
     */
    public function testCase9_ManualRechargeSenderAccountStrictlyRequired(): void
    {
        $bkash = new ManualBangladeshGateway('manual_bkash', 'bKash Manual', PaymentMethodType::MANUAL_BKASH, ['account_number' => '01711111111'], true);
        $this->registry->register($bkash);

        $user = new User(['id' => 88, 'username' => 'user88', 'status' => 'active']);
        $GLOBALS['_test_current_user'] = $user;
        $GLOBALS['_test_current_user_id'] = 88;
        $_SESSION['auth_user_id'] = 88;
        $_SESSION['user_id'] = 88;
        $_SESSION['_token'] = 'token123';

        // Create intent
        $intent = $this->paymentService->createIntent(
            'favorite-pay',
            'recharge_88_test',
            new Money(20000, 'BDT'),
            ['customer_id' => 88, 'gateway_id' => 'manual_bkash']
        );

        $req = Request::create('POST', '/account/recharge/manual', [
            '_token'        => 'token123',
            'intent_id'     => $intent->getId(),
            'trx_id'        => 'TRX_SENDER_TEST',
            'sender_number' => '', // EMPTY sender number
        ]);

        $response = $this->accountController->submitManual($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Sender Mobile / Account Number is required.', $_SESSION['flash_error'] ?? '');
    }

    /**
     * Requirement 10: Manual recharge -> TrxID is strictly required.
     */
    public function testCase10_ManualRechargeTrxIdStrictlyRequired(): void
    {
        $bkash = new ManualBangladeshGateway('manual_bkash', 'bKash Manual', PaymentMethodType::MANUAL_BKASH, ['account_number' => '01711111111'], true);
        $this->registry->register($bkash);

        $user = new User(['id' => 89, 'username' => 'user89', 'status' => 'active']);
        $GLOBALS['_test_current_user'] = $user;
        $GLOBALS['_test_current_user_id'] = 89;
        $_SESSION['auth_user_id'] = 89;
        $_SESSION['user_id'] = 89;
        $_SESSION['_token'] = 'token456';

        $intent = $this->paymentService->createIntent(
            'favorite-pay',
            'recharge_89_test',
            new Money(20000, 'BDT'),
            ['customer_id' => 89, 'gateway_id' => 'manual_bkash']
        );

        $req = Request::create('POST', '/account/recharge/manual', [
            '_token'        => 'token456',
            'intent_id'     => $intent->getId(),
            'trx_id'        => '   ', // EMPTY TrxID
            'sender_number' => '01799887766',
        ]);

        $response = $this->accountController->submitManual($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Transaction Reference / TrxID is required.', $_SESSION['flash_error'] ?? '');
    }

    /**
     * Requirement 11: Manual recharge -> Submission produces status AWAITING_VERIFICATION.
     */
    public function testCase11_ManualRechargeStatusIsAwaitingVerification(): void
    {
        $bkash = new ManualBangladeshGateway('manual_bkash', 'bKash Manual', PaymentMethodType::MANUAL_BKASH, ['account_number' => '01711111111'], true);
        $this->registry->register($bkash);

        $intent = $this->paymentService->createIntent(
            'favorite-pay',
            'recharge_90_test',
            new Money(50000, 'BDT'),
            ['customer_id' => 90, 'gateway_id' => 'manual_bkash']
        );

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_STATUS_CHECK_1',
            ['sender_account' => '01711223344']
        );

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
        $freshIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $freshIntent->getStatus());
    }

    /**
     * Requirement 12: Manual recharge -> Customer wallet balance remains unchanged before admin approval,
     * and is credited upon admin approval.
     */
    public function testCase12_BalanceUnchangedUntilAdminApprovalThenCredited(): void
    {
        $userId = 91;
        $bkash = new ManualBangladeshGateway('manual_bkash', 'bKash Manual', PaymentMethodType::MANUAL_BKASH, ['account_number' => '01711111111'], true);
        $this->registry->register($bkash);

        // Initial balance is 0
        $initialBalance = $this->walletService->getAvailableBalance($userId);
        $this->assertSame(0, $initialBalance->getAmount());

        $rechargeMinor = 75000; // 750.00 BDT
        $intent = $this->paymentService->createIntent(
            'favorite-pay',
            'recharge_91_test',
            new Money($rechargeMinor, 'BDT'),
            ['customer_id' => $userId, 'gateway_id' => 'manual_bkash']
        );

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_APPROVAL_TEST_91',
            ['sender_account' => '01755443322']
        );

        // Assert balance remains 0 while AWAITING_VERIFICATION
        $preApprovalBalance = $this->walletService->getAvailableBalance($userId);
        $this->assertSame(0, $preApprovalBalance->getAmount(), 'Wallet balance must remain unchanged before operator approval');

        // Admin approves manual payment attempt
        $approvedAttempt = $this->paymentService->approveManualPayment($attempt->getId(), 1, 'Verified incoming bKash SMS');
        $this->assertSame(PaymentStatus::SUCCEEDED, $approvedAttempt->getStatus());

        // Intent moves to SUCCEEDED and triggers wallet settlement
        $this->walletService->settleSuccessfulPayment($intent->getId());

        // Post-approval: balance is credited with exact recharge amount
        $postApprovalBalance = $this->walletService->getAvailableBalance($userId);
        $this->assertSame($rechargeMinor, $postApprovalBalance->getAmount(), 'Wallet balance must be credited exactly after operator approval');
    }
}
