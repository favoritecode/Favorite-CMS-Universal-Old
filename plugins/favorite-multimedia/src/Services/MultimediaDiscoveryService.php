<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Favorite;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Services\MediaLocalizationService;

class MultimediaDiscoveryService
{
    // Scoring constants
    public const WEIGHT_SHARED_GENRE  = 10.0;
    public const WEIGHT_SAME_ARTIST   = 30.0;
    public const WEIGHT_SAME_ALBUM    = 25.0;
    public const WEIGHT_SAME_DIRECTOR = 15.0;
    public const WEIGHT_CAST_MATCH    = 5.0;
    public const WEIGHT_POPULARITY    = 1.0;
    public const WEIGHT_FAVORITE      = 3.0;

    // Meaningful playback thresholds
    public const MEANINGFUL_PLAY_SECONDS = 30.0;
    public const MEANINGFUL_PLAY_PERCENT = 5.0;

    // Diversity limit
    public const MAX_SAME_PRIMARY_GENRE = 3;

    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    /**
     * Check if discovery feature is enabled globally.
     */
    public static function isDiscoveryEnabled(): bool
    {
        return Setting::get('multimedia', 'enable_discovery', 'yes') === 'yes';
    }

    /**
     * Check if trending feature is enabled globally.
     */
    public static function isTrendingEnabled(): bool
    {
        return Setting::get('multimedia', 'enable_trending', 'yes') === 'yes';
    }

    /**
     * Get trending window duration in days.
     */
    public static function getTrendingWindowDays(): int
    {
        return max(1, min(90, (int)Setting::get('multimedia', 'trending_window_days', 7)));
    }

    /**
     * Maximum discovery items per rail.
     */
    public static function getMaxDiscoveryItems(): int
    {
        return max(4, min(30, (int)Setting::get('multimedia', 'max_discovery_items', 10)));
    }

    public static function hasFavoritesTable(): bool
    {
        try {
            self::getDb()->selectOne("SELECT 1 FROM multimedia_favorites LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasProgressTable(): bool
    {
        try {
            self::getDb()->selectOne("SELECT 1 FROM multimedia_playback_progress LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasRatingsTable(): bool
    {
        try {
            self::getDb()->selectOne("SELECT 1 FROM multimedia_ratings LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasSubscriptionsTable(): bool
    {
        try {
            self::getDb()->selectOne("SELECT 1 FROM multimedia_subscriptions LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 1. Related Movies for a given Movie.
     */
    public static function getRelatedMovies(int|Movie $movie, int $limit = 6, mixed $user = null): array
    {
        $movieObj = is_int($movie) ? Movie::find($movie) : $movie;
        if (!$movieObj || $movieObj->status !== 'published') {
            return [];
        }

        $db = self::getDb();
        $movieId = (int)$movieObj->id;

        // Fetch genres of the current movie
        $genres = Genre::getForContent('movie', $movieId);
        $genreIds = array_map(fn($g) => (int)$g->id, $genres);

        $candidates = [];

        if (!empty($genreIds)) {
            $placeholders = implode(',', array_fill(0, count($genreIds), '?'));
            $params = array_merge([$movieId], $genreIds);

            // Find other published movies sharing genres
            $sql = "SELECT m.*, COUNT(cg.genre_id) as shared_genres_count
                    FROM multimedia_movies m
                    JOIN multimedia_content_genres cg ON m.id = cg.content_id AND cg.content_type = 'movie'
                    WHERE m.id != ? AND m.status = 'published' AND cg.genre_id IN ({$placeholders})
                    GROUP BY m.id
                    ORDER BY shared_genres_count DESC, m.views_count DESC, m.id DESC
                    LIMIT 30";

            $rows = $db->select($sql, $params);
            foreach ($rows as $row) {
                $m = new Movie((array)$row);
                $score = ((int)($row->shared_genres_count ?? 0)) * self::WEIGHT_SHARED_GENRE;

                // Director bonus
                if (!empty($movieObj->director) && !empty($m->director) && strcasecmp(trim($movieObj->director), trim($m->director)) === 0) {
                    $score += self::WEIGHT_SAME_DIRECTOR;
                }

                // Cast overlap bonus
                if (!empty($movieObj->cast) && !empty($m->cast)) {
                    $castA = array_filter(array_map('trim', explode(',', $movieObj->cast)));
                    $castB = array_filter(array_map('trim', explode(',', $m->cast)));
                    $sharedCast = array_intersect(array_map('strtolower', $castA), array_map('strtolower', $castB));
                    $score += count($sharedCast) * self::WEIGHT_CAST_MATCH;
                }

                // Views bonus (logarithmic, capped at 5)
                $views = (int)($m->views_count ?? 0);
                if ($views > 0) {
                    $score += min(5.0, log10($views + 1) * 2.0);
                }

                $candidates[] = [
                    'model' => $m,
                    'score' => $score,
                ];
            }
        }

        // If candidates are fewer than limit, backfill with top published movies
        if (count($candidates) < $limit) {
            $existingIds = array_merge([$movieId], array_map(fn($c) => (int)$c['model']->id, $candidates));
            $backfill = self::getBackfillContent('movie', $existingIds, $limit - count($candidates));
            foreach ($backfill as $m) {
                $candidates[] = ['model' => $m, 'score' => 1.0];
            }
        }

        // Sort by score DESC
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, $limit);

        return array_map(fn($c) => self::hydrateCandidate('movie', $c['model'], $user), $candidates);
    }

    /**
     * 2. Related Series for a given Series.
     */
    public static function getRelatedSeries(int|Series $series, int $limit = 6, mixed $user = null): array
    {
        $seriesObj = is_int($series) ? Series::find($series) : $series;
        if (!$seriesObj || $seriesObj->status !== 'published') {
            return [];
        }

        $db = self::getDb();
        $seriesId = (int)$seriesObj->id;

        $genres = Genre::getForContent('series', $seriesId);
        $genreIds = array_map(fn($g) => (int)$g->id, $genres);

        $candidates = [];

        if (!empty($genreIds)) {
            $placeholders = implode(',', array_fill(0, count($genreIds), '?'));
            $params = array_merge([$seriesId], $genreIds);

            $sql = "SELECT s.*, COUNT(cg.genre_id) as shared_genres_count
                    FROM multimedia_series s
                    JOIN multimedia_content_genres cg ON s.id = cg.content_id AND cg.content_type = 'series'
                    WHERE s.id != ? AND s.status = 'published' AND cg.genre_id IN ({$placeholders})
                    GROUP BY s.id
                    ORDER BY shared_genres_count DESC, s.views_count DESC, s.id DESC
                    LIMIT 30";

            $rows = $db->select($sql, $params);
            foreach ($rows as $row) {
                $s = new Series((array)$row);
                $score = ((int)($row->shared_genres_count ?? 0)) * self::WEIGHT_SHARED_GENRE;

                if (!empty($seriesObj->director) && !empty($s->director) && strcasecmp(trim($seriesObj->director), trim($s->director)) === 0) {
                    $score += self::WEIGHT_SAME_DIRECTOR;
                }

                $views = (int)($s->views_count ?? 0);
                if ($views > 0) {
                    $score += min(5.0, log10($views + 1) * 2.0);
                }

                $candidates[] = [
                    'model' => $s,
                    'score' => $score,
                ];
            }
        }

        if (count($candidates) < $limit) {
            $existingIds = array_merge([$seriesId], array_map(fn($c) => (int)$c['model']->id, $candidates));
            $backfill = self::getBackfillContent('series', $existingIds, $limit - count($candidates));
            foreach ($backfill as $s) {
                $candidates[] = ['model' => $s, 'score' => 1.0];
            }
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, $limit);

        return array_map(fn($c) => self::hydrateCandidate('series', $c['model'], $user), $candidates);
    }

    /**
     * 3. Related Songs for a given Song.
     */
    public static function getRelatedSongs(int|Song $song, int $limit = 6, mixed $user = null): array
    {
        $songObj = is_int($song) ? Song::find($song) : $song;
        if (!$songObj || $songObj->status !== 'published') {
            return [];
        }

        $db = self::getDb();
        $songId = (int)$songObj->id;
        $artistId = (int)($songObj->artist_id ?? 0);
        $albumId = (int)($songObj->album_id ?? 0);

        $genres = Genre::getForContent('song', $songId);
        $genreIds = array_map(fn($g) => (int)$g->id, $genres);

        $whereClauses = ["s.id != {$songId}", "s.status = 'published'"];
        $orClauses = [];
        $params = [];

        if ($artistId > 0) {
            $orClauses[] = "s.artist_id = ?";
            $params[] = $artistId;
        }
        if ($albumId > 0) {
            $orClauses[] = "s.album_id = ?";
            $params[] = $albumId;
        }
        if (!empty($genreIds)) {
            $placeholders = implode(',', array_fill(0, count($genreIds), '?'));
            $orClauses[] = "s.id IN (SELECT content_id FROM multimedia_content_genres WHERE content_type = 'song' AND genre_id IN ({$placeholders}))";
            foreach ($genreIds as $gid) {
                $params[] = $gid;
            }
        }

        $candidates = [];

        if (!empty($orClauses)) {
            $whereStr = implode(' AND ', $whereClauses) . " AND (" . implode(' OR ', $orClauses) . ")";
            $sql = "SELECT s.* FROM multimedia_songs s WHERE {$whereStr} ORDER BY s.plays_count DESC LIMIT 40";
            $rows = $db->select($sql, $params);

            foreach ($rows as $row) {
                $s = new Song((array)$row);
                $score = 0.0;

                // Same Artist bonus
                if ($artistId > 0 && (int)$s->artist_id === $artistId) {
                    $score += self::WEIGHT_SAME_ARTIST;
                }

                // Same Album bonus
                if ($albumId > 0 && (int)$s->album_id === $albumId) {
                    $score += self::WEIGHT_SAME_ALBUM;
                }

                // Shared genres bonus
                if (!empty($genreIds)) {
                    $sGenres = Genre::getForContent('song', (int)$s->id);
                    $sGenreIds = array_map(fn($g) => (int)$g->id, $sGenres);
                    $shared = array_intersect($genreIds, $sGenreIds);
                    $score += count($shared) * self::WEIGHT_SHARED_GENRE;
                }

                $plays = (int)($s->plays_count ?? 0);
                if ($plays > 0) {
                    $score += min(5.0, log10($plays + 1) * 2.0);
                }

                $candidates[] = [
                    'model' => $s,
                    'score' => $score,
                ];
            }
        }

        if (count($candidates) < $limit) {
            $existingIds = array_merge([$songId], array_map(fn($c) => (int)$c['model']->id, $candidates));
            $backfill = self::getBackfillContent('song', $existingIds, $limit - count($candidates));
            foreach ($backfill as $s) {
                $candidates[] = ['model' => $s, 'score' => 1.0];
            }
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, $limit);

        return array_map(fn($c) => self::hydrateCandidate('song', $c['model'], $user), $candidates);
    }

    /**
     * 4. Related Playlists for a Song, Artist, or Genre.
     */
    public static function getRelatedPlaylists(mixed $anchor, int $limit = 4, mixed $user = null): array
    {
        $db = self::getDb();
        $candidates = [];

        if ($anchor instanceof Song) {
            $songId = (int)$anchor->id;
            $artistId = (int)($anchor->artist_id ?? 0);

            $sql = "SELECT DISTINCT p.* FROM multimedia_playlists p
                    JOIN multimedia_playlist_items pi ON p.id = pi.playlist_id
                    JOIN multimedia_songs s ON pi.song_id = s.id
                    WHERE p.status = 'published' AND (pi.song_id = ? OR s.artist_id = ?)
                    ORDER BY p.id DESC LIMIT ?";
            $rows = $db->select($sql, [$songId, $artistId, $limit]);
            foreach ($rows as $row) {
                $candidates[] = new Playlist((array)$row);
            }
        } elseif ($anchor instanceof Artist) {
            $artistId = (int)$anchor->id;
            $sql = "SELECT DISTINCT p.* FROM multimedia_playlists p
                    JOIN multimedia_playlist_items pi ON p.id = pi.playlist_id
                    JOIN multimedia_songs s ON pi.song_id = s.id
                    WHERE p.status = 'published' AND s.artist_id = ?
                    ORDER BY p.id DESC LIMIT ?";
            $rows = $db->select($sql, [$artistId, $limit]);
            foreach ($rows as $row) {
                $candidates[] = new Playlist((array)$row);
            }
        } elseif ($anchor instanceof Genre) {
            $genreId = (int)$anchor->id;
            $sql = "SELECT DISTINCT p.* FROM multimedia_playlists p
                    JOIN multimedia_playlist_items pi ON p.id = pi.playlist_id
                    JOIN multimedia_content_genres cg ON pi.song_id = cg.content_id AND cg.content_type = 'song'
                    WHERE p.status = 'published' AND cg.genre_id = ?
                    ORDER BY p.id DESC LIMIT ?";
            $rows = $db->select($sql, [$genreId, $limit]);
            foreach ($rows as $row) {
                $candidates[] = new Playlist((array)$row);
            }
        }

        if (count($candidates) < $limit) {
            $existingIds = array_map(fn($p) => (int)$p->id, $candidates);
            $excludeClause = !empty($existingIds) ? "AND id NOT IN (" . implode(',', $existingIds) . ")" : "";
            $remaining = $limit - count($candidates);
            $rows = $db->select("SELECT * FROM multimedia_playlists WHERE status = 'published' {$excludeClause} ORDER BY featured DESC, id DESC LIMIT {$remaining}");
            foreach ($rows as $row) {
                $candidates[] = new Playlist((array)$row);
            }
        }

        return array_map(fn($p) => self::hydrateCandidate('playlist', $p, $user), array_slice($candidates, 0, $limit));
    }

    /**
     * 5. Trending Content: Time-bounded analytics window with manipulation resistance.
     */
    public static function getTrending(int $limit = 10, ?string $contentType = null, mixed $user = null): array
    {
        if (!self::isTrendingEnabled()) {
            return self::getPopular($limit, $contentType, $user);
        }

        $db = self::getDb();
        $days = self::getTrendingWindowDays();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $types = $contentType ? [$contentType] : ['movie', 'series', 'song'];
        $typePlaceholders = implode(',', array_fill(0, count($types), '?'));

        $analyticsParams = array_merge([$cutoff], $types);
        $sql = "SELECT content_type, content_id,
                       SUM(CASE 
                           WHEN event_type = 'play' THEN 3.0
                           WHEN event_type = 'download' THEN 2.0
                           WHEN event_type = 'view' THEN 1.0
                           ELSE 0.5 END) as trend_score
                FROM (
                    SELECT DISTINCT content_type, content_id, event_type, COALESCE(user_id, ip_hash) as actor
                    FROM multimedia_analytics
                    WHERE created_at >= ? AND content_type IN ({$typePlaceholders})
                ) sub
                GROUP BY content_type, content_id
                ORDER BY trend_score DESC
                LIMIT 50";

        $rows = $db->select($sql, $analyticsParams);
        $scores = [];

        foreach ($rows as $r) {
            $key = "{$r->content_type}:{$r->content_id}";
            $scores[$key] = [
                'type'  => (string)$r->content_type,
                'id'    => (int)$r->content_id,
                'score' => (float)$r->trend_score,
            ];
        }

        // Add recent favorites within window (4.0 pts per unique favorite) if table exists
        if (self::hasFavoritesTable()) {
            $favParams = array_merge([$cutoff], $types);
            $favSql = "SELECT content_type, content_id, COUNT(DISTINCT user_id) as fav_count
                       FROM multimedia_favorites
                       WHERE created_at >= ? AND content_type IN ({$typePlaceholders})
                       GROUP BY content_type, content_id";
            try {
                $favRows = $db->select($favSql, $favParams);
                foreach ($favRows as $fr) {
                    $key = "{$fr->content_type}:{$fr->content_id}";
                    if (!isset($scores[$key])) {
                        $scores[$key] = [
                            'type'  => (string)$fr->content_type,
                            'id'    => (int)$fr->content_id,
                            'score' => 0.0,
                        ];
                    }
                    $scores[$key]['score'] += ((int)$fr->fav_count) * 4.0;
                }
            } catch (\Throwable) {
            }
        }

        // Sort by trending score
        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

        $results = [];
        foreach ($scores as $item) {
            $model = self::fetchModel($item['type'], $item['id']);
            if ($model && $model->status === 'published') {
                $results[] = self::hydrateCandidate($item['type'], $model, $user);
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        // If results are sparse (< limit), blend in popular items
        if (count($results) < $limit) {
            $needed = $limit - count($results);
            $popular = self::getPopular($needed * 2, $contentType, $user);
            $seenKeys = array_map(fn($r) => "{$r['content_type']}:{$r['id']}", $results);
            foreach ($popular as $pop) {
                $key = "{$pop['content_type']}:{$pop['id']}";
                if (!in_array($key, $seenKeys, true)) {
                    $results[] = $pop;
                    $seenKeys[] = $key;
                    if (count($results) >= $limit) {
                        break;
                    }
                }
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * 6. Popular Content: All-time aggregate views, plays, and favorites.
     */
    public static function getPopular(int $limit = 10, ?string $contentType = null, mixed $user = null): array
    {
        $db = self::getDb();
        $candidates = [];

        $types = $contentType ? [$contentType] : ['movie', 'series', 'song'];
        $hasFavs = self::hasFavoritesTable();
        $hasRates = self::hasRatingsTable();

        if (in_array('movie', $types, true)) {
            $favSql = $hasFavs ? "+ COALESCE((SELECT COUNT(*) FROM multimedia_favorites WHERE content_type = 'movie' AND content_id = m.id), 0) * 3.0" : "";
            $rateSql = $hasRates ? "+ CASE WHEN (SELECT AVG(rating) FROM multimedia_ratings WHERE content_type = 'movie' AND content_id = m.id) >= 4.0 THEN 1.5 ELSE 0.0 END" : "";
            $rows = $db->select("SELECT m.*, 
                                        (m.views_count * 1.0 {$favSql} {$rateSql}) as pop_score
                                 FROM multimedia_movies m
                                 WHERE m.status = 'published'
                                 ORDER BY pop_score DESC, m.views_count DESC, m.id DESC
                                 LIMIT ?", [$limit]);
            foreach ($rows as $r) {
                $candidates[] = ['type' => 'movie', 'model' => new Movie((array)$r), 'score' => (float)$r->pop_score];
            }
        }

        if (in_array('series', $types, true)) {
            $favSql = $hasFavs ? "+ COALESCE((SELECT COUNT(*) FROM multimedia_favorites WHERE content_type = 'series' AND content_id = s.id), 0) * 3.0" : "";
            $rateSql = $hasRates ? "+ CASE WHEN (SELECT AVG(rating) FROM multimedia_ratings WHERE content_type = 'series' AND content_id = s.id) >= 4.0 THEN 1.5 ELSE 0.0 END" : "";
            $rows = $db->select("SELECT s.*, 
                                        (s.views_count * 1.0 {$favSql} {$rateSql}) as pop_score
                                 FROM multimedia_series s
                                 WHERE s.status = 'published'
                                 ORDER BY pop_score DESC, s.views_count DESC, s.id DESC
                                 LIMIT ?", [$limit]);
            foreach ($rows as $r) {
                $candidates[] = ['type' => 'series', 'model' => new Series((array)$r), 'score' => (float)$r->pop_score];
            }
        }

        if (in_array('song', $types, true)) {
            $favSql = $hasFavs ? "+ COALESCE((SELECT COUNT(*) FROM multimedia_favorites WHERE content_type = 'song' AND content_id = s.id), 0) * 3.0" : "";
            $rateSql = $hasRates ? "+ CASE WHEN (SELECT AVG(rating) FROM multimedia_ratings WHERE content_type = 'song' AND content_id = s.id) >= 4.0 THEN 1.5 ELSE 0.0 END" : "";
            $rows = $db->select("SELECT s.*, 
                                        (s.plays_count * 1.0 {$favSql} {$rateSql}) as pop_score
                                 FROM multimedia_songs s
                                 WHERE s.status = 'published'
                                 ORDER BY pop_score DESC, s.plays_count DESC, s.id DESC
                                 LIMIT ?", [$limit]);
            foreach ($rows as $r) {
                $candidates[] = ['type' => 'song', 'model' => new Song((array)$r), 'score' => (float)$r->pop_score];
            }
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, $limit);

        return array_map(fn($c) => self::hydrateCandidate($c['type'], $c['model'], $user), $candidates);
    }

    /**
     * Search published catalog items.
     */
    public static function search(string $query, ?string $contentType = null, int $limit = 10, ?string $lang = null): array
    {
        $q = trim($query);
        if ($q === '') {
            return ['results' => [], 'total' => 0];
        }

        $results = [];
        $seen = [];

        if (!$contentType || $contentType === 'movie') {
            $movies = \FavoriteCMS\Multimedia\Models\Movie::published($limit, 0, null, $q);
            foreach ($movies as $m) {
                $results[] = ['type' => 'movie', 'model' => $m];
                $seen['movie:' . $m->id] = true;
            }
        }
        if (!$contentType || $contentType === 'series') {
            $series = \FavoriteCMS\Multimedia\Models\Series::published($limit, 0, null, $q);
            foreach ($series as $s) {
                $results[] = ['type' => 'series', 'model' => $s];
                $seen['series:' . $s->id] = true;
            }
        }
        if (!$contentType || $contentType === 'song') {
            $songs = \FavoriteCMS\Multimedia\Models\Song::published($limit, 0, null, null, null, $q);
            foreach ($songs as $s) {
                $results[] = ['type' => 'song', 'model' => $s];
                $seen['song:' . $s->id] = true;
            }
        }

        // Search localized records
        try {
            $locResults = MediaLocalizationService::searchLocalized($q, $contentType, $lang, $limit);
            foreach ($locResults as $lr) {
                $key = ($lr['type'] ?? '') . ':' . ($lr['model']->id ?? 0);
                if (!isset($seen[$key])) {
                    $results[] = $lr;
                    $seen[$key] = true;
                }
            }
        } catch (\Throwable) {
            // Pre-migration or table missing fallback
        }

        // Hydrate localized metadata
        try {
            $results = MediaLocalizationService::hydrateList($results, $lang);
        } catch (\Throwable) {
            // Safe fallback
        }

        return [
            'results' => array_slice($results, 0, $limit),
            'total'   => count($results),
        ];
    }

    /**
     * 7. Recently Added Content: Ordered by publication / creation date.
     */
    public static function getRecentlyAdded(int $limit = 10, ?string $contentType = null, mixed $user = null): array
    {
        $db = self::getDb();
        $candidates = [];

        $types = $contentType ? [$contentType] : ['movie', 'series', 'song'];

        $hasPublishedAt = false;
        try {
            $db->selectOne("SELECT published_at FROM multimedia_movies LIMIT 0");
            $hasPublishedAt = true;
        } catch (\Throwable) {
            $hasPublishedAt = false;
        }

        $orderBy = $hasPublishedAt ? "ORDER BY COALESCE(published_at, created_at) DESC, id DESC" : "ORDER BY created_at DESC, id DESC";

        if (in_array('movie', $types, true)) {
            $rows = $db->select("SELECT * FROM multimedia_movies WHERE status = 'published' {$orderBy} LIMIT ?", [$limit]);
            foreach ($rows as $r) {
                $candidates[] = ['type' => 'movie', 'model' => new Movie((array)$r), 'time' => strtotime($r->published_at ?? $r->created_at ?? 'now')];
            }
        }

        if (in_array('series', $types, true)) {
            $rows = $db->select("SELECT * FROM multimedia_series WHERE status = 'published' {$orderBy} LIMIT ?", [$limit]);
            foreach ($rows as $r) {
                $candidates[] = ['type' => 'series', 'model' => new Series((array)$r), 'time' => strtotime($r->published_at ?? $r->created_at ?? 'now')];
            }
        }

        if (in_array('song', $types, true)) {
            $rows = $db->select("SELECT * FROM multimedia_songs WHERE status = 'published' {$orderBy} LIMIT ?", [$limit]);
            foreach ($rows as $r) {
                $candidates[] = ['type' => 'song', 'model' => new Song((array)$r), 'time' => strtotime($r->published_at ?? $r->created_at ?? 'now')];
            }
        }

        usort($candidates, fn($a, $b) => $b['time'] <=> $a['time']);
        $candidates = array_slice($candidates, 0, $limit);

        return array_map(fn($c) => self::hydrateCandidate($c['type'], $c['model'], $user), $candidates);
    }

    /**
     * 8. "Because You Watched [Title]": Derives recommendations from meaningful recent playback.
     */
    public static function getBecauseYouWatched(mixed $user, int $limit = 6): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasProgressTable()) {
            return [];
        }

        $db = self::getDb();

        $sql = "SELECT content_type, content_id, position, duration, percentage, is_completed, last_played_at
                FROM multimedia_playback_progress
                WHERE user_id = ? AND content_type IN ('movie', 'episode')
                  AND (position >= ? OR percentage >= ?)
                ORDER BY last_played_at DESC
                LIMIT 10";

        $rows = $db->select($sql, [$userId, self::MEANINGFUL_PLAY_SECONDS, self::MEANINGFUL_PLAY_PERCENT]);
        if (empty($rows)) {
            return [];
        }

        $completedIds = self::getUserCompletedIds($userId);

        foreach ($rows as $row) {
            $type = (string)$row->content_type;
            $id = (int)$row->content_id;

            $anchor = null;
            $related = [];

            if ($type === 'movie') {
                $movie = Movie::find($id);
                if ($movie && $movie->status === 'published') {
                    $anchor = [
                        'type'  => 'movie',
                        'title' => $movie->title,
                        'id'    => $movie->id,
                        'slug'  => $movie->slug,
                    ];
                    $related = self::getRelatedMovies($movie, $limit + count($completedIds), $user);
                }
            } elseif ($type === 'episode') {
                $ep = Episode::find($id);
                if ($ep && $ep->status === 'published') {
                    $series = $ep->getSeries();
                    if ($series && $series->status === 'published') {
                        $anchor = [
                            'type'  => 'series',
                            'title' => $series->title,
                            'id'    => $series->id,
                            'slug'  => $series->slug,
                        ];
                        $related = self::getRelatedSeries($series, $limit + count($completedIds), $user);
                    }
                }
            }

            if ($anchor && !empty($related)) {
                $filtered = [];
                foreach ($related as $item) {
                    $itemKey = "{$item['content_type']}:{$item['id']}";
                    if (in_array($itemKey, $completedIds, true)) {
                        continue;
                    }
                    if ($item['content_type'] === $anchor['type'] && (int)$item['id'] === (int)$anchor['id']) {
                        continue;
                    }
                    $filtered[] = $item;
                    if (count($filtered) >= $limit) {
                        break;
                    }
                }

                if (!empty($filtered)) {
                    return [
                        'anchor' => $anchor,
                        'items'  => $filtered,
                    ];
                }
            }
        }

        return [];
    }

    /**
     * 9. "Because You Liked [Title]": Derives recommendations from user Favorites.
     */
    public static function getBecauseYouLiked(mixed $user, int $limit = 6): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasFavoritesTable()) {
            return [];
        }

        $favorites = Favorite::getByUser($userId, 10);
        if (empty($favorites)) {
            return [];
        }

        $completedIds = self::getUserCompletedIds($userId);

        foreach ($favorites as $fav) {
            $type = (string)$fav->content_type;
            $id = (int)$fav->content_id;

            $anchor = null;
            $related = [];

            if ($type === 'movie') {
                $m = Movie::find($id);
                if ($m && $m->status === 'published') {
                    $anchor = ['type' => 'movie', 'title' => $m->title, 'id' => $m->id, 'slug' => $m->slug];
                    $related = self::getRelatedMovies($m, $limit + count($completedIds), $user);
                }
            } elseif ($type === 'series') {
                $s = Series::find($id);
                if ($s && $s->status === 'published') {
                    $anchor = ['type' => 'series', 'title' => $s->title, 'id' => $s->id, 'slug' => $s->slug];
                    $related = self::getRelatedSeries($s, $limit + count($completedIds), $user);
                }
            } elseif ($type === 'song') {
                $song = Song::find($id);
                if ($song && $song->status === 'published') {
                    $anchor = ['type' => 'song', 'title' => $song->title, 'id' => $song->id, 'slug' => $song->slug];
                    $related = self::getRelatedSongs($song, $limit + count($completedIds), $user);
                }
            }

            if ($anchor && !empty($related)) {
                $filtered = [];
                foreach ($related as $item) {
                    $itemKey = "{$item['content_type']}:{$item['id']}";
                    if (in_array($itemKey, $completedIds, true)) {
                        continue;
                    }
                    if ($item['content_type'] === $anchor['type'] && (int)$item['id'] === (int)$anchor['id']) {
                        continue;
                    }
                    $filtered[] = $item;
                    if (count($filtered) >= $limit) {
                        break;
                    }
                }

                if (!empty($filtered)) {
                    return [
                        'anchor' => $anchor,
                        'items'  => $filtered,
                    ];
                }
            }
        }

        return [];
    }

    /**
     * 10. Personalized Discovery Feed with Genre Diversity & Deduplication.
     */
    public static function getPersonalizedFeed(mixed $user, int $limit = 10, array $excludeKeys = []): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;

        // Cold-start fallback for guests or new users without history/likes/subscriptions
        if ($userId <= 0 || (!self::hasFavoritesTable() && !self::hasProgressTable() && !self::hasSubscriptionsTable())) {
            return self::getColdStartFeed($limit, $excludeKeys, $user);
        }

        $db = self::getDb();

        $genreWeights = [];

        // From Favorites
        if (self::hasFavoritesTable()) {
            $favRows = $db->select(
                "SELECT cg.genre_id, COUNT(*) as cnt
                 FROM multimedia_favorites f
                 JOIN multimedia_content_genres cg ON f.content_type = cg.content_type AND f.content_id = cg.content_id
                 WHERE f.user_id = ?
                 GROUP BY cg.genre_id",
                [$userId]
            );
            foreach ($favRows as $r) {
                $gid = (int)$r->genre_id;
                $genreWeights[$gid] = ($genreWeights[$gid] ?? 0.0) + (((int)$r->cnt) * self::WEIGHT_FAVORITE);
            }
        }

        // From Meaningful History
        if (self::hasProgressTable()) {
            $histRows = $db->select(
                "SELECT cg.genre_id, COUNT(*) as cnt
                 FROM multimedia_playback_progress p
                 JOIN multimedia_content_genres cg ON p.content_type = cg.content_type AND p.content_id = cg.content_id
                 WHERE p.user_id = ? AND (p.position >= ? OR p.percentage >= ?)
                 GROUP BY cg.genre_id",
                [$userId, self::MEANINGFUL_PLAY_SECONDS, self::MEANINGFUL_PLAY_PERCENT]
            );
            foreach ($histRows as $r) {
                $gid = (int)$r->genre_id;
                $genreWeights[$gid] = ($genreWeights[$gid] ?? 0.0) + (((int)$r->cnt) * 2.0);
            }
        }

        // Subscriptions
        $followedSeriesIds = [];
        $followedArtistIds = [];
        if ($userId > 0 && self::hasSubscriptionsTable()) {
            try {
                $fSeries = $db->select("SELECT target_id FROM multimedia_subscriptions WHERE user_id = ? AND target_type = 'series'", [$userId]);
                $followedSeriesIds = array_map(fn($r) => (int)$r->target_id, $fSeries);
                $fArtists = $db->select("SELECT target_id FROM multimedia_subscriptions WHERE user_id = ? AND target_type = 'artist'", [$userId]);
                $followedArtistIds = array_map(fn($r) => (int)$r->target_id, $fArtists);
            } catch (\Throwable) {
            }
        }

        if (empty($genreWeights) && empty($followedSeriesIds) && empty($followedArtistIds)) {
            return self::getColdStartFeed($limit, $excludeKeys, $user);
        }

        // Fetch exclusion IDs (completed items + continue watching)
        $completedKeys = self::getUserCompletedIds($userId);
        $cwRows = $db->select(
            "SELECT content_type, content_id FROM multimedia_playback_progress 
             WHERE user_id = ? AND is_completed = 0 AND position >= 5.0",
            [$userId]
        );
        $cwKeys = array_map(fn($r) => "{$r->content_type}:{$r->content_id}", $cwRows);
        $allExclusions = array_unique(array_merge($excludeKeys, $completedKeys, $cwKeys));

        arsort($genreWeights);
        $topGenreIds = array_slice(array_keys($genreWeights), 0, 5);

        $candidates = [];
        $genreCounts = [];

        if (!empty($topGenreIds)) {
            $placeholders = implode(',', array_fill(0, count($topGenreIds), '?'));

            // Movies candidates
            $movieSql = "SELECT m.*, cg.genre_id
                         FROM multimedia_movies m
                         JOIN multimedia_content_genres cg ON m.id = cg.content_id AND cg.content_type = 'movie'
                         WHERE m.status = 'published' AND cg.genre_id IN ({$placeholders})
                         ORDER BY m.views_count DESC LIMIT 40";
            $movieRows = $db->select($movieSql, $topGenreIds);

            foreach ($movieRows as $mr) {
                $key = "movie:{$mr->id}";
                if (in_array($key, $allExclusions, true)) {
                    continue;
                }
                $gid = (int)$mr->genre_id;
                $gWeight = $genreWeights[$gid] ?? 1.0;
                $score = $gWeight + min(5.0, log10((int)($mr->views_count ?? 0) + 1));

                if (!isset($candidates[$key])) {
                    $candidates[$key] = [
                        'type'     => 'movie',
                        'model'    => new Movie((array)$mr),
                        'score'    => $score,
                        'genre_id' => $gid,
                    ];
                } else {
                    $candidates[$key]['score'] += $gWeight;
                }
            }

            // Series candidates
            $seriesSql = "SELECT s.*, cg.genre_id
                          FROM multimedia_series s
                          JOIN multimedia_content_genres cg ON s.id = cg.content_id AND cg.content_type = 'series'
                          WHERE s.status = 'published' AND cg.genre_id IN ({$placeholders})
                          ORDER BY s.views_count DESC LIMIT 40";
            $seriesRows = $db->select($seriesSql, $topGenreIds);

            foreach ($seriesRows as $sr) {
                $key = "series:{$sr->id}";
                if (in_array($key, $allExclusions, true)) {
                    continue;
                }
                $gid = (int)$sr->genre_id;
                $gWeight = $genreWeights[$gid] ?? 1.0;
                $followBoost = in_array((int)$sr->id, $followedSeriesIds, true) ? 0.5 : 0.0;
                $score = $gWeight + min(5.0, log10((int)($sr->views_count ?? 0) + 1)) + $followBoost;

                if (!isset($candidates[$key])) {
                    $candidates[$key] = [
                        'type'     => 'series',
                        'model'    => new Series((array)$sr),
                        'score'    => $score,
                        'genre_id' => $gid,
                    ];
                } else {
                    $candidates[$key]['score'] += $gWeight;
                }
            }
        }

        // Also fetch general catalog candidates for genre diversity and discovery exploration
        $generalMovies = $db->select(
            "SELECT m.*, cg.genre_id FROM multimedia_movies m
             LEFT JOIN multimedia_content_genres cg ON m.id = cg.content_id AND cg.content_type = 'movie'
             WHERE m.status = 'published'
             ORDER BY m.views_count DESC, m.id DESC LIMIT 40"
        );
        foreach ($generalMovies as $mr) {
            $key = "movie:{$mr->id}";
            if (in_array($key, $allExclusions, true) || isset($candidates[$key])) {
                continue;
            }
            $gid = (int)($mr->genre_id ?? 0);
            $candidates[$key] = [
                'type'     => 'movie',
                'model'    => new Movie((array)$mr),
                'score'    => min(5.0, log10((int)($mr->views_count ?? 0) + 1)),
                'genre_id' => $gid,
            ];
        }

        $generalSeries = $db->select(
            "SELECT s.*, cg.genre_id FROM multimedia_series s
             LEFT JOIN multimedia_content_genres cg ON s.id = cg.content_id AND cg.content_type = 'series'
             WHERE s.status = 'published'
             ORDER BY s.views_count DESC, s.id DESC LIMIT 40"
        );
        foreach ($generalSeries as $sr) {
            $key = "series:{$sr->id}";
            if (in_array($key, $allExclusions, true) || isset($candidates[$key])) {
                continue;
            }
            $gid = (int)($sr->genre_id ?? 0);
            $followBoost = in_array((int)$sr->id, $followedSeriesIds, true) ? 0.5 : 0.0;
            $candidates[$key] = [
                'type'     => 'series',
                'model'    => new Series((array)$sr),
                'score'    => min(5.0, log10((int)($sr->views_count ?? 0) + 1)) + $followBoost,
                'genre_id' => $gid,
            ];
        }

        // Sort candidates by score DESC
        uasort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        // Apply diversity rule (max MAX_SAME_PRIMARY_GENRE items per primary genre)
        $diverseResults = [];
        foreach ($candidates as $cand) {
            $gid = $cand['genre_id'];
            $count = $genreCounts[$gid] ?? 0;
            if ($gid > 0 && $count >= self::MAX_SAME_PRIMARY_GENRE) {
                continue;
            }
            if ($gid > 0) {
                $genreCounts[$gid] = $count + 1;
            }
            $diverseResults[] = self::hydrateCandidate($cand['type'], $cand['model'], $user);
            if (count($diverseResults) >= $limit) {
                break;
            }
        }

        if (count($diverseResults) < $limit) {
            $seenKeys = array_merge($allExclusions, array_map(fn($r) => "{$r['content_type']}:{$r['id']}", $diverseResults));
            $backfill = self::getColdStartFeed($limit - count($diverseResults), $seenKeys, $user);
            $diverseResults = array_merge($diverseResults, $backfill);
        }

        return array_slice($diverseResults, 0, $limit);
    }

    /**
     * Recommended for User (alias for personalized feed).
     */
    public static function getRecommendedForUser(mixed $user, int $limit = 10): array
    {
        return self::getPersonalizedFeed($user, $limit);
    }

    /**
     * Cold-start fallback: Curated mix of Trending, Popular, and Recently Added.
     */
    public static function getColdStartFeed(int $limit = 10, array $excludeKeys = [], mixed $user = null): array
    {
        $trending = self::getTrending($limit * 2, null, $user);
        $popular = self::getPopular($limit * 2, null, $user);
        $recent = self::getRecentlyAdded($limit * 2, null, $user);

        $combined = [];
        $seen = array_flip($excludeKeys);

        foreach ([$trending, $popular, $recent] as $feed) {
            foreach ($feed as $item) {
                $key = "{$item['content_type']}:{$item['id']}";
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $combined[] = $item;
                    if (count($combined) >= $limit) {
                        return $combined;
                    }
                }
            }
        }

        return array_slice($combined, 0, $limit);
    }

    /**
     * Browse content by Genre.
     */
    public static function getGenreContent(string $genreSlug, int $limit = 20, int $offset = 0, mixed $user = null): array
    {
        $genre = Genre::findBySlug($genreSlug);
        if (!$genre) {
            return ['genre' => null, 'items' => [], 'total' => 0];
        }

        $db = self::getDb();
        $gid = (int)$genre->id;

        $movies = Movie::published($limit, $offset, $genreSlug);
        $series = Series::published($limit, $offset, $genreSlug);
        $songs  = Song::published($limit, $offset, null, null, $genreSlug);

        $items = [];
        foreach ($movies as $m) {
            $items[] = self::hydrateCandidate('movie', $m, $user);
        }
        foreach ($series as $s) {
            $items[] = self::hydrateCandidate('series', $s, $user);
        }
        foreach ($songs as $s) {
            $items[] = self::hydrateCandidate('song', $s, $user);
        }

        $totalMovies = Movie::countPublished($genreSlug);
        $totalSeries = Series::countPublished($genreSlug);
        $totalSongs  = Song::countPublished(null, null, $genreSlug);

        return [
            'genre'       => $genre,
            'items'       => array_slice($items, 0, $limit),
            'movies'      => array_map(fn($m) => self::hydrateCandidate('movie', $m, $user), $movies),
            'series'      => array_map(fn($s) => self::hydrateCandidate('series', $s, $user), $series),
            'songs'       => array_map(fn($s) => self::hydrateCandidate('song', $s, $user), $songs),
            'total'       => $totalMovies + $totalSeries + $totalSongs,
            'totalMovies' => $totalMovies,
            'totalSeries' => $totalSeries,
            'totalSongs'  => $totalSongs,
        ];
    }

    /**
     * Browse Artist profile and catalog.
     */
    public static function getArtistContent(string $artistSlug, mixed $user = null): ?array
    {
        $artist = Artist::findBySlug($artistSlug);
        if (!$artist) {
            return null;
        }

        $songs = $artist->getSongs();
        $albums = $artist->getAlbums();
        $playlists = self::getRelatedPlaylists($artist, 4, $user);

        return [
            'artist'    => $artist,
            'songs'     => array_map(fn($s) => self::hydrateCandidate('song', $s, $user), $songs),
            'albums'    => $albums,
            'playlists' => $playlists,
        ];
    }

    /**
     * Browse Album details and tracklist.
     */
    public static function getAlbumContent(string $albumSlug, mixed $user = null): ?array
    {
        $album = Album::findBySlug($albumSlug);
        if (!$album) {
            return null;
        }

        if (($album->status ?? 'published') !== 'published') {
            $isOwner = $user && ((int)($album->user_id ?? 0) === (int)$user->id);
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::can(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return null;
            }
        }

        $artist = $album->getArtist();
        $songs = $album->getSongs();

        return [
            'album'  => $album,
            'artist' => $artist,
            'songs'  => array_map(fn($s) => self::hydrateCandidate('song', $s, $user), $songs),
        ];
    }

    /**
     * Hydrate a candidate model into an access-aware presentation card.
     * CRITICAL: NEVER returns direct media stream URLs or download URLs!
     */
    public static function hydrateCandidate(string $contentType, object $item, mixed $user = null): array
    {
        $id = (int)($item->id ?? 0);
        $title = (string)($item->localized_title ?? $item->title ?? 'Untitled');
        $slug = (string)($item->slug ?? '');
        $description = (string)($item->localized_description ?? $item->description ?? '');
        $effectiveMode = (string)($item->access_mode ?? 'public');

        $accessState = MultimediaAccessService::checkAccess($user, $contentType, $item);
        $hasAccess = ($accessState === MultimediaAccessService::ALLOW);

        $badge = match ($effectiveMode) {
            'premium' => 'Premium',
            'login'   => 'Member',
            default   => 'Free',
        };

        $badgeClass = match ($effectiveMode) {
            'premium' => 'fmm-badge-premium',
            'login'   => 'fmm-badge-login',
            default   => 'fmm-badge-free',
        };

        $poster = '';
        $detailUrl = '#';
        $metaLabel = '';

        if ($contentType === 'movie') {
            $poster = (string)($item->poster ?? '');
            $detailUrl = "/movie/{$slug}";
            $metaLabel = method_exists($item, 'getDurationFormatted') ? $item->getDurationFormatted() : '';
            if (empty($metaLabel) && !empty($item->release_date)) {
                $metaLabel = substr((string)$item->release_date, 0, 4);
            }
        } elseif ($contentType === 'series') {
            $poster = (string)($item->poster ?? '');
            $detailUrl = "/series/{$slug}";
            $metaLabel = !empty($item->release_year) ? (string)$item->release_year : 'Series';
        } elseif ($contentType === 'song') {
            $poster = (string)($item->cover ?? '');
            $detailUrl = "/song/{$slug}";
            $artistName = method_exists($item, 'getArtist') ? ($item->getArtist()?->name ?? '') : '';
            $metaLabel = $artistName ?: 'Track';
        } elseif ($contentType === 'playlist') {
            $poster = (string)($item->cover ?? '');
            $detailUrl = "/playlist/{$slug}";
            $metaLabel = 'Playlist';
        }

        return [
            'id'               => $id,
            'content_type'     => $contentType,
            'title'            => $title,
            'slug'             => $slug,
            'description'      => $description,
            'poster'           => $poster,
            'detail_url'       => $detailUrl,
            'meta_label'       => $metaLabel,
            'access_mode'      => $effectiveMode,
            'access_state'     => $accessState,
            'has_access'       => $hasAccess,
            'badge'            => $badge,
            'badge_class'      => $badgeClass,
            'display_language' => (string)($item->display_language ?? 'en'),
            'model'            => $item,
        ];
    }

    /**
     * Get array of keys "type:id" for content completed by user.
     */
    private static function getUserCompletedIds(int $userId): array
    {
        if ($userId <= 0 || !self::hasProgressTable()) {
            return [];
        }
        $db = self::getDb();
        $rows = $db->select(
            "SELECT content_type, content_id FROM multimedia_playback_progress WHERE user_id = ? AND is_completed = 1",
            [$userId]
        );
        return array_map(fn($r) => "{$r->content_type}:{$r->content_id}", $rows);
    }

    /**
     * Fetch model instance by type and ID.
     */
    private static function fetchModel(string $type, int $id): ?object
    {
        return match ($type) {
            'movie'    => Movie::find($id),
            'series'   => Series::find($id),
            'song'     => Song::find($id),
            'playlist' => Playlist::find($id),
            default    => null,
        };
    }

    /**
     * Backfill content if candidate pools are smaller than limit.
     */
    private static function getBackfillContent(string $type, array $excludeIds, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $db = self::getDb();
        $excludeStr = !empty($excludeIds) ? "AND id NOT IN (" . implode(',', array_map('intval', $excludeIds)) . ")" : "";

        return match ($type) {
            'movie' => array_map(
                fn($r) => new Movie((array)$r),
                $db->select("SELECT * FROM multimedia_movies WHERE status = 'published' {$excludeStr} ORDER BY featured DESC, views_count DESC, id DESC LIMIT {$limit}")
            ),
            'series' => array_map(
                fn($r) => new Series((array)$r),
                $db->select("SELECT * FROM multimedia_series WHERE status = 'published' {$excludeStr} ORDER BY featured DESC, views_count DESC, id DESC LIMIT {$limit}")
            ),
            'song' => array_map(
                fn($r) => new Song((array)$r),
                $db->select("SELECT * FROM multimedia_songs WHERE status = 'published' {$excludeStr} ORDER BY featured DESC, plays_count DESC, id DESC LIMIT {$limit}")
            ),
            default => [],
        };
    }
}
