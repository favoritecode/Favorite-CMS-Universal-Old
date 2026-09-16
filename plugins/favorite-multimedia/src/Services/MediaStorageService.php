<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\Models\MediaProcessingJob;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\MediaStorageFile;
use FavoriteCMS\Multimedia\Models\MediaLocalization;
use FavoriteCMS\Multimedia\Storage\MediaStorageManager;

class MediaStorageService
{
    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    /**
     * Upload local processing outputs to configured storage driver.
     */
    public static function uploadProcessingOutputs(MediaProcessingJob $job, array $outputData): array
    {
        $driverName = (string)Setting::get('multimedia', 'storage_driver', 'local');
        $disk = MediaStorageManager::getDisk($driverName);

        $contentType = (string)$job->content_type;
        $contentId   = (int)$job->content_id;
        $jobId       = (int)$job->id;
        $sourceId    = $job->source_id ? (int)$job->source_id : null;

        $createdFiles = [];

        // 1. Process HLS directory if present
        $outputPath = (string)($outputData['output_path'] ?? '');
        $absPath = self::resolveAbsolutePath($outputPath);

        if (is_dir($absPath)) {
            // HLS directory containing master.m3u8, variants, and segments
            $files = self::scanDirectoryRecursive($absPath);
            foreach ($files as $relFile => $fullPath) {
                $storageKey = MediaStorageManager::buildKey($contentType, $contentId, 'hls', $relFile);
                $fileSize = (int)filesize($fullPath);
                $mimeType = self::detectMimeType($relFile);

                $fileType = str_contains($relFile, 'master.m3u8')
                    ? MediaStorageFile::TYPE_HLS_MASTER
                    : (str_ends_with($relFile, '.m3u8') ? MediaStorageFile::TYPE_HLS_VARIANT : MediaStorageFile::TYPE_HLS_SEGMENT);

                if ($driverName !== 'local') {
                    $stream = fopen($fullPath, 'rb');
                    if ($stream) {
                        $disk->put($storageKey, $stream, ['mime_type' => $mimeType]);
                        fclose($stream);
                    }
                }

                MediaStorageFile::recordFile(
                    $driverName,
                    $storageKey,
                    $fileType,
                    $contentType,
                    $contentId,
                    $sourceId,
                    $jobId,
                    $fileSize,
                    $mimeType
                );
                $createdFiles[] = $storageKey;
            }
        } elseif (is_file($absPath)) {
            // Single rendition, thumbnail, or audio file
            $filename = basename($absPath);
            $subfolder = str_ends_with($filename, '.jpg') || str_ends_with($filename, '.png') ? 'thumbnails' : 'renditions';
            $storageKey = MediaStorageManager::buildKey($contentType, $contentId, $subfolder, $filename);
            $fileSize = (int)filesize($absPath);
            $mimeType = self::detectMimeType($filename);

            $fileType = ($subfolder === 'thumbnails') ? MediaStorageFile::TYPE_THUMBNAIL : MediaStorageFile::TYPE_RENDITION;

            if ($driverName !== 'local') {
                $stream = fopen($absPath, 'rb');
                if ($stream) {
                    $disk->put($storageKey, $stream, ['mime_type' => $mimeType]);
                    fclose($stream);
                }
            }

            MediaStorageFile::recordFile(
                $driverName,
                $storageKey,
                $fileType,
                $contentType,
                $contentId,
                $sourceId,
                $jobId,
                $fileSize,
                $mimeType
            );
            $createdFiles[] = $storageKey;
        }

        return [
            'driver' => $driverName,
            'files'  => $createdFiles,
        ];
    }

    /**
     * Migrate an existing local media source to remote object storage.
     */
    public static function migrateSourceToRemote(MediaSource $source, bool $deleteLocal = false): array
    {
        $remoteDisk = MediaStorageManager::getDisk('s3');
        $localFile = MediaDeliveryService::resolveSourceFilePath($source);

        if (!$localFile || !file_exists($localFile)) {
            return ['success' => false, 'error' => "Local source file not found."];
        }

        $contentType = (string)$source->content_type;
        $contentId   = (int)$source->content_id;
        $sourceId    = (int)$source->id;

        $db = self::getDb();

        if (is_dir($localFile)) {
            // HLS directory migration
            $files = self::scanDirectoryRecursive($localFile);
            $uploaded = 0;

            foreach ($files as $relFile => $fullPath) {
                $storageKey = MediaStorageManager::buildKey($contentType, $contentId, 'hls', $relFile);
                $fileSize = (int)filesize($fullPath);
                $mimeType = self::detectMimeType($relFile);

                // Resumeability: if already exists with matching size, skip upload
                if (!$remoteDisk->exists($storageKey) || $remoteDisk->size($storageKey) !== $fileSize) {
                    $fp = fopen($fullPath, 'rb');
                    if ($fp) {
                        $remoteDisk->put($storageKey, $fp, ['mime_type' => $mimeType]);
                        fclose($fp);
                    }
                }

                $fileType = str_contains($relFile, 'master.m3u8')
                    ? MediaStorageFile::TYPE_HLS_MASTER
                    : (str_ends_with($relFile, '.m3u8') ? MediaStorageFile::TYPE_HLS_VARIANT : MediaStorageFile::TYPE_HLS_SEGMENT);

                MediaStorageFile::recordFile('s3', $storageKey, $fileType, $contentType, $contentId, $sourceId, null, $fileSize, $mimeType);
                $uploaded++;
            }

            $masterKey = MediaStorageManager::buildKey($contentType, $contentId, 'hls', 'master.m3u8');
            $db->update('multimedia_sources', [
                'storage_driver' => 's3',
                'storage_key'    => $masterKey,
                'is_migrated'    => 1,
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ], ['id' => $sourceId]);

            if ($deleteLocal) {
                self::recursiveDeleteDirectory($localFile);
            }

            return ['success' => true, 'uploaded' => $uploaded, 'key' => $masterKey];
        }

        // Single file migration
        $filename = basename($localFile);
        $subfolder = ($source->source_type === 'audio') ? 'audio' : 'renditions';
        $storageKey = MediaStorageManager::buildKey($contentType, $contentId, $subfolder, $filename);
        $fileSize = (int)filesize($localFile);
        $mimeType = (string)($source->mime_type ?: self::detectMimeType($filename));

        // Upload and verify
        if (!$remoteDisk->exists($storageKey) || $remoteDisk->size($storageKey) !== $fileSize) {
            $fp = fopen($localFile, 'rb');
            if ($fp) {
                $remoteDisk->put($storageKey, $fp, ['mime_type' => $mimeType]);
                fclose($fp);
            }
        }

        // Verification check
        if (!$remoteDisk->exists($storageKey)) {
            return ['success' => false, 'error' => "Verification failed: object not found after upload."];
        }

        MediaStorageFile::recordFile('s3', $storageKey, MediaStorageFile::TYPE_RENDITION, $contentType, $contentId, $sourceId, null, $fileSize, $mimeType);

        $db->update('multimedia_sources', [
            'storage_driver' => 's3',
            'storage_key'    => $storageKey,
            'is_migrated'    => 1,
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ], ['id' => $sourceId]);

        if ($deleteLocal && file_exists($localFile)) {
            @unlink($localFile);
        }

        return ['success' => true, 'uploaded' => 1, 'key' => $storageKey];
    }

    /**
     * Clean all storage assets for an entity upon deletion (Cascade cleanup).
     */
    public static function cleanupContentStorage(string $contentType, int $contentId): int
    {
        $files = MediaStorageFile::getForContent($contentType, $contentId);
        $count = 0;
        $db = self::getDb();

        foreach ($files as $f) {
            $driver = (string)$f->storage_driver;
            $key = (string)$f->storage_key;
            $disk = MediaStorageManager::getDisk($driver);

            // Safe delete: disk confines to prefix
            $disk->delete($key);
            $db->delete('multimedia_storage_files', ['id' => (int)$f->id]);
            $count++;
        }

        MediaLocalization::deleteForContent($contentType, $contentId);

        return $count;
    }

    /**
     * Scan storage tracking table for orphaned files.
     */
    public static function scanOrphans(): array
    {
        $db = self::getDb();

        // 1. Mark files as orphan if entity no longer exists
        $allFiles = $db->select("SELECT id, content_type, content_id FROM multimedia_storage_files WHERE is_orphan = 0");
        foreach ($allFiles as $f) {
            $id = (int)$f->id;
            $type = (string)($f->content_type ?? '');
            $cId = (int)($f->content_id ?? 0);

            if ($type === '' || $cId <= 0) {
                $db->update('multimedia_storage_files', ['is_orphan' => 1], ['id' => $id]);
                continue;
            }

            $table = 'multimedia_' . ($type === 'movie' ? 'movies' : ($type === 'episode' ? 'episodes' : 'songs'));
            $exists = $db->selectOne("SELECT id FROM `{$table}` WHERE id = ? LIMIT 1", [$cId]);
            if (!$exists) {
                $db->update('multimedia_storage_files', ['is_orphan' => 1], ['id' => $id]);
            }
        }

        $orphans = MediaStorageFile::getOrphans(500);
        $totalBytes = 0;
        foreach ($orphans as $o) {
            $totalBytes += (int)$o->file_size;
        }

        return [
            'count'       => count($orphans),
            'total_bytes' => $totalBytes,
            'orphans'     => $orphans,
        ];
    }

    /**
     * Clean orphaned files.
     */
    public static function cleanupOrphans(bool $dryRun = true, int $limit = 100): array
    {
        $scan = self::scanOrphans();
        $orphans = array_slice($scan['orphans'], 0, $limit);

        if ($dryRun) {
            return [
                'dry_run'     => true,
                'count'       => count($orphans),
                'total_bytes' => array_sum(array_map(fn($o) => (int)$o->file_size, $orphans)),
                'message'     => 'Dry run completed. No files were deleted.',
            ];
        }

        $db = self::getDb();
        $deletedCount = 0;
        $freedBytes = 0;

        foreach ($orphans as $o) {
            $driver = (string)$o->storage_driver;
            $key = (string)$o->storage_key;
            $disk = MediaStorageManager::getDisk($driver);

            $disk->delete($key);
            $db->delete('multimedia_storage_files', ['id' => (int)$o->id]);
            $deletedCount++;
            $freedBytes += (int)$o->file_size;
        }

        return [
            'dry_run'     => false,
            'count'       => $deletedCount,
            'total_bytes' => $freedBytes,
            'message'     => "Deleted {$deletedCount} orphaned files ({$freedBytes} bytes freed).",
        ];
    }

    /**
     * Get operational summary of all multimedia storage.
     */
    public static function getStorageUsageSummary(): array
    {
        $currentDriver = (string)Setting::get('multimedia', 'storage_driver', 'local');
        $disk = MediaStorageManager::getDisk($currentDriver);
        $health = $disk->testConnection();

        $totalBytes = MediaStorageFile::getTotalStorageBytes();
        $driverStats = MediaStorageFile::countByDriver();

        return [
            'active_driver'  => $currentDriver,
            'health'         => $health,
            'total_bytes'    => $totalBytes,
            'driver_stats'   => $driverStats,
            's3_configured'  => (!empty(Setting::get('multimedia', 's3_bucket')) && !empty(Setting::get('multimedia', 's3_access_key'))),
            'cdn_configured' => !empty(Setting::get('multimedia', 'cdn_base_url')),
        ];
    }

    private static function scanDirectoryRecursive(string $dir): array
    {
        $results = [];
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);

        foreach ($files as $f) {
            $full = $dir . '/' . $f;
            if (is_dir($full)) {
                $sub = self::scanDirectoryRecursive($full);
                foreach ($sub as $subRel => $subFull) {
                    $results[$f . '/' . $subRel] = $subFull;
                }
            } else {
                $results[$f] = $full;
            }
        }

        return $results;
    }

    private static function recursiveDeleteDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $f) {
            $p = $dir . '/' . $f;
            is_dir($p) ? self::recursiveDeleteDirectory($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private static function resolveAbsolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, '/') || (strlen($normalized) > 2 && $normalized[1] === ':')) {
            return $normalized;
        }

        $appRoot = defined('APP_ROOT') ? APP_ROOT : '.';
        return rtrim($appRoot, '/') . '/' . ltrim($normalized, '/');
    }

    private static function detectMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'm3u8'  => 'application/vnd.apple.mpegurl',
            'ts'    => 'video/mp2t',
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            'mp3'   => 'audio/mpeg',
            'vtt'   => 'text/vtt',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'   => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
