<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Models\Setting;

final class UploadSecurityService
{
    public const CATEGORY_VIDEO = 'video';
    public const CATEGORY_AUDIO = 'audio';
    public const CATEGORY_IMAGE = 'image';
    public const CATEGORY_SUBTITLE = 'subtitle';

    private const DANGEROUS_EXTENSIONS = [
        'php', 'phar', 'phtml', 'pht', 'php3', 'php4', 'php5', 'php7', 'php8',
        'cgi', 'pl', 'py', 'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd', 'com', 'msi', 'bin',
        'html', 'htm', 'shtml', 'svg', 'svgz', 'xml', 'js', 'jsp', 'asp', 'aspx', 'vbs',
        'htaccess', 'htpasswd', 'env', 'config', 'ini', 'sql'
    ];

    private const ALLOWED_EXTENSIONS = [
        self::CATEGORY_VIDEO    => ['mp4', 'webm', 'mkv', 'mov', 'm4v', 'avi'],
        self::CATEGORY_AUDIO    => ['mp3', 'm4a', 'flac', 'wav', 'aac', 'ogg', 'wma'],
        self::CATEGORY_IMAGE    => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'],
        self::CATEGORY_SUBTITLE => ['vtt', 'srt'],
    ];

    /**
     * Validate an uploaded file for security, MIME integrity, extension safety, and size limits.
     *
     * @param array{tmp_name: string, name?: string, size?: int, error?: int, type?: string} $file
     * @param string $category 'video'|'audio'|'image'|'subtitle'
     * @return array{valid: bool, error: ?string, sanitized_name: string, mime: string, size: int}
     */
    public static function validate(array $file, string $category): array
    {
        // 1. Basic PHP upload error check
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            return [
                'valid'          => false,
                'error'          => self::resolveUploadErrorMessage($error),
                'sanitized_name' => '',
                'mime'           => '',
                'size'           => 0,
            ];
        }

        $tmpPath = (string)$file['tmp_name'];
        if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
            return [
                'valid'          => false,
                'error'          => 'Temporary uploaded file is unreadable or missing.',
                'sanitized_name' => '',
                'mime'           => '',
                'size'           => 0,
            ];
        }

        $rawName = (string)($file['name'] ?? 'upload');
        $size = (int)($file['size'] ?? @filesize($tmpPath) ?: 0);

        // 2. Traversal, null-byte, and filename safety
        if (str_contains($rawName, "\0") || str_contains($rawName, '../') || str_contains($rawName, '..\\')) {
            return [
                'valid'          => false,
                'error'          => 'Malicious characters or directory traversal detected in filename.',
                'sanitized_name' => '',
                'mime'           => '',
                'size'           => $size,
            ];
        }

        // 3. Double-extension & executable check
        $nameParts = explode('.', strtolower($rawName));
        if (count($nameParts) > 1) {
            $finalExt = end($nameParts);
            // Check all segments for executable extensions
            foreach (array_slice($nameParts, 0, -1) as $segment) {
                if (in_array($segment, self::DANGEROUS_EXTENSIONS, true)) {
                    return [
                        'valid'          => false,
                        'error'          => 'Double-extension or dangerous payload pattern rejected for security.',
                        'sanitized_name' => '',
                        'mime'           => '',
                        'size'           => $size,
                    ];
                }
            }

            if (in_array($finalExt, self::DANGEROUS_EXTENSIONS, true)) {
                return [
                    'valid'          => false,
                    'error'          => "Executable or script extension '.{$finalExt}' is strictly prohibited.",
                    'sanitized_name' => '',
                    'mime'           => '',
                    'size'           => $size,
                ];
            }
        } else {
            $finalExt = '';
        }

        // 4. Category-specific extension validation
        $allowedExts = self::ALLOWED_EXTENSIONS[$category] ?? [];
        if (!in_array($finalExt, $allowedExts, true)) {
            return [
                'valid'          => false,
                'error'          => "Invalid file format (.{$finalExt}). Allowed formats for {$category}: " . implode(', ', $allowedExts) . '.',
                'sanitized_name' => '',
                'mime'           => '',
                'size'           => $size,
            ];
        }

        // 5. Size limit check
        $maxBytes = self::getMaxUploadBytes($category);
        if ($size > $maxBytes) {
            $maxMb = round($maxBytes / (1024 * 1024), 1);
            return [
                'valid'          => false,
                'error'          => "File size exceeds the maximum limit for {$category} ({$maxMb} MB).",
                'sanitized_name' => '',
                'mime'           => '',
                'size'           => $size,
            ];
        }

        // 6. Server-side authoritative MIME detection using finfo
        $detectedMime = self::detectMimeType($tmpPath);
        if (!self::isMimeCompatibleWithCategory($detectedMime, $category, $finalExt)) {
            return [
                'valid'          => false,
                'error'          => "File content signature ({$detectedMime}) does not match the expected {$category} media type.",
                'sanitized_name' => '',
                'mime'           => $detectedMime,
                'size'           => $size,
            ];
        }

        // 7. Deep inspection for SVG or script-bearing active payloads
        if (self::containsSvgOrScriptPayload($tmpPath)) {
            return [
                'valid'          => false,
                'error'          => 'SVG and script-bearing active payloads are strictly prohibited for security reasons.',
                'sanitized_name' => '',
                'mime'           => $detectedMime,
                'size'           => $size,
            ];
        }

        $sanitized = self::sanitizeFilename($rawName, $finalExt);

        return [
            'valid'          => true,
            'error'          => null,
            'sanitized_name' => $sanitized,
            'mime'           => $detectedMime,
            'size'           => $size,
        ];
    }

    /**
     * Check if a file contains SVG markup, embedded scripts, or dangerous active payloads (including SVGZ).
     */
    public static function containsSvgOrScriptPayload(string $filePath): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        $handle = @fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }
        $header = (string)fread($handle, 524288);
        fclose($handle);

        if ($header === '') {
            return false;
        }

        // Check for gzip magic bytes (\x1f\x8b), typical of SVGZ
        if (str_starts_with($header, "\x1f\x8b")) {
            $decompressed = @gzdecode($header);
            if ($decompressed === false && function_exists('gzinflate')) {
                $decompressed = @gzinflate(substr($header, 10));
            }
            if (is_string($decompressed) && $decompressed !== '') {
                if (self::hasSvgOrScriptSignatures($decompressed)) {
                    return true;
                }
            }
        }

        return self::hasSvgOrScriptSignatures($header);
    }

    /**
     * Inspect raw content for SVG tags, script tags, foreignObject, event handlers, and javascript pseudo-protocols.
     */
    public static function hasSvgOrScriptSignatures(string $content): bool
    {
        $lower = strtolower($content);

        // SVG markers
        if (str_contains($lower, '<svg') || str_contains($lower, 'xmlns="http://www.w3.org/2000/svg"') || str_contains($lower, "xmlns='http://www.w3.org/2000/svg'")) {
            return true;
        }

        // Script markers
        if (str_contains($lower, '<script') || str_contains($lower, '</script>')) {
            return true;
        }

        // Foreign object marker
        if (str_contains($lower, '<foreignobject') || str_contains($lower, '</foreignobject>')) {
            return true;
        }

        // JavaScript / VBScript execution markers
        if (str_contains($lower, 'javascript:') || str_contains($lower, 'vbscript:') || str_contains($lower, 'data:text/html')) {
            return true;
        }

        // Event handler patterns (e.g. onload=, onerror=, onclick=)
        if (preg_match('/on[a-z]{3,15}\s*=/i', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * Inspect file header using finfo.
     */
    public static function detectMimeType(string $filePath): string
    {
        try {
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $mime = finfo_file($finfo, $filePath);
                    finfo_close($finfo);
                    if (is_string($mime) && trim($mime) !== '') {
                        return strtolower(trim($mime));
                    }
                }
            }
            if (function_exists('mime_content_type')) {
                $mime = mime_content_type($filePath);
                if (is_string($mime) && trim($mime) !== '') {
                    return strtolower(trim($mime));
                }
            }
        } catch (\Throwable) {
        }

        return 'application/octet-stream';
    }

    /**
     * Verify detected MIME against the allowed category.
     */
    public static function isMimeCompatibleWithCategory(string $mime, string $category, string $ext): bool
    {
        $mime = strtolower($mime);

        return match ($category) {
            self::CATEGORY_VIDEO => str_starts_with($mime, 'video/')
                || in_array($mime, [
                    'video/mp4', 'video/webm', 'video/x-matroska', 'video/quicktime',
                    'video/x-msvideo', 'video/x-m4v', 'application/mp4', 'application/x-matroska',
                    'application/octet-stream' // In cases where finfo returns generic stream for rare video codecs
                ], true),

            self::CATEGORY_AUDIO => str_starts_with($mime, 'audio/')
                || in_array($mime, [
                    'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/wav',
                    'audio/x-wav', 'audio/aac', 'audio/ogg', 'audio/flac', 'audio/x-flac',
                    'application/ogg', 'application/octet-stream'
                ], true),

            self::CATEGORY_IMAGE => str_starts_with($mime, 'image/')
                && in_array($mime, [
                    'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/x-icon'
                ], true),

            self::CATEGORY_SUBTITLE => in_array($mime, [
                'text/vtt', 'text/plain', 'application/x-subrip', 'text/srt', 'application/octet-stream'
            ], true),

            default => false,
        };
    }

    /**
     * Get configured max upload size in bytes for each category.
     */
    public static function getMaxUploadBytes(string $category): int
    {
        $settingKey = match ($category) {
            self::CATEGORY_VIDEO    => 'max_video_upload_mb',
            self::CATEGORY_AUDIO    => 'max_audio_upload_mb',
            self::CATEGORY_IMAGE    => 'max_image_upload_mb',
            self::CATEGORY_SUBTITLE => 'max_subtitle_upload_mb',
            default                 => 'max_upload_mb',
        };

        $defaultMb = match ($category) {
            self::CATEGORY_VIDEO    => 500,
            self::CATEGORY_AUDIO    => 100,
            self::CATEGORY_IMAGE    => 10,
            self::CATEGORY_SUBTITLE => 5,
            default                 => 50,
        };

        $mb = (int)Setting::get('multimedia', $settingKey, $defaultMb);
        if ($mb <= 0) {
            $mb = $defaultMb;
        }

        return $mb * 1024 * 1024;
    }

    /**
     * Sanitize filename, preserving safe characters.
     */
    public static function sanitizeFilename(string $filename, string $ext): string
    {
        $filename = str_replace(['\\', '/', "\0", '..'], '', $filename);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $clean = preg_replace('/[^\p{L}\p{N}_\-\.]/u', '_', $name) ?? 'file';
        $clean = trim($clean, '._-');
        if ($clean === '') {
            $clean = 'media_' . time();
        }

        return $ext !== '' ? "{$clean}.{$ext}" : $clean;
    }

    private static function resolveUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the form.',
            UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            default               => 'Unknown upload error occurred.',
        };
    }
}

