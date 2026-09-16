<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Movie extends BaseModel
{
    protected static string $table = 'multimedia_movies';

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
        $row = $db->selectOne("SELECT * FROM multimedia_movies WHERE slug = ?", [$slug]);
        return $row ? new static((array)$row) : null;
    }

    public static function published(int $limit = 20, int $offset = 0, ?string $genreSlug = null, ?string $search = null): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "m.status = 'published'";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $where .= " AND (m.title LIKE ? OR m.director LIKE ? OR m.cast LIKE ?)";
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($genreSlug !== null && trim($genreSlug) !== '') {
            $sql = "SELECT m.* FROM multimedia_movies m
                    JOIN multimedia_content_genres cg ON m.id = cg.content_id AND cg.content_type = 'movie'
                    JOIN multimedia_genres g ON cg.genre_id = g.id
                    WHERE {$where} AND g.slug = ?
                    ORDER BY m.featured DESC, m.release_date DESC, m.id DESC
                    LIMIT {$limit} OFFSET {$offset}";
            $params[] = $genreSlug;
        } else {
            $sql = "SELECT m.* FROM multimedia_movies m
                    WHERE {$where}
                    ORDER BY m.featured DESC, m.release_date DESC, m.id DESC
                    LIMIT {$limit} OFFSET {$offset}";
        }

        $rows = $db->select($sql, $params);
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public static function countPublished(?string $genreSlug = null, ?string $search = null): int
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "m.status = 'published'";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $where .= " AND (m.title LIKE ? OR m.director LIKE ? OR m.cast LIKE ?)";
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($genreSlug !== null && trim($genreSlug) !== '') {
            $sql = "SELECT COUNT(DISTINCT m.id) as total FROM multimedia_movies m
                    JOIN multimedia_content_genres cg ON m.id = cg.content_id AND cg.content_type = 'movie'
                    JOIN multimedia_genres g ON cg.genre_id = g.id
                    WHERE {$where} AND g.slug = ?";
            $params[] = $genreSlug;
        } else {
            $sql = "SELECT COUNT(m.id) as total FROM multimedia_movies m WHERE {$where}";
        }

        $row = $db->selectOne($sql, $params);
        return (int)($row->total ?? 0);
    }

    public function getSources(bool $activeOnly = true): array
    {
        return MediaSource::getForContent('movie', (int)$this->id, $activeOnly);
    }

    public function getSubtitles(): array
    {
        return Subtitle::getForContent('movie', (int)$this->id);
    }

    public function getGenres(): array
    {
        return Genre::getForContent('movie', (int)$this->id);
    }

    public function incrementViews(): void
    {
        $this->db->query("UPDATE multimedia_movies SET views_count = views_count + 1 WHERE id = ?", [(int)$this->id]);
    }

    public function getDurationFormatted(): string
    {
        $total = (int)($this->duration ?? 0);
        if ($total <= 0) {
            return '';
        }
        $hours = floor($total / 3600);
        $minutes = floor(($total % 3600) / 60);
        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }
        return sprintf('%d min', $minutes);
    }

    public function getDownloadUrl(): ?string
    {
        $url = trim((string)($this->download_url ?? ''));
        return ($url !== '') ? $url : null;
    }

    public static function forUser(int $userId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select("SELECT * FROM multimedia_movies WHERE user_id = ? ORDER BY id DESC", [$userId]);
        return array_map(fn($r) => new static((array)$r), $rows);
    }
}

