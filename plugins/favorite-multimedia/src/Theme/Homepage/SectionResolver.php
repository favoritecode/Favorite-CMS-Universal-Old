<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Homepage;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\UserLibraryService;

/**
 * Resolves homepage section configurations into hydrated model arrays using batched queries.
 */
final class SectionResolver
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Container::getInstance()->get(Database::class);
    }

    /**
     * Resolves all sections for a HomepageConfig into an array of sections with items.
     *
     * @return array{sections: array<int, array>}
     */
    public function resolveAll(HomepageConfig $config, ?User $user = null): array
    {
        $resolvedSections = [];
        foreach ($config->getSections() as $sec) {
            $items = $this->resolve($sec, $user);
            $sec['items'] = $items;
            $resolvedSections[] = $sec;
        }
        return ['sections' => $resolvedSections];
    }

    /**
     * Resolves items for a specific section configuration.
     *
     * @return array<int, mixed>
     */
    public function resolve(array $section, ?User $user = null): array
    {
        $type = (string)($section['type'] ?? '');
        $limit = max(1, min(50, (int)($section['limit'] ?? 10)));
        $options = (array)($section['options'] ?? []);

        switch ($type) {
            case SectionRegistry::TYPE_HERO:
                return $this->resolveHero($section, $limit);

            case SectionRegistry::TYPE_CONTINUE_WATCHING:
                if (!$user) {
                    return [];
                }
                return UserLibraryService::getContinueWatching($user, $limit);

            case SectionRegistry::TYPE_CONTINUE_LISTENING:
                if (!$user) {
                    return [];
                }
                return UserLibraryService::getContinueListening($user, $limit);

            case SectionRegistry::TYPE_TRENDING:
                return $this->resolveTrending($limit);

            case SectionRegistry::TYPE_LATEST_MOVIES:
                return Movie::published($limit, 0);

            case SectionRegistry::TYPE_POPULAR_MOVIES:
                return $this->resolvePopularMovies($limit);

            case SectionRegistry::TYPE_FEATURED_MOVIES:
                return $this->resolveFeaturedMovies($limit, $options);

            case SectionRegistry::TYPE_LATEST_SERIES:
                return Series::published($limit, 0);

            case SectionRegistry::TYPE_POPULAR_SERIES:
                return $this->resolvePopularSeries($limit);

            case SectionRegistry::TYPE_FEATURED_SERIES:
                return $this->resolveFeaturedSeries($limit, $options);

            case SectionRegistry::TYPE_LATEST_EPISODES:
                return $this->resolveLatestEpisodes($limit);

            case SectionRegistry::TYPE_MUSIC_SPOTLIGHT:
                return $this->resolveMusicSpotlight($limit, $options);

            case SectionRegistry::TYPE_NEW_SONGS:
                return Song::published($limit, 0);

            case SectionRegistry::TYPE_POPULAR_SONGS:
                return $this->resolvePopularSongs($limit);

            case SectionRegistry::TYPE_ALBUMS:
                return Album::published($limit, 0);

            case SectionRegistry::TYPE_FEATURED_ALBUMS:
                return $this->resolveFeaturedAlbums($limit, $options);

            case SectionRegistry::TYPE_ARTISTS:
            case SectionRegistry::TYPE_FEATURED_ARTISTS:
                return Artist::published($limit, 0);

            case SectionRegistry::TYPE_VIDEO_PLAYLISTS:
            case SectionRegistry::TYPE_AUDIO_PLAYLISTS:
                return Playlist::published($limit, 0);

            case SectionRegistry::TYPE_GENRES:
                return Genre::all();

            case SectionRegistry::TYPE_TOP_10:
                return $this->resolveTop10($options);

            case SectionRegistry::TYPE_EDITORS_PICKS:
                return $this->resolveEditorsPicks($limit, $options);

            case SectionRegistry::TYPE_CUSTOM_COLLECTION:
                return $this->resolveCustomCollection($options);

            default:
                return [];
        }
    }

    private function resolveHero(array $section, int $limit): array
    {
        $source = (string)($section['content_source'] ?? 'featured');
        $options = (array)($section['options'] ?? []);

        if ($source === 'manual' && !empty($options['manual_ids'])) {
            return $this->resolveManualItems($options['manual_ids']);
        }

        // Automatic hero: published movies/series with backdrop images
        try {
            $sql = "SELECT m.*, 'movie' as media_kind FROM multimedia_movies m 
                    WHERE m.status = 'published' AND m.backdrop IS NOT NULL AND m.backdrop != '' 
                    ORDER BY m.featured DESC, m.id DESC LIMIT ?";
            $rows = $this->db->select($sql, [$limit]);
            if (!empty($rows)) {
                return array_map(fn($r) => new Movie((array)$r), $rows);
            }
        } catch (\Throwable) {
        }

        // Fallback to latest published movies
        return Movie::published($limit, 0);
    }

    private function resolveTrending(int $limit): array
    {
        try {
            $movies = Movie::published((int)ceil($limit / 2), 0);
            $series = Series::published((int)floor($limit / 2), 0);
            $merged = array_merge($movies, $series);
            return array_slice($merged, 0, $limit);
        } catch (\Throwable) {
            return Movie::published($limit, 0);
        }
    }

    private function resolvePopularMovies(int $limit): array
    {
        try {
            $sql = "SELECT * FROM multimedia_movies WHERE status = 'published' ORDER BY views_count DESC, rating DESC, id DESC LIMIT ?";
            $rows = $this->db->select($sql, [$limit]);
            return array_map(fn($r) => new Movie((array)$r), $rows);
        } catch (\Throwable) {
            return Movie::published($limit, 0);
        }
    }

    private function resolveFeaturedMovies(int $limit, array $options): array
    {
        if (!empty($options['manual_ids'])) {
            return $this->resolveManualItems($options['manual_ids'], 'movie');
        }

        try {
            $sql = "SELECT * FROM multimedia_movies WHERE status = 'published' ORDER BY featured DESC, id DESC LIMIT ?";
            $rows = $this->db->select($sql, [$limit]);
            return array_map(fn($r) => new Movie((array)$r), $rows);
        } catch (\Throwable) {
            return Movie::published($limit, 0);
        }
    }

    private function resolvePopularSeries(int $limit): array
    {
        try {
            $sql = "SELECT * FROM multimedia_series WHERE status = 'published' ORDER BY rating DESC, id DESC LIMIT ?";
            $rows = $this->db->select($sql, [$limit]);
            return array_map(fn($r) => new Series((array)$r), $rows);
        } catch (\Throwable) {
            return Series::published($limit, 0);
        }
    }

    private function resolveFeaturedSeries(int $limit, array $options): array
    {
        if (!empty($options['manual_ids'])) {
            return $this->resolveManualItems($options['manual_ids'], 'series');
        }

        try {
            $sql = "SELECT * FROM multimedia_series WHERE status = 'published' ORDER BY featured DESC, id DESC LIMIT ?";
            $rows = $this->db->select($sql, [$limit]);
            return array_map(fn($r) => new Series((array)$r), $rows);
        } catch (\Throwable) {
            return Series::published($limit, 0);
        }
    }

    private function resolveLatestEpisodes(int $limit): array
    {
        try {
            $sql = "SELECT * FROM multimedia_episodes WHERE status = 'published' ORDER BY id DESC LIMIT ?";
            $rows = $this->db->select($sql, [$limit]);
            return array_map(fn($r) => new Episode((array)$r), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolvePopularSongs(int $limit): array
    {
        try {
            $sql = "SELECT * FROM multimedia_songs WHERE status = 'published' ORDER BY plays_count DESC, id DESC LIMIT ?";
            $rows = $this->db->select($sql, [$limit]);
            return array_map(fn($r) => new Song((array)$r), $rows);
        } catch (\Throwable) {
            return Song::published($limit, 0);
        }
    }

    private function resolveMusicSpotlight(int $limit, array $options): array
    {
        if (!empty($options['manual_ids'])) {
            return $this->resolveManualItems($options['manual_ids'], 'song');
        }

        return Song::published($limit, 0);
    }

    private function resolveFeaturedAlbums(int $limit, array $options): array
    {
        if (!empty($options['manual_ids'])) {
            return $this->resolveManualItems($options['manual_ids'], 'album');
        }

        try {
            $sql = "SELECT * FROM multimedia_albums WHERE status = 'published' ORDER BY featured DESC, id DESC LIMIT ?";
            $rows = $this->db->select($sql, [$limit]);
            return array_map(fn($r) => new Album((array)$r), $rows);
        } catch (\Throwable) {
            return Album::published($limit, 0);
        }
    }

    private function resolveTop10(array $options): array
    {
        if (!empty($options['manual_ids'])) {
            return array_slice($this->resolveManualItems($options['manual_ids']), 0, 10);
        }

        try {
            $sql = "SELECT * FROM multimedia_movies WHERE status = 'published' ORDER BY views_count DESC, rating DESC, id DESC LIMIT 10";
            $rows = $this->db->select($sql);
            return array_map(fn($r) => new Movie((array)$r), $rows);
        } catch (\Throwable) {
            return array_slice(Movie::published(10, 0), 0, 10);
        }
    }

    private function resolveEditorsPicks(int $limit, array $options): array
    {
        if (!empty($options['manual_ids'])) {
            return $this->resolveManualItems($options['manual_ids']);
        }

        return Movie::published($limit, 0);
    }

    private function resolveCustomCollection(array $options): array
    {
        $ids = (array)($options['manual_ids'] ?? []);
        if (empty($ids)) {
            return [];
        }

        return $this->resolveManualItems($ids);
    }

    /**
     * Resolves specific content IDs across multimedia types without N+1 queries.
     *
     * @param array<int> $ids
     */
    private function resolveManualItems(array $ids, ?string $preferredType = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (empty($ids)) {
            return [];
        }

        $items = [];
        foreach ($ids as $id) {
            if ($preferredType !== null) {
                $model = MultimediaAccessService::findContentModel($preferredType, $id);
            } else {
                // Try movie first, then series, song, album, playlist
                $model = Movie::find($id) ?? Series::find($id) ?? Song::find($id) ?? Album::find($id) ?? Playlist::find($id);
            }

            if ($model && ($model->status ?? 'published') === 'published') {
                $items[] = $model;
            }
        }

        return $items;
    }
}
