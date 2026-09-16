<?php

declare(strict_types=1);

/**
 * Favorite Multimedia Theme - Functions & Bootstrap
 */

if (!function_exists('favorite_multimedia_theme_is_plugin_active')) {
    function favorite_multimedia_theme_is_plugin_active(): bool
    {
        return class_exists(\FavoriteCMS\Multimedia\FavoriteMultimediaPlugin::class);
    }
}

if (!function_exists('favorite_multimedia_theme_plugin_version')) {
    function favorite_multimedia_theme_plugin_version(): ?string
    {
        if (defined('\FavoriteCMS\Multimedia\FavoriteMultimediaPlugin::VERSION')) {
            return \FavoriteCMS\Multimedia\FavoriteMultimediaPlugin::VERSION;
        }
        return null;
    }
}

if (!function_exists('favorite_multimedia_theme_is_compatible')) {
    function favorite_multimedia_theme_is_compatible(): bool
    {
        $ver = favorite_multimedia_theme_plugin_version();
        return $ver !== null && version_compare($ver, '1.0.6', '>=');
    }
}

if (!function_exists('favorite_multimedia_theme_admin_notice')) {
    function favorite_multimedia_theme_admin_notice(): string
    {
        if (favorite_multimedia_theme_is_compatible()) {
            return '';
        }
        return '<div class="fm-theme-notice" style="background:#fef2f2;border:1px solid #ef4444;color:#991b1b;padding:12px;border-radius:6px;margin:16px 0;">'
            . '<strong>Favorite Multimedia Theme Notice:</strong> This theme requires the Favorite Multimedia plugin (v1.0.6 or newer). '
            . 'Please ensure the plugin is installed and activated in the admin panel.'
            . '</div>';
    }
}

if (!function_exists('favorite_multimedia_theme_asset')) {
    function favorite_multimedia_theme_asset(string $path): string
    {
        return '/themes/favorite-multimedia-theme/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('favorite_multimedia_theme_is_logged_in')) {
    function favorite_multimedia_theme_is_logged_in(): bool
    {
        return !empty($_SESSION['auth_user_id']);
    }
}

if (!function_exists('favorite_multimedia_theme_current_user')) {
    function favorite_multimedia_theme_current_user(): ?\FavoriteCMS\Models\User
    {
        try {
            if (function_exists('current_user')) {
                $user = current_user();
                if ($user !== null) {
                    return $user;
                }
            }
        } catch (\Throwable) {}

        $id = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($id > 0 && class_exists(\FavoriteCMS\Models\User::class)) {
            try {
                return \FavoriteCMS\Models\User::find($id);
            } catch (\Throwable) {}
        }
        return null;
    }
}

if (!function_exists('favorite_multimedia_theme_user_display_name')) {
    function favorite_multimedia_theme_user_display_name(?\FavoriteCMS\Models\User $user = null): string
    {
        if ($user !== null) {
            $name = trim((string)($user->name ?? $user->username ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        $sessionName = trim((string)($_SESSION['auth_user_name'] ?? ''));
        if ($sessionName !== '') {
            return $sessionName;
        }
        return 'Account';
    }
}

if (!function_exists('favorite_multimedia_theme_user_initials')) {
    function favorite_multimedia_theme_user_initials(?\FavoriteCMS\Models\User $user = null): string
    {
        $name = favorite_multimedia_theme_user_display_name($user);
        $words = preg_split('/\s+/', trim($name));
        if ($words && count($words) >= 2 && !empty($words[0]) && !empty($words[1])) {
            return strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        }
        return strtoupper(mb_substr($name, 0, 2) ?: 'U');
    }
}

if (!function_exists('favorite_multimedia_theme_can_access_admin')) {
    function favorite_multimedia_theme_can_access_admin(?\FavoriteCMS\Models\User $user = null): bool
    {
        try {
            if (!$user) {
                $user = favorite_multimedia_theme_current_user();
            }
            if ($user && method_exists($user, 'hasRole')) {
                if ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('editor') || $user->hasRole('moderator')) {
                    return true;
                }
            }
            if (function_exists('current_user_can')) {
                if (current_user_can('manage_options') || current_user_can('publish_posts') || current_user_can('access_admin')) {
                    return true;
                }
            }
        } catch (\Throwable) {}

        // Fallback to session role if present
        $role = strtolower((string)($_SESSION['auth_user_role'] ?? ''));
        if (in_array($role, ['super-admin', 'admin', 'editor', 'moderator'], true)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('favorite_multimedia_theme_can_create_posts')) {
    function favorite_multimedia_theme_can_create_posts(?\FavoriteCMS\Models\User $user = null): bool
    {
        try {
            if (func_num_args() === 0) {
                $user = favorite_multimedia_theme_current_user();
            }
            if (!$user || ($user->status ?? 'active') !== 'active') {
                return false;
            }
            if (method_exists($user, 'hasRole')) {
                if ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('editor') || $user->hasRole('author')) {
                    return true;
                }
            }
            if (method_exists($user, 'hasPermission')) {
                if ($user->hasPermission('create_posts') || $user->hasPermission('publish_posts') || $user->hasPermission('manage_posts')) {
                    return true;
                }
            }
            if (function_exists('current_user_can')) {
                if (current_user_can('publish_posts') || current_user_can('edit_posts')) {
                    return true;
                }
            }
        } catch (\Throwable) {}

        // Fallback to session role if present only when no explicit user was passed or user matches session
        if (func_num_args() === 0) {
            $role = strtolower((string)($_SESSION['auth_user_role'] ?? ''));
            if (in_array($role, ['super-admin', 'admin', 'editor', 'author'], true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('favorite_multimedia_theme_can_submit_media')) {
    function favorite_multimedia_theme_can_submit_media(?\FavoriteCMS\Models\User $user = null): bool
    {
        try {
            if (func_num_args() === 0 || $user === null) {
                $user = favorite_multimedia_theme_current_user();
            }
            if (!$user || ($user->status ?? 'active') !== 'active') {
                return false;
            }
            if (class_exists(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::class)) {
                return \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::canUserSubmit(null, $user);
            }
            if (method_exists($user, 'hasRole')) {
                if ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('editor') || $user->hasRole('moderator') || $user->hasRole('author') || $user->hasRole('contributor')) {
                    return true;
                }
            }
        } catch (\Throwable) {}

        // Fallback to session role if present only when no explicit user was passed
        if (func_num_args() === 0 || $user === null) {
            $role = strtolower((string)($_SESSION['auth_user_role'] ?? ''));
            if (in_array($role, ['super-admin', 'admin', 'editor', 'moderator', 'author', 'contributor'], true)) {
                return true;
            }
        }

        return false;
    }
}

