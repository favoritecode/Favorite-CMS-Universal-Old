<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 5 User Library & Progress Migration
 *
 * Creates the user personalization and playback tracking tables:
 * 1. multimedia_playback_progress - Throttled position, duration, completion, and watch/listening history
 * 2. multimedia_favorites         - Personal bookmarks ("My List") with unique user-content constraints
 */
class CreateMultimediaUserLibraryTables
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $isSqlite = $this->isSqlite();
        $engine = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pkBigint = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
        $updatedAt = $isSqlite
            ? 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

        // 1. multimedia_playback_progress
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_playback_progress` (
                `id`             {$pkBigint},
                `user_id`        BIGINT       NOT NULL,
                `content_type`   VARCHAR(50)  NOT NULL,
                `content_id`     BIGINT       NOT NULL,
                `position`       DOUBLE       NOT NULL DEFAULT 0,
                `duration`       DOUBLE       NOT NULL DEFAULT 0,
                `percentage`     DOUBLE       NOT NULL DEFAULT 0,
                `is_completed`   TINYINT(1)   NOT NULL DEFAULT 0,
                `last_played_at` DATETIME     NOT NULL,
                `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_playback_progress', 'idx_mm_prog_user_content', '`user_id`, `content_type`, `content_id`', true);
        $this->createIndexIfNotExists('multimedia_playback_progress', 'idx_mm_prog_user_recent', '`user_id`, `last_played_at`');
        $this->createIndexIfNotExists('multimedia_playback_progress', 'idx_mm_prog_user_cw', '`user_id`, `content_type`, `is_completed`, `last_played_at`');

        // 2. multimedia_favorites
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_favorites` (
                `id`           {$pkBigint},
                `user_id`      BIGINT       NOT NULL,
                `content_type` VARCHAR(50)  NOT NULL,
                `content_id`   BIGINT       NOT NULL,
                `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_favorites', 'idx_mm_fav_user_content', '`user_id`, `content_type`, `content_id`', true);
        $this->createIndexIfNotExists('multimedia_favorites', 'idx_mm_fav_user', '`user_id`, `created_at`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_favorites`");
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_playback_progress`");
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

    protected function createIndexIfNotExists(string $table, string $indexName, string $columns, bool $unique = false): void
    {
        if ($this->isSqlite()) {
            $uniqueClause = $unique ? 'UNIQUE' : '';
            $this->db->execute("CREATE {$uniqueClause} INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` ({$columns})");
            return;
        }

        try {
            $existing = $this->db->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            if (empty($existing)) {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            }
        } catch (\Throwable) {
            try {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            } catch (\Throwable) {
                // index might already exist
            }
        }
    }
}

