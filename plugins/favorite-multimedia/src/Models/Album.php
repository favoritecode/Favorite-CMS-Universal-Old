<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Album extends BaseModel
{
    protected static string $table = 'multimedia_albums';

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_albums WHERE slug = ?", [$slug]);
        return $row ? new static((array)$row) : null;
    }

    public static function published(int $limit = 20, int $offset = 0): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select("SELECT * FROM multimedia_albums WHERE status = 'published' ORDER BY id DESC LIMIT ? OFFSET ?", [$limit, $offset]);
        return array_map(fn($row) => new static((array)$row), $rows);
    }

    public function getArtist(): ?Artist
    {
        if (empty($this->artist_id)) {
            return null;
        }
        return Artist::find((int)$this->artist_id);
    }

    public function getSongs(): array
    {
        $rows = $this->db->select(
            "SELECT * FROM multimedia_songs WHERE album_id = ? AND status = 'published' ORDER BY id ASC",
            [$this->id]
        );
        return array_map(fn($r) => new Song((array)$r), $rows);
    }

    public static function forUser(int $userId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select("SELECT * FROM multimedia_albums WHERE user_id = ? ORDER BY id DESC", [$userId]);
        return array_map(fn($r) => new static((array)$r), $rows);
    }
}

