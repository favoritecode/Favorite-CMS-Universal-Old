<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use CreateMultimediaUserLibraryTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Favorite;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\PlaybackProgressService;
use FavoriteCMS\Multimedia\Services\UserLibraryService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase5Test extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;
    private string $tempLogFile;

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
                email VARCHAR(255),
                password VARCHAR(255),
                role VARCHAR(50) DEFAULT 'user',
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
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT,
                setting_key TEXT,
                value TEXT,
                type TEXT,
                created_at TEXT,
                updated_at TEXT
            );
        ");
        Setting::clearCache();

        // Multimedia plugin migration 001
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $migration1 = new CreateFavoriteMultimediaTables($this->db);
        $migration1->up();

        // Multimedia plugin migration 002 (Phase 5 Library tables)
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/002_create_multimedia_user_library_tables.php';
        $migration2 = new CreateMultimediaUserLibraryTables($this->db);
        $migration2->up();

        // Isolated log file
        $this->tempLogFile = sys_get_temp_dir() . '/multimedia_phase5_test_' . uniqid() . '.log';
        Logger::setLogFile($this->tempLogFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (file_exists($this->tempLogFile)) {
            @unlink($this->tempLogFile);
        }
    }

    /**
     * Helper to create and authenticate a user in session
     */
    private function authenticateUser(int $id = 1, string $role = 'user', string $username = 'john_doe'): User
    {
        $this->pdo->exec("DELETE FROM users WHERE id = {$id}");
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("INSERT INTO users (id, username, email, password, role, created_at, updated_at) VALUES (?, ?, ?, 'secret', ?, ?, ?)");
        $stmt->execute([$id, $username, "{$username}@example.com", $role, $now, $now]);

        $_SESSION['auth_user_id'] = $id;
        $_SESSION['user_id'] = $id;
        $_SESSION['user_role'] = $role;

        if ($role === 'admin') {
            $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, slug) VALUES (1, 'Admin', 'admin')");
            $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$id}");
            $this->pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES ({$id}, 1)");
        }

        $user = User::find($id);
        $this->assertNotNull($user);
        return $user;
    }

    /**
     * Helper to create a test movie
     */
    private function createTestMovie(string $title = 'Test Movie', string $access = 'public'): Movie
    {
        $slug = 'test-movie-' . uniqid();
        return Movie::create([
            'title' => $title,
            'slug' => $slug,
            'access_mode' => $access,
            'status' => 'published',
            'duration' => 7200,
            'release_year' => 2024,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 1. Test Migration Creates Tables and Unique Indexes
     */
    public function testMigrationCreatesTablesAndIndexes(): void
    {
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('multimedia_playback_progress', $tables);
        $this->assertContains('multimedia_favorites', $tables);

        // Verify index existence
        $indexes = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('idx_mm_prog_user_content', $indexes);
        $this->assertContains('idx_mm_fav_user_content', $indexes);

        // Test down() rollback safely drops tables
        $migration2 = new CreateMultimediaUserLibraryTables($this->db);
        $migration2->down();

        $tablesAfterDown = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains('multimedia_playback_progress', $tablesAfterDown);
        $this->assertNotContains('multimedia_favorites', $tablesAfterDown);

        // Re-run up() for subsequent tests
        $migration2->up();
    }

    /**
     * 2. Test Playback Progress Save and Retrieve
     */
    public function testPlaybackProgressSaveAndRetrieve(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createTestMovie('Inception');

        $record = PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 120.5, 1200.0, false);
        $this->assertNotNull($record);
        $this->assertEquals(1, $record->user_id);
        $this->assertEquals('movie', $record->content_type);
        $this->assertEquals($movie->id, $record->content_id);
        $this->assertEquals(120.5, $record->position);
        $this->assertEquals(1200.0, $record->duration);
        $this->assertEquals(10.04, $record->percentage);
        $this->assertFalse((bool)$record->is_completed);

        // Update progress
        $updated = PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 600.0, 1200.0, false);
        $this->assertEquals(600.0, $updated->position);
        $this->assertEquals(50.0, $updated->percentage);

        // Retrieve via PlaybackProgressService
        $serviceProgress = PlaybackProgressService::getProgress($user, 'movie', (int)$movie->id);
        $this->assertNotNull($serviceProgress);
        $this->assertEquals(600.0, $serviceProgress['position']);
        $this->assertEquals(50.0, $serviceProgress['percentage']);
        $this->assertTrue($serviceProgress['should_resume']);
        $this->assertEquals('10:00', $serviceProgress['formatted_position']);
    }

    /**
     * 3. Test Cross-User Isolation for Progress and Favorites
     */
    public function testCrossUserIsolationForProgressAndFavorites(): void
    {
        $user1 = $this->authenticateUser(1, 'user', 'user_one');
        $user2 = $this->authenticateUser(2, 'user', 'user_two');
        $movie = $this->createTestMovie('Interstellar');

        // User 1 watches at 300s
        PlaybackProgress::saveProgress((int)$user1->id, 'movie', (int)$movie->id, 300.0, 1000.0, false);
        Favorite::addFavorite((int)$user1->id, 'movie', (int)$movie->id);

        // User 2 watches at 800s
        PlaybackProgress::saveProgress((int)$user2->id, 'movie', (int)$movie->id, 800.0, 1000.0, false);

        // User 1 progress check
        $u1Progress = PlaybackProgressService::getProgress($user1, 'movie', (int)$movie->id);
        $this->assertEquals(300.0, $u1Progress['position']);
        $this->assertTrue(Favorite::isFavorited((int)$user1->id, 'movie', (int)$movie->id));

        // User 2 progress check
        $u2Progress = PlaybackProgressService::getProgress($user2, 'movie', (int)$movie->id);
        $this->assertEquals(800.0, $u2Progress['position']);
        $this->assertFalse(Favorite::isFavorited((int)$user2->id, 'movie', (int)$movie->id));
    }

    /**
     * 4. Test Guest Safety (Unauthorized Rejection on API Writes)
     */
    public function testGuestSafety(): void
    {
        $_SESSION = []; // Guest session

        // Guest progress read returns safe empty state
        $guestProgress = PlaybackProgressService::getProgress(null, 'movie', 99);
        $this->assertFalse($guestProgress['has_progress']);
        $this->assertFalse($guestProgress['should_resume']);

        // Guest save progress API call returns 401
        $request = new Request([], ['content_type' => 'movie', 'content_id' => 99, 'position' => 10], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $controller = new MediaPlaybackController($this->app);
        $response = $controller->apiSaveProgress($request);

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('unauthenticated', $data['status']);

        // Guest toggle favorite API call returns 401
        $favResponse = $controller->apiToggleFavorite($request);
        $this->assertEquals(401, $favResponse->getStatusCode());
    }

    /**
     * 5. Test 90% Completion Rule
     */
    public function testCompletionRuleAtNinetyPercentThreshold(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createTestMovie('Dunkirk');

        // 89% (890s / 1000s) -> Not completed, should resume
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 890.0, 1000.0, false);
        $p1 = PlaybackProgressService::getProgress($user, 'movie', (int)$movie->id);
        $this->assertFalse($p1['is_completed']);
        $this->assertTrue($p1['should_resume']);

        // 90% (900s / 1000s) -> Completed! should_resume becomes false
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 900.0, 1000.0, false);
        $p2 = PlaybackProgressService::getProgress($user, 'movie', (int)$movie->id);
        $this->assertTrue($p2['is_completed']);
        $this->assertFalse($p2['should_resume']);

        // Explicit is_completed = true even at 50%
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 500.0, 1000.0, true);
        $p3 = PlaybackProgressService::getProgress($user, 'movie', (int)$movie->id);
        $this->assertTrue($p3['is_completed']);
        $this->assertFalse($p3['should_resume']);
    }

    /**
     * 6. Test Resume Logic Thresholds (>= 5s Rule)
     */
    public function testResumeLogicThresholds(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createTestMovie('Oppenheimer');

        // 4.9s -> Position too low, should_resume is false
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 4.9, 1000.0, false);
        $p1 = PlaybackProgressService::getProgress($user, 'movie', (int)$movie->id);
        $this->assertFalse($p1['should_resume']);

        // 5.0s -> At threshold, should_resume is true
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 5.0, 1000.0, false);
        $p2 = PlaybackProgressService::getProgress($user, 'movie', (int)$movie->id);
        $this->assertTrue($p2['should_resume']);
    }

    /**
     * 7. Test Favorites Toggle and Uniqueness
     */
    public function testFavoritesToggleAndUniqueness(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createTestMovie('Tenet');

        // Toggle on
        $res1 = Favorite::toggleFavorite((int)$user->id, 'movie', (int)$movie->id);
        $this->assertTrue($res1['is_favorited']);
        $this->assertTrue(Favorite::isFavorited((int)$user->id, 'movie', (int)$movie->id));

        // Toggle off
        $res2 = Favorite::toggleFavorite((int)$user->id, 'movie', (int)$movie->id);
        $this->assertFalse($res2['is_favorited']);
        $this->assertFalse(Favorite::isFavorited((int)$user->id, 'movie', (int)$movie->id));

        // Direct duplicate insert attempt is safe
        Favorite::addFavorite((int)$user->id, 'movie', (int)$movie->id);
        $duplicate = Favorite::addFavorite((int)$user->id, 'movie', (int)$movie->id);
        $this->assertNotNull($duplicate);

        $count = $this->pdo->query("SELECT COUNT(*) FROM multimedia_favorites WHERE user_id = 1 AND content_type = 'movie' AND content_id = {$movie->id}")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    /**
     * 8. Test Continue Watching Feed (Ordering & Exclusion of Completed Titles)
     */
    public function testContinueWatchingFeed(): void
    {
        $user = $this->authenticateUser(1);
        $m1 = $this->createTestMovie('Movie One');
        $m2 = $this->createTestMovie('Movie Two');
        $m3 = $this->createTestMovie('Movie Three');

        // M1 watched recently (50%, incomplete)
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$m1->id, 500, 1000, false);
        sleep(1);

        // M2 watched most recently (30%, incomplete)
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$m2->id, 300, 1000, false);

        // M3 completed (95%)
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$m3->id, 950, 1000, true);

        $libraryService = new UserLibraryService();
        $continueWatching = $libraryService->getContinueWatching((int)$user->id, 10);

        // Should include M2 and M1, but exclude completed M3
        $this->assertCount(2, $continueWatching);
        $this->assertEquals($m2->id, $continueWatching[0]['content_id']); // Most recent first
        $this->assertEquals($m1->id, $continueWatching[1]['content_id']);
    }

    /**
     * 9. Test Continue Listening Feed for Songs
     */
    public function testContinueListeningFeed(): void
    {
        $user = $this->authenticateUser(1);
        $song = Song::create([
            'title' => 'Echoes of Silence',
            'slug' => 'echoes-of-silence-' . uniqid(),
            'access_mode' => 'public',
            'status' => 'published',
            'duration' => 240,
        ]);

        PlaybackProgress::saveProgress((int)$user->id, 'song', (int)$song->id, 120, 240, false);

        $libraryService = new UserLibraryService();
        $continueListening = $libraryService->getContinueListening((int)$user->id, 5);

        $this->assertCount(1, $continueListening);
        $this->assertEquals($song->id, $continueListening[0]['content_id']);
        $this->assertEquals('2:00', $continueListening[0]['formatted_position']);
    }

    /**
     * 10. Test Series Overall Progress & Next Episode Resolution
     */
    public function testSeriesProgressAndNextEpisode(): void
    {
        $user = $this->authenticateUser(1);

        $series = Series::create([
            'title' => 'Breaking Bad',
            'slug' => 'breaking-bad-' . uniqid(),
            'access_mode' => 'public',
            'status' => 'published',
        ]);

        $season1 = Season::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'title' => 'Season 1',
        ]);

        $season2 = Season::create([
            'series_id' => $series->id,
            'season_number' => 2,
            'title' => 'Season 2',
        ]);

        $ep1 = Episode::create([
            'series_id' => $series->id,
            'season_id' => $season1->id,
            'episode_number' => 1,
            'title' => 'Pilot',
            'slug' => 'pilot-' . uniqid(),
            'status' => 'published',
            'duration' => 3000,
        ]);

        $ep2 = Episode::create([
            'series_id' => $series->id,
            'season_id' => $season1->id,
            'episode_number' => 2,
            'title' => 'Cat\'s in the Bag...',
            'slug' => 'cats-in-the-bag-' . uniqid(),
            'status' => 'published',
            'duration' => 3000,
        ]);

        $ep3 = Episode::create([
            'series_id' => $series->id,
            'season_id' => $season2->id,
            'episode_number' => 1,
            'title' => 'Seven Thirty-Seven',
            'slug' => 'seven-thirty-seven-' . uniqid(),
            'status' => 'published',
            'duration' => 3000,
        ]);

        // Ep 1 is completed
        PlaybackProgress::saveProgress((int)$user->id, 'episode', (int)$ep1->id, 3000, 3000, true);

        // Ep 2 is 50% watched
        PlaybackProgress::saveProgress((int)$user->id, 'episode', (int)$ep2->id, 1500, 3000, false);

        // Check series progress calculation
        $seriesProgress = PlaybackProgressService::getSeriesProgress($user, (int)$series->id);
        $this->assertEquals(3, $seriesProgress['total_episodes']);
        $this->assertEquals(1, $seriesProgress['completed_episodes']);
        $this->assertEquals(33.3, round((float)$seriesProgress['overall_percentage'], 1));

        // Next episode to watch should be ep 2
        $this->assertNotNull($seriesProgress['next_episode']);
        $this->assertEquals($ep2->id, $seriesProgress['next_episode']->id);

        // When ep2 finishes, next should advance across seasons to ep3 (Season 2 Ep 1)
        $nextAfterEp2 = PlaybackProgressService::getNextEpisode($user, (int)$ep2->id);
        $this->assertTrue($nextAfterEp2['found']);
        $this->assertEquals($ep3->id, $nextAfterEp2['episode']->id);

        // After ep3 (last episode), next episode should be null/not found
        $nextAfterEp3 = PlaybackProgressService::getNextEpisode($user, (int)$ep3->id);
        $this->assertFalse($nextAfterEp3['found']);
    }

    /**
     * 11. Test History Removal and Clear All with User Isolation
     */
    public function testHistoryRemovalAndClear(): void
    {
        $user1 = $this->authenticateUser(1, 'user', 'user_one');
        $user2 = $this->authenticateUser(2, 'user', 'user_two');

        $m1 = $this->createTestMovie('Movie 1');
        $m2 = $this->createTestMovie('Movie 2');

        PlaybackProgress::saveProgress((int)$user1->id, 'movie', (int)$m1->id, 100, 1000);
        PlaybackProgress::saveProgress((int)$user1->id, 'movie', (int)$m2->id, 200, 1000);
        PlaybackProgress::saveProgress((int)$user2->id, 'movie', (int)$m1->id, 300, 1000);

        $libraryService = new UserLibraryService();
        $this->assertCount(2, $libraryService->getHistory((int)$user1->id));
        $this->assertCount(1, $libraryService->getHistory((int)$user2->id));

        // User 1 removes M1 from history
        $this->authenticateUser(1, 'user', 'user_one');
        $request = new Request([], ['content_type' => 'movie', 'content_id' => (int)$m1->id], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $controller = new MediaPlaybackController($this->app);
        $controller->apiRemoveHistory($request);

        $this->assertCount(1, $libraryService->getHistory((int)$user1->id));
        // User 2's history must remain untouched
        $this->assertCount(1, $libraryService->getHistory((int)$user2->id));

        // User 1 clears all history
        $controller->apiClearHistory($request);
        $this->assertCount(0, $libraryService->getHistory((int)$user1->id));
        $this->assertCount(1, $libraryService->getHistory((int)$user2->id));
    }

    /**
     * 12. Test Cascade Deletion of Progress and Favorites on Content Removal
     */
    public function testCascadeDeletionOnContentRemoval(): void
    {
        $user = $this->authenticateUser(1, 'admin', 'admin_user');
        $movie = $this->createTestMovie('Expendable Movie');

        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 200, 1000);
        Favorite::addFavorite((int)$user->id, 'movie', (int)$movie->id);

        $this->assertTrue(Favorite::isFavorited((int)$user->id, 'movie', (int)$movie->id));
        $this->assertNotNull(PlaybackProgress::findByUserAndContent((int)$user->id, 'movie', (int)$movie->id));

        // Admin deletes the movie
        $_SESSION['_token'] = 'valid-csrf-token';
        $adminController = new MultimediaAdminController($this->app);
        $req = new Request([], ['action' => 'delete', 'id' => (int)$movie->id, '_token' => 'valid-csrf-token'], ['REQUEST_METHOD' => 'POST']);
        $adminController->movies($req);

        // Verify cascade deletion
        $this->assertFalse(Favorite::isFavorited((int)$user->id, 'movie', (int)$movie->id));
        $this->assertNull(PlaybackProgress::findByUserAndContent((int)$user->id, 'movie', (int)$movie->id));
    }

    /**
     * 13. Test Bangla / Unicode Content Support in Library & History
     */
    public function testBanglaAndUnicodeContentSupport(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createTestMovie('তুফান (Toofan)');

        Favorite::addFavorite((int)$user->id, 'movie', (int)$movie->id);
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 300, 7200);

        $libraryService = new UserLibraryService();
        $myList = $libraryService->getMyList((int)$user->id);
        $history = $libraryService->getHistory((int)$user->id);

        $this->assertNotEmpty($myList);
        $this->assertEquals('তুফান (Toofan)', $myList[0]['title']);

        $this->assertNotEmpty($history);
        $this->assertEquals('তুফান (Toofan)', $history[0]['title']);
    }

    /**
     * 14. Test Frontend Library Page Routes & Rendering
     */
    public function testFrontendLibraryPageRoutes(): void
    {
        $user = $this->authenticateUser(1);
        $movie = $this->createTestMovie('Avatar');
        Favorite::addFavorite((int)$user->id, 'movie', (int)$movie->id);
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$movie->id, 100, 1000);

        $controller = new MultimediaFrontendController($this->app);
        $req = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);

        // Library Hub
        $resLib = $controller->library($req);
        $this->assertEquals(200, $resLib->getStatusCode());
        $this->assertStringContainsString('My Library', $resLib->getContent());
        $this->assertStringContainsString('Avatar', $resLib->getContent());

        // My List
        $resList = $controller->myList($req);
        $this->assertEquals(200, $resList->getStatusCode());
        $this->assertStringContainsString('My List', $resList->getContent());
        $this->assertStringContainsString('Avatar', $resList->getContent());

        // History
        $resHist = $controller->history($req);
        $this->assertEquals(200, $resHist->getStatusCode());
        $this->assertStringContainsString('Playback History', $resHist->getContent());
    }
}
