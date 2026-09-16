<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Models\Setting;

class MediaSourceResolver
{
    /**
     * Extracts the target URL from an iframe embed snippet if provided.
     */
    public static function extractIframeUrl(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        // Check if input contains an <iframe> tag
        if (stripos($input, '<iframe') !== false) {
            if (preg_match('/<iframe\b[^>]*\bsrc=(?:["\']([^"\']+)["\']|([^\s>]+))/i', $input, $matches)) {
                $src = trim(!empty($matches[1]) ? $matches[1] : ($matches[2] ?? ''));
                $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with($src, '//')) {
                    $src = 'https:' . $src;
                }
                return $src;
            }
        }

        return $input;
    }

    /**
     * Resolves a media URL or local upload path into structured playback and download metadata.
     *
     * @param string $urlOrPath The source URL or local path
     * @param string $mode 'url' or 'upload' or 'auto'
     * @param string|null $manualType Optional manual override: 'video', 'hls', 'audio', 'embed'
     * @return array
     */
    public static function resolve(string $urlOrPath, string $mode = 'auto', ?string $manualType = null): array
    {
        $urlOrPath = self::extractIframeUrl($urlOrPath);
        if ($urlOrPath === '') {
            return [
                'valid'             => false,
                'source_mode'       => 'unknown',
                'source_type'       => 'unknown',
                'player_type'       => 'none',
                'playable_url'      => '',
                'mime_type'         => '',
                'is_downloadable'   => false,
                'download_method'   => 'none',
                'error'             => 'Source URL or path cannot be empty.',
            ];
        }

        // Determine mode: upload vs url
        if ($mode === 'auto') {
            $isUpload = str_starts_with($urlOrPath, '/') || str_starts_with($urlOrPath, 'uploads/') || str_starts_with($urlOrPath, 'storage/');
            $mode = $isUpload ? 'upload' : 'url';
        }

        // Validate URL security if mode is 'url'
        if ($mode === 'url') {
            $validation = self::validateUrlSecurity($urlOrPath);
            if (!$validation['safe']) {
                return [
                    'valid'             => false,
                    'source_mode'       => 'url',
                    'source_type'       => 'unknown',
                    'player_type'       => 'none',
                    'playable_url'      => '',
                    'mime_type'         => '',
                    'is_downloadable'   => false,
                    'download_method'   => 'none',
                    'error'             => $validation['reason'],
                ];
            }
        }

        // Manual type override if specified
        if ($manualType !== null && in_array($manualType, ['video', 'hls', 'audio', 'embed'], true)) {
            if ($mode === 'embed' || $manualType === 'embed') {
                $host = strtolower((string)parse_url($urlOrPath, PHP_URL_HOST));
                if (!self::isEmbedDomainAllowed($urlOrPath)) {
                    return [
                        'valid'             => false,
                        'source_mode'       => $mode,
                        'source_type'       => 'embed',
                        'player_type'       => 'none',
                        'playable_url'      => '',
                        'mime_type'         => 'text/html',
                        'is_downloadable'   => false,
                        'download_method'   => 'none',
                        'error'             => "The domain for this embed player is not in the trusted allowlist ({$host}). Please add it under Multimedia Settings.",
                    ];
                }
            }
            $detected = ($manualType === 'embed')
                ? (self::detectEmbedService($urlOrPath) ?? [
                    'type'      => 'embed',
                    'mime_type' => 'text/html',
                    'embed_url' => $urlOrPath,
                    'service'   => 'generic',
                ])
                : self::detectSourceType($urlOrPath);
            return self::buildResolvedResult($urlOrPath, $mode, $manualType, $detected);
        }

        // Automatic type detection
        $detected = self::detectSourceType($urlOrPath);

        return self::buildResolvedResult($urlOrPath, $mode, $detected['type'], $detected);
    }

    /**
     * Strictly validate URL schemes and protect against SSRF and protocol exploits.
     * @param string $url The URL to validate
     * @param bool $validateDns When true, resolves the hostname to check for private/internal IPs (used during server-side fetches/proxies)
     */
    public static function validateUrlSecurity(string $url, bool $validateDns = false): array
    {
        $url = self::extractIframeUrl($url);
        // 1. Reject control characters and attribute delimiters
        if (preg_match('/[\x00-\x1F\x7F"\'<>]/', $url)) {
            return ['safe' => false, 'reason' => 'URL contains forbidden control characters or attribute delimiters.'];
        }

        // 2. Reject dangerous schemes
        $lower = strtolower($url);
        $dangerousSchemes = ['javascript:', 'data:', 'file:', 'vbscript:', 'phar:', 'php:', 'gopher:', 'dict:', 'ldap:'];
        foreach ($dangerousSchemes as $danger) {
            if (str_starts_with($lower, $danger)) {
                return ['safe' => false, 'reason' => "Dangerous URL scheme '{$danger}' is strictly rejected."];
            }
        }

        // 3. Reject protocol-relative URLs (e.g. //evil.com)
        if (str_starts_with($url, '//')) {
            return ['safe' => false, 'reason' => 'Protocol-relative URLs are not permitted.'];
        }

        // 4. Validate parse_url
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return ['safe' => false, 'reason' => 'Invalid URL structure. Must be a complete HTTP/HTTPS URL.'];
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['safe' => false, 'reason' => "Only HTTP and HTTPS protocols are permitted. Found: {$scheme}."];
        }

        // 5. SSRF validation on host
        $host = strtolower($parsed['host']);

        // Check for localhost or loopback name
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return ['safe' => false, 'reason' => 'Access to internal or loopback hostnames is forbidden (SSRF protection).'];
        }

        // Normalize bracketed IPv6 hosts (e.g. [::1])
        $cleanHost = trim($host, '[]');

        // Check standard IP addresses
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            if (self::isPrivateOrReservedIp($cleanHost)) {
                return ['safe' => false, 'reason' => 'Access to private, loopback, or cloud-metadata IP addresses is forbidden.'];
            }
        }

        // Check decimal integer hosts (e.g. 2130706433 -> 127.0.0.1)
        if (ctype_digit($cleanHost) && (float)$cleanHost <= 4294967295) {
            $decimalIp = long2ip((int)$cleanHost);
            if ($decimalIp && self::isPrivateOrReservedIp($decimalIp)) {
                return ['safe' => false, 'reason' => 'Access to private, loopback, or cloud-metadata IP addresses is forbidden.'];
            }
        }

        // Check hex hosts (e.g. 0x7f000001 -> 127.0.0.1)
        if (preg_match('/^0x[0-9a-f]+$/i', $cleanHost)) {
            $hexIp = long2ip((int)hexdec($cleanHost));
            if ($hexIp && self::isPrivateOrReservedIp($hexIp)) {
                return ['safe' => false, 'reason' => 'Access to private, loopback, or cloud-metadata IP addresses is forbidden.'];
            }
        }

        // Check octal-notation IPv4 addresses (e.g. 0177.0.0.1 -> 127.0.0.1)
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/', $cleanHost, $octMatches)) {
            $hasOctal = false;
            $parts = [];
            for ($i = 1; $i <= 4; $i++) {
                $seg = $octMatches[$i];
                if (strlen($seg) > 1 && str_starts_with($seg, '0')) {
                    $hasOctal = true;
                    $parts[] = octdec($seg);
                } else {
                    $parts[] = (int)$seg;
                }
            }
            if ($hasOctal) {
                $normalizedOctalIp = implode('.', $parts);
                if (self::isPrivateOrReservedIp($normalizedOctalIp)) {
                    return ['safe' => false, 'reason' => 'Access to private, loopback, or cloud-metadata IP addresses is forbidden.'];
                }
            }
        }

        // 6. Optional live DNS rebinding check for server-side network operations:
        // By default false for client-side playback URLs saved via admin forms to prevent production hangs.
        // Set to true when the server itself initiates an outbound fetch/proxy to remote media.
        if ($validateDns && !filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            $resolvedIps = @gethostbynamel($cleanHost);
            if (is_array($resolvedIps)) {
                foreach ($resolvedIps as $rIp) {
                    if (self::isPrivateOrReservedIp($rIp)) {
                        return ['safe' => false, 'reason' => "Hostname resolves to private/reserved IP: {$rIp} (SSRF protection)."];
                    }
                }
            }
        }

        return ['safe' => true, 'reason' => ''];
    }

    /**
     * Check if an IP address belongs to private, loopback, link-local, or cloud metadata ranges.
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Check IPv4 & IPv6 with FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // Explicit check for cloud metadata service (169.254.169.254) and link-local (169.254.0.0/16)
        if (str_starts_with($ip, '169.254.')) {
            return true;
        }

        // Explicit check for IPv6 loopback and link-local
        if ($ip === '::1' || str_starts_with(strtolower($ip), 'fe80:')) {
            return true;
        }

        return false;
    }

    /**
     * Detect media type from URL or path using explicit deterministic precedence:
     * 1. Known third-party Embed services (YouTube, Vimeo, Dailymotion, SoundCloud)
     * 2. HLS Streaming (.m3u8)
     * 3. Direct Video extensions (mp4, webm, ogv, mov, m4v)
     * 4. Direct Audio extensions (mp3, m4a, aac, wav, ogg, oga, flac, opus)
     * 5. Allowed Generic Embed Domain
     * 6. Unknown (do not blindly infer embed or fabricate MP4 MIME)
     */
    public static function detectSourceType(string $urlOrPath): array
    {
        $cleanPath = parse_url($urlOrPath, PHP_URL_PATH) ?? $urlOrPath;
        $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));

        // 1. Known third-party Embed services (YouTube, Vimeo, etc. must never become direct video)
        $embed = self::detectEmbedService($urlOrPath);
        if ($embed !== null) {
            return [
                'type'         => 'embed',
                'mime_type'    => 'text/html',
                'embed_url'    => $embed['embed_url'],
                'service'      => $embed['service'],
            ];
        }

        // 2. Check HLS Streaming (.m3u8 manifest)
        if ($ext === 'm3u8' || str_contains(strtolower($urlOrPath), '.m3u8')) {
            return [
                'type'      => 'hls',
                'mime_type' => 'application/x-mpegURL',
            ];
        }

        // 3. Direct Video extensions
        $videoExtensions = [
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'ogv'  => 'video/ogg',
            'mov'  => 'video/quicktime',
            'm4v'  => 'video/mp4',
        ];
        if (isset($videoExtensions[$ext])) {
            return [
                'type'      => 'video',
                'mime_type' => $videoExtensions[$ext],
            ];
        }

        // 4. Direct Audio extensions
        $audioExtensions = [
            'mp3'  => 'audio/mpeg',
            'm4a'  => 'audio/mp4',
            'aac'  => 'audio/aac',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            'oga'  => 'audio/ogg',
            'flac' => 'audio/flac',
            'opus' => 'audio/opus',
        ];
        if (isset($audioExtensions[$ext])) {
            return [
                'type'      => 'audio',
                'mime_type' => $audioExtensions[$ext],
            ];
        }

        // 5. Allowed Generic Embed Domain
        if (self::isEmbedDomainAllowed($urlOrPath)) {
            return [
                'type'         => 'embed',
                'mime_type'    => 'text/html',
                'embed_url'    => $urlOrPath,
                'service'      => 'generic',
            ];
        }

        // 6. Inconclusive / Unknown type (do not infer embed or fabricate MIME)
        return [
            'type'      => 'unknown',
            'mime_type' => '',
        ];
    }

    /**
     * Check if a hostname belongs to known default trusted embed services.
     * Kept strictly conservative to authoritative mainstream platforms.
     * Other third-party/ad-backed providers require explicit administrator allowlisting
     * via Multimedia Settings (trusted_embed_domains).
     */
    public static function isKnownEmbedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        $knownDomains = [
            'youtube.com', 'youtu.be', 'youtube-nocookie.com',
            'vimeo.com', 'player.vimeo.com',
            'dailymotion.com', 'dai.ly',
            'soundcloud.com', 'w.soundcloud.com',
            'videodelivery.net', 'mediadelivery.net',
            'twitch.tv', 'wistia.net', 'wistia.com',
            'rumble.com', 'mux.com', 'spotify.com',
            'drive.google.com', 'archive.org',
        ];

        foreach ($knownDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a URL or host belongs to a trusted external embed provider.
     */
    public static function isTrustedEmbedDomain(string $urlOrHost): bool
    {
        $urlOrHost = trim($urlOrHost);
        if ($urlOrHost === '') {
            return false;
        }

        // If it looks like a URL, extract host
        if (str_contains($urlOrHost, '://') || str_starts_with($urlOrHost, '//')) {
            $extracted = self::extractIframeUrl($urlOrHost);
            if (str_starts_with($extracted, '//')) {
                $extracted = 'https:' . $extracted;
            }
            $parsed = parse_url($extracted);
            $host = strtolower((string)($parsed['host'] ?? ''));
        } else {
            // Strip any protocol, path or port
            $clean = preg_replace('#^https?://#i', '', $urlOrHost);
            $clean = explode('/', $clean)[0];
            $clean = explode(':', $clean)[0];
            $host = strtolower(trim($clean));
        }

        if ($host === '') {
            return false;
        }

        // Check against known trusted services first
        if (self::isKnownEmbedHost($host)) {
            return true;
        }

        // Reject IP addresses, localhost, and internal hostnames as embed hosts (SSRF prevention)
        if (filter_var($host, FILTER_VALIDATE_IP) || self::isPrivateOrReservedIp($host) || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        // Load configured trusted embed domains
        $setting = (string)Setting::get('multimedia', 'trusted_embed_domains', '');
        if (trim($setting) === '') {
            return false;
        }

        $lines = preg_split('/[\r\n,;\s]+/', $setting);
        foreach ($lines as $line) {
            $raw = strtolower(trim($line));
            if ($raw === '') {
                continue;
            }
            // Strip scheme (https://, http://) if entered by admin
            $clean = preg_replace('#^https?://#i', '', $raw);
            // Strip any path or trailing slash (e.g. domain.com/ -> domain.com)
            $clean = explode('/', $clean)[0];
            // Strip port if present (e.g. domain.com:443 -> domain.com)
            $clean = explode(':', $clean)[0];
            $clean = trim($clean);
            if ($clean === '') {
                continue;
            }

            $allowed = ltrim($clean, '*.');
            if ($allowed === '') {
                continue;
            }

            // Exact hostname match
            if ($host === $allowed) {
                return true;
            }

            // Wildcard or subdomain match (e.g. *.example.com or example.com matching player.example.com)
            if (str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify if an embed URL comes from a trusted domain allowlist.
     */
    public static function isEmbedDomainAllowed(string $url): bool
    {
        return self::isTrustedEmbedDomain($url);
    }

    /**
     * Determine the iframe sandbox policy for an embed URL.
     *
     * Strict Mode: 'allow-scripts allow-same-origin allow-forms'
     * Compatible Mode: 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-presentation'
     * Off-for-trusted: null (sandbox attribute omitted for trusted domains only)
     *
     * Invariant: UNTRUSTED domains are ALWAYS forced to strict sandbox and cannot bypass sandbox.
     */
    public static function getEmbedSandboxPolicy(string $url, ?string $requestedSandboxMode = null): ?string
    {
        $isTrusted = self::isTrustedEmbedDomain($url);

        // Security invariant: UNTRUSTED domains ALWAYS get strict sandbox and CANNOT disable sandbox
        if (!$isTrusted) {
            return 'allow-scripts allow-same-origin allow-forms';
        }

        // Trusted provider: resolve configured mode
        $globalMode = (string)Setting::get('multimedia', 'embed_sandbox_mode', 'compatible');
        $mode = strtolower(trim((string)($requestedSandboxMode ?: $globalMode)));

        // Support URL fragment overrides for specific source testing (e.g. #sandbox=off, #sandbox=strict)
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        if ($fragment) {
            parse_str($fragment, $fragParams);
            if (!empty($fragParams['sandbox'])) {
                $mode = strtolower(trim((string)$fragParams['sandbox']));
            }
        }

        if (in_array($mode, ['off', 'off-for-trusted-only', 'none', 'disabled', 'false', '0'], true)) {
            return null; // Omit sandbox attribute completely
        }

        if ($mode === 'strict') {
            return 'allow-scripts allow-same-origin allow-forms';
        }

        // Compatible mode (default for trusted domains)
        return 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-presentation';
    }

    /**
     * Centralized allow attribute for embed player iframes.
     */
    public static function getEmbedAllowAttribute(?string $url = null): string
    {
        return 'autoplay; fullscreen; encrypted-media; picture-in-picture';
    }

    /**
     * Centralized referrerpolicy attribute for embed player iframes.
     */
    public static function getEmbedReferrerPolicy(?string $url = null): string
    {
        return 'no-referrer-when-downgrade';
    }

    /**
     * Detect known embed services (YouTube, Vimeo, Dailymotion, SoundCloud) with exact host verification.
     */
    public static function detectEmbedService(string $url): ?array
    {
        $url = self::extractIframeUrl($url);
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';

        // YouTube
        $youtubeHosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com'];
        if (in_array($host, $youtubeHosts, true)) {
            $videoId = null;
            if ($host === 'youtu.be') {
                $parts = explode('/', trim($path, '/'));
                $videoId = $parts[0] ?? null;
            } elseif (str_starts_with($path, '/embed/')) {
                $parts = explode('/', trim($path, '/'));
                $videoId = $parts[1] ?? basename($path);
            } elseif (str_starts_with($path, '/shorts/')) {
                $parts = explode('/', trim($path, '/'));
                $videoId = $parts[1] ?? basename($path);
            } elseif (str_starts_with($path, '/watch')) {
                parse_str($parsed['query'] ?? '', $query);
                $videoId = $query['v'] ?? null;
            }

            if ($videoId && preg_match('/^[a-zA-Z0-9_\-]{6,25}$/', $videoId)) {
                return [
                    'service'   => 'youtube',
                    'embed_url' => "https://www.youtube-nocookie.com/embed/{$videoId}",
                ];
            }
        }

        // Vimeo
        $vimeoHosts = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];
        if (in_array($host, $vimeoHosts, true)) {
            if (preg_match('/(\d{5,15})/', $path, $matches)) {
                return [
                    'service'   => 'vimeo',
                    'embed_url' => "https://player.vimeo.com/video/{$matches[1]}",
                ];
            }
        }

        // Dailymotion
        $dailyHosts = ['dailymotion.com', 'www.dailymotion.com', 'dai.ly'];
        if (in_array($host, $dailyHosts, true)) {
            $videoCode = null;
            if ($host === 'dai.ly') {
                $videoCode = trim($path, '/');
            } elseif (preg_match('#/video/([a-zA-Z0-9]+)#', $path, $matches)) {
                $videoCode = $matches[1];
            }
            if ($videoCode) {
                return [
                    'service'   => 'dailymotion',
                    'embed_url' => "https://www.dailymotion.com/embed/video/{$videoCode}",
                ];
            }
        }

        // SoundCloud
        $soundCloudHosts = ['soundcloud.com', 'www.soundcloud.com', 'w.soundcloud.com'];
        if (in_array($host, $soundCloudHosts, true)) {
            $encodedUrl = urlencode($url);
            return [
                'service'   => 'soundcloud',
                'embed_url' => "https://w.soundcloud.com/player/?url={$encodedUrl}&color=%232563eb&auto_play=false&show_artwork=true",
            ];
        }

        return null;
    }

    private static function buildResolvedResult(string $urlOrPath, string $mode, string $type, ?array $detected): array
    {
        $playableUrl = $urlOrPath;
        $mimeType = $detected['mime_type'] ?? '';

        if ($type === 'embed') {
            $playableUrl = $detected['embed_url'] ?? $urlOrPath;
            $playerType = 'embed';
            if ($mimeType === '') {
                $mimeType = 'text/html';
            }
            // Embeds are not directly downloadable files
            $isDownloadable = false;
            $downloadMethod = 'none';
        } elseif ($type === 'hls') {
            $playerType = 'video';
            $mimeType = 'application/x-mpegURL';
            // M3U8 is streaming manifest: do not promise arbitrary segment download
            $isDownloadable = false;
            $downloadMethod = 'none';
        } elseif ($type === 'video') {
            $playerType = 'video';
            if ($mimeType === '' && $mode === 'upload') {
                $mimeType = 'video/mp4';
            }
            $isDownloadable = true;
            $downloadMethod = ($mode === 'upload') ? 'direct' : 'stream_proxy';
        } elseif ($type === 'audio') {
            $playerType = 'audio';
            if ($mimeType === '' && $mode === 'upload') {
                $mimeType = 'audio/mpeg';
            }
            $isDownloadable = true;
            $downloadMethod = ($mode === 'upload') ? 'direct' : 'stream_proxy';
        } else {
            // unknown
            $type = 'unknown';
            $playerType = 'none';
            $isDownloadable = false;
            $downloadMethod = 'none';
        }

        return [
            'valid'             => ($type !== 'unknown'),
            'source_mode'       => $mode,
            'source_type'       => $type,
            'player_type'       => $playerType,
            'playable_url'      => $playableUrl,
            'mime_type'         => $mimeType,
            'is_downloadable'   => $isDownloadable,
            'download_method'   => $downloadMethod,
            'error'             => ($type === 'unknown') ? 'Unknown Media Type. Please select source type manually.' : null,
        ];
    }
}

