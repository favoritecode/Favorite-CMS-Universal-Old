<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Plugins\PluginManager;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Integrations\FavoritePayAdapter;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\PlaylistItem;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaTest extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        // Load plugin autoload
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $app = new Application();
        $this->pluginManager = new PluginManager($app);

        // In-memory SQLite PDO for isolated test execution
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

        // Create settings table for access/download tests
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT,
                setting_key TEXT,
                value TEXT,
                type TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ");
        Setting::clearCache();

        // Run migrations
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $migration = new CreateFavoriteMultimediaTables($this->db);
        $migration->up();
    }

    public function testPluginManifestAndMetadata(): void
    {
        $meta = $this->pluginManager->getPluginMetadata('favorite-multimedia');

        $this->assertSame('favorite-multimedia', $meta['id']);
        $this->assertSame('Favorite Multimedia', $meta['name']);
        $this->assertSame('1.0.8', $meta['version']);
        $this->assertSame('plugin.php', $meta['entry_point']);
        $this->assertTrue($meta['valid'], 'Plugin manifest must be recognized as valid by Core PluginManager');
        $this->assertTrue($meta['compatible'], 'Plugin must be compatible with PHP environment');
        $this->assertEmpty($meta['errors']);

        $validation = $this->pluginManager->validatePlugin('favorite-multimedia');
        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);
    }

    public function testDatabaseMigrationCreatesAndDropsAllTables(): void
    {
        $expectedTables = [
            'multimedia_genres',
            'multimedia_artists',
            'multimedia_albums',
            'multimedia_movies',
            'multimedia_series',
            'multimedia_seasons',
            'multimedia_episodes',
            'multimedia_songs',
            'multimedia_playlists',
            'multimedia_playlist_items',
            'multimedia_content_genres',
            'multimedia_sources',
            'multimedia_subtitles',
            'multimedia_analytics',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(
                $this->db->tableExists($table),
                "Failed asserting table '{$table}' exists after migration up."
            );
        }

        // Test migration rollback
        $migration = new CreateFavoriteMultimediaTables($this->db);
        $migration->down();

        foreach ($expectedTables as $table) {
            $this->assertFalse(
                $this->db->tableExists($table),
                "Failed asserting table '{$table}' was dropped by migration down."
            );
        }

        // Restore schema for remaining tests
        $migration->up();
    }

    public function testMediaSourceResolverDetection(): void
    {
        // 1. MP4 direct video
        $mp4 = MediaSourceResolver::resolve('https://cdn.example.com/videos/trailer.mp4');
        $this->assertTrue($mp4['valid']);
        $this->assertSame('video', $mp4['source_type']);
        $this->assertSame('video', $mp4['player_type']);
        $this->assertSame('video/mp4', $mp4['mime_type']);
        $this->assertTrue($mp4['is_downloadable']);

        // 2. WebM direct video
        $webm = MediaSourceResolver::resolve('https://cdn.example.com/videos/movie.webm');
        $this->assertTrue($webm['valid']);
        $this->assertSame('video', $webm['source_type']);
        $this->assertSame('video', $webm['player_type']);
        $this->assertSame('video/webm', $webm['mime_type']);
        $this->assertTrue($webm['is_downloadable']);

        // 3. M3U8 HLS stream
        $hls = MediaSourceResolver::resolve('https://streaming.example.com/live/master.m3u8');
        $this->assertTrue($hls['valid']);
        $this->assertSame('hls', $hls['source_type']);
        $this->assertSame('video', $hls['player_type']);
        $this->assertSame('application/x-mpegURL', $hls['mime_type']);
        $this->assertFalse($hls['is_downloadable'], 'HLS M3U8 streams must not be flagged as direct downloadable files');

        // 4. MP3 audio
        $mp3 = MediaSourceResolver::resolve('https://cdn.example.com/audio/track01.mp3');
        $this->assertTrue($mp3['valid']);
        $this->assertSame('audio', $mp3['source_type']);
        $this->assertSame('audio', $mp3['player_type']);
        $this->assertSame('audio/mpeg', $mp3['mime_type']);
        $this->assertTrue($mp3['is_downloadable']);

        // 5. M4A audio
        $m4a = MediaSourceResolver::resolve('https://cdn.example.com/audio/song.m4a');
        $this->assertTrue($m4a['valid']);
        $this->assertSame('audio', $m4a['source_type']);
        $this->assertSame('audio', $m4a['player_type']);
        $this->assertSame('audio/mp4', $m4a['mime_type']);

        // 6. YouTube embed
        $yt = MediaSourceResolver::resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertTrue($yt['valid']);
        $this->assertSame('embed', $yt['source_type']);
        $this->assertSame('embed', $yt['player_type']);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $yt['playable_url']);
        $this->assertFalse($yt['is_downloadable'], 'Embed sources must not be downloadable');

        // 7. Vimeo embed
        $vimeo = MediaSourceResolver::resolve('https://vimeo.com/76979871');
        $this->assertTrue($vimeo['valid']);
        $this->assertSame('embed', $vimeo['source_type']);
        $this->assertSame('embed', $vimeo['player_type']);
        $this->assertStringContainsString('player.vimeo.com/video/76979871', $vimeo['playable_url']);

        // 8. Manual override
        $override = MediaSourceResolver::resolve('https://cdn.example.com/stream-endpoint', 'url', 'video');
        $this->assertTrue($override['valid']);
        $this->assertSame('video', $override['player_type']);
    }

    public function testMediaSourceResolverSsrfProtection(): void
    {
        // Loopback IP
        $loopback = MediaSourceResolver::validateUrlSecurity('http://127.0.0.1/secret.mp4');
        $this->assertFalse($loopback['safe']);

        // Hostname localhost
        $localhost = MediaSourceResolver::validateUrlSecurity('http://localhost:8000/media.mp4');
        $this->assertFalse($localhost['safe']);

        // Private IP 10.x.x.x
        $private10 = MediaSourceResolver::validateUrlSecurity('http://10.0.0.1/video.mp4');
        $this->assertFalse($private10['safe']);

        // Private IP 192.168.x.x
        $private192 = MediaSourceResolver::validateUrlSecurity('http://192.168.1.100/video.mp4');
        $this->assertFalse($private192['safe']);

        // Cloud metadata address 169.254.169.254
        $meta = MediaSourceResolver::validateUrlSecurity('http://169.254.169.254/latest/meta-data/');
        $this->assertFalse($meta['safe']);

        // Dangerous schemes
        $file = MediaSourceResolver::validateUrlSecurity('file:///etc/passwd');
        $this->assertFalse($file['safe']);

        $js = MediaSourceResolver::validateUrlSecurity('javascript:alert(1)');
        $this->assertFalse($js['safe']);

        // Legitimate public HTTPS URL
        $public = MediaSourceResolver::validateUrlSecurity('https://example.com/media/sample.mp4');
        $this->assertTrue($public['safe']);
    }

    public function testMultimediaAccessServiceLevels(): void
    {
        $publicMovie = new Movie([
            'id' => 1,
            'title' => 'Open Movie',
            'status' => 'published',
            'access_mode' => 'public',
        ]);

        $loginMovie = new Movie([
            'id' => 2,
            'title' => 'Members Only Movie',
            'status' => 'published',
            'access_mode' => 'login',
        ]);

        $premiumMovie = new Movie([
            'id' => 3,
            'title' => 'VIP Movie',
            'status' => 'published',
            'access_mode' => 'premium',
        ]);

        // Unauthenticated guest user (null)
        $this->assertSame(
            MultimediaAccessService::ALLOW,
            MultimediaAccessService::checkAccess(null, 'movie', $publicMovie)
        );
        $this->assertSame(
            MultimediaAccessService::LOGIN_REQUIRED,
            MultimediaAccessService::checkAccess(null, 'movie', $loginMovie)
        );
        $this->assertSame(
            MultimediaAccessService::LOGIN_REQUIRED,
            MultimediaAccessService::checkAccess(null, 'movie', $premiumMovie)
        );

        // Regular logged-in user without premium entitlement
        $regularUser = $this->createMockUser(10, ['member']);
        $this->assertSame(
            MultimediaAccessService::ALLOW,
            MultimediaAccessService::checkAccess($regularUser, 'movie', $publicMovie)
        );
        $this->assertSame(
            MultimediaAccessService::ALLOW,
            MultimediaAccessService::checkAccess($regularUser, 'movie', $loginMovie)
        );
        $this->assertSame(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($regularUser, 'movie', $premiumMovie)
        );

        // In locked matrix: Role alone NEVER grants Premium playback (no bypass for Admin or Super-admin without entitlement)
        $adminUser = $this->createMockUser(1, ['admin']);
        $this->assertSame(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($adminUser, 'movie', $premiumMovie)
        );

        // Super-admin user without entitlement receives PREMIUM_REQUIRED
        $superAdmin = $this->createMockUser(2, ['super-admin']);
        $this->assertSame(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($superAdmin, 'movie', $premiumMovie)
        );
    }

    public function testSeriesSeasonEpisodeAccessInheritanceAndOverride(): void
    {
        // 1. Premium Series
        $series = Series::create([
            'title' => 'Premium Drama Series',
            'slug' => 'premium-drama-series',
            'status' => 'published',
            'access_mode' => 'premium',
        ]);

        $season = Season::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'title' => 'Season 1',
        ]);

        // Episode that inherits (should inherit 'premium' from series)
        $inheritEpisode = Episode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'episode_number' => 2,
            'title' => 'Episode 2 (VIP)',
            'slug' => 'episode-2-vip',
            'status' => 'published',
            'access_mode' => 'inherit',
        ]);

        // Episode that overrides to 'public' (e.g. pilot / free teaser episode)
        $freePilotEpisode = Episode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'episode_number' => 1,
            'title' => 'Episode 1 (Free Pilot)',
            'slug' => 'episode-1-free-pilot',
            'status' => 'published',
            'access_mode' => 'public',
        ]);

        // Episode that overrides to 'premium' even if series was public
        $publicSeries = Series::create([
            'title' => 'Public Documentary',
            'slug' => 'public-documentary',
            'status' => 'published',
            'access_mode' => 'public',
        ]);

        $specialEpisode = Episode::create([
            'series_id' => $publicSeries->id,
            'season_id' => $season->id,
            'episode_number' => 99,
            'title' => 'Behind the Scenes VIP',
            'slug' => 'behind-the-scenes-vip',
            'status' => 'published',
            'access_mode' => 'premium',
        ]);

        // A guest accessing the inherited episode -> LOGIN_REQUIRED
        $this->assertSame(
            MultimediaAccessService::LOGIN_REQUIRED,
            MultimediaAccessService::checkAccess(null, 'episode', $inheritEpisode)
        );

        // A regular user accessing inherited episode -> PREMIUM_REQUIRED
        $regularUser = $this->createMockUser(5, ['subscriber']);
        $this->assertSame(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($regularUser, 'episode', $inheritEpisode)
        );

        // A guest accessing the free pilot episode -> ALLOW (override honored!)
        $this->assertSame(
            MultimediaAccessService::ALLOW,
            MultimediaAccessService::checkAccess(null, 'episode', $freePilotEpisode)
        );

        // A regular user accessing special episode on public series -> PREMIUM_REQUIRED (override honored!)
        $this->assertSame(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkAccess($regularUser, 'episode', $specialEpisode)
        );
    }

    public function testPlaylistTrackAccessEnforcement(): void
    {
        $publicPlaylist = new Playlist([
            'id' => 1,
            'title' => 'Free Hits',
            'status' => 'published',
            'access_mode' => 'public',
        ]);

        $freeSong = new Song([
            'id' => 10,
            'title' => 'Free Track',
            'status' => 'published',
            'access_mode' => 'public',
        ]);

        $premiumSong = new Song([
            'id' => 11,
            'title' => 'Premium Track',
            'status' => 'published',
            'access_mode' => 'premium',
        ]);

        $regularUser = $this->createMockUser(7, ['member']);

        // Free song in free playlist -> ALLOW
        $this->assertSame(
            MultimediaAccessService::ALLOW,
            MultimediaAccessService::checkPlaylistTrackAccess($regularUser, $publicPlaylist, $freeSong)
        );

        // Premium song in free playlist -> PREMIUM_REQUIRED
        $this->assertSame(
            MultimediaAccessService::PREMIUM_REQUIRED,
            MultimediaAccessService::checkPlaylistTrackAccess($regularUser, $publicPlaylist, $premiumSong)
        );

        // Free song in login-required playlist -> guest denied
        $loginPlaylist = new Playlist([
            'id' => 2,
            'title' => 'Club Mix',
            'status' => 'published',
            'access_mode' => 'login',
        ]);
        $this->assertSame(
            MultimediaAccessService::LOGIN_REQUIRED,
            MultimediaAccessService::checkPlaylistTrackAccess(null, $loginPlaylist, $freeSong)
        );
    }

    public function testDownloadPermissionsSeparation(): void
    {
        $publicMovie = new Movie([
            'id' => 50,
            'title' => 'Free Download Movie',
            'status' => 'published',
            'access_mode' => 'public',
            'download_policy' => 'allow',
        ]);

        $noDownloadMovie = new Movie([
            'id' => 51,
            'title' => 'Streaming Only Movie',
            'status' => 'published',
            'access_mode' => 'public',
            'download_policy' => 'deny',
        ]);

        $directSource = new MediaSource([
            'id' => 1,
            'content_type' => 'movie',
            'content_id' => 50,
            'source_mode' => 'url',
            'source_type' => 'video',
            'url_or_path' => 'https://cdn.example.com/film.mp4',
            'allow_download' => 'inherit',
        ]);

        $hlsSource = new MediaSource([
            'id' => 2,
            'content_type' => 'movie',
            'content_id' => 50,
            'source_mode' => 'url',
            'source_type' => 'hls',
            'url_or_path' => 'https://cdn.example.com/playlist.m3u8',
            'allow_download' => 'allow',
        ]);

        // 1. Direct MP4 source on download-allowed movie -> allowed
        $res1 = MultimediaAccessService::checkDownloadPermission(null, 'movie', $publicMovie, $directSource);
        $this->assertTrue($res1['allowed']);

        // 2. Denied policy movie -> rejected even for public
        $res2 = MultimediaAccessService::checkDownloadPermission(null, 'movie', $noDownloadMovie, $directSource);
        $this->assertFalse($res2['allowed']);
        $this->assertStringContainsString('disabled', strtolower($res2['reason']));

        // 3. M3U8 source -> rejected by streaming safeguard
        $res3 = MultimediaAccessService::checkDownloadPermission(null, 'movie', $publicMovie, $hlsSource);
        $this->assertFalse($res3['allowed']);
        $this->assertStringContainsString('m3u8', strtolower($res3['reason']));
    }

    public function testAdaptersGracefulDegradation(): void
    {
        // When Favorite Digital is not installed, adapter returns false and doesn't crash
        $this->assertIsBool(FavoriteDigitalAdapter::isAvailable());
        $regularUser = $this->createMockUser(88, ['member']);
        $this->assertFalse(FavoriteDigitalAdapter::userHasEntitlement($regularUser, 'movie', 1));

        // When Favorite Pay is checked, adapter returns valid contract
        $this->assertIsBool(FavoritePayAdapter::isAvailable());
        $intent = FavoritePayAdapter::createPaymentIntent('multimedia_test_ref', 500);
        // Returns null or object gracefully without throwing exceptions
        $this->assertTrue($intent === null || is_object($intent));
    }

    public function testPlaylistAndGenreRelationships(): void
    {
        // 1. Genre creation & retrieval
        $genre = new Genre([
            'name' => 'Sci-Fi & Action',
            'slug' => 'sci-fi-action',
            'description' => 'Science fiction and high action',
        ]);
        $genre->save();
        $this->assertGreaterThan(0, $genre->id);

        $foundGenre = Genre::findBySlug('sci-fi-action');
        $this->assertNotNull($foundGenre);
        $this->assertSame('Sci-Fi & Action', $foundGenre->name);

        // 2. Playlist and PlaylistItem
        $playlist = new Playlist([
            'title' => 'Top 10 synthwave',
            'slug' => 'top-10-synthwave',
            'access_mode' => 'public',
            'status' => 'published',
        ]);
        $playlist->save();

        $song = new Song([
            'title' => 'Nightcall Synth',
            'slug' => 'nightcall-synth',
            'duration' => 245,
            'access_mode' => 'public',
            'status' => 'published',
        ]);
        $song->save();

        $added = $playlist->addSong((int)$song->id, 1);
        $this->assertTrue($added);

        $songs = $playlist->getSongs();
        $this->assertCount(1, $songs);
        $this->assertSame((int)$song->id, (int)$songs[0]->id);
        $this->assertSame('Nightcall Synth', $songs[0]->title);
    }

    private function createMockUser(int $id, array $roles = []): User
    {
        return new class($id, $roles) extends User {
            private int $mockId;
            private array $mockRoles;

            public function __construct(int $id, array $roles)
            {
                $this->mockId = $id;
                $this->mockRoles = $roles;
                parent::__construct(['id' => $id, 'username' => 'testuser_' . $id]);
            }

            public function hasRole(string $roleSlug): bool
            {
                return in_array($roleSlug, $this->mockRoles, true);
            }

            public function isBanned(): bool
            {
                return false;
            }
        };
    }
}
