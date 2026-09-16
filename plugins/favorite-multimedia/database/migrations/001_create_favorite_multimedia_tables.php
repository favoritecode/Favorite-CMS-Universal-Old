<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Database Schema Migration
 *
 * Creates the core multimedia management tables:
 * 1.  multimedia_genres          - Taxonomy for movies, series, songs
 * 2.  multimedia_artists         - Artist entities with bio and photos
 * 3.  multimedia_albums          - Albums grouping songs
 * 4.  multimedia_movies          - Movies catalog
 * 5.  multimedia_series          - Web series parent entities
 * 6.  multimedia_seasons         - Seasons belonging to web series
 * 7.  multimedia_episodes        - Individual episodes in seasons
 * 8.  multimedia_songs           - Audio tracks
 * 9.  multimedia_playlists       - Curated audio playlists
 * 10. multimedia_playlist_items  - Songs linked to playlists with sort order
 * 11. multimedia_content_genres  - Pivot associating genres to contents
 * 12. multimedia_sources         - Normalized video, audio, HLS & embed sources
 * 13. multimedia_subtitles       - Multi-language WebVTT/SRT subtitles
 * 14. multimedia_analytics       - Lightweight play, view, download audit records
 */
class CreateFavoriteMultimediaTables
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

        // 1. multimedia_genres
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_genres` (
                `id`          {$pkBigint},
                `name`        VARCHAR(191) NOT NULL,
                `slug`        VARCHAR(191) NOT NULL UNIQUE,
                `description` TEXT         NULL,
                `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_genres', 'idx_mm_genres_slug', '`slug`');

        // 2. multimedia_artists
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_artists` (
                `id`         {$pkBigint},
                `name`       VARCHAR(191) NOT NULL,
                `slug`       VARCHAR(191) NOT NULL UNIQUE,
                `photo`      VARCHAR(500) NULL,
                `biography`  TEXT         NULL,
                `status`     VARCHAR(32)  NOT NULL DEFAULT 'active',
                `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at` {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_artists', 'idx_mm_artists_slug', '`slug`');
        $this->createIndexIfNotExists('multimedia_artists', 'idx_mm_artists_status', '`status`');

        // 3. multimedia_albums
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_albums` (
                `id`           {$pkBigint},
                `title`        VARCHAR(191) NOT NULL,
                `slug`         VARCHAR(191) NOT NULL UNIQUE,
                `cover`        VARCHAR(500) NULL,
                `artist_id`    BIGINT       NULL,
                `release_date` DATE         NULL,
                `status`       VARCHAR(32)  NOT NULL DEFAULT 'published',
                `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_albums', 'idx_mm_albums_slug', '`slug`');
        $this->createIndexIfNotExists('multimedia_albums', 'idx_mm_albums_artist', '`artist_id`');

        // 4. multimedia_movies
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_movies` (
                `id`              {$pkBigint},
                `title`           VARCHAR(255) NOT NULL,
                `slug`            VARCHAR(191) NOT NULL UNIQUE,
                `description`     TEXT         NULL,
                `poster`          VARCHAR(500) NULL,
                `backdrop`        VARCHAR(500) NULL,
                `release_year`    INT          NULL,
                `release_date`    DATE         NULL,
                `language`        VARCHAR(64)  NULL,
                `country`         VARCHAR(64)  NULL,
                `duration`        INT          NOT NULL DEFAULT 0,
                `director`        VARCHAR(191) NULL,
                `cast`            TEXT         NULL,
                `trailer_url`     VARCHAR(500) NULL,
                `featured`        TINYINT(1)   NOT NULL DEFAULT 0,
                `status`          VARCHAR(32)  NOT NULL DEFAULT 'published',
                `access_mode`     VARCHAR(32)  NOT NULL DEFAULT 'public',
                `download_policy` VARCHAR(32)  NOT NULL DEFAULT 'inherit',
                `seo_title`       VARCHAR(255) NULL,
                `seo_description` TEXT         NULL,
                `views_count`     BIGINT       NOT NULL DEFAULT 0,
                `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_movies', 'idx_mm_movies_slug', '`slug`');
        $this->createIndexIfNotExists('multimedia_movies', 'idx_mm_movies_status', '`status`');
        $this->createIndexIfNotExists('multimedia_movies', 'idx_mm_movies_access', '`access_mode`');
        $this->createIndexIfNotExists('multimedia_movies', 'idx_mm_movies_featured', '`featured`');

        // 5. multimedia_series
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_series` (
                `id`              {$pkBigint},
                `title`           VARCHAR(255) NOT NULL,
                `slug`            VARCHAR(191) NOT NULL UNIQUE,
                `description`     TEXT         NULL,
                `poster`          VARCHAR(500) NULL,
                `backdrop`        VARCHAR(500) NULL,
                `release_year`    INT          NULL,
                `language`        VARCHAR(64)  NULL,
                `country`         VARCHAR(64)  NULL,
                `director`        VARCHAR(191) NULL,
                `cast`            TEXT         NULL,
                `trailer_url`     VARCHAR(500) NULL,
                `featured`        TINYINT(1)   NOT NULL DEFAULT 0,
                `status`          VARCHAR(32)  NOT NULL DEFAULT 'published',
                `access_mode`     VARCHAR(32)  NOT NULL DEFAULT 'public',
                `download_policy` VARCHAR(32)  NOT NULL DEFAULT 'inherit',
                `seo_title`       VARCHAR(255) NULL,
                `seo_description` TEXT         NULL,
                `views_count`     BIGINT       NOT NULL DEFAULT 0,
                `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_series', 'idx_mm_series_slug', '`slug`');
        $this->createIndexIfNotExists('multimedia_series', 'idx_mm_series_status', '`status`');
        $this->createIndexIfNotExists('multimedia_series', 'idx_mm_series_access', '`access_mode`');

        // 6. multimedia_seasons
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_seasons` (
                `id`            {$pkBigint},
                `series_id`     BIGINT       NOT NULL,
                `season_number` INT          NOT NULL DEFAULT 1,
                `title`         VARCHAR(191) NOT NULL,
                `description`   TEXT         NULL,
                `poster`        VARCHAR(500) NULL,
                `release_date`  DATE         NULL,
                `sort_order`    INT          NOT NULL DEFAULT 0,
                `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_seasons', 'idx_mm_seasons_series', '`series_id`');

        // 7. multimedia_episodes
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_episodes` (
                `id`              {$pkBigint},
                `series_id`       BIGINT       NOT NULL,
                `season_id`       BIGINT       NOT NULL,
                `episode_number`  INT          NOT NULL DEFAULT 1,
                `title`           VARCHAR(255) NOT NULL,
                `slug`            VARCHAR(191) NOT NULL UNIQUE,
                `description`     TEXT         NULL,
                `thumbnail`       VARCHAR(500) NULL,
                `duration`        INT          NOT NULL DEFAULT 0,
                `release_date`    DATE         NULL,
                `status`          VARCHAR(32)  NOT NULL DEFAULT 'published',
                `access_mode`     VARCHAR(32)  NOT NULL DEFAULT 'inherit',
                `download_policy` VARCHAR(32)  NOT NULL DEFAULT 'inherit',
                `sort_order`      INT          NOT NULL DEFAULT 0,
                `views_count`     BIGINT       NOT NULL DEFAULT 0,
                `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_episodes', 'idx_mm_episodes_season', '`season_id`');
        $this->createIndexIfNotExists('multimedia_episodes', 'idx_mm_episodes_series', '`series_id`');
        $this->createIndexIfNotExists('multimedia_episodes', 'idx_mm_episodes_slug', '`slug`');

        // 8. multimedia_songs
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_songs` (
                `id`              {$pkBigint},
                `title`           VARCHAR(255) NOT NULL,
                `slug`            VARCHAR(191) NOT NULL UNIQUE,
                `description`     TEXT         NULL,
                `cover`           VARCHAR(500) NULL,
                `artist_id`       BIGINT       NULL,
                `album_id`        BIGINT       NULL,
                `language`        VARCHAR(64)  NULL,
                `release_date`    DATE         NULL,
                `duration`        INT          NOT NULL DEFAULT 0,
                `lyrics`          TEXT         NULL,
                `featured`        TINYINT(1)   NOT NULL DEFAULT 0,
                `status`          VARCHAR(32)  NOT NULL DEFAULT 'published',
                `access_mode`     VARCHAR(32)  NOT NULL DEFAULT 'public',
                `download_policy` VARCHAR(32)  NOT NULL DEFAULT 'inherit',
                `seo_title`       VARCHAR(255) NULL,
                `seo_description` TEXT         NULL,
                `plays_count`     BIGINT       NOT NULL DEFAULT 0,
                `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_songs', 'idx_mm_songs_slug', '`slug`');
        $this->createIndexIfNotExists('multimedia_songs', 'idx_mm_songs_artist', '`artist_id`');
        $this->createIndexIfNotExists('multimedia_songs', 'idx_mm_songs_album', '`album_id`');
        $this->createIndexIfNotExists('multimedia_songs', 'idx_mm_songs_status', '`status`');

        // 9. multimedia_playlists
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_playlists` (
                `id`              {$pkBigint},
                `title`           VARCHAR(255) NOT NULL,
                `slug`            VARCHAR(191) NOT NULL UNIQUE,
                `description`     TEXT         NULL,
                `cover`           VARCHAR(500) NULL,
                `featured`        TINYINT(1)   NOT NULL DEFAULT 0,
                `status`          VARCHAR(32)  NOT NULL DEFAULT 'published',
                `access_mode`     VARCHAR(32)  NOT NULL DEFAULT 'public',
                `download_policy` VARCHAR(32)  NOT NULL DEFAULT 'inherit',
                `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_playlists', 'idx_mm_pl_slug', '`slug`');
        $this->createIndexIfNotExists('multimedia_playlists', 'idx_mm_pl_status', '`status`');

        // 10. multimedia_playlist_items
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_playlist_items` (
                `id`          {$pkBigint},
                `playlist_id` BIGINT    NOT NULL,
                `song_id`     BIGINT    NOT NULL,
                `sort_order`  INT       NOT NULL DEFAULT 0,
                `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_playlist_items', 'idx_mm_pli_pl', '`playlist_id`');
        $this->createIndexIfNotExists('multimedia_playlist_items', 'idx_mm_pli_song', '`song_id`');

        // 11. multimedia_content_genres
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_content_genres` (
                `id`           {$pkBigint},
                `content_type` VARCHAR(32) NOT NULL,
                `content_id`   BIGINT      NOT NULL,
                `genre_id`     BIGINT      NOT NULL
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_content_genres', 'idx_mm_cg_content', '`content_type`, `content_id`');
        $this->createIndexIfNotExists('multimedia_content_genres', 'idx_mm_cg_genre', '`genre_id`');

        // 12. multimedia_sources
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_sources` (
                `id`             {$pkBigint},
                `content_type`   VARCHAR(32)  NOT NULL,
                `content_id`     BIGINT       NOT NULL,
                `source_mode`    VARCHAR(32)  NOT NULL DEFAULT 'url',
                `source_type`    VARCHAR(32)  NOT NULL DEFAULT 'video',
                `url_or_path`    TEXT         NOT NULL,
                `label`          VARCHAR(191) NOT NULL DEFAULT 'Default',
                `quality`        VARCHAR(32)  NULL,
                `mime_type`      VARCHAR(128) NULL,
                `poster`         VARCHAR(500) NULL,
                `is_default`     TINYINT(1)   NOT NULL DEFAULT 1,
                `allow_download` VARCHAR(32)  NOT NULL DEFAULT 'inherit',
                `status`         VARCHAR(32)  NOT NULL DEFAULT 'active',
                `sort_order`     INT          NOT NULL DEFAULT 0,
                `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_sources', 'idx_mm_src_content', '`content_type`, `content_id`');
        $this->createIndexIfNotExists('multimedia_sources', 'idx_mm_src_type', '`source_type`');

        // 13. multimedia_subtitles
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_subtitles` (
                `id`           {$pkBigint},
                `content_type` VARCHAR(32)  NOT NULL,
                `content_id`   BIGINT       NOT NULL,
                `language`     VARCHAR(64)  NOT NULL,
                `label`        VARCHAR(191) NOT NULL,
                `file_or_url`  TEXT         NOT NULL,
                `format`       VARCHAR(16)  NOT NULL DEFAULT 'vtt',
                `is_default`   TINYINT(1)   NOT NULL DEFAULT 0,
                `sort_order`   INT          NOT NULL DEFAULT 0,
                `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   {$updatedAt}
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_subtitles', 'idx_mm_sub_content', '`content_type`, `content_id`');

        // 14. multimedia_analytics
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `multimedia_analytics` (
                `id`           {$pkBigint},
                `content_type` VARCHAR(32) NOT NULL,
                `content_id`   BIGINT      NOT NULL,
                `event_type`   VARCHAR(32) NOT NULL,
                `user_id`      BIGINT      NULL,
                `ip_hash`      VARCHAR(64) NULL,
                `created_at`   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");
        $this->createIndexIfNotExists('multimedia_analytics', 'idx_mm_an_content', '`content_type`, `content_id`, `event_type`');
        $this->createIndexIfNotExists('multimedia_analytics', 'idx_mm_an_created', '`created_at`');
    }

    public function down(): void
    {
        $tables = [
            'multimedia_analytics',
            'multimedia_subtitles',
            'multimedia_sources',
            'multimedia_content_genres',
            'multimedia_playlist_items',
            'multimedia_playlists',
            'multimedia_songs',
            'multimedia_episodes',
            'multimedia_seasons',
            'multimedia_series',
            'multimedia_movies',
            'multimedia_albums',
            'multimedia_artists',
            'multimedia_genres',
        ];

        foreach ($tables as $table) {
            $this->db->execute("DROP TABLE IF EXISTS `{$table}`");
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
