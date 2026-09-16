<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;

class MultimediaAnalyticsService
{
    /**
     * Resolve date range filter with inclusive timestamps.
     */
    public static function resolveDateRange(?string $range = 'last_30_days', ?string $customStart = null, ?string $customEnd = null): array
    {
        $range = strtolower(trim((string)$range));
        $now = time();

        switch ($range) {
            case 'today':
                $start = gmdate('Y-m-d 00:00:00', $now);
                $end   = gmdate('Y-m-d 23:59:59', $now);
                $label = 'Today';
                break;
            case 'last_7_days':
                $start = gmdate('Y-m-d 00:00:00', strtotime('-6 days', $now));
                $end   = gmdate('Y-m-d 23:59:59', $now);
                $label = 'Last 7 Days';
                break;
            case 'last_90_days':
                $start = gmdate('Y-m-d 00:00:00', strtotime('-89 days', $now));
                $end   = gmdate('Y-m-d 23:59:59', $now);
                $label = 'Last 90 Days';
                break;
            case 'all_time':
                $start = '1970-01-01 00:00:00';
                $end   = gmdate('Y-m-d 23:59:59', $now);
                $label = 'All Time';
                break;
            case 'custom':
                $s = $customStart ? trim($customStart) : gmdate('Y-m-d', strtotime('-30 days', $now));
                $e = $customEnd ? trim($customEnd) : gmdate('Y-m-d', $now);
                $start = strlen($s) === 10 ? "{$s} 00:00:00" : $s;
                $end   = strlen($e) === 10 ? "{$e} 23:59:59" : $e;
                $label = "Custom ({$s} to {$e})";
                break;
            case 'last_30_days':
            default:
                $range = 'last_30_days';
                $start = gmdate('Y-m-d 00:00:00', strtotime('-29 days', $now));
                $end   = gmdate('Y-m-d 23:59:59', $now);
                $label = 'Last 30 Days';
                break;
        }

        return [
            'key'   => $range,
            'label' => $label,
            'start' => $start,
            'end'   => $end,
        ];
    }

    /**
     * Get overview dashboard summary metrics.
     */
    public static function getOverviewStats(array $dateFilter = [], ?int $authorId = null): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        // 1. Analytics Event Counts (Plays, Views, Downloads, Denials)
        if ($authorId !== null && $authorId > 0) {
            $eventRows = $db->select("
                SELECT ma.event_type, COUNT(*) as c
                FROM multimedia_analytics ma
                WHERE ma.created_at >= ? AND ma.created_at <= ?
                AND (
                    (ma.content_type = 'movie' AND ma.content_id IN (SELECT id FROM multimedia_movies WHERE user_id = {$authorId}))
                    OR (ma.content_type = 'series' AND ma.content_id IN (SELECT id FROM multimedia_series WHERE user_id = {$authorId}))
                    OR (ma.content_type = 'episode' AND ma.content_id IN (SELECT id FROM multimedia_episodes WHERE user_id = {$authorId}))
                    OR (ma.content_type = 'song' AND ma.content_id IN (SELECT id FROM multimedia_songs WHERE user_id = {$authorId}))
                    OR (ma.content_type = 'playlist' AND ma.content_id IN (SELECT id FROM multimedia_playlists WHERE user_id = {$authorId}))
                )
                GROUP BY ma.event_type
            ", [$start, $end]);
        } else {
            $eventRows = $db->select("
                SELECT event_type, COUNT(*) as c
                FROM multimedia_analytics
                WHERE created_at >= ? AND created_at <= ?
                GROUP BY event_type
            ", [$start, $end]);
        }

        $plays = 0;
        $views = 0;
        $downloads = 0;
        $premiumDenied = 0;
        foreach ($eventRows as $r) {
            match ($r->event_type) {
                'play'           => $plays = (int)$r->c,
                'view'           => $views = (int)$r->c,
                'download'       => $downloads = (int)$r->c,
                'premium_denied' => $premiumDenied = (int)$r->c,
                default          => null,
            };
        }

        // 2. Unique Viewers (Authenticated user count & Unique privacy-safe session hashes)
        $uniqUsers = (int)($db->selectOne("
            SELECT COUNT(DISTINCT user_id) as c
            FROM multimedia_analytics
            WHERE user_id IS NOT NULL AND user_id > 0 AND created_at >= ? AND created_at <= ?
        ", [$start, $end])->c ?? 0);

        $uniqSessions = (int)($db->selectOne("
            SELECT COUNT(DISTINCT ip_hash) as c
            FROM multimedia_analytics
            WHERE ip_hash IS NOT NULL AND ip_hash != '' AND created_at >= ? AND created_at <= ?
        ", [$start, $end])->c ?? 0);

        // 3. Watch Time & Completion Rate from PlaybackProgress (Canonical >= 90% completion rule)
        $progStats = $db->selectOne("
            SELECT 
                COALESCE(SUM(position), 0) as total_watch_seconds,
                COALESCE(AVG(percentage), 0) as avg_progress,
                COUNT(*) as total_sessions,
                COALESCE(SUM(CASE WHEN is_completed = 1 OR percentage >= 90 THEN 1 ELSE 0 END), 0) as completed_sessions
            FROM multimedia_playback_progress
            WHERE last_played_at >= ? AND last_played_at <= ?
        ", [$start, $end]);

        $totalWatchSeconds = (float)($progStats->total_watch_seconds ?? 0.0);
        $totalSessions = (int)($progStats->total_sessions ?? 0);
        $completedSessions = (int)($progStats->completed_sessions ?? 0);
        $avgProgress = round((float)($progStats->avg_progress ?? 0.0), 1);
        $completionRate = $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100, 1) : 0.0;

        // 4. Favorites Growth
        $favorites = (int)($db->selectOne("
            SELECT COUNT(*) as c
            FROM multimedia_favorites
            WHERE created_at >= ? AND created_at <= ?
        ", [$start, $end])->c ?? 0);

        // 5. Follower Growth
        $followers = (int)($db->selectOne("
            SELECT COUNT(*) as c
            FROM multimedia_subscriptions
            WHERE created_at >= ? AND created_at <= ?
        ", [$start, $end])->c ?? 0);

        // 6. Ratings
        $ratingStats = $db->selectOne("
            SELECT 
                COUNT(*) as total_ratings,
                COALESCE(AVG(rating), 0) as avg_rating
            FROM multimedia_ratings
            WHERE created_at >= ? AND created_at <= ?
        ", [$start, $end]);

        $avgRating = round((float)($ratingStats->avg_rating ?? 0.0), 2);
        $totalRatings = (int)($ratingStats->total_ratings ?? 0);

        // 7. Content totals in system
        $totalMovies    = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_movies")->c ?? 0);
        $totalSeries    = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_series")->c ?? 0);
        $totalEpisodes  = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_episodes")->c ?? 0);
        $totalSongs     = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_songs")->c ?? 0);
        $totalPlaylists = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_playlists")->c ?? 0);

        return [
            'date_filter'          => $df,
            'total_plays'          => $plays,
            'total_views'          => $views,
            'total_downloads'      => $downloads,
            'premium_denied'       => $premiumDenied,
            'unique_users'         => $uniqUsers,
            'unique_sessions'      => $uniqSessions,
            'total_watch_seconds'  => $totalWatchSeconds,
            'watch_time_formatted' => self::formatDuration((int)$totalWatchSeconds),
            'completion_rate'      => $completionRate,
            'completed_sessions'   => $completedSessions,
            'total_sessions'       => $totalSessions,
            'avg_progress'         => $avgProgress,
            'favorites'            => $favorites,
            'followers'            => $followers,
            'avg_rating'           => $avgRating,
            'total_ratings'        => $totalRatings,
            'catalog_counts'       => [
                'movies'    => $totalMovies,
                'series'    => $totalSeries,
                'episodes'  => $totalEpisodes,
                'songs'     => $totalSongs,
                'playlists' => $totalPlaylists,
            ],
        ];
    }

    /**
     * Get daily/hourly trends for plays and views.
     */
    public static function getPlayTrends(array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        $rows = $db->select("
            SELECT 
                SUBSTR(created_at, 1, 10) as day_date,
                event_type,
                COUNT(*) as c
            FROM multimedia_analytics
            WHERE created_at >= ? AND created_at <= ?
              AND event_type IN ('play', 'view', 'download')
            GROUP BY SUBSTR(created_at, 1, 10), event_type
            ORDER BY day_date ASC
        ", [$start, $end]);

        $trendMap = [];
        foreach ($rows as $r) {
            $day = (string)$r->day_date;
            if (!isset($trendMap[$day])) {
                $trendMap[$day] = ['date' => $day, 'plays' => 0, 'views' => 0, 'downloads' => 0];
            }
            if ($r->event_type === 'play') {
                $trendMap[$day]['plays'] = (int)$r->c;
            } elseif ($r->event_type === 'view') {
                $trendMap[$day]['views'] = (int)$r->c;
            } elseif ($r->event_type === 'download') {
                $trendMap[$day]['downloads'] = (int)$r->c;
            }
        }

        return array_values($trendMap);
    }

    /**
     * Get paginated, sortable content performance list without N+1 queries.
     */
    public static function getContentPerformance(
        string $contentType,
        array $dateFilter = [],
        int $limit = 20,
        int $offset = 0,
        string $sortBy = 'plays',
        string $sortOrder = 'DESC',
        string $locale = 'en',
        ?int $authorId = null
    ): array {
        $allowedTypes = ['movie', 'series', 'episode', 'song', 'playlist'];
        if (!in_array($contentType, $allowedTypes, true)) {
            $contentType = 'movie';
        }

        $allowedSorts = ['plays', 'views', 'downloads', 'watch_time', 'completion_rate', 'favorites', 'rating', 'title'];
        if (!in_array(strtolower($sortBy), $allowedSorts, true)) {
            $sortBy = 'plays';
        }
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        // Catalog table mapping
        $tableMap = [
            'movie'    => 'multimedia_movies',
            'series'   => 'multimedia_series',
            'episode'  => 'multimedia_episodes',
            'song'     => 'multimedia_songs',
            'playlist' => 'multimedia_playlists',
        ];
        $catTable = $tableMap[$contentType];

        // Total count of catalog items
        $authorClause = ($authorId !== null && $authorId > 0) ? " WHERE user_id = {$authorId}" : "";
        $totalItems = (int)($db->selectOne("SELECT COUNT(*) as c FROM `{$catTable}`{$authorClause}")->c ?? 0);

        // Fetch paginated catalog records
        $catalogItems = $db->select("
            SELECT id, title, access_mode, status, created_at
            FROM `{$catTable}`
            {$authorClause}
            ORDER BY id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        if (empty($catalogItems)) {
            return [
                'items'        => [],
                'total'        => $totalItems,
                'content_type' => $contentType,
                'date_filter'  => $df,
                'sort_by'      => $sortBy,
                'sort_order'   => $sortOrder,
            ];
        }

        $contentIds = array_map(fn($item) => (int)$item->id, $catalogItems);
        $placeholders = implode(',', array_fill(0, count($contentIds), '?'));

        // 1. Bulk Analytics: Plays, Views, Downloads, Unique Users
        $anSql = "
            SELECT 
                content_id,
                event_type,
                COUNT(*) as event_count,
                COUNT(DISTINCT user_id) as uniq_users
            FROM multimedia_analytics
            WHERE content_type = ?
              AND content_id IN ({$placeholders})
              AND created_at >= ? AND created_at <= ?
            GROUP BY content_id, event_type
        ";
        $anParams = array_merge([$contentType], $contentIds, [$start, $end]);
        $anRows = $db->select($anSql, $anParams);

        $statsByContent = [];
        foreach ($contentIds as $cid) {
            $statsByContent[$cid] = [
                'plays'         => 0,
                'views'         => 0,
                'downloads'     => 0,
                'unique_users'  => 0,
                'watch_seconds' => 0.0,
                'completions'   => 0,
                'sessions'      => 0,
                'favorites'     => 0,
                'rating_avg'    => 0.0,
                'rating_count'  => 0,
            ];
        }

        foreach ($anRows as $r) {
            $cid = (int)$r->content_id;
            if ($r->event_type === 'play') {
                $statsByContent[$cid]['plays'] = (int)$r->event_count;
                $statsByContent[$cid]['unique_users'] = (int)$r->uniq_users;
            } elseif ($r->event_type === 'view') {
                $statsByContent[$cid]['views'] = (int)$r->event_count;
            } elseif ($r->event_type === 'download') {
                $statsByContent[$cid]['downloads'] = (int)$r->event_count;
            }
        }

        // 2. Bulk Playback Progress: Watch Time & Completion
        $progSql = "
            SELECT 
                content_id,
                COALESCE(SUM(position), 0) as total_watch_seconds,
                COUNT(*) as sessions,
                COALESCE(SUM(CASE WHEN is_completed = 1 OR percentage >= 90 THEN 1 ELSE 0 END), 0) as completed_sessions
            FROM multimedia_playback_progress
            WHERE content_type = ?
              AND content_id IN ({$placeholders})
              AND last_played_at >= ? AND last_played_at <= ?
            GROUP BY content_id
        ";
        $progParams = array_merge([$contentType], $contentIds, [$start, $end]);
        $progRows = $db->select($progSql, $progParams);
        foreach ($progRows as $pr) {
            $cid = (int)$pr->content_id;
            $statsByContent[$cid]['watch_seconds'] = (float)$pr->total_watch_seconds;
            $statsByContent[$cid]['sessions']      = (int)$pr->sessions;
            $statsByContent[$cid]['completions']   = (int)$pr->completed_sessions;
        }

        // 3. Bulk Favorites
        $favSql = "
            SELECT content_id, COUNT(*) as c
            FROM multimedia_favorites
            WHERE content_type = ?
              AND content_id IN ({$placeholders})
            GROUP BY content_id
        ";
        $favRows = $db->select($favSql, array_merge([$contentType], $contentIds));
        foreach ($favRows as $fr) {
            $statsByContent[(int)$fr->content_id]['favorites'] = (int)$fr->c;
        }

        // 4. Bulk Ratings
        $rateSql = "
            SELECT content_id, COUNT(*) as c, COALESCE(AVG(rating), 0) as avg_rate
            FROM multimedia_ratings
            WHERE content_type = ?
              AND content_id IN ({$placeholders})
            GROUP BY content_id
        ";
        $rateRows = $db->select($rateSql, array_merge([$contentType], $contentIds));
        foreach ($rateRows as $rr) {
            $cid = (int)$rr->content_id;
            $statsByContent[$cid]['rating_avg']   = round((float)$rr->avg_rate, 2);
            $statsByContent[$cid]['rating_count'] = (int)$rr->c;
        }

        // 5. Localized Titles (Zero N+1 Hydration)
        $modelItems = [];
        foreach ($catalogItems as $c) {
            $modelItems[] = [
                'type'  => $contentType,
                'model' => $c,
            ];
        }
        $hydrated = MediaLocalizationService::hydrateList($modelItems, $locale);

        // 6. Merge and calculate derived metrics
        $results = [];
        foreach ($hydrated as $h) {
            $item = $h['model'];
            $cid = (int)$item->id;
            $s = $statsByContent[$cid];

            $compRate = $s['sessions'] > 0 ? round(($s['completions'] / $s['sessions']) * 100, 1) : 0.0;

            $results[] = [
                'id'              => $cid,
                'title'           => $item->title,
                'localized_title' => $item->localized_title ?? $item->title,
                'access_mode'     => $item->access_mode ?? 'public',
                'status'          => $item->status ?? 'published',
                'plays'           => $s['plays'],
                'views'           => $s['views'],
                'downloads'       => $s['downloads'],
                'unique_users'    => $s['unique_users'],
                'watch_seconds'   => $s['watch_seconds'],
                'watch_formatted' => self::formatDuration((int)$s['watch_seconds']),
                'sessions'        => $s['sessions'],
                'completions'     => $s['completions'],
                'completion_rate' => $compRate,
                'favorites'       => $s['favorites'],
                'rating_avg'      => $s['rating_avg'],
                'rating_count'    => $s['rating_count'],
            ];
        }

        // Sort in PHP if sorting by derived metric
        usort($results, function ($a, $b) use ($sortBy, $sortOrder) {
            $valA = match ($sortBy) {
                'views'           => $a['views'],
                'downloads'       => $a['downloads'],
                'watch_time'      => $a['watch_seconds'],
                'completion_rate' => $a['completion_rate'],
                'favorites'       => $a['favorites'],
                'rating'          => $a['rating_avg'],
                'title'           => strtolower($a['title']),
                default           => $a['plays'],
            };
            $valB = match ($sortBy) {
                'views'           => $b['views'],
                'downloads'       => $b['downloads'],
                'watch_time'      => $b['watch_seconds'],
                'completion_rate' => $b['completion_rate'],
                'favorites'       => $b['favorites'],
                'rating'          => $b['rating_avg'],
                'title'           => strtolower($b['title']),
                default           => $b['plays'],
            };

            if ($valA == $valB) {
                return 0;
            }
            if ($sortOrder === 'ASC') {
                return ($valA < $valB) ? -1 : 1;
            }
            return ($valA > $valB) ? -1 : 1;
        });

        return [
            'items'        => $results,
            'total'        => $totalItems,
            'content_type' => $contentType,
            'date_filter'  => $df,
            'sort_by'      => $sortBy,
            'sort_order'   => $sortOrder,
        ];
    }

    /**
     * Get detailed analytics for a single movie.
     */
    public static function getMovieAnalytics(int $movieId, array $dateFilter = []): ?array
    {
        $movie = Movie::find($movieId);
        if (!$movie) {
            return null;
        }

        $res = self::getContentPerformance('movie', $dateFilter, 1, 0, 'plays', 'DESC');
        $itemStats = null;
        foreach ($res['items'] as $it) {
            if ($it['id'] === $movieId) {
                $itemStats = $it;
                break;
            }
        }

        // Drop-off buckets
        $dropOff = self::getCompletionAndDropOff('movie', $movieId, $dateFilter);

        return [
            'movie'    => $movie,
            'stats'    => $itemStats ?? [
                'id' => $movieId, 'title' => $movie->title, 'plays' => 0, 'views' => 0, 'downloads' => 0,
                'unique_users' => 0, 'watch_seconds' => 0, 'completion_rate' => 0.0, 'favorites' => 0,
                'rating_avg' => 0.0, 'rating_count' => 0,
            ],
            'drop_off' => $dropOff,
        ];
    }

    /**
     * Get aggregate analytics for a Series across all its seasons and episodes.
     */
    public static function getSeriesAnalytics(int $seriesId, array $dateFilter = []): ?array
    {
        $series = Series::find($seriesId);
        if (!$series) {
            return null;
        }

        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        // 1. Series-level views
        $seriesViews = (int)($db->selectOne("
            SELECT COUNT(*) as c
            FROM multimedia_analytics
            WHERE content_type = 'series' AND content_id = ?
              AND created_at >= ? AND created_at <= ?
        ", [$seriesId, $start, $end])->c ?? 0);

        // 2. Fetch all episodes of the series
        $episodes = $db->select("
            SELECT id, season_id, episode_number, title
            FROM multimedia_episodes
            WHERE series_id = ?
            ORDER BY season_id ASC, episode_number ASC
        ", [$seriesId]);

        $episodeCount = count($episodes);
        $totalEpisodePlays = 0;
        $uniqueSeriesViewers = 0;
        $mostWatchedEpisode = null;
        $dropOffEpisode = null;
        $epStats = [];

        if ($episodeCount > 0) {
            $epIds = array_map(fn($e) => (int)$e->id, $episodes);
            $ph = implode(',', array_fill(0, count($epIds), '?'));

            // Total plays across episodes
            $epPlayRows = $db->select("
                SELECT content_id, COUNT(*) as plays, COUNT(DISTINCT user_id) as uniq_users
                FROM multimedia_analytics
                WHERE content_type = 'episode'
                  AND content_id IN ({$ph})
                  AND event_type = 'play'
                  AND created_at >= ? AND created_at <= ?
                GROUP BY content_id
            ", array_merge($epIds, [$start, $end]));

            $playMap = [];
            foreach ($epPlayRows as $er) {
                $playMap[(int)$er->content_id] = (int)$er->plays;
            }

            // Overall unique viewers across all episodes without duplicate counting
            $uniqueSeriesViewers = (int)($db->selectOne("
                SELECT COUNT(DISTINCT user_id) as c
                FROM multimedia_analytics
                WHERE content_type = 'episode'
                  AND content_id IN ({$ph})
                  AND user_id IS NOT NULL AND user_id > 0
                  AND created_at >= ? AND created_at <= ?
            ", array_merge($epIds, [$start, $end]))->c ?? 0);

            $maxPlays = -1;
            $minPlays = PHP_INT_MAX;
            foreach ($episodes as $idx => $ep) {
                $p = $playMap[(int)$ep->id] ?? 0;
                $totalEpisodePlays += $p;
                $epStats[] = [
                    'episode_id'     => (int)$ep->id,
                    'episode_number' => (int)$ep->episode_number,
                    'title'          => $ep->title,
                    'plays'          => $p,
                ];
                if ($p > $maxPlays) {
                    $maxPlays = $p;
                    $mostWatchedEpisode = $ep;
                }
                if ($p < $minPlays && $idx > 0) { // drop off after first episode
                    $minPlays = $p;
                    $dropOffEpisode = $ep;
                }
            }
        }

        // Favorites for series
        $favorites = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_favorites
            WHERE content_type = 'series' AND content_id = ?
        ", [$seriesId])->c ?? 0);

        // Subscriptions/followers for series
        $followers = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_subscriptions
            WHERE target_type = 'series' AND target_id = ?
        ", [$seriesId])->c ?? 0);

        return [
            'series'                => $series,
            'series_views'          => $seriesViews,
            'total_episode_plays'   => $totalEpisodePlays,
            'unique_viewers'        => $uniqueSeriesViewers,
            'episode_count'         => $episodeCount,
            'episodes'              => $epStats,
            'most_watched_episode'  => $mostWatchedEpisode,
            'drop_off_episode'      => $dropOffEpisode,
            'favorites'             => $favorites,
            'followers'             => $followers,
        ];
    }

    /**
     * Get detailed analytics for a single Episode.
     */
    public static function getEpisodeAnalytics(int $episodeId, array $dateFilter = []): ?array
    {
        $episode = Episode::find($episodeId);
        if (!$episode) {
            return null;
        }

        $res = self::getContentPerformance('episode', $dateFilter, 1, 0, 'plays', 'DESC');
        $itemStats = null;
        foreach ($res['items'] as $it) {
            if ($it['id'] === $episodeId) {
                $itemStats = $it;
                break;
            }
        }

        $dropOff = self::getCompletionAndDropOff('episode', $episodeId, $dateFilter);

        return [
            'episode'  => $episode,
            'stats'    => $itemStats ?? ['id' => $episodeId, 'title' => $episode->title, 'plays' => 0],
            'drop_off' => $dropOff,
        ];
    }

    /**
     * Get detailed analytics for a Song.
     */
    public static function getSongAnalytics(int $songId, array $dateFilter = []): ?array
    {
        $song = Song::find($songId);
        if (!$song) {
            return null;
        }

        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        $plays = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_analytics
            WHERE content_type = 'song' AND content_id = ? AND event_type = 'play'
              AND created_at >= ? AND created_at <= ?
        ", [$songId, $start, $end])->c ?? 0);

        $listeners = (int)($db->selectOne("
            SELECT COUNT(DISTINCT user_id) as c FROM multimedia_analytics
            WHERE content_type = 'song' AND content_id = ? AND user_id > 0
              AND created_at >= ? AND created_at <= ?
        ", [$songId, $start, $end])->c ?? 0);

        $favorites = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_favorites
            WHERE content_type = 'song' AND content_id = ?
        ", [$songId])->c ?? 0);

        $playlistAppearances = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_playlist_items
            WHERE song_id = ?
        ", [$songId])->c ?? 0);

        // Completion for songs: completed sessions / total sessions
        $prog = $db->selectOne("
            SELECT COUNT(*) as total_s, SUM(CASE WHEN is_completed = 1 OR percentage >= 90 THEN 1 ELSE 0 END) as comp_s
            FROM multimedia_playback_progress
            WHERE content_type = 'song' AND content_id = ?
              AND last_played_at >= ? AND last_played_at <= ?
        ", [$songId, $start, $end]);

        $totSessions = (int)($prog->total_s ?? 0);
        $compSessions = (int)($prog->comp_s ?? 0);
        $completionRate = $totSessions > 0 ? round(($compSessions / $totSessions) * 100, 1) : 0.0;

        return [
            'song'                 => $song,
            'plays'                => $plays,
            'unique_listeners'     => $listeners,
            'favorites'            => $favorites,
            'playlist_appearances' => $playlistAppearances,
            'completion_rate'      => $completionRate,
        ];
    }

    /**
     * Get detailed analytics for a Playlist.
     */
    public static function getPlaylistAnalytics(int $playlistId, array $dateFilter = []): ?array
    {
        $playlist = Playlist::find($playlistId);
        if (!$playlist) {
            return null;
        }

        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        $views = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_analytics
            WHERE content_type = 'playlist' AND content_id = ? AND event_type = 'view'
              AND created_at >= ? AND created_at <= ?
        ", [$playlistId, $start, $end])->c ?? 0);

        $trackCount = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_playlist_items
            WHERE playlist_id = ?
        ", [$playlistId])->c ?? 0);

        $followers = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_subscriptions
            WHERE target_type = 'playlist' AND target_id = ?
        ", [$playlistId])->c ?? 0);

        return [
            'playlist'    => $playlist,
            'views'       => $views,
            'track_count' => $trackCount,
            'followers'   => $followers,
        ];
    }

    /**
     * Audience drop-off analysis classified into lightweight percentage progress buckets.
     */
    public static function getCompletionAndDropOff(string $contentType, ?int $contentId = null, array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        $sql = "
            SELECT 
                SUM(CASE WHEN percentage >= 0 AND percentage < 10 THEN 1 ELSE 0 END) as b_0_10,
                SUM(CASE WHEN percentage >= 10 AND percentage < 25 THEN 1 ELSE 0 END) as b_10_25,
                SUM(CASE WHEN percentage >= 25 AND percentage < 50 THEN 1 ELSE 0 END) as b_25_50,
                SUM(CASE WHEN percentage >= 50 AND percentage < 75 THEN 1 ELSE 0 END) as b_50_75,
                SUM(CASE WHEN percentage >= 75 AND percentage < 90 THEN 1 ELSE 0 END) as b_75_90,
                SUM(CASE WHEN percentage >= 90 OR is_completed = 1 THEN 1 ELSE 0 END) as b_90_100,
                COUNT(*) as total_count
            FROM multimedia_playback_progress
            WHERE content_type = ?
              AND last_played_at >= ? AND last_played_at <= ?
        ";
        $params = [$contentType, $start, $end];
        if ($contentId !== null && $contentId > 0) {
            $sql = str_replace("WHERE content_type = ?", "WHERE content_type = ? AND content_id = ?", $sql);
            $params = [$contentType, $contentId, $start, $end];
        }

        $row = $db->selectOne($sql, $params);
        $total = (int)($row->total_count ?? 0);

        $buckets = [
            '0_10'   => (int)($row->b_0_10 ?? 0),
            '10_25'  => (int)($row->b_10_25 ?? 0),
            '25_50'  => (int)($row->b_25_50 ?? 0),
            '50_75'  => (int)($row->b_50_75 ?? 0),
            '75_90'  => (int)($row->b_75_90 ?? 0),
            '90_100' => (int)($row->b_90_100 ?? 0), // Canonical Completed
        ];

        $percentages = [];
        foreach ($buckets as $k => $cnt) {
            $percentages[$k] = $total > 0 ? round(($cnt / $total) * 100, 1) : 0.0;
        }

        return [
            'total_sessions' => $total,
            'buckets'        => $buckets,
            'percentages'    => $percentages,
        ];
    }

    /**
     * Resume engagement: partially consumed items that were resumed.
     */
    public static function getResumeEngagement(array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        $row = $db->selectOne("
            SELECT 
                COUNT(*) as total_records,
                COALESCE(SUM(CASE WHEN position > 0 AND is_completed = 0 AND percentage < 90 THEN 1 ELSE 0 END), 0) as partial_sessions,
                COALESCE(SUM(CASE WHEN is_completed = 1 OR percentage >= 90 THEN 1 ELSE 0 END), 0) as completed_sessions
            FROM multimedia_playback_progress
            WHERE last_played_at >= ? AND last_played_at <= ?
        ", [$start, $end]);

        $total = (int)($row->total_records ?? 0);
        $partial = (int)($row->partial_sessions ?? 0);
        $completed = (int)($row->completed_sessions ?? 0);
        $resumeRate = $total > 0 ? round(($partial / $total) * 100, 1) : 0.0;

        return [
            'total_records'    => $total,
            'partial_sessions' => $partial,
            'completed'        => $completed,
            'resume_rate'      => $resumeRate,
        ];
    }

    /**
     * Next-Episode Continuation Rate for a Series.
     * Measures users who completed an episode and started the subsequent episode.
     */
    public static function getNextEpisodeContinuation(int $seriesId, array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        $episodes = $db->select("
            SELECT id, episode_number
            FROM multimedia_episodes
            WHERE series_id = ?
            ORDER BY season_id ASC, episode_number ASC
        ", [$seriesId]);

        if (count($episodes) < 2) {
            return ['eligible_pairs' => 0, 'continued_count' => 0, 'continuation_rate' => 0.0];
        }

        $ep1 = (int)$episodes[0]->id;
        $ep2 = (int)$episodes[1]->id;

        // Users who completed Ep 1
        $ep1Users = $db->select("
            SELECT user_id
            FROM multimedia_playback_progress
            WHERE content_type = 'episode' AND content_id = ?
              AND (is_completed = 1 OR percentage >= 90)
              AND user_id > 0
              AND last_played_at >= ? AND last_played_at <= ?
        ", [$ep1, $start, $end]);

        $ep1UserIds = array_map(fn($u) => (int)$u->user_id, $ep1Users);
        if (empty($ep1UserIds)) {
            return ['eligible_pairs' => 0, 'continued_count' => 0, 'continuation_rate' => 0.0];
        }

        $ph = implode(',', array_fill(0, count($ep1UserIds), '?'));
        $continuedUsers = (int)($db->selectOne("
            SELECT COUNT(DISTINCT user_id) as c
            FROM multimedia_playback_progress
            WHERE content_type = 'episode' AND content_id = ?
              AND user_id IN ({$ph})
              AND position > 0
        ", array_merge([$ep2], $ep1UserIds))->c ?? 0);

        $eligible = count($ep1UserIds);
        $rate = $eligible > 0 ? round(($continuedUsers / $eligible) * 100, 1) : 0.0;

        return [
            'eligible_pairs'    => $eligible,
            'continued_count'   => $continuedUsers,
            'continuation_rate' => $rate,
        ];
    }

    /**
     * Rating distribution and community review status breakdown.
     */
    public static function getRatingAndReviewAnalytics(array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        // Rating distribution 1 to 5 stars
        $ratingRows = $db->select("
            SELECT rating, COUNT(*) as c
            FROM multimedia_ratings
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY rating
        ", [$start, $end]);

        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $totalRatings = 0;
        $sumRatings = 0;
        foreach ($ratingRows as $rr) {
            $r = (int)$rr->rating;
            $cnt = (int)$rr->c;
            if ($r >= 1 && $r <= 5) {
                $dist[$r] = $cnt;
                $totalRatings += $cnt;
                $sumRatings += ($r * $cnt);
            }
        }
        $avgRating = $totalRatings > 0 ? round($sumRatings / $totalRatings, 2) : 0.0;

        // Review statuses
        $reviewsPending = 0;
        $reviewsApproved = 0;
        $reviewsRejected = 0;
        try {
            $revRows = $db->select("
                SELECT status, COUNT(*) as c
                FROM multimedia_reviews
                WHERE created_at >= ? AND created_at <= ?
                GROUP BY status
            ", [$start, $end]);
            foreach ($revRows as $rev) {
                if ($rev->status === 'pending') {
                    $reviewsPending = (int)$rev->c;
                } elseif ($rev->status === 'approved') {
                    $reviewsApproved = (int)$rev->c;
                } elseif ($rev->status === 'rejected') {
                    $reviewsRejected = (int)$rev->c;
                }
            }
        } catch (\Throwable) {
        }

        return [
            'total_ratings'    => $totalRatings,
            'avg_rating'       => $avgRating,
            'distribution'     => $dist,
            'reviews_pending'  => $reviewsPending,
            'reviews_approved' => $reviewsApproved,
            'reviews_rejected' => $reviewsRejected,
        ];
    }

    /**
     * Follower / subscriber growth across Series, Artists, and Playlists.
     */
    public static function getFollowerGrowth(array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        $rows = $db->select("
            SELECT target_type, COUNT(*) as c
            FROM multimedia_subscriptions
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY target_type
        ", [$start, $end]);

        $growth = ['series' => 0, 'artist' => 0, 'playlist' => 0];
        $total = 0;
        foreach ($rows as $r) {
            $t = (string)$r->target_type;
            if (isset($growth[$t])) {
                $growth[$t] = (int)$r->c;
                $total += (int)$r->c;
            }
        }

        return [
            'total_growth' => $total,
            'by_target'    => $growth,
        ];
    }

    /**
     * Notification performance: generated vs read rate.
     */
    public static function getNotificationAnalytics(array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        try {
            $row = $db->selectOne("
                SELECT 
                    COUNT(*) as total_notifications,
                    COALESCE(SUM(CASE WHEN is_read = 1 OR read_at IS NOT NULL THEN 1 ELSE 0 END), 0) as read_notifications
                FROM multimedia_notifications
                WHERE created_at >= ? AND created_at <= ?
            ", [$start, $end]);

            $total = (int)($row->total_notifications ?? 0);
            $read  = (int)($row->read_notifications ?? 0);
            $readRate = $total > 0 ? round(($read / $total) * 100, 1) : 0.0;
        } catch (\Throwable) {
            $total = 0;
            $read  = 0;
            $readRate = 0.0;
        }

        return [
            'total_generated' => $total,
            'read_count'      => $read,
            'read_rate'       => $readRate,
        ];
    }

    /**
     * Discovery attribution performance based on whitelisted discovery sources.
     */
    public static function getDiscoveryPerformance(array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        $sources = AnalyticsEvent::ALLOWED_SOURCES;
        $results = [];
        foreach ($sources as $s) {
            $results[$s] = ['plays' => 0, 'views' => 0];
        }
        $results['direct'] = ['plays' => 0, 'views' => 0];

        try {
            $rows = $db->select("
                SELECT discovery_source, event_type, COUNT(*) as c
                FROM multimedia_analytics
                WHERE created_at >= ? AND created_at <= ?
                  AND event_type IN ('play', 'view')
                GROUP BY discovery_source, event_type
            ", [$start, $end]);

            foreach ($rows as $r) {
                $src = $r->discovery_source ?: 'direct';
                if (!isset($results[$src])) {
                    $src = 'direct';
                }
                if ($r->event_type === 'play') {
                    $results[$src]['plays'] += (int)$r->c;
                } elseif ($r->event_type === 'view') {
                    $results[$src]['views'] += (int)$r->c;
                }
            }
        } catch (\Throwable) {
            // Column may not exist in older schema
        }

        return $results;
    }

    /**
     * Release performance: initial 24 hours and 7 days consumption for newly published content.
     */
    public static function getReleasePerformance(string $contentType, int $contentId): array
    {
        $db = self::db();
        $tableMap = [
            'movie'    => 'multimedia_movies',
            'series'   => 'multimedia_series',
            'episode'  => 'multimedia_episodes',
            'song'     => 'multimedia_songs',
            'playlist' => 'multimedia_playlists',
        ];
        $tbl = $tableMap[$contentType] ?? 'multimedia_movies';

        $item = $db->selectOne("SELECT id, title, published_at, created_at FROM `{$tbl}` WHERE id = ?", [$contentId]);
        if (!$item) {
            return ['error' => 'Content not found'];
        }

        $pubTimeStr = $item->published_at ?: $item->created_at;
        $pubTimestamp = strtotime((string)$pubTimeStr);
        if ($pubTimestamp <= 0) {
            return ['error' => 'Invalid publication timestamp'];
        }

        $start = gmdate('Y-m-d H:i:s', $pubTimestamp);
        $end24h = gmdate('Y-m-d H:i:s', $pubTimestamp + 86400);
        $end7d  = gmdate('Y-m-d H:i:s', $pubTimestamp + (7 * 86400));

        // Plays 24h
        $plays24h = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_analytics
            WHERE content_type = ? AND content_id = ? AND event_type = 'play'
              AND created_at >= ? AND created_at <= ?
        ", [$contentType, $contentId, $start, $end24h])->c ?? 0);

        // Plays 7d
        $plays7d = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_analytics
            WHERE content_type = ? AND content_id = ? AND event_type = 'play'
              AND created_at >= ? AND created_at <= ?
        ", [$contentType, $contentId, $start, $end7d])->c ?? 0);

        // Favorites 7d
        $favs7d = (int)($db->selectOne("
            SELECT COUNT(*) as c FROM multimedia_favorites
            WHERE content_type = ? AND content_id = ?
              AND created_at >= ? AND created_at <= ?
        ", [$contentType, $contentId, $start, $end7d])->c ?? 0);

        return [
            'content_type'   => $contentType,
            'content_id'     => $contentId,
            'title'          => $item->title,
            'published_at'   => $pubTimeStr,
            'plays_first_24h'=> $plays24h,
            'plays_first_7d' => $plays7d,
            'favs_first_7d'  => $favs7d,
        ];
    }

    /**
     * Premium access funnel & gatekeeper metrics.
     * Observes outcome only: attempts -> denials -> entitled plays.
     * Favorite Digital remains sole entitlement authority.
     */
    public static function getPremiumFunnel(array $dateFilter = []): array
    {
        $db = self::db();
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $start = $df['start'];
        $end   = $df['end'];

        // 1. Premium plays (content with access_mode = 'premium')
        $premiumPlays = (int)($db->selectOne("
            SELECT COUNT(*) as c
            FROM multimedia_analytics a
            JOIN multimedia_movies m ON a.content_type = 'movie' AND a.content_id = m.id
            WHERE m.access_mode = 'premium'
              AND a.event_type = 'play'
              AND a.created_at >= ? AND a.created_at <= ?
        ", [$start, $end])->c ?? 0);

        // 2. Premium denials (access blocked due to missing/invalid Favorite Digital entitlement)
        $premiumDenials = (int)($db->selectOne("
            SELECT COUNT(*) as c
            FROM multimedia_analytics
            WHERE event_type = 'premium_denied'
              AND created_at >= ? AND created_at <= ?
        ", [$start, $end])->c ?? 0);

        $totalAttempts = $premiumPlays + $premiumDenials;
        $conversionRate = $totalAttempts > 0 ? round(($premiumPlays / $totalAttempts) * 100, 1) : 0.0;

        return [
            'total_attempts'  => $totalAttempts,
            'entitled_plays'  => $premiumPlays,
            'premium_denials' => $premiumDenials,
            'conversion_rate' => $conversionRate,
            'authority_note'  => 'Favorite Digital is the exclusive entitlement authority. Payment in Favorite Pay does not grant playback without active entitlement.',
        ];
    }

    /**
     * Aggregate language, audio track, and subtitle usage (privacy-safe, no user profiling).
     */
    public static function getLanguageUsageAnalytics(array $dateFilter = []): array
    {
        $db = self::db();

        // 1. User preferences distribution
        $prefRows = [];
        try {
            $prefRows = $db->select("
                SELECT preferred_audio_language, preferred_subtitle_language, subtitle_enabled
                FROM multimedia_user_language_preferences
            ");
        } catch (\Throwable) {
        }

        $audioMap = [];
        $subMap = [];
        $subEnabledCount = 0;
        $totalPrefs = count($prefRows);

        foreach ($prefRows as $p) {
            $aLang = $p->preferred_audio_language ? MediaLanguageService::normalizeLanguageCode($p->preferred_audio_language) : 'default';
            $sLang = $p->preferred_subtitle_language ? MediaLanguageService::normalizeLanguageCode($p->preferred_subtitle_language) : 'default';
            $audioMap[$aLang] = ($audioMap[$aLang] ?? 0) + 1;
            $subMap[$sLang]   = ($subMap[$sLang] ?? 0) + 1;
            if (!empty($p->subtitle_enabled)) {
                $subEnabledCount++;
            }
        }

        $subEnabledPercent = $totalPrefs > 0 ? round(($subEnabledCount / $totalPrefs) * 100, 1) : 100.0;

        // Convert language codes to canonical labels
        $audioDistribution = [];
        foreach ($audioMap as $code => $count) {
            $label = $code === 'default' ? 'Default / Original' : MediaLanguageService::getLanguageLabel($code);
            $audioDistribution[] = [
                'code'       => $code,
                'label'      => $label,
                'count'      => $count,
                'percentage' => $totalPrefs > 0 ? round(($count / $totalPrefs) * 100, 1) : 0.0,
            ];
        }

        $subDistribution = [];
        foreach ($subMap as $code => $count) {
            $label = $code === 'default' ? 'Default' : MediaLanguageService::getLanguageLabel($code);
            $subDistribution[] = [
                'code'       => $code,
                'label'      => $label,
                'count'      => $count,
                'percentage' => $totalPrefs > 0 ? round(($count / $totalPrefs) * 100, 1) : 0.0,
            ];
        }

        return [
            'total_users_with_prefs'  => $totalPrefs,
            'subtitle_enabled_rate'   => $subEnabledPercent,
            'audio_distribution'      => $audioDistribution,
            'subtitle_distribution'   => $subDistribution,
        ];
    }

    /**
     * Operational metrics: FFmpeg transcoding jobs and storage utilization.
     */
    public static function getProcessingAndStorageMetrics(): array
    {
        $db = self::db();

        // 1. Processing jobs stats
        $jobsCompleted = 0;
        $jobsFailed = 0;
        $jobsPending = 0;
        try {
            $jobStats = $db->selectOne("
                SELECT 
                    COUNT(*) as total_jobs,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) as completed,
                    COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed,
                    COALESCE(SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END), 0) as pending
                FROM multimedia_processing_jobs
            ");
            if ($jobStats) {
                $jobsCompleted = (int)$jobStats->completed;
                $jobsFailed    = (int)$jobStats->failed;
                $jobsPending   = (int)$jobStats->pending;
            }
        } catch (\Throwable) {
        }

        $totalFinished = $jobsCompleted + $jobsFailed;
        $failureRate = $totalFinished > 0 ? round(($jobsFailed / $totalFinished) * 100, 1) : 0.0;

        // 2. Storage utilization stats
        $localBytes = 0;
        $s3Bytes = 0;
        $localFiles = 0;
        $s3Files = 0;
        try {
            $storageRows = $db->select("
                SELECT storage_driver, COUNT(*) as file_count, COALESCE(SUM(file_size), 0) as total_size
                FROM multimedia_storage_files
                WHERE is_orphan = 0
                GROUP BY storage_driver
            ");
            foreach ($storageRows as $sr) {
                if ($sr->storage_driver === 's3') {
                    $s3Files = (int)$sr->file_count;
                    $s3Bytes = (int)$sr->total_size;
                } else {
                    $localFiles = (int)$sr->file_count;
                    $localBytes = (int)$sr->total_size;
                }
            }
        } catch (\Throwable) {
        }

        return [
            'processing' => [
                'completed'       => $jobsCompleted,
                'failed'          => $jobsFailed,
                'pending'         => $jobsPending,
                'failure_rate'    => $failureRate,
            ],
            'storage' => [
                'local_files'     => $localFiles,
                'local_bytes'     => $localBytes,
                'local_formatted' => self::formatBytes($localBytes),
                's3_files'        => $s3Files,
                's3_bytes'        => $s3Bytes,
                's3_formatted'    => self::formatBytes($s3Bytes),
                'total_bytes'     => $localBytes + $s3Bytes,
                'total_formatted' => self::formatBytes($localBytes + $s3Bytes),
            ],
        ];
    }

    /**
     * Export aggregated analytics to CSV with UTF-8 BOM and Formula Injection Protection.
     */
    public static function exportCsv(string $reportType, array $dateFilter = [], string $contentType = 'movie'): string
    {
        $df = empty($dateFilter) ? self::resolveDateRange() : $dateFilter;
        $data = [];
        $headers = [];

        if ($reportType === 'content_performance') {
            $res = self::getContentPerformance($contentType, $df, 500, 0, 'plays', 'DESC');
            $headers = ['ID', 'Title', 'Access Mode', 'Status', 'Plays', 'Views', 'Downloads', 'Unique Users', 'Watch Time (s)', 'Completion Rate (%)', 'Favorites', 'Rating'];
            foreach ($res['items'] as $item) {
                $data[] = [
                    $item['id'],
                    $item['title'],
                    $item['access_mode'],
                    $item['status'],
                    $item['plays'],
                    $item['views'],
                    $item['downloads'],
                    $item['unique_users'],
                    $item['watch_seconds'],
                    $item['completion_rate'],
                    $item['favorites'],
                    $item['rating_avg'],
                ];
            }
        } elseif ($reportType === 'drop_off') {
            $res = self::getCompletionAndDropOff($contentType, null, $df);
            $headers = ['Progress Bucket', 'Sessions', 'Percentage (%)'];
            $labels = [
                '0_10'   => '0% - 10%',
                '10_25'  => '10% - 25%',
                '25_50'  => '25% - 50%',
                '50_75'  => '50% - 75%',
                '75_90'  => '75% - 90%',
                '90_100' => '90% - 100% (Completed)',
            ];
            foreach ($res['buckets'] as $bucket => $cnt) {
                $data[] = [
                    $labels[$bucket] ?? $bucket,
                    $cnt,
                    $res['percentages'][$bucket] ?? 0.0,
                ];
            }
        } else { // default 'overview'
            $res = self::getOverviewStats($df);
            $headers = ['Metric', 'Value'];
            $data = [
                ['Date Range', $df['label']],
                ['Total Plays', $res['total_plays']],
                ['Total Views', $res['total_views']],
                ['Total Downloads', $res['total_downloads']],
                ['Unique Authenticated Users', $res['unique_users']],
                ['Unique Guest Sessions', $res['unique_sessions']],
                ['Total Watch Time', $res['watch_time_formatted']],
                ['Completion Rate (%)', $res['completion_rate'] . '%'],
                ['Total Favorites', $res['favorites']],
                ['Total Followers', $res['followers']],
                ['Average Rating', $res['avg_rating']],
            ];
        }

        // Generate CSV string with UTF-8 BOM
        $out = "\xEF\xBB\xBF"; // UTF-8 BOM
        $stream = fopen('php://temp', 'r+');

        // Write Header with explicit escape parameter
        fputcsv($stream, array_map([self::class, 'sanitizeCsvCell'], $headers), ',', '"', "\\");

        // Write Data with explicit escape parameter
        foreach ($data as $row) {
            $cleanRow = array_map([self::class, 'sanitizeCsvCell'], $row);
            fputcsv($stream, $cleanRow, ',', '"', "\\");
        }

        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);

        return $out . (string)$csvContent;
    }

    /**
     * Prevent CSV Formula Injection (CWE-1236).
     * Disarms strings starting with =, +, -, @, \t, or \r.
     */
    public static function sanitizeCsvCell(mixed $value): string
    {
        $str = (string)$value;
        if ($str === '') {
            return '';
        }

        // Check dangerous leading characters for formula injection
        $firstChar = $str[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }

        return $str;
    }

    /**
     * Format duration in seconds to human readable string (e.g. 2h 15m 30s).
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $hours = (int)floor($seconds / 3600);
        $minutes = (int)floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0 || $hours > 0) {
            $parts[] = "{$minutes}m";
        }
        $parts[] = "{$secs}s";

        return implode(' ', $parts);
    }

    /**
     * Format bytes to human readable format (KB, MB, GB).
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int)floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        $size = round($bytes / pow(1024, $i), 2);

        return "{$size} {$units[$i]}";
    }

    protected static function db(): Database
    {
        return Container::getInstance()->get(Database::class);
    }
}
