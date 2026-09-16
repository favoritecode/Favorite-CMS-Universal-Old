<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\BaseModel;

class MediaStorageFile extends BaseModel
{
    protected static string $table = 'multimedia_storage_files';

    public const TYPE_RENDITION       = 'rendition';
    public const TYPE_MP4_RENDITION   = 'rendition';
    public const TYPE_HLS_MASTER      = 'hls_master';
    public const TYPE_HLS_VARIANT     = 'hls_variant';
    public const TYPE_HLS_SEGMENT     = 'hls_segment';
    public const TYPE_THUMBNAIL       = 'thumbnail';
    public const TYPE_AUDIO           = 'audio';
    public const TYPE_AUDIO_TRACK     = 'audio';
    public const TYPE_SUBTITLE        = 'subtitle';
    public const TYPE_ORIGINAL        = 'original';

    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    /**
     * Register or update a managed storage file (convenience alias).
     */
    public static function register(
        ?string $contentType,
        ?int $contentId,
        string $driver,
        string $key,
        string $urlOrPath,
        string $fileType,
        int $fileSize = 0,
        ?string $mimeType = null,
        ?int $sourceId = null,
        ?int $jobId = null
    ): self {
        return self::recordFile($driver, $key, $fileType, $contentType, $contentId, $sourceId, $jobId, $fileSize, $mimeType);
    }

    /**
     * Record or update a managed storage file.
     */
    public static function recordFile(
        string $driver,
        string $key,
        string $fileType,
        ?string $contentType = null,
        ?int $contentId = null,
        ?int $sourceId = null,
        ?int $jobId = null,
        int $fileSize = 0,
        ?string $mimeType = null
    ): self {
        $db = self::getDb();
        $now = gmdate('Y-m-d H:i:s');

        try {
            $existing = $db->selectOne(
                "SELECT id FROM multimedia_storage_files WHERE storage_driver = ? AND storage_key = ? LIMIT 1",
                [$driver, $key]
            );

            if ($existing) {
                $id = (int)$existing->id;
                $db->update('multimedia_storage_files', [
                    'file_type'    => $fileType,
                    'content_type' => $contentType,
                    'content_id'   => $contentId,
                    'source_id'    => $sourceId,
                    'job_id'       => $jobId,
                    'file_size'    => $fileSize,
                    'mime_type'    => $mimeType,
                    'is_orphan'    => 0,
                    'updated_at'   => $now,
                ], ['id' => $id]);
                return static::find($id) ?: new static();
            }

            $id = $db->insert('multimedia_storage_files', [
                'storage_driver' => $driver,
                'storage_key'    => $key,
                'file_type'      => $fileType,
                'content_type'   => $contentType,
                'content_id'     => $contentId,
                'source_id'      => $sourceId,
                'job_id'         => $jobId,
                'file_size'      => $fileSize,
                'mime_type'      => $mimeType,
                'is_orphan'      => 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            return static::find((int)$id) ?: new static();
        } catch (\Throwable) {
            // Graceful fallback when multimedia_storage_files table does not exist (pre-migration)
            return new static();
        }
    }

    /**
     * Get all managed files for an entity.
     */
    public static function getForContent(string $contentType, int $contentId): array
    {
        $db = self::getDb();
        $rows = $db->select(
            "SELECT * FROM multimedia_storage_files WHERE content_type = ? AND content_id = ? ORDER BY id ASC",
            [$contentType, $contentId]
        );
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Get all managed files for a source.
     */
    public static function getForSource(int $sourceId): array
    {
        $db = self::getDb();
        $rows = $db->select(
            "SELECT * FROM multimedia_storage_files WHERE source_id = ? ORDER BY id ASC",
            [$sourceId]
        );
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Get orphan files (unlinked or flagged).
     */
    public static function getOrphans(int $limit = 100): array
    {
        $db = self::getDb();
        $rows = $db->select(
            "SELECT * FROM multimedia_storage_files WHERE is_orphan = 1 OR (content_type IS NULL AND source_id IS NULL) ORDER BY id ASC LIMIT " . (int)$limit
        );
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    /**
     * Calculate total storage size in bytes.
     */
    public static function getTotalStorageBytes(?string $driver = null): int
    {
        $db = self::getDb();
        $sql = "SELECT SUM(file_size) as total FROM multimedia_storage_files";
        $params = [];
        if ($driver !== null) {
            $sql .= " WHERE storage_driver = ?";
            $params[] = $driver;
        }
        $res = $db->selectOne($sql, $params);
        return (int)($res->total ?? 0);
    }

    /**
     * Count files grouped by storage driver.
     */
    public static function countByDriver(): array
    {
        $db = self::getDb();
        $rows = $db->select(
            "SELECT storage_driver, COUNT(*) as count, SUM(file_size) as total_size FROM multimedia_storage_files GROUP BY storage_driver"
        );
        $result = [];
        foreach ($rows as $r) {
            $d = is_array($r) ? ($r['storage_driver'] ?? 'unknown') : ($r->storage_driver ?? 'unknown');
            $c = is_array($r) ? (int)($r['count'] ?? 0) : (int)($r->count ?? 0);
            $s = is_array($r) ? (int)($r['total_size'] ?? 0) : (int)($r->total_size ?? 0);
            $result[$d] = ['count' => $c, 'size' => $s];
        }
        return $result;
    }
}
