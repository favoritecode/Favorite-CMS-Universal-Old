<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class AnalyticsEvent extends BaseModel
{
    protected static string $table = 'multimedia_analytics';

    public const ALLOWED_SOURCES = [
        'catalog',
        'search',
        'trending',
        'popular',
        'related',
        'recommended',
        'continue_watching',
    ];

    public static function logEvent(
        string $contentType,
        int $contentId,
        string $eventType,
        ?int $userId = null,
        ?string $discoverySource = null,
        array|string|null $metadata = null
    ): void {
        $allowedTypes = ['movie', 'series', 'episode', 'song', 'playlist'];
        $allowedEvents = ['play', 'view', 'download', 'premium_denied', 'audio_selected', 'subtitle_selected'];
        if (!in_array($contentType, $allowedTypes, true) || !in_array($eventType, $allowedEvents, true) || $contentId <= 0) {
            return;
        }

        $source = null;
        if ($discoverySource !== null && in_array(strtolower(trim($discoverySource)), self::ALLOWED_SOURCES, true)) {
            $source = strtolower(trim($discoverySource));
        }

        $metaString = null;
        if (is_array($metadata)) {
            $metaString = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($metadata) && $metadata !== '') {
            $metaString = $metadata;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ipHash = hash('sha256', $ip . 'fav_mm_salt');

        $row = [
            'content_type' => $contentType,
            'content_id'   => $contentId,
            'event_type'   => $eventType,
            'user_id'      => $userId,
            'ip_hash'      => substr($ipHash, 0, 32),
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ];

        if ($source !== null) {
            $row['discovery_source'] = $source;
        }
        if ($metaString !== null) {
            $row['metadata'] = $metaString;
        }

        try {
            $db->insert('multimedia_analytics', $row);
        } catch (\Throwable $e) {
            // If new columns not yet in DB, fallback to baseline columns
            if (isset($row['discovery_source']) || isset($row['metadata'])) {
                unset($row['discovery_source'], $row['metadata']);
                try {
                    $db->insert('multimedia_analytics', $row);
                } catch (\Throwable) {
                }
            }
        }
    }

    public static function getStats(): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $totalMovies = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_movies")->c ?? 0);
        $totalSeries = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_series")->c ?? 0);
        $totalEpisodes = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_episodes")->c ?? 0);
        $totalSongs = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_songs")->c ?? 0);
        $totalPlaylists = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_playlists")->c ?? 0);

        $totalPlays = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_analytics WHERE event_type = 'play'")->c ?? 0);
        $totalDownloads = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_analytics WHERE event_type = 'download'")->c ?? 0);
        $totalViews = (int)($db->selectOne("SELECT COUNT(*) as c FROM multimedia_analytics WHERE event_type = 'view'")->c ?? 0);

        return [
            'total_movies'    => $totalMovies,
            'total_series'    => $totalSeries,
            'total_episodes'  => $totalEpisodes,
            'total_songs'     => $totalSongs,
            'total_playlists' => $totalPlaylists,
            'total_plays'     => $totalPlays,
            'total_downloads' => $totalDownloads,
            'total_views'     => $totalViews,
        ];
    }
}

