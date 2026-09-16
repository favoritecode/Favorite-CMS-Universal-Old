<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\MultimediaRating;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MediaSourcePlaybackService;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaSongVideoParityTest extends TestCase
{
    private Application $app;
    private Database $db;
    private User $adminUser;
    private User $regularUser;
    private string $tempDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $_SESSION = [];
        $_FILES = [];

        $this->tempDbPath = sys_get_temp_dir() . '/test_fmm_song_parity_' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->app = new Application(dirname(__DIR__, 4));
        Container::setInstance($this->app);

        $this->db = new Database([
            'driver'   => 'sqlite',
            'database' => $this->tempDbPath,
            'prefix'   => '',
        ]);
        $this->setDbPdo($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        $this->createCoreTables();
        $this->seedUsersAndRoles();

        Router::reset();
        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        $pluginManager = new PluginManager($this->app);
        $pluginManager->activatePlugin('favorite-multimedia');
        FavoriteMultimediaPlugin::bootstrap($this->app);

        $this->setSessionUser($this->adminUser);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_FILES = [];
        Router::reset();
        AdminMenu::reset();
        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        if (file_exists($this->tempDbPath)) {
            @unlink($this->tempDbPath);
        }

        parent::tearDown();
    }

    private function setDbPdo(Database $db, \PDO $pdo): void
    {
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($db, $pdo);
    }

    private function createCoreTables(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                status VARCHAR(20) DEFAULT 'active',
                email_verified_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL,
                slug VARCHAR(50) NOT NULL UNIQUE,
                description TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                group_name VARCHAR(50) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, role_id)
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (role_id, permission_id)
            );
        ");

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name VARCHAR(50) NOT NULL,
                setting_key VARCHAR(50) NOT NULL,
                value TEXT NULL,
                type VARCHAR(20) DEFAULT 'string',
                is_public INTEGER DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE (group_name, setting_key)
            );
        ");
    }

    private function seedUsersAndRoles(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $adminRoleId = $this->db->insert('roles', [
            'name'       => 'Administrator',
            'slug'       => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $regularRoleId = $this->db->insert('roles', [
            'name'       => 'Subscriber',
            'slug'       => 'subscriber',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $adminPerm = $this->db->insert('permissions', [
            'name'       => 'Admin Access',
            'slug'       => 'admin.access',
            'group_name' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mmPerm = $this->db->insert('permissions', [
            'name'       => 'Manage Multimedia',
            'slug'       => 'multimedia.manage',
            'group_name' => 'multimedia',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->insert('role_permissions', ['role_id' => $adminRoleId, 'permission_id' => $adminPerm]);
        $this->db->insert('role_permissions', ['role_id' => $adminRoleId, 'permission_id' => $mmPerm]);

        $adminId = (int)$this->db->insert('users', [
            'username'   => 'admin_user',
            'name'       => 'Admin User',
            'email'      => 'admin@example.com',
            'password'   => password_hash('secret123', PASSWORD_BCRYPT),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('user_roles', ['user_id' => $adminId, 'role_id' => $adminRoleId]);
        $this->adminUser = User::find($adminId);

        $regularId = (int)$this->db->insert('users', [
            'username'   => 'regular_user',
            'name'       => 'Regular Viewer',
            'email'      => 'regular@example.com',
            'password'   => password_hash('secret123', PASSWORD_BCRYPT),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('user_roles', ['user_id' => $regularId, 'role_id' => $regularRoleId]);
        $this->regularUser = User::find($regularId);
    }

    private function setSessionUser(?User $user): void
    {
        if ($user) {
            $_SESSION['user_id'] = $user->id;
            $_SESSION['user'] = (object)[
                'id'       => $user->id,
                'username' => $user->username,
                'email'    => $user->email,
            ];
        } else {
            unset($_SESSION['user_id'], $_SESSION['user']);
        }
    }

    public function testMigration010AddsFieldsToSongsSourcesAndProgress(): void
    {
        $songCols = array_column($this->db->select("PRAGMA table_info(multimedia_songs)"), 'name');
        $this->assertContains('playback_type', $songCols);
        $this->assertContains('default_playback_mode', $songCols);

        $sourceCols = array_column($this->db->select("PRAGMA table_info(multimedia_sources)"), 'name');
        $this->assertContains('media_kind', $sourceCols);

        $progressCols = array_column($this->db->select("PRAGMA table_info(multimedia_playback_progress)"), 'name');
        $this->assertContains('playback_mode', $progressCols);
    }

    public function testSongModelPlaybackTypeDefaultsAndHelpers(): void
    {
        $songId = (int)$this->db->insert('multimedia_songs', [
            'title'                 => 'Test Canonical Song',
            'slug'                  => 'test-canonical-song',
            'status'                => 'published',
            'playback_type'         => 'audio_video',
            'default_playback_mode' => 'video',
            'created_at'            => gmdate('Y-m-d H:i:s'),
            'updated_at'            => gmdate('Y-m-d H:i:s'),
        ]);

        $song = Song::find($songId);
        $this->assertNotNull($song);
        $this->assertSame(Song::PLAYBACK_AUDIO_VIDEO, $song->getPlaybackType());
        $this->assertSame('video', $song->getDefaultPlaybackMode());
        $this->assertFalse($song->hasAudio());
        $this->assertFalse($song->hasVideo());

        // Attach audio source
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'audio',
            'source_mode'  => 'remote',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/audio.mp3',
            'label'        => 'Studio Master',
            'is_default'   => 1,
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($song->hasAudio());
        $this->assertFalse($song->hasVideo());

        // Attach video source
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'video',
            'source_mode'  => 'remote',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/video.mp4',
            'label'        => 'Official 4K Video',
            'is_default'   => 1,
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($song->hasAudio());
        $this->assertTrue($song->hasVideo());
    }

    public function testSongIndependentDefaultsPerMediaKind(): void
    {
        $songId = (int)$this->db->insert('multimedia_songs', [
            'title'                 => 'Dual Mode Song',
            'slug'                  => 'dual-mode-song',
            'status'                => 'published',
            'playback_type'         => 'audio_video',
            'default_playback_mode' => 'audio',
            'created_at'            => gmdate('Y-m-d H:i:s'),
            'updated_at'            => gmdate('Y-m-d H:i:s'),
        ]);

        // 2 audio sources
        $a1 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'audio',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/audio1.mp3',
            'label'        => 'Audio Primary',
            'is_default'   => 1,
            'status'       => 'active',
        ]);
        $a2 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'audio',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/audio2.mp3',
            'label'        => 'Audio Backup',
            'is_default'   => 0,
            'status'       => 'active',
        ]);

        // 2 video sources
        $v1 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'video',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/video1.mp4',
            'label'        => 'Video Primary',
            'is_default'   => 1,
            'status'       => 'active',
        ]);
        $v2 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'video',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/video2.mp4',
            'label'        => 'Video Backup',
            'is_default'   => 0,
            'status'       => 'active',
        ]);

        $song = Song::find($songId);
        $this->assertSame($a1, (int)$song->getDefaultAudioSource()->id);
        $this->assertSame($v1, (int)$song->getDefaultVideoSource()->id);

        // Switch default video to v2; audio default MUST remain a1!
        MediaSource::enforceSingleDefault('song', $songId, $v2, 'video');
        $this->assertSame($v2, (int)$song->getDefaultVideoSource()->id);
        $this->assertSame($a1, (int)$song->getDefaultAudioSource()->id);

        // Switch default audio to a2; video default MUST remain v2!
        MediaSource::enforceSingleDefault('song', $songId, $a2, 'audio');
        $this->assertSame($a2, (int)$song->getDefaultAudioSource()->id);
        $this->assertSame($v2, (int)$song->getDefaultVideoSource()->id);
    }

    public function testSongPromoteNextDefaultScopedByKind(): void
    {
        $songId = (int)$this->db->insert('multimedia_songs', [
            'title'         => 'Promote Test Song',
            'slug'          => 'promote-test-song',
            'status'        => 'published',
            'playback_type' => 'audio_video',
        ]);

        $a1 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'audio',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/a1.mp3',
            'is_default'   => 1,
            'status'       => 'active',
            'sort_order'   => 1,
        ]);
        $a2 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'audio',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/a2.mp3',
            'is_default'   => 0,
            'status'       => 'active',
            'sort_order'   => 2,
        ]);

        $v1 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'video',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/v1.mp4',
            'is_default'   => 1,
            'status'       => 'active',
            'sort_order'   => 1,
        ]);
        $v2 = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'video',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/v2.mp4',
            'is_default'   => 0,
            'status'       => 'active',
            'sort_order'   => 2,
        ]);

        // Delete v1; promoteNextDefault for video should set v2 as default video without altering a1
        $this->db->delete('multimedia_sources', ['id' => $v1]);
        MediaSource::promoteNextDefault('song', $songId, 'video');

        $song = Song::find($songId);
        $this->assertSame($v2, (int)$song->getDefaultVideoSource()->id);
        $this->assertSame($a1, (int)$song->getDefaultAudioSource()->id);
    }

    public function testMediaSourcePlaybackServiceForDualModeSong(): void
    {
        $songId = (int)$this->db->insert('multimedia_songs', [
            'title'                 => 'Dual Mode Playback Song',
            'slug'                  => 'dual-mode-playback-song',
            'status'                => 'published',
            'playback_type'         => 'audio_video',
            'default_playback_mode' => 'audio',
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'audio',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/stream.mp3',
            'label'        => 'Audio Stream',
            'is_default'   => 1,
            'status'       => 'active',
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'video',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/stream.mp4',
            'label'        => 'Video Stream',
            'is_default'   => 1,
            'status'       => 'active',
        ]);

        $result = MediaSourcePlaybackService::getPlayableSources($this->adminUser, 'song', $songId);

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['has_audio']);
        $this->assertTrue($result['has_video']);
        $this->assertCount(1, $result['audio_sources']);
        $this->assertCount(1, $result['video_sources']);
        $this->assertSame('audio', $result['default_audio_source']['player_type']);
        $this->assertSame('video', $result['default_video_source']['player_type']);
        $this->assertSame('audio_video', $result['playback_type']);
        $this->assertSame('audio', $result['default_playback_mode']);
    }

    public function testMediaSourceResolverYouTubeVimeoAndEmbedForSong(): void
    {
        $songId = (int)$this->db->insert('multimedia_songs', [
            'title'         => 'Music Video Sources Test',
            'slug'          => 'music-video-sources-test',
            'status'        => 'published',
            'playback_type' => 'video',
        ]);

        // YouTube
        $ytUrl = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $ytRes = MediaSourceResolver::resolve($ytUrl);
        $this->assertTrue($ytRes['valid']);
        $this->assertSame('embed', $ytRes['source_type']);

        $ytSourceId = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'video',
            'source_mode'  => $ytRes['source_mode'],
            'source_type'  => $ytRes['source_type'],
            'url_or_path'  => $ytUrl,
            'label'        => 'YouTube Music Video',
            'is_default'   => 1,
            'status'       => 'active',
        ]);

        // Vimeo
        $vmUrl = 'https://vimeo.com/76979871';
        $vmRes = MediaSourceResolver::resolve($vmUrl);
        $this->assertTrue($vmRes['valid']);
        $this->assertSame('embed', $vmRes['source_type']);

        $vmSourceId = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $songId,
            'media_kind'   => 'video',
            'source_mode'  => $vmRes['source_mode'],
            'source_type'  => $vmRes['source_type'],
            'url_or_path'  => $vmUrl,
            'label'        => 'Vimeo Music Video',
            'is_default'   => 0,
            'status'       => 'active',
        ]);

        $playback = MediaSourcePlaybackService::getPlayableSources($this->adminUser, 'song', $songId);
        $this->assertTrue($playback['has_video']);
        $this->assertFalse($playback['has_audio']);
        $this->assertCount(2, $playback['video_sources']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $playback['video_sources'][0]['url']);
        $this->assertSame('https://player.vimeo.com/video/76979871', $playback['video_sources'][1]['url']);
    }

    public function testFailClosedAccessControlForSongPlayback(): void
    {
        $loginSongId = (int)$this->db->insert('multimedia_songs', [
            'title'         => 'Members Only Song',
            'slug'          => 'members-only-song',
            'status'        => 'published',
            'access_mode'   => 'login',
            'playback_type' => 'audio_video',
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $loginSongId,
            'media_kind'   => 'video',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/secret_video.mp4',
            'is_default'   => 1,
            'status'       => 'active',
        ]);

        // Guest user (null)
        $resGuest = MediaSourcePlaybackService::getPlayableSources(null, 'song', $loginSongId);
        $this->assertFalse($resGuest['allowed']);
        $this->assertSame(MultimediaAccessService::LOGIN_REQUIRED, $resGuest['access']);
        $this->assertEmpty($resGuest['sources']);
        $this->assertEmpty($resGuest['video_sources']);
        $this->assertNull($resGuest['default_video_source']);

        // Logged-in user
        $resUser = MediaSourcePlaybackService::getPlayableSources($this->regularUser, 'song', $loginSongId);
        $this->assertTrue($resUser['allowed']);
        $this->assertNotEmpty($resUser['video_sources']);
    }

    public function testAdminSongControllerReadinessProtection(): void
    {
        $controller = new MultimediaAdminController($this->app);
        $_SESSION['csrf_token'] = 'valid_token';
        $_SESSION['_token'] = 'valid_token';

        // 1. Audio song without audio source published -> reverts to draft
        $req = new Request([], [
            '_token'        => 'valid_token',
            'action'        => 'create',
            'title'         => 'No Audio Song',
            'playback_type' => 'audio',
            'submit_action' => 'publish',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-songs']);
        $controller->songs($req);

        $saved1 = Song::findBySlug('no-audio-song');
        $this->assertNotNull($saved1);
        $this->assertSame('draft', $saved1->status);

        // 2. Video song without video source published -> reverts to draft
        $req2 = new Request([], [
            '_token'        => 'valid_token',
            'action'        => 'create',
            'title'         => 'No Video Song',
            'playback_type' => 'video',
            'submit_action' => 'publish',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-songs']);
        $controller->songs($req2);

        $saved2 = Song::findBySlug('no-video-song');
        $this->assertNotNull($saved2);
        $this->assertSame('draft', $saved2->status);

        // 3. Dual mode song with ONLY audio source published -> reverts to draft
        $req3 = new Request([], [
            '_token'        => 'valid_token',
            'action'        => 'create',
            'title'         => 'Partial Dual Song',
            'playback_type' => 'audio_video',
            'audio_url'     => 'https://example.com/audio.mp3',
            'submit_action' => 'publish',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-songs']);
        $controller->songs($req3);

        $saved3 = Song::findBySlug('partial-dual-song');
        $this->assertNotNull($saved3);
        $this->assertSame('draft', $saved3->status);
        $this->assertStringContainsString('Dual-Mode requires both an audio source AND a video source', $_SESSION['flash_warning'] ?? '');

        // 4. Dual mode song with BOTH audio and video -> successfully published!
        $req4 = new Request([], [
            '_token'        => 'valid_token',
            'action'        => 'create',
            'title'         => 'Complete Dual Song',
            'playback_type' => 'audio_video',
            'audio_url'     => 'https://example.com/audio.mp3',
            'video_url'     => 'https://example.com/video.mp4',
            'submit_action' => 'publish',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-songs']);
        $controller->songs($req4);

        $saved4 = Song::findBySlug('complete-dual-song');
        $this->assertNotNull($saved4);
        $this->assertSame('published', $saved4->status);
    }

    public function testPlaylistTracklistPrioritizesAudioAndFlagsVideoOnly(): void
    {
        // 1. Dual-mode song
        $dualId = (int)$this->db->insert('multimedia_songs', [
            'title'         => 'Dual Song For Playlist',
            'slug'          => 'dual-song-for-playlist',
            'status'        => 'published',
            'playback_type' => 'audio_video',
        ]);
        $audioSrc = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $dualId,
            'media_kind'   => 'audio',
            'source_type'  => 'audio',
            'url_or_path'  => 'https://example.com/track.mp3',
            'is_default'   => 1,
            'status'       => 'active',
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $dualId,
            'media_kind'   => 'video',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/video.mp4',
            'is_default'   => 1,
            'status'       => 'active',
        ]);

        // 2. Video-only song
        $videoId = (int)$this->db->insert('multimedia_songs', [
            'title'         => 'Video Only Song',
            'slug'          => 'video-only-song',
            'status'        => 'published',
            'playback_type' => 'video',
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id'   => $videoId,
            'media_kind'   => 'video',
            'source_type'  => 'embed',
            'url_or_path'  => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'is_default'   => 1,
            'status'       => 'active',
        ]);

        $playlistId = (int)$this->db->insert('multimedia_playlists', [
            'title'       => 'Test Playlist',
            'slug'        => 'test-playlist',
            'status'      => 'published',
            'access_mode' => 'public',
        ]);
        $this->db->insert('multimedia_playlist_items', ['playlist_id' => $playlistId, 'song_id' => $dualId, 'sort_order' => 1]);
        $this->db->insert('multimedia_playlist_items', ['playlist_id' => $playlistId, 'song_id' => $videoId, 'sort_order' => 2]);

        $playlist = Playlist::find($playlistId);
        $this->assertNotNull($playlist);
        $songs = $playlist->getSongs();
        $this->assertCount(2, $songs);

        $tracksData = [];
        foreach ($songs as $s) {
            $sSource = $s->getDefaultAudioSource() ?: $s->getDefaultSource();
            $isVideoOnly = ($s->getPlaybackType() === Song::PLAYBACK_VIDEO) || (!$s->hasAudio());
            $tracksData[] = [
                'id'            => $s->id,
                'stream_url'    => ($sSource && !$isVideoOnly) ? "/multimedia/stream/{$sSource->id}" : '',
                'is_video_only' => $isVideoOnly,
            ];
        }

        // Dual-mode track has audio stream url and is_video_only = false
        $this->assertFalse($tracksData[0]['is_video_only']);
        $this->assertSame("/multimedia/stream/{$audioSrc}", $tracksData[0]['stream_url']);

        // Video-only track has empty audio stream url and is_video_only = true
        $this->assertTrue($tracksData[1]['is_video_only']);
        $this->assertSame('', $tracksData[1]['stream_url']);
    }

    public function testCanonicalSongEntityPreservesRatingsFavoritesAndPlaylistsAcrossModes(): void
    {
        $songId = (int)$this->db->insert('multimedia_songs', [
            'title'         => 'Unified Identity Song',
            'slug'          => 'unified-identity-song',
            'status'        => 'published',
            'playback_type' => 'audio',
        ]);

        // Add review & rating
        MultimediaRating::setRating((int)$this->regularUser->id, 'song', $songId, 5);
        $agg = MultimediaRating::getAggregate('song', $songId);
        $this->assertSame(1, $agg['count']);
        $this->assertEquals(5.0, $agg['average']);

        // Switch song from 'audio' to 'audio_video'
        $this->db->update('multimedia_songs', ['playback_type' => 'audio_video'], ['id' => $songId]);

        // Rating aggregate remains intact
        $aggAfter = MultimediaRating::getAggregate('song', $songId);
        $this->assertSame(1, $aggAfter['count']);
        $this->assertEquals(5.0, $aggAfter['average']);
    }

    public function testPluginVersionIs104(): void
    {
        $manifestPath = APP_ROOT . '/plugins/favorite-multimedia/plugin.json';
        $this->assertFileExists($manifestPath);
        $data = json_decode((string)file_get_contents($manifestPath), true);
        $this->assertSame('1.0.7', $data['version']);
    }
}
