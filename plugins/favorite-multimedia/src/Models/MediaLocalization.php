<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\BaseModel;

class MediaLocalization extends BaseModel
{
    protected static string $table = 'multimedia_localizations';

    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    /**
     * Get all localizations for a specific content entity.
     *
     * @return static[]
     */
    public static function getForContent(string $contentType, int $contentId): array
    {
        try {
            $rows = self::getDb()->select(
                "SELECT * FROM " . static::$table . " WHERE content_type = ? AND content_id = ? ORDER BY language_code ASC",
                [$contentType, $contentId]
            );

            return array_map(fn($r) => new static((array)$r), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Find specific localization for content entity by language code.
     */
    public static function findForContent(string $contentType, int $contentId, string $languageCode): ?self
    {
        try {
            $lang = strtolower(trim($languageCode));
            $row = self::getDb()->selectOne(
                "SELECT * FROM " . static::$table . " WHERE content_type = ? AND content_id = ? AND LOWER(language_code) = ? LIMIT 1",
                [$contentType, $contentId, $lang]
            );

            return $row ? new static((array)$row) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Create or update localization entry (enforces uniqueness on content_type + content_id + language_code).
     */
    public static function saveLocalization(
        string $contentType,
        int $contentId,
        string $languageCode,
        string $title,
        ?string $description = null,
        ?string $tagline = null
    ): self {
        $db = self::getDb();
        $lang = strtolower(trim($languageCode));
        $now = gmdate('Y-m-d H:i:s');

        $existing = self::findForContent($contentType, $contentId, $lang);

        if ($existing) {
            $id = (int)$existing->id;
            $db->update(static::$table, [
                'title'       => $title,
                'description' => $description,
                'tagline'     => $tagline,
                'updated_at'  => $now,
            ], ['id' => $id]);

            return static::find($id) ?: new static();
        }

        $id = $db->insert(static::$table, [
            'content_type'  => $contentType,
            'content_id'    => $contentId,
            'language_code' => $lang,
            'title'         => $title,
            'description'   => $description,
            'tagline'       => $tagline,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return static::find((int)$id) ?: new static();
    }

    /**
     * Delete localization entry.
     */
    public static function deleteLocalization(string $contentType, int $contentId, string $languageCode): bool
    {
        $lang = strtolower(trim($languageCode));
        $existing = self::findForContent($contentType, $contentId, $lang);
        if ($existing) {
            self::getDb()->delete(static::$table, ['id' => (int)$existing->id]);
            return true;
        }
        return false;
    }

    /**
     * Cascade deletion: delete all localizations for a content item.
     */
    public static function deleteForContent(string $contentType, int $contentId): int
    {
        try {
            $items = self::getForContent($contentType, $contentId);
            $count = 0;
            foreach ($items as $item) {
                self::getDb()->delete(static::$table, ['id' => (int)$item->id]);
                $count++;
            }
            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }
}
