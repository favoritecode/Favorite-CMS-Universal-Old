<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Storage;

use FavoriteCMS\Core\Logger;
use FavoriteCMS\Multimedia\Services\MediaDeliveryTokenService;

class LocalMultimediaStorage implements MultimediaStorageInterface
{
    protected string $rootDirectory;
    protected string $baseUrl;

    public function __construct(?string $rootDirectory = null, ?string $baseUrl = null)
    {
        if ($rootDirectory === null) {
            $base = defined('APP_ROOT') ? APP_ROOT . '/storage/multimedia' : sys_get_temp_dir() . '/multimedia';
            $this->rootDirectory = str_replace('\\', '/', $base);
        } else {
            $this->rootDirectory = rtrim(str_replace('\\', '/', $rootDirectory), '/');
        }

        $this->baseUrl = $baseUrl !== null ? rtrim($baseUrl, '/') : '/storage/multimedia';

        if (!is_dir($this->rootDirectory)) {
            @mkdir($this->rootDirectory, 0755, true);
        }
    }

    public function getRootDirectory(): string
    {
        return $this->rootDirectory;
    }

    public function put(string $key, mixed $content, array $options = []): bool
    {
        $path = $this->resolvePath($key, true);
        if ($path === null) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (is_resource($content)) {
            $fp = @fopen($path, 'wb');
            if (!$fp) {
                return false;
            }
            stream_copy_to_stream($content, $fp);
            fclose($fp);
            return true;
        }

        return file_put_contents($path, (string)$content) !== false;
    }

    public function get(string $key): ?string
    {
        $path = $this->resolvePath($key);
        if ($path === null || !file_exists($path) || is_dir($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        return ($content !== false) ? $content : null;
    }

    public function readStream(string $key)
    {
        $path = $this->resolvePath($key);
        if ($path === null || !file_exists($path) || is_dir($path)) {
            return null;
        }

        $res = @fopen($path, 'rb');
        return is_resource($res) ? $res : null;
    }

    public function exists(string $key): bool
    {
        $path = $this->resolvePath($key);
        return ($path !== null && file_exists($path));
    }

    public function size(string $key): int
    {
        $path = $this->resolvePath($key);
        if ($path === null || !file_exists($path)) {
            return 0;
        }

        $size = @filesize($path);
        return ($size !== false) ? (int)$size : 0;
    }

    public function delete(string $key): bool
    {
        $path = $this->resolvePath($key);
        if ($path === null || !file_exists($path)) {
            return false;
        }

        if (is_file($path)) {
            return @unlink($path);
        }

        return false;
    }

    public function deleteDirectory(string $prefix): int
    {
        $normPrefix = $this->sanitizeKey($prefix);
        if ($normPrefix === '' || $normPrefix === '.') {
            // Refuse to wipe entire root directory!
            return 0;
        }

        $targetDir = $this->rootDirectory . '/' . $normPrefix;
        if (!is_dir($targetDir)) {
            return 0;
        }

        $realTarget = realpath($targetDir);
        $realRoot = realpath($this->rootDirectory);

        if (!$realTarget || !$realRoot || !str_starts_with($realTarget, $realRoot) || $realTarget === $realRoot) {
            return 0;
        }

        return $this->recursiveDelete($realTarget);
    }

    public function url(string $key): string
    {
        $norm = $this->sanitizeKey($key);
        return $this->baseUrl . '/' . ltrim($norm, '/');
    }

    public function temporaryUrl(string $key, int $ttlSeconds = 300, array $options = []): ?string
    {
        $norm = $this->sanitizeKey($key);
        if (!$this->exists($norm)) {
            return null;
        }

        // Generate signed temporary streaming token
        $tokenData = MediaDeliveryTokenService::generateToken($norm, $ttlSeconds, $options);
        return '/api/multimedia/storage/token-stream?' . http_build_query([
            'key' => $norm,
            'exp' => $tokenData['exp'],
            'sig' => $tokenData['sig'],
        ]);
    }

    public function getDriverName(): string
    {
        return 'local';
    }

    public function testConnection(): array
    {
        $dir = $this->rootDirectory;
        if (!is_dir($dir)) {
            $created = @mkdir($dir, 0755, true);
            if (!$created) {
                return [
                    'success' => false,
                    'message' => "Root storage directory does not exist and cannot be created: {$dir}",
                    'details' => ['path' => $dir],
                ];
            }
        }

        if (!is_writable($dir)) {
            return [
                'success' => false,
                'message' => "Storage directory is not writable: {$dir}",
                'details' => ['path' => $dir],
            ];
        }

        // Write test probe file
        $testFile = $dir . '/.probe_' . uniqid();
        $written = @file_put_contents($testFile, 'probe');
        if ($written === false) {
            return [
                'success' => false,
                'message' => "Failed writing test file in storage directory.",
                'details' => ['path' => $dir],
            ];
        }
        @unlink($testFile);

        return [
            'success' => true,
            'message' => "Local storage is writable and healthy.",
            'details' => [
                'driver'    => 'local',
                'root'      => $dir,
                'free_byte' => @disk_free_space($dir) ?: 0,
            ],
        ];
    }

    public function resolvePath(string $key, bool $allowNonExistent = false): ?string
    {
        $sanitized = $this->sanitizeKey($key);
        if ($sanitized === '') {
            return null;
        }

        $candidate = $this->rootDirectory . '/' . $sanitized;
        $candidateDir = dirname($candidate);

        if (!$allowNonExistent) {
            $real = realpath($candidate);
            $realRoot = realpath($this->rootDirectory);
            if ($real && $realRoot && str_starts_with($real, $realRoot)) {
                return $real;
            }
            return null;
        }

        // For non-existent files during put(): verify parent directory path boundary
        if (!is_dir($candidateDir)) {
            @mkdir($candidateDir, 0755, true);
        }
        $realDir = realpath($candidateDir);
        $realRoot = realpath($this->rootDirectory);
        if ($realDir && $realRoot && str_starts_with($realDir, $realRoot)) {
            return $candidate;
        }

        return null;
    }

    public function sanitizeKey(string $key): string
    {
        $norm = str_replace('\\', '/', $key);

        // Strip null bytes and traversal tokens
        $norm = str_replace("\0", '', $norm);

        // Collapse multiple slashes
        $norm = preg_replace('#/+#', '/', $norm) ?? '';

        // Check for traversal segments
        $parts = explode('/', trim($norm, '/'));
        $safeParts = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                // Illegal traversal attempt!
                return '';
            }
            $safeParts[] = $part;
        }

        return implode('/', $safeParts);
    }

    protected function recursiveDelete(string $dir): int
    {
        $count = 0;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);

        foreach ($files as $file) {
            $p = $dir . '/' . $file;
            if (is_dir($p)) {
                $count += $this->recursiveDelete($p);
            } else {
                if (@unlink($p)) {
                    $count++;
                }
            }
        }

        @rmdir($dir);
        return $count;
    }
}
