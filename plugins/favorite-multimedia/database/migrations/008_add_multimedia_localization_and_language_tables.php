<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 12 Language & Localization Migration
 *
 * 1. Extends multimedia_subtitles with advanced subtitle attributes:
 *    - language_code (BCP 47 language tag)
 *    - is_forced (for foreign dialogue inside primary track)
 *    - is_sdh (subtitles for the deaf and hard of hearing)
 *    - storage_driver & storage_key (Phase 11 storage integration)
 *
 * 2. Extends multimedia_sources with audio track language & role:
 *    - language_code (BCP 47 language tag e.g. en, bn, ar)
 *    - audio_role (main, dub, commentary, descriptive)
 *
 * 3. Extends multimedia_movies and multimedia_series with original_language.
 *
 * 4. Creates multimedia_localizations table for generic translation & metadata.
 *
 * 5. Creates multimedia_user_language_preferences table for user preference persistence.
 */
class AddMultimediaLocalizationAndLanguageTables
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

        // 1. Extend multimedia_subtitles
        $this->addColumnIfNotExists('multimedia_subtitles', 'language_code', "VARCHAR(32) NULL DEFAULT NULL");
        $this->addColumnIfNotExists('multimedia_subtitles', 'is_forced', "TINYINT(1) NOT NULL DEFAULT 0");
        $this->addColumnIfNotExists('multimedia_subtitles', 'is_sdh', "TINYINT(1) NOT NULL DEFAULT 0");
        $this->addColumnIfNotExists('multimedia_subtitles', 'storage_driver', "VARCHAR(32) NOT NULL DEFAULT 'local'");
        $this->addColumnIfNotExists('multimedia_subtitles', 'storage_key', "VARCHAR(500) NULL DEFAULT NULL");

        $this->createIndexIfNotExists('multimedia_subtitles', 'idx_mm_sub_lang_code', '`language_code`');

        // 2. Extend multimedia_sources with audio language & role
        $this->addColumnIfNotExists('multimedia_sources', 'language_code', "VARCHAR(32) NULL DEFAULT NULL");
        $this->addColumnIfNotExists('multimedia_sources', 'audio_role', "VARCHAR(32) NOT NULL DEFAULT 'main'");

        $this->createIndexIfNotExists('multimedia_sources', 'idx_mm_src_lang', '`language_code`');

        // 3. Extend multimedia_movies and multimedia_series with original_language
        $this->addColumnIfNotExists('multimedia_movies', 'original_language', "VARCHAR(32) NOT NULL DEFAULT 'en'");
        $this->addColumnIfNotExists('multimedia_series', 'original_language', "VARCHAR(32) NOT NULL DEFAULT 'en'");

        // 4. Create multimedia_localizations table
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_localizations` (
                `id`            {$pkBigint},
                `content_type`  VARCHAR(32)  NOT NULL,
                `content_id`    BIGINT       NOT NULL,
                `language_code` VARCHAR(32)  NOT NULL,
                `title`         VARCHAR(255) NOT NULL,
                `description`   TEXT         NULL,
                `tagline`       VARCHAR(255) NULL,
                `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('multimedia_localizations', 'idx_mm_loc_unique', '`content_type`, `content_id`, `language_code`', true);
        $this->createIndexIfNotExists('multimedia_localizations', 'idx_mm_loc_lang_title', '`language_code`, `title`');

        // 5. Create multimedia_user_language_preferences table
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_user_language_preferences` (
                `id`                          {$pkBigint},
                `user_id`                     BIGINT       NOT NULL,
                `preferred_audio_language`    VARCHAR(32)  NULL DEFAULT NULL,
                `preferred_subtitle_language` VARCHAR(32)  NULL DEFAULT NULL,
                `subtitle_enabled`            TINYINT(1)   NOT NULL DEFAULT 1,
                `created_at`                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`                  {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('multimedia_user_language_preferences', 'idx_mm_ulp_user', '`user_id`', true);
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_user_language_preferences`");
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_localizations`");
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
