<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 13 Analytics & Attribution Migration
 *
 * 1. Extends multimedia_analytics with:
 *    - discovery_source (attribution source tag: catalog, search, trending, popular, related, recommended, continue_watching)
 *    - metadata (safe JSON/serialized tags for audio track, subtitle selection, or error codes)
 *
 * 2. Adds high-performance indexes:
 *    - idx_mm_an_event_created: (event_type, created_at)
 *    - idx_mm_an_user_content: (user_id, content_type, content_id)
 *    - idx_mm_an_source: (discovery_source)
 */
class CreateMultimediaAnalyticsIndexesAndFields
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // 1. Extend multimedia_analytics columns
        $this->addColumnIfNotExists('multimedia_analytics', 'discovery_source', "VARCHAR(32) NULL DEFAULT NULL");
        $this->addColumnIfNotExists('multimedia_analytics', 'metadata', "TEXT NULL DEFAULT NULL");

        // 2. Add performance indexes for aggregation & filtering
        $this->createIndexIfNotExists('multimedia_analytics', 'idx_mm_an_event_created', '`event_type`, `created_at`');
        $this->createIndexIfNotExists('multimedia_analytics', 'idx_mm_an_user_content', '`user_id`, `content_type`, `content_id`');
        $this->createIndexIfNotExists('multimedia_analytics', 'idx_mm_an_source', '`discovery_source`');
    }

    public function down(): void
    {
        // Non-destructive rollback: indexes dropped where applicable
        if (!$this->isSqlite()) {
            $this->dropIndexIfExists('multimedia_analytics', 'idx_mm_an_source');
            $this->dropIndexIfExists('multimedia_analytics', 'idx_mm_an_user_content');
            $this->dropIndexIfExists('multimedia_analytics', 'idx_mm_an_event_created');
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

    protected function addColumnIfNotExists(string $table, string $column, string $typeDefinition): void
    {
        try {
            if ($this->isSqlite()) {
                $cols = $this->db->select("PRAGMA table_info(`{$table}`)");
                $exists = false;
                foreach ($cols as $c) {
                    if (($c->name ?? '') === $column) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$typeDefinition}");
                }
            } else {
                $cols = $this->db->select("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
                if (empty($cols)) {
                    $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$typeDefinition}");
                }
            }
        } catch (\Throwable $e) {
            // Ignore if column already exists or table does not exist
        }
    }

    protected function createIndexIfNotExists(string $table, string $indexName, string $columns): void
    {
        try {
            if ($this->isSqlite()) {
                $this->db->execute("CREATE INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` ({$columns})");
            } else {
                $exists = $this->db->select("
                    SELECT 1 FROM information_schema.statistics
                    WHERE table_schema = DATABASE()
                      AND table_name = '{$table}'
                      AND index_name = '{$indexName}'
                ");
                if (empty($exists)) {
                    $this->db->execute("CREATE INDEX `{$indexName}` ON `{$table}` ({$columns})");
                }
            }
        } catch (\Throwable $e) {
            // Ignore index errors gracefully
        }
    }

    protected function dropIndexIfExists(string $table, string $indexName): void
    {
        try {
            $this->db->execute("DROP INDEX `{$indexName}` ON `{$table}`");
        } catch (\Throwable $e) {
            // Ignore if index does not exist
        }
    }
}
