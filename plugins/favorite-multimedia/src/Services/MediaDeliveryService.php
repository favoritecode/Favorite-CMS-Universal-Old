<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Logger;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Storage\MediaStorageManager;

class MediaDeliveryService
{
    /**
     * Stream a media source with HTTP 206 Range support for video/audio seeking.
     */
    public static function streamSource(MediaSource $source, bool $isProtected = false): void
    {
        $urlOrPath = (string)$source->url_or_path;
        $isLocal = ($source->source_mode === 'upload') || str_starts_with($urlOrPath, '/') || str_starts_with($urlOrPath, 'uploads/') || str_starts_with($urlOrPath, 'storage/');

        if ($isLocal) {
            self::streamLocalFile($urlOrPath, (string)($source->mime_type ?: 'application/octet-stream'), $isProtected);
            return;
        }

        // Remote URL: redirect to safe public/signed URL or stream through safe proxy
        self::proxyRemoteStream($urlOrPath, (string)($source->mime_type ?: 'application/octet-stream'), $isProtected);
    }

    /**
     * Deliver a media source as a direct download attachment.
     */
    public static function downloadSource(MediaSource $source, ?string $downloadName = null, bool $isProtected = true): void
    {
        $urlOrPath = (string)$source->url_or_path;
        $isLocal = ($source->source_mode === 'upload') || str_starts_with($urlOrPath, '/') || str_starts_with($urlOrPath, 'uploads/') || str_starts_with($urlOrPath, 'storage/');

        $filename = $downloadName ?: basename(parse_url($urlOrPath, PHP_URL_PATH) ?? 'media');
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if ($ext === '') {
            $ext = ($source->source_type === 'audio') ? 'mp3' : 'mp4';
            $filename .= '.' . $ext;
        }

        // Sanitize filename for Content-Disposition
        $safeFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        // 1. S3 remote object storage support
        if (($source->storage_driver ?? 'local') === 's3' && !empty($source->storage_key)) {
            $s3Disk = MediaStorageManager::getDisk('s3');
            $presignedUrl = $s3Disk->temporaryUrl((string)$source->storage_key, 300, ['download_name' => $safeFilename]);
            if ($presignedUrl !== null) {
                if (!headers_sent()) {
                    header('Location: ' . $presignedUrl, true, 302);
                }
                exit;
            }
        }

        if ($isLocal) {
            self::downloadLocalFile($urlOrPath, $safeFilename, (string)($source->mime_type ?: 'application/octet-stream'), $isProtected);
            return;
        }

        // Remote URL
        self::proxyRemoteDownload($urlOrPath, $safeFilename, (string)($source->mime_type ?: 'application/octet-stream'), $isProtected);
    }

    /**
     * Deliver a subtitle track with text/vtt headers.
     */
    public static function deliverSubtitle(Subtitle $subtitle, bool $isProtected = false): Response
    {
        $path = (string)$subtitle->file_or_url;
        $content = '';

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $security = MediaSourceResolver::validateUrlSecurity($path, true);
            if (!$security['safe']) {
                Logger::warning('Favorite Multimedia: Remote subtitle blocked by security policy', [
                    'reason' => $security['reason'],
                    'host'   => parse_url($path, PHP_URL_HOST),
                ]);
                return Response::make("WEBVTT\n\n1\n00:00:00.000 --> 00:00:05.000\nSubtitle URL blocked by security policy.", 403)
                    ->header('Content-Type', 'text/vtt; charset=utf-8');
            }

            $ctx = stream_context_create([
                'http' => [
                    'timeout'         => 10,
                    'follow_location' => 0,
                    'user_agent'      => 'FavoriteCMS-Multimedia/1.0',
                ],
            ]);
            $fetched = @file_get_contents($path, false, $ctx);
            if ($fetched === false) {
                Logger::warning('Favorite Multimedia: Failed to fetch remote subtitle', [
                    'host' => parse_url($path, PHP_URL_HOST),
                ]);
            }
            $content = $fetched !== false ? $fetched : "WEBVTT\n\n1\n00:00:00.000 --> 00:00:05.000\nFailed to load remote subtitle.";
        } elseif (($subtitle->storage_driver ?? 'local') === 's3' && !empty($subtitle->storage_key)) {
            $disk = MediaStorageManager::getDisk('s3');
            $content = (string)$disk->get((string)$subtitle->storage_key);
            if (empty($content)) {
                $content = "WEBVTT\n\n1\n00:00:00.000 --> 00:00:05.000\nSubtitle object not found.";
            }
        } else {
            $normalizedPath = str_replace('\\', '/', $path);
            $localFile = APP_ROOT . '/' . ltrim($normalizedPath, '/');
            $realFile = realpath($localFile);
            $realRoot = realpath(APP_ROOT);
            if (!str_contains($normalizedPath, '..') && $realFile !== false && $realRoot !== false && str_starts_with($realFile, $realRoot) && file_exists($realFile) && !is_dir($realFile)) {
                $content = (string)file_get_contents($realFile);
            } else {
                Logger::warning('Favorite Multimedia: Local subtitle file not found or invalid path', [
                    'path' => $normalizedPath,
                ]);
                $content = "WEBVTT\n\n1\n00:00:00.000 --> 00:00:05.000\nSubtitle file not found.";
            }
        }

        // Convert SRT to WebVTT and sanitize cue text if needed
        if ($subtitle->format === 'srt' || str_ends_with(strtolower($path), '.srt') || !str_starts_with(trim($content), 'WEBVTT')) {
            $content = MediaLanguageService::convertSrtToVtt($content);
        }

        $res = Response::make($content, 200);
        $res->header('Content-Type', 'text/vtt; charset=utf-8');
        if ($isProtected) {
            $res->header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            $res->header('Pragma', 'no-cache');
        } else {
            $res->header('Cache-Control', 'public, max-age=3600');
        }
        return $res;
    }

    /**
     * Compute HTTP streaming response headers, range calculation, and validation.
     */
    public static function buildStreamHeaders(
        string $path,
        string $mimeType,
        bool $isProtected = false,
        ?string $rangeHeader = null,
        string $httpMethod = 'GET'
    ): array {
        $normalizedPath = str_replace('\\', '/', $path);
        if (str_contains($normalizedPath, '..')) {
            return ['status' => 404, 'error' => 'Media file not found.'];
        }

        $filePath = APP_ROOT . '/' . ltrim($normalizedPath, '/');
        $realFile = realpath($filePath);
        $realRoot = realpath(APP_ROOT);
        if ($realFile === false || $realRoot === false || !str_starts_with($realFile, $realRoot) || is_dir($realFile)) {
            return ['status' => 404, 'error' => 'Media file not found.'];
        }

        $size = filesize($realFile);
        $start = 0;
        $end = max(0, $size - 1);

        if ($size > 0 && $rangeHeader !== null && $rangeHeader !== '') {
            if (preg_match('/bytes=\h*(\d*)-(\d*)/i', $rangeHeader, $matches)) {
                $rawStart = $matches[1] ?? '';
                $rawEnd   = $matches[2] ?? '';

                if ($rawStart === '' && $rawEnd !== '') {
                    // Suffix range: bytes=-500 (last 500 bytes)
                    $suffix = (int)$rawEnd;
                    $start  = max(0, $size - $suffix);
                    $end    = $size - 1;
                } elseif ($rawStart !== '' && $rawEnd === '') {
                    // Open range: bytes=500- (from 500 to end)
                    $start = (int)$rawStart;
                    $end   = $size - 1;
                } elseif ($rawStart !== '' && $rawEnd !== '') {
                    // Bounded range: bytes=500-1000
                    $start = (int)$rawStart;
                    $end   = min((int)$rawEnd, $size - 1);
                }
            }
        }

        if ($size > 0 && ($start > $end || $start >= $size || $end >= $size)) {
            return [
                'status'  => 416,
                'headers' => [
                    'Content-Range' => "bytes */{$size}",
                ],
                'error'   => 'Requested Range Not Satisfiable',
            ];
        }

        $length = ($size === 0) ? 0 : ($end - $start + 1);
        $isPartial = ($length < $size);

        $headers = [
            'Content-Type'   => $mimeType,
            'Accept-Ranges'  => 'bytes',
            'Content-Length' => (string)$length,
            'Cache-Control'  => $isProtected ? 'private, no-cache, no-store, must-revalidate' : 'public, max-age=3600',
        ];
        if ($isProtected) {
            $headers['Pragma'] = 'no-cache';
        }
        if ($isPartial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $isHead = strtoupper($httpMethod) === 'HEAD';

        return [
            'status'     => $isPartial ? 206 : 200,
            'headers'    => $headers,
            'start'      => $start,
            'end'        => $end,
            'length'     => $length,
            'size'       => $size,
            'is_partial' => $isPartial,
            'is_head'    => $isHead,
            'real_file'  => $realFile,
        ];
    }

    /**
     * Compute direct file download attachment headers and metadata.
     */
    public static function buildDownloadHeaders(
        string $path,
        string $safeFilename,
        string $mimeType,
        bool $isProtected = true,
        string $httpMethod = 'GET'
    ): array {
        $normalizedPath = str_replace('\\', '/', $path);
        if (str_contains($normalizedPath, '..')) {
            return ['status' => 404, 'error' => 'Media file not found.'];
        }

        $filePath = APP_ROOT . '/' . ltrim($normalizedPath, '/');
        $realFile = realpath($filePath);
        $realRoot = realpath(APP_ROOT);
        if ($realFile === false || $realRoot === false || !str_starts_with($realFile, $realRoot) || is_dir($realFile)) {
            return ['status' => 404, 'error' => 'Media file not found.'];
        }

        $size = filesize($realFile);
        $isHead = strtoupper($httpMethod) === 'HEAD';

        $headers = [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => "attachment; filename=\"{$safeFilename}\"",
            'Content-Length'      => (string)$size,
            'Cache-Control'       => $isProtected ? 'private, no-cache, no-store, must-revalidate' : 'public, max-age=3600',
        ];
        if ($isProtected) {
            $headers['Pragma'] = 'no-cache';
        }

        return [
            'status'    => 200,
            'headers'   => $headers,
            'size'      => $size,
            'is_head'   => $isHead,
            'real_file' => $realFile,
        ];
    }

    /**
     * Stream local file with HTTP 206 Partial Content (Byte range) support.
     */
    protected static function streamLocalFile(string $path, string $mimeType, bool $isProtected = false): void
    {
        $range = $_SERVER['HTTP_RANGE'] ?? null;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $meta = self::buildStreamHeaders($path, $mimeType, $isProtected, $range, $method);

        if ($meta['status'] === 404) {
            Logger::warning('Favorite Multimedia: Local media file not found or invalid path', [
                'path' => str_replace('\\', '/', $path),
            ]);
            http_response_code(404);
            echo "Media file not found.";
            exit;
        }

        if ($meta['status'] === 416) {
            http_response_code(416);
            foreach ($meta['headers'] as $name => $val) {
                header("{$name}: {$val}");
            }
            exit;
        }

        http_response_code($meta['status']);
        foreach ($meta['headers'] as $name => $val) {
            header("{$name}: {$val}");
        }

        if (!empty($meta['is_head'])) {
            exit;
        }

        // Flush output buffers before binary streaming
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $fp = fopen($meta['real_file'], 'rb');
        if ($fp === false) {
            exit;
        }

        fseek($fp, $meta['start']);
        $chunkSize = 64 * 1024; // 64KB chunks
        $remaining = $meta['length'];

        while (!feof($fp) && $remaining > 0 && connection_status() === CONNECTION_NORMAL) {
            $toRead = ($remaining > $chunkSize) ? $chunkSize : $remaining;
            $data = fread($fp, $toRead);
            if ($data === false) {
                break;
            }
            echo $data;
            flush();
            $remaining -= strlen($data);
        }

        fclose($fp);
        exit;
    }

    /**
     * Download local file using chunked binary stream.
     */
    protected static function downloadLocalFile(string $path, string $safeFilename, string $mimeType, bool $isProtected = true): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $meta = self::buildDownloadHeaders($path, $safeFilename, $mimeType, $isProtected, $method);

        if ($meta['status'] === 404) {
            Logger::warning('Favorite Multimedia: Download file missing or invalid path', [
                'path' => str_replace('\\', '/', $path),
            ]);
            http_response_code(404);
            echo "Media file not found.";
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        foreach ($meta['headers'] as $name => $val) {
            header("{$name}: {$val}");
        }

        if (!empty($meta['is_head'])) {
            exit;
        }

        $fp = fopen($meta['real_file'], 'rb');
        if ($fp !== false) {
            while (!feof($fp) && connection_status() === CONNECTION_NORMAL) {
                echo fread($fp, 64 * 1024);
                flush();
            }
            fclose($fp);
        }
        exit;
    }

    /**
     * Safely proxy remote stream with SSRF verification.
     */
    protected static function proxyRemoteStream(string $url, string $mimeType, bool $isProtected = false): void
    {
        $security = MediaSourceResolver::validateUrlSecurity($url);
        if (!$security['safe']) {
            Logger::warning('Favorite Multimedia: Blocked unsafe remote stream', [
                'reason' => $security['reason'],
                'host'   => parse_url($url, PHP_URL_HOST),
            ]);
            http_response_code(403);
            echo "Remote media streaming blocked: " . htmlspecialchars($security['reason'], ENT_QUOTES, 'UTF-8');
            exit;
        }

        if ($isProtected) {
            header("Cache-Control: private, no-cache, no-store, must-revalidate");
            header("Pragma: no-cache");
        } else {
            header("Cache-Control: public, max-age=3600");
        }

        // For public external CDN / storage URLs that allow direct playback, redirect safely
        header("Location: {$url}", true, 302);
        exit;
    }

    /**
     * Download external media URL by proxying stream server-side with strict redirect validation.
     */
    protected static function proxyRemoteDownload(string $url, string $safeFilename, string $mimeType, bool $isProtected = true): void
    {
        $currentUrl = $url;
        $maxRedirects = 3;
        $redirectCount = 0;
        $fp = false;

        while ($redirectCount <= $maxRedirects) {
            $security = MediaSourceResolver::validateUrlSecurity($currentUrl, true);
            if (!$security['safe']) {
                Logger::warning('Favorite Multimedia: Blocked unsafe remote download redirect', [
                    'reason' => $security['reason'],
                    'host'   => parse_url($currentUrl, PHP_URL_HOST),
                ]);
                http_response_code(403);
                echo "Remote download blocked by security policy: " . htmlspecialchars($security['reason'], ENT_QUOTES, 'UTF-8');
                exit;
            }

            $opts = [
                'http' => [
                    'method'          => 'GET',
                    'timeout'         => 30,
                    'follow_location' => 0, // Manual redirect validation prevents SSRF bypasses
                    'user_agent'      => 'FavoriteCMS-Multimedia/1.0',
                ],
                'ssl' => [
                    'verify_peer' => true,
                ],
            ];
            $context = stream_context_create($opts);
            $fp = @fopen($currentUrl, 'rb', false, $context);

            if ($fp === false) {
                break;
            }

            $meta = stream_get_meta_data($fp);
            $headers = $meta['wrapper_data'] ?? [];
            $statusCode = 200;
            $location = null;

            foreach ($headers as $h) {
                if (preg_match('#^HTTP/\d\.\d\s+(\d+)#i', $h, $m)) {
                    $statusCode = (int)$m[1];
                }
                if (preg_match('#^Location:\s*(.+)$#i', $h, $m)) {
                    $location = trim($m[1]);
                }
            }

            if (in_array($statusCode, [301, 302, 303, 307, 308], true) && $location !== null) {
                fclose($fp);
                $fp = false;
                if (!str_starts_with($location, 'http://') && !str_starts_with($location, 'https://')) {
                    $parsed = parse_url($currentUrl);
                    $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
                    $location = rtrim($base, '/') . '/' . ltrim($location, '/');
                }
                $currentUrl = $location;
                $redirectCount++;
                continue;
            }

            break;
        }

        if ($fp === false) {
            Logger::error('Favorite Multimedia: Unable to connect to remote download provider', [
                'host' => parse_url($currentUrl, PHP_URL_HOST),
            ]);
            http_response_code(502);
            echo "Unable to reach remote media provider for downloading.";
            exit;
        }

        $isHead = isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'HEAD';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        header("Content-Type: {$mimeType}");
        header("Content-Disposition: attachment; filename=\"{$safeFilename}\"");
        header("Cache-Control: " . ($isProtected ? 'private, no-cache, no-store, must-revalidate' : 'public, max-age=3600'));
        if ($isProtected) {
            header("Pragma: no-cache");
        }

        if ($isHead) {
            fclose($fp);
            exit;
        }

        while (!feof($fp) && connection_status() === CONNECTION_NORMAL) {
            echo fread($fp, 64 * 1024);
            flush();
        }

        fclose($fp);
        exit;
    }

    /**
     * Deliver adaptive HLS master playlist with rewritten secure endpoint URLs.
     */
    /**
     * Deliver adaptive HLS master playlist with rewritten secure endpoint URLs.
     */
    public static function deliverHlsMaster(MediaSource $source, bool $isProtected = false): Response
    {
        $realFile = self::resolveSourceFilePath($source);
        $content = '';

        if ($realFile && file_exists($realFile)) {
            $content = (string)file_get_contents($realFile);
        } elseif (($source->storage_driver ?? 'local') === 's3' && !empty($source->storage_key)) {
            $disk = MediaStorageManager::getDisk('s3');
            $content = (string)$disk->get((string)$source->storage_key);
        }

        if (empty($content)) {
            return Response::make('Master playlist not found.', 404);
        }

        $sourceId = (int)$source->id;
        $tokenQuery = '';
        if ($isProtected) {
            $tok = MediaDeliveryTokenService::generateHlsStreamToken($sourceId, 300);
            $tokenQuery = '?token=' . rawurlencode((string)$tok['sig']) . '&exp=' . $tok['exp'];
        }

        // Check for alternative audio tracks & subtitles
        $contentType = (string)$source->content_type;
        $contentId = (int)$source->content_id;
        $audioTracks = MediaSource::getAudioTracksForContent($contentType, $contentId, true);
        $subtitles = Subtitle::getForContent($contentType, $contentId);

        $hasAudioGroup = !empty($audioTracks);
        $hasSubGroup = !empty($subtitles);

        $mediaTags = [];
        if ($hasAudioGroup) {
            foreach ($audioTracks as $audio) {
                $audioId = (int)$audio->id;
                $lang = MediaLanguageService::normalizeLanguageCode((string)($audio->language_code ?: 'en'));
                $label = (string)($audio->label ?: MediaLanguageService::getLanguageLabel($lang));
                $isDef = !empty($audio->is_default) ? 'YES' : 'NO';
                $autoSel = $isDef;
                $uri = "/api/multimedia/hls/{$sourceId}/audio/{$audioId}/index.m3u8" . $tokenQuery;
                $mediaTags[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"audio-group\",NAME=\"{$label}\",DEFAULT={$isDef},AUTOSELECT={$autoSel},LANGUAGE=\"{$lang}\",URI=\"{$uri}\"";
            }
        }

        if ($hasSubGroup) {
            foreach ($subtitles as $sub) {
                $subId = (int)$sub->id;
                $lang = $sub->getLanguageCode();
                $label = (string)($sub->label ?: MediaLanguageService::getLanguageLabel($lang));
                $isDef = !empty($sub->is_default) ? 'YES' : 'NO';
                $isForced = $sub->isForced() ? 'YES' : 'NO';
                $autoSel = ($isDef === 'YES' || $isForced === 'YES') ? 'YES' : 'NO';
                $uri = "/api/multimedia/subtitles/{$subId}" . $tokenQuery;
                $mediaTags[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subs-group\",NAME=\"{$label}\",DEFAULT={$isDef},AUTOSELECT={$autoSel},FORCED={$isForced},LANGUAGE=\"{$lang}\",URI=\"{$uri}\"";
            }
        }

        // Rewrite relative variant paths and inject media tags
        $lines = explode("\n", $content);
        $rewritten = [];
        $headerInserted = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '#EXTM3U') && !$headerInserted) {
                $rewritten[] = $line;
                foreach ($mediaTags as $mTag) {
                    $rewritten[] = $mTag;
                }
                $headerInserted = true;
                continue;
            }

            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF:')) {
                $streamLine = $line;
                if ($hasAudioGroup && !str_contains($streamLine, 'AUDIO=')) {
                    $streamLine .= ',AUDIO="audio-group"';
                }
                if ($hasSubGroup && !str_contains($streamLine, 'SUBTITLES=')) {
                    $streamLine .= ',SUBTITLES="subs-group"';
                }
                $rewritten[] = $streamLine;
                continue;
            }

            if (!str_starts_with($trimmed, '#') && !empty($trimmed)) {
                if (preg_match('#^([a-zA-Z0-9_\-]+)/index\.m3u8$#', $trimmed, $m)) {
                    $quality = $m[1];
                    $rewritten[] = "/api/multimedia/hls/{$sourceId}/{$quality}/index.m3u8" . $tokenQuery;
                    continue;
                }
            }
            $rewritten[] = $line;
        }

        $cacheControl = $isProtected ? 'private, no-cache, no-store, must-revalidate' : 'public, max-age=300';
        return Response::make(implode("\n", $rewritten), 200)
            ->header('Content-Type', 'application/vnd.apple.mpegurl; charset=utf-8')
            ->header('Cache-Control', $cacheControl);
    }

    /**
     * Deliver HLS alternate audio variant playlist.
     */
    public static function deliverAudioVariant(MediaSource $mainSource, MediaSource $audioSource, bool $isProtected = false): Response
    {
        $audioId = (int)$audioSource->id;
        $sourceId = (int)$mainSource->id;
        $tokenQuery = '';
        if ($isProtected) {
            $tok = MediaDeliveryTokenService::generateHlsStreamToken($sourceId, 300);
            $tokenQuery = '?token=' . rawurlencode((string)$tok['sig']) . '&exp=' . $tok['exp'];
        }

        $audioStreamUrl = "/api/multimedia/hls/{$sourceId}/audio/{$audioId}/stream.mp3" . $tokenQuery;
        $playlist = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:10.0,\n{$audioStreamUrl}\n#EXT-X-ENDLIST\n";

        return Response::make($playlist, 200)
            ->header('Content-Type', 'application/vnd.apple.mpegurl; charset=utf-8')
            ->header('Cache-Control', $isProtected ? 'private, no-cache, no-store, must-revalidate' : 'public, max-age=300');
    }

    /**
     * Deliver adaptive HLS variant playlist with rewritten secure segment URLs.
     */
    public static function deliverHlsVariant(MediaSource $source, string $quality, bool $isProtected = false): Response
    {
        $quality = preg_replace('/[^a-zA-Z0-9_\-]/', '', $quality);
        $realFile = self::resolveSourceFilePath($source);
        $content = '';

        if ($realFile && file_exists($realFile)) {
            $baseDir = dirname($realFile);
            $variantFile = $baseDir . '/' . $quality . '/index.m3u8';
            if (file_exists($variantFile)) {
                $content = (string)file_get_contents($variantFile);
            }
        } elseif (($source->storage_driver ?? 'local') === 's3' && !empty($source->storage_key)) {
            $disk = MediaStorageManager::getDisk('s3');
            $dir = dirname((string)$source->storage_key);
            $variantKey = ($dir === '.' ? '' : $dir . '/') . $quality . '/index.m3u8';
            $content = (string)$disk->get($variantKey);
        }

        if (empty($content)) {
            return Response::make('Variant playlist not found.', 404);
        }

        $sourceId = (int)$source->id;
        $tokenQuery = '';
        if ($isProtected) {
            $tok = MediaDeliveryTokenService::generateHlsStreamToken($sourceId, 300);
            $tokenQuery = '?token=' . rawurlencode((string)$tok['sig']) . '&exp=' . $tok['exp'];
        }

        // Rewrite relative segment paths
        $lines = explode("\n", $content);
        $rewritten = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (!str_starts_with($trimmed, '#') && !empty($trimmed)) {
                if (preg_match('#^[a-zA-Z0-9_\-\.]+\.(ts|m4s|mp4)$#i', $trimmed)) {
                    $rewritten[] = "/api/multimedia/hls/{$sourceId}/{$quality}/{$trimmed}" . $tokenQuery;
                    continue;
                }
            }
            $rewritten[] = $line;
        }

        $cacheControl = $isProtected ? 'private, no-cache, no-store, must-revalidate' : 'public, max-age=300';
        return Response::make(implode("\n", $rewritten), 200)
            ->header('Content-Type', 'application/vnd.apple.mpegurl; charset=utf-8')
            ->header('Cache-Control', $cacheControl);
    }

    /**
     * Deliver protected HLS media segment (.ts / .m4s).
     */
    public static function deliverHlsSegment(MediaSource $source, string $quality, string $segmentFilename, bool $isProtected = false): Response
    {
        $quality = preg_replace('/[^a-zA-Z0-9_\-]/', '', $quality);
        $safeSegment = basename($segmentFilename);

        // Strict path traversal and extension check
        if ($safeSegment !== $segmentFilename || !preg_match('/^[a-zA-Z0-9_\-\.]+\.(ts|m4s|mp4)$/i', $safeSegment)) {
            return Response::make('Invalid segment name.', 400);
        }

        $realFile = self::resolveSourceFilePath($source);
        $content = null;

        if ($realFile && file_exists($realFile)) {
            $baseDir = dirname($realFile);
            $segmentPath = $baseDir . '/' . $quality . '/' . $safeSegment;
            if (file_exists($segmentPath)) {
                $content = (string)file_get_contents($segmentPath);
            }
        } elseif (($source->storage_driver ?? 'local') === 's3' && !empty($source->storage_key)) {
            $disk = MediaStorageManager::getDisk('s3');
            $dir = dirname((string)$source->storage_key);
            $segKey = ($dir === '.' ? '' : $dir . '/') . $quality . '/' . $safeSegment;
            $content = $disk->get($segKey);
        }

        if ($content === null) {
            return Response::make('Segment file not found.', 404);
        }

        $ext = strtolower(pathinfo($safeSegment, PATHINFO_EXTENSION));
        $mime = ($ext === 'ts') ? 'video/mp2t' : 'video/iso.segment';

        $cacheControl = $isProtected ? 'private, no-cache, no-store, must-revalidate' : 'public, max-age=86400';

        return Response::make($content, 200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', $cacheControl)
            ->header('Content-Length', (string)strlen($content));
    }

    public static function resolveSourceFilePath(MediaSource $source): ?string
    {
        $path = (string)$source->url_or_path;
        $normalized = str_replace('\\', '/', $path);

        if (file_exists($normalized)) {
            return realpath($normalized) ?: $normalized;
        }

        $appRoot = defined('APP_ROOT') ? str_replace('\\', '/', APP_ROOT) : '.';
        $candidate = rtrim($appRoot, '/') . '/' . ltrim($normalized, '/');
        if (file_exists($candidate)) {
            return realpath($candidate) ?: $candidate;
        }

        return null;
    }
}

