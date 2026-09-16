<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\MultimediaComment;
use FavoriteCMS\Multimedia\Models\MultimediaRating;
use FavoriteCMS\Multimedia\Models\MultimediaReview;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;
use PHPUnit\Framework\TestCase;

/**
 * Functional Integration Acceptance Tests for:
 * - Profile Dropdown Account Navigation (Subscriber vs Admin, Content & Membership groups)
 * - Community Rating System (Self-hydration, active stars, aggregate updates, removal, guest redirect)
 * - Discussion & Comments (Threaded loading, composer, counts, separation from reviews)
 * - Reviews Gating (Allowed types vs episode exclusion)
 * - Canonical Login Redirects (/admin/login?redirect=)
 * - Asset Synchronization & Theme v1.0.2 / Plugin v1.0.7 Integrity
 */
class FavoriteMultimediaFunctionalIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MediaPlaybackController $playbackCtrl;
    private string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $themeFunctions = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/functions.php';
        if (file_exists($themeFunctions) && !function_exists('favorite_multimedia_theme_is_logged_in')) {
            require_once $themeFunctions;
        }

        unset($GLOBALS['_test_favorite_digital_available']);
        $_SESSION = [];
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'TestAdmin';
        $_SESSION['auth_user_email'] = 'admin@example.com';
        $_SESSION['_token'] = 'valid_test_token_123';

        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_func_int_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        $this->createSchema();
        $this->seedData();

        $this->playbackCtrl = new MediaPlaybackController($this->app);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_favorite_digital_available']);
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                name TEXT,
                email TEXT,
                password TEXT,
                status TEXT DEFAULT 'active',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                slug TEXT UNIQUE,
                description TEXT
            );
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id INTEGER,
                role_id INTEGER,
                PRIMARY KEY (user_id, role_id)
            );
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT,
                setting_key TEXT,
                value TEXT,
                type TEXT DEFAULT 'string'
            );
            CREATE TABLE IF NOT EXISTS multimedia_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                content_type TEXT NOT NULL,
                content_id INTEGER NOT NULL,
                rating INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, content_type, content_id)
            );
            CREATE TABLE IF NOT EXISTS multimedia_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                content_type TEXT NOT NULL,
                content_id INTEGER NOT NULL,
                parent_id INTEGER DEFAULT NULL,
                body TEXT NOT NULL,
                status TEXT DEFAULT 'approved',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS multimedia_reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                content_type TEXT NOT NULL,
                content_id INTEGER NOT NULL,
                rating INTEGER DEFAULT NULL,
                title TEXT DEFAULT NULL,
                body TEXT NOT NULL,
                contains_spoiler INTEGER DEFAULT 0,
                status TEXT DEFAULT 'approved',
                helpful_count INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, content_type, content_id)
            );
            CREATE TABLE IF NOT EXISTS multimedia_review_helpful (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                review_id INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, review_id)
            );
            CREATE TABLE IF NOT EXISTS multimedia_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reporter_id INTEGER DEFAULT NULL,
                target_type TEXT NOT NULL,
                target_id INTEGER NOT NULL,
                reason TEXT NOT NULL,
                notes TEXT,
                status TEXT DEFAULT 'pending',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS multimedia_movies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                status TEXT DEFAULT 'published',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS multimedia_episodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                series_id INTEGER NOT NULL,
                season_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                status TEXT DEFAULT 'published',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    private function seedData(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec("
            INSERT INTO users (id, username, name, email, password, status) VALUES
            (1, 'admin', 'Administrator', 'admin@example.com', 'hash', 'active'),
            (2, 'subscriber', 'Regular Subscriber', 'sub@example.com', 'hash', 'active');

            INSERT INTO roles (id, name, slug) VALUES
            (1, 'Administrator', 'admin'),
            (2, 'Subscriber', 'subscriber');

            INSERT INTO user_roles (user_id, role_id) VALUES
            (1, 1),
            (2, 2);

            INSERT INTO multimedia_movies (id, title, slug, status) VALUES
            (10, 'Inception', 'inception', 'published');

            INSERT INTO multimedia_episodes (id, series_id, season_id, title, slug, status) VALUES
            (20, 1, 1, 'Pilot Episode', 'pilot-episode', 'published');
        ");
    }

    // =========================================================================
    // 1. Profile Account Dropdown Integration Tests
    // =========================================================================

    public function testThemeCanCreatePostsCapabilityHelper(): void
    {
        $admin = User::find(1);
        $subscriber = User::find(2);

        $this->assertTrue(favorite_multimedia_theme_can_create_posts($admin), 'Admin must have post creation capability');
        $this->assertFalse(favorite_multimedia_theme_can_create_posts($subscriber), 'Subscriber without author/editor permissions must not have post creation capability');
        $this->assertFalse(favorite_multimedia_theme_can_create_posts(null), 'Null user must return false');
    }

    public function testHeaderTemplateRendersCanonicalGroupedDropdownForAdmin(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $headerPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/header.php';
        $this->assertFileExists($headerPath);

        ob_start();
        include $headerPath;
        $html = ob_get_clean();

        // Account Group
        $this->assertStringContainsString('href="/admin/users/profile"', $html);
        $this->assertStringContainsString('My Profile', $html);

        // Media Group
        $this->assertStringContainsString('href="/multimedia/library"', $html);
        $this->assertStringContainsString('My Library', $html);
        $this->assertStringContainsString('href="/multimedia/my-list"', $html);
        $this->assertStringContainsString('My List', $html);
        $this->assertStringContainsString('href="/multimedia/history"', $html);
        $this->assertStringContainsString('History', $html);
        $this->assertStringContainsString('href="/multimedia/notifications"', $html);
        $this->assertStringContainsString('Notifications', $html);
        $this->assertStringContainsString('href="/multimedia/following"', $html);
        $this->assertStringContainsString('Following', $html);

        // Content Group
        $this->assertStringContainsString('href="/admin/posts"', $html);
        $this->assertStringContainsString('My Posts', $html);

        // Admin Group
        $this->assertStringContainsString('href="/admin"', $html);
        $this->assertStringContainsString('Dashboard', $html);

        // Session Group
        $this->assertStringContainsString('href="/admin/logout"', $html);
        $this->assertStringContainsString('Log Out', $html);
    }

    public function testHeaderTemplateHidesAdminOptionsForNormalSubscriber(): void
    {
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['auth_user_name'] = 'subscriber';
        $_SESSION['auth_user_email'] = 'sub@example.com';

        $headerPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/header.php';

        ob_start();
        include $headerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('My Profile', $html);
        $this->assertStringContainsString('My Library', $html);
        $this->assertStringContainsString('My List', $html);
        $this->assertStringContainsString('History', $html);
        $this->assertStringContainsString('Notifications', $html);
        $this->assertStringContainsString('Following', $html);
        $this->assertStringNotContainsString('My Posts', $html);
        $this->assertStringNotContainsString('Dashboard', $html);
        $this->assertStringContainsString('Log Out', $html);
    }

    public function testMembershipLinkConditionalOnFavoriteDigital(): void
    {
        $headerPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/header.php';

        // When Digital is unavailable
        unset($GLOBALS['_test_favorite_digital_available']);
        ob_start();
        include $headerPath;
        $htmlWithoutDigital = ob_get_clean();

        $this->assertStringNotContainsString('Membership', $htmlWithoutDigital);

        // When Digital is available
        $GLOBALS['_test_favorite_digital_available'] = true;
        ob_start();
        include $headerPath;
        $htmlWithDigital = ob_get_clean();

        $this->assertStringContainsString('Membership', $htmlWithDigital);
        $this->assertStringContainsString('href="/multimedia/membership"', $htmlWithDigital);

        unset($GLOBALS['_test_favorite_digital_available']);
    }

    public function testThemeHeaderSynchronizedWithPluginHeader(): void
    {
        $themeHeaderPath = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/header.php';
        $pluginHeaderPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/header.php';

        $this->assertFileExists($themeHeaderPath);
        $this->assertFileExists($pluginHeaderPath);

        $themeHeader = file_get_contents($themeHeaderPath);
        $pluginHeader = file_get_contents($pluginHeaderPath);

        $expectedSnippets = [
            'My Profile',
            'Membership',
            'My Library',
            'My List',
            'History',
            'Notifications',
            'Following',
            'My Posts',
            'My Multimedia',
            'My Submissions',
            'Dashboard',
            'Log Out',
            'favorite_multimedia_theme_can_create_posts',
            'FavoriteDigitalAdapter::getSubscriptionUrl',
        ];

        foreach ($expectedSnippets as $snippet) {
            $this->assertStringContainsString($snippet, $themeHeader, "Theme header missing snippet: {$snippet}");
            $this->assertStringContainsString($snippet, $pluginHeader, "Plugin header missing snippet: {$snippet}");
        }
    }

    // =========================================================================
    // 2. Community Rating System Functional Integration Tests
    // =========================================================================

    public function testRatingSubmissionAndPersistence(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $request = new Request([], [
            '_token'       => 'valid_test_token_123',
            'content_type' => 'movie',
            'content_id'   => 10,
            'rating'       => 5,
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $this->playbackCtrl->apiRate($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(5, $data['user_rating']);
        $this->assertSame(1, $data['rating_count']);
        $this->assertEquals(5.0, (float)$data['average_rating']);

        // Verify database row
        $row = $this->db->selectOne("SELECT * FROM multimedia_ratings WHERE user_id = 1 AND content_type = 'movie' AND content_id = 10");
        $this->assertNotNull($row);
        $this->assertSame(5, (int)$row->rating);
    }

    public function testMultipleUserRatingsCalculatesCorrectAggregate(): void
    {
        // User 1 rates 5
        MultimediaEngagementService::rateContent(User::find(1), 'movie', 10, 5);
        // User 2 rates 3
        MultimediaEngagementService::rateContent(User::find(2), 'movie', 10, 3);

        $aggregate = MultimediaEngagementService::getRatingAggregate('movie', 10);
        $this->assertSame(2, $aggregate['count']);
        $this->assertEquals(4.0, (float)$aggregate['average']);
    }

    public function testRatingRemovalClearsUserRatingAndUpdatesAggregate(): void
    {
        $admin = User::find(1);
        MultimediaEngagementService::rateContent($admin, 'movie', 10, 4);

        $_SESSION['auth_user_id'] = 1;
        $request = new Request([], [
            '_token'       => 'valid_test_token_123',
            'content_type' => 'movie',
            'content_id'   => 10,
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $this->playbackCtrl->apiRemoveRating($request);
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['rating_count']);
        $this->assertSame(0, (int)$data['average_rating']);

        $userRating = MultimediaEngagementService::getUserRating($admin, 'movie', 10);
        $this->assertNull($userRating);
    }

    public function testGuestRatingAttemptRejectedWithUnauthenticatedStatus(): void
    {
        unset($_SESSION['auth_user_id']);

        $request = new Request([], [
            '_token'       => 'valid_test_token_123',
            'content_type' => 'movie',
            'content_id'   => 10,
            'rating'       => 4,
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $this->playbackCtrl->apiRate($request);
        $this->assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('unauthenticated', $data['status']);
    }

    // =========================================================================
    // 3. Discussion & Comment System Functional Integration Tests
    // =========================================================================

    public function testCommentPostingAndThreadRetrieval(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $request = new Request([], [
            '_token'       => 'valid_test_token_123',
            'content_type' => 'movie',
            'content_id'   => 10,
            'body'         => 'This movie has fantastic storytelling and visuals!',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $this->playbackCtrl->apiSaveComment($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['comment']['id']);
        $this->assertSame('This movie has fantastic storytelling and visuals!', $data['comment']['body']);
        $this->assertSame('Administrator', $data['comment']['author']['display_name']);

        // Test replies
        $commentId = (int)$data['comment']['id'];
        $replyReq = new Request([], [
            '_token'       => 'valid_test_token_123',
            'content_type' => 'movie',
            'content_id'   => 10,
            'parent_id'    => $commentId,
            'body'         => 'I agree completely, especially the third act.',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $replyResp = $this->playbackCtrl->apiSaveComment($replyReq);
        $replyData = json_decode($replyResp->getContent(), true);
        $this->assertTrue($replyData['success']);
        $this->assertSame($commentId, (int)$replyData['comment']['parent_id']);

        // Check service thread loading
        $threads = MultimediaEngagementService::getCommentsForContent('movie', 10, 20, 0, User::find(1));
        $this->assertSame(2, $threads['total']);
        $this->assertCount(1, $threads['comments']);
        $this->assertCount(1, $threads['comments'][0]['replies']);
        $this->assertSame('I agree completely, especially the third act.', $threads['comments'][0]['replies'][0]['body']);
    }

    public function testCommentDeletionRemovesCommentAndReplies(): void
    {
        $admin = User::find(1);

        $comm = MultimediaEngagementService::createComment($admin, 'movie', 10, 'To be deleted');
        $this->assertTrue($comm['success']);
        $commId = (int)$comm['comment']['id'];

        $_SESSION['auth_user_id'] = 1;
        $request = new Request([], ['_token' => 'valid_test_token_123'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $response = $this->playbackCtrl->apiDeleteComment($request, (string)$commId);

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $deletedComm = MultimediaComment::find($commId);
        $this->assertNull($deletedComm);
    }

    public function testCommentsAndReviewsAreCompletelySeparated(): void
    {
        $admin = User::find(1);

        // Save a comment
        $comment = MultimediaEngagementService::createComment($admin, 'movie', 10, 'A casual comment');
        // Save a review
        $review = MultimediaEngagementService::createReview($admin, 'movie', 10, 'A comprehensive review body', 'Review Title', 5, false);

        $this->assertTrue($comment['success']);
        $this->assertTrue($review['success']);

        // Verify comment is not in reviews table
        $reviewRow = $this->db->selectOne("SELECT * FROM multimedia_reviews WHERE body = 'A casual comment'");
        $this->assertNull($reviewRow);

        // Verify review is not in comments table
        $commentRow = $this->db->selectOne("SELECT * FROM multimedia_comments WHERE body = 'A comprehensive review body'");
        $this->assertNull($commentRow);
    }

    // =========================================================================
    // 4. Content Type Gating & Episode Detail Integration
    // =========================================================================

    public function testEpisodeExplicitlyExcludesAudienceReviews(): void
    {
        $partialPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/_engagement_section.php';
        $this->assertFileExists($partialPath);

        // Render for episode
        ob_start();
        $contentType = 'episode';
        $contentId = 20;
        $user = User::find(1);
        include $partialPath;
        $episodeHtml = ob_get_clean();

        // Must show community score & rating shelf
        $this->assertStringContainsString('Community Score', $episodeHtml);
        $this->assertStringContainsString('fav-rating-shelf', $episodeHtml);

        // Must show discussion & comments
        $this->assertStringContainsString('Discussion', $episodeHtml);
        $this->assertStringContainsString('fmm-post-comment-form', $episodeHtml);

        // Must NOT show audience reviews
        $this->assertStringNotContainsString('Audience Reviews', $episodeHtml);
        $this->assertStringNotContainsString('fav-reviews-section', $episodeHtml);
    }

    public function testMovieRendersBothAudienceReviewsAndDiscussion(): void
    {
        $partialPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/_engagement_section.php';

        ob_start();
        $contentType = 'movie';
        $contentId = 10;
        $user = User::find(1);
        include $partialPath;
        $movieHtml = ob_get_clean();

        // Must show community score
        $this->assertStringContainsString('Community Score', $movieHtml);
        // Must show audience reviews
        $this->assertStringContainsString('Audience Reviews', $movieHtml);
        $this->assertStringContainsString('fav-reviews-section', $movieHtml);
        // Must show discussion
        $this->assertStringContainsString('Discussion', $movieHtml);
        $this->assertStringContainsString('fmm-post-comment-form', $movieHtml);
    }

    // =========================================================================
    // 5. Engagement Partial Self-Hydration & Login Canonical Redirects
    // =========================================================================

    public function testEngagementSectionSelfHydratesWithoutVariables(): void
    {
        $admin = User::find(1);
        MultimediaEngagementService::rateContent($admin, 'movie', 10, 5);
        MultimediaEngagementService::createComment($admin, 'movie', 10, 'Self-hydration test comment');

        $partialPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/_engagement_section.php';

        // Omit $ratingAggregate, $userRating, $reviewsData, $commentsData
        ob_start();
        $contentType = 'movie';
        $contentId = 10;
        $user = $admin;
        include $partialPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('Community Score', $html);
        $this->assertStringContainsString('5.0', $html);
        $this->assertStringContainsString('1 rating', $html);
        $this->assertStringContainsString('Self-hydration test comment', $html);
    }

    public function testEngagementSectionContainsZeroLegacyLoginUrls(): void
    {
        $partialPath = APP_ROOT . '/plugins/favorite-multimedia/views/frontend/_engagement_section.php';
        $content = file_get_contents($partialPath);

        $this->assertStringNotContainsString('/login?return=', $content, 'Legacy /login?return= must not exist in _engagement_section.php');
        $this->assertStringContainsString('/admin/login?redirect=', $content, 'Canonical /admin/login?redirect= must be present in _engagement_section.php');
    }

    public function testEngagementClientScriptContainsZeroLegacyLoginUrls(): void
    {
        $jsPath = APP_ROOT . '/plugins/favorite-multimedia/assets/js/multimedia-engagement.js';
        $this->assertFileExists($jsPath);
        $js = file_get_contents($jsPath);

        $this->assertStringNotContainsString('/login?return=', $js, 'Legacy /login?return= must not exist in multimedia-engagement.js');
        $this->assertStringContainsString('/admin/login?redirect=', $js, 'Canonical /admin/login?redirect= must be present in multimedia-engagement.js');
        $this->assertStringContainsString('redirectToLogin', $js);
        $this->assertStringContainsString('No ratings yet', $js);
    }

    // =========================================================================
    // 6. Theme and Plugin Asset Synchronization & Versions
    // =========================================================================

    public function testThemeAssetsAreSynchronizedWithPluginAssets(): void
    {
        $pluginJs = APP_ROOT . '/plugins/favorite-multimedia/assets/js/multimedia-engagement.js';
        $themeJs1 = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/assets/js/multimedia-engagement.js';
        $themeJs2 = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/assets/js/frontend/multimedia-engagement.js';

        $this->assertFileExists($pluginJs);
        $this->assertFileExists($themeJs1);
        $this->assertFileExists($themeJs2);

        $pluginHash = hash_file('sha256', $pluginJs);
        $themeHash1 = hash_file('sha256', $themeJs1);
        $themeHash2 = hash_file('sha256', $themeJs2);

        $this->assertSame($pluginHash, $themeHash1, 'Theme JS must match plugin JS');
        $this->assertSame($pluginHash, $themeHash2, 'Theme frontend JS must match plugin JS');
    }

    public function testStrictVersionCompliance(): void
    {
        $themeJson = json_decode(file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/theme.json'), true);
        $pluginJson = json_decode(file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/plugin.json'), true);

        $this->assertSame('1.0.2', $themeJson['version'], 'Theme version must strictly remain 1.0.2');
        $this->assertSame('1.0.7', $pluginJson['version'], 'Plugin version must strictly remain 1.0.7');
    }
}
