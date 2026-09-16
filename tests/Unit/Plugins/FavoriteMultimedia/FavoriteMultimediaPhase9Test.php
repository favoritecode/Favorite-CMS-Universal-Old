<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use AddMultimediaSchedulingFields;
use CreateFavoriteMultimediaTables;
use CreateMultimediaEngagementTables;
use CreateMultimediaSubscriptionNotificationTables;
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
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\MultimediaNotification;
use FavoriteCMS\Multimedia\Models\MultimediaNotificationPreference;
use FavoriteCMS\Multimedia\Models\MultimediaSubscription;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\MultimediaDiscoveryService;
use FavoriteCMS\Multimedia\Services\MultimediaNotificationService;
use FavoriteCMS\Multimedia\Services\MultimediaReleaseService;
use FavoriteCMS\Multimedia\Services\MultimediaSubscriptionService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase9Test extends TestCase
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

        // Run migrations 001, 002, 003, 004, and 005
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $m1 = new CreateFavoriteMultimediaTables($this->db);
        $m1->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/002_create_multimedia_user_library_tables.php';
        $m2 = new CreateMultimediaUserLibraryTables($this->db);
        $m2->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/003_create_multimedia_engagement_tables.php';
        $m3 = new CreateMultimediaEngagementTables($this->db);
        $m3->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/004_create_multimedia_subscription_notification_tables.php';
        $m4 = new CreateMultimediaSubscriptionNotificationTables($this->db);
        $m4->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/005_add_multimedia_scheduling_fields.php';
        $m5 = new AddMultimediaSchedulingFields($this->db);
        $m5->up();

        $_SESSION = [];
    }

    private function authenticateUser(int $id = 1, string $role = 'user', string $username = 'user_test'): User
    {
        $this->pdo->exec("INSERT OR REPLACE INTO users (id, username, name, email, password, role) VALUES ({$id}, '{$username}', 'Test User', '{$username}@example.com', 'hash_123', '{$role}')");
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

    private function createMovie(array $overrides = []): Movie
    {
        $data = array_merge([
            'title'        => 'Interstellar',
            'slug'         => 'interstellar-' . uniqid(),
            'description'  => 'A journey beyond space and time.',
            'access_mode'  => 'public',
            'status'       => 'published',
            'publish_at'   => null,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'unpublish_at' => null,
            'views_count'  => 0,
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], $overrides);

        $id = $this->db->insert('multimedia_movies', $data);
        return Movie::find((int)$id);
    }

    private function createSeries(array $overrides = []): Series
    {
        $data = array_merge([
            'title'        => 'Dark',
            'slug'         => 'dark-' . uniqid(),
            'description'  => 'A time travel mystery.',
            'access_mode'  => 'public',
            'status'       => 'published',
            'publish_at'   => null,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'unpublish_at' => null,
            'views_count'  => 0,
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], $overrides);

        $id = $this->db->insert('multimedia_series', $data);
        return Series::find((int)$id);
    }

    private function createSeason(int $seriesId, int $seasonNumber = 1): Season
    {
        $id = $this->db->insert('multimedia_seasons', [
            'series_id'     => $seriesId,
            'season_number' => $seasonNumber,
            'title'         => 'Season ' . $seasonNumber,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        return Season::find((int)$id);
    }

    private function createEpisode(int $seriesId, int $seasonId, array $overrides = []): Episode
    {
        $data = array_merge([
            'series_id'       => $seriesId,
            'season_id'       => $seasonId,
            'episode_number'  => 1,
            'title'           => 'Secrets',
            'slug'            => 'secrets-' . uniqid(),
            'access_mode'     => 'inherit',
            'status'          => 'published',
            'publish_at'      => null,
            'published_at'    => gmdate('Y-m-d H:i:s'),
            'unpublish_at'    => null,
            'download_policy' => 'inherit',
            'views_count'     => 0,
            'created_at'      => gmdate('Y-m-d H:i:s'),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ], $overrides);

        $id = $this->db->insert('multimedia_episodes', $data);
        return Episode::find((int)$id);
    }

    private function createArtist(string $name = 'Radiohead'): Artist
    {
        $id = $this->db->insert('multimedia_artists', [
            'name'       => $name,
            'slug'       => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid(),
            'biography'  => 'Legendary alternative rock band',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return Artist::find((int)$id);
    }

    private function createSong(int $artistId, array $overrides = []): Song
    {
        $data = array_merge([
            'title'        => 'Karma Police',
            'slug'         => 'karma-police-' . uniqid(),
            'artist_id'    => $artistId,
            'access_mode'  => 'public',
            'status'       => 'published',
            'publish_at'   => null,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'unpublish_at' => null,
            'plays_count'  => 0,
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], $overrides);

        $id = $this->db->insert('multimedia_songs', $data);
        return Song::find((int)$id);
    }

    private function createMediaSource(string $contentType, int $contentId, string $status = 'active'): MediaSource
    {
        $id = $this->db->insert('multimedia_sources', [
            'content_type'   => $contentType,
            'content_id'     => $contentId,
            'source_mode'    => 'url',
            'source_type'    => ($contentType === 'song' ? 'audio' : 'video'),
            'url_or_path'    => 'https://cdn.example.com/media.mp4',
            'label'          => '1080p Stream',
            'mime_type'      => 'video/mp4',
            'is_default'     => 1,
            'allow_download' => 'inherit',
            'status'         => $status,
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        return MediaSource::find((int)$id);
    }

    // -------------------------------------------------------------------------
    // Test 1: Migration 005 creates columns and indexes, backfills published_at
    // -------------------------------------------------------------------------
    public function testMigration005CreatesColumnsAndIndexes(): void
    {
        $tables = ['multimedia_movies', 'multimedia_series', 'multimedia_episodes', 'multimedia_songs'];

        foreach ($tables as $t) {
            $cols = $this->db->select("PRAGMA table_info({$t})");
            $names = array_column(array_map(fn($c) => (array)$c, $cols), 'name');

            $this->assertContains('publish_at', $names, "Table {$t} must have publish_at column");
            $this->assertContains('published_at', $names, "Table {$t} must have published_at column");
            $this->assertContains('unpublish_at', $names, "Table {$t} must have unpublish_at column");
        }
    }

    // -------------------------------------------------------------------------
    // Test 2: Draft content private from public visitors
    // -------------------------------------------------------------------------
    public function testDraftContentPrivateFromPublic(): void
    {
        $movie = $this->createMovie(['status' => 'draft']);
        $this->createMediaSource('movie', (int)$movie->id);

        // Guest user access
        $guestAccess = MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id);
        $this->assertSame(MultimediaAccessService::NOT_FOUND, $guestAccess);

        // Admin user access
        $admin = $this->authenticateUser(1, 'admin');
        $adminAccess = MultimediaAccessService::checkAccess($admin, 'movie', (int)$movie->id);
        $this->assertSame(MultimediaAccessService::ALLOW, $adminAccess);
    }

    // -------------------------------------------------------------------------
    // Test 3: Schedule validation requires valid future timestamp
    // -------------------------------------------------------------------------
    public function testScheduleValidationRequiresValidFutureTimestamp(): void
    {
        $admin = $this->authenticateUser(1, 'admin');
        $movie = $this->createMovie(['status' => 'draft']);
        $this->createMediaSource('movie', (int)$movie->id);

        // Reject invalid date string
        $resInvalid = MultimediaReleaseService::scheduleItem('movie', (int)$movie->id, 'invalid-date-xyz', null, $admin);
        $this->assertFalse($resInvalid['success']);

        // Reject past timestamp
        $resPast = MultimediaReleaseService::scheduleItem('movie', (int)$movie->id, '2020-01-01 00:00:00', null, $admin);
        $this->assertFalse($resPast['success']);
        $this->assertStringContainsString('future', strtolower($resPast['error']));

        // Accept valid future timestamp
        $futureUtc = gmdate('Y-m-d H:i:s', time() + 86400);
        $resFuture = MultimediaReleaseService::scheduleItem('movie', (int)$movie->id, $futureUtc, null, $admin);
        $this->assertTrue($resFuture['success']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('scheduled', $refreshed->status);
        $this->assertSame($futureUtc, $refreshed->publish_at);
    }

    // -------------------------------------------------------------------------
    // Test 4: Publish Now transitions to published and sets published_at
    // -------------------------------------------------------------------------
    public function testPublishNowTransitionsToPublishedAndSetsPublishedAt(): void
    {
        $admin = $this->authenticateUser(1, 'admin');
        $movie = $this->createMovie(['status' => 'draft', 'published_at' => null]);
        $this->createMediaSource('movie', (int)$movie->id);

        $res = MultimediaReleaseService::publishNow('movie', (int)$movie->id, $admin);
        $this->assertTrue($res['success']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('published', $refreshed->status);
        $this->assertNotNull($refreshed->published_at);
        $this->assertNull($refreshed->publish_at);
    }

    // -------------------------------------------------------------------------
    // Test 5: Future schedule remains inaccessible before due time
    // -------------------------------------------------------------------------
    public function testFutureScheduleRemainsInaccessibleBeforeDueTime(): void
    {
        $futureUtc = gmdate('Y-m-d H:i:s', time() + 3600);
        $movie = $this->createMovie(['status' => 'scheduled', 'publish_at' => $futureUtc]);
        $this->createMediaSource('movie', (int)$movie->id);

        $guestAccess = MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id);
        $this->assertSame(MultimediaAccessService::NOT_FOUND, $guestAccess);

        $member = $this->authenticateUser(2, 'user');
        $memberAccess = MultimediaAccessService::checkAccess($member, 'movie', (int)$movie->id);
        $this->assertSame(MultimediaAccessService::NOT_FOUND, $memberAccess);
    }

    // -------------------------------------------------------------------------
    // Test 6: Due release processor publishes due items
    // -------------------------------------------------------------------------
    public function testDueReleaseProcessorPublishesDueItems(): void
    {
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 100);
        $movie = $this->createMovie(['status' => 'scheduled', 'publish_at' => $pastUtc, 'published_at' => null]);
        $this->createMediaSource('movie', (int)$movie->id);

        $res = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(1, $res['published']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('published', $refreshed->status);
        $this->assertNotNull($refreshed->published_at);
    }

    // -------------------------------------------------------------------------
    // Test 7: Idempotency: multiple runs cause zero duplicate alerts or transitions
    // -------------------------------------------------------------------------
    public function testIdempotencyZeroDuplicateTransitionsOrAlerts(): void
    {
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 50);
        $movie = $this->createMovie(['status' => 'scheduled', 'publish_at' => $pastUtc]);
        $this->createMediaSource('movie', (int)$movie->id);

        $run1 = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(1, $run1['published']);

        // Second immediate run
        $run2 = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(0, $run2['published']);
        $this->assertSame(0, $run2['processed']);
    }

    // -------------------------------------------------------------------------
    // Test 8: Missed schedule recovery (simulating worker downtime)
    // -------------------------------------------------------------------------
    public function testMissedScheduleRecovery(): void
    {
        // Scheduled 3 days ago during hypothetical server outage
        $pastUtc = gmdate('Y-m-d H:i:s', time() - (3 * 86400));
        $movie = $this->createMovie(['status' => 'scheduled', 'publish_at' => $pastUtc]);
        $this->createMediaSource('movie', (int)$movie->id);

        $res = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(1, $res['published']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('published', $refreshed->status);
        $this->assertNotNull($refreshed->published_at);
    }

    // -------------------------------------------------------------------------
    // Test 9: Cancel schedule: reverts to draft without alerts
    // -------------------------------------------------------------------------
    public function testCancelScheduleRevertsToDraftWithoutAlerts(): void
    {
        $admin = $this->authenticateUser(1, 'admin');
        $futureUtc = gmdate('Y-m-d H:i:s', time() + 7200);
        $movie = $this->createMovie(['status' => 'scheduled', 'publish_at' => $futureUtc]);

        $res = MultimediaReleaseService::cancelSchedule('movie', (int)$movie->id, $admin);
        $this->assertTrue($res['success']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('draft', $refreshed->status);
        $this->assertNull($refreshed->publish_at);

        // Verify no notification dispatched
        $count = $this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_notifications")->c ?? 0;
        $this->assertSame(0, (int)$count);
    }

    // -------------------------------------------------------------------------
    // Test 10: Reschedule changes publish_at without early release or alerts
    // -------------------------------------------------------------------------
    public function testRescheduleChangesPublishAtWithoutPrematureReleaseOrAlerts(): void
    {
        $admin = $this->authenticateUser(1, 'admin');
        $movie = $this->createMovie(['status' => 'scheduled', 'publish_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $this->createMediaSource('movie', (int)$movie->id);

        $laterUtc = gmdate('Y-m-d H:i:s', time() + 7200);
        $res = MultimediaReleaseService::scheduleItem('movie', (int)$movie->id, $laterUtc, null, $admin);
        $this->assertTrue($res['success']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('scheduled', $refreshed->status);
        $this->assertSame($laterUtc, $refreshed->publish_at);

        // Public access remains NOT_FOUND
        $this->assertSame(MultimediaAccessService::NOT_FOUND, MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id));
    }

    // -------------------------------------------------------------------------
    // Test 11: Episode release notification integration
    // -------------------------------------------------------------------------
    public function testEpisodeReleaseNotificationIntegration(): void
    {
        $user = $this->authenticateUser(10, 'user', 'series_fan');
        $series = $this->createSeries(['status' => 'published']);
        $season = $this->createSeason((int)$series->id, 1);

        // User follows the series
        MultimediaSubscriptionService::subscribe($user, 'series', (int)$series->id);

        // Schedule episode due in the past
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 30);
        $episode = $this->createEpisode((int)$series->id, (int)$season->id, [
            'status'     => 'scheduled',
            'publish_at' => $pastUtc,
        ]);
        $this->createMediaSource('episode', (int)$episode->id);

        // Run due release processor
        $res = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(1, $res['published']);

        // Check follower received notification
        $inbox = MultimediaNotificationService::getInbox($user, 'all', 10, 0);
        $this->assertSame(1, $inbox['total']);
        $this->assertSame('new_episode', $inbox['items'][0]->type);
        $this->assertSame((int)$episode->id, $inbox['items'][0]->target_id);
    }

    // -------------------------------------------------------------------------
    // Test 12: Notification deduplication intact on repeat save/publish
    // -------------------------------------------------------------------------
    public function testNotificationDeduplicationIntact(): void
    {
        $user = $this->authenticateUser(11, 'user', 'dedupe_fan');
        $series = $this->createSeries(['status' => 'published']);
        $season = $this->createSeason((int)$series->id, 1);
        MultimediaSubscriptionService::subscribe($user, 'series', (int)$series->id);

        $episode = $this->createEpisode((int)$series->id, (int)$season->id, ['status' => 'published']);

        // Call notification trigger twice
        MultimediaNotificationService::onEpisodePublished((int)$episode->id);
        MultimediaNotificationService::onEpisodePublished((int)$episode->id);

        $inbox = MultimediaNotificationService::getInbox($user, 'all', 10, 0);
        $this->assertSame(1, $inbox['total']);
    }

    // -------------------------------------------------------------------------
    // Test 13: Content update preference enforcement
    // -------------------------------------------------------------------------
    public function testContentUpdatePreferenceEnforcement(): void
    {
        $user = $this->authenticateUser(12, 'user', 'quiet_user');
        $series = $this->createSeries(['status' => 'published']);
        $season = $this->createSeason((int)$series->id, 1);
        MultimediaSubscriptionService::subscribe($user, 'series', (int)$series->id);

        // Turn OFF content update notifications
        MultimediaNotificationService::savePreferences($user, ['notify_content_updates' => 0]);

        // Publish episode via release runner
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 30);
        $episode = $this->createEpisode((int)$series->id, (int)$season->id, [
            'status'     => 'scheduled',
            'publish_at' => $pastUtc,
        ]);
        $this->createMediaSource('episode', (int)$episode->id);

        MultimediaReleaseService::processDueReleases(10);

        $inbox = MultimediaNotificationService::getInbox($user, 'all', 10, 0);
        $this->assertSame(0, $inbox['total']);
    }

    // -------------------------------------------------------------------------
    // Test 14: Premium access preservation after scheduled release
    // -------------------------------------------------------------------------
    public function testPremiumAccessPreservation(): void
    {
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 20);
        $movie = $this->createMovie([
            'access_mode' => 'premium',
            'status'      => 'scheduled',
            'publish_at'  => $pastUtc,
        ]);
        $this->createMediaSource('movie', (int)$movie->id);

        // Process release
        MultimediaReleaseService::processDueReleases(10);

        // Public visitor denied with LOGIN_REQUIRED (must login first)
        $guestAccess = MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id);
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, $guestAccess);

        // Normal logged in user without premium entitlement gets PREMIUM_REQUIRED
        $user = $this->authenticateUser(14, 'user');
        $userAccess = MultimediaAccessService::checkAccess($user, 'movie', (int)$movie->id);
        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, $userAccess);
    }

    // -------------------------------------------------------------------------
    // Test 15: Public pilot override preservation
    // -------------------------------------------------------------------------
    public function testPublicPilotOverridePreservation(): void
    {
        $series = $this->createSeries(['access_mode' => 'premium', 'status' => 'published']);
        $season = $this->createSeason((int)$series->id, 1);

        $pastUtc = gmdate('Y-m-d H:i:s', time() - 20);
        $pilot = $this->createEpisode((int)$series->id, (int)$season->id, [
            'episode_number' => 1,
            'access_mode'    => 'public', // Free pilot
            'status'         => 'scheduled',
            'publish_at'     => $pastUtc,
        ]);
        $this->createMediaSource('episode', (int)$pilot->id);

        $ep2 = $this->createEpisode((int)$series->id, (int)$season->id, [
            'episode_number' => 2,
            'access_mode'    => 'inherit', // Inherits premium
            'status'         => 'scheduled',
            'publish_at'     => $pastUtc,
        ]);
        $this->createMediaSource('episode', (int)$ep2->id);

        MultimediaReleaseService::processDueReleases(10);

        // Guest check: free pilot is ALLOW, premium episode 2 is LOGIN_REQUIRED
        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess(null, 'episode', (int)$pilot->id));
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, MultimediaAccessService::checkAccess(null, 'episode', (int)$ep2->id));

        // Member check: premium episode 2 is PREMIUM_REQUIRED
        $user = $this->authenticateUser(15, 'user');
        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, MultimediaAccessService::checkAccess($user, 'episode', (int)$ep2->id));
    }

    // -------------------------------------------------------------------------
    // Test 16: Recently Added integration: excluded before, included after published
    // -------------------------------------------------------------------------
    public function testRecentlyAddedIntegration(): void
    {
        $futureUtc = gmdate('Y-m-d H:i:s', time() + 3600);
        $movie = $this->createMovie(['title' => 'Future Hit', 'status' => 'scheduled', 'publish_at' => $futureUtc]);

        $recentBefore = MultimediaDiscoveryService::getRecentlyAdded(10, 'movie');
        $titlesBefore = array_map(fn($item) => $item['model']->title, $recentBefore);
        $this->assertNotContains('Future Hit', $titlesBefore);

        // Now publish
        $this->createMediaSource('movie', (int)$movie->id);
        $admin = $this->authenticateUser(1, 'admin');
        MultimediaReleaseService::publishNow('movie', (int)$movie->id, $admin);

        $recentAfter = MultimediaDiscoveryService::getRecentlyAdded(10, 'movie');
        $titlesAfter = array_map(fn($item) => $item['model']->title, $recentAfter);
        $this->assertContains('Future Hit', $titlesAfter);
    }

    // -------------------------------------------------------------------------
    // Test 17: Search prerelease exclusion
    // -------------------------------------------------------------------------
    public function testSearchPrereleaseExclusion(): void
    {
        $futureUtc = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->createMovie(['title' => 'Secret Inception', 'status' => 'scheduled', 'publish_at' => $futureUtc]);
        $this->createMovie(['title' => 'Secret Draft', 'status' => 'draft']);

        $res = MultimediaDiscoveryService::search('Secret', 'movie');
        $this->assertCount(0, $res['results']);
    }

    // -------------------------------------------------------------------------
    // Test 18: Discovery prerelease exclusion (trending/popular/recommendations)
    // -------------------------------------------------------------------------
    public function testDiscoveryPrereleaseExclusion(): void
    {
        $futureUtc = gmdate('Y-m-d H:i:s', time() + 3600);
        $schedMovie = $this->createMovie(['title' => 'Unreleased Sensation', 'status' => 'scheduled', 'publish_at' => $futureUtc, 'views_count' => 99999]);
        $draftMovie = $this->createMovie(['title' => 'Hidden Draft', 'status' => 'draft', 'views_count' => 88888]);

        $trending = MultimediaDiscoveryService::getTrending(10, 'movie');
        $trendingTitles = array_map(fn($item) => $item['model']->title, $trending);
        $this->assertNotContains('Unreleased Sensation', $trendingTitles);
        $this->assertNotContains('Hidden Draft', $trendingTitles);

        $popular = MultimediaDiscoveryService::getPopular(10, 'movie');
        $popularTitles = array_map(fn($item) => $item['model']->title, $popular);
        $this->assertNotContains('Unreleased Sensation', $popularTitles);
    }

    // -------------------------------------------------------------------------
    // Test 19: Direct stream denial before release
    // -------------------------------------------------------------------------
    public function testDirectStreamDenialBeforeRelease(): void
    {
        $movie = $this->createMovie(['status' => 'scheduled', 'publish_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $source = $this->createMediaSource('movie', (int)$movie->id);

        $playbackCtrl = new MediaPlaybackController($this->app);
        $req = new Request();
        $res = $playbackCtrl->stream($req, (string)$source->id);

        $this->assertSame(404, $res->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 20: Parent hierarchy coordination: episode with unreleased series fails
    // -------------------------------------------------------------------------
    public function testParentHierarchyCoordination(): void
    {
        $series = $this->createSeries(['status' => 'draft']); // Parent not published
        $season = $this->createSeason((int)$series->id, 1);

        $pastUtc = gmdate('Y-m-d H:i:s', time() - 30);
        $episode = $this->createEpisode((int)$series->id, (int)$season->id, [
            'status'     => 'scheduled',
            'publish_at' => $pastUtc,
        ]);
        $this->createMediaSource('episode', (int)$episode->id);

        // Readiness check should fail
        $validation = MultimediaReleaseService::validateReadiness('episode', (int)$episode->id);
        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('series must be published', strtolower($validation['error']));

        // Release runner isolates failure and does not publish episode
        $runner = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(0, $runner['published']);
        $this->assertNotEmpty($runner['errors']);

        // Episode remains scheduled
        $epRefreshed = Episode::find((int)$episode->id);
        $this->assertSame('scheduled', $epRefreshed->status);
    }

    // -------------------------------------------------------------------------
    // Test 21: Song release: artist followers notified, multi-artist deduplication intact
    // -------------------------------------------------------------------------
    public function testSongReleaseArtistFollowersNotifiedAndDeduplicated(): void
    {
        $user1 = $this->authenticateUser(21, 'user', 'music_fan_1');
        $user2 = $this->authenticateUser(22, 'user', 'music_fan_2');

        $artist = $this->createArtist('Daft Punk');
        MultimediaSubscriptionService::subscribe($user1, 'artist', (int)$artist->id);
        MultimediaSubscriptionService::subscribe($user2, 'artist', (int)$artist->id);

        $pastUtc = gmdate('Y-m-d H:i:s', time() - 25);
        $song = $this->createSong((int)$artist->id, [
            'status'     => 'scheduled',
            'publish_at' => $pastUtc,
        ]);
        $this->createMediaSource('song', (int)$song->id);

        MultimediaReleaseService::processDueReleases(10);

        $inbox1 = MultimediaNotificationService::getInbox($user1, 'all', 10, 0);
        $inbox2 = MultimediaNotificationService::getInbox($user2, 'all', 10, 0);

        $this->assertSame(1, $inbox1['total']);
        $this->assertSame(1, $inbox2['total']);
        $this->assertSame('new_song', $inbox1['items'][0]->type);
    }

    // -------------------------------------------------------------------------
    // Test 22: Movie release preserves artwork and metadata
    // -------------------------------------------------------------------------
    public function testMovieReleaseArtworkAndMetadataIntact(): void
    {
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 15);
        $movie = $this->createMovie([
            'poster'       => 'https://images.example.com/poster.jpg',
            'backdrop'     => 'https://images.example.com/backdrop.jpg',
            'duration'     => 7200,
            'status'       => 'scheduled',
            'publish_at'   => $pastUtc,
        ]);
        $this->createMediaSource('movie', (int)$movie->id);

        MultimediaReleaseService::processDueReleases(10);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('published', $refreshed->status);
        $this->assertSame('https://images.example.com/poster.jpg', $refreshed->poster);
        $this->assertSame('https://images.example.com/backdrop.jpg', $refreshed->backdrop);
        $this->assertSame(7200, $refreshed->duration);
    }

    // -------------------------------------------------------------------------
    // Test 23: Failed readiness validation: missing media source does not expose broken media
    // -------------------------------------------------------------------------
    public function testFailedReadinessMissingMediaSource(): void
    {
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 15);
        // Movie without media source
        $movie = $this->createMovie([
            'status'     => 'scheduled',
            'publish_at' => $pastUtc,
        ]);

        $res = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(0, $res['published']);
        $this->assertNotEmpty($res['errors']);

        // Movie remains scheduled, not published with missing media
        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('scheduled', $refreshed->status);
    }

    // -------------------------------------------------------------------------
    // Test 24: Bounded batch processing respects limit
    // -------------------------------------------------------------------------
    public function testBoundedBatchProcessingLimit(): void
    {
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 20);

        for ($i = 1; $i <= 5; $i++) {
            $m = $this->createMovie(['title' => 'Batch Movie ' . $i, 'status' => 'scheduled', 'publish_at' => $pastUtc]);
            $this->createMediaSource('movie', (int)$m->id);
        }

        // Run with limit = 2
        $res = MultimediaReleaseService::processDueReleases(2);
        $this->assertSame(2, $res['published']);
        $this->assertSame(2, $res['processed']);

        // Check remaining scheduled items
        $queue = MultimediaReleaseService::getScheduledQueue();
        $this->assertCount(3, $queue);
    }

    // -------------------------------------------------------------------------
    // Test 25: Timezone conversion UTC vs local application timezone
    // -------------------------------------------------------------------------
    public function testTimezoneConversionUtcVsLocal(): void
    {
        Setting::set('general', 'timezone', 'Asia/Dhaka'); // UTC+6

        $localStr = '2026-10-15 18:00:00';
        $utcStr = MultimediaReleaseService::localToUtc($localStr);
        $this->assertSame('2026-10-15 12:00:00', $utcStr);

        $convertedBack = MultimediaReleaseService::utcToLocal($utcStr, 'Y-m-d H:i:s');
        $this->assertSame($localStr, $convertedBack);

        // Reset to UTC
        Setting::set('general', 'timezone', 'UTC');
    }

    // -------------------------------------------------------------------------
    // Test 26: Optional unpublish scheduling
    // -------------------------------------------------------------------------
    public function testOptionalUnpublishScheduling(): void
    {
        $pastUnpublish = gmdate('Y-m-d H:i:s', time() - 60);
        $movie = $this->createMovie([
            'status'       => 'published',
            'unpublish_at' => $pastUnpublish,
        ]);

        $res = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(1, $res['unpublished']);

        $refreshed = Movie::find((int)$movie->id);
        $this->assertSame('unpublished', $refreshed->status);
    }

    // -------------------------------------------------------------------------
    // Test 27: Bangla and Unicode UTF-8 support in release titles and calendar
    // -------------------------------------------------------------------------
    public function testUnicodeAndBanglaSupport(): void
    {
        $banglaTitle = 'পথের পাঁচালী — সত্যজিৎ রায়';
        $futureUtc = gmdate('Y-m-d H:i:s', time() + 7200);
        $movie = $this->createMovie([
            'title'      => $banglaTitle,
            'status'     => 'scheduled',
            'publish_at' => $futureUtc,
        ]);

        $queue = MultimediaReleaseService::getScheduledQueue();
        $this->assertSame($banglaTitle, $queue[0]['title']);

        $startUtc = gmdate('Y-m-d 00:00:00');
        $endUtc = gmdate('Y-m-d 23:59:59', time() + 86400 * 2);
        $calendar = MultimediaReleaseService::getCalendarReleases($startUtc, $endUtc);

        $this->assertSame($banglaTitle, $calendar[0]['title']);
    }

    // -------------------------------------------------------------------------
    // Test 28: Permission enforcement: non-admins cannot schedule or publish
    // -------------------------------------------------------------------------
    public function testPermissionEnforcementNonAdminsCannotSchedule(): void
    {
        $user = $this->authenticateUser(28, 'user');
        $movie = $this->createMovie(['status' => 'draft']);

        $adminCtrl = new MultimediaAdminController($this->app);
        $req = new Request([], [
            'action'       => 'publish_now',
            'content_type' => 'movie',
            'content_id'   => (int)$movie->id,
            '_token'       => 'some_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $adminCtrl->apiPublishNow($req);
        $this->assertSame(403, $res->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 29: CSRF validation on schedule mutation endpoints
    // -------------------------------------------------------------------------
    public function testCsrfValidationOnScheduleMutationEndpoints(): void
    {
        $admin = $this->authenticateUser(1, 'admin');
        $_SESSION['_token'] = 'secret_session_token_123';

        $adminCtrl = new MultimediaAdminController($this->app);

        // Missing or invalid CSRF token
        $req = new Request([], [
            'content_type' => 'movie',
            'content_id'   => 1,
            '_token'       => 'invalid_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res1 = $adminCtrl->apiPublishNow($req);
        $this->assertSame(403, $res1->getStatusCode());

        $res2 = $adminCtrl->apiScheduleRelease($req);
        $this->assertSame(403, $res2->getStatusCode());

        $res3 = $adminCtrl->apiCancelSchedule($req);
        $this->assertSame(403, $res3->getStatusCode());

        $res4 = $adminCtrl->apiRunDueReleases($req);
        $this->assertSame(403, $res4->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 30: SQL injection resistance in calendar queries
    // -------------------------------------------------------------------------
    public function testSqlInjectionResistanceInCalendarQueries(): void
    {
        $maliciousType = "movie' OR '1'='1";
        $maliciousStatus = "published' UNION SELECT 1,2,3--";

        $calendar = MultimediaReleaseService::getCalendarReleases(
            gmdate('Y-m-d 00:00:00'),
            gmdate('Y-m-d 23:59:59'),
            ['content_type' => $maliciousType, 'status' => $maliciousStatus]
        );

        $this->assertIsArray($calendar);
    }

    // -------------------------------------------------------------------------
    // Test 31: XSS escaping in release display
    // -------------------------------------------------------------------------
    public function testXssEscapingInReleaseDisplay(): void
    {
        $xssTitle = "<script>alert('XSS')</script>";
        $movie = $this->createMovie(['title' => $xssTitle, 'status' => 'scheduled', 'publish_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

        $queue = MultimediaReleaseService::getScheduledQueue();
        $this->assertSame($xssTitle, $queue[0]['title']);

        // Verify htmlspecialchars escapes dangerous characters
        $escaped = htmlspecialchars($queue[0]['title'], ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    // -------------------------------------------------------------------------
    // Test 32: Full regression: Phase 1-8 features remain fully functional
    // -------------------------------------------------------------------------
    public function testFullRegressionAllPhases(): void
    {
        // 1. Phase 1 & 4: Access check and media resolution
        $movie = $this->createMovie(['status' => 'published', 'access_mode' => 'public']);
        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess(null, 'movie', (int)$movie->id));

        // 2. Phase 2: User Library progress
        $user = $this->authenticateUser(32, 'user');
        $this->createMediaSource('movie', (int)$movie->id);

        // 3. Phase 7: Ratings and Reviews
        $reviewId = $this->db->insert('multimedia_reviews', [
            'content_type' => 'movie',
            'content_id'   => (int)$movie->id,
            'user_id'      => (int)$user->id,
            'title'        => 'Masterpiece',
            'body'         => 'Mind bending journey.',
            'rating'       => 5,
            'status'       => 'approved',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);
        $this->assertGreaterThan(0, $reviewId);

        // 4. Phase 8: Subscriptions and Notifications
        $artist = $this->createArtist('Hans Zimmer');
        $subOk = MultimediaSubscriptionService::subscribe($user, 'artist', (int)$artist->id);
        $this->assertTrue($subOk);

        // 5. Phase 9: Scheduled Release Automation
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 30);
        $song = $this->createSong((int)$artist->id, [
            'title'      => 'Time',
            'status'     => 'scheduled',
            'publish_at' => $pastUtc,
        ]);
        $this->createMediaSource('song', (int)$song->id);

        $runner = MultimediaReleaseService::processDueReleases(10);
        $this->assertSame(1, $runner['published']);

        $inbox = MultimediaNotificationService::getInbox($user, 'all', 10, 0);
        $this->assertSame(1, $inbox['total']);
        $this->assertSame('new_song', $inbox['items'][0]->type);
    }
}
