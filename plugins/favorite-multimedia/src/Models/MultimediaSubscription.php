<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class MultimediaSubscription extends BaseModel
{
    protected static string $table = 'multimedia_subscriptions';

    public const TARGET_SERIES   = 'series';
    public const TARGET_ARTIST   = 'artist';
    public const TARGET_PLAYLIST = 'playlist';

    public const ALLOWED_TARGETS = [
        self::TARGET_SERIES,
        self::TARGET_ARTIST,
        self::TARGET_PLAYLIST,
    ];

    /**
     * Check if a user is following a target.
     */
    public static function isFollowing(int $userId, string $targetType, int $targetId): bool
    {
        if ($userId <= 0 || !in_array($targetType, self::ALLOWED_TARGETS, true) || $targetId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT id FROM multimedia_subscriptions WHERE user_id = ? AND target_type = ? AND target_id = ? LIMIT 1",
            [$userId, $targetType, $targetId]
        );

        return $row !== null;
    }

    /**
     * Follow a target (idempotent).
     */
    public static function follow(int $userId, string $targetType, int $targetId): bool
    {
        if ($userId <= 0 || !in_array($targetType, self::ALLOWED_TARGETS, true) || $targetId <= 0) {
            return false;
        }

        if (self::isFollowing($userId, $targetType, $targetId)) {
            return true;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        try {
            $db->insert('multimedia_subscriptions', [
                'user_id'     => $userId,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Unfollow a target (idempotent).
     */
    public static function unfollow(int $userId, string $targetType, int $targetId): bool
    {
        if ($userId <= 0 || !in_array($targetType, self::ALLOWED_TARGETS, true) || $targetId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query(
            "DELETE FROM multimedia_subscriptions WHERE user_id = ? AND target_type = ? AND target_id = ?",
            [$userId, $targetType, $targetId]
        );
        return true;
    }

    /**
     * Get user's subscriptions.
     */
    public static function getSubscriptions(int $userId, ?string $targetType = null, int $limit = 50, int $offset = 0): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($targetType && in_array($targetType, self::ALLOWED_TARGETS, true)) {
            $rows = $db->select(
                "SELECT * FROM multimedia_subscriptions WHERE user_id = ? AND target_type = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
                [$userId, $targetType, $limit, $offset]
            );
        } else {
            $rows = $db->select(
                "SELECT * FROM multimedia_subscriptions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
                [$userId, $limit, $offset]
            );
        }

        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Count user's subscriptions.
     */
    public static function countSubscriptions(int $userId, ?string $targetType = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        if ($targetType && in_array($targetType, self::ALLOWED_TARGETS, true)) {
            $row = $db->selectOne(
                "SELECT COUNT(*) as c FROM multimedia_subscriptions WHERE user_id = ? AND target_type = ?",
                [$userId, $targetType]
            );
        } else {
            $row = $db->selectOne(
                "SELECT COUNT(*) as c FROM multimedia_subscriptions WHERE user_id = ?",
                [$userId]
            );
        }

        return (int)($row->c ?? 0);
    }

    /**
     * Get subscriber user IDs for a target.
     *
     * @return int[]
     */
    public static function getSubscribers(string $targetType, int $targetId, int $limit = 500, int $offset = 0): array
    {
        if (!in_array($targetType, self::ALLOWED_TARGETS, true) || $targetId <= 0) {
            return [];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select(
            "SELECT user_id FROM multimedia_subscriptions WHERE target_type = ? AND target_id = ? ORDER BY id ASC LIMIT ? OFFSET ?",
            [$targetType, $targetId, $limit, $offset]
        );

        return array_map(fn($r) => (int)$r->user_id, $rows);
    }

    /**
     * Count subscribers for a target.
     */
    public static function countSubscribers(string $targetType, int $targetId): int
    {
        if (!in_array($targetType, self::ALLOWED_TARGETS, true) || $targetId <= 0) {
            return 0;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT COUNT(*) as c FROM multimedia_subscriptions WHERE target_type = ? AND target_id = ?",
            [$targetType, $targetId]
        );

        return (int)($row->c ?? 0);
    }

    /**
     * Cascade deletion when target content is deleted.
     */
    public static function deleteForTarget(string $targetType, int $targetId): int
    {
        if ($targetId <= 0) {
            return 0;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $stmt = $db->query(
            "DELETE FROM multimedia_subscriptions WHERE target_type = ? AND target_id = ?",
            [$targetType, $targetId]
        );
        return $stmt->rowCount();
    }

    public static function cleanupForTarget(string $targetType, int $targetId): int
    {
        return self::deleteForTarget($targetType, $targetId);
    }
}
