<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;

class MultimediaAccessService
{
    public const ALLOW = 'ALLOW';
    public const LOGIN_REQUIRED = 'LOGIN_REQUIRED';
    public const PREMIUM_REQUIRED = 'PREMIUM_REQUIRED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const NOT_FOUND = 'NOT_FOUND';

    /**
     * Evaluate viewing access for a given content item.
     *
     * @param User|null $user
     * @param string $contentType 'movie', 'series', 'episode', 'song', 'playlist'
     * @param int|object $content Either content ID or model instance
     * @return string One of the access constants (ALLOW, LOGIN_REQUIRED, PREMIUM_REQUIRED, FORBIDDEN, NOT_FOUND)
     */
    public static function canAccess(?User $user, string|object $content, ?int $contentId = null): bool
    {
        if (is_object($content)) {
            $contentType = match (true) {
                $content instanceof Movie => 'movie',
                $content instanceof Series => 'series',
                $content instanceof Episode => 'episode',
                $content instanceof Song => 'song',
                $content instanceof Playlist => 'playlist',
                default => 'movie',
            };
            return self::checkAccess($user, $contentType, $content) === self::ALLOW;
        }

        return self::checkAccess($user, (string)$content, $contentId ?? 0) === self::ALLOW;
    }

    public static function checkAccess(?User $user, string $contentType, int|object $content): string
    {
        // 1. Resolve content model
        $model = is_object($content) ? $content : self::findContentModel($contentType, (int)$content);
        if (!$model) {
            return self::NOT_FOUND;
        }

        $isSuspended = MultimediaPermission::isSuspendedUser($user);

        // 2. Draft / Unlisted content check
        $status = $model->status ?? 'published';
        if ($status !== 'published') {
            if ($isSuspended) {
                return self::NOT_FOUND;
            }
            $isOwner = $user && ((int)($model->user_id ?? 0) === (int)$user->id);
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || MultimediaPermission::can(MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return self::NOT_FOUND;
            }
        }

        // Parent hierarchy check for episodes (unreleased series hides episode)
        if ($contentType === 'episode' && $model instanceof Episode) {
            $series = $model->getSeries();
            if ($series && ($series->status ?? 'published') !== 'published') {
                if ($isSuspended) {
                    return self::NOT_FOUND;
                }
                $isSeriesOwner = $user && ((int)($series->user_id ?? 0) === (int)$user->id);
                $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || MultimediaPermission::can(MultimediaPermission::MODERATE, $user));
                if (!$isSeriesOwner && !$isMod) {
                    return self::NOT_FOUND;
                }
            }
        }

        // Parent hierarchy check for songs with unreleased album
        if ($contentType === 'song' && $model instanceof Song && !empty($model->album_id)) {
            $album = $model->getAlbum();
            if ($album && ($album->status ?? 'published') !== 'published') {
                if ($isSuspended) {
                    return self::NOT_FOUND;
                }
                $isAlbumOwner = $user && ((int)($album->user_id ?? 0) === (int)$user->id);
                $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || MultimediaPermission::can(MultimediaPermission::MODERATE, $user));
                if (!$isAlbumOwner && !$isMod) {
                    return self::NOT_FOUND;
                }
            }
        }

        // Access check for private playlists (only owner or moderator/admin)
        if ($contentType === 'playlist' && $model instanceof Playlist && ($model->access_mode ?? 'public') === 'private') {
            if ($isSuspended) {
                return self::NOT_FOUND;
            }
            $isOwner = $user && ((int)($model->user_id ?? 0) === (int)$user->id);
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || MultimediaPermission::can(MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return self::NOT_FOUND;
            }
        }

        // 3. Resolve effective access mode (handling inheritance for episodes)
        $accessMode = self::resolveEffectiveAccessMode($contentType, $model);

        // 4. Evaluate access modes strictly per Locked Access Matrix
        // NOTE: Role alone NEVER grants Premium playback (no bypass for Super Admin or Admin)
        switch ($accessMode) {
            case 'public':
                return self::ALLOW;

            case 'login':
                if (!$user || (int)($user->id ?? 0) <= 0) {
                    return self::LOGIN_REQUIRED;
                }
                if ($isSuspended) {
                    return self::FORBIDDEN;
                }
                return self::ALLOW;

            case 'premium':
                if (!$user || (int)($user->id ?? 0) <= 0) {
                    return self::LOGIN_REQUIRED;
                }
                if ($isSuspended) {
                    return self::FORBIDDEN;
                }

                // Check Favorite Digital entitlement (Exclusive authority for PREMIUM; fails closed for ALL roles)
                try {
                    $hasEntitlement = FavoriteDigitalAdapter::userHasEntitlement($user, $contentType, (int)$model->id);
                    if ($hasEntitlement) {
                        return self::ALLOW;
                    }
                } catch (\Throwable $e) {
                    \FavoriteCMS\Core\Logger::error('Favorite Multimedia: Favorite Digital entitlement evaluation failed (failing closed)', [
                        'user_id'      => (int)$user->id,
                        'content_type' => $contentType,
                        'content_id'   => (int)$model->id,
                        'error'        => $e->getMessage(),
                    ]);
                    return self::PREMIUM_REQUIRED;
                }

                return self::PREMIUM_REQUIRED;

            default:
                return self::ALLOW;
        }
    }

    /**
     * Resolve effective access mode taking hierarchy into account.
     * For Episodes: Episode access_mode ('public', 'login', 'premium') overrides Series access_mode.
     * If Episode access_mode is 'inherit', it inherits from parent Series.
     */
    public static function resolveEffectiveAccessMode(string $contentType, object $model): string
    {
        if ($contentType === 'episode' && $model instanceof Episode) {
            return strtolower($model->getResolvedAccessMode());
        }

        $mode = strtolower((string)($model->access_mode ?? 'public'));
        if ($mode === 'login_required') {
            $mode = 'login';
        }
        return in_array($mode, ['public', 'login', 'premium'], true) ? $mode : 'public';
    }

    /**
     * Resolve viewing access for a song inside a playlist.
     * Enforces BOTH playlist-level access AND individual song access.
     */
    public static function checkPlaylistTrackAccess(?User $user, Playlist $playlist, Song $song): string
    {
        // Check playlist access first
        $playlistAccess = self::checkAccess($user, 'playlist', $playlist);
        if ($playlistAccess !== self::ALLOW) {
            return $playlistAccess;
        }

        // Check individual song access
        return self::checkAccess($user, 'song', $song);
    }

    /**
     * Check download permission for a specific content item and optional source.
     * Download permission is separate from viewing permission, but requires viewing access.
     *
     * @param User|null $user
     * @param string $contentType
     * @param int|object $content
     * @param MediaSource|object|array|null $source
     * @return array{allowed: bool, reason: string, access_state: string}
     */
    public static function checkDownloadPermission(
        ?User $user,
        string $contentType,
        int|object $content,
        object|array|null $source = null
    ): array {
        if (is_array($source)) {
            $srcId = (int)($source['id'] ?? 0);
            $source = ($srcId > 0) ? MediaSource::find($srcId) : (object)$source;
        }

        $model = is_object($content) ? $content : self::findContentModel($contentType, (int)$content);
        if (!$model) {
            return [
                'allowed'        => false,
                'reason'         => 'Content not found.',
                'access_state'   => self::NOT_FOUND,
                'download_url'   => null,
                'download_label' => null,
                'options'        => [],
                'count'          => 0,
                'is_manual'      => false,
            ];
        }

        if (MultimediaPermission::isSuspendedUser($user)) {
            return [
                'allowed'        => false,
                'reason'         => 'Your account does not have permission to download.',
                'access_state'   => self::FORBIDDEN,
                'download_url'   => null,
                'download_label' => null,
                'options'        => [],
                'count'          => 0,
                'is_manual'      => false,
            ];
        }

        // 1. First verify content viewing access (enforces PUBLIC / LOGIN / PREMIUM via Favorite Digital)
        $viewAccess = self::checkAccess($user, $contentType, $model);
        if ($viewAccess !== self::ALLOW) {
            $reason = match ($viewAccess) {
                self::LOGIN_REQUIRED   => 'You must log in to download this content.',
                self::PREMIUM_REQUIRED => 'A Premium subscription is required to download this media.',
                self::FORBIDDEN        => 'Your account does not have permission to download.',
                default                => 'Content access is restricted.',
            };
            return [
                'allowed'        => false,
                'reason'         => $reason,
                'access_state'   => $viewAccess,
                'download_url'   => null,
                'download_label' => null,
                'options'        => [],
                'count'          => 0,
                'is_manual'      => false,
            ];
        }

        $isAdmin = ($user && ($user->hasRole('super-admin') || $user->hasRole('admin')));

        // 2. Check content download policy & global setting
        $globalSetting = Setting::get('multimedia', 'enable_downloads', 'yes');
        $isGlobalEnabled = ($globalSetting === 'yes' || $globalSetting === '1' || $globalSetting === 'true');

        $parentPolicy = (string)($model->download_policy ?? 'inherit');
        if ($contentType === 'episode' && $model instanceof Episode) {
            $parentPolicy = $model->getResolvedDownloadPolicy();
        }

        if (!$isAdmin) {
            if ($parentPolicy === 'deny') {
                return [
                    'allowed'        => false,
                    'reason'         => 'Downloads are disabled for this content.',
                    'access_state'   => self::FORBIDDEN,
                    'download_url'   => null,
                    'download_label' => null,
                    'options'        => [],
                    'count'          => 0,
                    'is_manual'      => false,
                ];
            }
            if ($parentPolicy === 'inherit' && !$isGlobalEnabled) {
                return [
                    'allowed'        => false,
                    'reason'         => 'Media downloads are currently disabled site-wide.',
                    'access_state'   => self::FORBIDDEN,
                    'download_url'   => null,
                    'download_label' => null,
                    'options'        => [],
                    'count'          => 0,
                    'is_manual'      => false,
                ];
            }
        }

        // 3. Check manual download options via DownloadSourceService first
        // If content has active manual download sources or a manual legacy download_url,
        // it always takes precedence or provides fallback for embeds/HLS/denied streams.
        $resolved = DownloadSourceService::resolveDownloadOptions($user, $contentType, $model, $source);
        if ($resolved['allowed']) {
            return $resolved;
        }

        // When downloads are not allowed via manual sources, check specific media source if provided
        if ($source !== null && !empty($source->id)) {
            $sourcePolicy = (string)($source->allow_download ?? 'inherit');
            if (!$isAdmin && $sourcePolicy === 'deny') {
                return [
                    'allowed'        => false,
                    'reason'         => 'Downloads are disabled for this media source.',
                    'access_state'   => self::FORBIDDEN,
                    'download_url'   => null,
                    'download_label' => null,
                    'options'        => [],
                    'count'          => 0,
                    'is_manual'      => false,
                ];
            }

            $sourceType = strtolower(trim((string)$source->source_type));
            $urlOrPath = trim((string)$source->url_or_path);

            if ($sourceType === 'embed' || $sourceType === 'hls' || str_contains(strtolower($urlOrPath), '.m3u8')) {
                $reason = ($sourceType === 'hls' || str_contains(strtolower($urlOrPath), '.m3u8'))
                    ? 'M3U8 streams cannot be downloaded as direct files.'
                    : 'External embedded media does not support direct downloading.';
                return [
                    'allowed'        => false,
                    'reason'         => $reason,
                    'access_state'   => self::FORBIDDEN,
                    'download_url'   => null,
                    'download_label' => null,
                    'options'        => [],
                    'count'          => 0,
                    'is_manual'      => false,
                ];
            }
        }

        return $resolved;
    }

    public static function findContentModel(string $contentType, int $id): ?object
    {
        return match ($contentType) {
            'movie'    => Movie::find($id),
            'series'   => \FavoriteCMS\Multimedia\Models\Series::find($id),
            'episode'  => Episode::find($id),
            'song'     => Song::find($id),
            'playlist' => Playlist::find($id),
            default    => null,
        };
    }
}

