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
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaMainFormPublishingTest extends TestCase
{
    private Application $app;
    private Database $db;
    private MultimediaAdminController $adminCtrl;
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

        $this->tempDb = sys_get_temp_dir() . '/fav_multimedia_main_form_' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $this->app->singleton(Database::class, fn() => $this->db);
        $this->app->singleton(Config::class, fn() => new Config([]));

        // Create core auth tables
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

        FavoriteMultimediaPlugin::reset();
        Setting::clearCache();

        // Activate plugin migrations
        $pm = new PluginManager($this->app);
        $pm->activatePlugin('favorite-multimedia');
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $this->adminCtrl = $this->app->make(MultimediaAdminController::class);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    // 1. MediaSourceResolver::extractIframeUrl unit tests
    public function testExtractIframeUrlVariants(): void
    {
        $this->assertSame('', MediaSourceResolver::extractIframeUrl(''));
        $this->assertSame('https://example.com/video.mp4', MediaSourceResolver::extractIframeUrl('https://example.com/video.mp4'));
        
        $iframeDouble = '<iframe src="https://player.example.com/embed/123" width="640" height="360" frameborder="0"></iframe>';
        $this->assertSame('https://player.example.com/embed/123', MediaSourceResolver::extractIframeUrl($iframeDouble));

        $iframeSingle = "<iframe width='100%' height='450' src='https://player.vimeo.com/video/987654321' allowfullscreen></iframe>";
        $this->assertSame('https://player.vimeo.com/video/987654321', MediaSourceResolver::extractIframeUrl($iframeSingle));

        $iframeEntities = '<iframe src="https://example.com/embed?id=1&amp;token=abc&amp;autoplay=0"></iframe>';
        $this->assertSame('https://example.com/embed?id=1&token=abc&autoplay=0', MediaSourceResolver::extractIframeUrl($iframeEntities));

        $iframeProto = '<iframe src="//player.example.com/embed/xyz"></iframe>';
        $this->assertSame('https://player.example.com/embed/xyz', MediaSourceResolver::extractIframeUrl($iframeProto));
    }

    // 2. Mainstream embed hosts recognized by default
    public function testKnownEmbedHostsRecognition(): void
    {
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('youtube.com'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('www.youtube.com'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('youtu.be'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('vimeo.com'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('player.vimeo.com'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('iframe.videodelivery.net'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('videodelivery.net'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('player.twitch.tv'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('fast.wistia.net'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('rumble.com'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('iframe.mediadelivery.net'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('stream.mux.com'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('open.spotify.com'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('drive.google.com'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('archive.org'));

        // Wildcard subdomain matching
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('custom.videodelivery.net'));
        $this->assertTrue(MediaSourceResolver::isKnownEmbedHost('stream.twitch.tv'));
    }

    // 3. Embed domain allowlist with custom settings
    public function testCustomTrustedEmbedDomainsAllowed(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', "customplayer.org\n*.cdnstream.io");
        $this->assertTrue(MediaSourceResolver::isEmbedDomainAllowed('https://customplayer.org/embed/123'));
        $this->assertTrue(MediaSourceResolver::isEmbedDomainAllowed('https://play.cdnstream.io/stream/456'));
        $this->assertFalse(MediaSourceResolver::isEmbedDomainAllowed('https://untrusted-evil.com/embed/999'));
    }

    // 4. SSRF boundaries strictly preserved
    public function testSSRFProtectionPreserved(): void
    {
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('http://127.0.0.1/admin')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('http://localhost:8080/test')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('http://169.254.169.254/latest/meta-data')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('http://[::1]/secret')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('javascript:alert(1)')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('data:text/html,payload')['safe']);
    }

    // 5. Bug 1 & 2 Fix: Create Movie with inline External Embed URL + Publish Now
    public function testCreateMovieWithInlineEmbedDirectUrlPublishNow(): void
    {
        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'title'         => 'The Great Embed Journey',
            'slug'          => 'the-great-embed-journey',
            'description'   => 'A documentary',
            'access_mode'   => 'public',
            'download_policy' => 'inherit',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => 'https://iframe.videodelivery.net/abc1234567890',
            'source_label'  => 'Cloudflare Stream',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('the-great-embed-journey');
        $this->assertNotNull($movie);
        $this->assertSame('published', $movie->status);
        $this->assertSame('public', $movie->access_mode);

        $sources = $movie->getSources(false);
        $this->assertCount(1, $sources);
        $source = $sources[0];
        $this->assertSame('embed', $source->source_type);
        $this->assertSame('https://iframe.videodelivery.net/abc1234567890', $source->url_or_path);
        $this->assertSame('text/html', $source->mime_type);
        $this->assertSame(1, (int)$source->is_default);
        $this->assertSame('active', $source->status);

        // Flash message should be success, not error
        $this->assertSame('Movie created successfully.', $_SESSION['flash_success'] ?? '');
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    // 6. Bug 1 & 2 Fix: Create Movie with pasted <iframe> snippet + Publish Now
    public function testCreateMovieWithInlineIframeSnippetPublishNow(): void
    {
        $iframeCode = '<iframe src="https://player.vimeo.com/video/76979871" width="640" height="360" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>';

        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'title'         => 'Vimeo Snippet Movie',
            'slug'          => 'vimeo-snippet-movie',
            'access_mode'   => 'public',
            'download_policy' => 'inherit',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => $iframeCode,
            'source_label'  => 'Main Vimeo Stream',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('vimeo-snippet-movie');
        $this->assertNotNull($movie);
        $this->assertSame('published', $movie->status);

        $sources = $movie->getSources(false);
        $this->assertCount(1, $sources);
        $this->assertSame('embed', $sources[0]->source_type);
        $this->assertSame('https://player.vimeo.com/video/76979871', $sources[0]->url_or_path);
    }

    // 7. Bug 3 Fix: Metadata-only edit of existing Movie preserves attached sources & stays Published
    public function testEditMovieMetadataOnlyPreservesSourcesAndPublishedStatus(): void
    {
        // 1. Create published movie with source
        $mId = (int)$this->db->insert('multimedia_movies', [
            'title'       => 'Initial Title',
            'slug'        => 'initial-title',
            'access_mode' => 'public',
            'status'      => 'published',
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $mId,
            'source_mode'  => 'url',
            'source_type'  => 'video',
            'url_or_path'  => 'https://example.com/stream.mp4',
            'mime_type'    => 'video/mp4',
            'is_default'   => 1,
            'status'       => 'active',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        // 2. Submit edit with empty video inputs (simulating editing title/description on main form)
        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'edit',
            'id'            => (string)$mId,
            'title'         => 'Updated Title After Edit',
            'slug'          => 'initial-title',
            'description'   => 'New description',
            'access_mode'   => 'public',
            'download_policy' => 'inherit',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'auto',
            'video_url'     => '', // Empty: user did not re-enter source
            'source_label'  => '',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $updated = Movie::find($mId);
        $this->assertSame('Updated Title After Edit', $updated->title);
        $this->assertSame('published', $updated->status);

        $sources = $updated->getSources(false);
        $this->assertCount(1, $sources, 'Existing source must remain attached after metadata update');
        $this->assertSame('https://example.com/stream.mp4', $sources[0]->url_or_path);
        $this->assertSame('Movie updated successfully.', $_SESSION['flash_success'] ?? '');
    }

    // 8. Quick Actions: Save Draft vs Schedule
    public function testQuickActionsDraftAndSchedule(): void
    {
        // Save Draft
        $reqDraft = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'title'         => 'Draft Movie',
            'slug'          => 'draft-movie',
            'access_mode'   => 'public',
            'status'        => 'published', // select had published
            'submit_action' => 'draft', // user clicked Save Draft
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);
        $this->adminCtrl->movies($reqDraft);

        $draft = Movie::findBySlug('draft-movie');
        $this->assertNotNull($draft);
        $this->assertSame('draft', $draft->status);

        // Schedule
        $reqSched = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'title'         => 'Scheduled Movie',
            'slug'          => 'scheduled-movie',
            'access_mode'   => 'public',
            'status'        => 'draft',
            'submit_action' => 'schedule', // user clicked Schedule
            'publish_at'    => gmdate('Y-m-d\TH:i', time() + 86400),
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);
        $this->adminCtrl->movies($reqSched);

        $sched = Movie::findBySlug('scheduled-movie');
        $this->assertNotNull($sched);
        $this->assertSame('scheduled', $sched->status);
    }

    // 9. No Media Protection sets actionable flash_error and unsets flash_success
    public function testNoMediaProtectionFlashErrorReporting(): void
    {
        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'title'         => 'Empty Movie',
            'slug'          => 'empty-movie',
            'access_mode'   => 'public',
            'status'        => 'published',
            'submit_action' => 'publish',
            'video_url'     => '', // No source provided
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $this->adminCtrl->movies($req);

        $movie = Movie::findBySlug('empty-movie');
        $this->assertNotNull($movie);
        $this->assertSame('draft', $movie->status);
        $this->assertNotEmpty($_SESSION['flash_error']);
        $this->assertStringContainsString('saved as Draft because no playable video source could be attached', $_SESSION['flash_error']);
        $this->assertArrayNotHasKey('flash_success', $_SESSION);
    }

    // 10. Episodes Parity: Create Episode with inline embed URL + Publish Now
    public function testCreateEpisodeWithInlineEmbedPublishNow(): void
    {
        $seriesId = (int)$this->db->insert('multimedia_series', ['title' => 'Test Series', 'slug' => 'test-series', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $seasonId = (int)$this->db->insert('multimedia_seasons', ['series_id' => $seriesId, 'season_number' => 1, 'title' => 'Season 1', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);

        $req = new Request([], [
            '_token'         => 'valid_test_token',
            'action'         => 'create',
            'season_id'      => (string)$seasonId,
            'series_id'      => (string)$seriesId,
            'episode_number' => '1',
            'title'          => 'Episode 1 Pilot',
            'slug'           => 'episode-1-pilot',
            'access_mode'    => 'public',
            'download_policy'=> 'inherit',
            'status'         => 'published',
            'submit_action'  => 'publish',
            'source_type'    => 'embed',
            'video_url'      => 'https://player.vimeo.com/video/12345678',
            'source_label'   => 'Vimeo Pilot',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-episodes']);

        $res = $this->adminCtrl->episodes($req);
        $this->assertInstanceOf(Response::class, $res);

        $ep = Episode::findBySlug('episode-1-pilot');
        $this->assertNotNull($ep);
        $this->assertSame('published', $ep->status);

        $sources = $ep->getSources(false);
        $this->assertCount(1, $sources);
        $this->assertSame('embed', $sources[0]->source_type);
        $this->assertSame('https://player.vimeo.com/video/12345678', $sources[0]->url_or_path);
    }

    // 11. Songs Parity: Dual Mode Song with audio and video embed + Publish Now
    public function testCreateSongWithAudioAndVideoEmbedPublishNow(): void
    {
        $req = new Request([], [
            '_token'             => 'valid_test_token',
            'action'             => 'create',
            'title'              => 'Dual Mode Hit',
            'slug'               => 'dual-mode-hit',
            'playback_type'      => 'audio_video',
            'access_mode'        => 'public',
            'download_policy'    => 'inherit',
            'status'             => 'published',
            'submit_action'      => 'publish',
            'audio_url'          => 'https://example.com/song.mp3',
            'audio_source_label' => 'Main Audio',
            'video_url'          => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_source_label' => 'Official Music Video',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-songs']);

        $res = $this->adminCtrl->songs($req);
        $this->assertInstanceOf(Response::class, $res);

        $song = Song::findBySlug('dual-mode-hit');
        $this->assertNotNull($song);
        $this->assertSame('published', $song->status);

        $audioSources = $song->getAudioSources(false);
        $this->assertCount(1, $audioSources);
        $this->assertSame('audio', $audioSources[0]->source_type);

        $videoSources = $song->getVideoSources(false);
        $this->assertCount(1, $videoSources);
        $this->assertSame('embed', $videoSources[0]->source_type);
    }

    // 12. View Inspection: movies.php has no nested <form> tags inside fav_movie_form
    public function testMovieFormViewContainsNoNestedForms(): void
    {
        $viewFile = APP_ROOT . '/plugins/favorite-multimedia/views/admin/movies.php';
        $this->assertFileExists($viewFile);
        $content = (string)file_get_contents($viewFile);

        // Find the main form boundaries
        $mainFormPos = strpos($content, 'id="fav_movie_form"');
        $this->assertNotFalse($mainFormPos, 'fav_movie_form must exist');

        $mainFormClosePos = strpos($content, '</form>', $mainFormPos);
        $this->assertNotFalse($mainFormClosePos, 'Closing form tag must exist');

        $mainFormInner = substr($content, $mainFormPos + strlen('id="fav_movie_form"'), $mainFormClosePos - $mainFormPos);

        // Assert NO <form tag appears inside the main form
        $this->assertStringNotContainsString('<form', $mainFormInner, 'Main movie form must NOT contain any nested <form> tags');
        $this->assertStringNotContainsString('<form id="form_reorder_up_', $mainFormInner, 'Auxiliary action forms must be outside the main form');
        $this->assertStringNotContainsString('<form id="form_add_another_source', $mainFormInner, 'Add Another Source subform must be outside the main form');

        // Confirm auxiliary forms exist AFTER closing tag
        $afterForm = substr($content, $mainFormClosePos);
        $this->assertStringContainsString('<form id="form_reorder_up_', $afterForm, 'Auxiliary forms must be positioned outside main form');
        $this->assertStringContainsString('<form id="form_add_another_source"', $afterForm, 'Add source subform must be positioned outside main form');
    }

    // 13. View Inspection: episodes.php has no nested <form> tags
    public function testEpisodeFormViewContainsNoNestedForms(): void
    {
        $viewFile = APP_ROOT . '/plugins/favorite-multimedia/views/admin/episodes.php';
        $this->assertFileExists($viewFile);
        $content = (string)file_get_contents($viewFile);

        $mainFormPos = strpos($content, '<form method="POST" action="/admin/page/multimedia-episodes"');
        $this->assertNotFalse($mainFormPos, 'Main episode form must exist');

        $mainFormClosePos = strpos($content, '</form>', $mainFormPos);
        $this->assertNotFalse($mainFormClosePos, 'Closing episode form tag must exist');

        $mainFormInner = substr($content, $mainFormPos + 50, $mainFormClosePos - ($mainFormPos + 50));

        $this->assertStringNotContainsString('<form', $mainFormInner, 'Main episode form must NOT contain any nested <form> tags');
        $this->assertStringNotContainsString('<form id="form_reorder_up_ep_', $mainFormInner, 'Auxiliary action forms must be outside the main form');

        $afterForm = substr($content, $mainFormClosePos);
        $this->assertStringContainsString('<form id="form_reorder_up_ep_', $afterForm);
        $this->assertStringContainsString('<form id="form_add_another_source_ep"', $afterForm);
    }

    // 14. Sidebar Navigation: Duplicate child submenu is NOT registered; line 440 serves as canonical Dashboard
    public function testSidebarDashboardSubmenuRegistered(): void
    {
        $pluginFile = APP_ROOT . '/plugins/favorite-multimedia/src/FavoriteMultimediaPlugin.php';
        $content = (string)file_get_contents($pluginFile);

        $this->assertStringNotContainsString("add_admin_submenu('multimedia', 'multimedia-dashboard', 'Dashboard'", $content, 'Duplicate multimedia-dashboard child submenu must NOT be registered');
        $this->assertStringContainsString("add_admin_menu(\n            'multimedia',\n            'Multimedia'", $content, 'Top-level multimedia menu must route to canonical Dashboard');
    }

    // 15. Sidebar Navigation: CSS/DOM cleanup hacks are completely removed and markup is clean
    public function testSidebarDuplicateCleanupMarkupInjected(): void
    {
        $rendered = $this->adminCtrl->dashboard(new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/multimedia']));

        $this->assertStringNotContainsString('.wp-submenu > li:has(> a[href="/admin/page/multimedia"])', $rendered, 'Cleanup CSS rule must NOT be present');
        $this->assertStringNotContainsString('a.textContent.trim().toLowerCase() === \'multimedia\'', $rendered, 'DOM removal fallback script must NOT be present');
        $this->assertStringContainsString('fav-admin-wrap', $rendered, 'Clean dashboard markup must be rendered');
        $this->assertStringContainsString('Multimedia Management Hub', $rendered);
    }

    // 16. Step 18 Exact Custom Domain Test: rasta428jem.com configured and tested
    public function testExactCustomDomainRastaEmbedPublishSucceeds(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', "https://rasta428jem.com/\nexample.com");
        Setting::clearCache();

        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'title'         => 'Rasta Feature Movie',
            'slug'          => 'rasta-feature-movie',
            'access_mode'   => 'public',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => 'https://rasta428jem.com/play/ftt29330744',
            'source_label'  => 'Rasta Player',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('rasta-feature-movie');
        $this->assertNotNull($movie);
        $this->assertSame('published', $movie->status);

        $sources = $movie->getSources(false);
        $this->assertCount(1, $sources);
        $this->assertSame('embed', $sources[0]->source_type);
        $this->assertSame('https://rasta428jem.com/play/ftt29330744', $sources[0]->url_or_path);
        $this->assertSame('text/html', $sources[0]->mime_type);
        $this->assertSame('video', $sources[0]->media_kind);
        $this->assertSame(1, (int)$sources[0]->is_default);
        $this->assertSame('active', $sources[0]->status);
    }

    // 17. Spoofed Hostname and Unsafe Scheme Rejection
    public function testDomainMatchingRejectsSpoofingAndUnsafeSchemes(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', "rasta428jem.com\n*.trustedcdn.net");
        Setting::clearCache();

        // Exact match passes
        $this->assertTrue(MediaSourceResolver::isEmbedDomainAllowed('https://rasta428jem.com/play/123'));
        $this->assertTrue(MediaSourceResolver::isEmbedDomainAllowed('https://player.trustedcdn.net/video/123'));

        // Spoofing hostnames must be strictly rejected
        $this->assertFalse(MediaSourceResolver::isEmbedDomainAllowed('https://rasta428jem.com.attacker.net/play/123'));
        $this->assertFalse(MediaSourceResolver::isEmbedDomainAllowed('https://attackerrasta428jem.com/play/123'));
        $this->assertFalse(MediaSourceResolver::isEmbedDomainAllowed('https://evil-trustedcdn.net/video/123'));

        // Unsafe schemes must be rejected
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('javascript:alert(1)')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('data:text/html;base64,PHNjcmlwdD4=')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('file:///etc/passwd')['safe']);

        // Private / loopback IPs must be rejected
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('http://127.0.0.1:8080/embed')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('http://10.0.0.1/player')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('http://192.168.1.1/video')['safe']);
        $this->assertFalse(MediaSourceResolver::validateUrlSecurity('http://localhost/embed')['safe']);
    }

    // 18. Episode External Embed Inline Save & Publish
    public function testEpisodeExternalEmbedSaveAndPublish(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', "rasta428jem.com");
        Setting::clearCache();

        // Create series and season first
        $seriesId = (int)$this->db->insert('multimedia_series', [
            'title' => 'Test Series', 'slug' => 'test-series', 'status' => 'published', 'access_mode' => 'public',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $seasonId = (int)$this->db->insert('multimedia_seasons', [
            'series_id' => $seriesId, 'season_number' => 1, 'title' => 'Season 1',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'series_id'     => (string)$seriesId,
            'season_id'     => (string)$seasonId,
            'episode_number' => '1',
            'title'         => 'Pilot Episode',
            'slug'          => 'pilot-episode',
            'access_mode'   => 'public',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => 'https://rasta428jem.com/play/ftt29330744',
            'source_label'  => 'Episode Stream',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-episodes']);

        $res = $this->adminCtrl->episodes($req);
        $this->assertInstanceOf(Response::class, $res);

        $ep = Episode::findBySlug('pilot-episode');
        $this->assertNotNull($ep);
        $this->assertSame('published', $ep->status);

        $sources = MediaSource::getForContent('episode', (int)$ep->id, false);
        $this->assertCount(1, $sources);
        $this->assertSame('embed', $sources[0]->source_type);
        $this->assertSame('https://rasta428jem.com/play/ftt29330744', $sources[0]->url_or_path);
        $this->assertSame('video', $sources[0]->media_kind);
    }

    // 19. Song Video External Embed Save & Publish
    public function testSongVideoExternalEmbedSaveAndPublish(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', "rasta428jem.com");
        Setting::clearCache();

        $req = new Request([], [
            '_token'             => 'valid_test_token',
            'action'             => 'create',
            'id'                 => '0',
            'title'              => 'Music Video Track',
            'slug'               => 'music-video-track',
            'playback_type'      => Song::PLAYBACK_VIDEO,
            'access_mode'        => 'public',
            'status'             => 'published',
            'submit_action'      => 'publish',
            'source_type'        => 'embed',
            'video_url'          => 'https://rasta428jem.com/play/ftt29330744',
            'video_source_label' => 'External Player',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-songs']);

        $res = $this->adminCtrl->songs($req);
        $this->assertInstanceOf(Response::class, $res);

        $song = Song::findBySlug('music-video-track');
        $this->assertNotNull($song);
        $this->assertSame('published', $song->status);

        $videoSources = $song->getVideoSources(false);
        $this->assertCount(1, $videoSources);
        $this->assertSame('embed', $videoSources[0]->source_type);
        $this->assertSame('https://rasta428jem.com/play/ftt29330744', $videoSources[0]->url_or_path);
        $this->assertSame('video', $videoSources[0]->media_kind);
    }

    // 20. YouTube Regression: Working YouTube logic remains 100% functional
    public function testYouTubeFormSaveAndPlaybackRegression(): void
    {
        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'title'         => 'Classic YouTube Video',
            'slug'          => 'classic-youtube-video',
            'access_mode'   => 'public',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'auto',
            'video_url'     => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'source_label'  => 'Official YouTube',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('classic-youtube-video');
        $this->assertNotNull($movie);
        $this->assertSame('published', $movie->status);

        $sources = $movie->getSources(false);
        $this->assertCount(1, $sources);
        $this->assertSame('embed', $sources[0]->source_type);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $sources[0]->url_or_path);
    }

    // 21. Multi-source: Attaching additional embed source via add_source preserves distinct sources
    public function testMultiSourcePreservesDistinctSources(): void
    {
        Setting::set('multimedia', 'trusted_embed_domains', "rasta428jem.com");
        Setting::clearCache();

        // 1. Create Movie with primary direct video
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title' => 'Multi-Source Movie', 'slug' => 'multi-source-movie', 'status' => 'published', 'access_mode' => 'public',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->insert('multimedia_sources', [
            'content_type' => 'movie', 'content_id' => $movieId, 'media_kind' => 'video',
            'source_mode' => 'url', 'source_type' => 'video', 'url_or_path' => 'https://example.com/stream.mp4',
            'label' => 'Server 1', 'is_default' => 1, 'status' => 'active', 'sort_order' => 1,
        ]);

        // 2. Add second source via add_source
        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'add_source',
            'content_type'  => 'movie',
            'content_id'    => (string)$movieId,
            'source_type'   => 'embed',
            'video_url'     => 'https://rasta428jem.com/play/ftt29330744',
            'source_label'  => 'Server 2 (Embed)',
            'is_default'    => '0',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-sources']);

        $res = $this->adminCtrl->sources($req);
        $this->assertInstanceOf(Response::class, $res);

        $sources = MediaSource::getForContent('movie', $movieId, false);
        $this->assertCount(2, $sources);
        $this->assertSame('video', $sources[0]->source_type);
        $this->assertSame(1, (int)$sources[0]->is_default);
        $this->assertSame('embed', $sources[1]->source_type);
        $this->assertSame('https://rasta428jem.com/play/ftt29330744', $sources[1]->url_or_path);
        $this->assertSame(0, (int)$sources[1]->is_default);
    }

    // 22. Untrusted Embed Domain rejects with explicit error and sets draft
    public function testUntrustedEmbedDomainRejectionWithExplicitError(): void
    {
        $req = new Request([], [
            '_token'        => 'valid_test_token',
            'action'        => 'create',
            'id'            => '0',
            'title'         => 'Untrusted Movie',
            'slug'          => 'untrusted-movie',
            'access_mode'   => 'public',
            'status'        => 'published',
            'submit_action' => 'publish',
            'source_type'   => 'embed',
            'video_url'     => 'https://rogue-player.xyz/embed/1234',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-movies']);

        $res = $this->adminCtrl->movies($req);
        $this->assertInstanceOf(Response::class, $res);

        $movie = Movie::findBySlug('untrusted-movie');
        $this->assertNotNull($movie);
        $this->assertSame('draft', $movie->status);

        $sources = $movie->getSources(false);
        $this->assertCount(0, $sources);

        $this->assertStringContainsString('The domain for this embed player is not in the trusted allowlist', $_SESSION['flash_error'] ?? '');
    }

    // 23. Settings textarea cleaning and normalization
    public function testSettingsCleaningTrustedEmbedDomains(): void
    {
        $req = new Request([], [
            '_token'                => 'valid_test_token',
            'enable_downloads'      => 'yes',
            'default_video_resolution' => '1080p',
            'hls_autoplay'          => 'no',
            'player_theme_color'    => '#2563eb',
            'enable_discovery'      => 'yes',
            'enable_trending'       => 'yes',
            'trending_window_days'  => '7',
            'max_discovery_items'   => '10',
            'review_moderation_mode' => 'auto_approve',
            'trusted_embed_domains' => "https://rasta428jem.com/\n*.cdnstream.io:443\n  player.example.com/play  ",
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/multimedia-settings']);

        $res = $this->adminCtrl->settings($req);
        $this->assertInstanceOf(Response::class, $res);

        $stored = (string)Setting::get('multimedia', 'trusted_embed_domains', '');
        $this->assertStringContainsString("rasta428jem.com", $stored);
        $this->assertStringNotContainsString("https://", $stored);
        $this->assertStringNotContainsString(":443", $stored);
        $this->assertStringNotContainsString("/play", $stored);
    }
}

