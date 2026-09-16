<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Episode extends BaseModel
{
    protected static string $table = 'multimedia_episodes';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_UNPUBLISHED = 'unpublished';

    public function isPublished(): bool
    {
        return ($this->status ?? '') === self::STATUS_PUBLISHED;
    }

    public function isScheduled(): bool
    {
        return ($this->status ?? '') === self::STATUS_SCHEDULED;
    }

    public function isDraft(): bool
    {
        return ($this->status ?? '') === self::STATUS_DRAFT;
    }

    public function isUnpublished(): bool
    {
        return ($this->status ?? '') === self::STATUS_UNPUBLISHED;
    }

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_episodes WHERE slug = ?", [$slug]);
        return $row ? new static((array)$row) : null;
    }

    public function getSeason(): ?Season
    {
        return Season::find((int)$this->season_id);
    }

    public function getSeries(): ?Series
    {
        return Series::find((int)$this->series_id);
    }

    public function getSources(bool $activeOnly = true): array
    {
        return MediaSource::getForContent('episode', (int)$this->id, $activeOnly);
    }

    public function getSubtitles(): array
    {
        return Subtitle::getForContent('episode', (int)$this->id);
    }

    public function getResolvedAccessMode(): string
    {
        $mode = $this->access_mode ?? 'inherit';
        if ($mode !== 'inherit' && in_array($mode, ['public', 'login', 'premium'], true)) {
            return $mode;
        }

        $series = $this->getSeries();
        return $series ? ($series->access_mode ?? 'public') : 'public';
    }

    public function getResolvedDownloadPolicy(): string
    {
        $policy = $this->download_policy ?? 'inherit';
        if ($policy !== 'inherit' && in_array($policy, ['allow', 'deny'], true)) {
            return $policy;
        }

        $series = $this->getSeries();
        return $series ? ($series->download_policy ?? 'inherit') : 'inherit';
    }

    public function incrementViews(): void
    {
        $this->db->query("UPDATE multimedia_episodes SET views_count = views_count + 1 WHERE id = ?", [(int)$this->id]);
    }

    public function getDownloadUrl(): ?string
    {
        $url = trim((string)($this->download_url ?? ''));
        return ($url !== '') ? $url : null;
    }

    public static function forUser(int $userId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select("SELECT * FROM multimedia_episodes WHERE user_id = ? ORDER BY id DESC", [$userId]);
        return array_map(fn($r) => new static((array)$r), $rows);
    }
}

