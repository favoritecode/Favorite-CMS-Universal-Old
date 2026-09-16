<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 9 Scheduling Migration
 *
 * Adds editorial release management fields to multimedia content tables:
 * 1. publish_at   - Scheduled UTC release timestamp
 * 2. published_at - Actual UTC publication timestamp
 * 3. unpublish_at - Optional scheduled UTC unpublish timestamp
 *
 * Applied to:
 * - multimedia_movies
 * - multimedia_series
 * - multimedia_episodes
 * - multimedia_songs
 */
class AddMultimediaSchedulingFields
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $tables = [
            'multimedia_movies',
            'multimedia_series',
            'multimedia_episodes',
            'multimedia_songs',
        ];

        foreach ($tables as $table) {
            $this->addColumnIfNotExists($table, 'publish_at', 'DATETIME NULL DEFAULT NULL');
            $this->addColumnIfNotExists($table, 'published_at', 'DATETIME NULL DEFAULT NULL');
            $this->addColumnIfNotExists($table, 'unpublish_at', 'DATETIME NULL DEFAULT NULL');

            // For existing published items, backfill published_at from created_at
            $this->db->execute(
                "UPDATE `{$table}` SET `published_at` = `created_at` WHERE `status` = 'published' AND `published_at` IS NULL"
            );

            // Create index for due release querying: status + publish_at
            $idxSched = 'idx_' . substr($table, 11, 4) . '_sched';
            $this->createIndexIfNotExists($table, $idxSched, '`status`, `publish_at`');

            // Create index for due unpublish querying: status + unpublish_at
            $idxUnpub = 'idx_' . substr($table, 11, 4) . '_unpub';
            $this->createIndexIfNotExists($table, $idxUnpub, '`status`, `unpublish_at`');
        }
    }

    public function down(): void
    {
        // Dropping columns in SQLite requires table rebuild; in MySQL drop column
        if (!$this->isSqlite()) {
            $tables = ['multimedia_movies', 'multimedia_series', 'multimedia_episodes', 'multimedia_songs'];
            foreach ($tables as $table) {
                try {
                    $this->db->execute("ALTER TABLE `{$table}` DROP COLUMN `publish_at`");
                    $this->db->execute("ALTER TABLE `{$table}` DROP COLUMN `published_at`");
                    $this->db->execute("ALTER TABLE `{$table}` DROP COLUMN `unpublish_at`");
                } catch (\Throwable) {
                }
            }
        }
    }

    protected function addColumnIfNotExists(string $table, string $column, string $typeDef): void
    {
        if ($this->isSqlite()) {
            $cols = $this->db->select("PRAGMA table_info(`{$table}`)");
            foreach ($cols as $col) {
                if (strtolower($col->name ?? '') === strtolower($column)) {
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
                // Index may already exist
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

