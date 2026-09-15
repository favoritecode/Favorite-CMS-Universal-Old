<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Comment;

class RolePermissionMatrixTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    private static array $createdUserIds = [];
    private static array $createdPostIds = [];
    private static array $createdCommentIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);

        // Ensure roles exist
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (1, 'Super Admin', 'super-admin', 'Full system access', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (2, 'Admin', 'admin', 'Administrative access', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (3, 'Editor', 'editor', 'Can publish and manage posts and pages', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (4, 'Author', 'author', 'Can publish and manage own posts', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (5, 'Subscriber', 'subscriber', 'Basic subscriber role', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (6, 'Moderator', 'moderator', 'Can moderate comments and content', 1)");

        // Ensure user 1 exists with super-admin role
        $u1 = static::$db->selectOne("SELECT id FROM `users` WHERE `id` = 1");
        $now = date('Y-m-d H:i:s');
        if (!$u1) {
            static::$db->execute("INSERT INTO `users` (`id`, `username`, `name`, `email`, `password`, `status`, `email_verified_at`, `created_at`, `updated_at`) VALUES (1, 'admin', 'Administrator', 'admin@example.com', ?, 'active', ?, ?, ?)", [
                password_hash('AdminPassword123!', PASSWORD_DEFAULT),
                $now,
                $now,
                $now,
            ]);
        }
        static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (1, 1)");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$createdCommentIds)) {
            $in = implode(',', array_map('intval', static::$createdCommentIds));
            static::$db->execute("DELETE FROM `comments` WHERE `id` IN ({$in})");
        }
        if (!empty(static::$createdPostIds)) {
            $in = implode(',', array_map('intval', static::$createdPostIds));
            static::$db->execute("DELETE FROM `post_taxonomies` WHERE `post_id` IN ({$in})");
            static::$db->execute("DELETE FROM `seo_meta` WHERE `object_type` = 'post' AND `object_id` IN ({$in})");
            static::$db->execute("DELETE FROM `posts` WHERE `id` IN ({$in})");
        }
        if (!empty(static::$createdUserIds)) {
            $in = implode(',', array_map('intval', static::$createdUserIds));
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN ({$in})");
            static::$db->execute("DELETE FROM `users` WHERE `id` IN ({$in})");
        }
    }

    protected function setUp(): void
    {
        static::$app->setInstalled(true);
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [
            '_token' => 'test-token-12345',
        ];
        $_POST = [];
        $_GET = [];
    }

    protected function createTestUser(string $roleSlug, string $prefix = 'user'): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('TestPassword123!', PASSWORD_DEFAULT);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        static::$createdUserIds[] = $userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find($userId);
    }

    protected function createTestPost(int $authorId, string $status = 'pending'): Post
    {
        $unique = 'post_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $postId = static::$db->insert('posts', [
            'title'        => 'Test Post ' . $unique,
            'slug'         => $unique,
            'content'      => '<p>Original Content for ' . $unique . '</p>',
            'excerpt'      => 'Test excerpt',
            'status'       => $status,
            'type'         => 'post',
            'author_id'    => $authorId,
            'created_at'   => $now,
            'updated_at'   => $now,
            'published_at' => $status === 'published' ? $now : null,
        ]);

        static::$createdPostIds[] = $postId;
        return Post::find($postId);
    }

    protected function createTestComment(int $postId, int $userId, string $status = 'pending'): Comment
    {
        $now = date('Y-m-d H:i:s');
        $commentId = static::$db->insert('comments', [
            'post_id'         => $postId,
            'user_id'         => $userId,
            'author_name'     => 'Commenter',
            'author_email'    => 'commenter@example.com',
            'content'         => 'This is a test comment.',
            'status'          => $status,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        static::$createdCommentIds[] = $commentId;
        return Comment::find($commentId);
    }

    /**
     * 1. Editor can approve a pending post
     */
    public function testEditorCanApprovePendingPost(): void
    {
        $editor = $this->createTestUser('editor', 'ed_appr');
        $author = $this->createTestUser('author', 'aut_appr');
        $post = $this->createTestPost($author->id, 'pending');

        $this->assertTrue($editor->canModeratePosts());

        $_SESSION['auth_user_id'] = $editor->id;
        $req = Request::create('POST', '/admin/posts/approve?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(302, $resp->getStatusCode());
        $fresh = Post::find($post->id);
        $this->assertEquals('published', $fresh->status);
    }

    /**
     * 2. Editor can reject a pending post
     */
    public function testEditorCanRejectPendingPost(): void
    {
        $editor = $this->createTestUser('editor', 'ed_rej');
        $author = $this->createTestUser('author', 'aut_rej');
        $post = $this->createTestPost($author->id, 'pending');

        $_SESSION['auth_user_id'] = $editor->id;
        $req = Request::create('POST', '/admin/posts/reject?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(302, $resp->getStatusCode());
        $fresh = Post::find($post->id);
        $this->assertEquals('rejected', $fresh->status);
    }

    /**
     * 3. Editor can moderate comments (approve, spam, trash)
     */
    public function testEditorCanModerateComments(): void
    {
        $editor = $this->createTestUser('editor', 'ed_comm');
        $author = $this->createTestUser('author', 'aut_comm');
        $post = $this->createTestPost($author->id, 'published');
        $comment = $this->createTestComment($post->id, $author->id, 'pending');

        $this->assertTrue($editor->canModerateComments());

        $_SESSION['auth_user_id'] = $editor->id;

        // Approve comment
        $req = Request::create('POST', '/admin/comments/approve?id=' . $comment->id, ['id' => $comment->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $fresh = Comment::find($comment->id);
        $this->assertEquals('approved', $fresh->status);

        // Spam comment
        $req = Request::create('POST', '/admin/comments/spam?id=' . $comment->id, ['id' => $comment->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $fresh = Comment::find($comment->id);
        $this->assertEquals('spam', $fresh->status);

        // Trash comment
        $req = Request::create('POST', '/admin/comments/trash?id=' . $comment->id, ['id' => $comment->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $fresh = Comment::find($comment->id);
        $this->assertEquals('trash', $fresh->status);
    }

    /**
     * 4. Moderator can view edit form for another user's post
     */
    public function testModeratorCanEditOtherUsersPost(): void
    {
        $moderator = $this->createTestUser('moderator', 'mod_view');
        $author = $this->createTestUser('author', 'aut_view');
        $post = $this->createTestPost($author->id, 'published');

        $this->assertTrue($moderator->canEditOtherPosts());
        $this->assertTrue($moderator->canEditPost($post));

        $_SESSION['auth_user_id'] = $moderator->id;
        $req = Request::create('GET', '/admin/posts/edit?id=' . $post->id, ['id' => $post->id]);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(200, $resp->getStatusCode());
    }

    /**
     * 5. Moderator can save/update another user's post
     */
    public function testModeratorCanSaveOtherUsersPost(): void
    {
        $moderator = $this->createTestUser('moderator', 'mod_save');
        $author = $this->createTestUser('author', 'aut_save');
        $post = $this->createTestPost($author->id, 'published');

        $_SESSION['auth_user_id'] = $moderator->id;
        $updatedTitle = 'Updated by Moderator ' . bin2hex(random_bytes(3));
        $req = Request::create('POST', '/admin/posts/update?id=' . $post->id, [
            'id'      => $post->id,
            '_token'  => 'test-token-12345',
            'title'   => $updatedTitle,
            'content' => '<p>Updated content by moderator</p>',
            'status'  => 'published',
        ]);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(302, $resp->getStatusCode());
        $fresh = Post::find($post->id);
        $this->assertEquals($updatedTitle, $fresh->title);
    }

    /**
     * 6. Author CANNOT edit another user's post
     */
    public function testAuthorCannotEditOtherUsersPost(): void
    {
        $authorA = $this->createTestUser('author', 'aut_a');
        $authorB = $this->createTestUser('author', 'aut_b');
        $postB = $this->createTestPost($authorB->id, 'published');

        $this->assertFalse($authorA->canEditOtherPosts());
        $this->assertFalse($authorA->canEditPost($postB));

        // GET edit form
        $_SESSION['auth_user_id'] = $authorA->id;
        $req = Request::create('GET', '/admin/posts/edit?id=' . $postB->id, ['id' => $postB->id]);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('not have permission', $_SESSION['flash_error'] ?? '');

        // POST update
        $req = Request::create('POST', '/admin/posts/update?id=' . $postB->id, [
            'id'     => $postB->id,
            '_token' => 'test-token-12345',
            'title'  => 'Malicious Update by Author A',
        ]);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(302, $resp->getStatusCode());
        $fresh = Post::find($postB->id);
        $this->assertNotEquals('Malicious Update by Author A', $fresh->title);
    }

    /**
     * 7. Subscriber CANNOT edit any post
     */
    public function testSubscriberCannotEditAnyPost(): void
    {
        $subscriber = $this->createTestUser('subscriber', 'sub_no_edit');
        $author = $this->createTestUser('author', 'aut_for_sub');
        $post = $this->createTestPost($author->id, 'published');

        $this->assertFalse($subscriber->canUpdatePosts());
        $this->assertFalse($subscriber->canEditPost($post));

        $_SESSION['auth_user_id'] = $subscriber->id;
        $req = Request::create('GET', '/admin/posts/edit?id=' . $post->id, ['id' => $post->id]);
        $resp = static::$kernel->handle($req);

        // Subscriber is blocked either via kernel post guard or controller
        $this->assertContains($resp->getStatusCode(), [302, 403]);
    }

    /**
     * 8. Unauthorized roles (Author & Subscriber) cannot approve or reject posts
     */
    public function testUnauthorizedRolesCannotApproveOrRejectPosts(): void
    {
        $author = $this->createTestUser('author', 'aut_unauth');
        $subscriber = $this->createTestUser('subscriber', 'sub_unauth');
        $post = $this->createTestPost($author->id, 'pending');

        $this->assertFalse($author->canModeratePosts());
        $this->assertFalse($subscriber->canModeratePosts());

        // Author tries to approve
        $_SESSION['auth_user_id'] = $author->id;
        $req = Request::create('POST', '/admin/posts/approve?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $this->assertEquals('pending', Post::find($post->id)->status);

        // Subscriber tries to reject
        $_SESSION['auth_user_id'] = $subscriber->id;
        $req = Request::create('POST', '/admin/posts/reject?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $this->assertEquals('pending', Post::find($post->id)->status);
    }

    /**
     * 9. Unauthorized roles (Author & Subscriber) cannot moderate comments
     */
    public function testUnauthorizedRolesCannotModerateComments(): void
    {
        $author = $this->createTestUser('author', 'aut_cmod');
        $subscriber = $this->createTestUser('subscriber', 'sub_cmod');
        $post = $this->createTestPost($author->id, 'published');
        $comment = $this->createTestComment($post->id, $author->id, 'pending');

        $this->assertFalse($author->canModerateComments());
        $this->assertFalse($subscriber->canModerateComments());

        // Author tries to approve comment
        $_SESSION['auth_user_id'] = $author->id;
        $req = Request::create('POST', '/admin/comments/approve?id=' . $comment->id, ['id' => $comment->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $this->assertEquals('pending', Comment::find($comment->id)->status);

        // Subscriber tries to trash comment
        $_SESSION['auth_user_id'] = $subscriber->id;
        $req = Request::create('POST', '/admin/comments/trash?id=' . $comment->id, ['id' => $comment->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $this->assertEquals('pending', Comment::find($comment->id)->status);
    }

    /**
     * 10. Super Admin remains fully privileged
     */
    public function testSuperAdminRemainsFullyPrivileged(): void
    {
        $superAdmin = User::find(1);
        $this->assertNotNull($superAdmin);
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($superAdmin->canCreatePosts());
        $this->assertTrue($superAdmin->canUpdatePosts());
        $this->assertTrue($superAdmin->canModeratePosts());
        $this->assertTrue($superAdmin->canModerateComments());
        $this->assertTrue($superAdmin->canEditOtherPosts());
        $this->assertTrue($superAdmin->canManagePages());
        $this->assertTrue($superAdmin->canManageTaxonomies());
        $this->assertTrue($superAdmin->canManageMenus());
        $this->assertTrue($superAdmin->canManagePlugins());
        $this->assertTrue($superAdmin->canManageUsers());
        $this->assertTrue($superAdmin->canUploadMedia());
    }

    /**
     * 11. Admin retains intended permissions without Super Admin escalation
     */
    public function testAdminRetainsIntendedPermissionsWithoutSuperAdminEscalation(): void
    {
        $admin = $this->createTestUser('admin', 'adm_test');
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertFalse($admin->hasRole('super-admin'));

        // Admin has full content & user admin
        $this->assertTrue($admin->canCreatePosts());
        $this->assertTrue($admin->canManagePages());
        $this->assertTrue($admin->canManageTaxonomies());
        $this->assertTrue($admin->canManageMenus());
        $this->assertTrue($admin->canManagePlugins());
        $this->assertTrue($admin->canManageUsers());
        $this->assertTrue($admin->canModerateComments());
        $this->assertTrue($admin->canModeratePosts());

        // Invariant: Admin cannot delete or downgrade the sole Super Admin
        $superAdmin = User::find(1);
        $this->assertFalse($superAdmin->canSelfDelete());
    }

    /**
     * 12. Author remains restricted to own-content workflow
     */
    public function testAuthorRemainsRestrictedToOwnContentWorkflow(): void
    {
        $author = $this->createTestUser('author', 'aut_wf');
        $this->assertTrue($author->canCreatePosts());
        $this->assertTrue($author->canUpdatePosts());
        $this->assertTrue($author->canUploadMedia());

        $ownPost = $this->createTestPost($author->id, 'published');
        $this->assertTrue($author->canEditPost($ownPost));

        // Restricted from others' content and system resources
        $this->assertFalse($author->canEditOtherPosts());
        $this->assertFalse($author->canModeratePosts());
        $this->assertFalse($author->canModerateComments());
        $this->assertFalse($author->canManagePages());
        $this->assertFalse($author->canManageTaxonomies());
        $this->assertFalse($author->canManageMenus());
        $this->assertFalse($author->canManagePlugins());
        $this->assertFalse($author->canManageUsers());
    }

    /**
     * 13. Subscriber remains restricted
     */
    public function testSubscriberRemainsRestricted(): void
    {
        $sub = $this->createTestUser('subscriber', 'sub_wf');
        $this->assertFalse($sub->canCreatePosts());
        $this->assertFalse($sub->canUpdatePosts());
        $this->assertFalse($sub->canModeratePosts());
        $this->assertFalse($sub->canModerateComments());
        $this->assertFalse($sub->canEditOtherPosts());
        $this->assertFalse($sub->canManagePages());
        $this->assertFalse($sub->canManageTaxonomies());
        $this->assertFalse($sub->canManageMenus());
        $this->assertFalse($sub->canManagePlugins());
        $this->assertFalse($sub->canManageUsers());
        $this->assertFalse($sub->canUploadMedia());
    }

    /**
     * 14. Existing sole Super Admin protections work
     */
    public function testExistingSoleSuperAdminProtectionsWork(): void
    {
        $superAdmin = User::find(1);
        $this->assertNotNull($superAdmin);
        $count = User::getActiveSuperAdminCount(static::$db);
        $this->assertGreaterThanOrEqual(1, $count);

        if ($count === 1) {
            $this->assertFalse($superAdmin->canSelfDelete());
            $this->expectException(\RuntimeException::class);
            $superAdmin->deleteAccount(1);
        }
    }

    /**
     * 15. Existing Super Admin emergency recovery works
     */
    public function testExistingSuperAdminEmergencyRecoveryWorks(): void
    {
        $user1 = User::find(1);
        $this->assertNotNull($user1);

        // When Super Admin count is >= 1, recovery is not eligible
        $this->assertFalse($user1->isEligibleForSuperAdminRecovery());
    }

    /**
     * 16. Moderator CANNOT trash, restore, or delete other users' posts
     */
    public function testModeratorCannotTrashRestoreOrDeleteOtherUsersPost(): void
    {
        $moderator = $this->createTestUser('moderator', 'mod_notrash');
        $author = $this->createTestUser('author', 'aut_notrash');
        $authorPost = $this->createTestPost($author->id, 'published');
        $modPost = $this->createTestPost($moderator->id, 'published');

        // Capability checks
        $this->assertFalse($moderator->canDeleteOtherPosts());
        $this->assertFalse($moderator->canDeletePost($authorPost));
        $this->assertTrue($moderator->canDeletePost($modPost));

        $_SESSION['auth_user_id'] = $moderator->id;

        // 1. Attempt to trash other user's post -> blocked
        $req = Request::create('POST', '/admin/posts/trash?id=' . $authorPost->id, [
            'id'     => $authorPost->id,
            '_token' => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertEquals('published', Post::find($authorPost->id)->status);

        // 2. Put other user's post in trash, attempt restore -> blocked
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$authorPost->id]);
        $req = Request::create('POST', '/admin/posts/restore?id=' . $authorPost->id, [
            'id'     => $authorPost->id,
            '_token' => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertEquals('trash', Post::find($authorPost->id)->status);

        // 3. Attempt permanent delete -> blocked
        $req = Request::create('POST', '/admin/posts/delete?id=' . $authorPost->id, [
            'id'     => $authorPost->id,
            '_token' => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertNotNull(Post::find($authorPost->id));

        // 4. Moderator trashing OWN post -> allowed
        $req = Request::create('POST', '/admin/posts/trash?id=' . $modPost->id, [
            'id'     => $modPost->id,
            '_token' => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($modPost->id)->status);
    }

    /**
     * 17. Moderator bulk actions skip other users' posts for destructive operations
     */
    public function testModeratorBulkActionSkipsOtherUsersPostForDestructiveActions(): void
    {
        $moderator = $this->createTestUser('moderator', 'mod_bulk');
        $author = $this->createTestUser('author', 'aut_bulk');
        $authorPost = $this->createTestPost($author->id, 'published');
        $modPost = $this->createTestPost($moderator->id, 'published');

        $_SESSION['auth_user_id'] = $moderator->id;

        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'trash',
            'ids'         => [$authorPost->id, $modPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());

        // Author's post was NOT trashed
        $this->assertEquals('published', Post::find($authorPost->id)->status);
        // Moderator's own post WAS trashed
        $this->assertEquals('trash', Post::find($modPost->id)->status);
    }

    /**
     * 18. Posts list view hides destructive actions for Moderator on other users' posts
     */
    public function testPostsListViewHidesDestructiveActionsForModeratorOnOtherPosts(): void
    {
        $moderator = $this->createTestUser('moderator', 'mod_list');
        $author = $this->createTestUser('author', 'aut_list');
        $authorPost = $this->createTestPost($author->id, 'published');
        $modPost = $this->createTestPost($moderator->id, 'published');

        $_SESSION['auth_user_id'] = $moderator->id;

        $req = Request::create('GET', '/admin/posts');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $body = (string)$resp->getContent();

        // Moderator sees Trash option for their own post
        $this->assertStringContainsString('action="/admin/posts/trash?id=' . $modPost->id . '"', $body);

        // Moderator does NOT see Trash option for author's post
        $this->assertStringNotContainsString('action="/admin/posts/trash?id=' . $authorPost->id . '"', $body);
    }

    /**
     * 19. Author dashboard access returns 200 without 500 error and displays scoped view
     */
    public function testAuthorDashboardAccessAndRestrictedView(): void
    {
        $author = $this->createTestUser('author', 'aut_dash');
        $authorPost = $this->createTestPost($author->id, 'published');

        $_SESSION['auth_user_id'] = $author->id;

        $req = Request::create('GET', '/admin');
        $resp = static::$kernel->handle($req);

        // Crucial invariant: Author visiting /admin must return 200 OK (NOT 500 error)
        $this->assertEquals(200, $resp->getStatusCode());
        $body = (string)$resp->getContent();

        $this->assertStringContainsString('Dashboard', $body);
        $this->assertStringContainsString('My Posts', $body);
        $this->assertStringContainsString('My Account', $body);

        // Author dashboard MUST NOT contain unauthorized management cards or links
        $this->assertStringNotContainsString('href="/admin/pages"', $body);
        $this->assertStringNotContainsString('href="/admin/comments"', $body);
        $this->assertStringNotContainsString('href="/admin/users"', $body);
        $this->assertStringNotContainsString('+ Add an About Page', $body);
        $this->assertStringNotContainsString('Customize Theme', $body);

        // Quick Draft form is available for author
        $this->assertStringContainsString('action="/admin/posts/quick-draft"', $body);
    }

    /**
     * 20. Author cannot access unpermitted administrative areas
     */
    public function testAuthorCannotAccessAdminPagesCommentsTaxonomiesMenus(): void
    {
        $author = $this->createTestUser('author', 'aut_nopages');
        $_SESSION['auth_user_id'] = $author->id;

        // Pages
        $req = Request::create('GET', '/admin/pages');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // Comments
        $req = Request::create('GET', '/admin/comments');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // Taxonomies
        $req = Request::create('GET', '/admin/taxonomies/categories');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // Menus
        $req = Request::create('GET', '/admin/menus');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // Users
        $req = Request::create('GET', '/admin/users');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());
    }
}
