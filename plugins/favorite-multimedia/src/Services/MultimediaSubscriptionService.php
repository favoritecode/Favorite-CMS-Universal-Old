<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\MultimediaSubscription;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Series;

class MultimediaSubscriptionService
{
    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    public static function hasSubscriptionsTable(): bool
    {
        try {
            $db = self::getDb();
            return method_exists($db, 'tableExists') ? $db->tableExists('multimedia_subscriptions') : true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function follow(mixed $user, string $targetType, int $targetId): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required to follow.'];
        }

        if (!in_array($targetType, MultimediaSubscription::ALLOWED_TARGETS, true)) {
            return ['success' => false, 'error' => 'Invalid subscription target type.'];
        }

        if ($targetId <= 0 || !self::targetExists($targetType, $targetId)) {
            return ['success' => false, 'error' => 'Target item not found.'];
        }

        if (!self::hasSubscriptionsTable()) {
            return ['success' => false, 'error' => 'Subscriptions unavailable.'];
        }

        $ok = MultimediaSubscription::follow($userId, $targetType, $targetId);
        $count = MultimediaSubscription::countSubscribers($targetType, $targetId);

        return [
            'success'      => $ok,
            'is_following' => true,
            'subscriber_count' => $count,
        ];
    }

    public static function subscribe(mixed $user, string $targetType, int $targetId): bool
    {
        $res = self::follow($user, $targetType, $targetId);
        return (bool)($res['success'] ?? false);
    }

    public static function unfollow(mixed $user, string $targetType, int $targetId): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'];
        }

        if (!in_array($targetType, MultimediaSubscription::ALLOWED_TARGETS, true)) {
            return ['success' => false, 'error' => 'Invalid subscription target type.'];
        }

        if (!self::hasSubscriptionsTable()) {
            return ['success' => false, 'error' => 'Subscriptions unavailable.'];
        }

        $ok = MultimediaSubscription::unfollow($userId, $targetType, $targetId);
        $count = MultimediaSubscription::countSubscribers($targetType, $targetId);

        return [
            'success'      => $ok,
            'is_following' => false,
            'subscriber_count' => $count,
        ];
    }

    public static function toggle(mixed $user, string $targetType, int $targetId): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'];
        }

        if (self::isFollowing($user, $targetType, $targetId)) {
            return self::unfollow($user, $targetType, $targetId);
        }

        return self::follow($user, $targetType, $targetId);
    }

    public static function isFollowing(mixed $user, string $targetType, int $targetId): bool
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasSubscriptionsTable()) {
            return false;
        }

        return MultimediaSubscription::isFollowing($userId, $targetType, $targetId);
    }

    public static function getSubscriptions(mixed $user, ?string $targetType = null, int $limit = 20, int $offset = 0): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasSubscriptionsTable()) {
            return ['items' => [], 'total' => 0];
        }

        $subs = MultimediaSubscription::getSubscriptions($userId, $targetType, $limit, $offset);
        $total = MultimediaSubscription::countSubscriptions($userId, $targetType);

        $hydrated = [];
        foreach ($subs as $sub) {
            $item = self::hydrateTarget((string)$sub->target_type, (int)$sub->target_id);
            if ($item !== null) {
                $hydrated[] = [
                    'id'          => (int)$sub->id,
                    'target_type' => (string)$sub->target_type,
                    'target_id'   => (int)$sub->target_id,
                    'created_at'  => (string)$sub->created_at,
                    'item'        => $item,
                ];
            }
        }

        return [
            'items' => $hydrated,
            'total' => $total,
        ];
    }

    public static function getUserSubscriptions(mixed $user): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasSubscriptionsTable()) {
            return ['series' => [], 'artist' => [], 'playlist' => []];
        }

        return [
            'series'   => self::getSubscriptions($userId, MultimediaSubscription::TARGET_SERIES)['items'] ?? [],
            'artist'   => self::getSubscriptions($userId, MultimediaSubscription::TARGET_ARTIST)['items'] ?? [],
            'playlist' => self::getSubscriptions($userId, MultimediaSubscription::TARGET_PLAYLIST)['items'] ?? [],
        ];
    }

    public static function getSubscribers(string $targetType, int $targetId, int $limit = 500, int $offset = 0): array
    {
        if (!self::hasSubscriptionsTable()) {
            return [];
        }

        return MultimediaSubscription::getSubscribers($targetType, $targetId, $limit, $offset);
    }

    public static function countSubscribers(string $targetType, int $targetId): int
    {
        if (!self::hasSubscriptionsTable()) {
            return 0;
        }

        return MultimediaSubscription::countSubscribers($targetType, $targetId);
    }

    public static function deleteForTarget(string $targetType, int $targetId): void
    {
        if (self::hasSubscriptionsTable()) {
            MultimediaSubscription::deleteForTarget($targetType, $targetId);
        }
    }

    public static function targetExists(string $targetType, int $targetId): bool
    {
        return match ($targetType) {
            MultimediaSubscription::TARGET_SERIES   => Series::find($targetId) !== null,
            MultimediaSubscription::TARGET_ARTIST   => Artist::find($targetId) !== null,
            MultimediaSubscription::TARGET_PLAYLIST => Playlist::find($targetId) !== null,
            default                                 => false,
        };
    }

    public static function hydrateTarget(string $targetType, int $targetId): ?array
    {
        switch ($targetType) {
            case MultimediaSubscription::TARGET_SERIES:
                $s = Series::find($targetId);
                if (!$s) {
                    return [
                        'title'        => 'Unavailable Series',
                        'slug'         => '',
                        'poster'       => '',
                        'url'          => '#',
                        'is_available' => false,
                    ];
                }
                return [
                    'title'        => (string)$s->title,
                    'slug'         => (string)$s->slug,
                    'poster'       => (string)($s->poster ?? ''),
                    'url'          => '/series/' . urlencode((string)$s->slug),
                    'is_available' => true,
                ];

            case MultimediaSubscription::TARGET_ARTIST:
                $a = Artist::find($targetId);
                if (!$a) {
                    return [
                        'title'        => 'Unavailable Artist',
                        'slug'         => '',
                        'poster'       => '',
                        'url'          => '#',
                        'is_available' => false,
                    ];
                }
                return [
                    'title'        => (string)$a->name,
                    'slug'         => (string)$a->slug,
                    'poster'       => (string)($a->photo ?? ''),
                    'url'          => '/multimedia/artist/' . urlencode((string)$a->slug),
                    'is_available' => true,
                ];

            case MultimediaSubscription::TARGET_PLAYLIST:
                $p = Playlist::find($targetId);
                if (!$p) {
                    return [
                        'title'        => 'Unavailable Playlist',
                        'slug'         => '',
                        'poster'       => '',
                        'url'          => '#',
                        'is_available' => false,
                    ];
                }
                return [
                    'title'        => (string)$p->title,
                    'slug'         => (string)$p->slug,
                    'poster'       => (string)($p->cover ?? ''),
                    'url'          => '/playlist/' . urlencode((string)$p->slug),
                    'is_available' => true,
                ];

            default:
                return null;
        }
    }
}
