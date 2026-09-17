<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\AdminMembershipController;
use FavoriteCMS\Digital\Controllers\AdminOrderController;
use FavoriteCMS\Digital\Controllers\AdminPackageController;
use FavoriteCMS\Digital\Controllers\AdminProductController;
use FavoriteCMS\Digital\Controllers\AdminServiceController;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\DownloadRepository;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\RefundRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\BulkActionService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\DownloadService;
use FavoriteCMS\Digital\Services\FulfillmentService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Digital\Services\RefundService;
use FavoriteCMS\Digital\Services\WalletService;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AdminBulkActionsTest
 *
 * Comprehensive test coverage for Favorite Digital Admin Bulk Actions:
 * 1. BulkActionService security validation (POST method, CSRF, auth, RBAC, ID sanitization, safe redirect)
 * 2. AdminProductController bulk actions (publish, draft, archive, empty selection, partial failures)
 * 3. AdminServiceController bulk actions (publish, draft, archive)
 * 4. AdminPackageController bulk actions (publish, draft, archive)
 * 5. AdminMembershipController bulk actions (cancel, expire, enable_auto_renew, disable_auto_renew)
 * 6. AdminOrderController bulk actions (processing, completed, cancelled with refund & entitlement revocation)
 */
class AdminBulkActionsTest extends TestCase
{
    private Application $app;
    private Database $sqliteDb;
    private PDO $sqlitePdo;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;
    private WalletRepository $walletRepo;
    private EntitlementRepository $entitlementRepo;
    private DownloadRepository $downloadRepo;
    private RefundRepository $refundRepo;
    private DigitalFileStorageService $storageService;
    private ProductManagementService $productService;
    private WalletService $walletService;
    private MembershipLifecycleService $membershipService;
    private FulfillmentService $fulfillmentService;
    private DefaultEntitlementChecker $checker;
    private DownloadService $downloadService;
    private RefundService $refundService;
    private OrderService $orderService;
    private BulkActionService $bulkService;
    private string $tempStorageDir;

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

        $this->app->singleton(Database::class, fn () => $this->sqliteDb);

        // Temp storage dir
        $this->tempStorageDir = sys_get_temp_dir() . '/fd_bulk_test_' . uniqid('', true);
        @mkdir($this->tempStorageDir, 0755, true);

        $this->storageService = new DigitalFileStorageService($this->tempStorageDir);
        $this->productRepo    = new ProductRepository($this->sqliteDb);
        $this->orderRepo      = new OrderRepository($this->sqliteDb);
        $this->walletRepo     = new WalletRepository($this->sqliteDb);
        $this->entitlementRepo = new EntitlementRepository($this->sqliteDb);
        $this->downloadRepo   = new DownloadRepository($this->sqliteDb);
        $this->refundRepo     = new RefundRepository($this->sqliteDb);

        $this->productService    = new ProductManagementService($this->productRepo, $this->storageService);
        $this->walletService     = new WalletService($this->walletRepo, $this->sqliteDb);
        $this->membershipService = new MembershipLifecycleService($this->productRepo);
        $this->fulfillmentService = new FulfillmentService(
            $this->orderRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            $this->sqliteDb
        );
        $this->checker = new DefaultEntitlementChecker(
            $this->sqliteDb,
            $this->entitlementRepo,
            $this->membershipService,
            $this->productRepo
        );
        $this->downloadService = new DownloadService(
            $this->downloadRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            $this->checker,
            $this->storageService,
            $this->sqliteDb
        );
        $this->refundService = new RefundService(
            $this->orderRepo,
            $this->refundRepo,
            $this->walletService,
            $this->entitlementRepo,
            $this->membershipService,
            $this->sqliteDb
        );
        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->membershipService,
            $this->checker,
            $this->sqliteDb
        );
        $this->bulkService = new BulkActionService();

        // Bind singletons
        $this->app->singleton(ProductRepository::class, fn () => $this->productRepo);
        $this->app->singleton(ProductManagementService::class, fn () => $this->productService);
        $this->app->singleton(OrderRepository::class, fn () => $this->orderRepo);
        $this->app->singleton(OrderService::class, fn () => $this->orderService);
        $this->app->singleton(FulfillmentService::class, fn () => $this->fulfillmentService);
        $this->app->singleton(EntitlementRepository::class, fn () => $this->entitlementRepo);
        $this->app->singleton(RefundService::class, fn () => $this->refundService);
        $this->app->singleton(BulkActionService::class, fn () => $this->bulkService);

        // Standard admin session
        $_SESSION = [
            'auth_user_id'   => 1,
            'auth_user_name' => 'Admin User',
            '_token'         => 'valid_csrf_token_test',
        ];

        $adminUser = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $adminUser;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempStorageDir)) {
            $files = glob($this->tempStorageDir . '/*');
            if ($files) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
            @rmdir($this->tempStorageDir);
        }
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    // =========================================================================
    // 1. BulkActionService Security & Validation Tests
    // =========================================================================

    public function testBulkServiceRejectsNonPostRequest(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->bulkService->handle($request, ['publish' => fn() => null], '/admin/page/favorite-digital');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Invalid request method. Bulk actions require POST.', $_SESSION['flash_error'] ?? null);
    }

    public function testBulkServiceRejectsInvalidCsrf(): void
    {
        $request = new Request([], ['_token' => 'wrong_token', 'ids' => [1, 2]], ['REQUEST_METHOD' => 'POST']);
        $response = $this->bulkService->handle($request, ['publish' => fn() => null], '/admin/page/favorite-digital');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Security token expired or invalid (CSRF failure). Please try again.', $_SESSION['flash_error'] ?? null);
    }

    public function testBulkServiceRejectsUnauthenticatedUser(): void
    {
        $_SESSION = ['_token' => 'valid_csrf_token_test'];
        unset($GLOBALS['_test_current_user']);

        $request = new Request([], ['_token' => 'valid_csrf_token_test', 'ids' => [1]], ['REQUEST_METHOD' => 'POST']);
        $response = $this->bulkService->handle($request, ['publish' => fn() => null], '/admin/page/favorite-digital');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeaders()['Location'] ?? null);
    }

    public function testBulkServiceRejectsInactiveUser(): void
    {
        $inactiveUser = new class extends User {
            public int $id = 2;
            public function isActive(): bool { return false; }
            public function can(string $capability): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $inactiveUser;
        $_SESSION['auth_user_id'] = 2;

        $request = new Request([], ['_token' => 'valid_csrf_token_test', 'ids' => [1]], ['REQUEST_METHOD' => 'POST']);
        $response = $this->bulkService->handle($request, ['publish' => fn() => null], '/admin/page/favorite-digital');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeaders()['Location'] ?? null);
        $this->assertSame('Your account is inactive or suspended.', $_SESSION['flash_error'] ?? null);
    }

    public function testBulkServiceRejectsUnauthorizedUserLackingManageOptions(): void
    {
        $subscriberUser = new class extends User {
            public int $id = 3;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $subscriberUser;
        $_SESSION['auth_user_id'] = 3;

        $request = new Request([], ['_token' => 'valid_csrf_token_test', 'ids' => [1]], ['REQUEST_METHOD' => 'POST']);
        $response = $this->bulkService->handle($request, ['publish' => fn() => null], '/admin/page/favorite-digital');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/page/favorite-digital', $response->getHeaders()['Location'] ?? null);
        $this->assertSame('You do not have permission to perform bulk actions.', $_SESSION['flash_error'] ?? null);
    }

    public function testRolePermissionConsistencyAcrossRoles(): void
    {
        // 1. Super Admin: has manage_options -> allowed
        $superAdmin = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return $capability === 'manage_options'; }
        };
        $GLOBALS['_test_current_user'] = $superAdmin;
        $_SESSION['auth_user_id'] = 1;
        $req = new Request([], ['_token' => 'valid_csrf_token_test', 'bulk_action' => 'publish', 'ids' => [1]], ['REQUEST_METHOD' => 'POST']);
        $resp = $this->bulkService->handle($req, ['publish' => fn() => null], '/admin/page/favorite-digital');
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('Publish completed successfully for 1 item(s).', $_SESSION['flash_success'] ?? null);

        // 2. Admin: has manage_options -> allowed
        $admin = new class extends User {
            public int $id = 2;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return $capability === 'manage_options'; }
        };
        $GLOBALS['_test_current_user'] = $admin;
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['flash_success'] = null;
        $resp = $this->bulkService->handle($req, ['publish' => fn() => null], '/admin/page/favorite-digital');
        $this->assertSame('Publish completed successfully for 1 item(s).', $_SESSION['flash_success'] ?? null);

        // 3. Editor: has publish_posts/manage_pages/manage_media, but NOT manage_options -> denied
        $editor = new class extends User {
            public int $id = 3;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool {
                return in_array($capability, ['publish_posts', 'manage_pages', 'manage_media', 'manage_seo'], true);
            }
        };
        $GLOBALS['_test_current_user'] = $editor;
        $_SESSION['auth_user_id'] = 3;
        $resp = $this->bulkService->handle($req, ['publish' => fn() => null], '/admin/page/favorite-digital');
        $this->assertSame('You do not have permission to perform bulk actions.', $_SESSION['flash_error'] ?? null);

        // 4. Moderator: has moderate_comments, approve_posts, but NOT manage_options -> denied
        $moderator = new class extends User {
            public int $id = 4;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool {
                return in_array($capability, ['moderate_comments', 'approve_posts'], true);
            }
        };
        $GLOBALS['_test_current_user'] = $moderator;
        $_SESSION['auth_user_id'] = 4;
        $resp = $this->bulkService->handle($req, ['publish' => fn() => null], '/admin/page/favorite-digital');
        $this->assertSame('You do not have permission to perform bulk actions.', $_SESSION['flash_error'] ?? null);

        // 5. Author: has manage_posts for own posts, but NOT manage_options -> denied
        $author = new class extends User {
            public int $id = 5;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool {
                return $capability === 'manage_posts';
            }
        };
        $GLOBALS['_test_current_user'] = $author;
        $_SESSION['auth_user_id'] = 5;
        $resp = $this->bulkService->handle($req, ['publish' => fn() => null], '/admin/page/favorite-digital');
        $this->assertSame('You do not have permission to perform bulk actions.', $_SESSION['flash_error'] ?? null);

        // 6. Subscriber: only view_admin, NO manage_options -> denied
        $subscriber = new class extends User {
            public int $id = 6;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $subscriber;
        $_SESSION['auth_user_id'] = 6;
        $resp = $this->bulkService->handle($req, ['publish' => fn() => null], '/admin/page/favorite-digital');
        $this->assertSame('You do not have permission to perform bulk actions.', $_SESSION['flash_error'] ?? null);
    }

    public function testOrderBulkActionsDeniedForNonAdminWithoutManageOptions(): void
    {
        $controller = new AdminOrderController(
            $this->app,
            $this->orderService,
            $this->fulfillmentService,
            $this->entitlementRepo,
            $this->refundService
        );

        // An Editor attempts to cancel orders in bulk -> denied at controller level
        $editor = new class extends User {
            public int $id = 30;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool {
                return in_array($capability, ['publish_posts', 'manage_pages'], true);
            }
        };
        $GLOBALS['_test_current_user'] = $editor;
        $_SESSION['auth_user_id'] = 30;

        $req = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'cancelled',
            'ids'         => [1],
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->handle($req);
        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testBulkServiceRejectsInvalidOrMissingAction(): void
    {
        $request = new Request([], [
            '_token' => 'valid_csrf_token_test',
            'bulk_action' => 'unsupported_action',
            'ids' => [1],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->bulkService->handle($request, ['publish' => fn() => null], '/admin/page/favorite-digital');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Please select a valid bulk action from the dropdown.', $_SESSION['flash_error'] ?? null);
    }

    public function testBulkServiceRejectsEmptyIds(): void
    {
        $request = new Request([], [
            '_token' => 'valid_csrf_token_test',
            'bulk_action' => 'publish',
            'ids' => [],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->bulkService->handle($request, ['publish' => fn() => null], '/admin/page/favorite-digital');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('No items were selected for the bulk action.', $_SESSION['flash_error'] ?? null);
    }

    public function testBulkServiceSanitizesAndFiltersIds(): void
    {
        $raw = ['1', 2, 'invalid', -5, 0, '3', 2];
        $clean = $this->bulkService->sanitizeIds($raw);

        $this->assertSame([1, 2, 3], $clean);

        // String input
        $fromString = $this->bulkService->sanitizeIds('4, 5, -1, 0, 6, 5');
        $this->assertSame([4, 5, 6], $fromString);
    }

    public function testBulkServiceSafeRedirectPreventsOpenRedirect(): void
    {
        // Internal relative path is allowed
        $reqAllowed = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'bulk_action' => 'publish',
            'ids'         => [1],
            'redirect_to' => '/admin/page/favorite-digital?page=2',
        ], ['REQUEST_METHOD' => 'POST']);

        $respAllowed = $this->bulkService->handle($reqAllowed, ['publish' => fn() => null], '/admin/page/favorite-digital');
        $this->assertSame('/admin/page/favorite-digital?page=2', $respAllowed->getHeaders()['Location'] ?? null);

        // Malicious external URL is rejected and falls back to default
        $reqMalicious = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'bulk_action' => 'publish',
            'ids'         => [1],
            'redirect_to' => 'https://malicious.example.com/steal',
        ], ['REQUEST_METHOD' => 'POST']);

        $respMalicious = $this->bulkService->handle($reqMalicious, ['publish' => fn() => null], '/admin/page/favorite-digital');
        $this->assertSame('/admin/page/favorite-digital', $respMalicious->getHeaders()['Location'] ?? null);
    }

    // =========================================================================
    // 2. AdminProductController Bulk Actions (publish, draft, archive)
    // =========================================================================

    public function testProductBulkActions(): void
    {
        // Seed 3 draft products directly with downloadable files configured
        $p1Id = $this->productRepo->createProduct([
            'title'          => 'Product 1',
            'slug'           => 'product-1-' . uniqid(),
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::DRAFT,
            'original_price' => 10.0,
            'final_price'    => 10.0,
            'currency'       => 'BDT',
        ]);
        $this->sqliteDb->insert('favorite_digital_product_details', [
            'product_id' => $p1Id,
            'file_path'  => 'files/prod1.zip',
            'file_name'  => 'prod1.zip',
            'file_size'  => 1024,
        ]);

        $p2Id = $this->productRepo->createProduct([
            'title'          => 'Product 2',
            'slug'           => 'product-2-' . uniqid(),
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::DRAFT,
            'original_price' => 20.0,
            'final_price'    => 20.0,
            'currency'       => 'BDT',
        ]);
        $this->sqliteDb->insert('favorite_digital_product_details', [
            'product_id' => $p2Id,
            'file_path'  => 'files/prod2.zip',
            'file_name'  => 'prod2.zip',
            'file_size'  => 2048,
        ]);

        $p3Id = $this->productRepo->createProduct([
            'title'          => 'Product 3',
            'slug'           => 'product-3-' . uniqid(),
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::DRAFT,
            'original_price' => 30.0,
            'final_price'    => 30.0,
            'currency'       => 'BDT',
        ]);
        $this->sqliteDb->insert('favorite_digital_product_details', [
            'product_id' => $p3Id,
            'file_path'  => 'files/prod3.zip',
            'file_name'  => 'prod3.zip',
            'file_size'  => 4096,
        ]);

        $controller = new AdminProductController($this->app, $this->productService);

        // A. Bulk Publish p1 and p2
        $requestPublish = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'publish',
            'ids'         => [$p1Id, $p2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->handle($requestPublish);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Publish completed successfully for 2 item(s).', $_SESSION['flash_success'] ?? null);

        $this->assertSame('published', $this->productRepo->findProduct($p1Id)->status);
        $this->assertSame('published', $this->productRepo->findProduct($p2Id)->status);
        $this->assertSame('draft', $this->productRepo->findProduct($p3Id)->status);

        // B. Bulk Draft p1
        $requestDraft = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'draft',
            'ids'         => [$p1Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->handle($requestDraft);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Draft completed successfully for 1 item(s).', $_SESSION['flash_success'] ?? null);
        $this->assertSame('draft', $this->productRepo->findProduct($p1Id)->status);

        // C. Bulk Archive p1, p2, p3
        $requestArchive = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'archive',
            'ids'         => [$p1Id, $p2Id, $p3Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->handle($requestArchive);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Archive completed successfully for 3 item(s).', $_SESSION['flash_success'] ?? null);

        $this->assertSame('archived', $this->productRepo->findProduct($p1Id)->status);
        $this->assertSame('archived', $this->productRepo->findProduct($p2Id)->status);
        $this->assertSame('archived', $this->productRepo->findProduct($p3Id)->status);
    }

    public function testProductBulkActionHandlesEmptySelection(): void
    {
        $controller = new AdminProductController($this->app, $this->productService);

        $request = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'publish',
            'ids'         => [],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->handle($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('No items were selected for the bulk action.', $_SESSION['flash_error'] ?? null);
    }

    public function testProductBulkActionHandlesPartialFailures(): void
    {
        $p1Id = $this->productRepo->createProduct([
            'title'          => 'Existing Product',
            'slug'           => 'existing-prod-' . uniqid(),
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::DRAFT,
            'original_price' => 15.0,
            'final_price'    => 15.0,
            'currency'       => 'BDT',
        ]);
        $this->sqliteDb->insert('favorite_digital_product_details', [
            'product_id' => $p1Id,
            'file_path'  => 'files/existing.zip',
            'file_name'  => 'existing.zip',
            'file_size'  => 1024,
        ]);

        $controller = new AdminProductController($this->app, $this->productService);

        // Submit p1 and non-existent ID 99999
        $request = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'publish',
            'ids'         => [$p1Id, 99999],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->handle($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Publish applied to 1 item(s).', $_SESSION['flash_success'] ?? null);
        $this->assertStringContainsString('1 item(s) failed', $_SESSION['flash_error'] ?? '');
        $this->assertSame('published', $this->productRepo->findProduct($p1Id)->status);
    }

    // =========================================================================
    // 3. AdminServiceController Bulk Actions (publish, draft, archive)
    // =========================================================================

    public function testServiceBulkActions(): void
    {
        $s1Id = $this->productRepo->createProduct([
            'title'          => 'Service 1',
            'slug'           => 'service-1-' . uniqid(),
            'product_type'   => ProductType::SERVICE,
            'status'         => ProductStatus::DRAFT,
            'original_price' => 50.0,
            'final_price'    => 50.0,
            'currency'       => 'BDT',
        ]);
        $s2Id = $this->productRepo->createProduct([
            'title'          => 'Service 2',
            'slug'           => 'service-2-' . uniqid(),
            'product_type'   => ProductType::SERVICE,
            'status'         => ProductStatus::DRAFT,
            'original_price' => 75.0,
            'final_price'    => 75.0,
            'currency'       => 'BDT',
        ]);

        $controller = new AdminServiceController($this->app, $this->productService);

        // Publish
        $reqPub = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'publish',
            'ids'         => [$s1Id, $s2Id],
        ], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->handle($reqPub);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('published', $this->productRepo->findProduct($s1Id)->status);
        $this->assertSame('published', $this->productRepo->findProduct($s2Id)->status);

        // Archive
        $reqArch = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'archive',
            'ids'         => [$s1Id],
        ], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->handle($reqArch);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('archived', $this->productRepo->findProduct($s1Id)->status);
        $this->assertSame('published', $this->productRepo->findProduct($s2Id)->status);
    }

    // =========================================================================
    // 4. AdminPackageController Bulk Actions (publish, draft, archive)
    // =========================================================================

    public function testPackageBulkActions(): void
    {
        // Seed included digital product
        $incId = $this->productRepo->createProduct([
            'title'          => 'Included Item',
            'slug'           => 'included-item-' . uniqid(),
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => 10.0,
            'final_price'    => 10.0,
            'currency'       => 'BDT',
        ]);
        $this->sqliteDb->insert('favorite_digital_product_details', [
            'product_id' => $incId,
            'file_path'  => 'files/inc.zip',
            'file_name'  => 'inc.zip',
            'file_size'  => 512,
        ]);

        // Seed Package 1
        $pkg1Id = $this->productRepo->createProduct([
            'title'          => 'Package 1',
            'slug'           => 'package-1-' . uniqid(),
            'product_type'   => ProductType::PACKAGE,
            'status'         => ProductStatus::DRAFT,
            'original_price' => 120.0,
            'final_price'    => 120.0,
            'currency'       => 'BDT',
        ]);
        $pRow1 = (int)$this->sqliteDb->insert('favorite_digital_packages', [
            'product_id'        => $pkg1Id,
            'package_type'      => 'bundle',
            'total_items_count' => 1,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
        $this->sqliteDb->insert('favorite_digital_package_items', [
            'package_id'          => $pRow1,
            'included_product_id' => $incId,
            'sort_order'          => 1,
        ]);

        // Seed Package 2
        $pkg2Id = $this->productRepo->createProduct([
            'title'          => 'Package 2',
            'slug'           => 'package-2-' . uniqid(),
            'product_type'   => ProductType::PACKAGE,
            'status'         => ProductStatus::DRAFT,
            'original_price' => 180.0,
            'final_price'    => 180.0,
            'currency'       => 'BDT',
        ]);
        $pRow2 = (int)$this->sqliteDb->insert('favorite_digital_packages', [
            'product_id'        => $pkg2Id,
            'package_type'      => 'bundle',
            'total_items_count' => 1,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
        $this->sqliteDb->insert('favorite_digital_package_items', [
            'package_id'          => $pRow2,
            'included_product_id' => $incId,
            'sort_order'          => 1,
        ]);

        $controller = new AdminPackageController($this->app, $this->productService);

        // Publish
        $reqPub = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'publish',
            'ids'         => [$pkg1Id, $pkg2Id],
        ], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->handle($reqPub);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('Publish completed successfully for 2 item(s).', $_SESSION['flash_success'] ?? null);
        $this->assertSame('published', $this->productRepo->findProduct($pkg1Id)->status);
        $this->assertSame('published', $this->productRepo->findProduct($pkg2Id)->status);

        // Draft
        $reqDraft = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'draft',
            'ids'         => [$pkg1Id, $pkg2Id],
        ], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->handle($reqDraft);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('draft', $this->productRepo->findProduct($pkg1Id)->status);
        $this->assertSame('draft', $this->productRepo->findProduct($pkg2Id)->status);
    }

    // =========================================================================
    // 5. AdminMembershipController Bulk Actions (cancel, expire, auto_renew)
    // =========================================================================

    public function testMembershipBulkActions(): void
    {
        // Seed membership plan product
        $planProdId = $this->productRepo->createProduct([
            'title'          => 'Gold Plan',
            'slug'           => 'gold-plan-' . uniqid(),
            'product_type'   => ProductType::MEMBERSHIP,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => 29.99,
            'final_price'    => 29.99,
            'currency'       => 'BDT',
        ]);

        // Insert plan policy tier into favorite_digital_membership_plans with allows_auto_renewal = 1
        $planTierId = (int)$this->sqliteDb->insert('favorite_digital_membership_plans', [
            'product_id'          => $planProdId,
            'plan_type'           => 'monthly',
            'duration_count'      => 30,
            'duration_unit'       => 'day',
            'grace_period_days'   => 3,
            'allows_auto_renewal' => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        // Insert two customer memberships directly into favorite_digital_memberships
        $now = date('Y-m-d H:i:s');
        $future = date('Y-m-d H:i:s', strtotime('+30 days'));

        $m1Id = (int)$this->sqliteDb->insert('favorite_digital_memberships', [
            'user_id'    => 101,
            'plan_id'    => $planTierId,
            'status'     => 'active',
            'started_at' => $now,
            'expires_at' => $future,
            'auto_renew' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $m2Id = (int)$this->sqliteDb->insert('favorite_digital_memberships', [
            'user_id'    => 102,
            'plan_id'    => $planTierId,
            'status'     => 'active',
            'started_at' => $now,
            'expires_at' => $future,
            'auto_renew' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $controller = new AdminMembershipController($this->app, $this->membershipService, $this->productService);

        // A. Enable auto renew for m2
        $reqRenew = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'enable_auto_renew',
            'ids'         => [$m2Id],
        ], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->handle($reqRenew);
        $this->assertSame(302, $resp->getStatusCode());

        $rowM2 = $this->sqliteDb->selectOne("SELECT * FROM favorite_digital_memberships WHERE id = ?", [$m2Id]);
        $this->assertSame(1, (int)$rowM2->auto_renew);

        // B. Disable auto renew for both m1 and m2
        $reqNoRenew = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'disable_auto_renew',
            'ids'         => [$m1Id, $m2Id],
        ], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->handle($reqNoRenew);
        $this->assertSame(302, $resp->getStatusCode());

        $rowM1 = $this->sqliteDb->selectOne("SELECT * FROM favorite_digital_memberships WHERE id = ?", [$m1Id]);
        $rowM2 = $this->sqliteDb->selectOne("SELECT * FROM favorite_digital_memberships WHERE id = ?", [$m2Id]);
        $this->assertSame(0, (int)$rowM1->auto_renew);
        $this->assertSame(0, (int)$rowM2->auto_renew);

        // C. Bulk Expire m1
        $reqExp = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'expire',
            'ids'         => [$m1Id],
        ], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->handle($reqExp);
        $this->assertSame(302, $resp->getStatusCode());

        $rowM1 = $this->sqliteDb->selectOne("SELECT * FROM favorite_digital_memberships WHERE id = ?", [$m1Id]);
        $this->assertSame('expired', $rowM1->status);

        // D. Bulk Cancel m2
        $reqCancel = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'cancel',
            'ids'         => [$m2Id],
        ], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->handle($reqCancel);
        $this->assertSame(302, $resp->getStatusCode());

        $rowM2 = $this->sqliteDb->selectOne("SELECT * FROM favorite_digital_memberships WHERE id = ?", [$m2Id]);
        $this->assertSame('cancelled', $rowM2->status);
    }

    // =========================================================================
    // 6. AdminOrderController Bulk Actions (processing, completed, cancelled)
    // =========================================================================

    public function testOrderBulkActionsStatusTransitions(): void
    {
        $customerId = 201;

        // Create 2 digital products
        $prod1Id = $this->productRepo->createProduct([
            'title'          => 'Item 1',
            'slug'           => 'item-1-' . uniqid(),
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => 50.0,
            'final_price'    => 50.0,
            'currency'       => 'BDT',
        ]);
        $prod2Id = $this->productRepo->createProduct([
            'title'          => 'Item 2',
            'slug'           => 'item-2-' . uniqid(),
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => 75.0,
            'final_price'    => 75.0,
            'currency'       => 'BDT',
        ]);

        // Create 2 pending orders
        $order1Id = $this->orderRepo->createOrder([
            'order_number'       => 'ORD-TEST-001',
            'user_id'            => $customerId,
            'status'             => OrderLifecycleState::STATUS_PENDING,
            'payment_status'     => OrderLifecycleState::PAYMENT_PENDING,
            'fulfillment_status' => OrderLifecycleState::FULFILLMENT_UNFULFILLED,
            'subtotal_amount'    => 50.0,
            'discount_amount'    => 0.0,
            'total_amount'       => 50.0,
            'currency'           => 'BDT',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
        $this->orderRepo->createOrderItem([
            'order_id'         => $order1Id,
            'product_id'       => $prod1Id,
            'product_type'     => ProductType::DIGITAL,
            'unit_price'       => 50.0,
            'discount_percent' => 0.0,
            'final_price'      => 50.0,
            'currency'         => 'BDT',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $order2Id = $this->orderRepo->createOrder([
            'order_number'       => 'ORD-TEST-002',
            'user_id'            => $customerId,
            'status'             => OrderLifecycleState::STATUS_PENDING,
            'payment_status'     => OrderLifecycleState::PAYMENT_PENDING,
            'fulfillment_status' => OrderLifecycleState::FULFILLMENT_UNFULFILLED,
            'subtotal_amount'    => 75.0,
            'discount_amount'    => 0.0,
            'total_amount'       => 75.0,
            'currency'           => 'BDT',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
        $this->orderRepo->createOrderItem([
            'order_id'         => $order2Id,
            'product_id'       => $prod2Id,
            'product_type'     => ProductType::DIGITAL,
            'unit_price'       => 75.0,
            'discount_percent' => 0.0,
            'final_price'      => 75.0,
            'currency'         => 'BDT',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $controller = new AdminOrderController(
            $this->app,
            $this->orderService,
            $this->fulfillmentService,
            $this->entitlementRepo,
            $this->refundService
        );

        // A. Bulk Processing
        $reqProc = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'processing',
            'ids'         => [$order1Id, $order2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->handle($reqProc);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('Mark Processing completed successfully for 2 item(s).', $_SESSION['flash_success'] ?? null);

        $this->assertSame(OrderLifecycleState::STATUS_PROCESSING, $this->orderRepo->findOrder($order1Id)->status);
        $this->assertSame(OrderLifecycleState::STATUS_PROCESSING, $this->orderRepo->findOrder($order2Id)->status);

        // B. Bulk Completed
        $reqComp = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'completed',
            'ids'         => [$order1Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->handle($reqComp);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame(OrderLifecycleState::STATUS_COMPLETED, $this->orderRepo->findOrder($order1Id)->status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $this->orderRepo->findOrder($order1Id)->fulfillment_status);
    }

    public function testOrderBulkCancelWithWalletRefundAndEntitlementRevocation(): void
    {
        $customerId = 301;
        // Credit initial deposit into customer wallet
        $this->walletService->credit($customerId, '200.00', 'initial_dep_' . uniqid(), 'Initial deposit');
        $initialBalance = (float)$this->walletService->getBalance($customerId);

        // Create digital product
        $prodId = $this->productRepo->createProduct([
            'title'          => 'Downloadable Asset',
            'slug'           => 'downloadable-asset-' . uniqid(),
            'product_type'   => ProductType::DIGITAL,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => 45.0,
            'final_price'    => 45.0,
            'currency'       => 'BDT',
        ]);

        // Create paid order
        $orderId = $this->orderRepo->createOrder([
            'order_number'       => 'ORD-PAID-001',
            'user_id'            => $customerId,
            'status'             => OrderLifecycleState::STATUS_PROCESSING,
            'payment_status'     => OrderLifecycleState::PAYMENT_PAID,
            'fulfillment_status' => OrderLifecycleState::FULFILLMENT_FULFILLED,
            'subtotal_amount'    => 45.0,
            'discount_amount'    => 0.0,
            'total_amount'       => 45.0,
            'currency'           => 'BDT',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        // Create order item
        $orderItemId = $this->orderRepo->createOrderItem([
            'order_id'         => $orderId,
            'product_id'       => $prodId,
            'product_type'     => ProductType::DIGITAL,
            'unit_price'       => 45.0,
            'discount_percent' => 0.0,
            'final_price'      => 45.0,
            'currency'         => 'BDT',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        // Create completed payment record so RefundService can calculate authoritative refund amount
        $this->orderRepo->createOrderPayment([
            'order_id'       => $orderId,
            'payment_method' => 'wallet',
            'amount_paid'    => 45.0,
            'currency'       => 'BDT',
            'status'         => 'paid',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        // Grant active entitlement linked to order item (source_type = 'purchase', source_id = $orderItemId)
        $entitlementId = $this->entitlementRepo->createEntitlement([
            'user_id'     => $customerId,
            'product_id'  => $prodId,
            'source_type' => 'purchase',
            'source_id'   => $orderItemId,
            'status'      => 'active',
            'granted_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('active', $this->entitlementRepo->findEntitlement($entitlementId)->status);

        $controller = new AdminOrderController(
            $this->app,
            $this->orderService,
            $this->fulfillmentService,
            $this->entitlementRepo,
            $this->refundService
        );

        // Bulk cancel the paid order
        $reqCancel = new Request([], [
            '_token'      => 'valid_csrf_token_test',
            'action'      => 'bulk_action',
            'bulk_action' => 'cancelled',
            'ids'         => [$orderId],
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->handle($reqCancel);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('Cancellation completed successfully for 1 item(s).', $_SESSION['flash_success'] ?? null);

        // Verify order is cancelled and payment marked refunded
        $refreshedOrder = $this->orderRepo->findOrder($orderId);
        $this->assertSame(OrderLifecycleState::STATUS_CANCELLED, $refreshedOrder->status);
        $this->assertSame(OrderLifecycleState::PAYMENT_REFUNDED, $refreshedOrder->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_CANCELLED, $refreshedOrder->fulfillment_status);

        // Verify entitlement is revoked
        $this->assertSame('revoked', $this->entitlementRepo->findEntitlement($entitlementId)->status);

        // Verify wallet was authoritatively credited 45.00
        $newBalance = (float)$this->walletService->getBalance($customerId);
        $this->assertEqualsWithDelta($initialBalance + 45.0, $newBalance, 0.001);

        // Verify refund record created
        $refund = $this->refundRepo->findRefundByOrderId($orderId);
        $this->assertNotNull($refund);
        $this->assertSame('completed', $refund->status);
        $this->assertSame('45.00', number_format((float)$refund->refund_amount, 2, '.', ''));
    }
}

