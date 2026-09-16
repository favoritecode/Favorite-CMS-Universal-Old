<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;

/**
 * DownloadSource Model
 *
 * Represents a discrete downloadable source attached to a Movie, Episode, or Song.
 * Enables multiple download links (resolutions, mirrors, audio encodings) per content.
 */
class DownloadSource extends BaseModel
{
    protected static string $table = 'multimedia_download_sources';

    /**
     * Retrieve all download sources for given content item.
     *
     * @param string $contentType 'movie', 'episode', 'song'
     * @param int $contentId
     * @param bool $activeOnly When true, returns only active rows
     * @return static[]
     */
    public static function getForContent(string $contentType, int $contentId, bool $activeOnly = true): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "content_type = ? AND content_id = ?";
        $params = [$contentType, $contentId];

        if ($activeOnly) {
            $where .= " AND is_active = 1";
        }

        $sql = "SELECT * FROM multimedia_download_sources WHERE {$where} ORDER BY sort_order ASC, id ASC";
        try {
            $rows = $db->select($sql, $params);
            return array_map(fn($r) => new static((array)$r), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Infer file format / extension from URL or path.
     */
    public static function inferFormat(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }
        return strtoupper($ext);
    }

    /**
     * Infer provider or hosting service from URL.
     */
    public static function inferProvider(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (str_contains($host, 'drive.google.com')) {
            return 'Google Drive';
        }
        if (str_contains($host, 'dropbox.com')) {
            return 'Dropbox';
        }
        if (str_contains($host, 'mega.nz') || str_contains($host, 'mega.io')) {
            return 'Mega';
        }
        if (str_contains($host, 'mediafire.com')) {
            return 'MediaFire';
        }
        if (str_contains($host, 'onedrive.live.com') || str_contains($host, '1drv.ms')) {
            return 'OneDrive';
        }
        return 'Direct';
    }

    /**
     * Generate user-facing display title for frontend selectors and dropdowns.
     * E.g. "1080p — MP4 — Google Drive" or "Audio — MP3 — Server 1"
     */
    public function getDisplayTitle(): string
    {
        $label = trim((string)($this->label ?? ''));
        $quality = trim((string)($this->quality ?? ''));
        $format = trim((string)($this->format ?? ''));
        $provider = trim((string)($this->provider ?? ''));

        // If label is custom and descriptive (not generic "Download" or "Direct Download"), use it
        if ($label !== '' && !in_array(strtolower($label), ['download', 'direct download', 'default'], true)) {
            $parts = [];
            if ($quality !== '' && !str_contains(strtolower($label), strtolower($quality))) {
                $parts[] = $quality;
            }
            if ($format !== '' && !str_contains(strtolower($label), strtolower($format))) {
                $parts[] = $format;
            }
            if (!empty($parts)) {
                return $label . ' (' . implode(' — ', $parts) . ')';
            }
            return $label;
        }

        // Build descriptive title from quality, format, provider
        $components = [];
        if ($quality !== '') {
            $components[] = $quality;
        }
        if ($format !== '') {
            $components[] = $format;
        }
        if ($provider !== '') {
            $components[] = $provider;
        } elseif ($label !== '') {
            $components[] = $label;
        }

        if (empty($components)) {
            return 'Direct Download';
        }

        return implode(' — ', $components);
    }

    /**
     * Secondary metadata description string.
     */
    public function getMetaDescription(): string
    {
        $parts = [];
        if (!empty($this->quality)) {
            $parts[] = $this->quality;
        }
        if (!empty($this->format)) {
            $parts[] = strtoupper($this->format);
        }
        if (!empty($this->provider)) {
            $parts[] = $this->provider;
        }
        return implode(' • ', $parts);
    }

    /**
     * Validate whether the URL is secure and compliant with policies.
     */
    public function isValidUrl(): bool
    {
        $url = trim((string)($this->url ?? ''));
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        $lower = strtolower($url);
        $dangerousSchemes = ['javascript:', 'data:', 'file:', 'vbscript:', 'phar:', 'php:', 'gopher:', 'dict:', 'ldap:'];
        foreach ($dangerousSchemes as $danger) {
            if (str_starts_with($lower, $danger)) {
                return false;
            }
        }

        if (str_starts_with($url, '//') || str_contains($url, '..')) {
            return false;
        }

        // If it is an HTTP/HTTPS URL
        if (preg_match('#^https?://#i', $url)) {
            $check = MediaSourceResolver::validateUrlSecurity($url, false);
            return !empty($check['safe']);
        }

        // Any other scheme (e.g. ftp:, javascript:, custom:) is rejected
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url)) {
            return false;
        }

        return true;
    }
}
