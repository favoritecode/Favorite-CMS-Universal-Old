<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Services\MediaDeliveryService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase3Test extends TestCase
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

        // Multimedia plugin tables
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $migration = new CreateFavoriteMultimediaTables($this->db);
        $migration->up();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * 1. Test Series -> Season -> Episode Hierarchy and sorting
     */
    public function testSeriesSeasonEpisodeHierarchyAndOrdering(): void
    {
        $series = Series::create([
            'title'       => 'Cyberpunk Tales',
            'slug'        => 'cyberpunk-tales',
            'status'      => 'published',
            'access_mode' => 'premium',
        ]);

        $season2 = Season::create([
            'series_id'     => $series->id,
            'season_number' => 2,
            'title'         => 'Season 2: Retribution',
            'sort_order'    => 2,
        ]);

        $season1 = Season::create([
            'series_id'     => $series->id,
            'season_number' => 1,
            'title'         => 'Season 1: Neon Dawn',
            'sort_order'    => 1,
        ]);

        $seasons = $series->getSeasons();
        $this->assertCount(2, $seasons);
        $this->assertSame('Season 1: Neon Dawn', $seasons[0]->title);
        $this->assertSame('Season 2: Retribution', $seasons[1]->title);

        // Add episodes to Season 1
        $ep1 = Episode::create([
            'series_id'      => $series->id,
            'season_id'      => $season1->id,
            'episode_number' => 1,
            'title'          => 'Pilot: The Wire',
            'slug'           => 'cyberpunk-tales-s1-e1',
            'access_mode'    => 'public', // Free Pilot override
            'status'         => 'published',
            'sort_order'     => 1,
        ]);

        $ep2 = Episode::create([
            'series_id'      => $series->id,
            'season_id'      => $season1->id,
            'episode_number' => 2,
            'title'          => 'Ghost in the Machine',
            'slug'           => 'cyberpunk-tales-s1-e2',
            'access_mode'    => 'inherit', // Inherits series premium
            'status'         => 'published',
            'sort_order'     => 2,
        ]);

        $episodes = $season1->getEpisodes();
        $this->assertCount(2, $episodes);
        $this->assertSame('Pilot: The Wire', $episodes[0]->title);
        $this->assertSame('Ghost in the Machine', $episodes[1]->title);

        // Verify parent series link from episode
        $this->assertSame($series->id, $ep1->getSeries()?->id);
    }

    /**
     * 2. Test Access Inheritance & Free Pilot Overrides
     * Series: PREMIUM -> Episode 1: PUBLIC (Free Pilot) vs Episode 2: INHERIT (PREMIUM)
     */
    public function testFreePilotAccessOverrideResolution(): void
    {
        $series = Series::create([
            'title'       => 'Master Series',
            'slug'        => 'master-series',
            'status'      => 'published',
            'access_mode' => 'premium',
        ]);

        $season = Season::create([
            'series_id'     => $series->id,
            'season_number' => 1,
            'title'         => 'Season 1',
        ]);

        $freePilot = Episode::create([
            'series_id'      => $series->id,
            'season_id'      => $season->id,
            'episode_number' => 1,
            'title'          => 'Episode 1 (Free Pilot)',
            'slug'           => 'master-ep-1',
            'access_mode'    => 'public', // Overridden to Public
            'status'         => 'published',
        ]);

        $premiumEpisode = Episode::create([
            'series_id'      => $series->id,
            'season_id'      => $season->id,
            'episode_number' => 2,
            'title'          => 'Episode 2 (Paid)',
            'slug'           => 'master-ep-2',
            'access_mode'    => 'inherit', // Inherits Premium from Series
            'status'         => 'published',
        ]);

        // Resolved access mode check
        $this->assertSame('public', $freePilot->getResolvedAccessMode());
        $this->assertSame('premium', $premiumEpisode->getResolvedAccessMode());

        // Episode 1 (Free Pilot) must ALLOW playback for guest (null user)
        $accessPilot = MultimediaAccessService::checkAccess(null, 'episode', (int)$freePilot->id);
        $this->assertSame(MultimediaAccessService::ALLOW, $accessPilot);

        // Episode 2: Guest must LOGIN_REQUIRED before subscription can be checked
        $accessGuest = MultimediaAccessService::checkAccess(null, 'episode', (int)$premiumEpisode->id);
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, $accessGuest);

        // Episode 2: Standard logged-in user without subscription must get PREMIUM_REQUIRED
        $regularUser = new User(['id' => 10, 'role' => 'user']);
        $accessRegular = MultimediaAccessService::checkAccess($regularUser, 'episode', (int)$premiumEpisode->id);
        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, $accessRegular);
    }

    /**
     * 3. Test MediaSource and Subtitle getContentTitle() lookup
     */
    public function testMediaSourceAndSubtitleHumanReadableContentTitles(): void
    {
        $movie = Movie::create([
            'title'  => 'Blade Runner 2049',
            'slug'   => 'blade-runner-2049',
            'status' => 'published',
        ]);

        $series = Series::create([
            'title'  => 'Westworld',
            'slug'   => 'westworld',
            'status' => 'published',
        ]);

        $season = Season::create([
            'series_id'     => $series->id,
            'season_number' => 1,
            'title'         => 'Season 1',
        ]);

        $episode = Episode::create([
            'series_id'      => $series->id,
            'season_id'      => $season->id,
            'episode_number' => 1,
            'title'          => 'The Original',
            'slug'           => 'the-original',
            'status'         => 'published',
        ]);

        $song = Song::create([
            'title'  => 'Midnight City',
            'slug'   => 'midnight-city',
            'status' => 'published',
        ]);

        // Media sources
        $srcMovie = MediaSource::create([
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'source_mode'  => 'direct',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/movie.mp4',
            'label'        => '1080p',
        ]);

        $srcEp = MediaSource::create([
            'content_type' => 'episode',
            'content_id'   => $episode->id,
            'source_mode'  => 'direct',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/ep1.mp4',
            'label'        => '720p',
        ]);

        $srcSong = MediaSource::create([
            'content_type' => 'song',
            'content_id'   => $song->id,
            'source_mode'  => 'direct',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/audio.mp3',
            'label'        => '320kbps',
        ]);

        $srcOrphan = new MediaSource(['content_type' => 'movie', 'content_id' => 9999]);

        $this->assertSame('Blade Runner 2049', $srcMovie->getContentTitle());
        $this->assertSame('Westworld - The Original', $srcEp->getContentTitle());
        $this->assertSame('Midnight City', $srcSong->getContentTitle());
        $this->assertSame('#9999', $srcOrphan->getContentTitle());

        // Subtitles
        $subMovie = Subtitle::create([
            'content_type' => 'movie',
            'content_id'   => $movie->id,
            'language'     => 'en',
            'label'        => 'English',
            'file_or_url'  => 'storage/subtitles/en.vtt',
            'format'       => 'vtt',
        ]);

        $subEp = Subtitle::create([
            'content_type' => 'episode',
            'content_id'   => $episode->id,
            'language'     => 'es',
            'label'        => 'Spanish',
            'file_or_url'  => 'storage/subtitles/es.vtt',
            'format'       => 'vtt',
        ]);

        $this->assertSame('Blade Runner 2049', $subMovie->getContentTitle());
        $this->assertSame('Westworld - The Original', $subEp->getContentTitle());
    }

    /**
     * 4. Test Playlist Dual-Access & Track Selection preservation
     */
    public function testPlaylistSongOrderingAndDualAccess(): void
    {
        $songPublic = Song::create([
            'title'       => 'Public Anthem',
            'slug'        => 'public-anthem',
            'access_mode' => 'public',
            'status'      => 'published',
        ]);

        $songPremium = Song::create([
            'title'       => 'VIP Exclusive Track',
            'slug'        => 'vip-exclusive-track',
            'access_mode' => 'premium',
            'status'      => 'published',
        ]);

        $playlist = Playlist::create([
            'title'       => 'Hybrid Vibes',
            'slug'        => 'hybrid-vibes',
            'access_mode' => 'public', // Anyone can see the playlist
            'status'      => 'published',
        ]);

        // Add songs
        $playlist->addSong((int)$songPublic->id, 1);
        $playlist->addSong((int)$songPremium->id, 2);

        $tracks = $playlist->getSongs();
        $this->assertCount(2, $tracks);
        $this->assertSame('Public Anthem', $tracks[0]->title);
        $this->assertSame('public', $tracks[0]->access_mode);
        $this->assertSame('VIP Exclusive Track', $tracks[1]->title);
        $this->assertSame('premium', $tracks[1]->access_mode);

        // Playlist visibility is public
        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess(null, 'playlist', (int)$playlist->id));

        // Public track in playlist allows playback
        $this->assertSame(MultimediaAccessService::ALLOW, MultimediaAccessService::checkAccess(null, 'song', (int)$songPublic->id));

        // Premium track inside public playlist requires LOGIN_REQUIRED for guest
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, MultimediaAccessService::checkAccess(null, 'song', (int)$songPremium->id));

        // Premium track inside public playlist requires PREMIUM_REQUIRED for logged-in non-subscriber
        $regularUser = new User(['id' => 10, 'role' => 'user']);
        $this->assertSame(MultimediaAccessService::PREMIUM_REQUIRED, MultimediaAccessService::checkAccess($regularUser, 'song', (int)$songPremium->id));
    }

    /**
     * 5. Test MediaDeliveryService Local Path Traversal Protection
     */
    public function testLocalPathTraversalProtection(): void
    {
        // Traversal attempt with ../../../
        $subTraversal = Subtitle::create([
            'content_type' => 'movie',
            'content_id'   => 1,
            'language'     => 'en',
            'label'        => 'Malicious Track',
            'file_or_url'  => '../../../../../../etc/passwd',
            'format'       => 'vtt',
        ]);

        $response = MediaDeliveryService::deliverSubtitle($subTraversal);
        $this->assertSame(200, $response->getStatusCode());
        $content = $response->getContent();

        // Must reject path traversal and never leak host file contents
        $this->assertStringContainsString('Subtitle file not found', $content);
        $this->assertStringNotContainsString('root:', $content);
    }

    /**
     * 6. Test Frontend Search Grouping
     */
    public function testFrontendSearchGrouping(): void
    {
        Movie::create(['title' => 'Galactic War', 'slug' => 'galactic-war', 'status' => 'published']);
        Series::create(['title' => 'Galactic Chronicles', 'slug' => 'galactic-chronicles', 'status' => 'published']);
        Song::create(['title' => 'Galactic Theme Song', 'slug' => 'galactic-theme-song', 'status' => 'published']);

        $controller = new MultimediaFrontendController($this->app);
        $request = new Request(['q' => 'Galactic'], [], [], [], [], ['REQUEST_METHOD' => 'GET']);

        $response = $controller->search($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertStringContainsString('Galactic War', $content);
        $this->assertStringContainsString('Galactic Chronicles', $content);
        $this->assertStringContainsString('Galactic Theme Song', $content);
        $this->assertStringContainsString('fav-mm-search-group', $content);
    }

    /**
     * 7. Test Admin Dashboard Recent Playlists Inclusion
     */
    public function testAdminDashboardPassesPlaylists(): void
    {
        // Admin user in session
        $_SESSION['user_id'] = 1;
        $_SESSION['user_role'] = 'admin';

        Playlist::create(['title' => 'Dashboard Test Playlist', 'slug' => 'dashboard-test-playlist', 'status' => 'published']);

        $controller = new MultimediaAdminController($this->app);
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia']);

        $result = $controller->dashboard($request);
        $content = is_string($result) ? $result : $result->getContent();

        $this->assertStringContainsString('Dashboard Test Playlist', $content);
        $this->assertStringContainsString('fav-admin-subnav', $content);
    }
}
