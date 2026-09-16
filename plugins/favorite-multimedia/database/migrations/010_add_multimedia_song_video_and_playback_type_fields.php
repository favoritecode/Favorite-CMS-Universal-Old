<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 14 Song Video Parity & Playback Type Migration
 *
 * 1. Extends multimedia_songs with playback configuration:
 *    - playback_type (audio, video, audio_video)
 *    - default_playback_mode (audio, video)
 *
 * 2. Extends multimedia_sources with media kind:
 *    - media_kind (audio, video)
 *    - Migrates existing song sources to media_kind = 'audio'
 *
 * 3. Extends multimedia_playback_progress with mode separation:
 *    - playback_mode (audio, video)
 */
class AddMultimediaSongVideoAndPlaybackTypeFields
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // 1. Extend multimedia_songs
        $this->addColumnIfNotExists('multimedia_songs', 'playback_type', "VARCHAR(32) NOT NULL DEFAULT 'audio'");
        $this->addColumnIfNotExists('multimedia_songs', 'default_playback_mode', "VARCHAR(32) NOT NULL DEFAULT 'audio'");
        $this->createIndexIfNotExists('multimedia_songs', 'idx_mm_songs_pb_type', '`playback_type`');

        // 2. Extend multimedia_sources
        $this->addColumnIfNotExists('multimedia_sources', 'media_kind', "VARCHAR(32) NOT NULL DEFAULT 'video'");
        $this->createIndexIfNotExists('multimedia_sources', 'idx_mm_src_kind', '`media_kind`');

        // Migrate existing song sources to media_kind = 'audio'
        try {
            $this->db->execute(
                "UPDATE multimedia_sources SET media_kind = 'audio' WHERE content_type = 'song' AND (source_type = 'audio' OR media_kind = 'video')"
            );
        } catch (\Throwable) {
            // Ignore if rows already migrated or table empty
        }

        // 3. Extend multimedia_playback_progress
        $this->addColumnIfNotExists('multimedia_playback_progress', 'playback_mode', "VARCHAR(32) NULL DEFAULT 'audio'");
    }

    public function down(): void
    {
        // SQLite does not support DROP COLUMN cleanly across older versions;
        // columns remain with default values without breaking rollback compatibility.
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

