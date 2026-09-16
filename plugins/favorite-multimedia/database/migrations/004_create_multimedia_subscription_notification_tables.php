<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 8 Subscriptions & Notifications Migration
 *
 * Creates content subscription and notification tables:
 * 1. multimedia_subscriptions            - Content following (series, artist, playlist)
 * 2. multimedia_notifications            - In-app release alerts and engagement activity
 * 3. multimedia_notification_preferences - User notification delivery preferences
 */
class CreateMultimediaSubscriptionNotificationTables
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

        // 1. multimedia_subscriptions
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_subscriptions` (
                `id`          {$pkBigint},
                `user_id`     BIGINT      NOT NULL,
                `target_type` VARCHAR(30) NOT NULL,
                `target_id`   BIGINT      NOT NULL,
                `created_at`  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_subscriptions', 'idx_mm_sub_user_target', '`user_id`, `target_type`, `target_id`', true);
        $this->createIndexIfNotExists('multimedia_subscriptions', 'idx_mm_sub_target', '`target_type`, `target_id`');
        $this->createIndexIfNotExists('multimedia_subscriptions', 'idx_mm_sub_user', '`user_id`');

        // 2. multimedia_notifications
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_notifications` (
                `id`            {$pkBigint},
                `user_id`       BIGINT       NOT NULL,
                `type`          VARCHAR(50)  NOT NULL,
                `subject_type`  VARCHAR(30)  NOT NULL,
                `subject_id`    BIGINT       NOT NULL,
                `actor_user_id` BIGINT       DEFAULT NULL,
                `dedupe_key`    VARCHAR(120) NOT NULL,
                `title`         VARCHAR(255) NOT NULL,
                `message`       VARCHAR(500) NOT NULL,
                `is_read`       TINYINT      NOT NULL DEFAULT 0,
                `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_notifications', 'idx_mm_notif_dedupe', '`dedupe_key`', true);
        $this->createIndexIfNotExists('multimedia_notifications', 'idx_mm_notif_user_read', '`user_id`, `is_read`, `created_at`');
        $this->createIndexIfNotExists('multimedia_notifications', 'idx_mm_notif_user_created', '`user_id`, `created_at`');

        // 3. multimedia_notification_preferences
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_notification_preferences` (
                `id`                        {$pkBigint},
                `user_id`                   BIGINT    NOT NULL,
                `notify_content_updates`    TINYINT   NOT NULL DEFAULT 1,
                `notify_engagement_replies` TINYINT   NOT NULL DEFAULT 1,
                `notify_moderation_updates` TINYINT   NOT NULL DEFAULT 1,
                `updated_at`                {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_notification_preferences', 'idx_mm_notif_pref_user', '`user_id`', true);
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_notification_preferences`");
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_notifications`");
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_subscriptions`");
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

