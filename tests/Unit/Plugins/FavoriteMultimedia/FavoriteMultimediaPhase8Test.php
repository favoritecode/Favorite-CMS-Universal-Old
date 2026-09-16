<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

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
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\MultimediaComment;
use FavoriteCMS\Multimedia\Models\MultimediaNotification;
use FavoriteCMS\Multimedia\Models\MultimediaNotificationPreference;
use FavoriteCMS\Multimedia\Models\MultimediaReview;
use FavoriteCMS\Multimedia\Models\MultimediaSubscription;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\MultimediaDiscoveryService;
use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;
use FavoriteCMS\Multimedia\Services\MultimediaNotificationService;
use FavoriteCMS\Multimedia\Services\MultimediaSubscriptionService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase8Test extends TestCase
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

        // Run migrations 001, 002, 003, and 004
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

    private function createSeries(string $title = 'Stranger Things', string $slug = 'stranger-things'): Series
    {
        $id = $this->db->insert('multimedia_series', [
            'title'       => $title,
            'slug'        => $slug,
            'access_mode' => 'public',
            'status'      => 'published',
            'views_count' => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return Series::find((int)$id);
    }

    private function createSeason(int $seriesId, int $seasonNumber = 1): Season
    {
        $id = $this->db->insert('multimedia_seasons', [
            'series_id'     => $seriesId,
            'season_number' => $seasonNumber,
            'title'         => "Season {$seasonNumber}",
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        return Season::find((int)$id);
    }

    private function createEpisode(int $seriesId, int $seasonId, int $epNum = 1, string $title = 'Chapter One', string $status = 'published'): Episode
    {
        $id = $this->db->insert('multimedia_episodes', [
            'series_id'      => $seriesId,
            'season_id'      => $seasonId,
            'episode_number' => $epNum,
            'title'          => $title,
            'slug'           => 'ep-' . $seriesId . '-' . $seasonId . '-' . $epNum . '-' . mt_rand(1000, 9999),
            'access_mode'    => 'public',
            'status'         => $status,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        return Episode::find((int)$id);
    }

    private function createArtist(string $name = 'Coldplay', string $slug = 'coldplay'): Artist
    {
        $id = $this->db->insert('multimedia_artists', [
            'name'       => $name,
            'slug'       => $slug,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Artist::find((int)$id);
    }

    private function createSong(string $title = 'Yellow', string $slug = 'yellow', ?int $artistId = null, string $status = 'published'): Song
    {
        $id = $this->db->insert('multimedia_songs', [
            'title'       => $title,
            'slug'        => $slug,
            'artist_id'   => $artistId,
            'access_mode' => 'public',
            'status'      => $status,
            'plays_count' => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return Song::find((int)$id);
    }

    private function createPlaylist(string $title = 'Road Trip', string $slug = 'road-trip'): Playlist
    {
        $id = $this->db->insert('multimedia_playlists', [
            'title'       => $title,
            'slug'        => $slug,
            'status'      => 'published',
            'access_mode' => 'public',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return Playlist::find((int)$id);
    }

    // -------------------------------------------------------------------------
    // Test 1: Migration 004 creates subscription & notification tables
    // -------------------------------------------------------------------------
    public function testMigrationCreatesSubscriptionAndNotificationTables(): void
    {
        $this->assertTrue(MultimediaSubscriptionService::hasSubscriptionsTable());
        $this->assertTrue(MultimediaNotificationService::hasNotificationsTable());
        $this->assertTrue(MultimediaNotificationService::hasPreferencesTable());
    }

    // -------------------------------------------------------------------------
    // Test 2: Subscription uniqueness constraint (user_id, target_type, target_id)
    // -------------------------------------------------------------------------
    public function testSubscriptionUniquenessConstraint(): void
    {
        $user = $this->authenticateUser(10);
        $series = $this->createSeries();

        $this->db->insert('multimedia_subscriptions', [
            'user_id'     => $user->id,
            'target_type' => 'series',
            'target_id'   => $series->id,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $duplicateThrew = false;
        try {
            $this->db->insert('multimedia_subscriptions', [
                'user_id'     => $user->id,
                'target_type' => 'series',
                'target_id'   => $series->id,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            $duplicateThrew = true;
        }

        $this->assertTrue($duplicateThrew, 'Duplicate raw subscription must be rejected by unique index');
    }

    // -------------------------------------------------------------------------
    // Test 3: Follow / unfollow toggle idempotency
    // -------------------------------------------------------------------------
    public function testFollowUnfollowToggleIdempotency(): void
    {
        $user = $this->authenticateUser(20);
        $artist = $this->createArtist();

        // 1. Follow
        $res1 = MultimediaSubscriptionService::follow($user, 'artist', (int)$artist->id);
        $this->assertTrue($res1['success']);
        $this->assertTrue($res1['is_following']);
        $this->assertSame(1, $res1['subscriber_count']);
        $this->assertTrue(MultimediaSubscriptionService::isFollowing($user, 'artist', (int)$artist->id));

        // Repeat follow should be idempotent (not fail or duplicate)
        $resRepeat = MultimediaSubscriptionService::follow($user, 'artist', (int)$artist->id);
        $this->assertTrue($resRepeat['success']);
        $this->assertSame(1, $resRepeat['subscriber_count']);

        // 2. Toggle -> Unfollow
        $resToggle = MultimediaSubscriptionService::toggle($user, 'artist', (int)$artist->id);
        $this->assertTrue($resToggle['success']);
        $this->assertFalse($resToggle['is_following']);
        $this->assertSame(0, $resToggle['subscriber_count']);
        $this->assertFalse(MultimediaSubscriptionService::isFollowing($user, 'artist', (int)$artist->id));

        // 3. Toggle again -> Follow
        $resToggle2 = MultimediaSubscriptionService::toggle($user, 'artist', (int)$artist->id);
        $this->assertTrue($resToggle2['success']);
        $this->assertTrue($resToggle2['is_following']);
        $this->assertSame(1, $resToggle2['subscriber_count']);
    }

    // -------------------------------------------------------------------------
    // Test 4: Target type validation (allowed: series, artist, playlist)
    // -------------------------------------------------------------------------
    public function testTargetTypeValidationRejectsInvalidTypes(): void
    {
        $user = $this->authenticateUser(30);

        // Invalid target type 'movie' (only series, artist, playlist can be followed)
        $res = MultimediaSubscriptionService::follow($user, 'movie', 1);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Invalid subscription target', $res['error']);

        // Invalid random type
        $resRandom = MultimediaSubscriptionService::follow($user, 'custom_type', 1);
        $this->assertFalse($resRandom['success']);
    }

    // -------------------------------------------------------------------------
    // Test 5: Target existence validation
    // -------------------------------------------------------------------------
    public function testTargetExistenceValidation(): void
    {
        $user = $this->authenticateUser(35);

        // Series with non-existent ID 99999
        $res = MultimediaSubscriptionService::follow($user, 'series', 99999);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Target item not found', $res['error']);
    }

    // -------------------------------------------------------------------------
    // Test 6: User ownership isolation in subscriptions
    // -------------------------------------------------------------------------
    public function testUserOwnershipIsolationInSubscriptions(): void
    {
        $userA = $this->authenticateUser(41, 'user', 'user_a');
        $userB = $this->authenticateUser(42, 'user', 'user_b');
        $series = $this->createSeries();

        // User A follows series
        MultimediaSubscriptionService::follow($userA, 'series', (int)$series->id);

        $subsA = MultimediaSubscriptionService::getUserSubscriptions($userA->id);
        $subsB = MultimediaSubscriptionService::getUserSubscriptions($userB->id);

        $this->assertCount(1, $subsA['series']);
        $this->assertCount(0, $subsB['series']);

        $this->assertTrue(MultimediaSubscriptionService::isFollowing($userA, 'series', (int)$series->id));
        $this->assertFalse(MultimediaSubscriptionService::isFollowing($userB, 'series', (int)$series->id));
    }

    // -------------------------------------------------------------------------
    // Test 7: Series new Episode release notification fan-out
    // -------------------------------------------------------------------------
    public function testSeriesNewEpisodeNotificationFanOut(): void
    {
        $user1 = $this->authenticateUser(51, 'user', 'sub_user1');
        $user2 = $this->authenticateUser(52, 'user', 'sub_user2');
        $user3 = $this->authenticateUser(53, 'user', 'non_sub_user');

        $series = $this->createSeries('Breaking Bad', 'breaking-bad');
        $season = $this->createSeason((int)$series->id, 1);

        // Users 1 and 2 follow the series
        MultimediaSubscriptionService::follow($user1, 'series', (int)$series->id);
        MultimediaSubscriptionService::follow($user2, 'series', (int)$series->id);

        // Episode is created and published
        $episode = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Pilot');

        $delivered = MultimediaNotificationService::onEpisodePublished((int)$episode->id);
        $this->assertSame(2, $delivered);

        // Verify users 1 & 2 have notification; user 3 has none
        $this->assertSame(1, MultimediaNotificationService::getUnreadCount($user1->id));
        $this->assertSame(1, MultimediaNotificationService::getUnreadCount($user2->id));
        $this->assertSame(0, MultimediaNotificationService::getUnreadCount($user3->id));

        $notifs1 = MultimediaNotificationService::getUserNotifications($user1->id);
        $this->assertCount(1, $notifs1);
        $this->assertStringContainsString('Breaking Bad', $notifs1[0]['title']);
        $this->assertStringContainsString('Pilot', $notifs1[0]['message']);
        $this->assertSame('new_episode', $notifs1[0]['type']);
    }

    // -------------------------------------------------------------------------
    // Test 8: Repeat-edit of published episode does not duplicate notifications
    // -------------------------------------------------------------------------
    public function testRepeatEditDoesNotDuplicateEpisodeNotifications(): void
    {
        $user = $this->authenticateUser(60);
        $series = $this->createSeries('Dark', 'dark');
        $season = $this->createSeason((int)$series->id, 1);
        $episode = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Secrets');

        MultimediaSubscriptionService::follow($user, 'series', (int)$series->id);

        // First dispatch
        $delivered1 = MultimediaNotificationService::onEpisodePublished((int)$episode->id);
        $this->assertSame(1, $delivered1);

        // Second dispatch (simulating an admin re-saving the episode)
        $delivered2 = MultimediaNotificationService::onEpisodePublished((int)$episode->id);
        $this->assertSame(0, $delivered2, 'Repeat publish must be ignored due to dedupe_key');

        $this->assertSame(1, MultimediaNotificationService::getUnreadCount($user->id));
    }

    // -------------------------------------------------------------------------
    // Test 9: Artist new Song release notification fan-out
    // -------------------------------------------------------------------------
    public function testArtistNewSongNotificationFanOut(): void
    {
        $user = $this->authenticateUser(70);
        $artist = $this->createArtist('Adele', 'adele');
        $song = $this->createSong('Hello', 'hello', (int)$artist->id);

        MultimediaSubscriptionService::follow($user, 'artist', (int)$artist->id);

        $delivered = MultimediaNotificationService::onSongPublished((int)$song->id);
        $this->assertSame(1, $delivered);

        $notifs = MultimediaNotificationService::getUserNotifications($user->id);
        $this->assertCount(1, $notifs);
        $this->assertSame('new_artist_song', $notifs[0]['type']);
        $this->assertStringContainsString('Adele', $notifs[0]['title']);
        $this->assertStringContainsString('Hello', $notifs[0]['message']);
    }

    // -------------------------------------------------------------------------
    // Test 10: Multi-artist recipient deduplication
    // -------------------------------------------------------------------------
    public function testMultiArtistRecipientDeduplication(): void
    {
        $user = $this->authenticateUser(80);
        $artist1 = $this->createArtist('Daft Punk', 'daft-punk');
        $artist2 = $this->createArtist('The Weeknd', 'the-weeknd');

        // User follows both artists
        MultimediaSubscriptionService::follow($user, 'artist', (int)$artist1->id);
        MultimediaSubscriptionService::follow($user, 'artist', (int)$artist2->id);

        $song = $this->createSong('Starboy', 'starboy', (int)$artist1->id);

        // Notify for both artists (collaboration)
        $delivered = MultimediaNotificationService::notifyArtistFollowersNewSong(
            (int)$song->id,
            'Starboy',
            [(int)$artist1->id, (int)$artist2->id]
        );

        // User must receive exactly ONE notification
        $this->assertSame(1, $delivered);
        $this->assertSame(1, MultimediaNotificationService::getUnreadCount($user->id));
    }

    // -------------------------------------------------------------------------
    // Test 11: Playlist update notification fan-out
    // -------------------------------------------------------------------------
    public function testPlaylistUpdateNotificationFanOut(): void
    {
        $creator = $this->authenticateUser(91, 'user', 'creator');
        $follower = $this->authenticateUser(92, 'user', 'follower');

        $playlist = $this->createPlaylist('Synthwave Chill', 'synthwave-chill', (int)$creator->id);

        MultimediaSubscriptionService::follow($follower, 'playlist', (int)$playlist->id);

        $delivered = MultimediaNotificationService::onPlaylistUpdated((int)$playlist->id);
        $this->assertSame(1, $delivered);

        $notifs = MultimediaNotificationService::getUserNotifications($follower->id);
        $this->assertCount(1, $notifs);
        $this->assertSame('playlist_updated', $notifs[0]['type']);
        $this->assertStringContainsString('Synthwave Chill', $notifs[0]['title']);
    }

    // -------------------------------------------------------------------------
    // Test 12: Comment reply notification to parent comment author
    // -------------------------------------------------------------------------
    public function testCommentReplyNotificationToParentAuthor(): void
    {
        $parentAuthor = $this->authenticateUser(101, 'user', 'parent_author');
        $replier = $this->authenticateUser(102, 'user', 'replier', 'Bob Marley');

        $movie = $this->db->insert('multimedia_movies', [
            'title'       => 'Interstellar',
            'slug'        => 'interstellar',
            'access_mode' => 'public',
            'status'      => 'published',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $parentCommentId = (int)$this->db->insert('multimedia_comments', [
            'user_id'      => $parentAuthor->id,
            'content_type' => 'movie',
            'content_id'   => $movie,
            'body'         => 'Incredible ending!',
            'status'       => 'approved',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $replyCommentId = (int)$this->db->insert('multimedia_comments', [
            'user_id'      => $replier->id,
            'content_type' => 'movie',
            'content_id'   => $movie,
            'parent_id'    => $parentCommentId,
            'body'         => 'I totally agree with your view!',
            'status'       => 'approved',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $notified = MultimediaNotificationService::notifyCommentReply(
            $parentCommentId,
            $replyCommentId,
            (int)$replier->id,
            'I totally agree with your view!'
        );

        $this->assertTrue($notified);
        $this->assertSame(1, MultimediaNotificationService::getUnreadCount($parentAuthor->id));

        $notifs = MultimediaNotificationService::getUserNotifications($parentAuthor->id);
        $this->assertSame('comment_reply', $notifs[0]['type']);
        $this->assertStringContainsString('Bob Marley', $notifs[0]['title']);
    }

    // -------------------------------------------------------------------------
    // Test 13: Self-reply suppression (no notification when replying to self)
    // -------------------------------------------------------------------------
    public function testSelfReplySuppressionGeneratesNoNotification(): void
    {
        $user = $this->authenticateUser(110);

        $parentCommentId = (int)$this->db->insert('multimedia_comments', [
            'user_id'      => $user->id,
            'content_type' => 'movie',
            'content_id'   => 1,
            'body'         => 'My original comment',
            'status'       => 'approved',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $replyCommentId = (int)$this->db->insert('multimedia_comments', [
            'user_id'      => $user->id,
            'content_type' => 'movie',
            'content_id'   => 1,
            'parent_id'    => $parentCommentId,
            'body'         => 'Adding more thoughts to my own comment',
            'status'       => 'approved',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $notified = MultimediaNotificationService::notifyCommentReply(
            $parentCommentId,
            $replyCommentId,
            (int)$user->id,
            'Adding more thoughts'
        );

        $this->assertFalse($notified, 'Self-reply must be suppressed');
        $this->assertSame(0, MultimediaNotificationService::getUnreadCount($user->id));
    }

    // -------------------------------------------------------------------------
    // Test 14: Review moderation outcome notification (approved & rejected)
    // -------------------------------------------------------------------------
    public function testReviewModerationNotificationApprovedAndRejected(): void
    {
        $author = $this->authenticateUser(120);

        $revApprovedId = (int)$this->db->insert('multimedia_reviews', [
            'user_id'      => $author->id,
            'content_type' => 'movie',
            'content_id'   => 1,
            'title'        => 'Masterpiece',
            'body'         => 'Great cinematic experience.',
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $revRejectedId = (int)$this->db->insert('multimedia_reviews', [
            'user_id'      => $author->id,
            'content_type' => 'movie',
            'content_id'   => 2,
            'title'        => 'Spam Title',
            'body'         => 'Spam content text.',
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        // 1. Approve review
        $admin = $this->authenticateUser(999, 'admin', 'admin_mod');
        $resApprove = MultimediaEngagementService::moderateReview($admin, $revApprovedId, 'approved');
        $this->assertTrue($resApprove['success']);

        // 2. Reject review
        $resReject = MultimediaEngagementService::moderateReview($admin, $revRejectedId, 'rejected');
        $this->assertTrue($resReject['success']);

        $notifs = MultimediaNotificationService::getUserNotifications($author->id);
        $this->assertCount(2, $notifs);

        $types = array_column($notifs, 'type');
        $this->assertContains('review_approved', $types);
        $this->assertContains('review_rejected', $types);
    }

    // -------------------------------------------------------------------------
    // Test 15: Comment moderation outcome notification (approved & rejected)
    // -------------------------------------------------------------------------
    public function testCommentModerationNotificationApprovedAndRejected(): void
    {
        $author = $this->authenticateUser(130);

        $comApprovedId = (int)$this->db->insert('multimedia_comments', [
            'user_id'      => $author->id,
            'content_type' => 'movie',
            'content_id'   => 1,
            'body'         => 'A genuine good comment.',
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $comRejectedId = (int)$this->db->insert('multimedia_comments', [
            'user_id'      => $author->id,
            'content_type' => 'movie',
            'content_id'   => 2,
            'body'         => 'Spam comment.',
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $admin = $this->authenticateUser(999, 'admin', 'admin_mod');
        MultimediaEngagementService::moderateComment($admin, $comApprovedId, 'approved');
        MultimediaEngagementService::moderateComment($admin, $comRejectedId, 'rejected');

        $notifs = MultimediaNotificationService::getUserNotifications($author->id);
        $this->assertCount(2, $notifs);

        $types = array_column($notifs, 'type');
        $this->assertContains('comment_approved', $types);
        $this->assertContains('comment_rejected', $types);
    }

    // -------------------------------------------------------------------------
    // Test 16: Preference enforcement: notify_content_updates = 0 blocks alerts
    // -------------------------------------------------------------------------
    public function testContentUpdatesPreferenceBlocksReleaseAlerts(): void
    {
        $user = $this->authenticateUser(140);
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id);

        MultimediaSubscriptionService::follow($user, 'series', (int)$series->id);

        // Turn OFF content updates
        MultimediaNotificationPreference::savePreferences($user->id, [
            'notify_content_updates' => 0,
        ]);

        $episode = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Opted Out Ep');
        $delivered = MultimediaNotificationService::onEpisodePublished((int)$episode->id);

        $this->assertSame(0, $delivered);
        $this->assertSame(0, MultimediaNotificationService::getUnreadCount($user->id));
    }

    // -------------------------------------------------------------------------
    // Test 17: Preference enforcement: notify_engagement_replies = 0 blocks alerts
    // -------------------------------------------------------------------------
    public function testEngagementRepliesPreferenceBlocksReplyAlerts(): void
    {
        $parentAuthor = $this->authenticateUser(150);
        $replier = $this->authenticateUser(151);

        // Turn OFF engagement replies
        MultimediaNotificationPreference::savePreferences($parentAuthor->id, [
            'notify_engagement_replies' => 0,
        ]);

        $parentCommentId = (int)$this->db->insert('multimedia_comments', [
            'user_id'      => $parentAuthor->id,
            'content_type' => 'movie',
            'content_id'   => 1,
            'body'         => 'Parent comment',
            'status'       => 'approved',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $replyCommentId = (int)$this->db->insert('multimedia_comments', [
            'user_id'      => $replier->id,
            'content_type' => 'movie',
            'content_id'   => 1,
            'parent_id'    => $parentCommentId,
            'body'         => 'Some reply snippet',
            'status'       => 'approved',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $notified = MultimediaNotificationService::notifyCommentReply(
            $parentCommentId,
            $replyCommentId,
            (int)$replier->id,
            'Some reply snippet'
        );

        $this->assertFalse($notified);
        $this->assertSame(0, MultimediaNotificationService::getUnreadCount($parentAuthor->id));
    }

    // -------------------------------------------------------------------------
    // Test 18: Preference enforcement: notify_moderation_updates = 0 blocks alerts
    // -------------------------------------------------------------------------
    public function testModerationPreferenceBlocksModerationAlerts(): void
    {
        $author = $this->authenticateUser(160);

        // Turn OFF moderation updates
        MultimediaNotificationPreference::savePreferences($author->id, [
            'notify_moderation_updates' => 0,
        ]);

        $revId = (int)$this->db->insert('multimedia_reviews', [
            'user_id'      => $author->id,
            'content_type' => 'movie',
            'content_id'   => 1,
            'title'        => 'Great',
            'body'         => 'Nice movie',
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $admin = $this->authenticateUser(999, 'admin', 'admin_mod');
        MultimediaEngagementService::moderateReview($admin, $revId, 'approved');

        $this->assertSame(0, MultimediaNotificationService::getUnreadCount($author->id));
    }

    // -------------------------------------------------------------------------
    // Test 19: Unread count accuracy and badge calculation
    // -------------------------------------------------------------------------
    public function testUnreadCountAccuracyAndBadgeCalculation(): void
    {
        $user = $this->authenticateUser(170);

        // Insert 3 unread notifications
        for ($i = 1; $i <= 3; $i++) {
            MultimediaNotification::createSafe(
                $user->id,
                MultimediaNotification::TYPE_NEW_EPISODE,
                'episode',
                $i,
                null,
                "badge_test_{$i}_u_{$user->id}",
                "Episode {$i}",
                "Message {$i}"
            );
        }

        $this->assertSame(3, MultimediaNotificationService::getUnreadCount($user->id));

        // Mark 1 as read
        $notifs = MultimediaNotificationService::getUserNotifications($user->id);
        MultimediaNotificationService::markAsRead($user->id, (int)$notifs[0]['id']);

        $this->assertSame(2, MultimediaNotificationService::getUnreadCount($user->id));
    }

    // -------------------------------------------------------------------------
    // Test 20: Mark single notification read with user isolation
    // -------------------------------------------------------------------------
    public function testMarkSingleNotificationReadWithUserIsolation(): void
    {
        $userA = $this->authenticateUser(181, 'user', 'mark_user_a');
        $userB = $this->authenticateUser(182, 'user', 'mark_user_b');

        $notifA = MultimediaNotification::createSafe(
            $userA->id,
            MultimediaNotification::TYPE_NEW_EPISODE,
            'episode',
            10,
            null,
            "mark_isolation_test_u_{$userA->id}",
            'Episode 10',
            'Message'
        );
        $this->assertNotNull($notifA);

        // User B attempts to mark User A's notification as read
        $resB = MultimediaNotificationService::markAsRead($userB->id, (int)$notifA->id);
        $this->assertFalse($resB, 'User B cannot mark User A notification as read');

        // Check it is still unread for User A
        $this->assertSame(1, MultimediaNotificationService::getUnreadCount($userA->id));

        // User A marks their own notification as read
        $resA = MultimediaNotificationService::markAsRead($userA->id, (int)$notifA->id);
        $this->assertTrue($resA);
        $this->assertSame(0, MultimediaNotificationService::getUnreadCount($userA->id));
    }

    // -------------------------------------------------------------------------
    // Test 21: Mark all notifications read
    // -------------------------------------------------------------------------
    public function testMarkAllNotificationsRead(): void
    {
        $user = $this->authenticateUser(190);

        for ($i = 1; $i <= 5; $i++) {
            MultimediaNotification::createSafe(
                $user->id,
                MultimediaNotification::TYPE_NEW_ARTIST_SONG,
                'song',
                $i,
                null,
                "mark_all_test_{$i}_u_{$user->id}",
                "Song {$i}",
                "Message {$i}"
            );
        }

        $this->assertSame(5, MultimediaNotificationService::getUnreadCount($user->id));

        $count = MultimediaNotificationService::markAllAsRead($user->id);
        $this->assertSame(5, $count);
        $this->assertSame(0, MultimediaNotificationService::getUnreadCount($user->id));
    }

    // -------------------------------------------------------------------------
    // Test 22: Deleted target safety (notification list handles deleted content)
    // -------------------------------------------------------------------------
    public function testDeletedTargetSafety(): void
    {
        $user = $this->authenticateUser(200);

        // Notification pointing to non-existent episode ID 999999
        MultimediaNotification::createSafe(
            $user->id,
            MultimediaNotification::TYPE_NEW_EPISODE,
            'episode',
            999999,
            null,
            "deleted_target_test_u_{$user->id}",
            'Deleted Ep',
            'Some message'
        );

        // Should return formatted item without fatal error
        $notifs = MultimediaNotificationService::getUserNotifications($user->id);
        $this->assertCount(1, $notifs);
        $this->assertSame('#', $notifs[0]['target_url']);
        $this->assertFalse($notifs[0]['is_available']);
    }

    // -------------------------------------------------------------------------
    // Test 23: Premium access remains strictly enforced (no entitlement bypass)
    // -------------------------------------------------------------------------
    public function testPremiumAccessRemainsStrictlyEnforced(): void
    {
        $user = $this->authenticateUser(210);

        // Premium movie
        $id = $this->db->insert('multimedia_movies', [
            'title'       => 'VIP Exclusive Movie',
            'slug'        => 'vip-exclusive',
            'access_mode' => 'premium',
            'status'      => 'published',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $movie = Movie::find((int)$id);

        // Verify standard user CANNOT access premium movie
        $this->assertFalse(MultimediaAccessService::canAccess($user, $movie));
    }

    // -------------------------------------------------------------------------
    // Test 24: Zero media stream URLs leaked in notification payloads
    // -------------------------------------------------------------------------
    public function testZeroMediaStreamUrlsLeakedInNotificationPayloads(): void
    {
        $user = $this->authenticateUser(220);

        MultimediaNotification::createSafe(
            $user->id,
            MultimediaNotification::TYPE_NEW_EPISODE,
            'episode',
            1,
            null,
            "stream_leak_test_u_{$user->id}",
            'New Episode Title',
            'New Episode Message'
        );

        $row = $this->pdo->query("SELECT * FROM multimedia_notifications WHERE user_id = {$user->id}")->fetch(PDO::FETCH_ASSOC);

        $serialized = json_encode($row);
        $this->assertStringNotContainsString('.mp4', $serialized);
        $this->assertStringNotContainsString('.m3u8', $serialized);
        $this->assertStringNotContainsString('.mp3', $serialized);
        $this->assertStringNotContainsString('stream_url', $serialized);
    }

    // -------------------------------------------------------------------------
    // Test 25: XSS escaping in notification messages and titles
    // -------------------------------------------------------------------------
    public function testXssEscapingInNotificationMessagesAndTitles(): void
    {
        $user = $this->authenticateUser(230);
        $xssTitle = 'Malicious <script>alert("pwned")</script> Title';
        $xssMessage = 'Hello <img src=x onerror=alert(1)> World';

        MultimediaNotification::createSafe(
            $user->id,
            MultimediaNotification::TYPE_NEW_EPISODE,
            'episode',
            1,
            null,
            "xss_test_u_{$user->id}",
            $xssTitle,
            $xssMessage
        );

        $notifs = MultimediaNotificationService::getUserNotifications($user->id);
        $this->assertCount(1, $notifs);
        $this->assertStringNotContainsString('<script>', $notifs[0]['title']);
        $this->assertStringNotContainsString('<img', $notifs[0]['message']);
        $this->assertStringContainsString('&lt;script&gt;', $notifs[0]['title']);
        $this->assertStringContainsString('&lt;img', $notifs[0]['message']);
    }

    // -------------------------------------------------------------------------
    // Test 26: SQL injection resistance in subscription and notification queries
    // -------------------------------------------------------------------------
    public function testSqlInjectionResistance(): void
    {
        $user = $this->authenticateUser(240);

        // Attempt SQL injection in target_type
        $maliciousType = "series' OR 1=1; --";
        $res = MultimediaSubscriptionService::follow($user, $maliciousType, 1);
        $this->assertFalse($res['success']);

        // Attempt SQL injection in dedupe_key
        $maliciousKey = "key' UNION SELECT * FROM users; --";
        $notif = MultimediaNotification::createSafe(
            $user->id,
            MultimediaNotification::TYPE_NEW_EPISODE,
            'episode',
            1,
            null,
            $maliciousKey,
            'Safe Title',
            'Safe Message'
        );
        $this->assertNotNull($notif);

        // Count should remain 1
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM multimedia_notifications WHERE user_id = {$user->id}")->fetchColumn();
        $this->assertSame(1, $count);
    }

    // -------------------------------------------------------------------------
    // Test 27: CSRF validation on all state mutations in MediaPlaybackController
    // -------------------------------------------------------------------------
    public function testCsrfValidationOnStateMutations(): void
    {
        $user = $this->authenticateUser(250);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid_token_123';
        $_SESSION['csrf_token'] = 'valid_token_xyz';

        $controller = new MediaPlaybackController($this->app);

        // 1. Toggle subscribe without valid CSRF
        $req = new Request();
        $res = $controller->apiToggleSubscribe($req);
        $this->assertSame(403, $res->getStatusCode());

        // 2. Mark read without valid CSRF
        $res2 = $controller->apiMarkNotificationRead($req, '1');
        $this->assertSame(403, $res2->getStatusCode());

        // 3. Mark all read without valid CSRF
        $res3 = $controller->apiMarkAllNotificationsRead($req);
        $this->assertSame(403, $res3->getStatusCode());

        // 4. Save preferences without valid CSRF
        $res4 = $controller->apiSaveNotificationPreferences($req);
        $this->assertSame(403, $res4->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Test 28: Bangla and Unicode UTF-8 support in notifications
    // -------------------------------------------------------------------------
    public function testBanglaAndUnicodeSupportInNotifications(): void
    {
        $user = $this->authenticateUser(260);
        $banglaTitle = 'নতুন পর্ব প্রকাশিত হয়েছে';
        $banglaMessage = 'জনপ্রিয় সিরিজ "পথের পাঁচালী" এর নতুন পর্ব এখন দেখা যাচ্ছে।';

        MultimediaNotification::createSafe(
            $user->id,
            MultimediaNotification::TYPE_NEW_EPISODE,
            'episode',
            1,
            null,
            "bangla_test_u_{$user->id}",
            $banglaTitle,
            $banglaMessage
        );

        $notifs = MultimediaNotificationService::getUserNotifications($user->id);
        $this->assertCount(1, $notifs);
        $this->assertStringContainsString('নতুন পর্ব প্রকাশিত হয়েছে', $notifs[0]['title']);
        $this->assertStringContainsString('পথের পাঁচালী', $notifs[0]['message']);
    }

    // -------------------------------------------------------------------------
    // Test 29: Guest restrictions (unauthenticated users cannot follow)
    // -------------------------------------------------------------------------
    public function testGuestRestrictions(): void
    {
        $_SESSION = []; // Clear session -> guest

        $resFollow = MultimediaSubscriptionService::follow(null, 'series', 1);
        $this->assertFalse($resFollow['success']);
        $this->assertSame('unauthenticated', $resFollow['status']);

        $resUnfollow = MultimediaSubscriptionService::unfollow(null, 'series', 1);
        $this->assertFalse($resUnfollow['success']);
        $this->assertSame('unauthenticated', $resUnfollow['status']);

        $this->assertSame(0, MultimediaNotificationService::getUnreadCount(0));
    }

    // -------------------------------------------------------------------------
    // Test 30: Cascade deletion removes subscriptions
    // -------------------------------------------------------------------------
    public function testCascadeDeletionRemovesSubscriptions(): void
    {
        $user = $this->authenticateUser(270);
        $series = $this->createSeries('Obsolete Series', 'obsolete-series');

        MultimediaSubscriptionService::follow($user, 'series', (int)$series->id);
        $this->assertSame(1, MultimediaSubscription::countSubscribers('series', (int)$series->id));

        // Cascade cleanup triggered
        $deletedCount = MultimediaSubscription::cleanupForTarget('series', (int)$series->id);
        $this->assertSame(1, $deletedCount);
        $this->assertSame(0, MultimediaSubscription::countSubscribers('series', (int)$series->id));
    }

    // -------------------------------------------------------------------------
    // Test 31: Discovery follow boost integration (+0.5 score affinity)
    // -------------------------------------------------------------------------
    public function testDiscoveryFollowBoostIntegration(): void
    {
        $user = $this->authenticateUser(280);

        // Create two series in the same category
        $series1 = $this->createSeries('Followed Series', 'followed-series');
        $series2 = $this->createSeries('Unfollowed Series', 'unfollowed-series');

        // User follows series 1
        MultimediaSubscriptionService::follow($user, 'series', (int)$series1->id);

        $recommendations = MultimediaDiscoveryService::getRecommendedForUser($user, 10);
        $this->assertNotEmpty($recommendations);

        // Followed series should be returned in recommendations
        $series1Found = false;
        foreach ($recommendations as $item) {
            $contentType = $item['content_type'] ?? $item['type'] ?? '';
            if ($contentType === 'series' && (int)$item['id'] === (int)$series1->id) {
                $series1Found = true;
                break;
            }
        }
        $this->assertTrue($series1Found, 'Followed series should be recommended with affinity boost');
    }
}
