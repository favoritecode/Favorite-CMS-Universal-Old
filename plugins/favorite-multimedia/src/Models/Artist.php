<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Artist extends BaseModel
{
    protected static string $table = 'multimedia_artists';

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_artists WHERE slug = ?", [$slug]);
        return $row ? new static((array)$row) : null;
    }

    public static function published(int $limit = 20, int $offset = 0): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select("SELECT * FROM multimedia_artists WHERE status = 'active' ORDER BY id DESC LIMIT ? OFFSET ?", [$limit, $offset]);
        return array_map(fn($row) => new static((array)$row), $rows);
    }

    public function getSongs(): array
    {
        $rows = $this->db->select(
            "SELECT * FROM multimedia_songs WHERE artist_id = ? AND status = 'published' ORDER BY release_date DESC",
            [$this->id]
        );
        return array_map(fn($r) => new Song((array)$r), $rows);
    }

    public function getAlbums(): array
    {
        $rows = $this->db->select(
            "SELECT * FROM multimedia_albums WHERE artist_id = ? ORDER BY release_date DESC",
            [$this->id]
        );
        return array_map(fn($r) => new Album((array)$r), $rows);
    }
}

