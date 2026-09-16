<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class PlaybackProgress extends BaseModel
{
    protected static string $table = 'multimedia_playback_progress';

    public const ALLOWED_TYPES = ['movie', 'episode', 'song'];

    /**
     * Find progress for a specific user and content item.
     */
    public static function findByUserAndContent(int $userId, string $contentType, int $contentId): ?static
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return null;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT * FROM multimedia_playback_progress WHERE user_id = ? AND content_type = ? AND content_id = ?",
            [$userId, $contentType, $contentId]
        );

        return $row ? new static((array)$row) : null;
    }

    /**
     * Record or update playback progress.
     */
    public static function recordProgress(
        int $userId,
        string $contentType,
        int $contentId,
        float $position,
        float $duration,
        float $percentage,
        bool $isCompleted
    ): static {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $existing = self::findByUserAndContent($userId, $contentType, $contentId);
        $now = date('Y-m-d H:i:s');

        if ($existing) {
            $db->query(
                "UPDATE multimedia_playback_progress SET
                    position = ?,
                    duration = ?,
                    percentage = ?,
                    is_completed = ?,
                    last_played_at = ?,
                    updated_at = ?
                 WHERE id = ?",
                [
                    $position,
                    $duration,
                    $percentage,
                    $isCompleted ? 1 : 0,
                    $now,
                    $now,
                    (int)$existing->id
                ]
            );
            return self::find((int)$existing->id);
        }

        $id = $db->insert('multimedia_playback_progress', [
            'user_id'        => $userId,
            'content_type'   => $contentType,
            'content_id'     => $contentId,
            'position'       => $position,
            'duration'       => $duration,
            'percentage'     => $percentage,
            'is_completed'   => $isCompleted ? 1 : 0,
            'last_played_at' => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return self::find((int)$id);
    }

    /**
     * Convenience method to calculate percentage and completion before recording.
     */
    public static function saveProgress(
        int $userId,
        string $contentType,
        int $contentId,
        float $position,
        float $duration,
        bool $isCompleted = false
    ): static {
        $duration = max(0.0, $duration);
        $percentage = $duration > 0.0 ? round(($position / $duration) * 100, 2) : 0.0;
        if ($percentage >= 90.0) {
            $isCompleted = true;
        }
        return self::recordProgress($userId, $contentType, $contentId, $position, $duration, $percentage, $isCompleted);
    }

    /**
     * Get partially watched movies and episodes for Continue Watching.
     * Only items with position >= 5 seconds and not completed.
     */
    public static function getContinueWatching(int $userId, int $limit = 12): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select(
            "SELECT * FROM multimedia_playback_progress
             WHERE user_id = ?
               AND content_type IN ('movie', 'episode')
               AND is_completed = 0
               AND position >= 5.0
             ORDER BY last_played_at DESC
             LIMIT {$limit}",
            [$userId]
        );

        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Get partially played songs for Continue Listening.
     */
    public static function getContinueListening(int $userId, int $limit = 12): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select(
            "SELECT * FROM multimedia_playback_progress
             WHERE user_id = ?
               AND content_type = 'song'
               AND is_completed = 0
               AND position >= 5.0
             ORDER BY last_played_at DESC
             LIMIT {$limit}",
            [$userId]
        );

        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Get recent watch and listening history ordered by last_played_at.
     */
    public static function getHistory(int $userId, int $limit = 20, int $offset = 0, ?string $contentType = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "user_id = ?";
        $params = [$userId];

        if ($contentType !== null && in_array($contentType, self::ALLOWED_TYPES, true)) {
            $where .= " AND content_type = ?";
            $params[] = $contentType;
        }

        $rows = $db->select(
            "SELECT * FROM multimedia_playback_progress
             WHERE {$where}
             ORDER BY last_played_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Count history items for a user.
     */
    public static function countHistory(int $userId, ?string $contentType = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "user_id = ?";
        $params = [$userId];

        if ($contentType !== null && in_array($contentType, self::ALLOWED_TYPES, true)) {
            $where .= " AND content_type = ?";
            $params[] = $contentType;
        }

        $row = $db->selectOne("SELECT COUNT(*) as c FROM multimedia_playback_progress WHERE {$where}", $params);
        return (int)($row->c ?? 0);
    }

    /**
     * Delete a single history entry for a user.
     */
    public static function deleteEntry(int $userId, string $contentType, int $contentId): bool
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query(
            "DELETE FROM multimedia_playback_progress WHERE user_id = ? AND content_type = ? AND content_id = ?",
            [$userId, $contentType, $contentId]
        );
        return true;
    }

    /**
     * Clear all history entries for a user.
     */
    public static function clearForUser(int $userId, ?string $contentType = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        if ($contentType !== null && in_array($contentType, self::ALLOWED_TYPES, true)) {
            $db->query("DELETE FROM multimedia_playback_progress WHERE user_id = ? AND content_type = ?", [$userId, $contentType]);
        } else {
            $db->query("DELETE FROM multimedia_playback_progress WHERE user_id = ?", [$userId]);
        }
        return true;
    }

    /**
     * Cascade deletion when a content item is deleted.
     */
    public static function deleteForContent(string $contentType, int $contentId): void
    {
        if ($contentId <= 0) {
            return;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query(
            "DELETE FROM multimedia_playback_progress WHERE content_type = ? AND content_id = ?",
            [$contentType, $contentId]
        );
    }
}
