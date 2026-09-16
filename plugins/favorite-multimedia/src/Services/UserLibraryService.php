<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Favorite;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;

class UserLibraryService
{
    /**
     * Helper to resolve User instance from User|int|null.
     */
    private static function resolveUser(User|int|null $user): ?User
    {
        if (is_int($user)) {
            return User::find($user);
        }
        return $user;
    }

    /**
     * Get hydrated, access-verified Continue Watching titles for user.
     *
     * @return array<int, array{progress: PlaybackProgress, item: object, title: string, url: string, poster: string, percentage: float, remaining_formatted: string}>
     */
    public static function getContinueWatching(User|int|null $user, int $limit = 12): array
    {
        $user = self::resolveUser($user);
        if (!$user || (int)$user->id <= 0) {
            return [];
        }

        $records = PlaybackProgress::getContinueWatching((int)$user->id, $limit * 2);
        $hydrated = [];

        foreach ($records as $rec) {
            $model = MultimediaAccessService::findContentModel((string)$rec->content_type, (int)$rec->content_id);
            if (!$model || ($model->status ?? 'published') !== 'published') {
                continue;
            }

            // Verify active access permission
            $accessState = MultimediaAccessService::checkAccess($user, (string)$rec->content_type, $model);
            if ($accessState !== MultimediaAccessService::ALLOW) {
                continue;
            }

            $title = (string)($model->title ?? '');
            $url = '';
            $poster = '';

            if ($rec->content_type === 'movie') {
                $url = '/movie/' . $model->slug;
                $poster = (string)($model->poster ?? '');
            } elseif ($rec->content_type === 'episode') {
                $url = '/episode/' . $model->slug;
                $poster = (string)($model->thumbnail ?? '');
            }

            $remainingSec = max(0, (float)$rec->duration - (float)$rec->position);
            $remainFormatted = self::formatRemainingTime((int)$remainingSec);

            $hydrated[] = [
                'progress'            => $rec,
                'content_type'        => (string)$rec->content_type,
                'content_id'          => (int)$rec->content_id,
                'item'                => $model,
                'title'               => $title,
                'url'                 => $url,
                'poster'              => $poster,
                'percentage'          => (float)$rec->percentage,
                'position'            => (float)$rec->position,
                'duration'            => (float)$rec->duration,
                'remaining_formatted' => $remainFormatted,
                'last_played_at'      => (string)$rec->last_played_at,
            ];

            if (count($hydrated) >= $limit) {
                break;
            }
        }

        return $hydrated;
    }

    /**
     * Get hydrated, access-verified Continue Listening songs for user.
     */
    public static function getContinueListening(User|int|null $user, int $limit = 12): array
    {
        $user = self::resolveUser($user);
        if (!$user || (int)$user->id <= 0) {
            return [];
        }

        $records = PlaybackProgress::getContinueListening((int)$user->id, $limit * 2);
        $hydrated = [];

        foreach ($records as $rec) {
            $song = Song::find((int)$rec->content_id);
            if (!$song || $song->status !== 'published') {
                continue;
            }

            $accessState = MultimediaAccessService::checkAccess($user, 'song', $song);
            if ($accessState !== MultimediaAccessService::ALLOW) {
                continue;
            }

            $artist = $song->getArtist();

            $hydrated[] = [
                'progress'       => $rec,
                'song'           => $song,
                'content_id'     => (int)$song->id,
                'title'          => $song->title,
                'artist_name'    => $artist ? $artist->name : '',
                'url'            => '/song/' . $song->slug,
                'poster'         => (string)($song->cover_image ?? ''),
                'percentage'     => (float)$rec->percentage,
                'position'       => (float)$rec->position,
                'duration'       => (float)$rec->duration,
                'formatted_position' => PlaybackProgressService::formatSeconds((float)$rec->position),
                'last_played_at' => (string)$rec->last_played_at,
                'content'        => [
                    'title'        => $song->title,
                    'poster_image' => (string)($song->cover_image ?? ''),
                ],
            ];

            if (count($hydrated) >= $limit) {
                break;
            }
        }

        return $hydrated;
    }

    /**
     * Get formatted history entries for user.
     */
    public static function getWatchHistory(User|int|null $user, int $limit = 20, int $offset = 0, ?string $contentType = null): array
    {
        $user = self::resolveUser($user);
        if (!$user || (int)$user->id <= 0) {
            return [];
        }

        $records = PlaybackProgress::getHistory((int)$user->id, $limit, $offset, $contentType);
        $items = [];

        foreach ($records as $rec) {
            $model = MultimediaAccessService::findContentModel((string)$rec->content_type, (int)$rec->content_id);
            if (!$model) {
                continue;
            }

            $accessState = MultimediaAccessService::checkAccess($user, (string)$rec->content_type, $model);
            $title = (string)($model->title ?? '');
            $url = '';
            $poster = '';

            if ($rec->content_type === 'movie') {
                $url = '/movie/' . $model->slug;
                $poster = (string)($model->poster ?? '');
            } elseif ($rec->content_type === 'episode') {
                $url = '/episode/' . $model->slug;
                $poster = (string)($model->thumbnail ?? '');
            } elseif ($rec->content_type === 'song') {
                $url = '/song/' . $model->slug;
                $poster = (string)($model->cover_image ?? '');
            }

            $items[] = [
                'progress_id'    => (int)$rec->id,
                'content_type'   => (string)$rec->content_type,
                'content_id'     => (int)$rec->content_id,
                'title'          => $title,
                'url'            => $url,
                'poster'         => $poster,
                'is_completed'   => (bool)$rec->is_completed,
                'percentage'     => (float)$rec->percentage,
                'last_played_at' => (string)$rec->last_played_at,
                'access_state'   => $accessState,
                'is_accessible'  => ($accessState === MultimediaAccessService::ALLOW),
                'content'        => [
                    'title'        => $title,
                    'poster_image' => $poster,
                ],
            ];
        }

        return $items;
    }

    /**
     * Alias for getWatchHistory.
     */
    public static function getHistory(User|int|null $user, int $limit = 20, int $offset = 0, ?string $contentType = null): array
    {
        return self::getWatchHistory($user, $limit, $offset, $contentType);
    }

    /**
     * Get user's favorites ("My List") with live access evaluation.
     */
    public static function getMyList(User|int|null $user, int $limit = 20, int $offset = 0, ?string $contentType = null): array
    {
        $user = self::resolveUser($user);
        if (!$user || (int)$user->id <= 0) {
            return [];
        }

        $records = Favorite::getByUser((int)$user->id, $limit, $offset, $contentType);
        $items = [];

        foreach ($records as $fav) {
            $model = self::resolveFavoriteModel((string)$fav->content_type, (int)$fav->content_id);
            if (!$model) {
                continue;
            }

            $accessState = MultimediaAccessService::checkAccess($user, (string)$fav->content_type, $model);
            $title = (string)($model->title ?? ($model->name ?? ''));
            $url = '';
            $poster = '';

            switch ($fav->content_type) {
                case 'movie':
                    $url = '/movie/' . $model->slug;
                    $poster = (string)($model->poster ?? '');
                    break;
                case 'series':
                    $url = '/series/' . $model->slug;
                    $poster = (string)($model->poster ?? '');
                    break;
                case 'song':
                    $url = '/song/' . $model->slug;
                    $poster = (string)($model->cover_image ?? '');
                    break;
                case 'playlist':
                    $url = '/playlist/' . $model->slug;
                    $poster = (string)($model->cover_image ?? '');
                    break;
            }

            $items[] = [
                'id'            => (int)$fav->id,
                'favorite_id'   => (int)$fav->id,
                'content_type'  => (string)$fav->content_type,
                'content_id'    => (int)$fav->content_id,
                'item'          => $model,
                'title'         => $title,
                'url'           => $url,
                'poster'        => $poster,
                'access_state'  => $accessState,
                'is_accessible' => ($accessState === MultimediaAccessService::ALLOW),
                'created_at'    => (string)$fav->created_at,
                'content'       => [
                    'title'        => $title,
                    'poster_image' => $poster,
                ],
            ];
        }

        return $items;
    }

    /**
     * Compile complete user library summary for authenticated library dashboard.
     */
    public static function compileLibraryDashboard(?User $user): array
    {
        if (!$user || (int)$user->id <= 0) {
            return [
                'authenticated'     => false,
                'continue_watching' => [],
                'continue_listening'=> [],
                'my_list'           => [],
                'recent_history'    => [],
                'history_count'     => 0,
                'favorites_count'   => 0,
            ];
        }

        $userId = (int)$user->id;

        return [
            'authenticated'     => true,
            'user'              => $user,
            'continue_watching' => self::getContinueWatching($user, 8),
            'continue_listening'=> self::getContinueListening($user, 6),
            'my_list'           => self::getMyList($user, 8),
            'recent_history'    => self::getWatchHistory($user, 8),
            'history_count'     => PlaybackProgress::countHistory($userId),
            'favorites_count'   => Favorite::countByUser($userId),
        ];
    }

    private static function resolveFavoriteModel(string $type, int $id): ?object
    {
        return match ($type) {
            'movie'    => Movie::find($id),
            'series'   => Series::find($id),
            'song'     => Song::find($id),
            'playlist' => Playlist::find($id),
            default    => null,
        };
    }

    private static function formatRemainingTime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }
        $m = floor($seconds / 60);
        $h = floor($m / 60);
        $remM = $m % 60;
        if ($h > 0) {
            return "{$h}h {$remM}m left";
        }
        return "{$m}m left";
    }
}
