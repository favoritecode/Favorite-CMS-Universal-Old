<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Phase 16 Multiple Download Sources Migration
 *
 * Creates the scalable relational table `multimedia_download_sources`
 * to support multiple download links per content item (Movie, Episode, Song).
 *
 * Idempotently migrates existing single `download_url` values from:
 * - multimedia_movies
 * - multimedia_episodes
 * - multimedia_songs
 */
class CreateMultimediaDownloadSourcesTable
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

        // 1. Create multimedia_download_sources table
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_download_sources` (
                `id`           {$pkBigint},
                `content_type` VARCHAR(32)   NOT NULL,
                `content_id`   BIGINT        NOT NULL,
                `label`        VARCHAR(191)  NOT NULL DEFAULT 'Download',
                `url`          VARCHAR(1000) NOT NULL,
                `quality`      VARCHAR(32)   NULL,
                `format`       VARCHAR(32)   NULL,
                `provider`     VARCHAR(64)   NULL,
                `sort_order`   INT           NOT NULL DEFAULT 0,
                `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
                `created_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   {$updatedAt}
            ){$engine};
        ");

        // 2. Create indexes
        $this->createIndexIfNotExists('multimedia_download_sources', 'idx_mm_dl_src_content', '`content_type`, `content_id`');
        $this->createIndexIfNotExists('multimedia_download_sources', 'idx_mm_dl_src_active', '`is_active`');

        // 3. Backward-compatible idempotent data migration for legacy single download_url
        $this->migrateLegacyDownloadUrls('movie', 'multimedia_movies');
        $this->migrateLegacyDownloadUrls('episode', 'multimedia_episodes');
        $this->migrateLegacyDownloadUrls('song', 'multimedia_songs');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `multimedia_download_sources`");
    }

    /**
     * Idempotently migrates existing single download_url columns into multimedia_download_sources.
     */
    protected function migrateLegacyDownloadUrls(string $contentType, string $table): void
    {
        try {
            if (!$this->hasColumn($table, 'download_url')) {
                return;
            }

            $rows = $this->db->select("SELECT id, download_url FROM `{$table}` WHERE download_url IS NOT NULL AND download_url != ''");
            if (empty($rows)) {
                return;
            }

            foreach ($rows as $row) {
                $id = (int)(is_array($row) ? ($row['id'] ?? 0) : ($row->id ?? 0));
                $url = trim((string)(is_array($row) ? ($row['download_url'] ?? '') : ($row->download_url ?? '')));

                if ($id <= 0 || $url === '') {
                    continue;
                }

                // Check for existing record to maintain idempotency
                $existing = $this->db->selectOne(
                    "SELECT id FROM `multimedia_download_sources` WHERE content_type = ? AND content_id = ? AND url = ?",
                    [$contentType, $id, $url]
                );

                if ($existing !== null) {
                    continue;
                }

                $format = $this->inferFormat($url);
                $provider = $this->inferProvider($url);

                $this->db->insert('multimedia_download_sources', [
                    'content_type' => $contentType,
                    'content_id'   => $id,
                    'label'        => 'Direct Download',
                    'url'          => $url,
                    'quality'      => null,
                    'format'       => $format,
                    'provider'     => $provider,
                    'sort_order'   => 0,
                    'is_active'    => 1,
                    'created_at'   => gmdate('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable) {
            // Defensive: ensure migration does not fail if legacy tables are not yet present
        }
    }

    protected function hasColumn(string $table, string $column): bool
    {
        try {
            if ($this->isSqlite()) {
                $cols = $this->db->select("PRAGMA table_info(`{$table}`)");
                foreach ($cols as $col) {
                    $name = is_array($col) ? ($col['name'] ?? '') : ($col->name ?? '');
                    if (strtolower((string)$name) === strtolower($column)) {
                        return true;
                    }
                }
                return false;
            } else {
                $cols = $this->db->select("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
                return !empty($cols);
            }
        } catch (\Throwable) {
            return false;
        }
    }

    protected function inferFormat(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }
        return strtoupper($ext);
    }

    protected function inferProvider(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (str_contains($host, 'drive.google.com')) {
            return 'Google Drive';
        }
        if (str_contains($host, 'dropbox.com')) {
            return 'Dropbox';
        }
        if (str_contains($host, 'mega.nz') || str_contains($host, 'mega.io')) {
            return 'Mega';
        }
        if (str_contains($host, 'mediafire.com')) {
            return 'MediaFire';
        }
        return 'Direct';
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
            $existing = $this->db->select("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
            if (empty($existing)) {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            }
        } catch (\Throwable) {
        }
    }
}

