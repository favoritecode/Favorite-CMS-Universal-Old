<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Permissions;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;

final class MultimediaPermission
{
    public const VIEW             = 'multimedia.view';
    public const CREATE           = 'multimedia.create';
    public const EDIT             = 'multimedia.edit';
    public const EDIT_OWN         = 'multimedia.edit_own';
    public const EDIT_OTHERS      = 'multimedia.edit_others';
    public const DELETE           = 'multimedia.delete';
    public const DELETE_OWN       = 'multimedia.delete_own';
    public const DELETE_OTHERS    = 'multimedia.delete_others';
    public const PUBLISH          = 'multimedia.publish';
    public const MANAGE_SOURCES   = 'multimedia.manage_sources';
    public const MANAGE_SUBTITLES = 'multimedia.manage_subtitles';
    public const MANAGE_PLAYLISTS = 'multimedia.manage_playlists';
    public const MANAGE_SETTINGS  = 'multimedia.manage_settings';
    public const MANAGE_ACCESS    = 'multimedia.manage_access';
    public const VIEW_ANALYTICS   = 'multimedia.view_analytics';
    public const MODERATE         = 'multimedia.moderate';

    /**
     * Safely verify if a user object or representation has a given role.
     */
    public static function hasUserRole(mixed $user, string $role): bool
    {
        if (!is_object($user)) {
            return false;
        }

        if (method_exists($user, 'hasRole')) {
            try {
                return (bool)$user->hasRole($role);
            } catch (\Throwable) {
                return false;
            }
        }

        if (isset($user->role) && is_string($user->role)) {
            return strtolower($user->role) === strtolower($role);
        }

        if (isset($user->roles) && is_array($user->roles)) {
            return in_array($role, $user->roles, true);
        }

        return false;
    }

    /**
     * Resolve the active user safely across core session keys and runtime context.
     */
    public static function resolveUser(mixed $user = null): ?object
    {
        if (is_object($user)) {
            return $user;
        }

        if (function_exists('current_user')) {
            try {
                $resolved = current_user();
                if (is_object($resolved)) {
                    return $resolved;
                }
            } catch (\Throwable) {}
        }

        $sessionUserId = (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($sessionUserId > 0) {
            try {
                $user = User::find($sessionUserId);
                if (is_object($user)) {
                    return $user;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Check whether a user is suspended, banned, disabled, or inactive.
     */
    public static function isSuspendedUser(mixed $user): bool
    {
        if ($user === null || !is_object($user)) {
            return false;
        }

        if (method_exists($user, 'isBanned')) {
            try {
                if ($user->isBanned()) {
                    return true;
                }
            } catch (\Throwable) {}
        }

        if (method_exists($user, 'isSuspended')) {
            try {
                if ($user->isSuspended()) {
                    return true;
                }
            } catch (\Throwable) {}
        }

        $status = '';
        if (isset($user->status)) {
            $status = strtolower(trim((string)$user->status));
        }
        if (in_array($status, ['suspended', 'banned', 'disabled', 'inactive'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Check whether a user has a specific multimedia capability.
     */
    public static function can(string $permission, mixed $user = null): bool
    {
        $user = self::resolveUser($user);

        if (!$user || self::isSuspendedUser($user)) {
            return false;
        }

        // Fail-closed for Contributor (Contributor is NOT in the locked matrix, cannot be mapped to author or granted creator capabilities)
        if (self::hasUserRole($user, 'contributor') && !self::hasUserRole($user, 'super-admin') && !self::hasUserRole($user, 'admin') && !self::hasUserRole($user, 'editor') && !self::hasUserRole($user, 'moderator') && !self::hasUserRole($user, 'author')) {
            return false;
        }

        // 1. Super Admin and Admin have full authoritative access
        if (self::hasUserRole($user, 'super-admin') || self::hasUserRole($user, 'admin')) {
            return true;
        }

        // 2. Editor role
        if (self::hasUserRole($user, 'editor')) {
            // Delete others or generic delete is strictly forbidden for all except Super Admin and Admin
            if ($permission === self::DELETE || $permission === self::DELETE_OTHERS || $permission === self::MANAGE_SETTINGS) {
                return false;
            }
            if ($permission === self::MANAGE_ACCESS) {
                return true;
            }
            return in_array($permission, [
                self::VIEW,
                self::CREATE,
                self::EDIT,
                self::EDIT_OWN,
                self::EDIT_OTHERS,
                self::DELETE_OWN,
                self::PUBLISH,
                self::MODERATE,
                self::MANAGE_SOURCES,
                self::MANAGE_SUBTITLES,
                self::MANAGE_PLAYLISTS,
                self::VIEW_ANALYTICS,
            ], true);
        }

        // 3. Moderator role
        if (self::hasUserRole($user, 'moderator')) {
            // Delete others or generic delete is strictly forbidden for all except Super Admin and Admin
            if ($permission === self::DELETE || $permission === self::DELETE_OTHERS || $permission === self::MANAGE_SETTINGS || $permission === self::MANAGE_ACCESS) {
                return false;
            }
            return in_array($permission, [
                self::VIEW,
                self::CREATE,
                self::EDIT,
                self::EDIT_OWN,
                self::EDIT_OTHERS,
                self::DELETE_OWN,
                self::PUBLISH,
                self::MODERATE,
                self::MANAGE_SOURCES,
                self::MANAGE_SUBTITLES,
                self::MANAGE_PLAYLISTS,
                self::VIEW_ANALYTICS,
            ], true);
        }

        // 4. Author role (Author-scoped creator capabilities)
        if (self::hasUserRole($user, 'author')) {
            if (Setting::get('multimedia', 'allow_author_submissions', 'yes') !== 'yes') {
                return false;
            }

            // Author capabilities per Locked Access Matrix
            if (in_array($permission, [
                self::CREATE,
                self::EDIT_OWN,
                self::DELETE_OWN,
                self::MANAGE_PLAYLISTS,
                self::VIEW_ANALYTICS,
            ], true)) {
                return true;
            }

            // Authors CANNOT view full catalog admin, edit others, delete others, publish directly, or moderate
            return false;
        }

        // 5. Subscriber role: strictly fail-closed for creator/admin permissions
        if (self::hasUserRole($user, 'subscriber')) {
            return false;
        }

        // 6. Fallback to database role_permissions
        try {
            if (method_exists($user, 'hasPermission')) {
                return (bool)$user->hasPermission($permission);
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if user is authorized to submit media (generally or for a specific category).
     * Supports both canUserSubmit('movie', $user) and canUserSubmit($user) or canUserSubmit().
     */
    public static function canUserSubmit(mixed $contentTypeOrUser = 'movie', mixed $user = null): bool
    {
        if (is_object($contentTypeOrUser) && !($contentTypeOrUser instanceof \Stringable)) {
            $user = $contentTypeOrUser;
            $contentType = null;
        } else {
            $contentType = is_string($contentTypeOrUser) ? trim($contentTypeOrUser) : (is_object($contentTypeOrUser) ? (string)$contentTypeOrUser : null);
        }

        $user = self::resolveUser($user);

        if (!$user || self::isSuspendedUser($user)) {
            return false;
        }

        // Fail-closed for Contributor and Subscriber (Subscriber has NO public multimedia creation)
        if (self::hasUserRole($user, 'subscriber') || self::hasUserRole($user, 'contributor')) {
            if (!self::hasUserRole($user, 'super-admin') && !self::hasUserRole($user, 'admin') && !self::hasUserRole($user, 'editor') && !self::hasUserRole($user, 'moderator') && !self::hasUserRole($user, 'author')) {
                return false;
            }
        }

        if (self::hasUserRole($user, 'super-admin') || self::hasUserRole($user, 'admin')) {
            return true;
        }

        // Global master switch for user submissions
        if (Setting::get('multimedia', 'user_submissions_enabled', 'yes') !== 'yes') {
            return false;
        }

        if (!self::can(self::CREATE, $user)) {
            return false;
        }

        // If specific content type requested
        if ($contentType !== null && $contentType !== '') {
            $typeSettingKey = match ($contentType) {
                'movie'    => 'allow_user_movie_upload',
                'series'   => 'allow_user_series_upload',
                'episode'  => 'allow_user_episode_upload',
                'song'     => 'allow_user_song_upload',
                'album'    => 'allow_user_album_upload',
                'playlist' => 'allow_user_playlist_creation',
                default    => null,
            };

            if ($typeSettingKey !== null && Setting::get('multimedia', $typeSettingKey, 'yes') !== 'yes') {
                return false;
            }

            return true;
        }

        // If no specific content type requested (general check), check if at least one type is enabled
        $types = [
            'allow_user_movie_upload',
            'allow_user_series_upload',
            'allow_user_episode_upload',
            'allow_user_song_upload',
            'allow_user_album_upload',
            'allow_user_playlist_creation',
        ];

        foreach ($types as $key) {
            if (Setting::get('multimedia', $key, 'yes') === 'yes') {
                return true;
            }
        }

        return false;
    }

    /**
     * Authoritative ownership verification for editing content.
     */
    public static function canEditContent(object|array $content, mixed $user = null): bool
    {
        $user = self::resolveUser($user);

        if (!$user || self::isSuspendedUser($user)) {
            return false;
        }

        // Super Admin & Admin can edit anything
        if (self::hasUserRole($user, 'super-admin') || self::hasUserRole($user, 'admin')) {
            return true;
        }

        // Fail-closed for Contributor and Subscriber
        if (self::hasUserRole($user, 'subscriber') || self::hasUserRole($user, 'contributor')) {
            if (!self::hasUserRole($user, 'super-admin') && !self::hasUserRole($user, 'admin') && !self::hasUserRole($user, 'editor') && !self::hasUserRole($user, 'moderator') && !self::hasUserRole($user, 'author')) {
                return false;
            }
        }

        $ownerId = is_object($content) ? (int)($content->user_id ?? 0) : (int)($content['user_id'] ?? 0);
        $isOwn = ($ownerId > 0 && (int)($user->id ?? 0) === $ownerId);

        // Editor and Moderator can edit own and others content
        if (self::hasUserRole($user, 'editor') || self::hasUserRole($user, 'moderator')) {
            return true;
        }

        // Author can ONLY edit own content
        if (self::hasUserRole($user, 'author')) {
            return $isOwn && self::can(self::EDIT_OWN, $user);
        }

        return false;
    }

    /**
     * Authoritative ownership verification for deleting content.
     */
    public static function canDeleteContent(object|array $content, mixed $user = null): bool
    {
        $user = self::resolveUser($user);

        if (!$user || self::isSuspendedUser($user)) {
            return false;
        }

        // 1. Super Admin and Admin have full authoritative delete access for any content
        if (self::hasUserRole($user, 'super-admin') || self::hasUserRole($user, 'admin')) {
            return true;
        }

        $ownerId = is_object($content) ? (int)($content->user_id ?? 0) : (int)($content['user_id'] ?? 0);
        $isOwn = ($ownerId > 0 && (int)($user->id ?? 0) === $ownerId);

        // Deleting OTHER users' content is STRICTLY Super Admin and Admin only!
        // (Editor = NO, Moderator = NO, Author = NO, Subscriber = NO, Suspended = NO)
        if (!$isOwn) {
            return false;
        }

        // Fail-closed for Contributor and Subscriber (Subscriber has NO delete permissions)
        if (self::hasUserRole($user, 'subscriber') || self::hasUserRole($user, 'contributor')) {
            if (!self::hasUserRole($user, 'super-admin') && !self::hasUserRole($user, 'admin') && !self::hasUserRole($user, 'editor') && !self::hasUserRole($user, 'moderator') && !self::hasUserRole($user, 'author')) {
                return false;
            }
        }

        $status = is_object($content) ? (string)($content->status ?? '') : (string)($content['status'] ?? '');

        // 2. Editor and Moderator: can delete own content regardless of status (draft, pending, published)
        if (self::hasUserRole($user, 'editor') || self::hasUserRole($user, 'moderator')) {
            return true;
        }

        // 3. Author: can delete own draft or pending, but CANNOT delete published own content
        if (self::hasUserRole($user, 'author')) {
            if ($status === 'published') {
                return false;
            }
            return in_array($status, ['draft', 'pending', 'pending_review', 'rejected'], true);
        }

        return false;
    }

    /**
     * Register default multimedia permissions in the CMS permissions table and link roles.
     */
    public static function registerDefaultPermissions(?Database $db): void
    {
        if ($db === null || !$db->tableExists('permissions')) {
            return;
        }

        $definitions = [
            ['name' => 'View Multimedia',              'slug' => self::VIEW,             'description' => 'View multimedia catalog, lists and details in admin',  'group_name' => 'multimedia'],
            ['name' => 'Create Multimedia Content',     'slug' => self::CREATE,           'description' => 'Create new movies, series, episodes and songs',        'group_name' => 'multimedia'],
            ['name' => 'Edit Multimedia Content',       'slug' => self::EDIT,             'description' => 'Edit multimedia items and metadata',                   'group_name' => 'multimedia'],
            ['name' => 'Edit Own Multimedia Content',   'slug' => self::EDIT_OWN,         'description' => 'Edit multimedia items created by self',                 'group_name' => 'multimedia'],
            ['name' => 'Edit Others Multimedia Content','slug' => self::EDIT_OTHERS,      'description' => 'Edit multimedia items created by other users',         'group_name' => 'multimedia'],
            ['name' => 'Delete Multimedia Content',     'slug' => self::DELETE,           'description' => 'Delete multimedia items and entries',                   'group_name' => 'multimedia'],
            ['name' => 'Delete Own Multimedia Content', 'slug' => self::DELETE_OWN,       'description' => 'Delete own unapproved draft/pending multimedia items', 'group_name' => 'multimedia'],
            ['name' => 'Delete Others Content',         'slug' => self::DELETE_OTHERS,    'description' => 'Delete multimedia items created by other users',         'group_name' => 'multimedia'],
            ['name' => 'Publish Multimedia Content',    'slug' => self::PUBLISH,          'description' => 'Publish or unpublish multimedia content directly',      'group_name' => 'multimedia'],
            ['name' => 'Manage Media Sources',         'slug' => self::MANAGE_SOURCES,   'description' => 'Manage video, audio, HLS and embed sources',            'group_name' => 'multimedia'],
            ['name' => 'Manage Subtitles',             'slug' => self::MANAGE_SUBTITLES, 'description' => 'Manage multi-language subtitles and tracks',            'group_name' => 'multimedia'],
            ['name' => 'Manage Playlists',             'slug' => self::MANAGE_PLAYLISTS, 'description' => 'Create and curate audio playlists',                     'group_name' => 'multimedia'],
            ['name' => 'Manage Multimedia Settings',   'slug' => self::MANAGE_SETTINGS,  'description' => 'Configure global multimedia settings and downloads',    'group_name' => 'multimedia'],
            ['name' => 'Manage Multimedia Access',     'slug' => self::MANAGE_ACCESS,    'description' => 'Configure access modes, entitlements and rules',        'group_name' => 'multimedia'],
            ['name' => 'View Multimedia Analytics',    'slug' => self::VIEW_ANALYTICS,   'description' => 'View play, view and download analytics data',           'group_name' => 'multimedia'],
            ['name' => 'Moderate Multimedia Community', 'slug' => self::MODERATE,         'description' => 'Moderate community submissions, reviews and reports',   'group_name' => 'multimedia'],
        ];

        foreach ($definitions as $def) {
            $exists = $db->selectOne("SELECT id FROM permissions WHERE slug = ? LIMIT 1", [$def['slug']]);
            if (!$exists) {
                $db->insert('permissions', $def);
            }
        }

        if (!$db->tableExists('roles') || !$db->tableExists('role_permissions')) {
            return;
        }

        $allPermSlugs = array_column($definitions, 'slug');

        $roleMappings = [
            'admin' => $allPermSlugs,
            'editor' => [
                self::VIEW, self::CREATE, self::EDIT, self::EDIT_OWN, self::EDIT_OTHERS,
                self::DELETE_OWN, self::PUBLISH,
                self::MANAGE_SOURCES, self::MANAGE_SUBTITLES, self::MANAGE_PLAYLISTS,
                self::MANAGE_ACCESS, self::VIEW_ANALYTICS, self::MODERATE,
            ],
            'moderator' => [
                self::VIEW, self::CREATE, self::EDIT, self::EDIT_OWN, self::EDIT_OTHERS,
                self::DELETE_OWN, self::PUBLISH,
                self::MANAGE_SOURCES, self::MANAGE_SUBTITLES, self::MANAGE_PLAYLISTS,
                self::VIEW_ANALYTICS, self::MODERATE,
            ],
            'author' => [
                self::CREATE, self::EDIT_OWN, self::DELETE_OWN, self::MANAGE_PLAYLISTS, self::VIEW_ANALYTICS,
            ],
            'subscriber' => [],
        ];

        foreach ($roleMappings as $roleSlug => $permSlugs) {
            $role = $db->selectOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
            if (!$role) {
                continue;
            }

            foreach ($permSlugs as $pSlug) {
                $perm = $db->selectOne("SELECT id FROM permissions WHERE slug = ? LIMIT 1", [$pSlug]);
                if (!$perm) {
                    continue;
                }
                $rpExists = $db->selectOne(
                    "SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1",
                    [$role->id, $perm->id]
                );
                if (!$rpExists) {
                    $db->insert('role_permissions', [
                        'role_id'       => $role->id,
                        'permission_id' => $perm->id,
                    ]);
                }
            }
        }

        // Clean up any legacy assignments where multimedia.view was assigned to non-admin creator roles
        try {
            $viewPerm = $db->selectOne("SELECT id FROM permissions WHERE slug = ? LIMIT 1", [self::VIEW]);
            if ($viewPerm) {
                $creatorRoles = $db->select("SELECT id FROM roles WHERE slug IN ('author', 'contributor', 'subscriber')");
                foreach ($creatorRoles as $cRole) {
                    $db->delete('role_permissions', [
                        'role_id'       => $cRole->id,
                        'permission_id' => $viewPerm->id,
                    ]);
                }
            }
        } catch (\Throwable) {}
    }

    /**
     * Resolve the canonical active super-admin or admin user ID.
     * Strictly avoids assigning ownership to arbitrary lowest active users.
     */
    public static function resolveCanonicalAdminId(?Database $db = null): ?int
    {
        try {
            if ($db === null) {
                if (\FavoriteCMS\Core\Container::getInstance()->has(Database::class)) {
                    $db = \FavoriteCMS\Core\Container::getInstance()->get(Database::class);
                } else {
                    return null;
                }
            }

            if ($db->tableExists('users') && $db->tableExists('roles') && $db->tableExists('user_roles')) {
                // 1. Priority 1: canonical active super-admin
                $row = $db->selectOne("
                    SELECT u.id 
                    FROM users u
                    JOIN user_roles ur ON u.id = ur.user_id
                    JOIN roles r ON ur.role_id = r.id
                    WHERE r.slug = 'super-admin'
                      AND (u.status IS NULL OR u.status = 'active')
                    ORDER BY u.id ASC
                    LIMIT 1
                ");
                if ($row && !empty($row->id)) {
                    return (int)$row->id;
                }

                // 2. Priority 2: canonical active admin
                $row = $db->selectOne("
                    SELECT u.id 
                    FROM users u
                    JOIN user_roles ur ON u.id = ur.user_id
                    JOIN roles r ON ur.role_id = r.id
                    WHERE r.slug = 'admin'
                      AND (u.status IS NULL OR u.status = 'active')
                    ORDER BY u.id ASC
                    LIMIT 1
                ");
                if ($row && !empty($row->id)) {
                    return (int)$row->id;
                }
            }

            // 3. Legacy column fallback
            if ($db->tableExists('users')) {
                $cols = array_column($db->select("PRAGMA table_info(users)"), 'name');
                if (in_array('role', $cols, true)) {
                    $row = $db->selectOne("SELECT id FROM users WHERE role = 'super-admin' AND (status IS NULL OR status = 'active') ORDER BY id ASC LIMIT 1");
                    if ($row && !empty($row->id)) {
                        return (int)$row->id;
                    }
                    $row = $db->selectOne("SELECT id FROM users WHERE role = 'admin' AND (status IS NULL OR status = 'active') ORDER BY id ASC LIMIT 1");
                    if ($row && !empty($row->id)) {
                        return (int)$row->id;
                    }
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
