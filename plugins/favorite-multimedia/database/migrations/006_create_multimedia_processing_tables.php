<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 10 Processing Tables Migration
 *
 * Creates the multimedia_processing_jobs table for asynchronous transcoding,
 * thumbnail extraction, and adaptive HLS packaging.
 */
class CreateMultimediaProcessingTables
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $isSqlite = $this->isSqlite();
        $pkBigint = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $engine   = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $updatedAt = $isSqlite ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_processing_jobs` (
                `id`            {$pkBigint},
                `content_type`  VARCHAR(32)  NOT NULL,
                `content_id`    BIGINT       NOT NULL,
                `source_id`     BIGINT       NULL,
                `job_type`      VARCHAR(32)  NOT NULL DEFAULT 'full_pipeline',
                `status`        VARCHAR(32)  NOT NULL DEFAULT 'pending',
                `progress`      INT          NOT NULL DEFAULT 0,
                `input_path`    TEXT         NOT NULL,
                `output_path`   TEXT         NULL,
                `settings`      TEXT         NULL,
                `error_message` TEXT         NULL,
                `attempts`      INT          NOT NULL DEFAULT 0,
                `started_at`    DATETIME     NULL,
                `completed_at`  DATETIME     NULL,
                `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('multimedia_processing_jobs', 'idx_proc_status_created', '`status`, `created_at`');
        $this->createIndexIfNotExists('multimedia_processing_jobs', 'idx_proc_content', '`content_type`, `content_id`');
        $this->createIndexIfNotExists('multimedia_processing_jobs', 'idx_proc_source', '`source_id`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_processing_jobs`");
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
                // Index may already exist
            }
        }
    }

    protected function isSqlite(): bool
    {
        try {
            return $this->db->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }
}
