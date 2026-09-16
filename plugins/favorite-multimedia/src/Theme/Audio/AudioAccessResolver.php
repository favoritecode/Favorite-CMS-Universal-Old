<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Audio;

use FavoriteCMS\Core\Logger;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;

/**
 * Access gatekeeper for audio playback.
 * Enforces Public, Login, and Favorite Digital Premium entitlement.
 * Operates strictly fail-closed. Never contacts Favorite Pay for authorization.
 */
final class AudioAccessResolver
{
    /**
     * Resolves authorized playable stream metadata for a song.
     *
     * @param int $songId
     * @param User|null $user
     * @return array<string, mixed>
     */
    public function resolvePlayableSong(int $songId, ?User $user): array
    {
        $song = Song::find($songId);
        if (!$song || ($song->status ?? 'published') !== 'published') {
            return [
                'allowed' => false,
                'reason'  => 'not_found',
                'message' => 'Track not found or is currently unavailable.',
            ];
        }

        // Check scheduled release date
        if (!empty($song->release_date)) {
            $releaseTime = strtotime((string)$song->release_date);
            if ($releaseTime !== false && $releaseTime > time()) {
                return [
                    'allowed' => false,
                    'reason'  => 'scheduled',
                    'message' => 'This track is scheduled for future release.',
                ];
            }
        }

        try {
            $accessState = MultimediaAccessService::checkAccess($user, 'song', $song);
        } catch (\Throwable $e) {
            // Fail-closed on authorization exception
            Logger::error('Favorite Multimedia Audio Access error', ['song_id' => $songId, 'exception' => $e->getMessage()]);
            return [
                'allowed' => false,
                'reason'  => 'forbidden',
                'message' => 'Unable to verify playback permissions. Access denied.',
            ];
        }

        if ($accessState === MultimediaAccessService::LOGIN_REQUIRED) {
            return [
                'allowed'   => false,
                'reason'    => 'login_required',
                'login_url' => '/admin/login?redirect=' . urlencode('/song/' . ($song->slug ?? (string)$songId)),
                'message'   => 'Authentication required to stream this track.',
            ];
        }

        if ($accessState === MultimediaAccessService::PREMIUM_REQUIRED) {
            $subUrl = FavoriteDigitalAdapter::getSubscriptionUrl();
            return [
                'allowed'          => false,
                'reason'           => 'premium_required',
                'subscription_url' => $subUrl,
                'message'          => 'Premium membership required to stream this track.',
            ];
        }

        if ($accessState !== MultimediaAccessService::ALLOW) {
            return [
                'allowed' => false,
                'reason'  => 'forbidden',
                'message' => 'Access denied for this track.',
            ];
        }

        // Resolved allowed: fetch default active audio source
        $source = MediaSource::getDefault('song', $songId, true, 'audio');
        if (!$source) {
            // Fallback to any active source
            $source = MediaSource::getDefault('song', $songId, true);
        }

        if (!$source || $source->status !== 'active') {
            return [
                'allowed' => false,
                'reason'  => 'no_source',
                'message' => 'No active audio stream found for this track.',
            ];
        }

        $artist = $song->getArtist();
        $artistName = $artist?->name ?: 'Unknown Artist';

        return [
            'allowed'     => true,
            'song_id'     => (int)$song->id,
            'title'       => (string)$song->title,
            'artist'      => (string)$artistName,
            'cover'       => (string)($song->cover ?? ''),
            'duration'    => (int)($song->duration ?? 0),
            'stream_url'  => '/multimedia/stream/' . (int)$source->id,
            'mime_type'   => (string)($source->mime_type ?? 'audio/mpeg'),
            'player_type' => (string)($source->player_type ?? 'html5'),
            'source_id'   => (int)$source->id,
            'has_lyrics'  => !empty($song->lyrics),
            'lyrics'      => (string)($song->lyrics ?? ''),
        ];
    }
}

