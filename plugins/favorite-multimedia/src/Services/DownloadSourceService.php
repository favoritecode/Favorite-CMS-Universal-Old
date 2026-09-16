<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\DownloadSource;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;

/**
 * DownloadSourceService
 *
 * Resolves, prioritizes, and verifies available download options for media items.
 *
 * Resolution Priority:
 * 1. Active manual download source rows (multimedia_download_sources)
 * 2. Legacy manual download_url fallback (if not already matching a row)
 * 3. Uploaded downloadable media files
 * 4. Direct downloadable media stream URLs
 *
 * Strict Exclusion:
 * - Third-party embeds (YouTube, Vimeo, generic external embeds)
 * - HLS playlists (.m3u8)
 *
 * Deduplication:
 * - Exact matching URLs are deduplicated across options.
 */
class DownloadSourceService
{
    public static function resolveDownloadOptions(
        ?object $user,
        string $contentType,
        object $content,
        object|array|null $selectedSource = null
    ): array {
        if (is_array($selectedSource)) {
            $selId = (int)($selectedSource['id'] ?? 0);
            $selectedSource = ($selId > 0) ? MediaSource::find($selId) : (object)$selectedSource;
        }
        // 1. Content viewing and access check (PUBLIC / LOGIN / PREMIUM via Favorite Digital)
        $viewAccess = MultimediaAccessService::checkAccess($user, $contentType, $content);
        if ($viewAccess !== MultimediaAccessService::ALLOW) {
            $reason = match ($viewAccess) {
                MultimediaAccessService::LOGIN_REQUIRED   => 'You must log in to download this content.',
                MultimediaAccessService::PREMIUM_REQUIRED => 'A Premium subscription is required to download this media.',
                MultimediaAccessService::FORBIDDEN        => 'Your account does not have permission to download.',
                default                                   => 'Content access is restricted.',
            };

            return [
                'allowed'        => false,
                'reason'         => $reason,
                'access_state'   => $viewAccess,
                'download_url'   => null,
                'download_label' => null,
                'options'        => [],
                'count'          => 0,
                'is_manual'      => false,
            ];
        }

        $isAdmin = ($user && ($user->hasRole('super-admin') || $user->hasRole('admin')));

        // 2. Check content download policy & global setting
        $globalSetting = Setting::get('multimedia', 'enable_downloads', 'yes');
        $isGlobalEnabled = ($globalSetting === 'yes' || $globalSetting === '1' || $globalSetting === 'true');

        $parentPolicy = (string)($content->download_policy ?? 'inherit');
        if ($contentType === 'episode' && $content instanceof Episode) {
            $parentPolicy = $content->getResolvedDownloadPolicy();
        }

        if (!$isAdmin) {
            if ($parentPolicy === 'deny') {
                return [
                    'allowed'        => false,
                    'reason'         => 'Downloads are disabled for this content.',
                    'access_state'   => MultimediaAccessService::FORBIDDEN,
                    'download_url'   => null,
                    'download_label' => null,
                    'options'        => [],
                    'count'          => 0,
                    'is_manual'      => false,
                ];
            }
            if ($parentPolicy === 'inherit' && !$isGlobalEnabled) {
                return [
                    'allowed'        => false,
                    'reason'         => 'Media downloads are currently disabled site-wide.',
                    'access_state'   => MultimediaAccessService::FORBIDDEN,
                    'download_url'   => null,
                    'download_label' => null,
                    'options'        => [],
                    'count'          => 0,
                    'is_manual'      => false,
                ];
            }
        }

        $options = [];
        $seenRawUrls = [];

        // Priority 1: Active manual download-source rows
        $contentId = (int)($content->id ?? 0);
        if ($contentId > 0) {
            $manualSources = DownloadSource::getForContent($contentType, $contentId, true);
            foreach ($manualSources as $dl) {
                $raw = trim((string)$dl->url);
                if ($raw === '') {
                    continue;
                }

                $norm = strtolower(rtrim($raw, '/'));
                if (isset($seenRawUrls[$norm])) {
                    continue;
                }

                // Security check on manual download URL
                if (!$dl->isValidUrl()) {
                    continue;
                }

                $seenRawUrls[$norm] = true;
                $format = $dl->format ?: DownloadSource::inferFormat($raw);
                $provider = $dl->provider ?: DownloadSource::inferProvider($raw);

                $options[] = [
                    'id'            => (int)$dl->id,
                    'type'          => 'download_source',
                    'label'         => $dl->label ?: 'Download',
                    'display_title' => $dl->getDisplayTitle(),
                    'meta'          => $dl->getMetaDescription(),
                    'url'           => "/multimedia/download-source/{$dl->id}",
                    'raw_url'       => $raw,
                    'quality'       => $dl->quality,
                    'format'        => $format,
                    'provider'      => $provider,
                    'is_manual'     => true,
                ];
            }
        }

        // Priority 2: Legacy manual download_url fallback (if not already matching a row)
        $legacyUrl = trim((string)($content->download_url ?? ''));
        if ($legacyUrl !== '') {
            $normLegacy = strtolower(rtrim($legacyUrl, '/'));
            if (!isset($seenRawUrls[$normLegacy])) {
                $check = MediaSourceResolver::validateUrlSecurity($legacyUrl, false);
                $isLocal = (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $legacyUrl) && !str_starts_with($legacyUrl, '//') && !str_contains($legacyUrl, '..'));

                if (!empty($check['safe']) || $isLocal) {
                    $seenRawUrls[$normLegacy] = true;
                    $format = DownloadSource::inferFormat($legacyUrl);
                    $provider = DownloadSource::inferProvider($legacyUrl);

                    $options[] = [
                        'id'            => null,
                        'type'          => 'legacy_content',
                        'label'         => 'Direct Download',
                        'display_title' => 'Direct Download',
                        'meta'          => $format ?: 'File',
                        'url'           => "/multimedia/download-content/{$contentType}/{$contentId}",
                        'raw_url'       => $legacyUrl,
                        'quality'       => null,
                        'format'        => $format,
                        'provider'      => $provider,
                        'is_manual'     => true,
                    ];
                }
            }
        }

        // Priority 3 & 4: Uploaded / direct playable media source
        $mediaSources = [];
        if ($selectedSource !== null && !empty($selectedSource->url_or_path)) {
            $mediaSources[] = $selectedSource;
        }
        if ($contentId > 0) {
            try {
                $dbSources = MediaSource::getForContent($contentType, $contentId, true);
                foreach ($dbSources as $ds) {
                    $mediaSources[] = $ds;
                }
            } catch (\Throwable) {
                // Ignore if tables not yet migrated
            }
        }
        foreach ($mediaSources as $s) {
                $sourcePolicy = (string)($s->allow_download ?? 'inherit');
                if (!$isAdmin && $sourcePolicy === 'deny') {
                    continue;
                }

                $sourceType = strtolower(trim((string)$s->source_type));
                $rawMedia = trim((string)$s->url_or_path);

                // Strictly exclude embeds and HLS
                if ($sourceType === 'embed' || $sourceType === 'hls' || str_contains(strtolower($rawMedia), '.m3u8')) {
                    continue;
                }

                $isUploaded = ($s->source_mode === 'local' || $s->source_mode === 'upload'
                    || str_starts_with($rawMedia, '/') || str_starts_with($rawMedia, 'storage/') || str_starts_with($rawMedia, 'uploads/'));

                $pathOnly = parse_url($rawMedia, PHP_URL_PATH) ?? '';
                $ext = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));
                $downloadableExts = ['mp4', 'webm', 'mkv', 'mov', 'm4v', 'mp3', 'm4a', 'flac', 'wav', 'aac', 'ogg', 'opus'];
                $isDirect = in_array($ext, $downloadableExts, true) || $sourceType === 'video' || $sourceType === 'audio';

                if (!$isUploaded && !$isDirect) {
                    continue;
                }

                $normMedia = strtolower(rtrim($rawMedia, '/'));
                if (isset($seenRawUrls[$normMedia])) {
                    continue;
                }

                $seenRawUrls[$normMedia] = true;
                $format = strtoupper($ext) ?: ($sourceType === 'audio' ? 'MP3' : 'MP4');
                $provider = $isUploaded ? 'Direct' : 'Mirror';
                $quality = $s->quality ?: null;
                $label = $s->label ?: ($isUploaded ? 'Media File' : 'Direct Media');

                $titleParts = array_filter([$quality, $format, ($provider !== 'Direct' ? $provider : null)]);
                $displayTitle = !empty($titleParts) ? implode(' — ', $titleParts) : $label;

                $options[] = [
                    'id'            => (int)$s->id,
                    'type'          => 'media_source',
                    'label'         => $label,
                    'display_title' => $displayTitle,
                    'meta'          => $quality ? "{$quality} • {$format}" : $format,
                    'url'           => "/multimedia/download/{$s->id}",
                    'raw_url'       => $rawMedia,
                    'quality'       => $quality,
                    'format'        => $format,
                    'provider'      => $provider,
                    'is_manual'     => false,
                ];
            }

        // Return resolved download metadata
        if (empty($options)) {
            return [
                'allowed'        => false,
                'reason'         => 'No downloadable media file is available.',
                'access_state'   => MultimediaAccessService::FORBIDDEN,
                'download_url'   => null,
                'download_label' => null,
                'options'        => [],
                'count'          => 0,
                'is_manual'      => false,
            ];
        }

        return [
            'allowed'        => true,
            'reason'         => 'Download allowed.',
            'access_state'   => MultimediaAccessService::ALLOW,
            'options'        => $options,
            'count'          => count($options),
            'download_url'   => $options[0]['url'],
            'download_label' => $options[0]['display_title'] ?? $options[0]['label'],
            'is_manual'      => !empty($options[0]['is_manual']),
        ];
    }
}

