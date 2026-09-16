<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Multimedia — Migration 013
 *
 * Adds content ownership and moderation audit fields to:
 * - multimedia_movies
 * - multimedia_series
 * - multimedia_episodes
 * - multimedia_songs
 * - multimedia_albums
 * - multimedia_playlists
 *
 * Fields:
 * - user_id          (canonical owner/creator, BIGINT NULL)
 * - approved_by      (moderator/admin user_id, BIGINT NULL)
 * - approved_at      (approval timestamp, DATETIME NULL)
 * - rejected_by      (moderator/admin user_id, BIGINT NULL)
 * - rejected_at      (rejection timestamp, DATETIME NULL)
 * - rejection_reason (editorial feedback, TEXT NULL)
 */
class AddMultimediaOwnershipAndModerationFields
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
            'multimedia_albums',
            'multimedia_playlists',
        ];

        $adminId = $this->resolveCanonicalAdminId();

        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            // 1. user_id
            if (!$this->hasColumn($table, 'user_id')) {
                $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `user_id` BIGINT NULL DEFAULT NULL");
            }

            // 2. approved_by
            if (!$this->hasColumn($table, 'approved_by')) {
                $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `approved_by` BIGINT NULL DEFAULT NULL");
            }

            // 3. approved_at
            if (!$this->hasColumn($table, 'approved_at')) {
                $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `approved_at` DATETIME NULL DEFAULT NULL");
            }

            // 4. rejected_by
            if (!$this->hasColumn($table, 'rejected_by')) {
                $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `rejected_by` BIGINT NULL DEFAULT NULL");
            }

            // 5. rejected_at
            if (!$this->hasColumn($table, 'rejected_at')) {
                $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `rejected_at` DATETIME NULL DEFAULT NULL");
            }

            // 6. rejection_reason
            if (!$this->hasColumn($table, 'rejection_reason')) {
                $this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `rejection_reason` TEXT NULL DEFAULT NULL");
            }

            // 7. Preserve existing trustworthy creator/author if column already exists
            foreach (['created_by', 'author_id', 'owner_id'] as $creatorCol) {
                if ($this->hasColumn($table, $creatorCol)) {
                    try {
                        $this->db->execute("UPDATE `{$table}` SET `user_id` = `{$creatorCol}` WHERE `user_id` IS NULL AND `{$creatorCol}` IS NOT NULL AND `{$creatorCol}` > 0");
                    } catch (\Throwable) {
                    }
                }
            }

            // 8. Safely assign canonical super-admin / admin owner for legacy content
            if ($adminId !== null && $adminId > 0) {
                try {
                    $this->db->execute("UPDATE `{$table}` SET `user_id` = ? WHERE `user_id` IS NULL", [$adminId]);
                } catch (\Throwable) {
                }
            }

            // 9. Create indexes
            $short = str_replace('multimedia_', '', $table);
            $this->createIndexIfNotExists($table, "idx_mm_{$short}_user", '`user_id`');
            $this->createIndexIfNotExists($table, "idx_mm_{$short}_status_user", '`status`, `user_id`');
        }
    }

    public function down(): void
    {
        // Safe no-op to prevent accidental data loss in production rollbacks
    }

    public function resolveCanonicalAdminId(): ?int
    {
        try {
            // 1. Priority 1: canonical active super-admin via user_roles
            if ($this->tableExists('users') && $this->tableExists('roles') && $this->tableExists('user_roles')) {
                $row = $this->db->selectOne("
                    SELECT u.id 
                    FROM users u
                    JOIN user_roles ur ON u.id = ur.user_id
                    JOIN roles r ON ur.role_id = r.id
                    WHERE r.slug = 'super-admin'
                      AND (u.status IS NULL OR u.status = 'active')
                    ORDER BY u.id ASC
                    LIMIT 1
                ");
                if ($row && !empty($row->id)) {
                    return (int)$row->id;
                }

                // 2. Priority 2: canonical active admin via user_roles
                $row = $this->db->selectOne("
                    SELECT u.id 
                    FROM users u
                    JOIN user_roles ur ON u.id = ur.user_id
                    JOIN roles r ON ur.role_id = r.id
                    WHERE r.slug = 'admin'
                      AND (u.status IS NULL OR u.status = 'active')
                    ORDER BY u.id ASC
                    LIMIT 1
                ");
                if ($row && !empty($row->id)) {
                    return (int)$row->id;
                }
            }

            // 3. Check legacy/direct role column on users table if present
            if ($this->tableExists('users') && $this->hasColumn('users', 'role')) {
                $row = $this->db->selectOne("
                    SELECT id FROM users
                    WHERE role = 'super-admin'
                      AND (status IS NULL OR status = 'active')
                    ORDER BY id ASC LIMIT 1
                ");
                if ($row && !empty($row->id)) {
                    return (int)$row->id;
                }

                $row = $this->db->selectOne("
                    SELECT id FROM users
                    WHERE role = 'admin'
                      AND (status IS NULL OR status = 'active')
                    ORDER BY id ASC LIMIT 1
                ");
                if ($row && !empty($row->id)) {
                    return (int)$row->id;
                }
            }

            // 4. NEVER fall back to arbitrary lowest user/subscriber/contributor!
            // Return null so unowned legacy content remains NULL (schema-compatible system-owned).
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function tableExists(string $table): bool
    {
        try {
            if ($this->isSqlite()) {
                $row = $this->db->selectOne("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
                return !empty($row);
            }
            $row = $this->db->selectOne("SHOW TABLES LIKE '{$table}'");
            return !empty($row);
        } catch (\Throwable) {
            return false;
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
            }

            $cols = $this->db->select("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            return !empty($cols);
        } catch (\Throwable) {
            return false;
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
            try {
                $this->db->execute("CREATE {$uniqueClause} INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` ({$columns})");
            } catch (\Throwable) {
            }
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

