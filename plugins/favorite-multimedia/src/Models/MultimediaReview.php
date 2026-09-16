<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class MultimediaReview extends BaseModel
{
    protected static string $table = 'multimedia_reviews';

    public const ALLOWED_TYPES = ['movie', 'series', 'song', 'playlist'];
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_HIDDEN   = 'hidden';

    /**
     * Find single review by ID.
     */
    public static function find(int $id): ?static
    {
        if ($id <= 0) {
            return null;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_reviews WHERE id = ? LIMIT 1", [$id]);
        return $row ? new static((array)$row) : null;
    }

    /**
     * Get user's review for content.
     */
    public static function getUserReview(int $userId, string $contentType, int $contentId): ?static
    {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return null;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT * FROM multimedia_reviews WHERE user_id = ? AND content_type = ? AND content_id = ? LIMIT 1",
            [$userId, $contentType, $contentId]
        );

        return $row ? new static((array)$row) : null;
    }

    /**
     * Create or update review for content.
     */
    public static function saveReview(
        int $userId,
        string $contentType,
        int $contentId,
        string $body,
        ?string $title = null,
        ?int $rating = null,
        bool $isSpoiler = false,
        string $status = self::STATUS_APPROVED
    ): ?static {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return null;
        }

        $body = trim($body);
        if (mb_strlen($body, 'UTF-8') < 3 || mb_strlen($body, 'UTF-8') > 3000) {
            return null;
        }

        $title = $title !== null ? trim($title) : null;
        if ($title !== null && mb_strlen($title, 'UTF-8') > 255) {
            $title = mb_substr($title, 0, 255, 'UTF-8');
        }

        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            $rating = null;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $now = date('Y-m-d H:i:s');

        $existing = self::getUserReview($userId, $contentType, $contentId);
        if ($existing) {
            $db->update('multimedia_reviews', [
                'title'            => $title,
                'body'             => $body,
                'rating'           => $rating,
                'contains_spoiler' => $isSpoiler ? 1 : 0,
                'status'           => $status,
                'updated_at'       => $now,
            ], ['id' => (int)$existing->id]);

            // Sync rating to multimedia_ratings if provided
            if ($rating !== null) {
                MultimediaRating::setRating($userId, $contentType, $contentId, $rating);
            }

            return self::find((int)$existing->id);
        }

        try {
            $insertRes = $db->insert('multimedia_reviews', [
                'user_id'          => $userId,
                'content_type'     => $contentType,
                'content_id'       => $contentId,
                'title'            => $title,
                'body'             => $body,
                'rating'           => $rating,
                'status'           => $status,
                'contains_spoiler' => $isSpoiler ? 1 : 0,
                'helpful_count'    => 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $newId = is_numeric($insertRes) ? (int)$insertRes : 0;
            if ($newId <= 0) {
                try {
                    $newId = (int)$db->getConnection()->lastInsertId();
                } catch (\Throwable) {}
            }

            // Sync rating to multimedia_ratings if provided
            if ($rating !== null) {
                MultimediaRating::setRating($userId, $contentType, $contentId, $rating);
            }

            return self::find($newId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Delete review by owner or moderator.
     */
    public static function deleteReview(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query("DELETE FROM multimedia_reviews WHERE id = ?", [$id]);
        $db->query("DELETE FROM multimedia_review_helpful WHERE review_id = ?", [$id]);
        $db->query("DELETE FROM multimedia_reports WHERE target_type = 'review' AND target_id = ?", [$id]);
        return true;
    }

    /**
     * Toggle helpful vote for review.
     */
    public static function voteHelpful(int $userId, int $reviewId): array
    {
        if ($userId <= 0 || $reviewId <= 0) {
            return ['success' => false, 'error' => 'Invalid parameters.'];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $existing = $db->selectOne(
            "SELECT id FROM multimedia_review_helpful WHERE user_id = ? AND review_id = ?",
            [$userId, $reviewId]
        );

        if ($existing) {
            $db->query("DELETE FROM multimedia_review_helpful WHERE id = ?", [$existing->id]);
            $db->query("UPDATE multimedia_reviews SET helpful_count = MAX(0, helpful_count - 1) WHERE id = ?", [$reviewId]);
            $rev = self::find($reviewId);
            return ['success' => true, 'voted' => false, 'helpful_count' => (int)($rev->helpful_count ?? 0)];
        }

        try {
            $db->insert('multimedia_review_helpful', [
                'user_id'    => $userId,
                'review_id'  => $reviewId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $db->query("UPDATE multimedia_reviews SET helpful_count = helpful_count + 1 WHERE id = ?", [$reviewId]);
            $rev = self::find($reviewId);
            return ['success' => true, 'voted' => true, 'helpful_count' => (int)($rev->helpful_count ?? 0)];
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Database error.'];
        }
    }

    /**
     * Check if user voted helpful for review.
     */
    public static function hasVotedHelpful(int $userId, int $reviewId): bool
    {
        if ($userId <= 0 || $reviewId <= 0) {
            return false;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT id FROM multimedia_review_helpful WHERE user_id = ? AND review_id = ?",
            [$userId, $reviewId]
        );
        return $row !== null;
    }

    /**
     * Get published/approved reviews for content with pagination and sort.
     */
    public static function getForContent(
        string $contentType,
        int $contentId,
        int $limit = 10,
        int $offset = 0,
        string $sort = 'newest',
        ?int $includeUserId = null
    ): array {
        if (!in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return [];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "content_type = ? AND content_id = ?";
        $params = [$contentType, $contentId];

        if ($includeUserId !== null && $includeUserId > 0) {
            $where .= " AND (status = 'approved' OR user_id = ?)";
            $params[] = $includeUserId;
        } else {
            $where .= " AND status = 'approved'";
        }

        $orderClause = match ($sort) {
            'highest' => 'rating DESC, helpful_count DESC, created_at DESC',
            'helpful' => 'helpful_count DESC, created_at DESC',
            default   => 'created_at DESC, id DESC',
        };

        $rows = $db->select(
            "SELECT * FROM multimedia_reviews WHERE {$where} ORDER BY {$orderClause} LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Count approved reviews for content.
     */
    public static function countForContent(string $contentType, int $contentId): int
    {
        if (!in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return 0;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT COUNT(*) as c FROM multimedia_reviews WHERE content_type = ? AND content_id = ? AND status = 'approved'",
            [$contentType, $contentId]
        );
        return (int)($row->c ?? 0);
    }

    /**
     * Cascade deletion when content is deleted.
     */
    public static function deleteForContent(string $contentType, int $contentId): void
    {
        if ($contentId <= 0) {
            return;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $reviews = $db->select("SELECT id FROM multimedia_reviews WHERE content_type = ? AND content_id = ?", [$contentType, $contentId]);
        foreach ($reviews as $r) {
            self::deleteReview((int)$r->id);
        }
    }
}
