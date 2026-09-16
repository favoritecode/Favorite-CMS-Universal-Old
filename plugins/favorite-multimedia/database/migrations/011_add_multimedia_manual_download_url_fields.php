<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 15 Manual Download URL Migration
 *
 * Adds optional manual download_url field to:
 * - multimedia_movies
 * - multimedia_episodes
 * - multimedia_songs
 *
 * Allows administrators to specify legitimate direct download links
 * separately from third-party or embedded playback streams.
 */
class AddMultimediaManualDownloadUrlFields
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->addColumnIfNotExists('multimedia_movies', 'download_url', 'VARCHAR(500) NULL');
        $this->addColumnIfNotExists('multimedia_episodes', 'download_url', 'VARCHAR(500) NULL');
        $this->addColumnIfNotExists('multimedia_songs', 'download_url', 'VARCHAR(500) NULL');
    }

    public function down(): void
    {
        // SQLite does not support DROP COLUMN cleanly across older versions;
        // columns remain with default NULL values without breaking rollback compatibility.
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

