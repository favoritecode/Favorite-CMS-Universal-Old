<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;

class NextItemResolverService
{
    /**
     * Resolve the next playable episode for series playback.
     * Enforces strict separation from single-video failover.
     * Skips draft, scheduled, deleted, and source-less episodes.
     *
     * @param object|null $user Current user or null
     * @param int $currentEpisodeId Current episode ID
     * @param array $options Configuration options:
     *                       - skip_unauthorized (bool, default false): whether to skip past locked episodes
     *                       - max_iterations (int, default 100): safety loop guard
     * @return array Standardized next item response payload
     */
    public static function resolveNextEpisode(?object $user, int $currentEpisodeId, array $options = []): array
    {
        $currentEpisode = Episode::find($currentEpisodeId);
        if (!$currentEpisode) {
            return [
                'found'            => false,
                'has_next'         => false,
                'completed'        => true,
                'series_completed' => false,
                'message'          => 'Current episode not found.',
            ];
        }

        $db = Container::getInstance()->get(Database::class);
        $currentSeason = Season::find((int)$currentEpisode->season_id);
        $series = $currentSeason ? Series::find((int)$currentSeason->series_id) : null;
        $seriesTitle = $series->title ?? 'Series';
        $seriesPoster = $series->poster ?? '';

        $skipUnauthorized = (bool)($options['skip_unauthorized'] ?? false);
        $maxIterations = (int)($options['max_iterations'] ?? 100);

        $visitedEpisodeIds = [$currentEpisodeId => true];
        $iterations = 0;

        // 1. Collect candidate episodes in current season (episode_number > current)
        $currentSeasonCandidates = $db->select(
            "SELECT * FROM multimedia_episodes
             WHERE season_id = ? AND episode_number > ?
             ORDER BY episode_number ASC",
            [(int)$currentEpisode->season_id, (int)$currentEpisode->episode_number]
        );

        $candidatesQueue = [];
        foreach ($currentSeasonCandidates as $row) {
            $candidatesQueue[] = [
                'row'           => $row,
                'season_number' => (int)($currentSeason->season_number ?? 1),
            ];
        }

        // 2. Collect candidate episodes in subsequent seasons of the series
        if ($currentSeason && !empty($currentSeason->series_id)) {
            $nextSeasons = $db->select(
                "SELECT * FROM multimedia_seasons
                 WHERE series_id = ? AND season_number > ?
                 ORDER BY season_number ASC",
                [(int)$currentSeason->series_id, (int)$currentSeason->season_number]
            );

            foreach ($nextSeasons as $sRow) {
                $seasonEps = $db->select(
                    "SELECT * FROM multimedia_episodes
                     WHERE season_id = ?
                     ORDER BY episode_number ASC",
                    [(int)$sRow->id]
                );
                foreach ($seasonEps as $epRow) {
                    $candidatesQueue[] = [
                        'row'           => $epRow,
                        'season_number' => (int)($sRow->season_number ?? 1),
                    ];
                }
            }
        }

        $requireSources = (bool)($options['require_sources'] ?? false);

        // Process candidate queue sequentially
        foreach ($candidatesQueue as $candidate) {
            $iterations++;
            if ($iterations > $maxIterations) {
                break; // Safety loop guard
            }

            $epRow = (array)$candidate['row'];
            $epId = (int)($epRow['id'] ?? 0);
            if ($epId <= 0 || isset($visitedEpisodeIds[$epId])) {
                continue;
            }
            $visitedEpisodeIds[$epId] = true;

            // Skip non-published
            $status = (string)($epRow['status'] ?? 'draft');
            if ($status !== 'published') {
                continue;
            }

            // Skip future scheduled
            $publishAt = $epRow['publish_at'] ?? null;
            if (!empty($publishAt) && strtotime((string)$publishAt) > time()) {
                continue;
            }

            // Verify episode has at least one active playable source if required
            $activeSourceCount = (int)($db->selectOne(
                "SELECT COUNT(*) as c FROM multimedia_sources
                 WHERE content_type = 'episode' AND content_id = ? AND status = 'active'",
                [$epId]
            )->c ?? 0);

            if ($requireSources && $activeSourceCount === 0) {
                continue; // Skip episodes without active streams/embeds
            }

            $epModel = new Episode($epRow);

            // Verify authorization
            $accessState = MultimediaAccessService::checkAccess($user, 'episode', $epModel);
            $isAccessible = ($accessState === MultimediaAccessService::ALLOW);

            if (!$isAccessible) {
                if ($skipUnauthorized) {
                    continue; // Skip past locked episode to find next accessible one
                }

                // Return next item prompt without leaking protected stream URLs
                return [
                    'found'            => true,
                    'has_next'         => true,
                    'completed'        => false,
                    'item_type'        => 'episode',
                    'episode'          => $epModel,
                    'id'               => $epId,
                    'title'            => (string)$epModel->title,
                    'slug'             => (string)$epModel->slug,
                    'episode_number'   => (int)$epModel->episode_number,
                    'season_id'        => (int)$epModel->season_id,
                    'season_number'    => (int)$candidate['season_number'],
                    'series_title'     => $seriesTitle,
                    'series_slug'      => $series->slug ?? '',
                    'thumbnail'        => (string)($epModel->thumbnail ?: $seriesPoster),
                    'poster'           => (string)($epModel->thumbnail ?: $seriesPoster),
                    'url'              => '/episode/' . $epModel->slug,
                    'player_url'       => null,
                    'stream_url'       => null,
                    'is_accessible'    => false,
                    'access_state'     => $accessState,
                    'sources'          => [],
                    'default_source'   => null,
                    'can_auto_play'    => false,
                    'upgrade_url'      => ($accessState === MultimediaAccessService::PREMIUM_REQUIRED)
                        ? FavoriteDigitalAdapter::getSubscriptionUrl()
                        : '/admin/login?redirect=' . urlencode('/episode/' . $epModel->slug),
                ];
            }

            // Accessible episode: resolve canonical playable sources
            $playable = MediaSourcePlaybackService::getPlayableSources($user, 'episode', $epModel);
            $defaultSrc = $playable['default_source'] ?? null;
            $playerUrl = $defaultSrc['url'] ?? ($defaultSrc['stream_url'] ?? (!empty($defaultSrc['id']) ? ('/multimedia/stream/' . $defaultSrc['id']) : null));

            return [
                'found'            => true,
                'has_next'         => true,
                'completed'        => false,
                'item_type'        => 'episode',
                'episode'          => $epModel,
                'id'               => $epId,
                'title'            => (string)$epModel->title,
                'slug'             => (string)$epModel->slug,
                'episode_number'   => (int)$epModel->episode_number,
                'season_id'        => (int)$epModel->season_id,
                'season_number'    => (int)$candidate['season_number'],
                'series_title'     => $seriesTitle,
                'series_slug'      => $series->slug ?? '',
                'thumbnail'        => (string)($epModel->thumbnail ?: $seriesPoster),
                'poster'           => (string)($epModel->thumbnail ?: $seriesPoster),
                'url'              => '/episode/' . $epModel->slug,
                'player_url'       => $playerUrl,
                'stream_url'       => $defaultSrc['stream_url'] ?? null,
                'is_accessible'    => true,
                'access_state'     => MultimediaAccessService::ALLOW,
                'sources'          => $playable['sources'] ?? [],
                'default_source'   => $defaultSrc,
                'can_auto_play'    => !empty($playerUrl),
            ];
        }

        // Clean termination at end of series
        return [
            'found'            => false,
            'has_next'         => false,
            'completed'        => true,
            'series_completed' => true,
            'message'          => 'Series completed.',
        ];
    }

    /**
     * Resolve the next playable item in a playlist (audio or video).
     * Preserves playlist sort order, skips unplayable items, and enforces loop limits.
     *
     * @param object|null $user Current user or null
     * @param int $playlistId Playlist ID
     * @param int $currentSongId Current song ID in playback
     * @param array $options Configuration options:
     *                       - repeat (bool, default false): loop playlist when reaching end
     *                       - is_shuffle (bool, default false): shuffle candidate order
     *                       - skip_unauthorized (bool, default true): skip past locked items
     *                       - max_iterations (int, default 100): safety loop guard
     * @return array Standardized playlist next item response payload
     */
    public static function resolveNextPlaylistItem(?object $user, int $playlistId, int $currentSongId, array $options = []): array
    {
        $playlist = Playlist::find($playlistId);
        if (!$playlist) {
            return [
                'found'              => false,
                'has_next'           => false,
                'completed'          => true,
                'playlist_completed' => false,
                'message'            => 'Playlist not found.',
            ];
        }

        // Verify playlist-level access
        $playlistAccess = MultimediaAccessService::checkAccess($user, 'playlist', $playlist);
        if ($playlistAccess !== MultimediaAccessService::ALLOW) {
            return [
                'found'              => true,
                'has_next'           => false,
                'completed'          => true,
                'playlist_completed' => true,
                'playlist_id'        => $playlistId,
                'playlist_title'     => (string)$playlist->title,
                'is_accessible'      => false,
                'access_state'       => $playlistAccess,
                'message'            => 'Access to playlist denied.',
            ];
        }

        $db = Container::getInstance()->get(Database::class);

        // Fetch ordered playlist items joined with songs
        $rows = $db->select(
            "SELECT pi.id as pli_id, pi.sort_order, s.*
             FROM multimedia_playlist_items pi
             JOIN multimedia_songs s ON pi.song_id = s.id
             WHERE pi.playlist_id = ?
             ORDER BY pi.sort_order ASC, pi.id ASC",
            [$playlistId]
        );

        if (empty($rows)) {
            return [
                'found'              => false,
                'has_next'           => false,
                'completed'          => true,
                'playlist_completed' => true,
                'playlist_id'        => $playlistId,
                'playlist_title'     => (string)$playlist->title,
                'message'            => 'Playlist contains no items.',
            ];
        }

        $items = array_map(fn($r) => (array)$r, $rows);
        $totalItems = count($items);

        // Locate current index
        $currentIndex = -1;
        if ($currentSongId > 0) {
            foreach ($items as $idx => $item) {
                if ((int)$item['id'] === $currentSongId) {
                    $currentIndex = $idx;
                    break;
                }
            }
        }

        $repeat = (bool)($options['repeat'] ?? false);
        $isShuffle = (bool)($options['is_shuffle'] ?? false);
        $skipUnauthorized = (bool)($options['skip_unauthorized'] ?? true);
        $maxIterations = (int)($options['max_iterations'] ?? max(100, $totalItems * 2));

        // Build candidate list
        $candidates = [];
        if ($isShuffle) {
            $pool = [];
            foreach ($items as $idx => $it) {
                if ((int)$it['id'] !== $currentSongId) {
                    $pool[] = $it;
                }
            }
            shuffle($pool);
            $candidates = $pool;
        } elseif ($currentIndex >= 0) {
            // Forward from currentIndex + 1
            for ($i = $currentIndex + 1; $i < $totalItems; $i++) {
                $candidates[] = $items[$i];
            }
            if ($repeat) {
                // Wrap around to start up to currentIndex
                for ($i = 0; $i <= $currentIndex; $i++) {
                    $candidates[] = $items[$i];
                }
            }
        } else {
            // Current item not in playlist: start from 0
            $candidates = $items;
        }

        $visitedIds = [];
        $iterations = 0;

        foreach ($candidates as $cand) {
            $iterations++;
            if ($iterations > $maxIterations) {
                break; // Loop protection
            }

            $songId = (int)($cand['id'] ?? 0);
            if ($songId <= 0 || isset($visitedIds[$songId])) {
                continue;
            }
            // Do not repeat current song immediately in non-repeat mode
            if (!$repeat && $songId === $currentSongId) {
                continue;
            }
            $visitedIds[$songId] = true;

            // Skip non-published
            if (($cand['status'] ?? 'draft') !== 'published') {
                continue;
            }

            // Skip future scheduled
            $pubAt = $cand['publish_at'] ?? null;
            if (!empty($pubAt) && strtotime((string)$pubAt) > time()) {
                continue;
            }

            $requireSources = (bool)($options['require_sources'] ?? true);

            // Verify active source exists if required
            $activeSources = (int)($db->selectOne(
                "SELECT COUNT(*) as c FROM multimedia_sources
                 WHERE content_type = 'song' AND content_id = ? AND status = 'active'",
                [$songId]
            )->c ?? 0);

            if ($requireSources && $activeSources === 0) {
                continue; // Skip source-less songs
            }

            $songModel = new Song($cand);

            // Access check
            $accessState = MultimediaAccessService::checkAccess($user, 'song', $songModel);
            $isAccessible = ($accessState === MultimediaAccessService::ALLOW);

            if (!$isAccessible) {
                if ($skipUnauthorized) {
                    continue; // Skip to next playable song
                }

                // Return locked status without leaking protected source URLs
                return [
                    'found'              => true,
                    'has_next'           => true,
                    'completed'          => false,
                    'playlist_completed' => false,
                    'item_type'          => 'song',
                    'playlist_id'        => $playlistId,
                    'playlist_title'     => (string)$playlist->title,
                    'id'                 => $songId,
                    'title'              => (string)$songModel->title,
                    'slug'               => (string)$songModel->slug,
                    'artist'             => (string)($songModel->artist ?? ''),
                    'cover'              => (string)($songModel->cover ?: $playlist->cover),
                    'thumbnail'          => (string)($songModel->cover ?: $playlist->cover),
                    'url'                => '/song/' . $songModel->slug,
                    'playback_type'      => $songModel->getPlaybackType(),
                    'is_video_only'      => !empty($songModel->is_video_only),
                    'player_url'         => null,
                    'stream_url'         => null,
                    'is_accessible'      => false,
                    'access_state'       => $accessState,
                    'sources'            => [],
                    'default_source'     => null,
                    'can_auto_play'      => false,
                    'is_loop'            => $repeat,
                ];
            }

            // Accessible: resolve canonical playable sources
            $playable = MediaSourcePlaybackService::getPlayableSources($user, 'song', $songModel);
            $defaultSource = $playable['default_source'] ?? null;
            $playerUrl = $defaultSource['url'] ?? ($defaultSource['stream_url'] ?? ('/multimedia/stream/' . ($defaultSource['id'] ?? '')));

            return [
                'found'              => true,
                'has_next'           => true,
                'completed'          => false,
                'playlist_completed' => false,
                'item_type'          => 'song',
                'playlist_id'        => $playlistId,
                'playlist_title'     => (string)$playlist->title,
                'id'                 => $songId,
                'title'              => (string)$songModel->title,
                'slug'               => (string)$songModel->slug,
                'artist'             => (string)($songModel->artist ?? ''),
                'cover'              => (string)($songModel->cover ?: $playlist->cover),
                'thumbnail'          => (string)($songModel->cover ?: $playlist->cover),
                'url'                => '/song/' . $songModel->slug,
                'playback_type'      => $playable['playback_type'] ?? 'audio',
                'is_video_only'      => !empty($songModel->is_video_only),
                'player_url'         => $playerUrl,
                'stream_url'         => $defaultSource['stream_url'] ?? null,
                'is_accessible'      => true,
                'access_state'       => MultimediaAccessService::ALLOW,
                'sources'            => $playable['sources'] ?? [],
                'default_source'     => $defaultSource,
                'can_auto_play'      => true,
                'is_loop'            => $repeat,
            ];
        }

        // Clean playlist completion
        return [
            'found'              => false,
            'has_next'           => false,
            'completed'          => true,
            'playlist_completed' => true,
            'playlist_id'        => $playlistId,
            'playlist_title'     => (string)$playlist->title,
            'message'            => 'Playlist completed.',
        ];
    }
}
