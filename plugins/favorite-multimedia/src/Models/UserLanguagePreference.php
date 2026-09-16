<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\BaseModel;

class UserLanguagePreference extends BaseModel
{
    protected static string $table = 'multimedia_user_language_preferences';

    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    /**
     * Get preference record for a user.
     */
    public static function getForUser(int $userId): ?self
    {
        if ($userId <= 0) {
            return null;
        }

        $row = self::getDb()->selectOne(
            "SELECT * FROM " . static::$table . " WHERE user_id = ? LIMIT 1",
            [$userId]
        );

        return $row ? new static((array)$row) : null;
    }

    /**
     * Save or update preference record for a user.
     */
    public static function saveForUser(
        int $userId,
        ?string $audioLang = null,
        ?string $subLang = null,
        bool $subEnabled = true
    ): self {
        $db = self::getDb();
        $now = gmdate('Y-m-d H:i:s');
        $audio = $audioLang !== null ? strtolower(trim($audioLang)) : null;
        $sub = $subLang !== null ? strtolower(trim($subLang)) : null;
        $enabled = $subEnabled ? 1 : 0;

        $existing = self::getForUser($userId);

        if ($existing) {
            $id = (int)$existing->id;
            $db->update(static::$table, [
                'preferred_audio_language'    => $audio,
                'preferred_subtitle_language' => $sub,
                'subtitle_enabled'            => $enabled,
                'updated_at'                  => $now,
            ], ['id' => $id]);

            return static::find($id) ?: new static();
        }

        $id = $db->insert(static::$table, [
            'user_id'                     => $userId,
            'preferred_audio_language'    => $audio,
            'preferred_subtitle_language' => $sub,
            'subtitle_enabled'            => $enabled,
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ]);

        return static::find((int)$id) ?: new static();
    }
}
