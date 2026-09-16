<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Search;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;

/**
 * Universal Search Service for Favorite Multimedia Theme System.
 *
 * Provides safe, multi-entity search across Movies, Series, Songs,
 * Albums, Artists, and Playlists.
 *
 * NEVER exposes protected playback or download URLs in search payloads.
 */
class MultimediaSearchService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Container::getInstance()->get(Database::class);
    }

    /**
     * Search across all supported multimedia entities.
     *
     * @return array{
     *     query: string,
     *     filter: string,
     *     total_matches: int,
     *     groups: array<string, SearchResultGroup>
     * }
     */
    public function search(string $query, ?string $filter = 'all', int $limitPerGroup = 10, ?User $user = null): array
    {
        $cleanQuery = trim($query);
        $filter = strtolower(trim($filter ?: 'all'));

        if ($cleanQuery === '') {
            return [
                'query'         => '',
                'filter'        => $filter,
                'total_matches' => 0,
                'groups'        => [],
            ];
        }

        $groups = [];
        $totalMatches = 0;

        // 1. Movies
        if (in_array($filter, ['all', 'movies', 'movie'], true)) {
            $movieItems = $this->searchMovies($cleanQuery, $limitPerGroup);
            if (!empty($movieItems)) {
                $groups['movies'] = new SearchResultGroup('movies', 'Movies', '🎬', $movieItems);
                $totalMatches += count($movieItems);
            }
        }

        // 2. Series
        if (in_array($filter, ['all', 'series'], true)) {
            $seriesItems = $this->searchSeries($cleanQuery, $limitPerGroup);
            if (!empty($seriesItems)) {
                $groups['series'] = new SearchResultGroup('series', 'Series', '📺', $seriesItems);
                $totalMatches += count($seriesItems);
            }
        }

        // 3. Songs (included in 'all', 'music', 'songs')
        if (in_array($filter, ['all', 'music', 'songs', 'song'], true)) {
            $songItems = $this->searchSongs($cleanQuery, $limitPerGroup);
            if (!empty($songItems)) {
                $groups['songs'] = new SearchResultGroup('songs', 'Songs', '🎵', $songItems);
                $totalMatches += count($songItems);
            }
        }

        // 4. Albums (included in 'all', 'music', 'albums', 'album')
        if (in_array($filter, ['all', 'music', 'albums', 'album'], true)) {
            $albumItems = $this->searchAlbums($cleanQuery, $limitPerGroup);
            if (!empty($albumItems)) {
                $groups['albums'] = new SearchResultGroup('albums', 'Albums', '💿', $albumItems);
                $totalMatches += count($albumItems);
            }
        }

        // 5. Artists
        if (in_array($filter, ['all', 'artists', 'artist'], true)) {
            $artistItems = $this->searchArtists($cleanQuery, $limitPerGroup);
            if (!empty($artistItems)) {
                $groups['artists'] = new SearchResultGroup('artists', 'Artists', '🎙️', $artistItems);
                $totalMatches += count($artistItems);
            }
        }

        // 6. Playlists
        if (in_array($filter, ['all', 'playlists', 'playlist'], true)) {
            $playlistItems = $this->searchPlaylists($cleanQuery, $limitPerGroup);
            if (!empty($playlistItems)) {
                $groups['playlists'] = new SearchResultGroup('playlists', 'Playlists', '📑', $playlistItems);
                $totalMatches += count($playlistItems);
            }
        }

        return [
            'query'         => $cleanQuery,
            'filter'        => $filter,
            'total_matches' => $totalMatches,
            'groups'        => $groups,
        ];
    }

    private function searchMovies(string $query, int $limit): array
    {
        try {
            $movies = Movie::published($limit, 0, null, $query);
            $items = [];
            foreach ($movies as $m) {
                $accessMode = (string)($m->access_mode ?? 'public');
                $items[] = [
                    'id'          => (int)$m->id,
                    'type'        => 'movie',
                    'title'       => (string)$m->title,
                    'slug'        => (string)$m->slug,
                    'url'         => '/movie/' . $m->slug,
                    'poster'      => (string)($m->poster ?? ''),
                    'subtitle'    => $m->release_year ? (string)$m->release_year : '',
                    'badge'       => strtoupper($accessMode),
                    'access_mode' => $accessMode,
                    'is_premium'  => $accessMode === 'premium',
                    'meta'        => $m->getDurationFormatted(),
                ];
            }
            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function searchSeries(string $query, int $limit): array
    {
        try {
            $series = Series::published($limit, 0, null, $query);
            $items = [];
            foreach ($series as $s) {
                $accessMode = (string)($s->access_mode ?? 'public');
                $seasonsCount = count($s->getSeasons());
                $items[] = [
                    'id'          => (int)$s->id,
                    'type'        => 'series',
                    'title'       => (string)$s->title,
                    'slug'        => (string)$s->slug,
                    'url'         => '/series/' . $s->slug,
                    'poster'      => (string)($s->poster ?? ''),
                    'subtitle'    => $s->release_year ? (string)$s->release_year : '',
                    'badge'       => strtoupper($accessMode),
                    'access_mode' => $accessMode,
                    'is_premium'  => $accessMode === 'premium',
                    'meta'        => $seasonsCount . ($seasonsCount === 1 ? ' Season' : ' Seasons'),
                ];
            }
            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function searchSongs(string $query, int $limit): array
    {
        try {
            $songs = Song::published($limit, 0, null, null, null, $query);
            $items = [];
            foreach ($songs as $sg) {
                $accessMode = (string)($sg->access_mode ?? 'public');
                $artist = $sg->getArtist();
                $items[] = [
                    'id'          => (int)$sg->id,
                    'type'        => 'song',
                    'title'       => (string)$sg->title,
                    'slug'        => (string)$sg->slug,
                    'url'         => '/song/' . $sg->slug,
                    'poster'      => (string)($sg->cover ?? ''),
                    'subtitle'    => $artist ? (string)$artist->name : 'Various Artists',
                    'badge'       => strtoupper($accessMode),
                    'access_mode' => $accessMode,
                    'is_premium'  => $accessMode === 'premium',
                    'meta'        => $sg->getDurationFormatted(),
                ];
            }
            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function searchAlbums(string $query, int $limit): array
    {
        try {
            $like = '%' . $query . '%';
            $rows = $this->db->select(
                "SELECT * FROM multimedia_albums WHERE status = 'published' AND title LIKE ? ORDER BY id DESC LIMIT ?",
                [$like, $limit]
            );
            $items = [];
            foreach ($rows as $row) {
                $album = new Album((array)$row);
                $artist = $album->getArtist();
                $items[] = [
                    'id'          => (int)$album->id,
                    'type'        => 'album',
                    'title'       => (string)$album->title,
                    'slug'        => (string)$album->slug,
                    'url'         => '/multimedia/album/' . $album->slug,
                    'poster'      => (string)($album->cover ?? ''),
                    'subtitle'    => $artist ? (string)$artist->name : 'Various Artists',
                    'badge'       => 'ALBUM',
                    'access_mode' => 'public',
                    'is_premium'  => false,
                    'meta'        => $album->release_year ? (string)$album->release_year : '',
                ];
            }
            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function searchArtists(string $query, int $limit): array
    {
        try {
            $like = '%' . $query . '%';
            $rows = $this->db->select(
                "SELECT * FROM multimedia_artists WHERE status = 'active' AND (name LIKE ? OR biography LIKE ?) ORDER BY id DESC LIMIT ?",
                [$like, $like, $limit]
            );
            $items = [];
            foreach ($rows as $row) {
                $artist = new Artist((array)$row);
                $items[] = [
                    'id'          => (int)$artist->id,
                    'type'        => 'artist',
                    'title'       => (string)$artist->name,
                    'slug'        => (string)$artist->slug,
                    'url'         => '/multimedia/artist/' . $artist->slug,
                    'poster'      => (string)($artist->avatar ?? ''),
                    'subtitle'    => 'Artist',
                    'badge'       => 'ARTIST',
                    'access_mode' => 'public',
                    'is_premium'  => false,
                    'meta'        => '',
                ];
            }
            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function searchPlaylists(string $query, int $limit): array
    {
        try {
            $like = '%' . $query . '%';
            $rows = $this->db->select(
                "SELECT * FROM multimedia_playlists WHERE status = 'published' AND (title LIKE ? OR description LIKE ?) ORDER BY id DESC LIMIT ?",
                [$like, $like, $limit]
            );
            $items = [];
            foreach ($rows as $row) {
                $playlist = new Playlist((array)$row);
                $accessMode = (string)($playlist->access_mode ?? 'public');
                $items[] = [
                    'id'          => (int)$playlist->id,
                    'type'        => 'playlist',
                    'title'       => (string)$playlist->title,
                    'slug'        => (string)$playlist->slug,
                    'url'         => '/playlist/' . $playlist->slug,
                    'poster'      => (string)($playlist->cover ?? ''),
                    'subtitle'    => ucfirst((string)($playlist->type ?? 'mixed')) . ' Playlist',
                    'badge'       => strtoupper($accessMode),
                    'access_mode' => $accessMode,
                    'is_premium'  => $accessMode === 'premium',
                    'meta'        => '',
                ];
            }
            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
