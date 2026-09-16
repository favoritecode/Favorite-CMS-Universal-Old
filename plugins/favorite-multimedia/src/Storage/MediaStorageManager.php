<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Storage;

use FavoriteCMS\Core\Logger;
use FavoriteCMS\Models\Setting;

class MediaStorageManager
{
    protected static array $instances = [];

    public static function resetInstances(): void
    {
        self::$instances = [];
    }

    public static function setDisk(string $driver, MultimediaStorageInterface $storage): void
    {
        self::$instances[strtolower(trim($driver))] = $storage;
    }

    /**
     * Get configured storage driver instance.
     */
    public static function getDisk(?string $driver = null): MultimediaStorageInterface
    {
        if ($driver === null) {
            $driver = (string)Setting::get('multimedia', 'storage_driver', 'local');
        }

        $driver = strtolower(trim($driver));
        if ($driver !== 's3') {
            $driver = 'local';
        }

        if (isset(self::$instances[$driver])) {
            return self::$instances[$driver];
        }

        if ($driver === 's3') {
            $config = [
                'endpoint'      => Setting::get('multimedia', 's3_endpoint', 'https://s3.amazonaws.com'),
                'region'        => Setting::get('multimedia', 's3_region', 'us-east-1'),
                'bucket'        => Setting::get('multimedia', 's3_bucket', ''),
                'access_key'    => Setting::get('multimedia', 's3_access_key', ''),
                'secret_key'    => Setting::get('multimedia', 's3_secret_key', ''),
                'path_prefix'   => Setting::get('multimedia', 's3_path_prefix', 'multimedia'),
                'cdn_base_url'  => Setting::get('multimedia', 'cdn_base_url', ''),
                'use_path_style'=> (bool)Setting::get('multimedia', 's3_path_style', '1'),
            ];
            self::$instances['s3'] = new S3CompatibleMultimediaStorage($config);
            return self::$instances['s3'];
        }

        self::$instances['local'] = new LocalMultimediaStorage();
        return self::$instances['local'];
    }

    /**
     * Build safe, deterministic storage key.
     */
    public static function buildKey(string $contentType, int $contentId, string $subfolder, string $filename): string
    {
        $safeType = preg_replace('/[^a-zA-Z0-9_\-]/', '', $contentType) ?: 'media';
        $safeId   = max(1, $contentId);
        $safeSub  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $subfolder) ?: 'general';
        $safeFile = self::sanitizeFilename($filename);

        return "{$safeType}/{$safeId}/{$safeSub}/{$safeFile}";
    }

    /**
     * Sanitize filename, handling Bangla/Unicode characters safely.
     */
    public static function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(['\\', '/', "\0"], '', $filename);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Strip dangerous chars, keep letters, numbers, hyphens, underscores
        $clean = preg_replace('/[^\p{L}\p{N}_\-\.]/u', '_', $name) ?? 'media';
        $clean = trim($clean, '._-');
        if ($clean === '') {
            $clean = 'media_' . time();
        }

        return $ext !== '' ? "{$clean}.{$ext}" : $clean;
    }

    /**
     * Validate S3 endpoint to prevent SSRF vulnerabilities.
     */
    public static function validateEndpointSecurity(string $endpoint): array
    {
        $parsed = parse_url($endpoint);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return ['valid' => false, 'reason' => 'Invalid endpoint URL format.'];
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'reason' => 'Only HTTP and HTTPS endpoints are permitted.'];
        }

        $host = strtolower($parsed['host']);

        // Disallow credentials in endpoint URL
        if (!empty($parsed['user']) || !empty($parsed['pass'])) {
            return ['valid' => false, 'reason' => 'User credentials in endpoint URL are forbidden.'];
        }

        // Dangerous cloud metadata endpoints
        $forbiddenHosts = [
            '169.254.169.254', // AWS/Azure/GCP metadata
            'metadata.google.internal',
            'instance-data',
        ];

        if (in_array($host, $forbiddenHosts, true)) {
            return ['valid' => false, 'reason' => 'Access to internal metadata services is prohibited.'];
        }

        return ['valid' => true, 'reason' => ''];
    }
}
