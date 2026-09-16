<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use CreateMultimediaEngagementTables;
use CreateMultimediaUserLibraryTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\MultimediaComment;
use FavoriteCMS\Multimedia\Models\MultimediaRating;
use FavoriteCMS\Multimedia\Models\MultimediaReport;
use FavoriteCMS\Multimedia\Models\MultimediaReview;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\MultimediaDiscoveryService;
use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase7Test extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;

    protected function setUp(): void
    {
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->app = new Application();
        Container::getInstance()->instance(Application::class, $this->app);

        // In-memory SQLite database
        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->db = new class($this->pdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };

        Container::getInstance()->instance(Database::class, $this->db);

        // Core tables
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(100),
                name VARCHAR(100),
                email VARCHAR(255),
                password VARCHAR(255),
                role VARCHAR(50) DEFAULT 'user',
                avatar VARCHAR(255) NULL,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                slug TEXT
            );
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id INTEGER,
                role_id INTEGER
            );
            CREATE TABLE IF NOT EXISTS permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                slug TEXT
            );
            CREATE TABLE IF NOT EXISTS role_permissions (
                role_id INTEGER,
                permission_id INTEGER
            );
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT,
                setting_key TEXT,
                value TEXT,
                type TEXT,
                is_public INTEGER DEFAULT 0,
                created_at TEXT,
                updated_at TEXT
            );
        ");
        Setting::clearCache();

        // Run migrations 001, 002, and 003
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $m1 = new CreateFavoriteMultimediaTables($this->db);
        $m1->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/002_create_multimedia_user_library_tables.php';
        $m2 = new CreateMultimediaUserLibraryTables($this->db);
        $m2->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/003_create_multimedia_engagement_tables.php';
        $m3 = new CreateMultimediaEngagementTables($this->db);
        $m3->up();

        $_SESSION = [];
    }

    private function authenticateUser(int $id = 1, string $role = 'user', string $username = 'john_doe', ?string $name = null): User
    {
        $nameVal = $name ? "'{$name}'" : "NULL";
        $this->pdo->exec("INSERT OR REPLACE INTO users (id, username, name, email, password, role) VALUES ({$id}, '{$username}', {$nameVal}, '{$username}@example.com', 'secret_hash_123', '{$role}')");
        $_SESSION['auth_user_id'] = $id;

        if ($role === 'admin' || $role === 'administrator') {
            $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, slug) VALUES (1, 'Admin', 'admin')");
            $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$id}");
            $this->pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES ({$id}, 1)");
        }

        $u = User::find($id);
        $this->assertNotNull($u);
        return $u;
    }

    private function createMovie(string $title, string $slug, string $access = 'public', string $status = 'published', int $views = 0): Movie
    {
        $id = $this->db->insert('multimedia_movies', [
            'title'       => $title,
            'slug'        => $slug,
            'access_mode' => $access,
            'status'      => $status,
            'views_count' => $views,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return Movie::find((int)$id);
    }

    // -------------------------------------------------------------------------
    // Test 1: Migration creates engagement tables & unique indexes
    // -------------------------------------------------------------------------
    public function testMigrationCreatesEngagementTablesAndIndexes(): void
    {
        $this->assertTrue(MultimediaEngagementService::hasRatingsTable());
        $this->assertTrue(MultimediaEngagementService::hasReviewsTable());
        $this->assertTrue(MultimediaEngagementService::hasCommentsTable());
        $this->assertTrue(MultimediaEngagementService::hasReportsTable());

        // Verify index enforcement on ratings
        $this->db->insert('multimedia_ratings', [
            'user_id'      => 10,
            'content_type' => 'movie',
            'content_id'   => 100,
            'rating'       => 4,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $duplicateFailed = false;
        try {
            $this->db->insert('multimedia_ratings', [
                'user_id'      => 10,
                'content_type' => 'movie',
                'content_id'   => 100,
                'rating'       => 5,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            $duplicateFailed = true;
        }

        $this->assertTrue($duplicateFailed, 'Duplicate (user_id, content_type, content_id) raw insert must be rejected by unique index');
    }

    // -------------------------------------------------------------------------
    // Test 2: Rating uniqueness (1 per user/content) via Service
    // -------------------------------------------------------------------------
    public function testRatingUniquenessPerUserAndContent(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createMovie('Inception', 'inception');

        $res = MultimediaEngagementService::rateContent($user, 'movie', (int)$movie->id, 4);
        $this->assertTrue($res['success']);
        $this->assertSame(4, $res['user_rating']);
        $this->assertSame(1, $res['rating_count']);
        $this->assertSame(4.0, $res['average_rating']);

        $userRating = MultimediaEngagementService::getUserRating($user, 'movie', (int)$movie->id);
        $this->assertSame(4, $userRating);
    }

    // -------------------------------------------------------------------------
    // Test 3: Rating update does not increment count
    // -------------------------------------------------------------------------
    public function testRatingUpdateDoesNotIncrementCount(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createMovie('Interstellar', 'interstellar');

        // Initial rate: 3 stars
        MultimediaEngagementService::rateContent($user, 'movie', (int)$movie->id, 3);
        $agg1 = MultimediaEngagementService::getRatingAggregate('movie', (int)$movie->id);
        $this->assertSame(1, $agg1['count']);
        $this->assertSame(3.0, $agg1['average']);

        // Update rate: 5 stars
        $res = MultimediaEngagementService::rateContent($user, 'movie', (int)$movie->id, 5);
        $this->assertTrue($res['success']);
        $this->assertSame(5, $res['user_rating']);
        $this->assertSame(1, $res['rating_count']);
        $this->assertSame(5.0, $res['average_rating']);

        $agg2 = MultimediaEngagementService::getRatingAggregate('movie', (int)$movie->id);
        $this->assertSame(1, $agg2['count']);
        $this->assertSame(5.0, $agg2['average']);
    }

    // -------------------------------------------------------------------------
    // Test 4: Rating aggregation mathematical accuracy
    // -------------------------------------------------------------------------
    public function testRatingAggregationMathematicalAccuracy(): void
    {
        $u1 = $this->authenticateUser(1, 'user', 'alice');
        $u2 = $this->authenticateUser(2, 'user', 'bob');
        $u3 = $this->authenticateUser(3, 'user', 'charlie');
        $movie = $this->createMovie('The Prestige', 'the-prestige');

        MultimediaEngagementService::rateContent($u1, 'movie', (int)$movie->id, 4);
        MultimediaEngagementService::rateContent($u2, 'movie', (int)$movie->id, 5);
        MultimediaEngagementService::rateContent($u3, 'movie', (int)$movie->id, 2);

        $agg = MultimediaEngagementService::getRatingAggregate('movie', (int)$movie->id);
        // (4 + 5 + 2) / 3 = 11 / 3 = 3.666... -> 3.7
        $this->assertSame(3, $agg['count']);
        $this->assertSame(3.7, $agg['average']);
    }

    // -------------------------------------------------------------------------
    // Test 5: Invalid rating bounds (<1 or >5 rejected)
    // -------------------------------------------------------------------------
    public function testInvalidRatingBoundsRejected(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createMovie('Tenet', 'tenet');

        $resLow = MultimediaEngagementService::rateContent($user, 'movie', (int)$movie->id, 0);
        $this->assertFalse($resLow['success']);
        $this->assertStringContainsString('between 1 and 5', $resLow['error']);

        $resHigh = MultimediaEngagementService::rateContent($user, 'movie', (int)$movie->id, 6);
        $this->assertFalse($resHigh['success']);
        $this->assertStringContainsString('between 1 and 5', $resHigh['error']);

        $resNegative = MultimediaEngagementService::rateContent($user, 'movie', (int)$movie->id, -1);
        $this->assertFalse($resNegative['success']);
    }

    // -------------------------------------------------------------------------
    // Test 6: Invalid content type rejected for rating and reviews
    // -------------------------------------------------------------------------
    public function testInvalidContentTypeRejectedForRatingAndReviews(): void
    {
        $user = $this->authenticateUser(1);

        $resRating = MultimediaEngagementService::rateContent($user, 'unsupported_type', 1, 5);
        $this->assertFalse($resRating['success']);
        $this->assertStringContainsString('Invalid content type', $resRating['error']);

        $resReview = MultimediaEngagementService::createReview($user, 'unsupported_type', 1, 'Awesome content!');
        $this->assertFalse($resReview['success']);
        $this->assertStringContainsString('Invalid content type', $resReview['error']);

        $resComment = MultimediaEngagementService::createComment($user, 'unsupported_type', 1, 'Nice discussion');
        $this->assertFalse($resComment['success']);
        $this->assertStringContainsString('Invalid content type', $resComment['error']);
    }

    // -------------------------------------------------------------------------
    // Test 7: Review submission & ownership & automatic rating sync
    // -------------------------------------------------------------------------
    public function testReviewSubmissionAndOwnership(): void
    {
        $user = $this->authenticateUser(1, 'user', 'sam_critic');
        $movie = $this->createMovie('Memento', 'memento');

        $res = MultimediaEngagementService::createReview(
            $user,
            'movie',
            (int)$movie->id,
            'A mind-bending psychological thriller that redefines storytelling.',
            'Masterpiece of non-linear cinema',
            5,
            false
        );

        $this->assertTrue($res['success']);
        $this->assertSame('approved', $res['status']);
        $this->assertNotNull($res['review']);
        $this->assertSame('Masterpiece of non-linear cinema', $res['review']['title']);
        $this->assertSame(5, $res['review']['rating']);
        $this->assertTrue($res['review']['is_owner']);

        // Check that rating table was also synced
        $rating = MultimediaEngagementService::getUserRating($user, 'movie', (int)$movie->id);
        $this->assertSame(5, $rating);
    }

    // -------------------------------------------------------------------------
    // Test 8: User A cannot edit User B review
    // -------------------------------------------------------------------------
    public function testUserCannotEditAnotherUsersReview(): void
    {
        $userA = $this->authenticateUser(1, 'user', 'user_a');
        $movie = $this->createMovie('Dunkirk', 'dunkirk');

        $res = MultimediaEngagementService::createReview(
            $userA,
            'movie',
            (int)$movie->id,
            'Intense survival film with stunning audio design.'
        );
        $reviewId = $res['review']['id'];

        $userB = $this->authenticateUser(2, 'user', 'user_b');
        $updateRes = MultimediaEngagementService::updateReview($userB, $reviewId, 'Hacked review content!');

        $this->assertFalse($updateRes['success']);
        $this->assertSame('forbidden', $updateRes['status']);

        // Verify review content remains unchanged
        $review = MultimediaReview::find($reviewId);
        $this->assertSame('Intense survival film with stunning audio design.', $review->body);
    }

    // -------------------------------------------------------------------------
    // Test 9: User A cannot delete User B review
    // -------------------------------------------------------------------------
    public function testUserCannotDeleteAnotherUsersReview(): void
    {
        $userA = $this->authenticateUser(1, 'user', 'user_a');
        $movie = $this->createMovie('Oppenheimer', 'oppenheimer');

        $res = MultimediaEngagementService::createReview(
            $userA,
            'movie',
            (int)$movie->id,
            'Phenomenal biopic of historical gravity.'
        );
        $reviewId = $res['review']['id'];

        $userB = $this->authenticateUser(2, 'user', 'user_b');
        $deleteRes = MultimediaEngagementService::deleteReview($userB, $reviewId);

        $this->assertFalse($deleteRes['success']);
        $this->assertSame('forbidden', $deleteRes['status']);
        $this->assertNotNull(MultimediaReview::find($reviewId));

        // Author can delete own review
        $deleteAuthor = MultimediaEngagementService::deleteReview($userA, $reviewId);
        $this->assertTrue($deleteAuthor['success']);
        $this->assertNull(MultimediaReview::find($reviewId));
    }

    // -------------------------------------------------------------------------
    // Test 10: Review moderation status workflow (pending -> approved -> rejected)
    // -------------------------------------------------------------------------
    public function testReviewModerationStatusWorkflow(): void
    {
        $user = $this->authenticateUser(1, 'user', 'moviefan');
        $admin = $this->authenticateUser(99, 'admin', 'moderator_admin');
        $movie = $this->createMovie('Barbie', 'barbie');

        // Configure setting to require approval
        $this->db->insert('settings', [
            'group_name'  => 'multimedia',
            'setting_key' => 'review_moderation_mode',
            'value'       => 'require_approval',
            'type'        => 'string',
            'is_public'   => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        Setting::clearCache();

        $res = MultimediaEngagementService::createReview(
            $user,
            'movie',
            (int)$movie->id,
            'Colorful and satirical commentary on society.'
        );

        $this->assertTrue($res['success']);
        $this->assertSame('pending', $res['status']);
        $reviewId = $res['review']['id'];

        // Public list should not show pending review to anonymous user
        $publicReviews = MultimediaEngagementService::getReviewsForContent('movie', (int)$movie->id);
        $this->assertCount(0, $publicReviews['reviews']);

        // Admin approves review
        $modRes = MultimediaEngagementService::moderateReview($admin, $reviewId, 'approve');
        $this->assertTrue($modRes['success']);
        $this->assertSame('approved', $modRes['new_status']);

        // Now review appears publicly
        $publicReviewsAfter = MultimediaEngagementService::getReviewsForContent('movie', (int)$movie->id);
        $this->assertCount(1, $publicReviewsAfter['reviews']);
        $this->assertSame('approved', $publicReviewsAfter['reviews'][0]['status']);

        // Admin rejects review
        $modReject = MultimediaEngagementService::moderateReview($admin, $reviewId, 'reject');
        $this->assertTrue($modReject['success']);
        $this->assertSame('rejected', $modReject['new_status']);

        // Review no longer in public list
        $publicReviewsFinal = MultimediaEngagementService::getReviewsForContent('movie', (int)$movie->id);
        $this->assertCount(0, $publicReviewsFinal['reviews']);
    }

    // -------------------------------------------------------------------------
    // Test 11: Comment submission & shallow 1-level threading
    // -------------------------------------------------------------------------
    public function testCommentSubmissionAndShallowThreading(): void
    {
        $u1 = $this->authenticateUser(1, 'user', 'alice');
        $movie = $this->createMovie('Avatar', 'avatar');

        // Top level comment
        $c1Res = MultimediaEngagementService::createComment($u1, 'movie', (int)$movie->id, 'What did you think of the visual effects?');
        $this->assertTrue($c1Res['success']);
        $c1Id = $c1Res['comment']['id'];
        $this->assertNull($c1Res['comment']['parent_id']);

        // Reply to top level comment
        $u2 = $this->authenticateUser(2, 'user', 'bob');
        $c2Res = MultimediaEngagementService::createComment($u2, 'movie', (int)$movie->id, 'They were groundbreaking!', $c1Id);
        $this->assertTrue($c2Res['success']);
        $c2Id = $c2Res['comment']['id'];
        $this->assertSame($c1Id, $c2Res['comment']['parent_id']);

        // Attempt to reply to reply (depth 2) -> must collapse/clamp to top-level parent ($c1Id)
        $u3 = $this->authenticateUser(3, 'user', 'charlie');
        $c3Res = MultimediaEngagementService::createComment($u3, 'movie', (int)$movie->id, 'Totally agree with bob!', $c2Id);
        $this->assertTrue($c3Res['success']);
        // Assert it attached to top-level $c1Id, preserving shallow 1-level depth
        $this->assertSame($c1Id, $c3Res['comment']['parent_id']);

        // Verify structure in getCommentsForContent
        $commentTree = MultimediaEngagementService::getCommentsForContent('movie', (int)$movie->id);
        $this->assertCount(1, $commentTree['comments']); // 1 top-level thread
        $this->assertCount(2, $commentTree['comments'][0]['replies']); // 2 replies under it
    }

    // -------------------------------------------------------------------------
    // Test 12: Comment ownership and editing
    // -------------------------------------------------------------------------
    public function testCommentOwnershipAndEditing(): void
    {
        $user = $this->authenticateUser(1, 'user', 'author');
        $movie = $this->createMovie('Gladiator', 'gladiator');

        $res = MultimediaEngagementService::createComment($user, 'movie', (int)$movie->id, 'Initial thoughts on the film.');
        $commentId = $res['comment']['id'];

        $editRes = MultimediaEngagementService::updateComment($user, $commentId, 'Updated comprehensive thoughts on the film.');
        $this->assertTrue($editRes['success']);
        $this->assertSame('Updated comprehensive thoughts on the film.', $editRes['comment']['body']);
    }

    // -------------------------------------------------------------------------
    // Test 13: User A cannot edit User B comment
    // -------------------------------------------------------------------------
    public function testUserCannotEditAnotherUsersComment(): void
    {
        $u1 = $this->authenticateUser(1, 'user', 'alice');
        $movie = $this->createMovie('Titanic', 'titanic');
        $cRes = MultimediaEngagementService::createComment($u1, 'movie', (int)$movie->id, 'A classic romance.');
        $commentId = $cRes['comment']['id'];

        $u2 = $this->authenticateUser(2, 'user', 'bob');
        $editRes = MultimediaEngagementService::updateComment($u2, $commentId, 'Malicious tampering');
        $this->assertFalse($editRes['success']);
        $this->assertSame('forbidden', $editRes['status']);

        // Non-moderator cannot delete
        $delRes = MultimediaEngagementService::deleteComment($u2, $commentId);
        $this->assertFalse($delRes['success']);
        $this->assertSame('forbidden', $delRes['status']);

        // Admin moderator can delete
        $admin = $this->authenticateUser(99, 'admin', 'mod');
        $adminDel = MultimediaEngagementService::deleteComment($admin, $commentId);
        $this->assertTrue($adminDel['success']);
        $this->assertNull(MultimediaComment::find($commentId));
    }

    // -------------------------------------------------------------------------
    // Test 14: Report creation & duplicate report prevention
    // -------------------------------------------------------------------------
    public function testReportCreationAndDuplicatePrevention(): void
    {
        $u1 = $this->authenticateUser(1, 'user', 'spammer');
        $movie = $this->createMovie('The Dark Knight', 'dark-knight');
        $reviewRes = MultimediaEngagementService::createReview($u1, 'movie', (int)$movie->id, 'Buy cheap watches at badsite.com!');
        $reviewId = $reviewRes['review']['id'];

        $reporter = $this->authenticateUser(2, 'user', 'vigilant');
        $rep1 = MultimediaEngagementService::createReport($reporter, 'review', $reviewId, 'spam', 'External spam links');
        $this->assertTrue($rep1['success']);
        $this->assertNotNull(MultimediaReport::find((int)$rep1['report_id']));

        // Second report by same user must be rejected
        $rep2 = MultimediaEngagementService::createReport($reporter, 'review', $reviewId, 'spam', 'Second attempt');
        $this->assertFalse($rep2['success']);
        $this->assertStringContainsString('already reported', $rep2['error']);

        // Invalid reason rejected
        $repInvalid = MultimediaEngagementService::createReport($reporter, 'review', $reviewId, 'dislike_content');
        $this->assertFalse($repInvalid['success']);
        $this->assertStringContainsString('Invalid report reason', $repInvalid['error']);
    }

    // -------------------------------------------------------------------------
    // Test 15: Admin moderation permission check
    // -------------------------------------------------------------------------
    public function testAdminModerationPermissionCheck(): void
    {
        $normalUser = $this->authenticateUser(1, 'user', 'regular_joe');
        $adminUser = $this->authenticateUser(99, 'admin', 'site_admin');

        $this->assertFalse(MultimediaEngagementService::canModerate($normalUser));
        $this->assertTrue(MultimediaEngagementService::canModerate($adminUser));

        $movie = $this->createMovie('Matrix', 'matrix');
        $rev = MultimediaEngagementService::createReview($normalUser, 'movie', (int)$movie->id, 'Good sci-fi movie');
        $revId = $rev['review']['id'];

        // Regular user cannot moderate
        $failMod = MultimediaEngagementService::moderateReview($normalUser, $revId, 'reject');
        $this->assertFalse($failMod['success']);
        $this->assertSame('forbidden', $failMod['status']);

        // Admin can moderate
        $passMod = MultimediaEngagementService::moderateReview($adminUser, $revId, 'reject');
        $this->assertTrue($passMod['success']);
    }

    // -------------------------------------------------------------------------
    // Test 16: Guest cannot rate, review, or comment
    // -------------------------------------------------------------------------
    public function testGuestCannotRateReviewOrComment(): void
    {
        $_SESSION['auth_user_id'] = null;
        $guest = null;
        $movie = $this->createMovie('Fight Club', 'fight-club');

        $rateRes = MultimediaEngagementService::rateContent($guest, 'movie', (int)$movie->id, 5);
        $this->assertFalse($rateRes['success']);
        $this->assertSame('unauthenticated', $rateRes['status']);

        $revRes = MultimediaEngagementService::createReview($guest, 'movie', (int)$movie->id, 'First rule is do not talk about it.');
        $this->assertFalse($revRes['success']);
        $this->assertSame('unauthenticated', $revRes['status']);

        $comRes = MultimediaEngagementService::createComment($guest, 'movie', (int)$movie->id, 'Great soundtrack');
        $this->assertFalse($comRes['success']);
        $this->assertSame('unauthenticated', $comRes['status']);
    }

    // -------------------------------------------------------------------------
    // Test 17: CSRF validation on state mutations
    // -------------------------------------------------------------------------
    public function testCsrfValidationOnStateMutations(): void
    {
        $user = $this->authenticateUser(1, 'user', 'csrf_tester');
        $movie = $this->createMovie('Pulp Fiction', 'pulp-fiction');
        $controller = new MediaPlaybackController($this->app);

        $_SESSION['_token'] = 'expected_secret_token_123';

        // 1. Request with missing token -> 403
        $badReq = new Request([], ['content_type' => 'movie', 'content_id' => $movie->id, 'rating' => 5], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $resp1 = $controller->apiRate($badReq);
        $this->assertSame(403, $resp1->getStatusCode());

        // 2. Request with valid token in body -> 200
        $goodReq = new Request([], [
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'rating'       => 5,
            '_token'       => 'expected_secret_token_123',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $resp2 = $controller->apiRate($goodReq);
        $this->assertSame(200, $resp2->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 18: XSS escaping in reviews and comments
    // -------------------------------------------------------------------------
    public function testXssEscapingInReviewsAndComments(): void
    {
        $user = $this->authenticateUser(1, 'user', 'xss_tester');
        $movie = $this->createMovie('Inception 2', 'inception-2');

        $xssPayload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $revRes = MultimediaEngagementService::createReview($user, 'movie', (int)$movie->id, $xssPayload, '<b>Payload Title</b>');
        $this->assertTrue($revRes['success']);

        $hydrated = $revRes['review'];
        $this->assertSame($xssPayload, $hydrated['body']);

        // When rendered through htmlspecialchars in views, payload is neutered:
        $escaped = htmlspecialchars($hydrated['body'], ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    // -------------------------------------------------------------------------
    // Test 19: SQL injection resistance in engagement queries
    // -------------------------------------------------------------------------
    public function testSqlInjectionResistanceInEngagementQueries(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createMovie('Hackers', 'hackers');

        $maliciousType = "movie' OR '1'='1";
        $res = MultimediaEngagementService::rateContent($user, $maliciousType, (int)$movie->id, 4);
        $this->assertFalse($res['success']);

        // Database remains uncorrupted
        $count = $this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_ratings");
        $this->assertSame(0, (int)$count->c);
    }

    // -------------------------------------------------------------------------
    // Test 20: Bangla and Unicode in reviews and comments
    // -------------------------------------------------------------------------
    public function testBanglaAndUnicodeInReviewsAndComments(): void
    {
        $user = $this->authenticateUser(1, 'user', 'bangla_user', 'রহিম শেখ');
        $movie = $this->createMovie('Hawa', 'hawa');

        $banglaReview = 'অসাধারণ একটি চলচ্চিত্র! হাওয়া সিনেমার আবহ সংগীত ও অভিনয় অতুলনীয় ছিল। 🔥🎬✨';
        $banglaTitle = 'মন ছুঁয়ে যাওয়া সিনেমা';

        $rev = MultimediaEngagementService::createReview($user, 'movie', (int)$movie->id, $banglaReview, $banglaTitle, 5);
        $this->assertTrue($rev['success']);
        $this->assertSame($banglaReview, $rev['review']['body']);
        $this->assertSame($banglaTitle, $rev['review']['title']);
        $this->assertSame('রহিম শেখ', $rev['review']['author']['display_name']);

        $banglaComment = 'সাদা সাদা কালা কালা গানটি সত্যিই প্রশংসনীয়!';
        $com = MultimediaEngagementService::createComment($user, 'movie', (int)$movie->id, $banglaComment);
        $this->assertTrue($com['success']);
        $this->assertSame($banglaComment, $com['comment']['body']);
    }

    // -------------------------------------------------------------------------
    // Test 21: User privacy protection (no email, phone, password leakage)
    // -------------------------------------------------------------------------
    public function testUserPrivacyProtectionNoSensitiveDataLeakage(): void
    {
        $user = $this->authenticateUser(42, 'user', 'secret_agent', 'Agent 007');
        $movie = $this->createMovie('Skyfall', 'skyfall');

        $rev = MultimediaEngagementService::createReview($user, 'movie', (int)$movie->id, 'Classic spy action at its finest.');
        $author = $rev['review']['author'];

        $this->assertSame('Agent 007', $author['display_name']);
        $this->assertArrayNotHasKey('email', $author);
        $this->assertArrayNotHasKey('password', $author);
        $this->assertArrayNotHasKey('phone', $author);
        $this->assertFalse($author['is_deleted']);

        // Deleted user display test
        $deletedAuthor = MultimediaEngagementService::formatAuthorDisplay(99999);
        $this->assertSame('Deleted User', $deletedAuthor['display_name']);
        $this->assertTrue($deletedAuthor['is_deleted']);
        $this->assertNull($deletedAuthor['avatar']);
    }

    // -------------------------------------------------------------------------
    // Test 22: Cascade deletion when content is deleted
    // -------------------------------------------------------------------------
    public function testCascadeDeletionWhenContentDeleted(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createMovie('Blade Runner', 'blade-runner');
        $movieId = (int)$movie->id;

        MultimediaEngagementService::rateContent($user, 'movie', $movieId, 5);
        MultimediaEngagementService::createReview($user, 'movie', $movieId, 'Neon noir masterpiece.');
        MultimediaEngagementService::createComment($user, 'movie', $movieId, 'Vangelis score is legendary.');

        $this->assertSame(1, (int)$this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_ratings WHERE content_type = 'movie' AND content_id = {$movieId}")->c);
        $this->assertSame(1, (int)$this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_reviews WHERE content_type = 'movie' AND content_id = {$movieId}")->c);
        $this->assertSame(1, (int)$this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_comments WHERE content_type = 'movie' AND content_id = {$movieId}")->c);

        // Run cascade deletion
        MultimediaEngagementService::deleteForContent('movie', $movieId);

        $this->assertSame(0, (int)$this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_ratings WHERE content_type = 'movie' AND content_id = {$movieId}")->c);
        $this->assertSame(0, (int)$this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_reviews WHERE content_type = 'movie' AND content_id = {$movieId}")->c);
        $this->assertSame(0, (int)$this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_comments WHERE content_type = 'movie' AND content_id = {$movieId}")->c);
    }

    // -------------------------------------------------------------------------
    // Test 23: Premium access remains authoritative regardless of engagement
    // -------------------------------------------------------------------------
    public function testPremiumAccessRemainsAuthoritativeRegardlessOfEngagement(): void
    {
        $freeUser = $this->authenticateUser(1, 'user', 'free_user');
        $premiumMovie = $this->createMovie('Exclusive Film', 'exclusive-film', 'premium');

        // Free user can rate or review (if allowed publicly)
        MultimediaEngagementService::rateContent($freeUser, 'movie', (int)$premiumMovie->id, 5);

        // However, stream entitlement remains strictly checked by MultimediaAccessService
        $access = MultimediaAccessService::checkAccess($freeUser, 'movie', (int)$premiumMovie->id);
        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, $access);
    }

    // -------------------------------------------------------------------------
    // Test 24: Discovery rating boost integration
    // -------------------------------------------------------------------------
    public function testDiscoveryRatingBoostIntegration(): void
    {
        $u1 = $this->authenticateUser(1);
        $u2 = $this->authenticateUser(2);

        $m1 = $this->createMovie('High Rated Movie', 'high-rated', 'public', 'published', 10);
        $m2 = $this->createMovie('Unrated Movie', 'unrated', 'public', 'published', 10);

        // Give m1 high ratings (5 stars from multiple users)
        MultimediaEngagementService::rateContent($u1, 'movie', (int)$m1->id, 5);
        MultimediaEngagementService::rateContent($u2, 'movie', (int)$m1->id, 5);

        $popular = MultimediaDiscoveryService::getPopular(10, 'movie');
        $this->assertNotEmpty($popular);

        // High rated movie should be ranked first due to rating quality boost (+1.5 per star over baseline)
        $this->assertSame((int)$m1->id, (int)$popular[0]['id']);
    }
}
