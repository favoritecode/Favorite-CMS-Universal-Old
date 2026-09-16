<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Multimedia\Models\MediaSource;

class MediaSourceService
{
    /**
     * Canonical method to validate, normalize, and save a media source.
     * Used identically by main content forms (Movie, Episode, Song) and Advanced Sources.
     *
     * @param array $params Source configuration parameters
     * @return array ['success' => bool, 'source_id' => int|null, 'source' => MediaSource|null, 'error' => string|null]
     */
    public static function saveSource(array $params): array
    {
        $contentType = strtolower(trim((string)($params['content_type'] ?? '')));
        $contentId = (int)($params['content_id'] ?? 0);

        if ($contentType === '' || $contentId <= 0) {
            return [
                'success'   => false,
                'source_id' => null,
                'source'    => null,
                'error'     => 'Invalid content type or content ID.',
            ];
        }

        $urlOrPath = trim((string)($params['url_or_path'] ?? ''));
        // Extract plain URL if iframe snippet is pasted
        $urlOrPath = MediaSourceResolver::extractIframeUrl($urlOrPath);

        if ($urlOrPath === '') {
            return [
                'success'   => false,
                'source_id' => null,
                'source'    => null,
                'error'     => 'Media URL or file path cannot be empty.',
            ];
        }

        $mode = (string)($params['source_mode'] ?? '');
        if ($mode === '' || $mode === 'auto') {
            $isUpload = str_starts_with($urlOrPath, '/') || str_starts_with($urlOrPath, 'uploads/') || str_starts_with($urlOrPath, 'storage/');
            $mode = $isUpload ? 'upload' : 'url';
        }

        if ($mode === 'url' && !preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $urlOrPath)) {
            $urlOrPath = 'https://' . ltrim($urlOrPath, '/');
        }

        $rawType = strtolower(trim((string)($params['source_type'] ?? '')));
        $mediaKind = strtolower(trim((string)($params['media_kind'] ?? '')));
        if ($mediaKind === '') {
            $mediaKind = ($rawType === 'audio' || ($contentType === 'song' && !isset($params['is_video_source']))) ? 'audio' : 'video';
        }

        // Validate security if URL mode
        if ($mode === 'url') {
            $sec = MediaSourceResolver::validateUrlSecurity($urlOrPath);
            if (!$sec['safe']) {
                return [
                    'success'   => false,
                    'source_id' => null,
                    'source'    => null,
                    'error'     => $sec['reason'] ?? 'Invalid or insecure media URL.',
                ];
            }
        }

        // Canonical Source Type classification
        $sourceType = 'video';
        $mimeType = trim((string)($params['mime_type'] ?? ''));

        if ($rawType === 'embed' || $rawType === 'external_embed') {
            // External embed player
            $normalizedUrl = $urlOrPath;
            if (str_starts_with($normalizedUrl, '//')) {
                $normalizedUrl = 'https:' . $normalizedUrl;
            } elseif (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $normalizedUrl)) {
                $normalizedUrl = 'https://' . $normalizedUrl;
            }
            $host = strtolower((string)parse_url($normalizedUrl, PHP_URL_HOST));
            if (!MediaSourceResolver::isEmbedDomainAllowed($urlOrPath)) {
                return [
                    'success'   => false,
                    'source_id' => null,
                    'source'    => null,
                    'error'     => "The domain for this embed player is not in the trusted allowlist ({$host}). Please add it under Multimedia Settings.",
                ];
            }
            $sourceType = 'embed';
            $mimeType = 'text/html';
        } elseif ($rawType === 'youtube' || $rawType === 'vimeo') {
            $sourceType = 'embed';
            $mimeType = 'text/html';
        } elseif ($rawType === 'hls' || str_contains(strtolower($urlOrPath), '.m3u8')) {
            $sourceType = 'hls';
            if ($mimeType === '') {
                $mimeType = 'application/x-mpegURL';
            }
        } elseif ($rawType === 'audio' || $mediaKind === 'audio') {
            $sourceType = 'audio';
            $mediaKind = 'audio';
            if ($mimeType === '') {
                $mimeType = 'audio/mpeg';
            }
        } elseif ($rawType === 'upload' || $mode === 'upload') {
            $sourceType = ($mediaKind === 'audio') ? 'audio' : 'video';
            if ($mimeType === '') {
                $mimeType = ($mediaKind === 'audio') ? 'audio/mpeg' : 'video/mp4';
            }
        } else {
            // Check if URL points to a known embed service even if admin selected direct/auto
            $embedInfo = MediaSourceResolver::detectEmbedService($urlOrPath);
            if ($embedInfo !== null) {
                $sourceType = 'embed';
                $mimeType = 'text/html';
            } elseif (MediaSourceResolver::isEmbedDomainAllowed($urlOrPath) && !str_contains(strtolower($urlOrPath), '.mp4') && !str_contains(strtolower($urlOrPath), '.m3u8')) {
                // If the host is in trusted embed domains and has no direct video extension, treat as embed
                $sourceType = 'embed';
                $mimeType = 'text/html';
            } else {
                $sourceType = 'video';
                if ($mimeType === '') {
                    $mimeType = 'video/mp4';
                }
            }
        }

        // Label generation if empty
        $label = trim((string)($params['label'] ?? ''));
        if ($label === '') {
            $label = match ($sourceType) {
                'embed' => 'Embed Player',
                'hls'   => 'HLS Stream',
                'audio' => 'Audio Stream',
                default => 'Main Server',
            };
        }

        $status = strtolower(trim((string)($params['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive', 'failed', 'processing'], true)) {
            $status = 'active';
        }

        $allowDownload = strtolower(trim((string)($params['allow_download'] ?? 'inherit')));
        if (!in_array($allowDownload, ['inherit', 'allow', 'deny'], true)) {
            $allowDownload = 'inherit';
        }

        $existingSources = MediaSource::getForContent($contentType, $contentId, false, $mediaKind);
        $isFirst = empty($existingSources);

        $isDefault = isset($params['is_default']) ? ((int)(bool)$params['is_default']) : ($isFirst ? 1 : 0);
        $sortOrder = isset($params['sort_order']) ? (int)$params['sort_order'] : (count($existingSources) + 1);

        $data = [
            'content_type'   => $contentType,
            'content_id'     => $contentId,
            'source_mode'    => $mode,
            'source_type'    => $sourceType,
            'url_or_path'    => $urlOrPath,
            'label'          => $label,
            'quality'        => $params['quality'] ?? null,
            'mime_type'      => $mimeType,
            'poster'         => $params['poster'] ?? null,
            'is_default'     => $isDefault,
            'allow_download' => $allowDownload,
            'status'         => $status,
            'sort_order'     => $sortOrder,
            'media_kind'     => $mediaKind,
            'language_code'  => $params['language_code'] ?? 'en',
            'audio_role'     => $params['audio_role'] ?? 'main',
        ];

        /** @var Database $db */
        $db = Container::getInstance()->get(Database::class);
        $targetId = (int)($params['id'] ?? 0);

        // If replace_default is true and we don't have an explicit ID, find existing default source
        if ($targetId <= 0 && !empty($params['replace_default'])) {
            $def = MediaSource::getDefault($contentType, $contentId, false, $mediaKind);
            if ($def) {
                $targetId = (int)$def->id;
            }
        }

        if ($targetId > 0) {
            $db->update('multimedia_sources', $data, ['id' => $targetId]);
            $sourceId = $targetId;
        } else {
            $sourceId = (int)$db->insert('multimedia_sources', $data);
        }

        // Default management
        if ($isDefault === 1) {
            MediaSource::enforceSingleDefault($contentType, $contentId, $sourceId, $mediaKind);
        } else {
            MediaSource::promoteNextDefault($contentType, $contentId, $mediaKind);
        }

        $savedSource = MediaSource::find($sourceId);

        return [
            'success'   => true,
            'source_id' => $sourceId,
            'source'    => $savedSource,
            'error'     => null,
        ];
    }
}

