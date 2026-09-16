<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for v1.0.5 External Embed Publish & Download Source System (B15 Matrix).
 */
class FavoriteMultimediaDownloadAndEmbedPublishTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaAdminController $adminCtrl;
    private MediaPlaybackController $playbackCtrl;
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
        $_SESSION['_token'] = 'valid_test_token';

        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_multimedia_embed_dl_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        // Create core auth & settings tables
        $this->db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(50), name VARCHAR(100), email VARCHAR(100), password VARCHAR(255), status VARCHAR(20), email_verified_at DATETIME, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE permissions (id INTEGER PRIMARY KEY, name VARCHAR(100), slug VARCHAR(100), description TEXT, group_name VARCHAR(50), created_at DATETIME, updated_at DATETIME);");
        $this->db->execute("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));");
        $this->db->execute("CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, created_at DATETIME, PRIMARY KEY (role_id, permission_id));");
        $this->db->execute("CREATE TABLE settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");

        $now = gmdate('Y-m-d H:i:s');
        $rId = $this->db->insert('roles', ['name' => 'Admin', 'slug' => 'admin', 'created_at' => $now, 'updated_at' => $now]);
        $uId = $this->db->insert('users', ['username' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('user_roles', ['user_id' => $uId, 'role_id' => $rId]);

        // Regular non-admin user
        $this->db->insert('users', ['id' => 2, 'username' => 'regular', 'name' => 'Regular User', 'email' => 'regular@example.com', 'password' => 'secret', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        $pm = new PluginManager($this->app);
        $pm->activatePlugin('favorite-multimedia');
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->adminCtrl = $this->app->make(MultimediaAdminController::class);
        $this->playbackCtrl = $this->app->make(MediaPlaybackController::class);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    /**
     * Helper to authenticate as user id or guest
     */
    private function authenticate(?int $userId): void
    {
        if ($userId === null) {
            $_SESSION = [];
        } else {
            $_SESSION['auth_user_id'] = $userId;
            $_SESSION['_token'] = 'valid_test_token';
        }
    }

    // 1. New Movie: External Embed -> publish now -> status published, source count 1, type embed
    public function testNewMovieExternalEmbedPublishNowPersistsSourceAndPublishes(): void
    {
        $this->authenticate(1);

        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'title'         => 'The Grand Budapest Embed',
            'slug'          => 'the-grand-budapest-embed',
            'description'   => 'A cinephile film',
            'access_mode'   => 'public',
            'download_policy' => 'inherit',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => 'https://iframe.videodelivery.net/abc123embed456',
            'source_label'  => 'Cloudflare Stream',
            'download_url'  => '',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('the-grand-budapest-embed');
        $this->assertNotNull($movie);
        $this->assertSame('published', $movie->status, 'Movie should be published directly without draft demotion');
        $this->assertSame('public', $movie->access_mode);

        $sources = $movie->getSources(false);
        $this->assertCount(1, $sources, 'Media source count must be 1');
        $this->assertSame('embed', $sources[0]->source_type);
        $this->assertSame('https://iframe.videodelivery.net/abc123embed456', $sources[0]->url_or_path);
        $this->assertSame('text/html', $sources[0]->mime_type);
        $this->assertSame('video', $sources[0]->media_kind);
    }

    // 2. Edit Movie: change title only -> status remains published, embed source intact
    public function testEditMovieTitleOnlyPreservesEmbedSourceAndPublishedStatus(): void
    {
        $this->authenticate(1);

        // Create published movie with embed source
        $mId = $this->db->insert('multimedia_movies', [
            'title'       => 'Original Title',
            'slug'        => 'original-title',
            'access_mode' => 'public',
            'download_policy' => 'inherit',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_type'  => 'embed',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://player.vimeo.com/video/987654321',
            'label'        => 'Vimeo Embed',
            'mime_type'    => 'text/html',
            'is_default'   => 1,
            'sort_order'   => 0,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'edit',
            'id'            => (string)$mId,
            'title'         => 'Updated Cinephile Title',
            'slug'          => 'original-title',
            'access_mode'   => 'public',
            'download_policy' => 'inherit',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => '', // left blank during title update
            'download_url'  => '',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::find($mId);
        $this->assertNotNull($movie);
        $this->assertSame('Updated Cinephile Title', $movie->title);
        $this->assertSame('published', $movie->status);

        $sources = $movie->getSources(false);
        $this->assertCount(1, $sources, 'Embed source must remain intact');
        $this->assertSame('https://player.vimeo.com/video/987654321', $sources[0]->url_or_path);
        $this->assertSame('embed', $sources[0]->source_type);
    }

    // 3. Uploaded MP4: download URL auto-derived and allowed
    public function testUploadedMp4AutoDerivesDownloadUrlAndAllows(): void
    {
        $mId = $this->db->insert('multimedia_movies', [
            'title'       => 'Local Video Movie',
            'slug'        => 'local-video-movie',
            'access_mode' => 'public',
            'download_policy' => 'inherit',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $sId = $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_type'  => 'upload',
            'media_kind'   => 'video',
            'url_or_path'  => 'multimedia/movies/sample.mp4',
            'label'        => 'Original 1080p MP4',
            'mime_type'    => 'video/mp4',
            'is_default'   => 1,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $movie = Movie::find($mId);
        $sources = $movie->getSources(true);

        $downloadInfo = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie, $sources[0]);
        $this->assertTrue($downloadInfo['allowed']);
        $this->assertSame("/multimedia/download/{$sId}", $downloadInfo['download_url']);
        $this->assertFalse($downloadInfo['is_manual']);
    }

    // 4. Direct MP4 URL: download URL auto-derived and allowed
    public function testDirectMp4UrlAutoDerivesDownloadUrlAndAllows(): void
    {
        $mId = $this->db->insert('multimedia_movies', [
            'title'       => 'CDN Direct Movie',
            'slug'        => 'cdn-direct-movie',
            'access_mode' => 'public',
            'download_policy' => 'inherit',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $sId = $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_type'  => 'direct',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://cdn.example.com/videos/feature.mp4',
            'label'        => 'CDN MP4',
            'mime_type'    => 'video/mp4',
            'is_default'   => 1,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $movie = Movie::find($mId);
        $sources = $movie->getSources(true);

        $downloadInfo = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie, $sources[0]);
        $this->assertTrue($downloadInfo['allowed']);
        $this->assertSame("/multimedia/download/{$sId}", $downloadInfo['download_url']);
        $this->assertFalse($downloadInfo['is_manual']);
    }

    // 5. External Embed only without manual URL: download button hidden, download allowed false
    public function testExternalEmbedOnlyWithoutManualUrlHidesDownload(): void
    {
        $mId = $this->db->insert('multimedia_movies', [
            'title'       => 'Embed Only Movie',
            'slug'        => 'embed-only-movie',
            'access_mode' => 'public',
            'download_policy' => 'inherit',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_type'  => 'embed',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://iframe.videodelivery.net/embedxyz789',
            'label'        => 'Stream Embed',
            'mime_type'    => 'text/html',
            'is_default'   => 1,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $movie = Movie::find($mId);
        $sources = $movie->getSources(true);

        $downloadInfo = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie, $sources[0]);
        $this->assertFalse($downloadInfo['allowed']);
        $this->assertNull($downloadInfo['download_url']);
        $this->assertSame('External embedded media does not support direct downloading.', $downloadInfo['reason']);
    }

    // 6. External Embed + manual download URL: download button points to download URL, download allowed true
    public function testExternalEmbedWithManualDownloadUrlAllowsDownload(): void
    {
        $mId = $this->db->insert('multimedia_movies', [
            'title'        => 'Embed Movie With Manual DL',
            'slug'         => 'embed-movie-with-manual-dl',
            'access_mode'  => 'public',
            'download_policy' => 'inherit',
            'download_url' => 'https://downloads.example.com/movies/grand-feature.mp4',
            'status'       => 'published',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_type'  => 'embed',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://iframe.videodelivery.net/embedxyz789',
            'label'        => 'Stream Embed',
            'mime_type'    => 'text/html',
            'is_default'   => 1,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $movie = Movie::find($mId);
        $sources = $movie->getSources(true);

        $downloadInfo = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie, $sources[0]);
        $this->assertTrue($downloadInfo['allowed']);
        $this->assertSame("/multimedia/download-content/movie/{$mId}", $downloadInfo['download_url']);
        $this->assertTrue($downloadInfo['is_manual']);

        // Test downloading via downloadContent endpoint directly
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/multimedia/download-content/movie/{$mId}"]);
        $res = $this->playbackCtrl->downloadContent($req, 'movie', (string)$mId);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('https://downloads.example.com/movies/grand-feature.mp4', $res->getHeaders()['Location'] ?? null);
    }

    // 7. YouTube only: no fake download URL, button hidden unless manual download URL provided
    public function testYouTubeOnlyHidesDownloadUnlessManualUrlProvided(): void
    {
        $mId = $this->db->insert('multimedia_movies', [
            'title'       => 'YouTube Trailer Movie',
            'slug'        => 'youtube-trailer-movie',
            'access_mode' => 'public',
            'download_policy' => 'inherit',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_type'  => 'youtube',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'label'        => 'YouTube Source',
            'mime_type'    => 'video/youtube',
            'is_default'   => 1,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $movie = Movie::find($mId);
        $sources = $movie->getSources(true);

        // Without manual download URL: hidden
        $downloadInfo = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie, $sources[0]);
        $this->assertFalse($downloadInfo['allowed']);
        $this->assertNull($downloadInfo['download_url']);

        // Add manual download URL
        $this->db->update('multimedia_movies', ['download_url' => 'https://cdn.example.com/yt-master.mp4'], ['id' => $mId]);
        $movie = Movie::find($mId);

        $downloadInfoManual = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie, $sources[0]);
        $this->assertTrue($downloadInfoManual['allowed']);
        $this->assertSame("/multimedia/download-content/movie/{$mId}", $downloadInfoManual['download_url']);
    }

    // 8. HLS only: no fake download URL, button hidden unless manual download URL provided
    public function testHlsOnlyHidesDownloadUnlessManualUrlProvided(): void
    {
        $mId = $this->db->insert('multimedia_movies', [
            'title'       => 'HLS Stream Movie',
            'slug'        => 'hls-stream-movie',
            'access_mode' => 'public',
            'download_policy' => 'inherit',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_type'  => 'hls',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://cdn.example.com/stream/index.m3u8',
            'label'        => 'HLS Master',
            'mime_type'    => 'application/x-mpegURL',
            'is_default'   => 1,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $movie = Movie::find($mId);
        $sources = $movie->getSources(true);

        // Without manual download URL: hidden
        $downloadInfo = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie, $sources[0]);
        $this->assertFalse($downloadInfo['allowed']);
        $this->assertNull($downloadInfo['download_url']);
        $this->assertSame('M3U8 streams cannot be downloaded as direct files.', $downloadInfo['reason']);

        // With manual download URL: points to download-content endpoint
        $this->db->update('multimedia_movies', ['download_url' => 'https://cdn.example.com/offline-copy.mp4'], ['id' => $mId]);
        $movie = Movie::find($mId);

        $downloadInfoManual = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movie, $sources[0]);
        $this->assertTrue($downloadInfoManual['allowed']);
        $this->assertSame("/multimedia/download-content/movie/{$mId}", $downloadInfoManual['download_url']);
    }

    // 9. Episode: manual download URL works identically
    public function testEpisodeManualDownloadUrlWorksIdentically(): void
    {
        $this->authenticate(1);

        $seriesId = $this->db->insert('multimedia_series', [
            'title'       => 'Test Web Series',
            'slug'        => 'test-web-series',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $seasonId = $this->db->insert('multimedia_seasons', [
            'series_id'      => $seriesId,
            'season_number'  => 1,
            'title'          => 'Season 1',
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);

        // Create episode with manual download URL and embed source
        $req = new Request([], [
            '_token'         => 'valid_test_token',
            'action'         => 'create',
            'id'             => '0',
            'series_id'      => (string)$seriesId,
            'season_id'      => (string)$seasonId,
            'episode_number' => '1',
            'title'          => 'Pilot Episode Embed',
            'slug'           => 'pilot-episode-embed',
            'access_mode'    => 'public',
            'download_policy'=> 'inherit',
            'status'         => 'published',
            'submit_action'  => 'publish',
            'source_type'    => 'embed',
            'video_url'      => 'https://iframe.videodelivery.net/pilot777',
            'source_label'   => 'Cloudflare Pilot',
            'download_url'   => 'https://cdn.example.com/episodes/s01e01.mp4',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-episodes']);

        $res = $this->adminCtrl->episodes($req);
        $this->assertInstanceOf(Response::class, $res);

        $episode = Episode::findBySlug('pilot-episode-embed');
        $this->assertNotNull($episode);
        $this->assertSame('published', $episode->status);
        $this->assertSame('https://cdn.example.com/episodes/s01e01.mp4', $episode->getDownloadUrl());

        $sources = $episode->getSources(true);
        $this->assertCount(1, $sources);
        $this->assertSame('embed', $sources[0]->source_type);

        $downloadInfo = MultimediaAccessService::checkDownloadPermission(null, 'episode', $episode, $sources[0]);
        $this->assertTrue($downloadInfo['allowed']);
        $this->assertSame("/multimedia/download-content/episode/{$episode->id}", $downloadInfo['download_url']);

        // Test downloading via downloadContent endpoint
        $dlReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/multimedia/download-content/episode/{$episode->id}"]);
        $dlRes = $this->playbackCtrl->downloadContent($dlReq, 'episode', (string)$episode->id);
        $this->assertInstanceOf(Response::class, $dlRes);
        $this->assertSame(302, $dlRes->getStatusCode());
        $this->assertSame('https://cdn.example.com/episodes/s01e01.mp4', $dlRes->getHeaders()['Location'] ?? null);
    }

    // 10. Song: manual download URL works identically
    public function testSongManualDownloadUrlWorksIdentically(): void
    {
        $this->authenticate(1);

        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'title'         => 'Acoustic Melody',
            'slug'          => 'acoustic-melody',
            'access_mode'   => 'public',
            'download_policy' => 'inherit',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'direct',
            'media_kind'    => 'audio',
            'audio_url'     => 'https://stream.mux.com/sample_audio.m3u8',
            'source_label'  => 'HLS Audio Stream',
            'download_url'  => 'https://cdn.example.com/lossless/acoustic-melody.flac',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-songs']);

        $res = $this->adminCtrl->songs($req);
        $this->assertInstanceOf(Response::class, $res);

        $song = Song::findBySlug('acoustic-melody');
        $this->assertNotNull($song);
        $this->assertSame('published', $song->status);
        $this->assertSame('https://cdn.example.com/lossless/acoustic-melody.flac', $song->getDownloadUrl());

        $sources = $song->getSources(true);
        $downloadInfo = MultimediaAccessService::checkDownloadPermission(null, 'song', $song, $sources[0] ?? null);
        $this->assertTrue($downloadInfo['allowed']);
        $this->assertSame("/multimedia/download-content/song/{$song->id}", $downloadInfo['download_url']);

        // Test downloadContent endpoint
        $dlReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/multimedia/download-content/song/{$song->id}"]);
        $dlRes = $this->playbackCtrl->downloadContent($dlReq, 'song', (string)$song->id);
        $this->assertInstanceOf(Response::class, $dlRes);
        $this->assertSame(302, $dlRes->getStatusCode());
        $this->assertSame('https://cdn.example.com/lossless/acoustic-melody.flac', $dlRes->getHeaders()['Location'] ?? null);
    }

    // 11. Fail-closed access control: unauthenticated on login-required (401), non-premium on premium (403)
    public function testFailClosedAccessControlOnDownloadEndpoints(): void
    {
        // 11a. Login-required movie
        $loginMovieId = $this->db->insert('multimedia_movies', [
            'title'        => 'Members Only Film',
            'slug'         => 'members-only-film',
            'access_mode'  => 'login',
            'download_policy' => 'inherit',
            'download_url' => 'https://cdn.example.com/secret/members-film.mp4',
            'status'       => 'published',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $loginSourceId = $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $loginMovieId,
            'source_type'  => 'direct',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://cdn.example.com/secret/members-film.mp4',
            'label'        => 'Members Direct',
            'mime_type'    => 'video/mp4',
            'is_default'   => 1,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $movieLogin = Movie::find($loginMovieId);

        // Guest check: must be allowed = false, download_url = null
        $this->authenticate(null);
        $guestCheck = MultimediaAccessService::checkDownloadPermission(null, 'movie', $movieLogin);
        $this->assertFalse($guestCheck['allowed']);
        $this->assertNull($guestCheck['download_url']);
        $this->assertSame('You must log in to download this content.', $guestCheck['reason']);

        // Guest attempting downloadContent: 401
        $req1 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/multimedia/download-content/movie/{$loginMovieId}"]);
        $res1 = $this->playbackCtrl->downloadContent($req1, 'movie', (string)$loginMovieId);
        $this->assertInstanceOf(Response::class, $res1);
        $this->assertSame(401, $res1->getStatusCode());

        // Guest attempting direct source download: 401
        $req2 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/multimedia/download/{$loginSourceId}"]);
        $res2 = $this->playbackCtrl->download($req2, (string)$loginSourceId);
        $this->assertInstanceOf(Response::class, $res2);
        $this->assertSame(401, $res2->getStatusCode());

        // 11b. Premium-required movie
        $premMovieId = $this->db->insert('multimedia_movies', [
            'title'        => 'VIP Blockbuster',
            'slug'         => 'vip-blockbuster',
            'access_mode'  => 'premium',
            'download_policy' => 'inherit',
            'download_url' => 'https://cdn.example.com/vip/blockbuster.mp4',
            'status'       => 'published',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $premSourceId = $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $premMovieId,
            'source_type'  => 'direct',
            'media_kind'   => 'video',
            'url_or_path'  => 'https://cdn.example.com/vip/blockbuster.mp4',
            'label'        => 'VIP Direct',
            'mime_type'    => 'video/mp4',
            'is_default'   => 1,
            'status'       => 'active',
            'allow_download' => 'inherit',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $moviePrem = Movie::find($premMovieId);

        // Authenticate as regular non-premium user (ID 2)
        $this->authenticate(2);
        $regUser = User::find(2);

        $premCheck = MultimediaAccessService::checkDownloadPermission($regUser, 'movie', $moviePrem);
        $this->assertFalse($premCheck['allowed']);
        $this->assertNull($premCheck['download_url']);
        $this->assertSame('A Premium subscription is required to download this media.', $premCheck['reason']);

        // Non-premium attempting downloadContent: 403
        $req3 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/multimedia/download-content/movie/{$premMovieId}"]);
        $res3 = $this->playbackCtrl->downloadContent($req3, 'movie', (string)$premMovieId);
        $this->assertInstanceOf(Response::class, $res3);
        $this->assertSame(403, $res3->getStatusCode());

        // Non-premium attempting direct source download: 403
        $req4 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/multimedia/download/{$premSourceId}"]);
        $res4 = $this->playbackCtrl->download($req4, (string)$premSourceId);
        $this->assertInstanceOf(Response::class, $res4);
        $this->assertSame(403, $res4->getStatusCode());
    }

    // 12. Domain validation failure reports exact error and prevents silent publish
    public function testDomainValidationFailureReportsExactErrorAndPreventsSilentPublish(): void
    {
        $this->authenticate(1);

        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'title'         => 'Untrusted Domain Movie',
            'slug'          => 'untrusted-domain-movie',
            'description'   => 'Trying to sneak untrusted embed host',
            'access_mode'   => 'public',
            'download_policy' => 'inherit',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => 'https://malicious-stream-host.xyz/embed/12345',
            'source_label'  => 'Evil Stream',
            'download_url'  => '',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        // Verify content was NOT published
        $movie = Movie::findBySlug('untrusted-domain-movie');
        if ($movie !== null) {
            $this->assertNotSame('published', $movie->status, 'Untrusted embed domain must not result in published status');
        }

        // Verify flash error exists with explicit domain failure details
        $errorMsg = $_SESSION['flash_error'] ?? ($_SESSION['_flash']['error'] ?? '');
        $this->assertNotEmpty($errorMsg, 'Error flash message must be set for untrusted embed domain');
        $this->assertStringContainsString('malicious-stream-host.xyz', $errorMsg);
        $this->assertStringContainsString('not in the trusted allowlist', $errorMsg);
    }
}