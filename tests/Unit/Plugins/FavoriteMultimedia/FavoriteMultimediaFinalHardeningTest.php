<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Controllers\MediaPlaybackController;
use FavoriteCMS\Multimedia\Controllers\MultimediaAdminController;
use FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController;
use FavoriteCMS\Multimedia\FavoriteMultimediaPlugin;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\UploadSecurityService;
use PHPUnit\Framework\TestCase;

class FavoriteMultimediaFinalHardeningTest extends TestCase
{
    private Application $app;
    private Database $db;
    private string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 4));
        }
        require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

        $this->tempDb = sys_get_temp_dir() . '/test_fmm_final_hardening_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $this->app = new Application(APP_ROOT);
        Application::setInstance($this->app);
        Container::setInstance($this->app);

        $this->db = new Database(['driver' => 'sqlite', 'database' => $this->tempDb, 'prefix' => '']);
        $ref = new \ReflectionProperty(Database::class, 'pdo');
        $ref->setValue($this->db, $pdo);

        $config = new Config([]);
        $this->app->instance(Config::class, $config);
        $this->app->instance('config', $config);
        $this->app->instance(Database::class, $this->db);
        $this->app->instance('db', $this->db);

        $this->createSchema($pdo);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['_token'] = 'final_csrf_token_test';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        parent::tearDown();
    }

    private function createSchema(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username VARCHAR(50), name VARCHAR(100), email VARCHAR(100), password VARCHAR(255), status VARCHAR(20), role VARCHAR(50) DEFAULT 'subscriber', email_verified_at DATETIME, created_at DATETIME, updated_at DATETIME);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50), description TEXT, created_at DATETIME, updated_at DATETIME);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, group_name VARCHAR(50), setting_key VARCHAR(50), value TEXT, type VARCHAR(20), is_public INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (group_name, setting_key));");

        FavoriteMultimediaPlugin::reset();
        $plugin = FavoriteMultimediaPlugin::bootstrap($this->app);
        $plugin->runMigrations();

        $pdo->exec("INSERT INTO roles (id, name, slug) VALUES 
            (1, 'Super Administrator', 'super-admin'),
            (2, 'Administrator', 'admin'),
            (3, 'Moderator', 'moderator'),
            (4, 'Author', 'author'),
            (5, 'Contributor', 'contributor'),
            (6, 'Subscriber', 'subscriber')");

        $now = date('Y-m-d H:i:s');
        // Super Admin (ID 100)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (100, 'superadmin', 'Super Administrator', 'superadmin@example.com', 'pwd', 'super-admin', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (100, 1)");

        // Admin (ID 101)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (101, 'siteadmin', 'Site Administrator', 'siteadmin@example.com', 'pwd', 'admin', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (101, 2)");

        // Moderator (ID 102)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (102, 'moduser', 'Content Moderator', 'mod@example.com', 'pwd', 'moderator', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (102, 3)");

        // Author User A (ID 201)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (201, 'authorA', 'Author User A', 'authorA@example.com', 'pwd', 'author', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (201, 4)");

        // Author User B (ID 202)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (202, 'authorB', 'Author User B', 'authorB@example.com', 'pwd', 'author', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (202, 4)");

        // Subscriber (ID 301)
        $pdo->exec("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (301, 'subscriber1', 'Standard Subscriber', 'sub1@example.com', 'pwd', 'subscriber', 'active', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (301, 6)");
    }

    private function setUser(int $userId): User
    {
        $_SESSION['auth_user_id'] = $userId;
        $user = User::find($userId);
        $this->assertNotNull($user, "User ID {$userId} should exist in test database");
        return $user;
    }

    private function clearUser(): void
    {
        unset($_SESSION['auth_user_id']);
    }

    // =========================================================================
    // SECTION 1: LEGACY OWNERSHIP FALLBACK HARDENING (Tests 1-4)
    // =========================================================================

    public function testLegacyOwnershipAssignsSuperAdminWhenPresent(): void
    {
        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/013_add_multimedia_ownership_and_moderation_fields.php';
        $migration = new \AddMultimediaOwnershipAndModerationFields($this->db);
        $refMethod = new \ReflectionMethod($migration, 'resolveCanonicalAdminId');
        
        $resolved = $refMethod->invoke($migration, $this->db);
        $this->assertEquals(100, $resolved, 'Super-admin (ID 100) must be resolved as highest priority canonical owner');
    }

    public function testLegacyOwnershipFallsBackToAdminWhenNoSuperAdmin(): void
    {
        // Deactivate or delete super admin
        $this->db->execute("DELETE FROM users WHERE id = 100");
        $this->db->execute("DELETE FROM user_roles WHERE user_id = 100");

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/013_add_multimedia_ownership_and_moderation_fields.php';
        $migration = new \AddMultimediaOwnershipAndModerationFields($this->db);
        $refMethod = new \ReflectionMethod($migration, 'resolveCanonicalAdminId');

        $resolved = $refMethod->invoke($migration, $this->db);
        $this->assertEquals(101, $resolved, 'Admin (ID 101) must be resolved when no active super-admin exists');
    }

    public function testLegacyOwnershipFallsBackToNullWhenNoAdminOrSuperAdmin(): void
    {
        // Remove all admins and super-admins
        $this->db->execute("DELETE FROM users WHERE id IN (100, 101)");
        $this->db->execute("DELETE FROM user_roles WHERE user_id IN (100, 101)");

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/013_add_multimedia_ownership_and_moderation_fields.php';
        $migration = new \AddMultimediaOwnershipAndModerationFields($this->db);
        $refMethod = new \ReflectionMethod($migration, 'resolveCanonicalAdminId');

        $resolved = $refMethod->invoke($migration, $this->db);
        $this->assertNull($resolved, 'Resolver must return NULL when neither super-admin nor admin exists');
    }

    public function testLegacyOwnershipNeverFallsBackToLowestArbitraryUser(): void
    {
        // Create an arbitrary normal user with lowest ID 1
        $now = date('Y-m-d H:i:s');
        $this->db->execute("INSERT INTO users (id, username, name, email, password, role, status, created_at, updated_at) 
            VALUES (1, 'lowsubscriber', 'Lowest Subscriber', 'low@example.com', 'pwd', 'subscriber', 'active', '{$now}', '{$now}')");
        $this->db->execute("INSERT INTO user_roles (user_id, role_id) VALUES (1, 6)");

        // Remove admins
        $this->db->execute("DELETE FROM users WHERE id IN (100, 101)");
        $this->db->execute("DELETE FROM user_roles WHERE user_id IN (100, 101)");

        require_once APP_ROOT . '/plugins/favorite-multimedia/database/migrations/013_add_multimedia_ownership_and_moderation_fields.php';
        $migration = new \AddMultimediaOwnershipAndModerationFields($this->db);
        $refMethod = new \ReflectionMethod($migration, 'resolveCanonicalAdminId');

        $resolved = $refMethod->invoke($migration, $this->db);
        $this->assertNull($resolved, 'Resolver must NEVER fall back to lowest active arbitrary subscriber');
        $this->assertNotEquals(1, $resolved);

        // Also test static method MultimediaPermission::resolveCanonicalAdminId
        $permAdminId = MultimediaPermission::resolveCanonicalAdminId($this->db);
        $this->assertNull($permAdminId, 'MultimediaPermission resolver must also return NULL and never select ID 1');
    }

    // =========================================================================
    // SECTION 2: SVG & UPLOAD SECURITY HARDENING (Tests 5-9)
    // =========================================================================

    public function testUploadSecurityRejectsDirectSvgExtension(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_svg_');
        file_put_contents($tmpFile, '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="40"/></svg>');

        $res = UploadSecurityService::validate([
            'tmp_name' => $tmpFile,
            'name'     => 'malicious_icon.svg',
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int)filesize($tmpFile),
        ], UploadSecurityService::CATEGORY_IMAGE);

        @unlink($tmpFile);
        $this->assertFalse($res['valid'], 'Direct .svg upload must be rejected');
        $this->assertStringContainsString('prohibited', strtolower($res['error'] ?? ''));
    }

    public function testUploadSecurityRejectsDirectSvgzExtension(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_svgz_');
        $svg = '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="40"/></svg>';
        file_put_contents($tmpFile, gzencode($svg));

        $res = UploadSecurityService::validate([
            'tmp_name' => $tmpFile,
            'name'     => 'archive_logo.svgz',
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int)filesize($tmpFile),
        ], UploadSecurityService::CATEGORY_IMAGE);

        @unlink($tmpFile);
        $this->assertFalse($res['valid'], 'Direct .svgz upload must be rejected');
        $this->assertStringContainsString('prohibited', strtolower($res['error'] ?? ''));
    }

    public function testUploadSecurityRejectsDisguisedSvgWithScript(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_fakejpg_');
        $payload = "GIF89a\x01\x00\x01\x00\x80\x00\x00<svg><script>alert('xss')</script></svg>";
        file_put_contents($tmpFile, $payload);

        $res = UploadSecurityService::validate([
            'tmp_name' => $tmpFile,
            'name'     => 'photo.jpg',
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int)filesize($tmpFile),
        ], UploadSecurityService::CATEGORY_IMAGE);

        @unlink($tmpFile);
        $this->assertFalse($res['valid'], 'Disguised JPEG containing script-bearing SVG must be rejected');
        $this->assertTrue(UploadSecurityService::containsSvgOrScriptPayload($tmpFile) || !$res['valid']);
    }

    public function testUploadSecurityRejectsDisguisedSvgWithEventHandlers(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_event_');
        $payload = '<svg xmlns="http://www.w3.org/2000/svg" onload="fetch(\'//evil.com\')"><rect/></svg>';
        file_put_contents($tmpFile, $payload);

        $hasPayload = UploadSecurityService::containsSvgOrScriptPayload($tmpFile);
        @unlink($tmpFile);

        $this->assertTrue($hasPayload, 'containsSvgOrScriptPayload must detect inline event handlers in SVG');
    }

    public function testUploadSecurityRejectsDisguisedSvgWithForeignObject(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_foreign_');
        $payload = '<svg><foreignObject width="100" height="50"><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject></svg>';
        file_put_contents($tmpFile, $payload);

        $hasPayload = UploadSecurityService::containsSvgOrScriptPayload($tmpFile);
        @unlink($tmpFile);

        $this->assertTrue($hasPayload, 'containsSvgOrScriptPayload must detect foreignObject and embedded script');
    }

    // =========================================================================
    // SECTION 3: ALBUM MODERATION WORKFLOW (Tests 10-16)
    // =========================================================================

    public function testAlbumCreatedByAuthorIsForcedToPending(): void
    {
        $user = $this->setUser(201); // Author
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'        => 'create',
            'title'         => 'Author New Album',
            'slug'          => 'author-new-album',
            'submit_action' => 'publish', // Author requests immediate publish
            '_token'        => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->albums($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $album = Album::findBySlug('author-new-album');
        $this->assertNotNull($album);
        $this->assertEquals('pending', $album->status, 'Album created by author must be forced to pending review');
        $this->assertEquals(201, (int)$album->user_id);
        $this->assertNull($album->approved_by);
        $this->assertNull($album->approved_at);
    }

    public function testAlbumApprovedByModeratorBecomesPublishedWithAuditFields(): void
    {
        $author = $this->setUser(201);
        $albumId = (int)$this->db->insert('multimedia_albums', [
            'title'   => 'Pending Test Album',
            'slug'    => 'pending-test-album',
            'user_id' => 201,
            'status'  => 'pending',
        ]);

        // Switch to moderator
        $mod = $this->setUser(102);
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'       => 'approve',
            'target_type'  => 'content',
            'content_type' => 'album',
            'target_id'    => $albumId,
            '_token'       => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->moderation($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $album = Album::find($albumId);
        $this->assertEquals('published', $album->status);
        $this->assertEquals(102, (int)$album->approved_by);
        $this->assertNotNull($album->approved_at);
        $this->assertNull($album->rejected_by);
        $this->assertNull($album->rejection_reason);
    }

    public function testAlbumRejectedByModeratorBecomesRejectedWithReason(): void
    {
        $albumId = (int)$this->db->insert('multimedia_albums', [
            'title'   => 'Album To Reject',
            'slug'    => 'album-to-reject',
            'user_id' => 201,
            'status'  => 'pending',
        ]);

        $mod = $this->setUser(102);
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'           => 'reject',
            'target_type'      => 'content',
            'content_type'     => 'album',
            'target_id'        => $albumId,
            'rejection_reason' => 'Cover image quality does not meet resolution standards.',
            '_token'           => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->moderation($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $album = Album::find($albumId);
        $this->assertEquals('rejected', $album->status);
        $this->assertEquals(102, (int)$album->rejected_by);
        $this->assertEquals('Cover image quality does not meet resolution standards.', $album->rejection_reason);
    }

    public function testAlbumResubmissionByAuthorResetsToPendingAndClearsRejectionAudit(): void
    {
        $albumId = (int)$this->db->insert('multimedia_albums', [
            'title'            => 'Rejected Album',
            'slug'             => 'rejected-album',
            'user_id'          => 201,
            'status'           => 'rejected',
            'rejected_by'      => 102,
            'rejected_at'      => date('Y-m-d H:i:s'),
            'rejection_reason' => 'Fix cover image resolution',
        ]);

        $author = $this->setUser(201);
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'        => 'edit',
            'id'            => $albumId,
            'title'         => 'Rejected Album - Fixed Cover',
            'slug'          => 'rejected-album',
            'submit_action' => 'pending',
            '_token'        => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->albums($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $album = Album::find($albumId);
        $this->assertEquals('pending', $album->status);
        $this->assertNull($album->rejected_by, 'Resubmission must clear rejected_by');
        $this->assertNull($album->rejected_at, 'Resubmission must clear rejected_at');
        $this->assertNull($album->rejection_reason, 'Resubmission must clear rejection_reason');
    }

    public function testUnpublishedAlbumIsHiddenFromPublicListing(): void
    {
        $this->db->insert('multimedia_albums', [
            'title'   => 'Live Published Album',
            'slug'    => 'live-published-album',
            'status'  => 'published',
            'user_id' => 101,
        ]);
        $this->db->insert('multimedia_albums', [
            'title'   => 'Secret Pending Album',
            'slug'    => 'secret-pending-album',
            'status'  => 'pending',
            'user_id' => 201,
        ]);
        $this->db->insert('multimedia_albums', [
            'title'   => 'Rejected Bad Album',
            'slug'    => 'rejected-bad-album',
            'status'  => 'rejected',
            'user_id' => 201,
        ]);

        $published = Album::published();
        $slugs = array_map(fn($a) => $a->slug, $published);

        $this->assertContains('live-published-album', $slugs);
        $this->assertNotContains('secret-pending-album', $slugs, 'Pending album must not appear in published list');
        $this->assertNotContains('rejected-bad-album', $slugs, 'Rejected album must not appear in published list');
    }

    public function testDirectUnpublishedAlbumUrlReturns404ForGuests(): void
    {
        $albumId = (int)$this->db->insert('multimedia_albums', [
            'title'   => 'Pending Album 404 Test',
            'slug'    => 'pending-album-404-test',
            'user_id' => 201,
            'status'  => 'pending',
        ]);

        $frontendCtrl = new MultimediaFrontendController($this->app);

        // Guest visitor (not authenticated)
        $this->clearUser();
        $guestReq = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $frontendCtrl->album($guestReq, 'pending-album-404-test');
        $this->assertEquals(404, $resp->getStatusCode(), 'Guest direct visit to unpublished album must return 404');

        // Author visitor (creator) can view
        $this->setUser(201);
        $authorResp = $frontendCtrl->album($guestReq, 'pending-album-404-test');
        $this->assertEquals(200, $authorResp->getStatusCode(), 'Author must be able to view their pending album');

        // Moderator visitor can view
        $this->setUser(102);
        $modResp = $frontendCtrl->album($guestReq, 'pending-album-404-test');
        $this->assertEquals(200, $modResp->getStatusCode(), 'Moderator must be able to preview pending album');
    }

    public function testCrossUserAlbumEditingIsForbidden(): void
    {
        $albumId = (int)$this->db->insert('multimedia_albums', [
            'title'   => 'Author A Album',
            'slug'    => 'author-a-album',
            'user_id' => 201, // Created by User A
            'status'  => 'pending',
        ]);

        // User B attempts to edit User A's album
        $this->setUser(202); // User B
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'      => 'edit',
            'id'          => $albumId,
            'title'       => 'Hacked by User B',
            '_token'      => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->albums($req);
        $this->assertEquals(403, $resp->getStatusCode(), 'Cross-user album editing must be rejected with 403 Forbidden');

        // Verify content untouched
        $album = Album::find($albumId);
        $this->assertEquals('Author A Album', $album->title);
    }

    // =========================================================================
    // SECTION 4: PLAYLIST MODERATION & PERSONAL PRESERVATION (Tests 17-24)
    // =========================================================================

    public function testPublicPlaylistCreatedByAuthorIsForcedToPending(): void
    {
        $author = $this->setUser(201);
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'      => 'create',
            'title'       => 'Public EDM Hits',
            'slug'        => 'public-edm-hits',
            'access_mode' => 'public',
            'status'      => 'published', // Requesting immediate publish
            '_token'      => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->playlists($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $pl = Playlist::findBySlug('public-edm-hits');
        $this->assertNotNull($pl);
        $this->assertEquals('pending', $pl->status, 'Public playlist created by author must be forced to pending review');
        $this->assertEquals(201, (int)$pl->user_id);
    }

    public function testPublicPlaylistApprovedByModeratorBecomesPublishedWithAuditFields(): void
    {
        $plId = (int)$this->db->insert('multimedia_playlists', [
            'title'       => 'Pending Public Playlist',
            'slug'        => 'pending-public-playlist',
            'user_id'     => 201,
            'status'      => 'pending',
            'access_mode' => 'public',
        ]);

        $this->setUser(102); // Moderator
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'       => 'approve',
            'target_type'  => 'content',
            'content_type' => 'playlist',
            'target_id'    => $plId,
            '_token'       => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->moderation($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $pl = Playlist::find($plId);
        $this->assertEquals('published', $pl->status);
        $this->assertEquals(102, (int)$pl->approved_by);
        $this->assertNotNull($pl->approved_at);
        $this->assertNull($pl->rejected_by);
    }

    public function testPublicPlaylistRejectedByModeratorBecomesRejectedWithReason(): void
    {
        $plId = (int)$this->db->insert('multimedia_playlists', [
            'title'       => 'Spam Playlist',
            'slug'        => 'spam-playlist',
            'user_id'     => 201,
            'status'      => 'pending',
            'access_mode' => 'public',
        ]);

        $this->setUser(102);
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'           => 'reject',
            'target_type'      => 'content',
            'content_type'     => 'playlist',
            'target_id'        => $plId,
            'rejection_reason' => 'Contains promotional spam and uncurated tracks.',
            '_token'           => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->moderation($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $pl = Playlist::find($plId);
        $this->assertEquals('rejected', $pl->status);
        $this->assertEquals(102, (int)$pl->rejected_by);
        $this->assertEquals('Contains promotional spam and uncurated tracks.', $pl->rejection_reason);
    }

    public function testPublicPlaylistResubmissionByAuthorResetsToPendingAndClearsRejection(): void
    {
        $plId = (int)$this->db->insert('multimedia_playlists', [
            'title'            => 'Rejected Playlist',
            'slug'             => 'rejected-playlist',
            'user_id'          => 201,
            'status'           => 'rejected',
            'access_mode'      => 'public',
            'rejected_by'      => 102,
            'rejection_reason' => 'Fix spam items',
        ]);

        $this->setUser(201);
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'      => 'edit',
            'id'          => $plId,
            'title'       => 'Cleaned Up Playlist',
            'slug'        => 'rejected-playlist',
            'access_mode' => 'public',
            'status'      => 'pending',
            '_token'      => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->playlists($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $pl = Playlist::find($plId);
        $this->assertEquals('pending', $pl->status);
        $this->assertNull($pl->rejected_by, 'Resubmission must clear rejected_by');
        $this->assertNull($pl->rejection_reason, 'Resubmission must clear rejection_reason');
    }

    public function testUnpublishedPlaylistIsHiddenFromPublicListing(): void
    {
        $this->db->insert('multimedia_playlists', [
            'title'       => 'Live Public Playlist',
            'slug'        => 'live-public-playlist',
            'status'      => 'published',
            'access_mode' => 'public',
            'user_id'     => 101,
        ]);
        $this->db->insert('multimedia_playlists', [
            'title'       => 'Pending Review Playlist',
            'slug'        => 'pending-review-playlist',
            'status'      => 'pending',
            'access_mode' => 'public',
            'user_id'     => 201,
        ]);

        $published = Playlist::published();
        $slugs = array_map(fn($p) => $p->slug, $published);

        $this->assertContains('live-public-playlist', $slugs);
        $this->assertNotContains('pending-review-playlist', $slugs);
    }

    public function testDirectUnpublishedPlaylistUrlReturns404ForGuests(): void
    {
        $plId = (int)$this->db->insert('multimedia_playlists', [
            'title'       => 'Pending Playlist 404',
            'slug'        => 'pending-playlist-404',
            'user_id'     => 201,
            'status'      => 'pending',
            'access_mode' => 'public',
        ]);

        $frontendCtrl = new MultimediaFrontendController($this->app);

        // Guest visitor
        $this->clearUser();
        $guestReq = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $frontendCtrl->playlist($guestReq, 'pending-playlist-404');
        $this->assertEquals(404, $resp->getStatusCode(), 'Guest accessing unapproved playlist must receive 404');

        // Author visitor
        $this->setUser(201);
        $authorResp = $frontendCtrl->playlist($guestReq, 'pending-playlist-404');
        $this->assertEquals(200, $authorResp->getStatusCode(), 'Author must be able to view their pending playlist');
    }

    public function testCrossUserPlaylistEditingIsForbidden(): void
    {
        $plId = (int)$this->db->insert('multimedia_playlists', [
            'title'       => 'User A Private Playlist',
            'slug'        => 'user-a-private-pl',
            'user_id'     => 201,
            'status'      => 'published',
            'access_mode' => 'private',
        ]);

        $this->setUser(202); // User B
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'      => 'edit',
            'id'          => $plId,
            'title'       => 'User B hijacking',
            '_token'      => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->playlists($req);
        $this->assertEquals(403, $resp->getStatusCode(), 'Cross-user playlist editing must return 403 Forbidden');
    }

    public function testPrivatePlaylistPreservesPersonalStatusWithoutModerationQueue(): void
    {
        // Author creates a private playlist
        $this->setUser(201);
        $controller = new MultimediaAdminController($this->app);

        $req = new Request([], [
            'action'      => 'create',
            'title'       => 'My Personal Night Vibes',
            'slug'        => 'my-personal-night-vibes',
            'access_mode' => 'private',
            'status'      => 'published',
            '_token'      => 'final_csrf_token_test',
        ], ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->playlists($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $pl = Playlist::findBySlug('my-personal-night-vibes');
        $this->assertNotNull($pl);
        $this->assertEquals('published', $pl->status, 'Private playlist remains published/personal to user without being forced to pending review');
        $this->assertEquals('private', $pl->access_mode);

        // Verify private playlist is excluded from public Playlist::published() listing
        $published = Playlist::published();
        $slugs = array_map(fn($p) => $p->slug, $published);
        $this->assertNotContains('my-personal-night-vibes', $slugs, 'Private playlist must never appear in public directory');

        // Verify guest accessing private playlist receives 404
        $this->clearUser();
        $frontendCtrl = new MultimediaFrontendController($this->app);
        $guestResp = $frontendCtrl->playlist(new Request([], [], ['REQUEST_METHOD' => 'GET']), 'my-personal-night-vibes');
        $this->assertEquals(404, $guestResp->getStatusCode(), 'Private playlist must return 404 for unauthenticated visitors');
    }

    // =========================================================================
    // SECTION 5: PUBLIC & PLAYBACK ZERO-LEAKAGE AUDIT (Tests 25-34)
    // =========================================================================

    public function testDraftOrPendingMovieHiddenFromGuests(): void
    {
        $this->db->insert('multimedia_movies', [
            'title'   => 'Unreleased Draft Movie',
            'slug'    => 'unreleased-draft-movie',
            'status'  => 'draft',
            'user_id' => 101,
        ]);

        $this->clearUser();
        $frontendCtrl = new MultimediaFrontendController($this->app);
        $resp = $frontendCtrl->movie(new Request([], [], ['REQUEST_METHOD' => 'GET']), 'unreleased-draft-movie');
        $this->assertEquals(404, $resp->getStatusCode(), 'Guest must receive 404 for draft movie');
    }

    public function testDraftOrPendingSeriesHiddenFromGuests(): void
    {
        $this->db->insert('multimedia_series', [
            'title'   => 'Pending Web Series',
            'slug'    => 'pending-web-series',
            'status'  => 'pending',
            'user_id' => 201,
        ]);

        $this->clearUser();
        $frontendCtrl = new MultimediaFrontendController($this->app);
        $resp = $frontendCtrl->seriesSingle(new Request([], [], ['REQUEST_METHOD' => 'GET']), 'pending-web-series');
        $this->assertEquals(404, $resp->getStatusCode(), 'Guest must receive 404 for pending series');
    }

    public function testDraftOrPendingEpisodeHiddenFromGuests(): void
    {
        $seriesId = (int)$this->db->insert('multimedia_series', [
            'title'   => 'Live Series',
            'slug'    => 'live-series',
            'status'  => 'published',
            'user_id' => 101,
        ]);
        $seasonId = (int)$this->db->insert('multimedia_seasons', [
            'series_id'     => $seriesId,
            'season_number' => 1,
            'title'         => 'Season 1',
        ]);
        $this->db->insert('multimedia_episodes', [
            'series_id'      => $seriesId,
            'season_id'      => $seasonId,
            'episode_number' => 1,
            'title'          => 'Unapproved Episode',
            'slug'           => 'unapproved-episode',
            'status'         => 'pending',
            'user_id'        => 201,
        ]);

        $this->clearUser();
        $frontendCtrl = new MultimediaFrontendController($this->app);
        $resp = $frontendCtrl->episode(new Request([], [], ['REQUEST_METHOD' => 'GET']), 'unapproved-episode');
        $this->assertEquals(404, $resp->getStatusCode(), 'Guest must receive 404 for pending episode');
    }

    public function testEpisodeOfUnpublishedSeriesHiddenFromGuests(): void
    {
        $seriesId = (int)$this->db->insert('multimedia_series', [
            'title'   => 'Unpublished Series Parent',
            'slug'    => 'unpublished-series-parent',
            'status'  => 'draft',
            'user_id' => 101,
        ]);
        $seasonId = (int)$this->db->insert('multimedia_seasons', [
            'series_id'     => $seriesId,
            'season_number' => 1,
            'title'         => 'Season 1',
        ]);
        $this->db->insert('multimedia_episodes', [
            'series_id'      => $seriesId,
            'season_id'      => $seasonId,
            'episode_number' => 1,
            'title'          => 'Published Episode Child',
            'slug'           => 'published-episode-child',
            'status'         => 'published', // Marked published, but parent series is draft!
            'user_id'        => 101,
        ]);

        $this->clearUser();
        $frontendCtrl = new MultimediaFrontendController($this->app);
        $resp = $frontendCtrl->episode(new Request([], [], ['REQUEST_METHOD' => 'GET']), 'published-episode-child');
        $this->assertEquals(404, $resp->getStatusCode(), 'Episode of unreleased series must be hidden from guests');
    }

    public function testDraftOrPendingSongHiddenFromGuests(): void
    {
        $this->db->insert('multimedia_songs', [
            'title'   => 'Unapproved Song Single',
            'slug'    => 'unapproved-song-single',
            'status'  => 'pending',
            'user_id' => 201,
        ]);

        $this->clearUser();
        $frontendCtrl = new MultimediaFrontendController($this->app);
        $resp = $frontendCtrl->song(new Request([], [], ['REQUEST_METHOD' => 'GET']), 'unapproved-song-single');
        $this->assertEquals(404, $resp->getStatusCode(), 'Guest must receive 404 for pending song');
    }

    public function testSongOfUnpublishedAlbumHiddenFromGuests(): void
    {
        $albumId = (int)$this->db->insert('multimedia_albums', [
            'title'   => 'Unreleased Future Album',
            'slug'    => 'unreleased-future-album',
            'status'  => 'pending',
            'user_id' => 201,
        ]);
        $songId = (int)$this->db->insert('multimedia_songs', [
            'album_id' => $albumId,
            'title'    => 'Track from Unreleased Album',
            'slug'     => 'track-unreleased-album',
            'status'   => 'published',
            'user_id'  => 201,
        ]);

        $this->clearUser();
        $song = Song::find($songId);
        $accessState = MultimediaAccessService::checkAccess(null, 'song', $song);
        $this->assertEquals(MultimediaAccessService::NOT_FOUND, $accessState, 'Song belonging to unreleased album must return NOT_FOUND for guests');
    }

    public function testMediaStreamEndpointBlocksUnpublishedContent(): void
    {
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'   => 'Secret Vault Movie',
            'slug'    => 'secret-vault-movie',
            'status'  => 'pending',
            'user_id' => 201,
        ]);
        $sourceId = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'direct',
            'url_or_path'  => 'https://example.com/vault.mp4',
            'status'       => 'active',
        ]);

        $this->clearUser();
        $playbackCtrl = new MediaPlaybackController($this->app);
        $resp = $playbackCtrl->stream(new Request([], [], ['REQUEST_METHOD' => 'GET']), (string)$sourceId);
        $this->assertNotNull($resp);
        $this->assertEquals(404, $resp->getStatusCode(), 'Media stream endpoint must return 404 for unpublished media source');
    }

    public function testMediaDownloadEndpointBlocksUnpublishedContent(): void
    {
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'   => 'Secret Download Movie',
            'slug'    => 'secret-download-movie',
            'status'  => 'pending',
            'user_id' => 201,
        ]);
        $sourceId = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'direct',
            'url_or_path'  => 'https://example.com/secret_download.mp4',
            'status'       => 'active',
        ]);

        $this->clearUser();
        $playbackCtrl = new MediaPlaybackController($this->app);
        $resp = $playbackCtrl->download(new Request([], [], ['REQUEST_METHOD' => 'GET']), (string)$sourceId);
        $this->assertNotNull($resp);
        $this->assertEquals(404, $resp->getStatusCode(), 'Media download endpoint must return 404 for unpublished media source');
    }

    public function testPlayerEmbedEndpointBlocksUnpublishedContent(): void
    {
        $movieId = (int)$this->db->insert('multimedia_movies', [
            'title'   => 'Hidden Preview Movie',
            'slug'    => 'hidden-preview-movie',
            'status'  => 'pending',
            'user_id' => 201,
        ]);
        $sourceId = (int)$this->db->insert('multimedia_sources', [
            'content_type' => 'movie',
            'content_id'   => $movieId,
            'source_type'  => 'direct',
            'url_or_path'  => 'https://example.com/hidden.mp4',
            'status'       => 'active',
        ]);

        $this->clearUser();
        $playbackCtrl = new MediaPlaybackController($this->app);
        $resp = $playbackCtrl->player(new Request([], [], ['REQUEST_METHOD' => 'GET']), (string)$sourceId);
        $this->assertEquals(404, $resp->getStatusCode(), 'Player embed endpoint must return 404 for unapproved content');
    }

    public function testPlaylistTrackStreamingBlocksTracksOfUnpublishedPlaylists(): void
    {
        $songId = (int)$this->db->insert('multimedia_songs', [
            'title'   => 'Unpublished Track',
            'slug'    => 'unpublished-track',
            'status'  => 'published',
            'user_id' => 201,
        ]);
        $plId = (int)$this->db->insert('multimedia_playlists', [
            'title'       => 'Pending Secret Playlist',
            'slug'        => 'pending-secret-pl',
            'status'      => 'pending',
            'access_mode' => 'public',
            'user_id'     => 201,
        ]);

        $playlist = Playlist::find($plId);
        $song = Song::find($songId);

        $this->clearUser();
        $access = MultimediaAccessService::checkPlaylistTrackAccess(null, $playlist, $song);
        $this->assertEquals(MultimediaAccessService::NOT_FOUND, $access, 'Track access via unapproved playlist must return NOT_FOUND for public visitors');
    }
}

