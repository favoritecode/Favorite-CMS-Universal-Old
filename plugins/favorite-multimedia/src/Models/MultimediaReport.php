<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class MultimediaReport extends BaseModel
{
    protected static string $table = 'multimedia_reports';

    public const TARGET_REVIEW  = 'review';
    public const TARGET_COMMENT = 'comment';
    public const ALLOWED_TARGETS = [self::TARGET_REVIEW, self::TARGET_COMMENT];

    public const REASON_SPAM       = 'spam';
    public const REASON_HARASSMENT = 'harassment';
    public const REASON_OFF_TOPIC  = 'off_topic';
    public const REASON_OTHER      = 'other';
    public const ALLOWED_REASONS   = [
        self::REASON_SPAM,
        self::REASON_HARASSMENT,
        self::REASON_OFF_TOPIC,
        self::REASON_OTHER,
    ];

    public const STATUS_OPEN      = 'open';
    public const STATUS_REVIEWED  = 'reviewed';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_ACTIONED  = 'actioned';

    /**
     * Find single report by ID.
     */
    public static function find(int $id): ?static
    {
        if ($id <= 0) {
            return null;
        }
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_reports WHERE id = ? LIMIT 1", [$id]);
        return $row ? new static((array)$row) : null;
    }

    /**
     * File a report against a review or comment.
     */
    public static function fileReport(int $reporterUserId, string $targetType, int $targetId, string $reason, ?string $notes = null): array
    {
        if ($reporterUserId <= 0) {
            return ['success' => false, 'error' => 'Authentication required to report content.'];
        }

        if (!in_array($targetType, self::ALLOWED_TARGETS, true)) {
            return ['success' => false, 'error' => 'Invalid report target.'];
        }

        if (!in_array($reason, self::ALLOWED_REASONS, true)) {
            return ['success' => false, 'error' => 'Invalid report reason.'];
        }

        if ($targetId <= 0) {
            return ['success' => false, 'error' => 'Invalid target ID.'];
        }

        $notes = $notes !== null ? trim($notes) : null;
        if ($notes !== null && mb_strlen($notes, 'UTF-8') > 255) {
            $notes = mb_substr($notes, 0, 255, 'UTF-8');
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);

        // Check if report already filed by this user for this target
        $existing = $db->selectOne(
            "SELECT id FROM multimedia_reports WHERE reporter_user_id = ? AND target_type = ? AND target_id = ?",
            [$reporterUserId, $targetType, $targetId]
        );

        if ($existing) {
            return ['success' => false, 'duplicate' => true, 'error' => 'You have already reported this item.'];
        }

        $now = date('Y-m-d H:i:s');
        try {
            $db->insert('multimedia_reports', [
                'reporter_user_id' => $reporterUserId,
                'target_type'      => $targetType,
                'target_id'        => $targetId,
                'reason'           => $reason,
                'notes'            => $notes,
                'status'           => self::STATUS_OPEN,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $reportId = (int)$db->getConnection()->lastInsertId();
            return ['success' => true, 'duplicate' => false, 'report_id' => $reportId];
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Failed to save report.'];
        }
    }

    /**
     * Resolve report.
     */
    public static function resolveReport(int $id, string $status): bool
    {
        $allowed = [self::STATUS_REVIEWED, self::STATUS_DISMISSED, self::STATUS_ACTIONED];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->update('multimedia_reports', [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        return true;
    }

    /**
     * Count open reports.
     */
    public static function countOpen(): int
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT COUNT(*) as c FROM multimedia_reports WHERE status = 'open'");
        return (int)($row->c ?? 0);
    }
}
