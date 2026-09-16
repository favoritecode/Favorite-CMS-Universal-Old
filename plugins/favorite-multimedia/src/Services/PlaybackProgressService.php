<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;

class PlaybackProgressService
{
    public const COMPLETION_THRESHOLD_PERCENT = 90.0;
    public const MIN_RESUME_SECONDS = 5.0;

    /**
     * Save playback progress for an authenticated user.
     *
     * @return array{success: bool, error?: string, code?: int, position?: float, duration?: float, percentage?: float, is_completed?: bool}
     */
    public static function saveProgress(
        ?User $user,
        string $contentType,
        int $contentId,
        float $position,
        float $duration,
        bool $forceCompleted = false
    ): array {
        if (!$user || (int)$user->id <= 0) {
            return ['success' => false, 'error' => 'Authentication required.', 'code' => 401];
        }

        if (!in_array($contentType, PlaybackProgress::ALLOWED_TYPES, true)) {
            return ['success' => false, 'error' => 'Unsupported content type.', 'code' => 400];
        }

        if ($contentId <= 0) {
            return ['success' => false, 'error' => 'Invalid content ID.', 'code' => 400];
        }

        // Validate content exists
        $model = MultimediaAccessService::findContentModel($contentType, $contentId);
        if (!$model) {
            return ['success' => false, 'error' => 'Content not found.', 'code' => 404];
        }

        // Verify viewing access permission
        $accessState = MultimediaAccessService::checkAccess($user, $contentType, $model);
        if ($accessState !== MultimediaAccessService::ALLOW) {
            return ['success' => false, 'error' => 'Access denied to this media content.', 'code' => 403];
        }

        // Sanitize and validate numeric bounds
        $position = max(0.0, $position);
        $duration = max(0.0, $duration);

        // Sanity limit: max 24 hours (86400 seconds)
        if ($duration > 86400.0) {
            return ['success' => false, 'error' => 'Duration exceeds allowable threshold.', 'code' => 400];
        }

        if ($duration > 0.0 && $position > ($duration + 5.0)) {
            $position = $duration;
        }

        $percentage = $duration > 0.0 ? min(100.0, round(($position / $duration) * 100.0, 2)) : 0.0;
        $isCompleted = $forceCompleted || ($percentage >= self::COMPLETION_THRESHOLD_PERCENT);

        $saved = PlaybackProgress::recordProgress(
            (int)$user->id,
            $contentType,
            $contentId,
            $position,
            $duration,
            $percentage,
            $isCompleted
        );

        return [
            'success'      => true,
            'position'     => (float)$saved->position,
            'duration'     => (float)$saved->duration,
            'percentage'   => (float)$saved->percentage,
            'is_completed' => (bool)$saved->is_completed,
        ];
    }

    /**
     * Retrieve playback progress and resume recommendation for content.
     */
    public static function getProgress(?User $user, string $contentType, int $contentId): array
    {
        $default = [
            'has_progress'  => false,
            'position'      => 0.0,
            'duration'      => 0.0,
            'percentage'    => 0.0,
            'is_completed'  => false,
            'should_resume' => false,
            'last_played_at'=> null,
        ];

        if (!$user || (int)$user->id <= 0) {
            return $default;
        }

        if (!in_array($contentType, PlaybackProgress::ALLOWED_TYPES, true) || $contentId <= 0) {
            return $default;
        }

        // Access check
        $accessState = MultimediaAccessService::checkAccess($user, $contentType, $contentId);
        if ($accessState !== MultimediaAccessService::ALLOW) {
            return array_merge($default, ['access_allowed' => false]);
        }

        $record = PlaybackProgress::findByUserAndContent((int)$user->id, $contentType, $contentId);
        if (!$record) {
            return $default;
        }

        $pos = (float)$record->position;
        $dur = (float)$record->duration;
        $pct = (float)$record->percentage;
        $completed = (bool)$record->is_completed;

        // Resume rule: Resume is only recommended if partially watched (>= 5.0s and not completed)
        $shouldResume = ($pos >= self::MIN_RESUME_SECONDS && !$completed);

        return [
            'has_progress'  => true,
            'position'      => $pos,
            'duration'      => $dur,
            'percentage'    => $pct,
            'is_completed'  => $completed,
            'should_resume' => $shouldResume,
            'last_played_at'=> $record->last_played_at,
            'formatted_position' => self::formatSeconds($pos),
        ];
    }

    /**
     * Get next episode in series sequence and verify user access.
     */
    public static function getNextEpisode(?User $user, int $currentEpisodeId): array
    {
        return NextItemResolverService::resolveNextEpisode($user, $currentEpisodeId, [
            'require_sources'   => false,
            'skip_unauthorized' => false,
        ]);
    }

    /**
     * Compute series-level progress based on episode completions.
     */
    public static function getSeriesProgress(?User $user, int $seriesId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $allEpisodes = $db->select(
            "SELECT e.id, e.season_id, e.episode_number, e.title, e.slug
             FROM multimedia_episodes e
             JOIN multimedia_seasons s ON e.season_id = s.id
             WHERE s.series_id = ? AND e.status = 'published'
             ORDER BY s.season_number ASC, e.episode_number ASC",
            [$seriesId]
        );

        $totalCount = count($allEpisodes);
        if ($totalCount === 0 || !$user || (int)$user->id <= 0) {
            return [
                'total_episodes'   => $totalCount,
                'watched_episodes' => 0,
                'percentage'       => 0.0,
                'last_watched'     => null,
                'next_episode'     => $allEpisodes[0] ?? null,
            ];
        }

        $episodeIds = array_map(fn($e) => (int)$e->id, $allEpisodes);
        $inClause = implode(',', $episodeIds);

        $progressRows = $db->select(
            "SELECT content_id, is_completed, position, duration, last_played_at
             FROM multimedia_playback_progress
             WHERE user_id = ? AND content_type = 'episode' AND content_id IN ({$inClause})
             ORDER BY last_played_at DESC",
            [(int)$user->id]
        );

        $completedCount = 0;
        $completedMap = [];
        $lastWatchedId = null;

        if (!empty($progressRows)) {
            $lastWatchedId = (int)$progressRows[0]->content_id;
            foreach ($progressRows as $row) {
                if ((int)$row->is_completed === 1) {
                    $completedCount++;
                    $completedMap[(int)$row->content_id] = true;
                }
            }
        }

        // Find next uncompleted episode
        $nextEpisode = null;
        foreach ($allEpisodes as $ep) {
            if (!isset($completedMap[(int)$ep->id])) {
                $nextEpisode = $ep;
                break;
            }
        }

        $percentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100.0, 1) : 0.0;

        return [
            'total_episodes'     => $totalCount,
            'watched_episodes'   => $completedCount,
            'completed_episodes' => $completedCount,
            'percentage'         => $percentage,
            'overall_percentage' => $percentage,
            'last_watched_id'    => $lastWatchedId,
            'next_episode'       => $nextEpisode ?: ($allEpisodes[0] ?? null),
        ];
    }

    /**
     * Format seconds into mm:ss or hh:mm:ss string.
     */
    public static function formatSeconds(float $sec): string
    {
        $sec = (int)round($sec);
        $m = (int)floor($sec / 60);
        $s = $sec % 60;
        $h = (int)floor($m / 60);
        $remM = $m % 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $remM, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }
}
