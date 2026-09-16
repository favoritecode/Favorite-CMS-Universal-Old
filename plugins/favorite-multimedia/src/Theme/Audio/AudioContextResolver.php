<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Audio;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Song;

/**
 * Resolves collections of published audio tracks for Album, Playlist, Artist, and Discovery contexts.
 * Guarantees zero protected raw stream URL leakage into queue payloads.
 */
final class AudioContextResolver
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Container::getInstance()->get(Database::class);
    }

    /**
     * Resolves an album tracklist into queue items.
     * If startSongId is given, orders or indexes from that song onwards.
     *
     * @return array{context: 'album', context_id: int, title: string, items: array<int, array<string, mixed>>, start_index: int}
     */
    public function resolveAlbum(int $albumId, ?int $startSongId = null): array
    {
        $album = Album::find($albumId);
        if (!$album || ($album->status ?? 'published') !== 'published') {
            return [
                'context'     => AudioPlayerState::CONTEXT_ALBUM,
                'context_id'  => $albumId,
                'title'       => 'Album',
                'items'       => [],
                'start_index' => 0,
            ];
        }

        $artist = $album->getArtist();
        $artistName = $artist?->name ?? 'Unknown Artist';

        $sql = "SELECT s.*, a.name as artist_name 
                FROM multimedia_songs s 
                LEFT JOIN multimedia_artists a ON s.artist_id = a.id 
                WHERE s.album_id = ? AND s.status = 'published' 
                ORDER BY s.id ASC";
        $rows = $this->db->select($sql, [$albumId]);

        $items = [];
        $startIndex = 0;

        foreach ($rows as $idx => $row) {
            $r = (array)$row;
            if (!$this->isSongPlayable($r)) {
                continue;
            }

            $songId = (int)$r['id'];
            if ($startSongId !== null && $songId === $startSongId) {
                $startIndex = count($items);
            }

            $items[] = [
                'id'           => $songId,
                'content_type' => 'song',
                'title'        => (string)$r['title'],
                'artist'       => (string)($r['artist_name'] ?? $artistName),
                'cover'        => (string)($r['cover'] ?? $album->cover ?? ''),
                'duration'     => (int)($r['duration'] ?? 0),
                'access_mode'  => (string)($r['access_mode'] ?? 'public'),
                'album_id'     => $albumId,
                'artist_id'    => isset($r['artist_id']) ? (int)$r['artist_id'] : null,
                'sort_order'   => (int)($r['sort_order'] ?? $idx),
            ];
        }

        return [
            'context'     => AudioPlayerState::CONTEXT_ALBUM,
            'context_id'  => $albumId,
            'title'       => (string)$album->title,
            'items'       => $items,
            'start_index' => $startIndex,
        ];
    }

    /**
     * Resolves an audio playlist into queue items.
     *
     * @return array{context: 'playlist', context_id: int, title: string, items: array<int, array<string, mixed>>, start_index: int}
     */
    public function resolvePlaylist(int $playlistId, ?int $startSongId = null): array
    {
        $playlist = Playlist::find($playlistId);
        if (!$playlist || ($playlist->status ?? 'published') !== 'published') {
            return [
                'context'     => AudioPlayerState::CONTEXT_PLAYLIST,
                'context_id'  => $playlistId,
                'title'       => 'Playlist',
                'items'       => [],
                'start_index' => 0,
            ];
        }

        $sql = "SELECT s.*, a.name as artist_name, pi.sort_order as playlist_order
                FROM multimedia_playlist_items pi
                JOIN multimedia_songs s ON pi.song_id = s.id
                LEFT JOIN multimedia_artists a ON s.artist_id = a.id
                WHERE pi.playlist_id = ? AND s.status = 'published'
                ORDER BY pi.sort_order ASC, pi.id ASC";
        $rows = $this->db->select($sql, [$playlistId]);

        $items = [];
        $startIndex = 0;

        foreach ($rows as $idx => $row) {
            $r = (array)$row;
            if (!$this->isSongPlayable($r)) {
                continue;
            }

            $songId = (int)$r['id'];
            if ($startSongId !== null && $songId === $startSongId) {
                $startIndex = count($items);
            }

            $items[] = [
                'id'           => $songId,
                'content_type' => 'song',
                'title'        => (string)$r['title'],
                'artist'       => (string)($r['artist_name'] ?? 'Unknown Artist'),
                'cover'        => (string)($r['cover'] ?? $playlist->cover ?? ''),
                'duration'     => (int)($r['duration'] ?? 0),
                'access_mode'  => (string)($r['access_mode'] ?? 'public'),
                'album_id'     => isset($r['album_id']) ? (int)$r['album_id'] : null,
                'artist_id'    => isset($r['artist_id']) ? (int)$r['artist_id'] : null,
                'sort_order'   => (int)($r['playlist_order'] ?? $idx),
            ];
        }

        return [
            'context'     => AudioPlayerState::CONTEXT_PLAYLIST,
            'context_id'  => $playlistId,
            'title'       => (string)$playlist->title,
            'items'       => $items,
            'start_index' => $startIndex,
        ];
    }

    /**
     * Resolves artist's published songs for Artist Play All.
     *
     * @return array{context: 'artist', context_id: int, title: string, items: array<int, array<string, mixed>>, start_index: int}
     */
    public function resolveArtist(int $artistId, int $limit = 50, ?int $startSongId = null): array
    {
        $artist = Artist::find($artistId);
        if (!$artist) {
            return [
                'context'     => AudioPlayerState::CONTEXT_ARTIST,
                'context_id'  => $artistId,
                'title'       => 'Artist',
                'items'       => [],
                'start_index' => 0,
            ];
        }

        $sql = "SELECT s.*, ? as artist_name 
                FROM multimedia_songs s 
                WHERE s.artist_id = ? AND s.status = 'published' 
                ORDER BY s.plays_count DESC, s.id DESC LIMIT ?";
        $rows = $this->db->select($sql, [$artist->name, $artistId, $limit]);

        $items = [];
        $startIndex = 0;

        foreach ($rows as $idx => $row) {
            $r = (array)$row;
            if (!$this->isSongPlayable($r)) {
                continue;
            }

            $songId = (int)$r['id'];
            if ($startSongId !== null && $songId === $startSongId) {
                $startIndex = count($items);
            }

            $items[] = [
                'id'           => $songId,
                'content_type' => 'song',
                'title'        => (string)$r['title'],
                'artist'       => (string)$artist->name,
                'cover'        => (string)($r['cover'] ?? $artist->photo ?? ''),
                'duration'     => (int)($r['duration'] ?? 0),
                'access_mode'  => (string)($r['access_mode'] ?? 'public'),
                'album_id'     => isset($r['album_id']) ? (int)$r['album_id'] : null,
                'artist_id'    => $artistId,
                'sort_order'   => $idx,
            ];
        }

        return [
            'context'     => AudioPlayerState::CONTEXT_ARTIST,
            'context_id'  => $artistId,
            'title'       => (string)$artist->name,
            'items'       => $items,
            'start_index' => $startIndex,
        ];
    }

    /**
     * Resolves single song into a 1-item queue struct.
     *
     * @return array<string, mixed>|null
     */
    public function resolveSong(int $songId): ?array
    {
        $sql = "SELECT s.*, a.name as artist_name 
                FROM multimedia_songs s 
                LEFT JOIN multimedia_artists a ON s.artist_id = a.id 
                WHERE s.id = ? AND s.status = 'published'";
        $row = $this->db->selectOne($sql, [$songId]);
        if (!$row) {
            return null;
        }

        $r = (array)$row;
        if (!$this->isSongPlayable($r)) {
            return null;
        }

        return [
            'id'           => (int)$r['id'],
            'content_type' => 'song',
            'title'        => (string)$r['title'],
            'artist'       => (string)($r['artist_name'] ?? 'Unknown Artist'),
            'cover'        => (string)($r['cover'] ?? ''),
            'duration'     => (int)($r['duration'] ?? 0),
            'access_mode'  => (string)($r['access_mode'] ?? 'public'),
            'album_id'     => isset($r['album_id']) ? (int)$r['album_id'] : null,
            'artist_id'    => isset($r['artist_id']) ? (int)$r['artist_id'] : null,
            'sort_order'   => 0,
        ];
    }

    /**
     * Validates that song is published, active, not scheduled in the future, and playable.
     *
     * @param array<string, mixed> $songRow
     */
    private function isSongPlayable(array $songRow): bool
    {
        $status = (string)($songRow['status'] ?? '');
        if ($status !== 'published') {
            return false;
        }

        // Future scheduled release check
        if (!empty($songRow['release_date'])) {
            $releaseTimestamp = strtotime((string)$songRow['release_date']);
            if ($releaseTimestamp !== false && $releaseTimestamp > time()) {
                return false;
            }
        }

        return true;
    }
}
