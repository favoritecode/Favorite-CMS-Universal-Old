<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class MultimediaNotificationPreference extends BaseModel
{
    protected static string $table = 'multimedia_notification_preferences';

    public static function getForUser(int $userId): array
    {
        $defaults = [
            'notify_content_updates'    => true,
            'notify_engagement_replies' => true,
            'notify_moderation_updates' => true,
        ];

        if ($userId <= 0) {
            return $defaults;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT * FROM multimedia_notification_preferences WHERE user_id = ? LIMIT 1",
            [$userId]
        );

        if (!$row) {
            return $defaults;
        }

        return [
            'notify_content_updates'    => (bool)$row->notify_content_updates,
            'notify_engagement_replies' => (bool)$row->notify_engagement_replies,
            'notify_moderation_updates' => (bool)$row->notify_moderation_updates,
        ];
    }

    public static function saveForUser(int $userId, array $prefs): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $contentUpdates = isset($prefs['notify_content_updates']) ? ($prefs['notify_content_updates'] ? 1 : 0) : 1;
        $engagementReplies = isset($prefs['notify_engagement_replies']) ? ($prefs['notify_engagement_replies'] ? 1 : 0) : 1;
        $moderationUpdates = isset($prefs['notify_moderation_updates']) ? ($prefs['notify_moderation_updates'] ? 1 : 0) : 1;

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $now = date('Y-m-d H:i:s');

        $existing = $db->selectOne(
            "SELECT id FROM multimedia_notification_preferences WHERE user_id = ? LIMIT 1",
            [$userId]
        );

        if ($existing) {
            $db->update('multimedia_notification_preferences', [
                'notify_content_updates'    => $contentUpdates,
                'notify_engagement_replies' => $engagementReplies,
                'notify_moderation_updates' => $moderationUpdates,
                'updated_at'                => $now,
            ], ['id' => (int)$existing->id]);
            return true;
        }

        try {
            $db->insert('multimedia_notification_preferences', [
                'user_id'                   => $userId,
                'notify_content_updates'    => $contentUpdates,
                'notify_engagement_replies' => $engagementReplies,
                'notify_moderation_updates' => $moderationUpdates,
                'updated_at'                => $now,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function savePreferences(int $userId, array $prefs): bool
    {
        return self::saveForUser($userId, $prefs);
    }

    public static function allowsContentUpdates(int $userId): bool
    {
        $prefs = self::getForUser($userId);
        return (bool)($prefs['notify_content_updates'] ?? true);
    }

    public static function allowsEngagementReplies(int $userId): bool
    {
        $prefs = self::getForUser($userId);
        return (bool)($prefs['notify_engagement_replies'] ?? true);
    }

    public static function allowsModerationUpdates(int $userId): bool
    {
        $prefs = self::getForUser($userId);
        return (bool)($prefs['notify_moderation_updates'] ?? true);
    }
}
