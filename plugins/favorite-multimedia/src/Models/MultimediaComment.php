<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class MultimediaComment extends BaseModel
{
    protected static string $table = 'multimedia_comments';

    public const ALLOWED_TYPES = ['movie', 'series', 'episode', 'song', 'playlist'];
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_HIDDEN   = 'hidden';

    /**
     * Find single comment by ID.
     */
    public static function find(int $id): ?static
    {
        if ($id <= 0) {
            return null;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_comments WHERE id = ? LIMIT 1", [$id]);
        return $row ? new static((array)$row) : null;
    }

    /**
     * Create comment or reply.
     */
    public static function createComment(
        int $userId,
        string $contentType,
        int $contentId,
        string $body,
        ?int $parentId = null,
        string $status = self::STATUS_APPROVED
    ): ?static {
        if ($userId <= 0 || !in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return null;
        }

        $body = trim($body);
        if (mb_strlen($body, 'UTF-8') < 1 || mb_strlen($body, 'UTF-8') > 1000) {
            return null;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);

        // Verify parent comment if replying (must belong to same content and be top-level)
        $actualParentId = null;
        if ($parentId !== null && $parentId > 0) {
            $parent = self::find($parentId);
            if ($parent && (string)$parent->content_type === $contentType && (int)$parent->content_id === $contentId) {
                // If the parent itself has a parent, attach to the top-level parent to maintain shallow 1-level depth
                $actualParentId = $parent->parent_id ? (int)$parent->parent_id : (int)$parent->id;
            } else {
                return null;
            }
        }

        $now = date('Y-m-d H:i:s');
        try {
            $insertRes = $db->insert('multimedia_comments', [
                'user_id'      => $userId,
                'content_type' => $contentType,
                'content_id'   => $contentId,
                'parent_id'    => $actualParentId,
                'body'         => $body,
                'status'       => $status,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            $newId = is_numeric($insertRes) ? (int)$insertRes : 0;
            if ($newId <= 0) {
                try {
                    $newId = (int)$db->getConnection()->lastInsertId();
                } catch (\Throwable) {}
            }
            return self::find($newId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Update comment by author.
     */
    public static function updateComment(int $id, int $userId, string $body): bool
    {
        $comment = self::find($id);
        if (!$comment || (int)$comment->user_id !== $userId) {
            return false;
        }

        $body = trim($body);
        if (mb_strlen($body, 'UTF-8') < 1 || mb_strlen($body, 'UTF-8') > 1000) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->update('multimedia_comments', [
            'body'       => $body,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        return true;
    }

    /**
     * Delete comment and child replies.
     */
    public static function deleteComment(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);

        // Delete child replies
        $db->query("DELETE FROM multimedia_comments WHERE parent_id = ?", [$id]);

        // Delete reports for this comment
        $db->query("DELETE FROM multimedia_reports WHERE target_type = 'comment' AND target_id = ?", [$id]);

        // Delete comment itself
        $db->query("DELETE FROM multimedia_comments WHERE id = ?", [$id]);
        return true;
    }

    /**
     * Get approved comments for content structured as top-level with replies.
     */
    public static function getForContent(string $contentType, int $contentId, int $limit = 20, int $offset = 0): array
    {
        if (!in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return [];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);

        // 1. Fetch top-level comments
        $rows = $db->select(
            "SELECT * FROM multimedia_comments
             WHERE content_type = ? AND content_id = ? AND parent_id IS NULL AND status = 'approved'
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$contentType, $contentId]
        );

        $comments = array_map(fn($r) => new static((array)$r), $rows);
        if (empty($comments)) {
            return [];
        }

        $parentIds = array_map(fn($c) => (int)$c->id, $comments);
        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));

        // 2. Fetch replies for these parent comments
        $replyRows = $db->select(
            "SELECT * FROM multimedia_comments
             WHERE parent_id IN ({$placeholders}) AND status = 'approved'
             ORDER BY created_at ASC, id ASC",
            $parentIds
        );

        $repliesByParent = [];
        foreach ($replyRows as $r) {
            $pid = (int)$r->parent_id;
            $repliesByParent[$pid][] = new static((array)$r);
        }

        foreach ($comments as $c) {
            $c->replies = $repliesByParent[(int)$c->id] ?? [];
        }

        return $comments;
    }

    /**
     * Count top-level approved comments for content.
     */
    public static function countForContent(string $contentType, int $contentId): int
    {
        if (!in_array($contentType, self::ALLOWED_TYPES, true) || $contentId <= 0) {
            return 0;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT COUNT(*) as c FROM multimedia_comments WHERE content_type = ? AND content_id = ? AND status = 'approved'",
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
        $comments = $db->select("SELECT id FROM multimedia_comments WHERE content_type = ? AND content_id = ?", [$contentType, $contentId]);
        foreach ($comments as $c) {
            self::deleteComment((int)$c->id);
        }
    }
}
