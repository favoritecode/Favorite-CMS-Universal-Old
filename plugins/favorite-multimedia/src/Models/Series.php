<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Series extends BaseModel
{
    protected static string $table = 'multimedia_series';

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
        $row = $db->selectOne("SELECT * FROM multimedia_series WHERE slug = ?", [$slug]);
        return $row ? new static((array)$row) : null;
    }

    public static function published(int $limit = 20, int $offset = 0, ?string $genreSlug = null, ?string $search = null): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "s.status = 'published'";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $where .= " AND (s.title LIKE ? OR s.director LIKE ? OR s.cast LIKE ?)";
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($genreSlug !== null && trim($genreSlug) !== '') {
            $sql = "SELECT s.* FROM multimedia_series s
                    JOIN multimedia_content_genres cg ON s.id = cg.content_id AND cg.content_type = 'series'
                    JOIN multimedia_genres g ON cg.genre_id = g.id
                    WHERE {$where} AND g.slug = ?
                    ORDER BY s.featured DESC, s.id DESC
                    LIMIT {$limit} OFFSET {$offset}";
            $params[] = $genreSlug;
        } else {
            $sql = "SELECT s.* FROM multimedia_series s
                    WHERE {$where}
                    ORDER BY s.featured DESC, s.id DESC
                    LIMIT {$limit} OFFSET {$offset}";
        }

        $rows = $db->select($sql, $params);
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public static function countPublished(?string $genreSlug = null, ?string $search = null): int
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "s.status = 'published'";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $where .= " AND (s.title LIKE ? OR s.director LIKE ? OR s.cast LIKE ?)";
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($genreSlug !== null && trim($genreSlug) !== '') {
            $sql = "SELECT COUNT(DISTINCT s.id) as total FROM multimedia_series s
                    JOIN multimedia_content_genres cg ON s.id = cg.content_id AND cg.content_type = 'series'
                    JOIN multimedia_genres g ON cg.genre_id = g.id
                    WHERE {$where} AND g.slug = ?";
            $params[] = $genreSlug;
        } else {
            $sql = "SELECT COUNT(s.id) as total FROM multimedia_series s WHERE {$where}";
        }

        $row = $db->selectOne($sql, $params);
        return (int)($row->total ?? 0);
    }

    public function getSeasons(): array
    {
        $rows = $this->db->select(
            "SELECT * FROM multimedia_seasons WHERE series_id = ? ORDER BY sort_order ASC, season_number ASC, id ASC",
            [(int)$this->id]
        );
        return array_map(fn($r) => new Season((array)$r), $rows);
    }

    public function getGenres(): array
    {
        return Genre::getForContent('series', (int)$this->id);
    }

    public function incrementViews(): void
    {
        $this->db->query("UPDATE multimedia_series SET views_count = views_count + 1 WHERE id = ?", [(int)$this->id]);
    }

    public static function forUser(int $userId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select("SELECT * FROM multimedia_series WHERE user_id = ? ORDER BY id DESC", [$userId]);
        return array_map(fn($r) => new static((array)$r), $rows);
    }
}

