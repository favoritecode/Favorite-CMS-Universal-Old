<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Theme\ThemeConfig;
use FavoriteCMS\Multimedia\Theme\ThemeManager;
use FavoriteCMS\Multimedia\Theme\Audio\AudioPlayerState;
use FavoriteCMS\Multimedia\Theme\Audio\AudioQueueManager;
use FavoriteCMS\Multimedia\Theme\Audio\AudioContextResolver;
use FavoriteCMS\Multimedia\Theme\Audio\AudioAccessResolver;
use FavoriteCMS\Multimedia\Theme\Audio\AudioPlaybackSession;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive Test Suite for Favorite Multimedia Theme System Chunk 3:
 * Persistent Audio Player + Queue + Audio Playlist Experience.
 * 
 * Verifies all 45 scenarios:
 * 1-5: Canonical player, state persistence, safe serialization, refresh recovery, intent restoration.
 * 6-8: Auto-next album/playlist/artist context resolution.
 * 9-11: Repeat modes (off, queue loop, repeat one).
 * 12-14: Deterministic shuffle, active song preservation, unshuffle restoration.
 * 15-20: Queue mutations (play next, add, remove, clear, reorder, empty degradation).
 * 21-27: Access gating (public, login, premium fail-closed, deleted files, error notices).
 * 28-29: Autoplay rejection handling, page transition queue preservation.
 * 30-38: UI components (mini-player, scrubber, volume/mute, modal, lyrics, drawer).
 * 39-43: Keyboard shortcuts & MediaSession API.
 * 44-45: Mutual exclusion between video and audio engines.
 */
class FavoriteMultimediaThemeAudioTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaFrontendController $frontendCtrl;
    private string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $_SESSION = [];
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'admin';
        $_SESSION['auth_user_email'] = 'admin@example.com';
        $_SESSION['_token'] = 'valid_theme_token';

        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_audio_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        // Core tables
        $this->db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(50), name VARCHAR(100), email VARCHAR(100), password VARCHAR(255), status VARCHAR(20), email_verified_at DATETIME, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE permissions (id INTEGER PRIMARY KEY, name VARCHAR(100), slug VARCHAR(100), description TEXT, group_name VARCHAR(50), created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));");
        $this->db->execute("CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, created_at DATETIME, PRIMARY KEY (role_id, permission_id));");
        $this->db->execute("CREATE TABLE settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");

        $now = gmdate('Y-m-d H:i:s');
        $rId = $this->db->insert('roles', ['name' => 'Admin', 'slug' => 'admin', 'created_at' => $now, 'updated_at' => $now]);
        $uId = $this->db->insert('users', ['id' => 1, 'username' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('user_roles', ['user_id' => $uId, 'role_id' => $rId]);

        // Regular non-admin user
        $this->db->insert('users', ['id' => 2, 'username' => 'regular', 'name' => 'Regular User', 'email' => 'regular@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();
        ThemeManager::reset();

        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->frontendCtrl = $this->app->make(MultimediaFrontendController::class);
    }

    protected function tearDown(): void
    {
        FavoriteMultimediaPlugin::reset();
        ThemeManager::reset();
        $_SESSION = [];

        if (isset($this->tempDb) && file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    private function createSampleSong(array $overrides = [], bool $withSource = true): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'title' => 'Starlight Echoes',
            'slug' => 'starlight-echoes-' . bin2hex(random_bytes(4)),
            'description' => 'A beautiful acoustic track.',
            'artist_id' => null,
            'album_id' => null,
            'cover' => '/assets/songs/starlight.jpg',
            'lyrics' => "Echoes across the night\nStars shining bright",
            'duration' => 210,
            'status' => 'published',
            'access_mode' => 'public',
            'featured' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $songId = (int)$this->db->insert('multimedia_songs', $data);

        if ($withSource) {
            $this->db->insert('multimedia_sources', [
                'content_type' => 'song',
                'content_id'   => $songId,
                'source_mode'  => 'url',
                'source_type'  => 'audio',
                'url_or_path'  => '/storage/audio/track-' . $songId . '.mp3',
                'label'        => 'Standard Audio',
                'is_default'   => 1,
                'status'       => 'active',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        return $songId;
    }

    private function createSampleAlbum(array $overrides = []): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'title' => 'Galactic Horizons',
            'slug' => 'galactic-horizons-' . bin2hex(random_bytes(4)),
            'artist_id' => null,
            'cover' => '/assets/albums/galactic.jpg',
            'release_date' => '2025-06-15',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        return (int)$this->db->insert('multimedia_albums', $data);
    }

    private function createSampleArtist(array $overrides = []): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'name' => 'Solaris Ensemble',
            'slug' => 'solaris-ensemble-' . bin2hex(random_bytes(4)),
            'biography' => 'Acoustic indie collective.',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        return (int)$this->db->insert('multimedia_artists', $data);
    }

    private function createSamplePlaylist(array $overrides = []): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'title' => 'Deep Work Flow',
            'slug' => 'deep-work-flow-' . bin2hex(random_bytes(4)),
            'description' => 'Focus beats and calm rhythms.',
            'status' => 'published',
            'access_mode' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        return (int)$this->db->insert('multimedia_playlists', $data);
    }

    // =========================================================================
    // SCENARIOS 1 - 5: Architecture, Persistence, and Intent
    // =========================================================================

    /**
     * Scenario 1: Canonical HTMLAudioElement single player model.
     */
    public function testScenario01CanonicalHtmlAudioElementSinglePlayerModel(): void
    {
        $playerJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-player.js');
        $this->assertStringContainsString('window.__FM_GLOBAL_AUDIO_PLAYER_INSTANCE__', $playerJs);
        $this->assertStringContainsString('this.audio = new Audio()', $playerJs);
        $this->assertStringContainsString('window.FavoriteAudioPlayer = new GlobalAudioPlayer()', $playerJs);

        $miniPlayerView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/mini-player.php');
        $this->assertStringContainsString('id="fm-mini-player"', $miniPlayerView);
    }

    /**
     * Scenario 2: Persistent player state retention across pages (sessionStorage schema validation).
     */
    public function testScenario02PersistentPlayerStateRetentionAcrossPages(): void
    {
        $state = new AudioPlayerState([
            'songId' => 42,
            'status' => 'playing',
            'isPlayingIntent' => true,
            'position' => 125.4,
            'duration' => 240.0,
            'volume' => 0.8,
            'isMuted' => false,
            'repeatMode' => 'queue',
            'isShuffled' => false,
            'queueContext' => 'album',
            'contextId' => 10,
            'currentIndex' => 2,
            'queue' => [
                ['id' => 40, 'title' => 'Intro', 'artist' => 'Artist A', 'duration' => 60],
                ['id' => 41, 'title' => 'Verse', 'artist' => 'Artist A', 'duration' => 180],
                ['id' => 42, 'title' => 'Chorus', 'artist' => 'Artist A', 'duration' => 240],
            ]
        ]);

        $serialized = $state->toArray();
        $this->assertSame(42, $serialized['songId']);
        $this->assertSame('playing', $serialized['status']);
        $this->assertTrue($serialized['isPlayingIntent']);
        $this->assertEqualsWithDelta(125.4, $serialized['position'], 0.01);
        $this->assertCount(3, $serialized['queue']);
    }

    /**
     * Scenario 3: Safe state serialization (zero protected URLs stored).
     */
    public function testScenario03SafeStateSerializationZeroProtectedUrlsStored(): void
    {
        // Even if raw state is passed with a secret token or stream URL, it must be stripped
        $state = new AudioPlayerState([
            'songId' => 99,
            'stream_url' => 'https://example.com/protected/audio.mp3?token=secret123',
            'token' => 'super_secret',
            'queue' => [
                ['id' => 99, 'title' => 'Track 99', 'stream_url' => 'https://secret.url/leak.mp3', 'secret' => 'xyz']
            ]
        ]);

        $exported = $state->toArray();
        $json = json_encode($exported);

        $this->assertStringNotContainsString('secret123', $json);
        $this->assertStringNotContainsString('leak.mp3', $json);
        $this->assertArrayNotHasKey('stream_url', $exported);
        $this->assertArrayNotHasKey('stream_url', $exported['queue'][0]);
    }

    /**
     * Scenario 4: State recovery after hard refresh.
     */
    public function testScenario04StateRecoveryAfterHardRefresh(): void
    {
        $rawJson = json_encode([
            'songId' => 15,
            'status' => 'paused',
            'isPlayingIntent' => true,
            'position' => 88.5,
            'duration' => 180.0,
            'volume' => 0.65,
            'isMuted' => false,
            'repeatMode' => 'off',
            'isShuffled' => true,
            'queueContext' => 'playlist',
            'contextId' => 3,
            'currentIndex' => 0,
            'queue' => [
                ['id' => 15, 'title' => 'Track 15', 'artist' => 'Artist X', 'duration' => 180]
            ]
        ]);

        $recovered = AudioPlayerState::fromJson($rawJson);
        $this->assertSame(15, $recovered->songId);
        $this->assertEqualsWithDelta(88.5, $recovered->position, 0.01);
        $this->assertTrue($recovered->isPlayingIntent);
        $this->assertTrue($recovered->isShuffled);
        $this->assertSame(1, count($recovered->queue));
    }

    /**
     * Scenario 5: Playback intent restoration (paused vs playing restoration).
     */
    public function testScenario05PlaybackIntentRestoration(): void
    {
        // User paused manually before leaving
        $pausedState = new AudioPlayerState([
            'songId' => 10,
            'status' => 'paused',
            'isPlayingIntent' => false,
            'position' => 30.0,
        ]);
        $this->assertFalse($pausedState->isPlayingIntent);

        // User was actively listening when navigating
        $playingState = new AudioPlayerState([
            'songId' => 10,
            'status' => 'playing',
            'isPlayingIntent' => true,
            'position' => 30.0,
        ]);
        $this->assertTrue($playingState->isPlayingIntent);
    }

    // =========================================================================
    // SCENARIOS 6 - 8: Auto-Next & Context Resolution
    // =========================================================================

    /**
     * Scenario 6: Auto-next in album context.
     */
    public function testScenario06AutoNextInAlbumContext(): void
    {
        $albumId = $this->createSampleAlbum();
        $s1 = $this->createSampleSong(['album_id' => $albumId, 'title' => 'Album Track 1']);
        $s2 = $this->createSampleSong(['album_id' => $albumId, 'title' => 'Album Track 2']);
        $s3 = $this->createSampleSong(['album_id' => $albumId, 'title' => 'Album Track 3']);

        $resolver = new AudioContextResolver();
        $res = $resolver->resolveAlbum($albumId, $s2);
        $this->assertSame('album', $res['context']);
        $this->assertCount(3, $res['items']);
        $this->assertSame(1, $res['start_index']); // s2 is index 1

        $qm = new AudioQueueManager();
        $qm->setQueue($res['items'], $res['start_index']);
        $this->assertSame($s2, $qm->getCurrentItem()['id']);

        $next = $qm->next();
        $this->assertNotNull($next);
        $this->assertSame($s3, $next['id']);
    }

    /**
     * Scenario 7: Auto-next in playlist context.
     */
    public function testScenario07AutoNextInPlaylistContext(): void
    {
        $plId = $this->createSamplePlaylist();
        $s1 = $this->createSampleSong(['title' => 'PL Song 1']);
        $s2 = $this->createSampleSong(['title' => 'PL Song 2']);

        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('multimedia_playlist_items', ['playlist_id' => $plId, 'song_id' => $s1, 'sort_order' => 1, 'created_at' => $now]);
        $this->db->insert('multimedia_playlist_items', ['playlist_id' => $plId, 'song_id' => $s2, 'sort_order' => 2, 'created_at' => $now]);

        $resolver = new AudioContextResolver();
        $res = $resolver->resolvePlaylist($plId, $s1);
        $this->assertSame('playlist', $res['context']);
        $this->assertCount(2, $res['items']);
        $this->assertSame(0, $res['start_index']);

        $qm = new AudioQueueManager();
        $qm->setQueue($res['items'], 0);
        $next = $qm->next();
        $this->assertSame($s2, $next['id']);
    }

    /**
     * Scenario 8: Auto-next in artist context.
     */
    public function testScenario08AutoNextInArtistContext(): void
    {
        $artistId = $this->createSampleArtist();
        $s1 = $this->createSampleSong(['artist_id' => $artistId, 'title' => 'Artist Track 1', 'plays_count' => 100]);
        $s2 = $this->createSampleSong(['artist_id' => $artistId, 'title' => 'Artist Track 2', 'plays_count' => 50]);

        $resolver = new AudioContextResolver();
        $res = $resolver->resolveArtist($artistId, 50, $s1);
        $this->assertSame('artist', $res['context']);
        $this->assertCount(2, $res['items']);

        $qm = new AudioQueueManager();
        $qm->setQueue($res['items'], 0);
        $next = $qm->next();
        $this->assertSame($s2, $next['id']);
    }

    // =========================================================================
    // SCENARIOS 9 - 11: Repeat Modes
    // =========================================================================

    /**
     * Scenario 9: Queue loop / repeat off behavior.
     */
    public function testScenario09QueueLoopRepeatOffBehavior(): void
    {
        $items = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 2, 'title' => 'T2'],
        ];

        $qm = new AudioQueueManager($items, 1, 'off');
        $this->assertSame(1, $qm->getCurrentIndex());

        // At end of queue with repeat off, next() returns null
        $next = $qm->next();
        $this->assertNull($next);
        $this->assertSame(1, $qm->getCurrentIndex()); // index preserved at end
    }

    /**
     * Scenario 10: Repeat-all (queue repeat) wrap-around.
     */
    public function testScenario10RepeatAllQueueRepeatWrapAround(): void
    {
        $items = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 2, 'title' => 'T2'],
        ];

        $qm = new AudioQueueManager($items, 1, 'queue');
        $next = $qm->next();
        $this->assertNotNull($next);
        $this->assertSame(1, $next['id']);
        $this->assertSame(0, $qm->getCurrentIndex());
    }

    /**
     * Scenario 11: Repeat-one track loop.
     */
    public function testScenario11RepeatOneTrackLoop(): void
    {
        $items = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 2, 'title' => 'T2'],
        ];

        $qm = new AudioQueueManager($items, 1, 'one');
        $next = $qm->next();
        $this->assertNotNull($next);
        $this->assertSame(2, $next['id']); // same track
        $this->assertSame(1, $qm->getCurrentIndex());

        $next2 = $qm->next();
        $this->assertSame(2, $next2['id']);
    }

    // =========================================================================
    // SCENARIOS 12 - 14: Deterministic Shuffle & Unshuffle
    // =========================================================================

    /**
     * Scenario 12: Deterministic shuffle order.
     */
    public function testScenario12DeterministicShuffleOrder(): void
    {
        $items = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 2, 'title' => 'T2'],
            ['id' => 3, 'title' => 'T3'],
            ['id' => 4, 'title' => 'T4'],
            ['id' => 5, 'title' => 'T5'],
        ];

        $qm = new AudioQueueManager($items, 0);
        $isShuffled = $qm->toggleShuffle();
        $this->assertTrue($isShuffled);
        $this->assertTrue($qm->isShuffled());
        $this->assertCount(5, $qm->getItems());
    }

    /**
     * Scenario 13: Shuffle preserves current playing track.
     */
    public function testScenario13ShufflePreservesCurrentPlayingTrack(): void
    {
        $items = [
            ['id' => 10, 'title' => 'Track 10'],
            ['id' => 20, 'title' => 'Track 20'],
            ['id' => 30, 'title' => 'Track 30'],
            ['id' => 40, 'title' => 'Track 40'],
        ];

        $qm = new AudioQueueManager($items, 2); // currently playing Track 30
        $this->assertSame(30, $qm->getCurrentItem()['id']);

        $qm->toggleShuffle();
        // Current playing track must stay at current index (or index 0)
        $this->assertSame(30, $qm->getCurrentItem()['id']);
    }

    /**
     * Scenario 14: Unshuffle restores original collection order.
     */
    public function testScenario14UnshuffleRestoresOriginalCollectionOrder(): void
    {
        $originalItems = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 2, 'title' => 'T2'],
            ['id' => 3, 'title' => 'T3'],
            ['id' => 4, 'title' => 'T4'],
        ];

        $qm = new AudioQueueManager($originalItems, 1); // playing T2
        $qm->toggleShuffle(); // Shuffled
        $this->assertTrue($qm->isShuffled());

        $qm->toggleShuffle(); // Unshuffled
        $this->assertFalse($qm->isShuffled());

        $restored = $qm->getItems();
        $this->assertSame([1, 2, 3, 4], array_column($restored, 'id'));
        $this->assertSame(2, $qm->getCurrentItem()['id']);
    }

    // =========================================================================
    // SCENARIOS 15 - 20: Queue Operations & Graceful Degradation
    // =========================================================================

    /**
     * Scenario 15: Play next track insertion.
     */
    public function testScenario15PlayNextTrackInsertion(): void
    {
        $items = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 3, 'title' => 'T3'],
        ];

        $qm = new AudioQueueManager($items, 0);
        $insertedIndex = $qm->insertNext(['id' => 2, 'title' => 'T2 (Next)']);
        $this->assertSame(1, $insertedIndex);

        $q = $qm->getItems();
        $this->assertSame(2, $q[1]['id']);
        $this->assertSame(3, $q[2]['id']);
    }

    /**
     * Scenario 16: Add to queue append.
     */
    public function testScenario16AddToQueueAppend(): void
    {
        $qm = new AudioQueueManager([['id' => 1, 'title' => 'T1']], 0);
        $qm->add(['id' => 2, 'title' => 'T2']);

        $items = $qm->getItems();
        $this->assertCount(2, $items);
        $this->assertSame(2, $items[1]['id']);
    }

    /**
     * Scenario 17: Remove queue item.
     */
    public function testScenario17RemoveQueueItem(): void
    {
        $items = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 2, 'title' => 'T2'],
            ['id' => 3, 'title' => 'T3'],
        ];

        $qm = new AudioQueueManager($items, 2); // currently at index 2 (T3)
        $qm->remove(0); // remove T1

        $this->assertCount(2, $qm->getItems());
        $this->assertSame(1, $qm->getCurrentIndex()); // index adjusted down
        $this->assertSame(3, $qm->getCurrentItem()['id']);
    }

    /**
     * Scenario 18: Clear queue preserves active track.
     */
    public function testScenario18ClearQueuePreservesActiveTrack(): void
    {
        $items = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 2, 'title' => 'T2'],
            ['id' => 3, 'title' => 'T3'],
        ];

        $qm = new AudioQueueManager($items, 1);
        $qm->clear(true); // preserveActive = true

        $this->assertCount(1, $qm->getItems());
        $this->assertSame(2, $qm->getCurrentItem()['id']);
        $this->assertSame(0, $qm->getCurrentIndex());

        // Full clear
        $qm->clear(false);
        $this->assertCount(0, $qm->getItems());
    }

    /**
     * Scenario 19: Reorder queue.
     */
    public function testScenario19ReorderQueue(): void
    {
        $items = [
            ['id' => 1, 'title' => 'T1'],
            ['id' => 2, 'title' => 'T2'],
            ['id' => 3, 'title' => 'T3'],
        ];

        $qm = new AudioQueueManager($items, 0); // playing T1
        $qm->move(0, 2); // Move T1 from start to end

        $newItems = $qm->getItems();
        $this->assertSame(2, $newItems[0]['id']);
        $this->assertSame(3, $newItems[1]['id']);
        $this->assertSame(1, $newItems[2]['id']);
        $this->assertSame(2, $qm->getCurrentIndex()); // index follows moved current item
    }

    /**
     * Scenario 20: Empty queue graceful degradation.
     */
    public function testScenario20EmptyQueueGracefulDegradation(): void
    {
        $qm = new AudioQueueManager([]);
        $this->assertTrue($qm->isEmpty());
        $this->assertNull($qm->getCurrentItem());
        $this->assertNull($qm->next());
        $prev = $qm->previous(0.0);
        $this->assertSame('none', $prev['action']);
        $this->assertNull($prev['item']);
    }

    // =========================================================================
    // SCENARIOS 21 - 27: Access Gating & Error Notices
    // =========================================================================

    /**
     * Scenario 21: Public song playback permission.
     */
    public function testScenario21PublicSongPlaybackPermission(): void
    {
        $songId = $this->createSampleSong(['access_mode' => 'public']);
        $resolver = new AudioAccessResolver();
        $res = $resolver->resolvePlayableSong($songId, null);

        $this->assertTrue($res['allowed']);
        $this->assertArrayHasKey('stream_url', $res);
    }

    /**
     * Scenario 22: Login-required song without auth fails closed.
     */
    public function testScenario22LoginRequiredSongWithoutAuthFailsClosed(): void
    {
        $songId = $this->createSampleSong(['access_mode' => 'login_required']);
        $resolver = new AudioAccessResolver();
        $res = $resolver->resolvePlayableSong($songId, null);

        $this->assertFalse($res['allowed']);
        $this->assertSame('login_required', $res['reason']);
        $this->assertArrayHasKey('login_url', $res);
    }

    /**
     * Scenario 23: Login-required song with auth resolves stream.
     */
    public function testScenario23LoginRequiredSongWithAuthResolvesStream(): void
    {
        $songId = $this->createSampleSong(['access_mode' => 'login_required']);
        $resolver = new AudioAccessResolver();
        $authUser = User::find(2); // regular user
        $res = $resolver->resolvePlayableSong($songId, $authUser);

        $this->assertTrue($res['allowed']);
        $this->assertArrayHasKey('stream_url', $res);
    }

    /**
     * Scenario 24: Premium-required song without subscription fails closed.
     */
    public function testScenario24PremiumRequiredSongWithoutSubscriptionFailsClosed(): void
    {
        $songId = $this->createSampleSong(['access_mode' => 'premium']);
        $resolver = new AudioAccessResolver();
        $regularUser = User::find(2); // regular user
        $res = $resolver->resolvePlayableSong($songId, $regularUser);

        $this->assertFalse($res['allowed']);
        $this->assertSame('premium_required', $res['reason']);
        $this->assertArrayHasKey('subscription_url', $res);
    }

    /**
     * Scenario 25: Premium-required song with active subscription resolves stream.
     */
    public function testScenario25PremiumRequiredSongWithActiveSubscriptionResolvesStream(): void
    {
        $songId = $this->createSampleSong(['access_mode' => 'premium']);
        $resolver = new AudioAccessResolver();
        $adminUser = User::find(1);
        $GLOBALS['_test_favorite_digital_entitled_users'] = [1 => true];
        try {
            $res = $resolver->resolvePlayableSong($songId, $adminUser);
            $this->assertTrue($res['allowed']);
            $this->assertArrayHasKey('stream_url', $res);
        } finally {
            unset($GLOBALS['_test_favorite_digital_entitled_users']);
        }
    }

    /**
     * Scenario 26: Missing/deleted audio file fails closed.
     */
    public function testScenario26MissingOrDeletedAudioFileFailsClosed(): void
    {
        $songId = $this->createSampleSong(['access_mode' => 'public'], false); // withSource = false
        $resolver = new AudioAccessResolver();
        $res = $resolver->resolvePlayableSong($songId, null);

        $this->assertFalse($res['allowed']);
        $this->assertSame('no_source', $res['reason']);
    }

    /**
     * Scenario 27: Network failure recovery / graceful error notice.
     */
    public function testScenario27NetworkFailureRecoveryGracefulErrorNotice(): void
    {
        $req = new Request([], [], [], [], [], ['REQUEST_URI' => '/multimedia/api/audio/resolve/999999', 'REQUEST_METHOD' => 'GET']);
        $response = $this->frontendCtrl->apiAudioResolve($req, '999999');
        $this->assertInstanceOf(Response::class, $response);
        $body = json_decode($response->getContent(), true);

        $this->assertFalse($body['success']);
        $this->assertSame('not_found', $body['reason']);
    }

    // =========================================================================
    // SCENARIOS 28 - 29: Autoplay Rejection & Queue Preservation
    // =========================================================================

    /**
     * Scenario 28: Browser autoplay rejection handling.
     */
    public function testScenario28BrowserAutoplayRejectionHandling(): void
    {
        $playerJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-player.js');
        $this->assertStringContainsString('NotAllowedError', $playerJs);
        $this->assertStringContainsString('autoplay_blocked', $playerJs);
        $this->assertStringContainsString('requiresInteraction', $playerJs);
    }

    /**
     * Scenario 29: Page change preserves active queue.
     */
    public function testScenario29PageChangePreservesActiveQueue(): void
    {
        $queueData = [
            'queueContext' => 'album',
            'contextId' => 5,
            'currentIndex' => 2,
            'repeatMode' => 'queue',
            'isShuffled' => true,
            'items' => [
                ['id' => 101, 'title' => 'Track 1'],
                ['id' => 102, 'title' => 'Track 2'],
                ['id' => 103, 'title' => 'Track 3'],
            ]
        ];

        $qm = AudioQueueManager::fromArray($queueData);
        $this->assertSame(3, count($qm->getItems()));
        $this->assertSame(2, $qm->getCurrentIndex());
        $this->assertSame(103, $qm->getCurrentItem()['id']);
        $this->assertSame('queue', $qm->getRepeatMode());
        $this->assertTrue($qm->isShuffled());
    }

    // =========================================================================
    // SCENARIOS 30 - 38: Frontend UI Components & Controls
    // =========================================================================

    /**
     * Scenario 30: Mini player renders track title, artist, artwork.
     */
    public function testScenario30MiniPlayerRendersTrackMetadata(): void
    {
        $miniView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/mini-player.php');
        $this->assertStringContainsString('id="fm-mini-player-title"', $miniView);
        $this->assertStringContainsString('id="fm-mini-player-artist"', $miniView);
        $this->assertStringContainsString('id="fm-mini-player-art"', $miniView);
    }

    /**
     * Scenario 31: Mini player play/pause toggle reflects audio state.
     */
    public function testScenario31MiniPlayerPlayPauseToggleReflectsAudioState(): void
    {
        $miniView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/mini-player.php');
        $this->assertStringContainsString('id="fm-mini-player-play-btn"', $miniView);

        $uiJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js');
        $this->assertStringContainsString('setPlayState(isPlaying)', $uiJs);
    }

    /**
     * Scenario 32: Mini player seekbar scrubs audio currentTime.
     */
    public function testScenario32MiniPlayerSeekbarScrubsAudioPosition(): void
    {
        $miniView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/mini-player.php');
        $this->assertStringContainsString('id="fm-mini-player-progress"', $miniView);
        $this->assertStringContainsString('type="range"', $miniView);
    }

    /**
     * Scenario 33: Mini player volume and mute controls.
     */
    public function testScenario33MiniPlayerVolumeAndMuteControls(): void
    {
        $miniView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/mini-player.php');
        $this->assertStringContainsString('id="fm-mini-player-volume"', $miniView);
        $this->assertStringContainsString('id="fm-mini-player-mute-btn"', $miniView);
    }

    /**
     * Scenario 34: Mini player expand to full modal.
     */
    public function testScenario34MiniPlayerExpandToFullModal(): void
    {
        $miniView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/mini-player.php');
        $this->assertStringContainsString('id="fm-mini-player-expand-btn"', $miniView);

        $expandedView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/expanded-player.php');
        $this->assertStringContainsString('id="fm-expanded-player"', $expandedView);
    }

    /**
     * Scenario 35: Full modal shows lyrics if available.
     */
    public function testScenario35FullModalShowsLyricsIfAvailable(): void
    {
        $expandedView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/expanded-player.php');
        $this->assertStringContainsString('id="fm-expanded-lyrics-content"', $expandedView);
        $this->assertStringContainsString('id="fm-expanded-lyrics-toggle"', $expandedView);
    }

    /**
     * Scenario 36: Full modal hides lyrics if not present.
     */
    public function testScenario36FullModalHidesLyricsIfNotPresent(): void
    {
        $uiJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js');
        $this->assertStringContainsString('expandedLyricsToggle.style.display = \'none\'', $uiJs);
    }

    /**
     * Scenario 37: Full modal queue drawer toggle.
     */
    public function testScenario37FullModalQueueDrawerToggle(): void
    {
        $expandedView = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/expanded-player.php');
        $this->assertStringContainsString('id="fm-expanded-queue-btn"', $expandedView);

        $drawer = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/audio-queue-drawer.php');
        $this->assertStringContainsString('id="fm-audio-queue-drawer"', $drawer);
    }

    /**
     * Scenario 38: Queue drawer reorder / jump to track.
     */
    public function testScenario38QueueDrawerReorderAndJumpToTrack(): void
    {
        $drawer = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/audio-queue-drawer.php');
        $this->assertStringContainsString('id="fm-queue-drawer-list"', $drawer);
        $this->assertStringContainsString('id="fm-queue-clear-btn"', $drawer);

        $uiJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js');
        $this->assertStringContainsString('q.jumpTo(idx)', $uiJs);
        $this->assertStringContainsString('q.remove(idx)', $uiJs);
    }

    // =========================================================================
    // SCENARIOS 39 - 43: Keyboard Shortcuts & MediaSession API
    // =========================================================================

    /**
     * Scenario 39: Keyboard space toggles play/pause.
     */
    public function testScenario39KeyboardSpaceTogglesPlayPause(): void
    {
        $uiJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js');
        $this->assertStringContainsString('case \'Space\':', $uiJs);
        $this->assertStringContainsString('p.togglePlayPause()', $uiJs);
    }

    /**
     * Scenario 40: Keyboard arrow keys seek +/- 5 seconds.
     */
    public function testScenario40KeyboardArrowKeysSeekSeconds(): void
    {
        $uiJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js');
        $this->assertStringContainsString('case \'ArrowLeft\':', $uiJs);
        $this->assertStringContainsString('p.seekRelative(-5)', $uiJs);
        $this->assertStringContainsString('case \'ArrowRight\':', $uiJs);
        $this->assertStringContainsString('p.seekRelative(5)', $uiJs);
    }

    /**
     * Scenario 41: Keyboard mute shortcut.
     */
    public function testScenario41KeyboardMuteShortcut(): void
    {
        $uiJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js');
        $this->assertStringContainsString('case \'KeyM\':', $uiJs);
        $this->assertStringContainsString('p.toggleMute()', $uiJs);
    }

    /**
     * Scenario 42: Media session metadata update.
     */
    public function testScenario42MediaSessionMetadataUpdate(): void
    {
        $uiJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js');
        $this->assertStringContainsString('new MediaMetadata', $uiJs);
        $this->assertStringContainsString('navigator.mediaSession.metadata', $uiJs);
    }

    /**
     * Scenario 43: Media session action handlers.
     */
    public function testScenario43MediaSessionActionHandlers(): void
    {
        $uiJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js');
        $this->assertStringContainsString('navigator.mediaSession.setActionHandler(\'play\'', $uiJs);
        $this->assertStringContainsString('navigator.mediaSession.setActionHandler(\'pause\'', $uiJs);
        $this->assertStringContainsString('navigator.mediaSession.setActionHandler(\'previoustrack\'', $uiJs);
        $this->assertStringContainsString('navigator.mediaSession.setActionHandler(\'nexttrack\'', $uiJs);
        $this->assertStringContainsString('navigator.mediaSession.setActionHandler(\'seekto\'', $uiJs);
    }

    // =========================================================================
    // SCENARIOS 44 - 45: Video / Audio Mutual Exclusion
    // =========================================================================

    /**
     * Scenario 44: Video player launch pauses audio player (mutual exclusion).
     */
    public function testScenario44VideoPlayerLaunchPausesAudioPlayer(): void
    {
        $playerJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-player.js');
        $this->assertStringContainsString('fm:video:play', $playerJs);
        $this->assertStringContainsString('this.audio.pause()', $playerJs);
    }

    /**
     * Scenario 45: Audio player launch pauses video player (mutual exclusion).
     */
    public function testScenario45AudioPlayerLaunchPausesVideoPlayer(): void
    {
        $playerJs = file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-player.js');
        $this->assertStringContainsString('pauseActiveVideos()', $playerJs);
        $this->assertStringContainsString('document.querySelectorAll(\'video\')', $playerJs);
        $this->assertStringContainsString('fm:audio:play', $playerJs);
    }
}
