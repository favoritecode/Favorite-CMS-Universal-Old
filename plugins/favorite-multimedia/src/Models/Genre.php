<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Genre extends BaseModel
{
    protected static string $table = 'multimedia_genres';

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_genres WHERE slug = ?", [$slug]);
        return $row ? new static((array)$row) : null;
    }

    public static function getForContent(string $contentType, int $contentId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $sql = "SELECT g.* FROM multimedia_genres g 
                JOIN multimedia_content_genres cg ON g.id = cg.genre_id 
                WHERE cg.content_type = ? AND cg.content_id = ? 
                ORDER BY g.name ASC";
        $rows = $db->select($sql, [$contentType, $contentId]);
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public static function syncForContent(string $contentType, int $contentId, array $genreIds): void
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->delete('multimedia_content_genres', [
            'content_type' => $contentType,
            'content_id'   => $contentId,
        ]);

        foreach ($genreIds as $genreId) {
            $genreId = (int)$genreId;
            if ($genreId > 0) {
                $db->insert('multimedia_content_genres', [
                    'content_type' => $contentType,
                    'content_id'   => $contentId,
                    'genre_id'     => $genreId,
                ]);
            }
        }
    }
}

