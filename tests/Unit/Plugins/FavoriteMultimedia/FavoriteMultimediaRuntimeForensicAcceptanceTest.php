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
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\MultimediaComment;
use FavoriteCMS\Multimedia\Models\MultimediaReview;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance test verifying runtime forensic fixes:
 * A. Duplicate homepage header fix (canonical header ownership)
 * B. Profile dropdown data attributes and non-home functionality
 * C. Comment submission form attributes, hidden inputs, CSRF, and persistence
 * D. Audience Review form attributes, star picker, hidden inputs, and persistence
 */
class FavoriteMultimediaRuntimeForensicAcceptanceTest extends TestCase
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

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_runtime_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->app = new Application(APP_ROOT);
        Application::setInstance($this->app);
        Container::setInstance($this->app);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $config = new Config([]);
        $this->app->instance(Config::class, $config);
        $this->app->instance('config', $config);
        $this->app->instance(Database::class, $this->db);
        $this->app->instance('db', $this->db);

        $this->createTables($pdo);

        $this->playbackCtrl = new MediaPlaybackController($this->app);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['_token'] = 'forensic_csrf_token_test_123';
        $_SESSION['auth_user_id'] = 1;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['fm_canonical_header_rendered']);
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function createTables(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username VARCHAR(50), name VARCHAR(100), email VARCHAR(100), password VARCHAR(255), status VARCHAR(20), role VARCHAR(50) DEFAULT 'subscriber', email_verified_at DATETIME, created_at DATETIME, updated_at DATETIME);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, created_at DATETIME, updated_at DATETIME);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");

        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, created_at, updated_at) 
            VALUES (1, 'streamadmin', 'Stream Admin', 'admin@example.com', 'secret', 'admin', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO roles (id, name, slug) VALUES (1, 'Administrator', 'admin')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO multimedia_movies (id, title, slug, status, featured, created_at, updated_at) 
            VALUES (1, 'Inception', 'inception', 'published', 1, '{$now}', '{$now}')");
    }

    public function testHomepageHeaderDeduplication(): void
    {
        unset($GLOBALS['fm_canonical_header_rendered']);

        ob_start();
        try {
            $siteTitle = 'Streaming Platform';
            require APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/header.php';
            $themeHeader = (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $this->assertTrue(!empty($GLOBALS['fm_canonical_header_rendered']), 'Theme header must flag canonical header as rendered.');

        ob_start();
        try {
            $metaTitle = 'Streaming Platform';
            require APP_ROOT . '/plugins/favorite-multimedia/views/frontend/hub.php';
            $hubOutput = (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // Hub should omit outer HTML shell and header when canonical header is rendered
        $this->assertStringNotContainsString('<!DOCTYPE html>', $hubOutput);
        $this->assertStringNotContainsString('<header class="fm-header', $hubOutput);
        $this->assertStringContainsString('fm-main-content', $hubOutput);

        $fullRender = $themeHeader . $hubOutput;
        $count = substr_count($fullRender, '<header class="fm-header');
        $this->assertSame(1, $count, 'Full homepage render must have exactly ONE <header class="fm-header">');
    }

    public function testStandaloneHubRendersFullShellWhenCanonicalHeaderNotSet(): void
    {
        unset($GLOBALS['fm_canonical_header_rendered']);

        ob_start();
        try {
            $metaTitle = 'Streaming Hub';
            require APP_ROOT . '/plugins/favorite-multimedia/views/frontend/hub.php';
            $hubOutput = (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $this->assertStringContainsString('<!DOCTYPE html>', $hubOutput);
        $this->assertStringContainsString('<header class="fm-header', $hubOutput);
        $this->assertSame(1, substr_count($hubOutput, '<header class="fm-header'));
    }

    public function testProfileDropdownDataAttributes(): void
    {
        unset($GLOBALS['fm_canonical_header_rendered']);
        ob_start();
        require APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/header.php';
        $html = ob_get_clean();

        $this->assertStringContainsString('data-fm-profile-toggle="true"', $html);
        $this->assertStringContainsString('data-fm-profile-menu="true"', $html);
        $this->assertStringContainsString('id="fm-profile-btn"', $html);
        $this->assertStringContainsString('id="fm-profile-dropdown"', $html);
    }

    public function testDetailViewsContainMetaCsrfToken(): void
    {
        $detailViews = [
            APP_ROOT . '/plugins/favorite-multimedia/views/frontend/movie-detail.php',
            APP_ROOT . '/plugins/favorite-multimedia/views/frontend/series-detail.php',
            APP_ROOT . '/plugins/favorite-multimedia/views/frontend/episode-detail.php',
            APP_ROOT . '/plugins/favorite-multimedia/views/frontend/song-detail.php',
            APP_ROOT . '/plugins/favorite-multimedia/views/frontend/playlist-detail.php',
        ];

        foreach ($detailViews as $viewFile) {
            $this->assertFileExists($viewFile);
            $content = file_get_contents($viewFile);
            $this->assertStringContainsString('<meta name="csrf-token"', $content, basename($viewFile) . ' must contain <meta name="csrf-token">');
        }
    }

    public function testEngagementSectionReviewAndCommentFormContracts(): void
    {
        $contentType = 'movie';
        $contentId = 1;
        $user = User::find(1);
        $userRating = 4;
        $ratingAggregate = ['average' => 4.2, 'count' => 10];
        $reviewsData = ['reviews' => [], 'total' => 0];
        $commentsData = ['comments' => [], 'total' => 0];

        ob_start();
        require APP_ROOT . '/plugins/favorite-multimedia/views/frontend/_engagement_section.php';
        $html = ob_get_clean();

        // Review Form
        $this->assertStringContainsString('id="fmm-submit-review"', $html);
        $this->assertStringContainsString('action="/multimedia/api/review"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('data-endpoint="/multimedia/api/review"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="content_type"', $html);
        $this->assertStringContainsString('name="content_id"', $html);
        $this->assertStringContainsString('name="rating"', $html);
        $this->assertStringContainsString('fmm-review-star-picker', $html);

        // Comment Form
        $this->assertStringContainsString('id="fmm-post-comment-form"', $html);
        $this->assertStringContainsString('action="/multimedia/api/comment"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('data-endpoint="/multimedia/api/comment"', $html);
    }

    public function testCommentApiPersistence(): void
    {
        $payload = [
            '_token'       => 'forensic_csrf_token_test_123',
            'content_type' => 'movie',
            'content_id'   => 1,
            'body'         => 'This is a test discussion comment for Inception.',
        ];

        $request = new Request([], $payload, [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $response = $this->playbackCtrl->apiSaveComment($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['comment']);
        $this->assertSame('This is a test discussion comment for Inception.', $data['comment']['body']);

        // Verify DB record
        $comment = MultimediaComment::find((int)$data['comment']['id']);
        $this->assertNotNull($comment);
        $this->assertSame(1, (int)$comment->user_id);
        $this->assertSame('movie', $comment->content_type);
        $this->assertSame(1, (int)$comment->content_id);
    }

    public function testReviewApiPersistence(): void
    {
        $payload = [
            '_token'           => 'forensic_csrf_token_test_123',
            'content_type'     => 'movie',
            'content_id'       => 1,
            'title'            => 'Masterpiece',
            'body'             => 'Incredible mind-bending movie from start to finish.',
            'rating'           => 5,
            'contains_spoiler' => 0,
        ];

        $request = new Request([], $payload, [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $response = $this->playbackCtrl->apiSaveReview($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['review']);
        $this->assertSame('Masterpiece', $data['review']['title']);
        $this->assertSame(5, (int)$data['review']['rating']);

        // Verify DB record
        $review = MultimediaReview::find((int)$data['review']['id']);
        $this->assertNotNull($review);
        $this->assertSame(1, (int)$review->user_id);
        $this->assertSame('movie', $review->content_type);
        $this->assertSame(1, (int)$review->content_id);
        $this->assertSame(5, (int)$review->rating);
    }

    public function testGridRulesAndAspectPosterInCss(): void
    {
        $cssPath = APP_ROOT . '/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css';
        $this->assertFileExists($cssPath);
        $css = file_get_contents($cssPath);

        $this->assertStringContainsString('.fm-grid-poster', $css);
        $this->assertStringContainsString('.fm-grid-posters', $css);
        $this->assertStringContainsString('.fm-grid,', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));', $css);
        $this->assertStringContainsString('.fm-aspect-poster { aspect-ratio: 2 / 3;', $css);
    }

    public function testProfileDropdownMenuCssAndJsHardening(): void
    {
        $cssPath = APP_ROOT . '/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css';
        $css = file_get_contents($cssPath);

        $this->assertStringContainsString('.fm-profile-dropdown.fm-dropdown-open', $css);
        $this->assertStringContainsString('.fm-profile-dropdown[hidden]', $css);

        $jsPath = APP_ROOT . '/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js';
        $js = file_get_contents($jsPath);

        $this->assertStringContainsString('profileBtn.dataset.fmListenerAttached', $js);
        $this->assertStringContainsString("profileMenu.classList.add('fm-dropdown-open')", $js);
        $this->assertStringContainsString("profileMenu.classList.remove('fm-dropdown-open')", $js);
    }

    public function testFooterDoesNotContainDuplicateProfileListener(): void
    {
        $footerPath = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme/footer.php';
        $footer = file_get_contents($footerPath);

        $this->assertStringNotContainsString("var btn = document.getElementById('fm-profile-btn');", $footer);
        $this->assertStringNotContainsString("var menu = document.getElementById('fm-profile-dropdown');", $footer);
    }

    public function testEngagementJsUsesUrlEncodedPayloadToPreventKernel413(): void
    {
        $jsPath = APP_ROOT . '/plugins/favorite-multimedia/assets/js/multimedia-engagement.js';
        $js = file_get_contents($jsPath);

        $this->assertStringContainsString('function sendEngagementRequest(url, data, token)', $js);
        $this->assertStringContainsString('application/x-www-form-urlencoded; charset=UTF-8', $js);
        $this->assertStringContainsString("sendEngagementRequest('/multimedia/api/rate'", $js);
        $this->assertStringContainsString("sendEngagementRequest('/multimedia/api/comment'", $js);
        $this->assertStringContainsString("sendEngagementRequest('/multimedia/api/review'", $js);
    }

    public function testFrontendViewsContainAssetVersionQueries(): void
    {
        $moviesList = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/movies-list.php');
        $this->assertStringContainsString('multimedia-frontend.css?v=1.0.7', $moviesList);
        $this->assertStringContainsString('multimedia-frontend.js?v=1.0.7', $moviesList);
        $this->assertStringContainsString('fm-grid-posters', $moviesList);

        $seriesList = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/series-list.php');
        $this->assertStringContainsString('multimedia-frontend.css?v=1.0.7', $seriesList);
        $this->assertStringContainsString('multimedia-frontend.js?v=1.0.7', $seriesList);
        $this->assertStringContainsString('fm-grid-posters', $seriesList);

        $movieDetail = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/movie-detail.php');
        $this->assertStringContainsString('multimedia-frontend.css?v=1.0.7', $movieDetail);
        $this->assertStringContainsString('multimedia-frontend.js?v=1.0.7', $movieDetail);
    }
}
