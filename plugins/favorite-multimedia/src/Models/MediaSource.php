<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class MediaSource extends BaseModel
{
    protected static string $table = 'multimedia_sources';

    public static function getForContent(string $contentType, int $contentId, bool $activeOnly = false, ?string $mediaKind = null): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "content_type = ? AND content_id = ?";
        $params = [$contentType, $contentId];

        if ($activeOnly) {
            $where .= " AND status = 'active'";
        }

        if ($mediaKind !== null) {
            if ($mediaKind === 'audio') {
                $where .= " AND (media_kind = 'audio' OR source_type = 'audio')";
            } elseif ($mediaKind === 'video') {
                $where .= " AND (media_kind = 'video' OR media_kind = '' OR media_kind IS NULL) AND source_type != 'audio'";
            }
        }

        $sql = "SELECT * FROM multimedia_sources WHERE {$where} ORDER BY is_default DESC, sort_order ASC, id ASC";
        $rows = $db->select($sql, $params);
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public static function getDefault(string $contentType, int $contentId, bool $activeOnly = true, ?string $mediaKind = null): ?self
    {
        try {
            $sources = static::getForContent($contentType, $contentId, $activeOnly, $mediaKind);
            if (!empty($sources)) {
                return $sources[0];
            }
            if ($activeOnly) {
                $all = static::getForContent($contentType, $contentId, false, $mediaKind);
                return $all[0] ?? null;
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    public function isDownloadAllowed(string $globalSetting = 'yes', string $parentPolicy = 'inherit'): bool
    {
        // 1. Content/Source level override
        $policy = $this->allow_download ?? 'inherit';
        if ($policy === 'deny') {
            return false;
        }
        if ($policy === 'allow') {
            return true;
        }

        // 2. Parent policy check
        if ($parentPolicy === 'deny') {
            return false;
        }
        if ($parentPolicy === 'allow') {
            return true;
        }

        // 3. Global setting fallback
        return $globalSetting === 'yes' || $globalSetting === '1' || $globalSetting === 'true';
    }

    public function getContentTitle(): string
    {
        $type = (string)($this->content_type ?? '');
        $id = (int)($this->content_id ?? 0);
        return match ($type) {
            'movie'   => Movie::find($id)?->title ?? "#{$id}",
            'episode' => ($ep = Episode::find($id)) ? (($s = $ep->getSeries()) ? "{$s->title} - {$ep->title}" : $ep->title) : "#{$id}",
            'song'    => Song::find($id)?->title ?? "#{$id}",
            default   => "#{$id}",
        };
    }

    public function getLanguageCode(): string
    {
        return strtolower(trim((string)($this->language_code ?? 'en')));
    }

    public function getAudioRole(): string
    {
        return (string)($this->audio_role ?? 'main');
    }

    public function getSourceType(): string
    {
        return strtolower(trim((string)($this->source_type ?? 'video')));
    }

    public function getSourceLabel(): string
    {
        $label = trim((string)($this->label ?? ''));
        return $label !== '' ? $label : 'Main Stream';
    }

    public function getUrlOrPath(): string
    {
        return trim((string)($this->url_or_path ?? ''));
    }

    public function getStatus(): string
    {
        return strtolower(trim((string)($this->status ?? 'active')));
    }

    /**
     * Determine whether this source is ready/playable for viewers.
     * Must have a non-empty URL/path and status === 'active'.
     */
    public function isPlayable(): bool
    {
        return $this->getStatus() === 'active' && $this->getUrlOrPath() !== '';
    }

    /**
     * Determine whether this source represents configured media (even if inactive/disabled).
     */
    public function isConfigured(): bool
    {
        return $this->getUrlOrPath() !== '';
    }

    /**
     * Get only playable (active + valid) media sources for a content item.
     *
     * @return static[]
     */
    public static function getPlayableForContent(string $contentType, int $contentId, ?string $mediaKind = null): array
    {
        $all = static::getForContent($contentType, $contentId, true, $mediaKind);
        return array_values(array_filter($all, fn(self $s) => $s->isPlayable()));
    }

    /**
     * Get all structured audio tracks for a content item.
     *
     * @return static[]
     */
    public static function getAudioTracksForContent(string $contentType, int $contentId, bool $activeOnly = true): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "content_type = ? AND content_id = ? AND source_type = 'audio'";
        $params = [$contentType, $contentId];

        if ($activeOnly) {
            $where .= " AND status = 'active'";
        }

        $sql = "SELECT * FROM multimedia_sources WHERE {$where} ORDER BY is_default DESC, sort_order ASC, id ASC";
        $rows = $db->select($sql, $params);
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Ensure only one audio source is marked default for a given content item.
     */
    public static function enforceSingleDefaultAudio(string $contentType, int $contentId, int $defaultSourceId): void
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->execute(
            "UPDATE multimedia_sources SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE content_type = ? AND content_id = ? AND source_type = 'audio'",
            [$defaultSourceId, $contentType, $contentId]
        );
    }

    /**
     * Find audio track by content item and language code.
     */
    public static function findAudioByContentAndLanguage(string $contentType, int $contentId, string $languageCode): ?self
    {
        $lang = strtolower(trim($languageCode));
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne(
            "SELECT * FROM multimedia_sources WHERE content_type = ? AND content_id = ? AND source_type = 'audio' AND LOWER(language_code) = ? AND status = 'active' ORDER BY is_default DESC, id ASC LIMIT 1",
            [$contentType, $contentId, $lang]
        );

        return $row ? new static((array)$row) : null;
    }

    public function getMediaKind(): string
    {
        $kind = strtolower(trim((string)($this->media_kind ?? '')));
        if ($kind === 'audio' || $kind === 'video') {
            return $kind;
        }
        return ($this->getSourceType() === 'audio') ? 'audio' : 'video';
    }

    /**
     * Ensure only one source is marked default for a given content item (optionally scoped by media_kind).
     */
    public static function enforceSingleDefault(string $contentType, int $contentId, int $defaultSourceId, ?string $mediaKind = null): void
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        if ($mediaKind === 'audio') {
            $db->execute(
                "UPDATE multimedia_sources SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE content_type = ? AND content_id = ? AND (media_kind = 'audio' OR source_type = 'audio')",
                [$defaultSourceId, $contentType, $contentId]
            );
        } elseif ($mediaKind === 'video') {
            $db->execute(
                "UPDATE multimedia_sources SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE content_type = ? AND content_id = ? AND (media_kind = 'video' OR media_kind = '' OR media_kind IS NULL) AND source_type != 'audio'",
                [$defaultSourceId, $contentType, $contentId]
            );
        } else {
            $db->execute(
                "UPDATE multimedia_sources SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE content_type = ? AND content_id = ?",
                [$defaultSourceId, $contentType, $contentId]
            );
        }
    }

    /**
     * If content item has no default source, promote the first active source as default (optionally scoped by media_kind).
     */
    public static function promoteNextDefault(string $contentType, int $contentId, ?string $mediaKind = null): void
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $kindWhere = "";
        if ($mediaKind === 'audio') {
            $kindWhere = " AND (media_kind = 'audio' OR source_type = 'audio')";
        } elseif ($mediaKind === 'video') {
            $kindWhere = " AND (media_kind = 'video' OR media_kind = '' OR media_kind IS NULL) AND source_type != 'audio'";
        }

        $hasDefault = $db->selectOne(
            "SELECT id FROM multimedia_sources WHERE content_type = ? AND content_id = ? AND is_default = 1{$kindWhere} LIMIT 1",
            [$contentType, $contentId]
        );
        if (!$hasDefault) {
            $next = $db->selectOne(
                "SELECT id FROM multimedia_sources WHERE content_type = ? AND content_id = ? AND status = 'active'{$kindWhere} ORDER BY sort_order ASC, id ASC LIMIT 1",
                [$contentType, $contentId]
            );
            if ($next) {
                $db->execute("UPDATE multimedia_sources SET is_default = 1 WHERE id = ?", [(int)$next->id]);
            }
        }
    }
}

