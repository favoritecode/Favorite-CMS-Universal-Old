<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Audio;

use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Song;

/**
 * Manages audio playback session updates, history recording, and Continue Listening progress.
 */
final class AudioPlaybackSession
{
    /**
     * Records throttled playback progress for an authenticated user.
     *
     * @return array{saved: bool, completed: bool, percentage: float}
     */
    public function recordProgress(
        ?User $user,
        int $songId,
        float $position,
        float $duration,
        bool $forceCompleted = false
    ): array {
        if (!$user || (int)$user->id <= 0 || $songId <= 0) {
            return ['saved' => false, 'completed' => false, 'percentage' => 0.0];
        }

        $pos = max(0.0, $position);
        $dur = max(0.0, $duration);
        $percentage = ($dur > 0.0) ? min(100.0, round(($pos / $dur) * 100.0, 2)) : 0.0;
        $isCompleted = $forceCompleted || ($percentage >= 90.0);

        PlaybackProgress::recordProgress(
            (int)$user->id,
            'song',
            $songId,
            $pos,
            $dur,
            $percentage,
            $isCompleted
        );

        if ($isCompleted) {
            AnalyticsEvent::logEvent('song', $songId, 'complete', (int)$user->id);
        }

        return [
            'saved'      => true,
            'completed'  => $isCompleted,
            'percentage' => $percentage,
        ];
    }

    /**
     * Fetches saved progress for Continue Listening resume.
     *
     * @return array{position: float, percentage: float, should_resume: bool}|null
     */
    public function getSavedProgress(?User $user, int $songId): ?array
    {
        if (!$user || (int)$user->id <= 0 || $songId <= 0) {
            return null;
        }

        $progress = PlaybackProgress::findByUserAndContent((int)$user->id, 'song', $songId);
        if (!$progress) {
            return null;
        }

        $pos = (float)($progress->position ?? 0.0);
        $pct = (float)($progress->percentage ?? 0.0);
        $isFinished = !empty($progress->is_completed);

        // Resume if track was partially listened to (between 5s and 90%)
        $shouldResume = (!$isFinished && $pos > 5.0 && $pct < 90.0);

        return [
            'position'      => $pos,
            'percentage'    => $pct,
            'should_resume' => $shouldResume,
        ];
    }

    /**
     * Fetches recently played tracks for authenticated user with deduplication.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentlyPlayed(?User $user, int $limit = 20): array
    {
        if (!$user || (int)$user->id <= 0) {
            return [];
        }

        $records = PlaybackProgress::getHistory((int)$user->id, $limit * 2);
        $results = [];
        $seen = [];

        foreach ($records as $rec) {
            if ((string)$rec->content_type !== 'song') {
                continue;
            }
            $songId = (int)$rec->content_id;
            if (isset($seen[$songId])) {
                continue;
            }
            $seen[$songId] = true;

            $song = Song::find($songId);
            if (!$song || ($song->status ?? 'published') !== 'published') {
                continue;
            }

            $artist = $song->getArtist();
            $results[] = [
                'id'             => $songId,
                'content_type'   => 'song',
                'title'          => (string)$song->title,
                'artist'         => (string)($artist?->name ?: 'Unknown Artist'),
                'cover'          => (string)($song->cover ?? ''),
                'duration'       => (int)($song->duration ?? 0),
                'access_mode'    => (string)($song->access_mode ?? 'public'),
                'last_played_at' => (string)($rec->last_played_at ?? ''),
                'position'       => (float)($rec->position ?? 0.0),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}

