<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class MultimediaRating extends BaseModel
{
    protected static string $table = 'multimedia_ratings';

    public const ALLOWED_TYPES = ['movie', 'series', 'episode', 'song', 'playlist'];

    /**
     * Get single user's rating for content.
     */
    public static function getUserRating(int $userId, string $contentType, int $contentId): ?int
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return null;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT rating FROM multimedia_ratings WHERE user_id = ? AND content_type = ? AND content_id = ?",
            [$userId, $contentType, $contentId]
        );

        return $row ? (int)$row->rating : null;
    }

    /**
     * Set or update user rating (1-5).
     */
    public static function setRating(int $userId, string $contentType, int $contentId, int $rating): bool
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return false;
        }
        if ($rating < 1 || $rating > 5) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $now = date('Y-m-d H:i:s');

        $existing = $db->selectOne(
            "SELECT id FROM multimedia_ratings WHERE user_id = ? AND content_type = ? AND content_id = ?",
            [$userId, $contentType, $contentId]
        );

        if ($existing) {
            $db->update('multimedia_ratings', [
                'rating'     => $rating,
                'updated_at' => $now,
            ], ['id' => (int)$existing->id]);
            return true;
        }

        try {
            $db->insert('multimedia_ratings', [
                'user_id'      => $userId,
                'content_type' => $contentType,
                'content_id'   => $contentId,
                'rating'       => $rating,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Delete user rating.
     */
    public static function removeRating(int $userId, string $contentType, int $contentId): bool
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query(
            "DELETE FROM multimedia_ratings WHERE user_id = ? AND content_type = ? AND content_id = ?",
            [$userId, $contentType, $contentId]
        );
        return true;
    }

    /**
     * Get aggregate rating score (average and count).
     */
    public static function getAggregate(string $contentType, int $contentId): array
    {
        if (!in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return ['average' => 0.0, 'count' => 0];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT AVG(rating) as avg_rating, COUNT(*) as rating_count
             FROM multimedia_ratings
             WHERE content_type = ? AND content_id = ?",
            [$contentType, $contentId]
        );

        $avg = $row && $row->avg_rating !== null ? round((float)$row->avg_rating, 1) : 0.0;
        $cnt = $row ? (int)($row->rating_count ?? 0) : 0;

        return [
            'average' => $avg,
            'count'   => $cnt,
        ];
    }

    /**
     * Cascade deletion when content item is deleted.
     */
    public static function deleteForContent(string $contentType, int $contentId): void
    {
        if ($contentId <= 0) {
            return;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query(
            "DELETE FROM multimedia_ratings WHERE content_type = ? AND content_id = ?",
            [$contentType, $contentId]
        );
    }
}
