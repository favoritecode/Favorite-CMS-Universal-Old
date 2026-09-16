<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class MultimediaNotification extends BaseModel
{
    protected static string $table = 'multimedia_notifications';

    public const TYPE_NEW_EPISODE      = 'new_episode';
    public const TYPE_NEW_ARTIST_SONG  = 'new_artist_song';
    public const TYPE_PLAYLIST_UPDATED = 'playlist_updated';
    public const TYPE_COMMENT_REPLY    = 'comment_reply';
    public const TYPE_REVIEW_APPROVED  = 'review_approved';
    public const TYPE_REVIEW_REJECTED  = 'review_rejected';
    public const TYPE_COMMENT_APPROVED = 'comment_approved';
    public const TYPE_COMMENT_REJECTED = 'comment_rejected';

    public const ALLOWED_TYPES = [
        self::TYPE_NEW_EPISODE,
        self::TYPE_NEW_ARTIST_SONG,
        self::TYPE_PLAYLIST_UPDATED,
        self::TYPE_COMMENT_REPLY,
        self::TYPE_REVIEW_APPROVED,
        self::TYPE_REVIEW_REJECTED,
        self::TYPE_COMMENT_APPROVED,
        self::TYPE_COMMENT_REJECTED,
    ];

    public const ALLOWED_SUBJECT_TYPES = [
        'episode',
        'song',
        'playlist',
        'review',
        'comment',
    ];

    /**
     * Find single notification by ID.
     */
    public static function find(int $id): ?static
    {
        if ($id <= 0) {
            return null;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_notifications WHERE id = ? LIMIT 1", [$id]);
        return $row ? new static((array)$row) : null;
    }

    /**
     * Create safe notification record with deduplication.
     */
    public static function createSafe(
        int $userId,
        string $type,
        string $subjectType,
        int $subjectId,
        ?int $actorUserId,
        string $dedupeKey,
        string $title,
        string $message
    ): ?static {
        if ($userId <= 0 || !in_array($type, self::ALLOWED_TYPES, true)) {
            return null;
        }

        if (!in_array($subjectType, self::ALLOWED_SUBJECT_TYPES, true) || $subjectId <= 0) {
            return null;
        }

        $title = trim($title);
        $message = trim($message);
        if ($title === '' || $message === '') {
            return null;
        }

        if (mb_strlen($title, 'UTF-8') > 255) {
            $title = mb_substr($title, 0, 255, 'UTF-8');
        }
        if (mb_strlen($message, 'UTF-8') > 500) {
            $message = mb_substr($message, 0, 500, 'UTF-8');
        }

        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            $dedupeKey = "notif_{$userId}_{$type}_{$subjectType}_{$subjectId}_" . time();
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $now = date('Y-m-d H:i:s');

        try {
            $db->insert('multimedia_notifications', [
                'user_id'       => $userId,
                'type'          => $type,
                'subject_type'  => $subjectType,
                'subject_id'    => $subjectId,
                'actor_user_id' => ($actorUserId && $actorUserId > 0) ? $actorUserId : null,
                'dedupe_key'    => $dedupeKey,
                'title'         => $title,
                'message'       => $message,
                'is_read'       => 0,
                'created_at'    => $now,
            ]);

            $newId = (int)$db->getConnection()->lastInsertId();
            return self::find($newId);
        } catch (\Throwable) {
            // Dedupe key collided or insert failed
            return null;
        }
    }

    /**
     * Get paginated notifications for a user.
     */
    public static function getForUser(int $userId, int $limit = 20, int $offset = 0, ?bool $unreadOnly = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($unreadOnly === true) {
            $rows = $db->select(
                "SELECT * FROM multimedia_notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
                [$userId, $limit, $offset]
            );
        } else {
            $rows = $db->select(
                "SELECT * FROM multimedia_notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
                [$userId, $limit, $offset]
            );
        }

        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Count notifications for a user.
     */
    public static function countForUser(int $userId, ?bool $unreadOnly = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        if ($unreadOnly === true) {
            $row = $db->selectOne(
                "SELECT COUNT(*) as c FROM multimedia_notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
        } else {
            $row = $db->selectOne(
                "SELECT COUNT(*) as c FROM multimedia_notifications WHERE user_id = ?",
                [$userId]
            );
        }

        return (int)($row->c ?? 0);
    }

    /**
     * Count unread notifications for a user.
     */
    public static function countUnread(int $userId): int
    {
        return self::countForUser($userId, true);
    }

    /**
     * Mark single notification as read (with user isolation).
     */
    public static function markRead(int $id, int $userId): bool
    {
        if ($id <= 0 || $userId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $affected = $db->update('multimedia_notifications', [
            'is_read' => 1,
        ], [
            'id'      => $id,
            'user_id' => $userId,
        ]);

        return $affected > 0;
    }

    /**
     * Mark all notifications as read for a user.
     */
    public static function markAllRead(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $stmt = $db->query(
            "UPDATE multimedia_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
            [$userId]
        );

        return $stmt->rowCount();
    }

    /**
     * Delete single notification (with user isolation).
     */
    public static function deleteForUser(int $id, int $userId): bool
    {
        if ($id <= 0 || $userId <= 0) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query(
            "DELETE FROM multimedia_notifications WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );

        return true;
    }

    /**
     * Delete notifications associated with deleted subject.
     */
    public static function deleteForSubject(string $subjectType, int $subjectId): void
    {
        if ($subjectId <= 0) {
            return;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->query(
            "DELETE FROM multimedia_notifications WHERE subject_type = ? AND subject_id = ?",
            [$subjectType, $subjectId]
        );
    }
}
