<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Subtitle extends BaseModel
{
    protected static string $table = 'multimedia_subtitles';

    public static function getForContent(string $contentType, int $contentId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $sql = "SELECT * FROM multimedia_subtitles WHERE content_type = ? AND content_id = ? ORDER BY is_default DESC, sort_order ASC, id ASC";
        $rows = $db->select($sql, [$contentType, $contentId]);
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public function getContentTitle(): string
    {
        $type = (string)($this->content_type ?? '');
        $id = (int)($this->content_id ?? 0);
        return match ($type) {
            'movie'   => Movie::find($id)?->title ?? "#{$id}",
            'episode' => ($ep = Episode::find($id)) ? (($s = $ep->getSeries()) ? "{$s->title} - {$ep->title}" : $ep->title) : "#{$id}",
            default   => "#{$id}",
        };
    }

    public function getLanguageCode(): string
    {
        return strtolower(trim((string)($this->language_code ?? $this->language ?? 'en')));
    }

    public function isForced(): bool
    {
        return !empty($this->is_forced);
    }

    public function isSdh(): bool
    {
        return !empty($this->is_sdh);
    }

    /**
     * Ensure only one subtitle track is marked default for a given content item.
     */
    public static function enforceSingleDefault(string $contentType, int $contentId, int $defaultSubtitleId): void
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->execute(
            "UPDATE multimedia_subtitles SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE content_type = ? AND content_id = ?",
            [$defaultSubtitleId, $contentType, $contentId]
        );
    }

    /**
     * Find subtitle track by content item and language code.
     */
    public static function findByContentAndLanguage(string $contentType, int $contentId, string $languageCode): ?self
    {
        $lang = strtolower(trim($languageCode));
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT * FROM multimedia_subtitles WHERE content_type = ? AND content_id = ? AND (LOWER(language_code) = ? OR LOWER(language) = ?) ORDER BY is_default DESC, id ASC LIMIT 1",
            [$contentType, $contentId, $lang, $lang]
        );

        return $row ? new static((array)$row) : null;
    }
}

