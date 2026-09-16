<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\DownloadSourceService;
use FavoriteCMS\Multimedia\Services\MediaSourcePlaybackService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Favorite Multimedia v1.0.7:
 * - Forensic Song 500 Fix across all song modes and sources
 * - Unified Volume Memory & Admin Settings
 * - PiP and Background Audio Configuration
 * - Release Version Verification
 */
class FavoriteMultimediaVolumeAndPlaybackTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaAdminController $adminCtrl;
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
        $_SESSION['_token'] = 'valid_test_token';

        $this->app = new Application(APP_ROOT);
        Container::setInstance($this->app);

        $this->tempDb = sys_get_temp_dir() . '/fav_multimedia_vol_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        $this->createBaseSchema();

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        $pm = new PluginManager($this->app);
        $pm->activatePlugin('favorite-multimedia');
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->seedTestData();

        $this->adminCtrl = new MultimediaAdminController($this->app);
        $this->frontendCtrl = new MultimediaFrontendController($this->app);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function createBaseSchema(): void
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            role TEXT NOT NULL DEFAULT 'user',
            created_at TEXT,
            updated_at TEXT
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            description TEXT,
            created_at TEXT,
            updated_at TEXT
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            description TEXT,
            group_name TEXT,
            created_at TEXT,
            updated_at TEXT
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS user_roles (
            user_id INTEGER,
            role_id INTEGER,
            PRIMARY KEY (user_id, role_id)
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER,
            permission_id INTEGER,
            created_at TEXT,
            PRIMARY KEY (role_id, permission_id)
        )");

        $this->db->execute("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            `group` TEXT,
            group_name TEXT,
            `key` TEXT,
            setting_key TEXT,
            value TEXT,
            type TEXT,
            is_public INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        )");
    }

    private function seedTestData(): void
    {
        $now = date('Y-m-d H:i:s');

        $rId = $this->db->insert('roles', [
            'name' => 'Admin',
            'slug' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $uId = $this->db->insert('users', [
            'username' => 'admin',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('user_roles', ['user_id' => $uId, 'role_id' => $rId]);

        $this->db->insert('users', [
            'username' => 'free',
            'name' => 'Free Listener',
            'email' => 'free@example.com',
            'password' => 'secret',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Audio-only song
        $s1 = $this->db->insert('multimedia_songs', [
            'title' => 'Audio Only Track',
            'slug' => 'audio-only-track',
            'status' => 'published',
            'access_mode' => 'public',
            'playback_type' => 'audio',
            'duration' => 200,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id' => $s1,
            'media_kind' => 'audio',
            'source_type' => 'direct',
            'label' => 'Server 1 Audio',
            'url_or_path' => 'https://example.com/audio1.mp3',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Video-only song
        $s2 = $this->db->insert('multimedia_songs', [
            'title' => 'Video Only Track',
            'slug' => 'video-only-track',
            'status' => 'published',
            'access_mode' => 'public',
            'playback_type' => 'video',
            'duration' => 240,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id' => $s2,
            'media_kind' => 'video',
            'source_type' => 'direct',
            'label' => 'Official Video',
            'url_or_path' => 'https://example.com/video1.mp4',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Dual mode (Audio + Video) song
        $s3 = $this->db->insert('multimedia_songs', [
            'title' => 'Dual Mode Anthem',
            'slug' => 'dual-mode-anthem',
            'status' => 'published',
            'access_mode' => 'public',
            'playback_type' => 'audio_video',
            'default_playback_mode' => 'audio',
            'duration' => 210,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id' => $s3,
            'media_kind' => 'audio',
            'source_type' => 'direct',
            'label' => 'Audio Master',
            'url_or_path' => 'https://example.com/dual_audio.mp3',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id' => $s3,
            'media_kind' => 'video',
            'source_type' => 'direct',
            'label' => 'Music Video HD',
            'url_or_path' => 'https://example.com/dual_video.mp4',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Song with downloads
        $s4 = $this->db->insert('multimedia_songs', [
            'title' => 'Downloadable Song',
            'slug' => 'downloadable-song',
            'status' => 'published',
            'access_mode' => 'public',
            'playback_type' => 'audio',
            'duration' => 180,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'song',
            'content_id' => $s4,
            'media_kind' => 'audio',
            'source_type' => 'direct',
            'label' => 'Playable Audio',
            'url_or_path' => 'https://example.com/play_s4.mp3',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('multimedia_download_sources', [
            'content_type' => 'song',
            'content_id' => $s4,
            'label' => 'FLAC Studio Master',
            'url' => 'https://example.com/download_s4.flac',
            'is_active' => 1,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Song with no sources
        $this->db->insert('multimedia_songs', [
            'title' => 'Empty Track',
            'slug' => 'empty-track',
            'status' => 'published',
            'access_mode' => 'public',
            'playback_type' => 'audio',
            'duration' => 150,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test01CheckDownloadPermissionHandlesArraySourceGracefully(): void
    {
        $song = Song::findBySlug('audio-only-track');
        $this->assertNotNull($song);

        // Simulated normalized playbackData array source
        $arraySource = [
            'id' => 1,
            'source_type' => 'direct',
            'media_kind' => 'audio',
            'label' => 'Server 1 Audio',
            'url' => 'https://example.com/audio1.mp3',
            'raw_url' => 'https://example.com/audio1.mp3',
            'is_default' => true,
        ];

        // Must not throw TypeError
        $res = MultimediaAccessService::checkDownloadPermission(null, 'song', $song, $arraySource);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('allowed', $res);
        $this->assertTrue($res['allowed']);
    }

    public function test02CheckDownloadPermissionHandlesMediaSourceModel(): void
    {
        $song = Song::findBySlug('audio-only-track');
        $this->assertNotNull($song);
        $modelSource = MediaSource::find(1);
        $this->assertNotNull($modelSource);

        $res = MultimediaAccessService::checkDownloadPermission(null, 'song', $song, $modelSource);
        $this->assertIsArray($res);
        $this->assertTrue($res['allowed']);
    }

    public function test03CheckDownloadPermissionHandlesNullSource(): void
    {
        $song = Song::findBySlug('empty-track');
        $this->assertNotNull($song);

        $res = MultimediaAccessService::checkDownloadPermission(null, 'song', $song, null);
        $this->assertIsArray($res);
        $this->assertFalse($res['allowed']);
    }

    public function test04DownloadSourceServiceResolvesArraySelectedSource(): void
    {
        $song = Song::findBySlug('audio-only-track');
        $arraySource = [
            'id' => 1,
            'url_or_path' => 'https://example.com/audio1.mp3',
            'source_type' => 'direct',
        ];

        $res = DownloadSourceService::resolveDownloadOptions(null, 'song', $song, $arraySource);
        $this->assertIsArray($res);
        $this->assertTrue($res['allowed']);
    }

    public function test05AllSongDetailPagesRenderHttp200Without500Errors(): void
    {
        $slugs = [
            'audio-only-track',
            'video-only-track',
            'dual-mode-anthem',
            'downloadable-song',
            'empty-track',
        ];

        foreach ($slugs as $slug) {
            $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/song/{$slug}"], [], []);
            $response = $this->frontendCtrl->song($req, $slug);
            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals(200, $response->getStatusCode(), "Song detail failed for slug: {$slug}");
            $this->assertStringContainsString('<!DOCTYPE html>', (string)$response->getContent());
        }
    }

    public function test06AdminPlaybackAndVolumeSettingsSaveAndRetrieve(): void
    {
        $postData = [
            '_token'                => 'valid_test_token',
            'default_media_volume'  => '35',
            'remember_media_volume' => 'yes',
            'enable_pip'            => 'yes',
            'enable_background_audio' => 'yes',
        ];

        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST'], [], []);
        $res = $this->adminCtrl->settings($req);

        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals(302, $res->getStatusCode());

        $this->assertEquals('35', Setting::get('multimedia', 'default_media_volume'));
        $this->assertEquals('yes', Setting::get('multimedia', 'remember_media_volume'));
        $this->assertEquals('yes', Setting::get('multimedia', 'enable_pip'));
        $this->assertEquals('yes', Setting::get('multimedia', 'enable_background_audio'));
    }

    public function test07AdminPlaybackVolumeClampedBetween5And50(): void
    {
        // Test lower bound clamp
        $postLow = [
            '_token'               => 'valid_test_token',
            'default_media_volume' => '1',
        ];
        $this->adminCtrl->settings(new Request([], $postLow, ['REQUEST_METHOD' => 'POST'], [], []));
        $this->assertEquals('5', Setting::get('multimedia', 'default_media_volume'));

        // Test upper bound clamp
        $postHigh = [
            '_token'               => 'valid_test_token',
            'default_media_volume' => '99',
        ];
        $this->adminCtrl->settings(new Request([], $postHigh, ['REQUEST_METHOD' => 'POST'], [], []));
        $this->assertEquals('50', Setting::get('multimedia', 'default_media_volume'));
    }

    public function test08AdminSettingsViewRendersVolumeAndPlaybackFields(): void
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET'], [], []);
        $output = $this->adminCtrl->settings($req);

        $this->assertIsString($output);
        $this->assertStringContainsString('name="default_media_volume"', $output);
        $this->assertStringContainsString('name="remember_media_volume"', $output);
        $this->assertStringContainsString('name="enable_pip"', $output);
        $this->assertStringContainsString('name="enable_background_audio"', $output);
    }

    public function test09AudioPlayerComponentIncludesConfigScript(): void
    {
        Setting::set('multimedia', 'default_media_volume', '30');
        Setting::set('multimedia', 'remember_media_volume', 'yes');
        Setting::set('multimedia', 'enable_pip', 'yes');
        Setting::set('multimedia', 'enable_background_audio', 'yes');

        ob_start();
        include APP_ROOT . '/plugins/favorite-multimedia/views/frontend/components/audio-player.php';
        $rendered = ob_get_clean();

        $this->assertStringContainsString('window.FavoriteMultimediaConfig', $rendered);
        $this->assertStringContainsString('window.FavoriteMultimediaConfig.defaultVolume = 0.3', $rendered);
        $this->assertStringContainsString('window.FavoriteMultimediaConfig.rememberVolume = true', $rendered);
        $this->assertStringContainsString('window.FavoriteMultimediaConfig.enablePip = true', $rendered);
        $this->assertStringContainsString('window.FavoriteMultimediaConfig.enableBackgroundAudio = true', $rendered);
    }

    public function test10DualModeSongRendersBackgroundAudioButton(): void
    {
        Setting::set('multimedia', 'enable_background_audio', 'yes');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/song/dual-mode-anthem'], [], []);
        $response = $this->frontendCtrl->song($req, 'dual-mode-anthem');

        $content = (string)$response->getContent();
        $this->assertStringContainsString('id="fav_mode_pill_bg_audio"', $content);
        $this->assertStringContainsString('playSongInBackground', $content);
    }

    public function test11ReleaseVersionMatches107AcrossAllFiles(): void
    {
        $this->assertEquals('1.0.7', FavoriteMultimediaPlugin::VERSION);

        $jsonPath = APP_ROOT . '/plugins/favorite-multimedia/plugin.json';
        $this->assertFileExists($jsonPath);
        $json = json_decode((string)file_get_contents($jsonPath), true);
        $this->assertEquals('1.0.7', $json['version'] ?? '');

        $pluginPhp = (string)file_get_contents(APP_ROOT . '/plugins/favorite-multimedia/plugin.php');
        $this->assertMatchesRegularExpression('/Version:\s*1\.0\.7/', $pluginPhp);
    }
}
