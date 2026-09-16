<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Playlist extends BaseModel
{
    protected static string $table = 'multimedia_playlists';

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_playlists WHERE slug = ?", [$slug]);
        return $row ? new static((array)$row) : null;
    }

    public static function published(int $limit = 20, int $offset = 0): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $sql = "SELECT * FROM multimedia_playlists WHERE status = 'published' AND (access_mode IS NULL OR access_mode != 'private') ORDER BY featured DESC, id DESC LIMIT {$limit} OFFSET {$offset}";
        $rows = $db->select($sql);
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public function getSongs(): array
    {
        $sql = "SELECT s.*, pi.sort_order as playlist_sort_order 
                FROM multimedia_songs s 
                JOIN multimedia_playlist_items pi ON s.id = pi.song_id 
                WHERE pi.playlist_id = ? 
                ORDER BY pi.sort_order ASC, pi.id ASC";
        $rows = $this->db->select($sql, [(int)$this->id]);
        return array_map(fn($r) => new Song((array)$r), $rows);
    }

    public function addSong(int $songId, ?int $sortOrder = null): bool
    {
        // Check if already in playlist
        $existing = $this->db->selectOne(
            "SELECT id FROM multimedia_playlist_items WHERE playlist_id = ? AND song_id = ?",
            [(int)$this->id, $songId]
        );
        if ($existing) {
            return false;
        }

        if ($sortOrder === null) {
            $maxRow = $this->db->selectOne(
                "SELECT MAX(sort_order) as max_sort FROM multimedia_playlist_items WHERE playlist_id = ?",
                [(int)$this->id]
            );
            $sortOrder = ($maxRow && $maxRow->max_sort !== null) ? (int)$maxRow->max_sort + 1 : 0;
        }

        return $this->db->insert('multimedia_playlist_items', [
            'playlist_id' => (int)$this->id,
            'song_id'     => $songId,
            'sort_order'  => $sortOrder,
        ]) > 0;
    }

    public function removeSong(int $songId): bool
    {
        return $this->db->delete('multimedia_playlist_items', [
            'playlist_id' => (int)$this->id,
            'song_id'     => $songId,
        ]) > 0;
    }

    public function reorderSongs(array $songIds): void
    {
        foreach ($songIds as $order => $songId) {
            $this->db->update('multimedia_playlist_items', [
                'sort_order' => (int)$order,
            ], [
                'playlist_id' => (int)$this->id,
                'song_id'     => (int)$songId,
            ]);
        }
    }

    public static function forUser(int $userId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select("SELECT * FROM multimedia_playlists WHERE user_id = ? ORDER BY id DESC", [$userId]);
        return array_map(fn($r) => new static((array)$r), $rows);
    }
}

