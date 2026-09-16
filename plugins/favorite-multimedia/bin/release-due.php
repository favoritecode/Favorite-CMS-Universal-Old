<?php

declare(strict_types=1);

/**
 * Favorite Multimedia — CLI Due Release Runner
 *
 * Usage:
 *   php bin/release-due.php [--limit=100]
 */

if (file_exists(__DIR__ . '/../../../bootstrap.php')) {
    require_once __DIR__ . '/../../../bootstrap.php';
}

// Find and load autoloader / bootstrap
$autoloadPaths = [
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
    __DIR__ . '/../autoload.php',
];

$loaded = false;
foreach ($autoloadPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $loaded = true;
        break;
    }
}

// Load plugin autoloader if not already loaded
if (file_exists(__DIR__ . '/../autoload.php')) {
    require_once __DIR__ . '/../autoload.php';
}

use FavoriteCMS\Multimedia\Services\MultimediaReleaseService;

// Parse CLI arguments
$limit = 100;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(1000, (int)substr($arg, 8)));
    }
}

echo sprintf("[%s] Starting Favorite Multimedia due release processing (limit: %d)...\n", gmdate('Y-m-d H:i:s'), $limit);

try {
    $result = MultimediaReleaseService::processDueReleases($limit);

    echo sprintf(
        "[%s] Completed: %d processed, %d published, %d unpublished, %d errors.\n",
        gmdate('Y-m-d H:i:s'),
        $result['processed'] ?? 0,
        $result['published'] ?? 0,
        $result['unpublished'] ?? 0,
        count($result['errors'] ?? [])
    );

    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $err) {
            fwrite(STDERR, sprintf("Error on %s #%s: %s\n", $err['content_type'] ?? 'item', $err['content_id'] ?? '?', $err['error'] ?? 'Unknown error'));
        }
    }

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("[%s] Fatal Release Runner Error: %s\n", gmdate('Y-m-d H:i:s'), $e->getMessage()));
    exit(1);
}

