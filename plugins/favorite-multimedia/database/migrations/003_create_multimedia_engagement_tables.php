<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 7 Engagement & Moderation Migration
 *
 * Creates user engagement, feedback, and moderation tables:
 * 1. multimedia_ratings        - 1-5 star ratings with unique (user, content_type, content_id) constraint
 * 2. multimedia_reviews        - Long-form reviews with moderation status, spoiler flag, and unique constraint
 * 3. multimedia_review_helpful - Single helpful vote per user per review
 * 4. multimedia_comments       - Discussion comments with shallow 1-level threading (parent_id)
 * 5. multimedia_reports        - Community safety reporting for reviews/comments with duplicate prevention
 */
class CreateMultimediaEngagementTables
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

        // 1. multimedia_ratings
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_ratings` (
                `id`           {$pkBigint},
                `user_id`      BIGINT       NOT NULL,
                `content_type` VARCHAR(50)  NOT NULL,
                `content_id`   BIGINT       NOT NULL,
                `rating`       TINYINT      NOT NULL,
                `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_ratings', 'idx_mm_rate_user_content', '`user_id`, `content_type`, `content_id`', true);
        $this->createIndexIfNotExists('multimedia_ratings', 'idx_mm_rate_content', '`content_type`, `content_id`');

        // 2. multimedia_reviews
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_reviews` (
                `id`               {$pkBigint},
                `user_id`          BIGINT       NOT NULL,
                `content_type`     VARCHAR(50)  NOT NULL,
                `content_id`       BIGINT       NOT NULL,
                `title`            VARCHAR(255) DEFAULT NULL,
                `body`             TEXT         NOT NULL,
                `rating`           TINYINT      DEFAULT NULL,
                `status`           VARCHAR(20)  NOT NULL DEFAULT 'approved',
                `contains_spoiler` TINYINT(1)   NOT NULL DEFAULT 0,
                `helpful_count`    INT          NOT NULL DEFAULT 0,
                `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_reviews', 'idx_mm_rev_user_content', '`user_id`, `content_type`, `content_id`', true);
        $this->createIndexIfNotExists('multimedia_reviews', 'idx_mm_rev_content_status', '`content_type`, `content_id`, `status`');
        $this->createIndexIfNotExists('multimedia_reviews', 'idx_mm_rev_status_created', '`status`, `created_at`');

        // 3. multimedia_review_helpful
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_review_helpful` (
                `id`         {$pkBigint},
                `user_id`    BIGINT    NOT NULL,
                `review_id`  BIGINT    NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_review_helpful', 'idx_mm_rev_help_user_review', '`user_id`, `review_id`', true);

        // 4. multimedia_comments
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_comments` (
                `id`           {$pkBigint},
                `user_id`      BIGINT       NOT NULL,
                `content_type` VARCHAR(50)  NOT NULL,
                `content_id`   BIGINT       NOT NULL,
                `parent_id`    BIGINT       DEFAULT NULL,
                `body`         TEXT         NOT NULL,
                `status`       VARCHAR(20)  NOT NULL DEFAULT 'approved',
                `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_comments', 'idx_mm_comm_content_status', '`content_type`, `content_id`, `status`');
        $this->createIndexIfNotExists('multimedia_comments', 'idx_mm_comm_parent', '`parent_id`');
        $this->createIndexIfNotExists('multimedia_comments', 'idx_mm_comm_status_created', '`status`, `created_at`');

        // 5. multimedia_reports
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_reports` (
                `id`               {$pkBigint},
                `reporter_user_id` BIGINT       NOT NULL,
                `target_type`      VARCHAR(20)  NOT NULL,
                `target_id`        BIGINT       NOT NULL,
                `reason`           VARCHAR(50)  NOT NULL,
                `notes`            VARCHAR(255) DEFAULT NULL,
                `status`           VARCHAR(20)  NOT NULL DEFAULT 'open',
                `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_reports', 'idx_mm_rep_user_target', '`reporter_user_id`, `target_type`, `target_id`', true);
        $this->createIndexIfNotExists('multimedia_reports', 'idx_mm_rep_target', '`target_type`, `target_id`');
        $this->createIndexIfNotExists('multimedia_reports', 'idx_mm_rep_status_created', '`status`, `created_at`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_reports`");
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_comments`");
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_review_helpful`");
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_reviews`");
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_ratings`");
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
