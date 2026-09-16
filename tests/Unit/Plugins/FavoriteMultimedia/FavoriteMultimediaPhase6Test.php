<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use CreateFavoriteMultimediaTables;
use CreateMultimediaUserLibraryTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Favorite;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\MultimediaDiscoveryService;
use PDO;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaPhase6Test extends TestCase
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
                is_public INTEGER DEFAULT 0,
                created_at TEXT,
                updated_at TEXT
            );
        ");
        Setting::clearCache();

        // Run migrations 001 and 002
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/001_create_favorite_multimedia_tables.php';
        $m1 = new CreateFavoriteMultimediaTables($this->db);
        $m1->up();

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/002_create_multimedia_user_library_tables.php';
        $m2 = new CreateMultimediaUserLibraryTables($this->db);
        $m2->up();

        $_SESSION = [];
    }

    private function authenticateUser(int $id = 1, string $role = 'user'): User
    {
        $this->pdo->exec("INSERT OR IGNORE INTO users (id, username, email, password, role) VALUES ({$id}, 'user{$id}', 'user{$id}@example.com', 'hash', '{$role}')");
        $_SESSION['auth_user_id'] = $id;
        $u = User::find($id);
        $this->assertNotNull($u);
        return $u;
    }

    private function createGenre(string $name, string $slug): Genre
    {
        $this->db->insert('multimedia_genres', ['name' => $name, 'slug' => $slug]);
        return Genre::findBySlug($slug);
    }

    private function createMovie(string $title, string $slug, array $genreIds = [], string $access = 'public', string $status = 'published', int $views = 0, ?string $director = null): Movie
    {
        $id = $this->db->insert('multimedia_movies', [
            'title'       => $title,
            'slug'        => $slug,
            'access_mode' => $access,
            'status'      => $status,
            'views_count' => $views,
            'director'    => $director,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        Genre::syncForContent('movie', (int)$id, $genreIds);
        return Movie::find((int)$id);
    }

    private function createSeries(string $title, string $slug, array $genreIds = [], string $access = 'public', string $status = 'published', int $views = 0): Series
    {
        $id = $this->db->insert('multimedia_series', [
            'title'       => $title,
            'slug'        => $slug,
            'access_mode' => $access,
            'status'      => $status,
            'views_count' => $views,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        Genre::syncForContent('series', (int)$id, $genreIds);
        return Series::find((int)$id);
    }

    private function createSong(string $title, string $slug, ?int $artistId = null, ?int $albumId = null, array $genreIds = [], int $plays = 0, string $access = 'public', string $status = 'published'): Song
    {
        $id = $this->db->insert('multimedia_songs', [
            'title'       => $title,
            'slug'        => $slug,
            'artist_id'   => $artistId,
            'album_id'    => $albumId,
            'plays_count' => $plays,
            'access_mode' => $access,
            'status'      => $status,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        Genre::syncForContent('song', (int)$id, $genreIds);
        return Song::find((int)$id);
    }

    // 1. Related Movies: Ranks by shared genres and excludes self
    public function test_related_movies_ranks_by_shared_genres_and_excludes_self(): void
    {
        $action = $this->createGenre('Action', 'action');
        $scifi  = $this->createGenre('Sci-Fi', 'sci-fi');
        $drama  = $this->createGenre('Drama', 'drama');

        // Target: Inception (Action + Sci-Fi, Christopher Nolan)
        $target = $this->createMovie('Inception', 'inception', [(int)$action->id, (int)$scifi->id], 'public', 'published', 100, 'Christopher Nolan');

        // Candidate 1: Interstellar (Action + Sci-Fi, Christopher Nolan) -> 2 shared genres + director bonus
        $c1 = $this->createMovie('Interstellar', 'interstellar', [(int)$action->id, (int)$scifi->id], 'public', 'published', 50, 'Christopher Nolan');

        // Candidate 2: The Matrix (Action + Sci-Fi, Wachowskis) -> 2 shared genres, different director
        $c2 = $this->createMovie('The Matrix', 'the-matrix', [(int)$action->id, (int)$scifi->id], 'public', 'published', 50, 'Wachowskis');

        // Candidate 3: Die Hard (Action only) -> 1 shared genre
        $c3 = $this->createMovie('Die Hard', 'die-hard', [(int)$action->id], 'public', 'published', 20, 'John McTiernan');

        // Candidate 4: The Notebook (Drama only) -> 0 shared genres
        $c4 = $this->createMovie('The Notebook', 'the-notebook', [(int)$drama->id], 'public', 'published', 10, 'Nick Cassavetes');

        $related = MultimediaDiscoveryService::getRelatedMovies($target, 4);

        $this->assertNotEmpty($related);
        $ids = array_map(fn($r) => $r['id'], $related);

        // Self must NOT be included
        $this->assertNotContains((int)$target->id, $ids);

        // Interstellar should rank first due to director match + 2 shared genres
        $this->assertEquals((int)$c1->id, $related[0]['id']);

        // The Matrix should rank second (2 shared genres)
        $this->assertEquals((int)$c2->id, $related[1]['id']);

        // Die Hard should rank ahead of The Notebook
        $this->assertEquals((int)$c3->id, $related[2]['id']);
    }

    // 2. Related Series: Preserves content-type semantics
    public function test_related_series_preserves_content_type_semantics(): void
    {
        $thriller = $this->createGenre('Thriller', 'thriller');
        $s1 = $this->createSeries('Breaking Bad', 'breaking-bad', [(int)$thriller->id]);
        $s2 = $this->createSeries('Better Call Saul', 'better-call-saul', [(int)$thriller->id]);
        $s3 = $this->createSeries('Ozark', 'ozark', [(int)$thriller->id]);

        $related = MultimediaDiscoveryService::getRelatedSeries($s1, 3);
        $this->assertNotEmpty($related);

        foreach ($related as $item) {
            $this->assertEquals('series', $item['content_type']);
            $this->assertNotEquals((int)$s1->id, $item['id']);
        }
    }

    // 3. Related Songs: Prioritizes same artist and album
    public function test_related_songs_prioritizes_same_artist_and_album(): void
    {
        $this->db->insert('multimedia_artists', ['name' => 'Pink Floyd', 'slug' => 'pink-floyd']);
        $artist = Artist::findBySlug('pink-floyd');

        $this->db->insert('multimedia_albums', ['artist_id' => $artist->id, 'title' => 'The Wall', 'slug' => 'the-wall']);
        $album = Album::findBySlug('the-wall');

        $rock = $this->createGenre('Rock', 'rock');

        $targetSong = $this->createSong('Comfortably Numb', 'comfortably-numb', (int)$artist->id, (int)$album->id, [(int)$rock->id]);
        $sameAlbumSong = $this->createSong('Hey You', 'hey-you', (int)$artist->id, (int)$album->id, [(int)$rock->id]);
        $sameArtistSong = $this->createSong('Time', 'time', (int)$artist->id, null, [(int)$rock->id]);
        $otherSong = $this->createSong('Stairway to Heaven', 'stairway', 99, null, [(int)$rock->id]);

        $related = MultimediaDiscoveryService::getRelatedSongs($targetSong, 3);

        $this->assertNotEmpty($related);
        $ids = array_map(fn($r) => $r['id'], $related);

        $this->assertNotContains((int)$targetSong->id, $ids);
        // Same album & artist song must rank #1
        $this->assertEquals((int)$sameAlbumSong->id, $related[0]['id']);
        // Same artist must rank #2
        $this->assertEquals((int)$sameArtistSong->id, $related[1]['id']);
    }

    // 4. Trending: Calculates recent window with manipulation resistance
    public function test_trending_calculates_recent_window_with_manipulation_resistance(): void
    {
        $m1 = $this->createMovie('Trending Film 1', 'trending-1');
        $m2 = $this->createMovie('Trending Film 2', 'trending-2');

        $now = date('Y-m-d H:i:s');
        $oldDate = date('Y-m-d H:i:s', strtotime('-15 days'));

        // Old engagement (15 days ago) outside 7-day window
        $this->db->insert('multimedia_analytics', [
            'content_type' => 'movie',
            'content_id'   => $m2->id,
            'event_type'   => 'play',
            'user_id'      => 10,
            'ip_hash'      => 'old_ip',
            'created_at'   => $oldDate,
        ]);

        // Recent engagement for m1 by two distinct users
        $this->db->insert('multimedia_analytics', [
            'content_type' => 'movie',
            'content_id'   => $m1->id,
            'event_type'   => 'play',
            'user_id'      => 1,
            'ip_hash'      => 'hash1',
            'created_at'   => $now,
        ]);
        $this->db->insert('multimedia_analytics', [
            'content_type' => 'movie',
            'content_id'   => $m1->id,
            'event_type'   => 'play',
            'user_id'      => 2,
            'ip_hash'      => 'hash2',
            'created_at'   => $now,
        ]);

        // Malicious spam: 10 play events from the same IP on m2
        for ($i = 0; $i < 10; $i++) {
            $this->db->insert('multimedia_analytics', [
                'content_type' => 'movie',
                'content_id'   => $m2->id,
                'event_type'   => 'play',
                'user_id'      => null,
                'ip_hash'      => 'spammer_ip',
                'created_at'   => $now,
            ]);
        }

        // Under DISTINCT actor counting, m1 has 2 distinct plays (score = 6.0), m2 has 1 distinct play from spammer (score = 3.0)
        $trending = MultimediaDiscoveryService::getTrending(2, 'movie');

        $this->assertNotEmpty($trending);
        $this->assertEquals((int)$m1->id, $trending[0]['id'], 'Content with multiple distinct actors must outrank single-actor spammed content');
    }

    // 5. Popular Content: Aggregates views, plays, and favorites
    public function test_popular_content_aggregates_views_plays_and_favorites(): void
    {
        $m1 = $this->createMovie('High Views Movie', 'high-views', [], 'public', 'published', 100);
        $m2 = $this->createMovie('High Favorites Movie', 'high-favs', [], 'public', 'published', 10);

        // Add 50 favorites to m2 -> 10 + (50 * 3) = 160 pts, outranking m1's 100 pts
        for ($u = 1; $u <= 50; $u++) {
            Favorite::addFavorite($u, 'movie', (int)$m2->id);
        }

        $popular = MultimediaDiscoveryService::getPopular(2, 'movie');
        $this->assertCount(2, $popular);
        $this->assertEquals((int)$m2->id, $popular[0]['id']);
        $this->assertEquals((int)$m1->id, $popular[1]['id']);
    }

    // 6. Recently Added: Orders by creation timestamp
    public function test_recently_added_orders_by_publication_date(): void
    {
        $m1 = $this->createMovie('Older Film', 'older-film');
        sleep(1);
        $m2 = $this->createMovie('Newer Film', 'newer-film');

        $recent = MultimediaDiscoveryService::getRecentlyAdded(2, 'movie');
        $this->assertEquals((int)$m2->id, $recent[0]['id']);
        $this->assertEquals((int)$m1->id, $recent[1]['id']);
    }

    // 7. "Because You Watched": Requires meaningful playback (>=30s or >=5%)
    public function test_because_you_watched_requires_meaningful_playback(): void
    {
        $user = $this->authenticateUser(1);
        $action = $this->createGenre('Action', 'action');

        $mBounce = $this->createMovie('Bounced Movie', 'bounced-movie', [(int)$action->id]);
        $mMeaningful = $this->createMovie('Engaged Movie', 'engaged-movie', [(int)$action->id]);
        $mCandidate = $this->createMovie('Candidate Movie', 'candidate-movie', [(int)$action->id]);

        // Accidental click: watched only 10s (1%) -> should NOT trigger recommendation
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$mBounce->id, 10.0, 1000.0);

        $rec1 = MultimediaDiscoveryService::getBecauseYouWatched($user, 4);
        $this->assertEmpty($rec1, 'Short bounces (<30s and <5%) must not trigger recommendations');

        // Meaningful playback: watched 120s (12%)
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$mMeaningful->id, 120.0, 1000.0);

        $rec2 = MultimediaDiscoveryService::getBecauseYouWatched($user, 4);
        $this->assertNotEmpty($rec2);
        $this->assertEquals('Engaged Movie', $rec2['anchor']['title']);
        $candidateIds = array_map(fn($it) => $it['id'], $rec2['items']);
        $this->assertContains((int)$mCandidate->id, $candidateIds);
    }

    // 8. "Because You Liked": Leverages user favorites
    public function test_because_you_liked_uses_favorites_preference_signal(): void
    {
        $user = $this->authenticateUser(1);
        $scifi = $this->createGenre('Sci-Fi', 'scifi');

        $likedMovie = $this->createMovie('Dune', 'dune', [(int)$scifi->id]);
        $recMovie = $this->createMovie('Blade Runner 2049', 'blade-runner', [(int)$scifi->id]);

        Favorite::addFavorite((int)$user->id, 'movie', (int)$likedMovie->id);

        $rec = MultimediaDiscoveryService::getBecauseYouLiked($user, 4);
        $this->assertNotEmpty($rec);
        $this->assertEquals('Dune', $rec['anchor']['title']);
        $recIds = array_map(fn($it) => $it['id'], $rec['items']);
        $this->assertContains((int)$recMovie->id, $recIds);
    }

    // 9. Completed Content is excluded from primary discovery
    public function test_completed_content_is_excluded_from_primary_recommendations(): void
    {
        $user = $this->authenticateUser(1);
        $comedy = $this->createGenre('Comedy', 'comedy');

        $favMovie = $this->createMovie('Superbad', 'superbad', [(int)$comedy->id]);
        $completedMovie = $this->createMovie('Step Brothers', 'step-brothers', [(int)$comedy->id]);
        $unwatchedMovie = $this->createMovie('Booksmart', 'booksmart', [(int)$comedy->id]);

        Favorite::addFavorite((int)$user->id, 'movie', (int)$favMovie->id);

        // Mark Step Brothers as completed (95%)
        PlaybackProgress::saveProgress((int)$user->id, 'movie', (int)$completedMovie->id, 950.0, 1000.0);

        $feed = MultimediaDiscoveryService::getPersonalizedFeed($user, 5);
        $feedIds = array_map(fn($it) => $it['id'], $feed);

        // Completed movie must be omitted
        $this->assertNotContains((int)$completedMovie->id, $feedIds, 'Completed media must be excluded from personalized discovery');
        $this->assertContains((int)$unwatchedMovie->id, $feedIds);
    }

    // 10. Cold Start Fallback: New users receive trending & popular mix without errors
    public function test_cold_start_fallback_provides_valid_discovery_without_errors(): void
    {
        $m1 = $this->createMovie('Starter 1', 'starter-1', [], 'public', 'published', 20);
        $m2 = $this->createMovie('Starter 2', 'starter-2', [], 'public', 'published', 40);

        // Guest user (null)
        $guestFeed = MultimediaDiscoveryService::getPersonalizedFeed(null, 4);
        $this->assertNotEmpty($guestFeed);
        $this->assertLessThanOrEqual(4, count($guestFeed));

        // Authenticated user with zero history and zero favorites
        $newUser = $this->authenticateUser(88);
        $newFeed = MultimediaDiscoveryService::getPersonalizedFeed($newUser, 4);
        $this->assertNotEmpty($newFeed);
    }

    // 11. Diversity Rule: Prevents single genre concentration
    public function test_diversity_rule_prevents_single_genre_monopoly(): void
    {
        $user = $this->authenticateUser(1);
        $action = $this->createGenre('Action', 'action');
        $drama  = $this->createGenre('Drama', 'drama');

        // User likes Action
        $baseAction = $this->createMovie('Action Base', 'action-base', [(int)$action->id]);
        Favorite::addFavorite((int)$user->id, 'movie', (int)$baseAction->id);

        // Create 6 Action movies and 4 Drama movies
        for ($i = 1; $i <= 6; $i++) {
            $this->createMovie("Action Film {$i}", "action-film-{$i}", [(int)$action->id], 'public', 'published', 100 - $i);
        }
        for ($j = 1; $j <= 4; $j++) {
            $this->createMovie("Drama Film {$j}", "drama-film-{$j}", [(int)$drama->id], 'public', 'published', 50 - $j);
        }

        $feed = MultimediaDiscoveryService::getPersonalizedFeed($user, 6);
        $this->assertNotEmpty($feed);

        // Count how many Action items are returned
        $actionCount = 0;
        foreach ($feed as $item) {
            $model = Movie::find($item['id']);
            $genres = Genre::getForContent('movie', (int)$model->id);
            foreach ($genres as $g) {
                if ($g->slug === 'action') {
                    $actionCount++;
                }
            }
        }

        // Diversity limit ensures no more than 3 of the same primary genre in top results
        $this->assertLessThanOrEqual(MultimediaDiscoveryService::MAX_SAME_PRIMARY_GENRE, $actionCount);
    }

    // 12. Access-Aware Recommendations: Never leak protected media source URLs
    public function test_access_aware_recommendations_never_leak_stream_urls(): void
    {
        $premMovie = $this->createMovie('Premium Blockbuster', 'premium-bb', [], 'premium');
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $premMovie->id,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://secret-cdn.example.com/premium-master.mp4',
            'status'       => 'active',
        ]);

        // Guest user inspection
        $hydrated = MultimediaDiscoveryService::hydrateCandidate('movie', $premMovie, null);

        $this->assertEquals('premium', $hydrated['access_mode']);
        $this->assertFalse($hydrated['has_access']);
        $this->assertEquals('Premium', $hydrated['badge']);
        $this->assertEquals('/movie/premium-bb', $hydrated['detail_url']);

        // Assert that the private stream URL is nowhere in the candidate payload
        $this->assertArrayNotHasKey('stream_url', $hydrated);
        $this->assertArrayNotHasKey('download_url', $hydrated);
        $this->assertArrayNotHasKey('url_or_path', $hydrated);
    }

    // 13. Deleted & Unpublished Content filtered from discovery
    public function test_deleted_and_unpublished_content_filtered_from_discovery(): void
    {
        $draftMovie = $this->createMovie('Secret Draft', 'secret-draft', [], 'public', 'draft');
        $pubMovie = $this->createMovie('Public Movie', 'pub-movie', [], 'public', 'published');

        $trending = MultimediaDiscoveryService::getTrending(10, 'movie');
        $trendingSlugs = array_map(fn($it) => $it['slug'], $trending);

        $this->assertNotContains('secret-draft', $trendingSlugs, 'Draft/unpublished items must never appear in discovery');
        $this->assertContains('pub-movie', $trendingSlugs);
    }

    // 14. Multilingual & Unicode (Bangla) support in discovery
    public function test_multilingual_unicode_support_in_discovery(): void
    {
        $banglaGenre = $this->createGenre('নাটক', 'drama-bn');
        $banglaMovie = $this->createMovie('অপরাজেয়', 'aparajeyo', [(int)$banglaGenre->id]);

        $hydrated = MultimediaDiscoveryService::hydrateCandidate('movie', $banglaMovie, null);

        $this->assertEquals('অপরাজেয়', $hydrated['title']);
        $this->assertEquals('/movie/aparajeyo', $hydrated['detail_url']);

        // Check genre browsing by Unicode slug
        $genreContent = MultimediaDiscoveryService::getGenreContent('drama-bn');
        $this->assertNotNull($genreContent['genre']);
        $this->assertEquals('নাটক', $genreContent['genre']->name);
        $this->assertEquals('অপরাজেয়', $genreContent['movies'][0]['title']);
    }

    // 15. Admin Discovery Settings Lifecycle
    public function test_admin_discovery_settings_lifecycle(): void
    {
        $admin = $this->authenticateUser(1, 'administrator');
        $_SESSION['_token'] = 'csrf_secret_123';

        $adminCtrl = new MultimediaAdminController($this->app);
        $req = new Request([], [
            '_token'               => 'csrf_secret_123',
            'enable_downloads'     => 'yes',
            'enable_discovery'     => 'yes',
            'enable_trending'      => 'yes',
            'trending_window_days' => '14',
            'max_discovery_items'  => '12',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $adminCtrl->settings($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());

        // Verify values read from service
        $this->assertTrue(MultimediaDiscoveryService::isDiscoveryEnabled());
        $this->assertTrue(MultimediaDiscoveryService::isTrendingEnabled());
        $this->assertEquals(14, MultimediaDiscoveryService::getTrendingWindowDays());
        $this->assertEquals(12, MultimediaDiscoveryService::getMaxDiscoveryItems());
    }

    // 16. Frontend Controller Discovery Routes (discover, genre, artist, album)
    public function test_frontend_controller_discovery_routes(): void
    {
        $genre = $this->createGenre('Animation', 'animation');
        $movie = $this->createMovie('Toy Story', 'toy-story', [(int)$genre->id]);

        $this->db->insert('multimedia_artists', ['name' => 'Hans Zimmer', 'slug' => 'hans-zimmer', 'biography' => 'Composer']);
        $this->db->insert('multimedia_albums', ['title' => 'Gladiator OST', 'slug' => 'gladiator-ost']);

        $frontendCtrl = new MultimediaFrontendController($this->app);

        // 1. Discover page
        $reqDiscover = new Request(['tab' => 'all'], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $resDiscover = $frontendCtrl->discover($reqDiscover);
        $this->assertEquals(200, $resDiscover->getStatusCode());
        $this->assertStringContainsString('Discover Multimedia', $resDiscover->getContent());

        // 2. Genre page
        $reqGenre = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $resGenre = $frontendCtrl->genre($reqGenre, 'animation');
        $this->assertEquals(200, $resGenre->getStatusCode());
        $this->assertStringContainsString('Animation', $resGenre->getContent());

        // 3. Artist page
        $reqArtist = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $resArtist = $frontendCtrl->artist($reqArtist, 'hans-zimmer');
        $this->assertEquals(200, $resArtist->getStatusCode());
        $this->assertStringContainsString('Hans Zimmer', $resArtist->getContent());

        // 4. Album page
        $reqAlbum = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $resAlbum = $frontendCtrl->album($reqAlbum, 'gladiator-ost');
        $this->assertEquals(200, $resAlbum->getStatusCode());
        $this->assertStringContainsString('Gladiator OST', $resAlbum->getContent());
    }
}
