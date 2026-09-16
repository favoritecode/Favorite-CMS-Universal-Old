<?php

declare(strict_types=1);

/**
 * Favorite Multimedia — CLI Media Storage & Lifecycle Manager
 *
 * Usage:
 *   php bin/manage-storage.php --action=test-connection
 *   php bin/manage-storage.php --action=stats
 *   php bin/manage-storage.php --action=migrate [--delete-local] [--limit=50]
 *   php bin/manage-storage.php --action=cleanup-orphans [--dry-run]
 */

if (file_exists(__DIR__ . '/../../../bootstrap.php')) {
    require_once __DIR__ . '/../../../bootstrap.php';
}

$autoloadPaths = [
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
    __DIR__ . '/../autoload.php',
];

foreach ($autoloadPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

if (file_exists(__DIR__ . '/../autoload.php')) {
    require_once __DIR__ . '/../autoload.php';
}

use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Services\MediaStorageService;
use FavoriteCMS\Multimedia\Storage\MediaStorageManager;

$action = 'stats';
$limit = 50;
$deleteLocal = false;
$dryRun = false;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--action=')) {
        $action = substr($arg, 9);
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(1000, (int)substr($arg, 8)));
    } elseif ($arg === '--delete-local') {
        $deleteLocal = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

echo sprintf("[%s] Favorite Multimedia Storage Manager: action=%s\n", gmdate('Y-m-d H:i:s'), $action);

try {
    switch ($action) {
        case 'test-connection':
            $driver = (string)\FavoriteCMS\Models\Setting::get('multimedia_storage_driver', 'local');
            $disk = MediaStorageManager::getDisk($driver);
            $res = $disk->testConnection();
            echo sprintf("[%s] Health check: %s (%s)\n", gmdate('Y-m-d H:i:s'), $res['success'] ? 'SUCCESS' : 'FAILED', $res['message']);
            exit($res['success'] ? 0 : 1);

        case 'stats':
            $summary = MediaStorageService::getStorageUsageSummary();
            echo sprintf("[%s] Active Driver: %s\n", gmdate('Y-m-d H:i:s'), $summary['active_driver']);
            echo sprintf("[%s] Total Managed Size: %s bytes\n", gmdate('Y-m-d H:i:s'), number_format($summary['total_bytes']));
            foreach ($summary['driver_stats'] as $drv => $st) {
                echo sprintf("  - %s: %d files (%s bytes)\n", $drv, $st['count'], number_format($st['size']));
            }
            exit(0);

        case 'migrate':
            echo sprintf("[%s] Starting migration to object storage (limit: %d, delete_local: %s)...\n", gmdate('Y-m-d H:i:s'), $limit, $deleteLocal ? 'yes' : 'no');
            $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
            $sources = $db->select("SELECT id FROM multimedia_sources WHERE (storage_driver IS NULL OR storage_driver = 'local') AND is_migrated = 0 LIMIT " . (int)$limit);
            $migrated = 0;
            foreach ($sources as $s) {
                $src = MediaSource::find((int)$s->id);
                if ($src) {
                    $res = MediaStorageService::migrateSourceToRemote($src, $deleteLocal);
                    if ($res['success'] ?? false) {
                        $migrated++;
                        echo sprintf("  [+] Migrated source #%d -> %s\n", $src->id, $res['key'] ?? '');
                    } else {
                        echo sprintf("  [-] Failed source #%d: %s\n", $src->id, $res['error'] ?? 'Unknown error');
                    }
                }
            }
            echo sprintf("[%s] Migration completed: %d sources migrated.\n", gmdate('Y-m-d H:i:s'), $migrated);
            exit(0);

        case 'cleanup-orphans':
            $res = MediaStorageService::cleanupOrphans($dryRun, $limit);
            echo sprintf("[%s] Orphan cleanup (%s): %d files, %s bytes\n", gmdate('Y-m-d H:i:s'), $dryRun ? 'DRY RUN' : 'EXECUTED', $res['count'], number_format($res['total_bytes']));
            exit(0);

        default:
            fwrite(STDERR, sprintf("Unknown action: %s\n", $action));
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("[%s] Fatal Storage Error: %s\n", gmdate('Y-m-d H:i:s'), $e->getMessage()));
    exit(1);
}
