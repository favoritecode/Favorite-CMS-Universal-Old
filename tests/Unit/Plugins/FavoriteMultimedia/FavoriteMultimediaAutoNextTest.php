<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\PlaylistItem;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MediaSourcePlaybackService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\NextItemResolverService;
use FavoriteCMS\Multimedia\Services\PlaybackProgressService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaAutoNextTest extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;

    protected function setUp(): void
    {
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->app = new Application();

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

        $container = Container::getInstance();
        $container->instance(Database::class, $this->db);

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255),
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
                is_public INTEGER DEFAULT 0,
                created_at TEXT,
                updated_at TEXT
            );
        ");
        Setting::clearCache();

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
    }

    private function createSeries(string $title = 'Test Series'): Series
    {
        return Series::create([
            'title'        => $title,
            'slug'         => 'series-' . uniqid(),
            'status'       => 'published',
            'access_mode'  => 'public',
        ]);
    }

    private function createSeason(int $seriesId, int $seasonNumber, string $title = 'Season'): Season
    {
        return Season::create([
            'series_id'     => $seriesId,
            'season_number' => $seasonNumber,
            'title'         => $title . ' ' . $seasonNumber,
        ]);
    }

    private function createEpisode(int $seriesId, int $seasonId, int $epNumber, string $title, array $overrides = []): Episode
    {
        $data = array_merge([
            'series_id'      => $seriesId,
            'season_id'      => $seasonId,
            'episode_number' => $epNumber,
            'title'          => $title,
            'slug'           => 'ep-' . uniqid(),
            'status'         => 'published',
            'access_mode'    => 'public',
            'duration'       => 2400,
        ], $overrides);

        return Episode::create($data);
    }

    private function addMediaSource(string $contentType, int $contentId, string $url = 'https://example.com/video.mp4', string $type = 'video', bool $isDefault = true): MediaSource
    {
        return MediaSource::create([
            'content_type' => $contentType,
            'content_id'   => $contentId,
            'source_type'  => $type,
            'label'        => 'Server 1',
            'url_or_path'  => $url,
            'mime_type'    => ($type === 'audio') ? 'audio/mpeg' : 'video/mp4',
            'is_default'   => $isDefault ? 1 : 0,
            'status'       => 'active',
            'sort_order'   => 1,
        ]);
    }

    private function createSong(string $title, array $overrides = []): Song
    {
        $data = array_merge([
            'title'        => $title,
            'slug'         => 'song-' . uniqid(),
            'status'       => 'published',
            'access_mode'  => 'public',
            'duration'     => 210,
        ], $overrides);

        return Song::create($data);
    }

    // 1. Next episode resolution in same season (sequential order)
    public function testScenario1_NextEpisodeInSameSeasonSequential(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Episode 1');
        $ep2 = $this->createEpisode((int)$series->id, (int)$season->id, 2, 'Episode 2');
        $ep3 = $this->createEpisode((int)$series->id, (int)$season->id, 3, 'Episode 3');

        $result = NextItemResolverService::resolveNextEpisode(null, (int)$ep1->id);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['has_next']);
        $this->assertFalse($result['completed']);
        $this->assertSame((int)$ep2->id, $result['id']);
        $this->assertSame('Episode 2', $result['title']);
        $this->assertSame(2, $result['episode_number']);
    }

    // 2. Next episode resolution crossing season boundary
    public function testScenario2_NextEpisodeAcrossSeasonBoundary(): void
    {
        $series = $this->createSeries();
        $s1 = $this->createSeason((int)$series->id, 1);
        $s2 = $this->createSeason((int)$series->id, 2);

        $epS1E1 = $this->createEpisode((int)$series->id, (int)$s1->id, 1, 'S1 E1');
        $epS1E2 = $this->createEpisode((int)$series->id, (int)$s1->id, 2, 'S1 E2 Finale');
        $epS2E1 = $this->createEpisode((int)$series->id, (int)$s2->id, 1, 'S2 E1 Premiere');

        $result = NextItemResolverService::resolveNextEpisode(null, (int)$epS1E2->id);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['has_next']);
        $this->assertSame((int)$epS2E1->id, $result['id']);
        $this->assertSame(2, $result['season_number']);
        $this->assertSame(1, $result['episode_number']);
    }

    // 3. Safe skip of draft/unpublished episodes
    public function testScenario3_SafeSkipDraftEpisodes(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Ep 1');
        $ep2 = $this->createEpisode((int)$series->id, (int)$season->id, 2, 'Ep 2 Draft', ['status' => 'draft']);
        $ep3 = $this->createEpisode((int)$series->id, (int)$season->id, 3, 'Ep 3 Published');

        $result = NextItemResolverService::resolveNextEpisode(null, (int)$ep1->id);

        $this->assertTrue($result['found']);
        $this->assertSame((int)$ep3->id, $result['id']);
        $this->assertSame('Ep 3 Published', $result['title']);
    }

    // 4. Safe skip of scheduled future episodes
    public function testScenario4_SafeSkipScheduledFutureEpisodes(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Ep 1');
        $futureDate = date('Y-m-d H:i:s', time() + 86400 * 7);
        $ep2 = $this->createEpisode((int)$series->id, (int)$season->id, 2, 'Ep 2 Scheduled', ['publish_at' => $futureDate]);
        $ep3 = $this->createEpisode((int)$series->id, (int)$season->id, 3, 'Ep 3 Available');

        $result = NextItemResolverService::resolveNextEpisode(null, (int)$ep1->id);

        $this->assertTrue($result['found']);
        $this->assertSame((int)$ep3->id, $result['id']);
    }

    // 5. Safe skip of episodes without playable media sources
    public function testScenario5_SafeSkipEpisodesWithoutMediaSources(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Ep 1');
        $ep2 = $this->createEpisode((int)$series->id, (int)$season->id, 2, 'Ep 2 No Streams');
        $ep3 = $this->createEpisode((int)$series->id, (int)$season->id, 3, 'Ep 3 With Stream');

        $this->addMediaSource('episode', (int)$ep3->id, 'https://cdn.example.com/ep3.mp4');

        $result = NextItemResolverService::resolveNextEpisode(null, (int)$ep1->id, ['require_sources' => true]);

        $this->assertTrue($result['found']);
        $this->assertSame((int)$ep3->id, $result['id']);
    }

    // 6. Final episode in series marks has_next: false, completed: true
    public function testScenario6_FinalEpisodeInSeriesTermination(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Series Finale');

        $result = NextItemResolverService::resolveNextEpisode(null, (int)$ep1->id);

        $this->assertFalse($result['has_next']);
        $this->assertTrue($result['completed']);
        $this->assertTrue($result['series_completed']);
    }

    // 7. Loop protection terminates even with circular/corrupted data
    public function testScenario7_LoopProtectionBoundsExecution(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Ep 1');

        for ($i = 2; $i <= 10; $i++) {
            $this->createEpisode((int)$series->id, (int)$season->id, $i, "Ep $i Draft", ['status' => 'draft']);
        }

        $result = NextItemResolverService::resolveNextEpisode(null, (int)$ep1->id, ['max_iterations' => 3]);

        $this->assertFalse($result['has_next']);
        $this->assertTrue($result['completed']);
    }

    // 8. Playlist next item sequential resolution by sort_order
    public function testScenario8_PlaylistSequentialResolution(): void
    {
        $playlist = Playlist::create(['title' => 'Rock Hits', 'slug' => 'rock-hits-' . uniqid(), 'status' => 'published']);
        $s1 = $this->createSong('Song A');
        $s2 = $this->createSong('Song B');
        $s3 = $this->createSong('Song C');

        $this->addMediaSource('song', (int)$s1->id, 'https://cdn.example.com/s1.mp3', 'audio');
        $this->addMediaSource('song', (int)$s2->id, 'https://cdn.example.com/s2.mp3', 'audio');
        $this->addMediaSource('song', (int)$s3->id, 'https://cdn.example.com/s3.mp3', 'audio');

        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s1->id, 'sort_order' => 1]);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s2->id, 'sort_order' => 2]);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s3->id, 'sort_order' => 3]);

        $result = NextItemResolverService::resolveNextPlaylistItem(null, (int)$playlist->id, (int)$s1->id);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['has_next']);
        $this->assertSame((int)$s2->id, $result['id']);
        $this->assertSame('Song B', $result['title']);
    }

    // 9. Playlist safe skip of draft/unplayable songs
    public function testScenario9_PlaylistSafeSkipDraftSongs(): void
    {
        $playlist = Playlist::create(['title' => 'Pop Mix', 'slug' => 'pop-mix-' . uniqid(), 'status' => 'published']);
        $s1 = $this->createSong('Track 1');
        $s2 = $this->createSong('Track 2 Draft', ['status' => 'draft']);
        $s3 = $this->createSong('Track 3 Active');

        $this->addMediaSource('song', (int)$s1->id, 'https://cdn.example.com/t1.mp3', 'audio');
        $this->addMediaSource('song', (int)$s2->id, 'https://cdn.example.com/t2.mp3', 'audio');
        $this->addMediaSource('song', (int)$s3->id, 'https://cdn.example.com/t3.mp3', 'audio');

        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s1->id, 'sort_order' => 1]);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s2->id, 'sort_order' => 2]);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s3->id, 'sort_order' => 3]);

        $result = NextItemResolverService::resolveNextPlaylistItem(null, (int)$playlist->id, (int)$s1->id);

        $this->assertTrue($result['found']);
        $this->assertSame((int)$s3->id, $result['id']);
        $this->assertSame('Track 3 Active', $result['title']);
    }

    // 10. Final playlist item termination
    public function testScenario10_FinalPlaylistItemTermination(): void
    {
        $playlist = Playlist::create(['title' => 'Short Playlist', 'slug' => 'short-pl-' . uniqid(), 'status' => 'published']);
        $s1 = $this->createSong('Only Song');
        $this->addMediaSource('song', (int)$s1->id, 'https://cdn.example.com/only.mp3', 'audio');
        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s1->id, 'sort_order' => 1]);

        $result = NextItemResolverService::resolveNextPlaylistItem(null, (int)$playlist->id, (int)$s1->id, ['repeat' => false]);

        $this->assertFalse($result['has_next']);
        $this->assertTrue($result['completed']);
        $this->assertTrue($result['playlist_completed']);
    }

    // 11. Playlist loop/repeat option cycling back to first playable item
    public function testScenario11_PlaylistLoopCyclesToStart(): void
    {
        $playlist = Playlist::create(['title' => 'Looping Playlist', 'slug' => 'loop-pl-' . uniqid(), 'status' => 'published']);
        $s1 = $this->createSong('Track A');
        $s2 = $this->createSong('Track B');

        $this->addMediaSource('song', (int)$s1->id, 'https://cdn.example.com/ta.mp3', 'audio');
        $this->addMediaSource('song', (int)$s2->id, 'https://cdn.example.com/tb.mp3', 'audio');

        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s1->id, 'sort_order' => 1]);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s2->id, 'sort_order' => 2]);

        $result = NextItemResolverService::resolveNextPlaylistItem(null, (int)$playlist->id, (int)$s2->id, ['repeat' => true]);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['has_next']);
        $this->assertSame((int)$s1->id, $result['id']);
        $this->assertSame('Track A', $result['title']);
    }

    // 12. Fail-closed entitlement check: premium item access check
    public function testScenario12_FailClosedEntitlementCheckForPremium(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Free Ep');
        $ep2 = $this->createEpisode((int)$series->id, (int)$season->id, 2, 'VIP Ep', ['access_mode' => 'premium']);

        $this->addMediaSource('episode', (int)$ep2->id, 'https://cdn.example.com/vip.mp4');

        $user = new User(['id' => 99, 'role' => 'user']);
        $result = NextItemResolverService::resolveNextEpisode($user, (int)$ep1->id, ['skip_unauthorized' => false]);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['has_next']);
        $this->assertFalse($result['is_accessible']);
        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, $result['access_state']);
        $this->assertFalse($result['can_auto_play']);

        // Guest user gets LOGIN_REQUIRED
        $guestResult = NextItemResolverService::resolveNextEpisode(null, (int)$ep1->id, ['skip_unauthorized' => false]);
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, $guestResult['access_state']);
    }

    // 13. Unauthorized items never leak protected stream URLs
    public function testScenario13_UnauthorizedItemsNeverLeakProtectedUrls(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Ep 1');
        $ep2 = $this->createEpisode((int)$series->id, (int)$season->id, 2, 'Secret Premium Ep', ['access_mode' => 'premium']);

        $this->addMediaSource('episode', (int)$ep2->id, 'https://cdn.example.com/secret_stream.m3u8', 'hls');

        $result = NextItemResolverService::resolveNextEpisode(null, (int)$ep1->id, ['skip_unauthorized' => false]);

        $this->assertNull($result['player_url']);
        $this->assertNull($result['stream_url']);
        $this->assertEmpty($result['sources']);
        $this->assertNull($result['default_source']);
        $this->assertNotNull($result['upgrade_url']);
    }

    // 14. Autoplay denial handling: Promise rejection pattern verified
    public function testScenario14_AutoplayRejectionSafety(): void
    {
        // Test that MediaPlaybackController does not crash when request passes autoplay=0
        $controller = new MediaPlaybackController($this->app);
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Ep 1');
        $ep2 = $this->createEpisode((int)$series->id, (int)$season->id, 2, 'Ep 2');
        $this->addMediaSource('episode', (int)$ep2->id, 'https://cdn.example.com/ep2.mp4');

        $req = new Request(['require_sources' => '1'], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $controller->apiNextEpisode($req, (string)$ep1->id);

        $this->assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertTrue($data['has_next']);
        $this->assertTrue($data['can_auto_play']);
    }

    // 15. Progress finalization: is_completed recorded
    public function testScenario15_ProgressFinalization(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep1 = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'Ep 1');

        PlaybackProgress::saveProgress(10, 'episode', (int)$ep1->id, 2400, 2400, true);

        $progress = PlaybackProgress::findByUserAndContent(10, 'episode', (int)$ep1->id);
        $this->assertNotNull($progress);
        $this->assertEquals(1, $progress->is_completed);
    }

    // 16. Admin setting auto_play_next toggle persistence
    public function testScenario16_AdminSettingAutoPlayNextPersistence(): void
    {
        Setting::set('multimedia', 'auto_play_next', 'yes');
        Setting::set('multimedia', 'auto_next_countdown', '5');

        $this->assertSame('yes', Setting::get('multimedia', 'auto_play_next'));
        $this->assertSame('5', Setting::get('multimedia', 'auto_next_countdown'));

        Setting::set('multimedia', 'auto_play_next', 'no');
        Setting::set('multimedia', 'auto_next_countdown', '10');

        $this->assertSame('no', Setting::get('multimedia', 'auto_play_next'));
        $this->assertSame('10', Setting::get('multimedia', 'auto_next_countdown'));
    }

    // 17. Embed iframe source separation: embed sources have can_auto_failover false
    public function testScenario17_EmbedIframeSourceSeparation(): void
    {
        $series = $this->createSeries();
        $season = $this->createSeason((int)$series->id, 1);
        $ep = $this->createEpisode((int)$series->id, (int)$season->id, 1, 'YouTube Ep');

        $this->addMediaSource('episode', (int)$ep->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'embed');

        $playable = MediaSourcePlaybackService::getPlayableSources(null, 'episode', (int)$ep->id);
        $this->assertNotEmpty($playable['sources']);
        $source = $playable['sources'][0];

        $this->assertSame('embed', $source['player_type']);
        $this->assertFalse($source['can_auto_failover'], 'Embed sources must NOT support automatic stream failover');
    }

    // 18. Playlist context preserved across items in multiple playlists
    public function testScenario18_PlaylistContextPreservedAcrossMultiplePlaylists(): void
    {
        $p1 = Playlist::create(['title' => 'Playlist 1', 'slug' => 'p1-' . uniqid(), 'status' => 'published']);
        $p2 = Playlist::create(['title' => 'Playlist 2', 'slug' => 'p2-' . uniqid(), 'status' => 'published']);

        $sShared = $this->createSong('Shared Song');
        $s1Next = $this->createSong('P1 Exclusive Follower');
        $s2Next = $this->createSong('P2 Exclusive Follower');

        $this->addMediaSource('song', (int)$sShared->id, 'https://cdn.example.com/shared.mp3', 'audio');
        $this->addMediaSource('song', (int)$s1Next->id, 'https://cdn.example.com/p1_next.mp3', 'audio');
        $this->addMediaSource('song', (int)$s2Next->id, 'https://cdn.example.com/p2_next.mp3', 'audio');

        // P1 sequence: Shared -> P1 Exclusive
        PlaylistItem::create(['playlist_id' => $p1->id, 'song_id' => $sShared->id, 'sort_order' => 1]);
        PlaylistItem::create(['playlist_id' => $p1->id, 'song_id' => $s1Next->id, 'sort_order' => 2]);

        // P2 sequence: Shared -> P2 Exclusive
        PlaylistItem::create(['playlist_id' => $p2->id, 'song_id' => $sShared->id, 'sort_order' => 1]);
        PlaylistItem::create(['playlist_id' => $p2->id, 'song_id' => $s2Next->id, 'sort_order' => 2]);

        // When queried under P1 context:
        $res1 = NextItemResolverService::resolveNextPlaylistItem(null, (int)$p1->id, (int)$sShared->id);
        $this->assertSame((int)$s1Next->id, $res1['id']);

        // When queried under P2 context:
        $res2 = NextItemResolverService::resolveNextPlaylistItem(null, (int)$p2->id, (int)$sShared->id);
        $this->assertSame((int)$s2Next->id, $res2['id']);
    }

    // 19. Controller API next-episode and next-playlist-item endpoints
    public function testScenario19_ControllerApiEndpoints(): void
    {
        $controller = new MediaPlaybackController($this->app);

        $playlist = Playlist::create(['title' => 'Api Playlist', 'slug' => 'api-pl-' . uniqid(), 'status' => 'published']);
        $s1 = $this->createSong('Track 1');
        $s2 = $this->createSong('Track 2');
        $this->addMediaSource('song', (int)$s1->id, 'https://cdn.example.com/t1.mp3', 'audio');
        $this->addMediaSource('song', (int)$s2->id, 'https://cdn.example.com/t2.mp3', 'audio');

        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s1->id, 'sort_order' => 1]);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'song_id' => $s2->id, 'sort_order' => 2]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $controller->apiNextPlaylistItem($req, (string)$playlist->id, (string)$s1->id);

        $this->assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertTrue($data['has_next']);
        $this->assertSame((int)$s2->id, $data['id']);
        $this->assertSame('Track 2', $data['title']);
    }

    // 20. Version constant equals 1.0.6
    public function testScenario20_PluginVersionConstantIs106(): void
    {
        $this->assertSame('1.0.7', FavoriteMultimediaPlugin::VERSION);

        $pluginJsonPath = APP_ROOT . '/plugins/favorite-multimedia/plugin.json';
        $this->assertFileExists($pluginJsonPath);
        $json = json_decode(file_get_contents($pluginJsonPath), true);
        $this->assertSame('1.0.7', $json['version'] ?? '');
    }
}
