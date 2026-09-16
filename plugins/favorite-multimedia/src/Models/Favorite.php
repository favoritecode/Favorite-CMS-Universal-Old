<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Favorite extends BaseModel
{
    protected static string $table = 'multimedia_favorites';

    public const ALLOWED_TYPES = ['movie', 'series', 'song', 'playlist'];

    /**
     * Check if a content item is favorited by the user.
     */
    public static function isFavorited(int $userId, string $contentType, int $contentId): bool
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT id FROM multimedia_favorites WHERE user_id = ? AND content_type = ? AND content_id = ?",
            [$userId, $contentType, $contentId]
        );

        return $row !== null;
    }

    /**
     * Add an item to the user's favorites list.
     */
    public static function addFavorite(int $userId, string $contentType, int $contentId): bool
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return false;
        }

        if (self::isFavorited($userId, $contentType, $contentId)) {
            return true;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        try {
            $db->insert('multimedia_favorites', [
                'user_id'      => $userId,
                'content_type' => $contentType,
                'content_id'   => $contentId,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Remove an item from the user's favorites list.
     */
    public static function removeFavorite(int $userId, string $contentType, int $contentId): bool
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query(
            "DELETE FROM multimedia_favorites WHERE user_id = ? AND content_type = ? AND content_id = ?",
            [$userId, $contentType, $contentId]
        );
        return true;
    }

    /**
     * Toggle favorite state for a content item.
     */
    public static function toggleFavorite(int $userId, string $contentType, int $contentId): array
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return ['success' => false, 'is_favorited' => false, 'error' => 'Invalid parameters.'];
        }

        if (self::isFavorited($userId, $contentType, $contentId)) {
            self::removeFavorite($userId, $contentType, $contentId);
            return ['success' => true, 'is_favorited' => false];
        }

        $added = self::addFavorite($userId, $contentType, $contentId);
        return ['success' => $added, 'is_favorited' => $added];
    }

    /**
     * Get favorites for a specific user.
     */
    public static function getByUser(int $userId, int $limit = 20, int $offset = 0, ?string $contentType = null): array
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
            "SELECT * FROM multimedia_favorites
             WHERE {$where}
             ORDER BY created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Count favorites for a user.
     */
    public static function countByUser(int $userId, ?string $contentType = null): int
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

        $row = $db->selectOne("SELECT COUNT(*) as c FROM multimedia_favorites WHERE {$where}", $params);
        return (int)($row->c ?? 0);
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
            "DELETE FROM multimedia_favorites WHERE content_type = ? AND content_id = ?",
            [$contentType, $contentId]
        );
    }
}

