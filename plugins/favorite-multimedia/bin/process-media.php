<?php

declare(strict_types=1);

/**
 * Favorite Multimedia — CLI Media Processing & Transcoding Runner
 *
 * Usage:
 *   php bin/process-media.php [--limit=5] [--job-id=N] [--verbose]
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

use FavoriteCMS\Multimedia\Models\MediaProcessingJob;
use FavoriteCMS\Multimedia\Services\MediaProcessingService;

$limit = 5;
$jobId = null;
$verbose = false;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(100, (int)substr($arg, 8)));
    } elseif (str_starts_with($arg, '--job-id=')) {
        $jobId = (int)substr($arg, 9);
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    }
}

echo sprintf("[%s] Starting Favorite Multimedia media processing worker (limit: %d)...\n", gmdate('Y-m-d H:i:s'), $limit);

try {
    if ($jobId !== null && $jobId > 0) {
        $job = MediaProcessingJob::find($jobId);
        if (!$job) {
            fwrite(STDERR, sprintf("[%s] Error: Job #%d not found.\n", gmdate('Y-m-d H:i:s'), $jobId));
            exit(1);
        }
        $result = MediaProcessingService::processQueue(1);
    } else {
        $result = MediaProcessingService::processQueue($limit);
    }

    echo sprintf(
        "[%s] Completed: %d processed, %d completed, %d failed.\n",
        gmdate('Y-m-d H:i:s'),
        $result['processed'] ?? 0,
        $result['completed'] ?? 0,
        $result['failed'] ?? 0
    );

    if ($verbose && !empty($result['jobs'])) {
        foreach ($result['jobs'] as $j) {
            echo sprintf("  - Job #%d: %s%s\n", $j['job_id'], $j['status'], !empty($j['error']) ? ' (' . $j['error'] . ')' : '');
        }
    }

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("[%s] Fatal processing worker error: %s\n", gmdate('Y-m-d H:i:s'), $e->getMessage()));
    exit(1);
}
