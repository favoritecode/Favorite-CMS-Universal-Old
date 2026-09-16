<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 11 Storage & Lifecycle Migration
 *
 * 1. Extends multimedia_sources with storage backend metadata:
 *    - storage_driver (local / s3)
 *    - storage_key    (remote object key or relative storage path)
 *    - is_migrated    (flag indicating remote migration complete)
 *    - storage_meta   (JSON metadata e.g. etag, bucket, size)
 *
 * 2. Creates multimedia_storage_files table to track all managed generated assets
 *    (renditions, HLS manifests, TS segments, thumbnails, audio) for precise usage
 *    reporting, orphan detection, and safe cascade cleanup.
 */
class AddMultimediaStorageFields
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $isSqlite  = $this->isSqlite();
        $pkBigint  = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $engine    = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $updatedAt = $isSqlite ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

        // 1. Extend multimedia_sources
        $this->addColumnIfNotExists('multimedia_sources', 'storage_driver', "VARCHAR(32) NOT NULL DEFAULT 'local'");
        $this->addColumnIfNotExists('multimedia_sources', 'storage_key', "VARCHAR(500) NULL DEFAULT NULL");
        $this->addColumnIfNotExists('multimedia_sources', 'is_migrated', "TINYINT(1) NOT NULL DEFAULT 0");
        $this->addColumnIfNotExists('multimedia_sources', 'storage_meta', "TEXT NULL DEFAULT NULL");

        $this->createIndexIfNotExists('multimedia_sources', 'idx_mm_src_driver', '`storage_driver`');

        // 2. Create multimedia_storage_files tracking table
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_storage_files` (
                `id`             {$pkBigint},
                `storage_driver` VARCHAR(32)  NOT NULL DEFAULT 'local',
                `storage_key`    VARCHAR(500) NOT NULL,
                `file_type`      VARCHAR(32)  NOT NULL DEFAULT 'rendition',
                `content_type`   VARCHAR(32)  NULL,
                `content_id`     BIGINT       NULL,
                `source_id`      BIGINT       NULL,
                `job_id`         BIGINT       NULL,
                `file_size`      BIGINT       NOT NULL DEFAULT 0,
                `mime_type`      VARCHAR(128) NULL,
                `is_orphan`      TINYINT(1)   NOT NULL DEFAULT 0,
                `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('multimedia_storage_files', 'idx_st_files_driver_key', '`storage_driver`, `storage_key`');
        $this->createIndexIfNotExists('multimedia_storage_files', 'idx_st_files_content', '`content_type`, `content_id`');
        $this->createIndexIfNotExists('multimedia_storage_files', 'idx_st_files_type', '`file_type`');
        $this->createIndexIfNotExists('multimedia_storage_files', 'idx_st_files_source', '`source_id`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_storage_files`");
    }

    protected function addColumnIfNotExists(string $table, string $column, string $typeDef): void
    {
        if ($this->isSqlite()) {
            $cols = $this->db->select("PRAGMA table_info(`{$table}`)");
            foreach ($cols as $col) {
                $name = is_array($col) ? ($col['name'] ?? '') : ($col->name ?? '');
                if (strtolower((string)$name) === strtolower($column)) {
                    return;
                }
            }
            $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$typeDef}");
        } else {
            $cols = $this->db->select("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if (empty($cols)) {
                $this->db->execute("ALTER TABLE `{$table}` ADD `{$column}` {$typeDef}");
            }
        }
    }

    protected function createIndexIfNotExists(string $table, string $indexName, string $columns, bool $unique = false): void
    {
        $uniqueKeyword = $unique ? 'UNIQUE ' : '';
        if ($this->isSqlite()) {
            $this->db->execute("CREATE {$uniqueKeyword}INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` ({$columns})");
        } else {
            try {
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueKeyword}INDEX `{$indexName}` ({$columns})");
            } catch (\Throwable) {
            }
        }
    }

    protected function isSqlite(): bool
    {
        try {
            $driver = $this->db->getConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return strtolower((string)$driver) === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }
}
