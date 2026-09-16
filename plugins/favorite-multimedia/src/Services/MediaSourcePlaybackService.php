<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Song;

class MediaSourcePlaybackService
{
    /**
     * Get authorized, normalized playback sources for a content item.
     * Enforces MultimediaAccessService fail-closed access control.
     *
     * @param object|null $user Current user session or null
     * @param string $contentType 'movie', 'episode', or 'song'
     * @param object|int $content Model instance or content ID
     * @return array Array containing access status and normalized sources
     */
    public static function getPlayableSources(?object $user, string $contentType, object|int $content, ?string $mediaKind = null): array
    {
        $contentModel = is_object($content)
            ? $content
            : MultimediaAccessService::findContentModel($contentType, (int)$content);

        if (!$contentModel) {
            return [
                'access'               => MultimediaAccessService::NOT_FOUND,
                'allowed'              => false,
                'sources'              => [],
                'audio_sources'        => [],
                'video_sources'        => [],
                'default_source'       => null,
                'default_audio_source' => null,
                'default_video_source' => null,
                'has_audio'            => false,
                'has_video'            => false,
                'playback_type'        => 'audio',
                'default_playback_mode'=> 'audio',
                'count'                => 0,
            ];
        }

        // Access gating
        $accessState = MultimediaAccessService::checkAccess($user, $contentType, $contentModel);
        if ($accessState !== MultimediaAccessService::ALLOW) {
            return [
                'access'               => $accessState,
                'allowed'              => false,
                'sources'              => [],
                'audio_sources'        => [],
                'video_sources'        => [],
                'default_source'       => null,
                'default_audio_source' => null,
                'default_video_source' => null,
                'has_audio'            => false,
                'has_video'            => false,
                'playback_type'        => ($contentModel instanceof Song) ? $contentModel->getPlaybackType() : 'video',
                'default_playback_mode'=> ($contentModel instanceof Song) ? $contentModel->getDefaultPlaybackMode() : 'video',
                'count'                => 0,
            ];
        }

        $sources = MediaSource::getForContent($contentType, (int)$contentModel->id, true);
        if (empty($sources)) {
            return [
                'access'               => MultimediaAccessService::ALLOW,
                'allowed'              => true,
                'sources'              => [],
                'audio_sources'        => [],
                'video_sources'        => [],
                'default_source'       => null,
                'default_audio_source' => null,
                'default_video_source' => null,
                'has_audio'            => false,
                'has_video'            => false,
                'playback_type'        => ($contentModel instanceof Song) ? $contentModel->getPlaybackType() : 'video',
                'default_playback_mode'=> ($contentModel instanceof Song) ? $contentModel->getDefaultPlaybackMode() : 'video',
                'count'                => 0,
            ];
        }

        $normalized = [];
        $defaultItem = null;
        $serverIdx = 1;

        foreach ($sources as $source) {
            $rawUrl = (string)($source->url_or_path ?? '');
            $sourceType = strtolower(trim((string)($source->source_type ?? 'video')));
            $label = trim((string)($source->label ?? ''));
            $itemMediaKind = method_exists($source, 'getMediaKind')
                ? $source->getMediaKind()
                : (($sourceType === 'audio') ? 'audio' : 'video');

            // Check if this source points to a known embed service (YouTube, Vimeo, etc.)
            $embedInfo = MediaSourceResolver::detectEmbedService($rawUrl);
            $isKnownEmbed = ($embedInfo !== null);
            $isGenericEmbed = ($sourceType === 'embed') || MediaSourceResolver::isEmbedDomainAllowed($rawUrl);

            if ($isKnownEmbed) {
                $playerType = 'embed';
                $playableUrl = $embedInfo['embed_url'];
                $mimeType = 'text/html';
                $canAutoFailover = false;
                if ($label === '') {
                    $label = ucfirst($embedInfo['service']) . " ({$serverIdx})";
                }
            } elseif ($isGenericEmbed) {
                $playerType = 'embed';
                $playableUrl = $rawUrl;
                $mimeType = 'text/html';
                $canAutoFailover = false;
                if ($label === '') {
                    $label = "Embed Player ({$serverIdx})";
                }
            } elseif ($sourceType === 'hls' || str_contains(strtolower($rawUrl), '.m3u8')) {
                $playerType = 'hls';
                $playableUrl = "/multimedia/stream/{$source->id}";
                $mimeType = 'application/x-mpegURL';
                $canAutoFailover = true;
                if ($label === '') {
                    $label = "HLS Stream ({$serverIdx})";
                }
            } elseif ($sourceType === 'audio' || $itemMediaKind === 'audio') {
                $playerType = 'audio';
                $playableUrl = "/multimedia/stream/{$source->id}";
                $mimeType = $source->mime_type ?: 'audio/mpeg';
                $canAutoFailover = true;
                if ($label === '') {
                    $label = "Audio Server {$serverIdx}";
                }
            } else {
                // Direct video
                $playerType = 'video';
                $playableUrl = "/multimedia/stream/{$source->id}";
                $mimeType = $source->mime_type ?: 'video/mp4';
                $canAutoFailover = true;
                if ($label === '') {
                    $label = "Server {$serverIdx}";
                }
            }

            $isTrustedEmbed = false;
            $sandboxPolicy = null;
            $allowAttribute = 'autoplay; fullscreen; encrypted-media; picture-in-picture';
            $referrerPolicy = 'no-referrer-when-downgrade';

            if ($playerType === 'embed') {
                $isTrustedEmbed = MediaSourceResolver::isTrustedEmbedDomain($playableUrl);
                $sandboxPolicy = MediaSourceResolver::getEmbedSandboxPolicy($playableUrl);
                $allowAttribute = MediaSourceResolver::getEmbedAllowAttribute($playableUrl);
                $referrerPolicy = MediaSourceResolver::getEmbedReferrerPolicy($playableUrl);
            }

            $isDefault = !empty($source->is_default);
            $item = [
                'id'                => (int)$source->id,
                'label'             => $label,
                'source_type'       => $sourceType,
                'player_type'       => $playerType,
                'media_kind'        => $itemMediaKind,
                'url'               => $playableUrl,
                'stream_url'        => "/multimedia/stream/{$source->id}",
                'raw_url'           => $rawUrl,
                'mime_type'         => $mimeType,
                'is_default'        => $isDefault,
                'can_auto_failover' => $canAutoFailover,
                'sort_order'        => (int)($source->sort_order ?? 0),
                'is_trusted_embed'  => $isTrustedEmbed,
                'sandbox_policy'    => $sandboxPolicy,
                'allow_attribute'   => $allowAttribute,
                'referrer_policy'   => $referrerPolicy,
            ];

            if ($isDefault && $defaultItem === null) {
                $defaultItem = $item;
            }

            $normalized[] = $item;
            $serverIdx++;
        }

        // Separate audio and video groups
        $audioSources = array_values(array_filter($normalized, fn($s) => $s['media_kind'] === 'audio' || $s['player_type'] === 'audio'));
        $videoSources = array_values(array_filter($normalized, fn($s) => $s['media_kind'] === 'video' || in_array($s['player_type'], ['video', 'hls', 'embed'], true)));

        $defaultAudio = null;
        foreach ($audioSources as $as) {
            if ($as['is_default']) {
                $defaultAudio = $as;
                break;
            }
        }
        if ($defaultAudio === null && !empty($audioSources)) {
            $defaultAudio = $audioSources[0];
        }

        $defaultVideo = null;
        foreach ($videoSources as $vs) {
            if ($vs['is_default']) {
                $defaultVideo = $vs;
                break;
            }
        }
        if ($defaultVideo === null && !empty($videoSources)) {
            $defaultVideo = $videoSources[0];
        }

        // Scope primary sources array by mediaKind if specified
        $scopedSources = $normalized;
        if ($mediaKind === 'audio') {
            $scopedSources = $audioSources;
            $defaultItem = $defaultAudio;
        } elseif ($mediaKind === 'video') {
            $scopedSources = $videoSources;
            $defaultItem = $defaultVideo;
        } elseif ($defaultItem === null && !empty($normalized)) {
            $defaultItem = $normalized[0];
        }

        $playbackType = ($contentModel instanceof Song) ? $contentModel->getPlaybackType() : 'video';
        $defaultMode = ($contentModel instanceof Song) ? $contentModel->getDefaultPlaybackMode() : 'video';

        return [
            'access'               => MultimediaAccessService::ALLOW,
            'allowed'              => true,
            'sources'              => $scopedSources,
            'audio_sources'        => $audioSources,
            'video_sources'        => $videoSources,
            'default_source'       => $defaultItem,
            'default_audio_source' => $defaultAudio,
            'default_video_source' => $defaultVideo,
            'has_audio'            => !empty($audioSources),
            'has_video'            => !empty($videoSources),
            'playback_type'        => $playbackType,
            'default_playback_mode'=> $defaultMode,
            'count'                => count($scopedSources),
        ];
    }
}
