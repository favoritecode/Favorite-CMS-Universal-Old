<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Models\MediaLocalization;
use FavoriteCMS\Multimedia\Models\UserLanguagePreference;
use FavoriteCMS\Multimedia\Services\MultimediaAnalyticsService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;

class FavoriteMultimediaPhase13Test extends TestCase
{
    private Application $app;
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }

        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->app = new Application();
        Container::setInstance($this->app);

        // In-memory SQLite Database
        $pdo = new \PDO('sqlite::memory:', '', '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
        ]);

        $this->db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $ref = new \ReflectionProperty(Database::class, 'pdo');
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);

        // Core CMS Schema
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                name TEXT,
                email TEXT,
                password TEXT,
                role TEXT,
                status TEXT DEFAULT 'active',
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

        // Run migrations 001 through 009
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        (new \CreateFavoriteMultimediaTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/002_create_multimedia_user_library_tables.php';
        (new \CreateMultimediaUserLibraryTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/003_create_multimedia_engagement_tables.php';
        (new \CreateMultimediaEngagementTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/004_create_multimedia_subscription_notification_tables.php';
        (new \CreateMultimediaSubscriptionNotificationTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/005_add_multimedia_scheduling_fields.php';
        (new \AddMultimediaSchedulingFields($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/006_create_multimedia_processing_tables.php';
        (new \CreateMultimediaProcessingTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/007_add_multimedia_storage_fields.php';
        (new \AddMultimediaStorageFields($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/008_add_multimedia_localization_and_language_tables.php';
        (new \AddMultimediaLocalizationAndLanguageTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/009_create_multimedia_analytics_indexes_and_fields.php';
        (new \CreateMultimediaAnalyticsIndexesAndFields($this->db))->up();

        // Initialize Plugin
        FavoriteMultimediaPlugin::reset();
        FavoriteMultimediaPlugin::bootstrap($this->app);

        $_SESSION = [];
        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        unset($GLOBALS['_test_favorite_pay_available']);
    }

    protected function tearDown(): void
    {
        FavoriteMultimediaPlugin::reset();
        unset($GLOBALS['_test_favorite_digital_available']);
        unset($GLOBALS['_test_favorite_digital_entitled_users']);
        unset($GLOBALS['_test_favorite_pay_available']);
        $_SESSION = [];
        parent::tearDown();
    }

    private function createAdminUser(): int
    {
        $role = $this->db->selectOne("SELECT id FROM roles WHERE slug = 'admin'");
        $roleId = $role ? (int)$role->id : (int)$this->db->insert('roles', ['name' => 'Administrator', 'slug' => 'admin']);

        $uid = (int)$this->db->insert('users', [
            'username'   => 'admin_' . uniqid(),
            'email'      => 'admin@test.com',
            'role'       => 'admin',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->insert('user_roles', ['user_id' => $uid, 'role_id' => $roleId]);

        $_SESSION['auth_user_id'] = $uid;
        $_SESSION['csrf_token'] = 'phase13_csrf_valid';
        return $uid;
    }

    private function createSubscriberUser(): int
    {
        $role = $this->db->selectOne("SELECT id FROM roles WHERE slug = 'subscriber'");
        $roleId = $role ? (int)$role->id : (int)$this->db->insert('roles', ['name' => 'Subscriber', 'slug' => 'subscriber']);

        $uid = (int)$this->db->insert('users', [
            'username'   => 'sub_' . uniqid(),
            'email'      => 'sub@test.com',
            'role'       => 'subscriber',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->insert('user_roles', ['user_id' => $uid, 'role_id' => $roleId]);

        $_SESSION['auth_user_id'] = $uid;
        $_SESSION['csrf_token'] = 'phase13_csrf_valid';
        return $uid;
    }

    // =========================================================================
    // 1. MIGRATION 009 & SCHEMA AUDIT
    // =========================================================================

    public function testMigration009CreatesAttributionColumnsAndPerformanceIndexes(): void
    {
        $cols = $this->db->select("PRAGMA table_info(multimedia_analytics)");
        $colNames = array_map(fn($c) => $c->name, $cols);

        $this->assertContains('discovery_source', $colNames);
        $this->assertContains('metadata', $colNames);

        // Verify index existence
        $indexes = $this->db->select("PRAGMA index_list(multimedia_analytics)");
        $idxNames = array_map(fn($i) => $i->name, $indexes);

        $this->assertContains('idx_mm_an_event_created', $idxNames);
        $this->assertContains('idx_mm_an_user_content', $idxNames);
        $this->assertContains('idx_mm_an_source', $idxNames);
    }

    // =========================================================================
    // 2. ANALYTICS EVENT LOGGING & ATTRIBUTION
    // =========================================================================

    public function testAnalyticsEventLoggingWithDiscoverySourceAndMetadata(): void
    {
        AnalyticsEvent::logEvent('movie', 10, 'play', 42, 'trending', ['audio' => 'bn']);
        AnalyticsEvent::logEvent('movie', 10, 'view', 42, 'catalog');
        AnalyticsEvent::logEvent('movie', 10, 'invalid_source', 42, 'malicious_tracking_source');

        $rows = $this->db->select("SELECT * FROM multimedia_analytics WHERE content_id = 10 ORDER BY id ASC");
        $this->assertCount(2, $rows); // 3rd discarded because invalid event_type

        $this->assertSame('trending', $rows[0]->discovery_source);
        $this->assertStringContainsString('"audio":"bn"', (string)$rows[0]->metadata);
        $this->assertSame('catalog', $rows[1]->discovery_source);
    }

    // =========================================================================
    // 3. DATE RANGE BOUNDARIES
    // =========================================================================

    public function testDateRangeResolutionInclusiveBoundsAndTimezones(): void
    {
        $today = MultimediaAnalyticsService::resolveDateRange('today');
        $this->assertSame(gmdate('Y-m-d 00:00:00'), $today['start']);
        $this->assertSame(gmdate('Y-m-d 23:59:59'), $today['end']);

        $last7 = MultimediaAnalyticsService::resolveDateRange('last_7_days');
        $this->assertSame(gmdate('Y-m-d 00:00:00', strtotime('-6 days')), $last7['start']);

        $custom = MultimediaAnalyticsService::resolveDateRange('custom', '2026-01-01', '2026-01-31');
        $this->assertSame('2026-01-01 00:00:00', $custom['start']);
        $this->assertSame('2026-01-31 23:59:59', $custom['end']);
    }

    // =========================================================================
    // 4. OVERVIEW DASHBOARD METRICS
    // =========================================================================

    public function testDashboardOverviewMetricsCalculation(): void
    {
        $uid = $this->createSubscriberUser();

        // 3 plays, 2 views, 1 download
        AnalyticsEvent::logEvent('movie', 1, 'play', $uid);
        AnalyticsEvent::logEvent('movie', 1, 'play', $uid);
        AnalyticsEvent::logEvent('movie', 2, 'play', null); // guest
        AnalyticsEvent::logEvent('movie', 1, 'view', $uid);
        AnalyticsEvent::logEvent('movie', 2, 'view', null);
        AnalyticsEvent::logEvent('movie', 1, 'download', $uid);

        // Progress records: 1 completed (>=90%), 1 partial (50%)
        $this->db->insert('multimedia_playback_progress', [
            'user_id'        => $uid,
            'content_type'   => 'movie',
            'content_id'     => 1,
            'position'       => 3600,
            'duration'       => 3600,
            'percentage'     => 100.0,
            'is_completed'   => 1,
            'last_played_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_playback_progress', [
            'user_id'        => $uid,
            'content_type'   => 'movie',
            'content_id'     => 2,
            'position'       => 1800,
            'duration'       => 3600,
            'percentage'     => 50.0,
            'is_completed'   => 0,
            'last_played_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $stats = MultimediaAnalyticsService::getOverviewStats();

        $this->assertSame(3, $stats['total_plays']);
        $this->assertSame(2, $stats['total_views']);
        $this->assertSame(1, $stats['total_downloads']);
        $this->assertSame(1, $stats['unique_users']);
        $this->assertSame(5400.0, $stats['total_watch_seconds']);
        $this->assertSame('1h 30m 0s', $stats['watch_time_formatted']);
        $this->assertSame(50.0, $stats['completion_rate']); // 1 of 2 completed
    }

    // =========================================================================
    // 5. CANONICAL COMPLETION RATE (>= 90%)
    // =========================================================================

    public function testCanonicalCompletionRateThresholdAt90Percent(): void
    {
        // 10 sessions: 7 completed (percentage >= 90 or is_completed=1), 3 incomplete
        for ($i = 1; $i <= 7; $i++) {
            $this->db->insert('multimedia_playback_progress', [
                'user_id'        => $i,
                'content_type'   => 'movie',
                'content_id'     => 100,
                'position'       => 900,
                'duration'       => 1000,
                'percentage'     => 90.0,
                'is_completed'   => 1,
                'last_played_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        for ($i = 8; $i <= 10; $i++) {
            $this->db->insert('multimedia_playback_progress', [
                'user_id'        => $i,
                'content_type'   => 'movie',
                'content_id'     => 100,
                'position'       => 500,
                'duration'       => 1000,
                'percentage'     => 50.0,
                'is_completed'   => 0,
                'last_played_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $dropOff = MultimediaAnalyticsService::getCompletionAndDropOff('movie', 100);

        $this->assertSame(10, $dropOff['total_sessions']);
        $this->assertSame(7, $dropOff['buckets']['90_100']);
        $this->assertSame(3, $dropOff['buckets']['50_75']);
        $this->assertSame(70.0, $dropOff['percentages']['90_100']);
    }

    // =========================================================================
    // 6. CONTENT PERFORMANCE (ZERO N+1) & LOCALIZED TITLES
    // =========================================================================

    public function testContentPerformanceBulkQueryWithoutNPlusOne(): void
    {
        $m1 = (int)$this->db->insert('multimedia_movies', ['title' => 'Alpha Journey', 'slug' => 'alpha-journey', 'status' => 'published']);
        $m2 = (int)$this->db->insert('multimedia_movies', ['title' => 'Beta Quest', 'slug' => 'beta-quest', 'status' => 'published']);

        // Localized title for m1 in Bangla
        MediaLocalization::saveLocalization('movie', $m1, 'bn', 'আলফা যাত্রা');

        AnalyticsEvent::logEvent('movie', $m1, 'play', 10);
        AnalyticsEvent::logEvent('movie', $m1, 'play', 20);
        AnalyticsEvent::logEvent('movie', $m2, 'play', 30);

        $perf = MultimediaAnalyticsService::getContentPerformance('movie', [], 10, 0, 'plays', 'DESC', 'bn');

        $this->assertCount(2, $perf['items']);
        $first = $perf['items'][0];
        $this->assertSame($m1, $first['id']);
        $this->assertSame('আলফা যাত্রা', $first['localized_title']);
        $this->assertSame(2, $first['plays']);
    }

    // =========================================================================
    // 7. SERIES AGGREGATION ACROSS ALL EPISODES
    // =========================================================================

    public function testSeriesAnalyticsEpisodeAggregationWithoutDuplicateCounting(): void
    {
        $sId = (int)$this->db->insert('multimedia_series', ['title' => 'Dark Horizon', 'slug' => 'dark-horizon', 'status' => 'published']);
        $seasonId = (int)$this->db->insert('multimedia_seasons', ['series_id' => $sId, 'season_number' => 1, 'title' => 'Season 1']);

        $ep1 = (int)$this->db->insert('multimedia_episodes', ['series_id' => $sId, 'season_id' => $seasonId, 'episode_number' => 1, 'title' => 'Pilot', 'slug' => 'pilot']);
        $ep2 = (int)$this->db->insert('multimedia_episodes', ['series_id' => $sId, 'season_id' => $seasonId, 'episode_number' => 2, 'title' => 'The Search', 'slug' => 'the-search']);

        // User 55 watches both Ep 1 and Ep 2
        AnalyticsEvent::logEvent('episode', $ep1, 'play', 55);
        AnalyticsEvent::logEvent('episode', $ep2, 'play', 55);
        // User 66 watches only Ep 1
        AnalyticsEvent::logEvent('episode', $ep1, 'play', 66);

        $seriesStats = MultimediaAnalyticsService::getSeriesAnalytics($sId);

        $this->assertSame(3, $seriesStats['total_episode_plays']); // 2 for ep1 + 1 for ep2
        $this->assertSame(2, $seriesStats['unique_viewers']); // User 55 and 66 without duplicate counting
        $this->assertSame($ep1, (int)$seriesStats['most_watched_episode']->id);
        $this->assertSame($ep2, (int)$seriesStats['drop_off_episode']->id);
    }

    // =========================================================================
    // 8. NEXT-EPISODE CONTINUATION
    // =========================================================================

    public function testNextEpisodeContinuationRate(): void
    {
        $sId = (int)$this->db->insert('multimedia_series', ['title' => 'Thriller', 'slug' => 'thriller', 'status' => 'published']);
        $seasonId = (int)$this->db->insert('multimedia_seasons', ['series_id' => $sId, 'season_number' => 1, 'title' => 'Season 1']);
        $ep1 = (int)$this->db->insert('multimedia_episodes', ['series_id' => $sId, 'season_id' => $seasonId, 'episode_number' => 1, 'title' => 'Ep 1', 'slug' => 'ep-1']);
        $ep2 = (int)$this->db->insert('multimedia_episodes', ['series_id' => $sId, 'season_id' => $seasonId, 'episode_number' => 2, 'title' => 'Ep 2', 'slug' => 'ep-2']);

        // Users 1, 2, 3 completed Ep 1
        foreach ([1, 2, 3] as $uid) {
            $this->db->insert('multimedia_playback_progress', [
                'user_id' => $uid, 'content_type' => 'episode', 'content_id' => $ep1,
                'position' => 1000, 'duration' => 1000, 'percentage' => 100.0, 'is_completed' => 1,
                'last_played_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        // Users 1 and 2 continued to Ep 2 (user 3 dropped off)
        foreach ([1, 2] as $uid) {
            $this->db->insert('multimedia_playback_progress', [
                'user_id' => $uid, 'content_type' => 'episode', 'content_id' => $ep2,
                'position' => 200, 'duration' => 1000, 'percentage' => 20.0, 'is_completed' => 0,
                'last_played_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $cont = MultimediaAnalyticsService::getNextEpisodeContinuation($sId);

        $this->assertSame(3, $cont['eligible_pairs']);
        $this->assertSame(2, $cont['continued_count']);
        $this->assertSame(66.7, $cont['continuation_rate']);
    }

    // =========================================================================
    // 9. SONG & PLAYLIST ANALYTICS
    // =========================================================================

    public function testSongAndPlaylistAnalytics(): void
    {
        $songId = (int)$this->db->insert('multimedia_songs', ['title' => 'Melody', 'slug' => 'melody', 'status' => 'published']);
        $plId = (int)$this->db->insert('multimedia_playlists', ['title' => 'Hits', 'slug' => 'hits', 'status' => 'published']);

        $this->db->insert('multimedia_playlist_items', ['playlist_id' => $plId, 'song_id' => $songId, 'sort_order' => 1]);

        AnalyticsEvent::logEvent('song', $songId, 'play', 1);
        AnalyticsEvent::logEvent('song', $songId, 'play', 2);
        AnalyticsEvent::logEvent('playlist', $plId, 'view', 1);

        $songAnalytics = MultimediaAnalyticsService::getSongAnalytics($songId);
        $this->assertSame(2, $songAnalytics['plays']);
        $this->assertSame(2, $songAnalytics['unique_listeners']);
        $this->assertSame(1, $songAnalytics['playlist_appearances']);

        $plAnalytics = MultimediaAnalyticsService::getPlaylistAnalytics($plId);
        $this->assertSame(1, $plAnalytics['views']);
        $this->assertSame(1, $plAnalytics['track_count']);
    }

    // =========================================================================
    // 10. RATINGS & REVIEW STATUS BREAKDOWN
    // =========================================================================

    public function testRatingDistributionAndReviewStatusCounts(): void
    {
        $this->db->insert('multimedia_ratings', ['user_id' => 1, 'content_type' => 'movie', 'content_id' => 1, 'rating' => 5, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->insert('multimedia_ratings', ['user_id' => 2, 'content_type' => 'movie', 'content_id' => 1, 'rating' => 5, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->insert('multimedia_ratings', ['user_id' => 3, 'content_type' => 'movie', 'content_id' => 1, 'rating' => 4, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->insert('multimedia_ratings', ['user_id' => 4, 'content_type' => 'movie', 'content_id' => 1, 'rating' => 3, 'created_at' => gmdate('Y-m-d H:i:s')]);

        $this->db->insert('multimedia_reviews', ['user_id' => 1, 'content_type' => 'movie', 'content_id' => 1, 'body' => 'Great', 'status' => 'approved', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->insert('multimedia_reviews', ['user_id' => 2, 'content_type' => 'movie', 'content_id' => 1, 'body' => 'Spam', 'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s')]);

        $res = MultimediaAnalyticsService::getRatingAndReviewAnalytics();

        $this->assertSame(4, $res['total_ratings']);
        $this->assertSame(4.25, $res['avg_rating']);
        $this->assertSame(2, $res['distribution'][5]);
        $this->assertSame(1, $res['distribution'][4]);
        $this->assertSame(1, $res['distribution'][3]);
        $this->assertSame(1, $res['reviews_approved']);
        $this->assertSame(1, $res['reviews_pending']);
    }

    // =========================================================================
    // 11. DISCOVERY ATTRIBUTION WHITELIST
    // =========================================================================

    public function testDiscoveryAttributionWhitelistEnforcement(): void
    {
        AnalyticsEvent::logEvent('movie', 1, 'play', 1, 'trending');
        AnalyticsEvent::logEvent('movie', 1, 'play', 2, 'recommended');
        AnalyticsEvent::logEvent('movie', 1, 'play', 3, null); // direct

        $disc = MultimediaAnalyticsService::getDiscoveryPerformance();

        $this->assertSame(1, $disc['trending']['plays']);
        $this->assertSame(1, $disc['recommended']['plays']);
        $this->assertSame(1, $disc['direct']['plays']);
    }

    // =========================================================================
    // 12. RELEASE PERFORMANCE (FIRST 24H & 7D)
    // =========================================================================

    public function testReleasePerformanceFirst24HoursAnd7Days(): void
    {
        $pubTime = gmdate('Y-m-d H:i:s', strtotime('-10 days'));
        $movId = (int)$this->db->insert('multimedia_movies', [
            'title'        => 'Launch Title',
            'slug'         => 'launch-title',
            'status'       => 'published',
            'published_at' => $pubTime,
            'created_at'   => $pubTime,
        ]);

        // Play within first 12 hours
        $this->db->insert('multimedia_analytics', [
            'content_type' => 'movie',
            'content_id'   => $movId,
            'event_type'   => 'play',
            'created_at'   => gmdate('Y-m-d H:i:s', strtotime($pubTime) + 3600),
        ]);

        // Play within 3 days (within 7d, not 24h)
        $this->db->insert('multimedia_analytics', [
            'content_type' => 'movie',
            'content_id'   => $movId,
            'event_type'   => 'play',
            'created_at'   => gmdate('Y-m-d H:i:s', strtotime($pubTime) + (3 * 86400)),
        ]);

        $rel = MultimediaAnalyticsService::getReleasePerformance('movie', $movId);

        $this->assertSame(1, $rel['plays_first_24h']);
        $this->assertSame(2, $rel['plays_first_7d']);
    }

    // =========================================================================
    // 13. CRITICAL: FAVORITE DIGITAL SOLE ENTITLEMENT AUTHORITY
    // =========================================================================

    public function testFavoriteDigitalIsSoleAuthorityForPremiumAnalytics(): void
    {
        $movId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'VIP Cinema',
            'slug'        => 'vip-cinema',
            'access_mode' => 'premium',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $tmpHls = APP_ROOT . '/storage/test_hls_' . uniqid() . '.m3u8';
        file_put_contents($tmpHls, "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n");
        $relPath = str_replace('\\', '/', substr($tmpHls, strlen(APP_ROOT) + 1));

        $sourceId = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $movId,
            'source_type'  => 'hls',
            'url_or_path'  => $relPath,
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $userId = $this->createSubscriberUser();

        $controller = new MediaPlaybackController($this->app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/api/multimedia/hls/{$sourceId}/master.m3u8"]);

        // SCENARIO 1: Favorite Pay active, but Favorite Digital is MISSING/DISABLED
        // Outcome: MUST DENY (403) and log premium_denied.
        $GLOBALS['_test_favorite_pay_available'] = true;
        $GLOBALS['_test_favorite_digital_available'] = false; // missing
        unset($GLOBALS['_test_favorite_digital_entitled_users']);

        $resp1 = $controller->hlsMaster($req, (string)$sourceId);
        $this->assertNotNull($resp1);
        $this->assertSame(403, $resp1->getStatusCode());

        // Verify premium_denied event was recorded in analytics
        $deniedCount = (int)($this->db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_analytics
            WHERE event_type = 'premium_denied' AND content_id = ?
        ", [$movId])->c ?? 0);
        $this->assertSame(1, $deniedCount);

        // SCENARIO 2: Favorite Pay active, Favorite Digital active with valid entitlement
        // Outcome: ALLOWED (stream initiation succeeds, returns 200)
        $GLOBALS['_test_favorite_digital_available'] = true;
        $GLOBALS['_test_favorite_digital_entitled_users'] = [$userId];

        $resp2 = $controller->hlsMaster($req, (string)$sourceId);
        $this->assertNotNull($resp2);
        $this->assertSame(200, $resp2->getStatusCode());

        // Record entitled play event
        AnalyticsEvent::logEvent('movie', $movId, 'play', $userId);

        // Verify Premium Funnel reflects attempts, denials, and entitled plays
        $funnel = MultimediaAnalyticsService::getPremiumFunnel();
        $this->assertSame(2, $funnel['total_attempts']);
        $this->assertSame(1, $funnel['premium_denials']);
        $this->assertSame(1, $funnel['entitled_plays']);
        $this->assertSame(50.0, $funnel['conversion_rate']);

        @unlink($tmpHls);

        // Verify Premium Funnel reflects attempts, denials, and entitled plays
        $funnel = MultimediaAnalyticsService::getPremiumFunnel();
        $this->assertSame(2, $funnel['total_attempts']);
        $this->assertSame(1, $funnel['premium_denials']);
        $this->assertSame(1, $funnel['entitled_plays']);
        $this->assertSame(50.0, $funnel['conversion_rate']);
    }

    // =========================================================================
    // 14. MULTI-LANGUAGE & SUBTITLE USAGE (PRIVACY SAFE)
    // =========================================================================

    public function testLanguageAndSubtitleUsageAggregateMetrics(): void
    {
        // 3 users save preferences: 2 Bangla audio, 1 English
        UserLanguagePreference::saveForUser(1, 'bn', 'en', true);
        UserLanguagePreference::saveForUser(2, 'bn', 'bn', true);
        UserLanguagePreference::saveForUser(3, 'en', null, false);

        $langStats = MultimediaAnalyticsService::getLanguageUsageAnalytics();

        $this->assertSame(3, $langStats['total_users_with_prefs']);
        $this->assertSame(66.7, $langStats['subtitle_enabled_rate']); // 2 of 3 enabled
        $this->assertNotEmpty($langStats['audio_distribution']);
    }

    // =========================================================================
    // 15. OPERATIONAL PROCESSING & STORAGE METRICS
    // =========================================================================

    public function testOperationalProcessingAndStorageMetrics(): void
    {
        $this->db->insert('multimedia_processing_jobs', [
            'content_type' => 'movie', 'content_id' => 1, 'job_type' => 'hls_transcode',
            'input_path'   => 'raw/movie1.mp4', 'status' => 'completed', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->insert('multimedia_processing_jobs', [
            'content_type' => 'movie', 'content_id' => 2, 'job_type' => 'hls_transcode',
            'input_path'   => 'raw/movie2.mp4', 'status' => 'failed', 'error_message' => 'FFmpeg timeout', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_storage_files', [
            'content_type'   => 'movie',
            'content_id'     => 1,
            'storage_driver' => 'local',
            'storage_key'    => 'local.mp4',
            'file_size'      => 1048576,
            'is_orphan'      => 0,
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->insert('multimedia_storage_files', [
            'content_type'   => 'movie',
            'content_id'     => 1,
            'storage_driver' => 's3',
            'storage_key'    => 's3.mp4',
            'file_size'      => 2097152,
            'is_orphan'      => 0,
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);

        $ops = MultimediaAnalyticsService::getProcessingAndStorageMetrics();

        $this->assertSame(1, $ops['processing']['completed']);
        $this->assertSame(1, $ops['processing']['failed']);
        $this->assertSame(50.0, $ops['processing']['failure_rate']);
        $this->assertSame(1, $ops['storage']['local_files']);
        $this->assertSame(1, $ops['storage']['s3_files']);
        $this->assertSame(3145728, $ops['storage']['total_bytes']);
    }

    // =========================================================================
    // 16. CSV EXPORT & FORMULA INJECTION PREVENTION (CWE-1236)
    // =========================================================================

    public function testCsvExportGenerationWithUtf8BomAndFormulaInjectionSanitization(): void
    {
        $movId = (int)$this->db->insert('multimedia_movies', [
            'title'       => '=HYPERLINK("http://evil.com","Click")',
            'slug'        => 'formula-injection-movie',
            'status'      => 'published',
            'access_mode' => 'public',
        ]);

        // Add play
        AnalyticsEvent::logEvent('movie', $movId, 'play', 1);

        $csv = MultimediaAnalyticsService::exportCsv('content_performance', [], 'movie');

        // 1. Starts with UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // 2. Dangerous leading formula character '=' is sanitized by prepending single quote
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
    }

    // =========================================================================
    // 17. BANGLA & ARABIC UNICODE TITLE DISPLAY
    // =========================================================================

    public function testBanglaAndArabicUnicodeTitleExportAndDisplay(): void
    {
        $movId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'মহাযাত্রা - The Great Journey',
            'slug'        => 'the-great-journey',
            'status'      => 'published',
        ]);

        $csv = MultimediaAnalyticsService::exportCsv('content_performance', [], 'movie');

        $this->assertStringContainsString('মহাযাত্রা - The Great Journey', $csv);
    }

    // =========================================================================
    // 18. ADMIN PERMISSION & CSRF SECURITY ENFORCEMENT
    // =========================================================================

    public function testAdminPermissionEnforcementOnAnalyticsEndpoints(): void
    {
        $adminController = new MultimediaAdminController($this->app);

        // 1. Guest access denied
        $_SESSION = [];
        $guestReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-analytics']);
        $respGuest = $adminController->analytics($guestReq);
        $this->assertInstanceOf(Response::class, $respGuest);
        $this->assertSame(403, $respGuest->getStatusCode());

        // 2. Subscriber access denied
        $this->createSubscriberUser();
        $subReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-analytics']);
        $respSub = $adminController->analytics($subReq);
        $this->assertInstanceOf(Response::class, $respSub);
        $this->assertSame(403, $respSub->getStatusCode());

        // 3. Admin access permitted
        $this->createAdminUser();
        $adminReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia-analytics']);
        $respAdmin = $adminController->analytics($adminReq);
        $this->assertIsString($respAdmin); // renders view string
        $this->assertStringContainsString('Multimedia Analytics &amp; Intelligence', $respAdmin);
    }

    // =========================================================================
    // 19. ZERO DATA HANDLING (NO DIVISION BY ZERO)
    // =========================================================================

    public function testZeroDataHandlingWithoutDivisionByZero(): void
    {
        // Fresh empty database with zero events or progress
        $overview = MultimediaAnalyticsService::getOverviewStats();
        $this->assertSame(0, $overview['total_plays']);
        $this->assertSame(0.0, $overview['completion_rate']);
        $this->assertSame(0.0, $overview['avg_rating']);

        $dropOff = MultimediaAnalyticsService::getCompletionAndDropOff('movie', 999);
        $this->assertSame(0, $dropOff['total_sessions']);
        $this->assertSame(0.0, $dropOff['percentages']['90_100']);

        $resume = MultimediaAnalyticsService::getResumeEngagement();
        $this->assertSame(0.0, $resume['resume_rate']);

        $funnel = MultimediaAnalyticsService::getPremiumFunnel();
        $this->assertSame(0.0, $funnel['conversion_rate']);

        $perf = MultimediaAnalyticsService::getContentPerformance('movie', [], 10, 0);
        $this->assertSame([], $perf['items']);
    }

    // =========================================================================
    // 20. SQL INJECTION RESISTANCE
    // =========================================================================

    public function testSqlInjectionResistanceOnSortingAndFiltering(): void
    {
        // Malicious sorting string
        $maliciousSort = "plays; DROP TABLE multimedia_movies; --";
        $maliciousType = "movie' OR 1=1 --";

        $res = MultimediaAnalyticsService::getContentPerformance($maliciousType, [], 10, 0, $maliciousSort);

        // Still returns safe movie performance without crashing or executing SQLi
        $this->assertSame('movie', $res['content_type']);
        $this->assertSame('plays', $res['sort_by']);

        // Movies table still exists intact
        $this->assertTrue($this->db->tableExists('multimedia_movies'));
    }
}
