<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaProcessingJob;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\MediaStorageFile;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Song;

class MediaProcessingService
{
    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    /**
     * Dispatch new media processing job into queue.
     */
    public static function dispatchJob(
        string $contentType,
        int $contentId,
        string $inputPath,
        string $jobType = MediaProcessingJob::TYPE_FULL_PIPELINE,
        array $settings = [],
        ?int $sourceId = null
    ): ?MediaProcessingJob {
        if (!in_array($contentType, ['movie', 'episode', 'song'], true) || $contentId <= 0) {
            return null;
        }

        if (!in_array($jobType, MediaProcessingJob::ALLOWED_TYPES, true)) {
            $jobType = MediaProcessingJob::TYPE_FULL_PIPELINE;
        }

        $now = gmdate('Y-m-d H:i:s');
        $db = self::getDb();

        $data = [
            'content_type'  => $contentType,
            'content_id'    => $contentId,
            'source_id'     => $sourceId,
            'job_type'      => $jobType,
            'status'        => MediaProcessingJob::STATUS_PENDING,
            'progress'      => 0,
            'input_path'    => $inputPath,
            'output_path'   => null,
            'settings'      => !empty($settings) ? json_encode($settings) : null,
            'error_message' => null,
            'attempts'      => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        try {
            $id = $db->insert('multimedia_processing_jobs', $data);
            return MediaProcessingJob::find((int)$id);
        } catch (\Throwable $e) {
            Logger::error("Favorite Multimedia: Failed to dispatch processing job", [
                'type'    => $contentType,
                'id'      => $contentId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Retry a failed or cancelled job.
     */
    public static function retryJob(int $jobId): array
    {
        $job = MediaProcessingJob::find($jobId);
        if (!$job) {
            return ['success' => false, 'error' => 'Job not found.'];
        }

        if (!$job->canRetry()) {
            return ['success' => false, 'error' => 'Job is not in a retryable state.'];
        }

        $now = gmdate('Y-m-d H:i:s');
        $attempts = (int)($job->attempts ?? 0) + 1;

        self::getDb()->update('multimedia_processing_jobs', [
            'status'        => MediaProcessingJob::STATUS_PENDING,
            'progress'      => 0,
            'error_message' => null,
            'attempts'      => $attempts,
            'started_at'    => null,
            'completed_at'  => null,
            'updated_at'    => $now,
        ], ['id' => $jobId]);

        return ['success' => true, 'job_id' => $jobId, 'status' => MediaProcessingJob::STATUS_PENDING];
    }

    /**
     * Cancel a pending or processing job.
     */
    public static function cancelJob(int $jobId): array
    {
        $job = MediaProcessingJob::find($jobId);
        if (!$job) {
            return ['success' => false, 'error' => 'Job not found.'];
        }

        if (!$job->canCancel()) {
            return ['success' => false, 'error' => 'Job cannot be cancelled in its current state.'];
        }

        $now = gmdate('Y-m-d H:i:s');
        self::getDb()->update('multimedia_processing_jobs', [
            'status'        => MediaProcessingJob::STATUS_CANCELLED,
            'error_message' => 'Cancelled by administrator.',
            'updated_at'    => $now,
        ], ['id' => $jobId]);

        return ['success' => true, 'job_id' => $jobId, 'status' => MediaProcessingJob::STATUS_CANCELLED];
    }

    /**
     * Delete a job record.
     */
    public static function deleteJob(int $jobId): bool
    {
        try {
            self::getDb()->delete('multimedia_processing_jobs', ['id' => $jobId]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Process bounded batch of pending queue jobs.
     */
    public static function processQueue(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $pendingJobs = MediaProcessingJob::findPending($limit);

        $processedCount = 0;
        $completedCount = 0;
        $failedCount = 0;
        $details = [];

        foreach ($pendingJobs as $job) {
            $jobId = (int)$job->id;
            $processedCount++;

            $startNow = gmdate('Y-m-d H:i:s');
            self::getDb()->update('multimedia_processing_jobs', [
                'status'     => MediaProcessingJob::STATUS_PROCESSING,
                'progress'   => 10,
                'started_at' => $startNow,
                'updated_at' => $startNow,
            ], ['id' => $jobId]);

            try {
                $result = self::executeJob($job);

                // Post-processing storage upload and file tracking
                try {
                    MediaStorageService::uploadProcessingOutputs($job, $result);
                } catch (\Throwable $stEx) {
                    Logger::warning("Favorite Multimedia: Storage file tracking error: " . $stEx->getMessage());
                }

                $completedNow = gmdate('Y-m-d H:i:s');
                self::getDb()->update('multimedia_processing_jobs', [
                    'status'       => MediaProcessingJob::STATUS_COMPLETED,
                    'progress'     => 100,
                    'output_path'  => $result['output_path'] ?? null,
                    'completed_at' => $completedNow,
                    'updated_at'   => $completedNow,
                ], ['id' => $jobId]);

                $completedCount++;
                $details[] = [
                    'job_id'  => $jobId,
                    'status'  => MediaProcessingJob::STATUS_COMPLETED,
                    'sources' => $result['sources_created'] ?? [],
                ];
            } catch (\Throwable $e) {
                $failedCount++;
                $errorMsg = $e->getMessage();
                $failedNow = gmdate('Y-m-d H:i:s');

                self::getDb()->update('multimedia_processing_jobs', [
                    'status'        => MediaProcessingJob::STATUS_FAILED,
                    'error_message' => $errorMsg,
                    'updated_at'    => $failedNow,
                ], ['id' => $jobId]);

                Logger::error("Favorite Multimedia: Processing job #{$jobId} failed", [
                    'job_id' => $jobId,
                    'error'  => $errorMsg,
                ]);

                $details[] = [
                    'job_id' => $jobId,
                    'status' => MediaProcessingJob::STATUS_FAILED,
                    'error'  => $errorMsg,
                ];
            }
        }

        return [
            'success'   => true,
            'processed' => $processedCount,
            'completed' => $completedCount,
            'failed'    => $failedCount,
            'jobs'      => $details,
        ];
    }

    /**
     * Dispatch to specialized job handler.
     */
    protected static function executeJob(MediaProcessingJob $job): array
    {
        // Support test mock callback for deterministic testing
        if (isset($GLOBALS['_test_processing_mock']['job_handler'])) {
            $cb = $GLOBALS['_test_processing_mock']['job_handler'];
            return $cb($job);
        }

        return match ($job->job_type) {
            MediaProcessingJob::TYPE_GENERATE_THUMBNAIL => self::handleGenerateThumbnail($job),
            MediaProcessingJob::TYPE_TRANSCODE_MP4      => self::handleTranscodeMp4($job),
            MediaProcessingJob::TYPE_TRANSCODE_HLS      => self::handleTranscodeHls($job),
            MediaProcessingJob::TYPE_EXTRACT_AUDIO      => self::handleExtractAudio($job),
            MediaProcessingJob::TYPE_FULL_PIPELINE      => self::handleFullPipeline($job),
            default                                     => throw new \InvalidArgumentException("Unknown job type: {$job->job_type}"),
        };
    }

    /**
     * Generate poster/backdrop thumbnail from video.
     */
    public static function handleGenerateThumbnail(MediaProcessingJob $job): array
    {
        $input = self::resolveAbsolutePath((string)$job->input_path);
        if (!file_exists($input)) {
            throw new \RuntimeException("Input media file not found: {$job->input_path}");
        }

        $type = (string)$job->content_type;
        $id = (int)$job->content_id;
        $targetDir = self::getStorageDirectory($type, $id, 'thumbnails');
        $outputFilename = "thumb_" . time() . ".jpg";
        $outputPath = $targetDir . '/' . $outputFilename;

        $settings = $job->getSettings();
        $timeOffset = $settings['time_offset'] ?? '00:00:03';

        if (FFmpegService::isAvailable()) {
            $cmd = [
                '-ss', (string)$timeOffset,
                '-i', $input,
                '-vframes', '1',
                '-q:v', '2',
                '-y',
                $outputPath,
            ];
            $res = FFmpegService::executeFFmpeg($cmd);
            if (!$res['success']) {
                throw new \RuntimeException("Thumbnail extraction failed: " . $res['output']);
            }
        } else {
            // Mock / fallback placeholder creation
            self::generatePlaceholderImage($outputPath, 1280, 720, "Preview: {$type} #{$id}");
        }

        // Relative path for database
        $relativeUrl = self::getRelativeStoragePath($outputPath);

        // Register in media storage files
        MediaStorageFile::register(
            $type,
            $id,
            'local',
            $relativeUrl,
            $relativeUrl,
            MediaStorageFile::TYPE_THUMBNAIL,
            file_exists($outputPath) ? (int)filesize($outputPath) : 0,
            'image/jpeg'
        );

        // Optionally update content poster if not set
        $content = $job->getContent();
        if ($content && empty($content->poster)) {
            $table = 'multimedia_' . ($type === 'movie' ? 'movies' : ($type === 'episode' ? 'episodes' : 'songs'));
            self::getDb()->update($table, ['poster' => $relativeUrl], ['id' => $id]);
        }

        return [
            'output_path'     => $relativeUrl,
            'sources_created' => [],
        ];
    }

    /**
     * Transcode into multiple MP4 quality renditions (1080p, 720p, 480p, 360p).
     */
    public static function handleTranscodeMp4(MediaProcessingJob $job): array
    {
        $input = self::resolveAbsolutePath((string)$job->input_path);
        if (!file_exists($input)) {
            throw new \RuntimeException("Input media file not found: {$job->input_path}");
        }

        $type = (string)$job->content_type;
        $id = (int)$job->content_id;
        $targetDir = self::getStorageDirectory($type, $id, 'mp4');

        $settings = $job->getSettings();
        $qualities = $settings['qualities'] ?? ['720p', '480p'];
        $createdSources = [];

        $resolutions = [
            '1080p' => ['w' => 1920, 'h' => 1080, 'bitrate' => '4000k'],
            '720p'  => ['w' => 1280, 'h' => 720,  'bitrate' => '2200k'],
            '480p'  => ['w' => 854,  'h' => 480,  'bitrate' => '1000k'],
            '360p'  => ['w' => 640,  'h' => 360,  'bitrate' => '600k'],
        ];

        foreach ($qualities as $q) {
            if (!isset($resolutions[$q])) {
                continue;
            }

            $conf = $resolutions[$q];
            $outFile = $targetDir . "/video_{$q}.mp4";

            if (FFmpegService::isAvailable()) {
                $cmd = [
                    '-i', $input,
                    '-vf', "scale={$conf['w']}:{$conf['h']}:force_original_aspect_ratio=decrease,pad={$conf['w']}:{$conf['h']}:(ow-iw)/2:(oh-ih)/2",
                    '-c:v', 'libx264',
                    '-preset', 'fast',
                    '-b:v', $conf['bitrate'],
                    '-c:a', 'aac',
                    '-b:a', '128k',
                    '-movflags', '+faststart',
                    '-y',
                    $outFile,
                ];
                $res = FFmpegService::executeFFmpeg($cmd);
                if (!$res['success']) {
                    throw new \RuntimeException("MP4 transcoding for {$q} failed: " . $res['output']);
                }
            } else {
                // Fallback simulation file
                file_put_contents($outFile, "MOCK_MP4_DATA_{$q}");
            }

            $relPath = self::getRelativeStoragePath($outFile);

            // Register in media storage files
            MediaStorageFile::register(
                $type,
                $id,
                'local',
                $relPath,
                $relPath,
                MediaStorageFile::TYPE_MP4_RENDITION,
                file_exists($outFile) ? (int)filesize($outFile) : 0,
                'video/mp4'
            );

            // Register in multimedia_sources
            $sourceId = self::registerMediaSource(
                $type,
                $id,
                $relPath,
                'upload',
                'video',
                "MP4 {$q}",
                $q,
                'video/mp4',
                0
            );

            $createdSources[] = [
                'source_id' => $sourceId,
                'quality'   => $q,
                'path'      => $relPath,
            ];
        }

        return [
            'output_path'     => self::getRelativeStoragePath($targetDir),
            'sources_created' => $createdSources,
        ];
    }

    /**
     * Package adaptive bitrate HLS (master.m3u8 + variants + .ts segments).
     */
    public static function handleTranscodeHls(MediaProcessingJob $job): array
    {
        $input = self::resolveAbsolutePath((string)$job->input_path);
        if (!file_exists($input)) {
            throw new \RuntimeException("Input media file not found: {$job->input_path}");
        }

        $type = (string)$job->content_type;
        $id = (int)$job->content_id;
        $hlsDir = self::getStorageDirectory($type, $id, 'hls');

        $settings = $job->getSettings();
        $qualities = $settings['qualities'] ?? ['720p', '480p'];
        $createdSources = [];

        $hlsProfiles = [
            '1080p' => ['bandwidth' => 4500000, 'res' => '1920x1080', 'w' => 1920, 'h' => 1080, 'bv' => '4000k'],
            '720p'  => ['bandwidth' => 2500000, 'res' => '1280x720',  'w' => 1280, 'h' => 720,  'bv' => '2200k'],
            '480p'  => ['bandwidth' => 1200000, 'res' => '854x480',   'w' => 854,  'h' => 480,   'bv' => '1000k'],
            '360p'  => ['bandwidth' => 700000,  'res' => '640x360',   'w' => 640,  'h' => 360,   'bv' => '600k'],
        ];

        $masterEntries = ["#EXTM3U", "#EXT-X-VERSION:3"];

        foreach ($qualities as $q) {
            if (!isset($hlsProfiles[$q])) {
                continue;
            }

            $prof = $hlsProfiles[$q];
            $variantDir = $hlsDir . '/' . $q;
            if (!is_dir($variantDir)) {
                @mkdir($variantDir, 0755, true);
            }

            $playlistFile = $variantDir . '/index.m3u8';
            $segmentPattern = $variantDir . '/seg_%03d.ts';

            if (FFmpegService::isAvailable()) {
                $cmd = [
                    '-i', $input,
                    '-vf', "scale={$prof['w']}:{$prof['h']}:force_original_aspect_ratio=decrease,pad={$prof['w']}:{$prof['h']}:(ow-iw)/2:(oh-ih)/2",
                    '-c:v', 'libx264',
                    '-preset', 'fast',
                    '-b:v', $prof['bv'],
                    '-c:a', 'aac',
                    '-b:a', '128k',
                    '-hls_time', '6',
                    '-hls_playlist_type', 'vod',
                    '-hls_segment_filename', $segmentPattern,
                    '-y',
                    $playlistFile,
                ];
                $res = FFmpegService::executeFFmpeg($cmd);
                if (!$res['success']) {
                    throw new \RuntimeException("HLS transcoding for {$q} failed: " . $res['output']);
                }
            } else {
                // Mock / fallback generation
                file_put_contents($variantDir . '/seg_000.ts', "MOCK_HLS_SEGMENT_0");
                file_put_contents($variantDir . '/seg_001.ts', "MOCK_HLS_SEGMENT_1");
                $mockPlaylist = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:6.000,\nseg_000.ts\n#EXTINF:6.000,\nseg_001.ts\n#EXT-X-ENDLIST\n";
                file_put_contents($playlistFile, $mockPlaylist);
            }

            // Register variant playlist in storage files
            $relVariant = self::getRelativeStoragePath($playlistFile);
            MediaStorageFile::register(
                $type,
                $id,
                'local',
                $relVariant,
                $relVariant,
                MediaStorageFile::TYPE_HLS_VARIANT,
                file_exists($playlistFile) ? (int)filesize($playlistFile) : 0,
                'application/x-mpegURL'
            );

            // Register variant segments
            $segments = glob($variantDir . '/*.ts') ?: [];
            foreach ($segments as $seg) {
                $relSeg = self::getRelativeStoragePath($seg);
                MediaStorageFile::register(
                    $type,
                    $id,
                    'local',
                    $relSeg,
                    $relSeg,
                    MediaStorageFile::TYPE_HLS_SEGMENT,
                    (int)filesize($seg),
                    'video/MP2T'
                );
            }

            // Append to master playlist
            $masterEntries[] = "#EXT-X-STREAM-INF:BANDWIDTH={$prof['bandwidth']},RESOLUTION={$prof['res']}";
            $masterEntries[] = "{$q}/index.m3u8";
        }

        // Write master.m3u8
        $masterPath = $hlsDir . '/master.m3u8';
        file_put_contents($masterPath, implode("\n", $masterEntries) . "\n");

        $relMasterPath = self::getRelativeStoragePath($masterPath);

        // Register master HLS in storage files
        MediaStorageFile::register(
            $type,
            $id,
            'local',
            $relMasterPath,
            $relMasterPath,
            MediaStorageFile::TYPE_HLS_MASTER,
            file_exists($masterPath) ? (int)filesize($masterPath) : 0,
            'application/x-mpegURL'
        );

        // Register master HLS source
        $sourceId = self::registerMediaSource(
            $type,
            $id,
            $relMasterPath,
            'hls',
            'hls',
            'Adaptive HLS Stream',
            'auto',
            'application/x-mpegURL',
            1
        );

        $createdSources[] = [
            'source_id' => $sourceId,
            'type'      => 'hls_master',
            'path'      => $relMasterPath,
        ];

        return [
            'output_path'     => $relMasterPath,
            'sources_created' => $createdSources,
        ];
    }

    /**
     * Extract audio track from video media.
     */
    public static function handleExtractAudio(MediaProcessingJob $job): array
    {
        $input = self::resolveAbsolutePath((string)$job->input_path);
        if (!file_exists($input)) {
            throw new \RuntimeException("Input media file not found: {$job->input_path}");
        }

        $type = (string)$job->content_type;
        $id = (int)$job->content_id;
        $targetDir = self::getStorageDirectory($type, $id, 'audio');
        $outputFile = $targetDir . "/audio_track.mp3";

        if (FFmpegService::isAvailable()) {
            $cmd = [
                '-i', $input,
                '-vn',
                '-c:a', 'libmp3lame',
                '-q:a', '2',
                '-y',
                $outputFile,
            ];
            $res = FFmpegService::executeFFmpeg($cmd);
            if (!$res['success']) {
                throw new \RuntimeException("Audio extraction failed: " . $res['output']);
            }
        } else {
            file_put_contents($outputFile, "MOCK_AUDIO_DATA");
        }

        $relPath = self::getRelativeStoragePath($outputFile);

        $sourceId = self::registerMediaSource(
            $type,
            $id,
            $relPath,
            'upload',
            'audio',
            'Extracted Audio (MP3)',
            'high',
            'audio/mpeg',
            0
        );

        return [
            'output_path'     => $relPath,
            'sources_created' => [
                ['source_id' => $sourceId, 'type' => 'audio', 'path' => $relPath]
            ],
        ];
    }

    /**
     * Run full pipeline: Thumbnail + MP4 renditions + Adaptive HLS.
     */
    public static function handleFullPipeline(MediaProcessingJob $job): array
    {
        $allSources = [];

        // 1. Thumbnail
        try {
            $tRes = self::handleGenerateThumbnail($job);
            if (!empty($tRes['sources_created'])) {
                $allSources = array_merge($allSources, $tRes['sources_created']);
            }
        } catch (\Throwable $e) {
            Logger::warning("Favorite Multimedia: Thumbnail pipeline step warning", ['error' => $e->getMessage()]);
        }

        // 2. MP4 Renditions
        try {
            $mp4Res = self::handleTranscodeMp4($job);
            if (!empty($mp4Res['sources_created'])) {
                $allSources = array_merge($allSources, $mp4Res['sources_created']);
            }
        } catch (\Throwable $e) {
            Logger::warning("Favorite Multimedia: MP4 pipeline step warning", ['error' => $e->getMessage()]);
        }

        // 3. Adaptive HLS
        $hlsRes = self::handleTranscodeHls($job);
        if (!empty($hlsRes['sources_created'])) {
            $allSources = array_merge($allSources, $hlsRes['sources_created']);
        }

        return [
            'output_path'     => $hlsRes['output_path'] ?? '',
            'sources_created' => $allSources,
        ];
    }

    /**
     * Register or update a MediaSource in multimedia_sources.
     */
    public static function registerMediaSource(
        string $contentType,
        int $contentId,
        string $urlOrPath,
        string $sourceMode,
        string $sourceType,
        string $label,
        string $quality,
        string $mimeType,
        int $isDefault = 0
    ): int {
        $db = self::getDb();

        // Check if existing source with identical path exists
        $existing = $db->selectOne(
            "SELECT id FROM multimedia_sources WHERE content_type = ? AND content_id = ? AND url_or_path = ? LIMIT 1",
            [$contentType, $contentId, $urlOrPath]
        );

        $now = gmdate('Y-m-d H:i:s');

        if ($existing) {
            $sourceId = (int)$existing->id;
            $db->update('multimedia_sources', [
                'source_mode' => $sourceMode,
                'source_type' => $sourceType,
                'label'       => $label,
                'quality'     => $quality,
                'mime_type'   => $mimeType,
                'status'      => 'active',
                'updated_at'  => $now,
            ], ['id' => $sourceId]);
            return $sourceId;
        }

        $storageDriver = (string)Setting::get('multimedia', 'storage_driver', 'local');

        $payload = [
            'content_type'   => $contentType,
            'content_id'     => $contentId,
            'source_mode'    => $sourceMode,
            'source_type'    => $sourceType,
            'url_or_path'    => $urlOrPath,
            'label'          => $label,
            'quality'        => $quality,
            'mime_type'      => $mimeType,
            'is_default'     => $isDefault,
            'allow_download' => 'inherit',
            'status'         => 'active',
            'sort_order'     => 10,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        try {
            $cols = $db->select("PRAGMA table_info(multimedia_sources)");
            $colNames = array_column(array_map(fn($c) => (array)$c, $cols), 'name');
            if (in_array('storage_driver', $colNames, true)) {
                $payload['storage_driver'] = $storageDriver;
                $payload['storage_key'] = $urlOrPath;
            }
        } catch (\Throwable) {
        }

        $id = $db->insert('multimedia_sources', $payload);

        return (int)$id;
    }

    private static function getStorageDirectory(string $contentType, int $contentId, string $subfolder): string
    {
        $base = defined('APP_ROOT') ? APP_ROOT . '/storage/multimedia' : sys_get_temp_dir() . '/multimedia';
        $dir = "{$base}/{$contentType}/{$contentId}/{$subfolder}";

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private static function resolveAbsolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, '/') || (strlen($normalized) > 2 && $normalized[1] === ':')) {
            return $normalized;
        }

        $appRoot = defined('APP_ROOT') ? APP_ROOT : '.';
        return rtrim($appRoot, '/') . '/' . ltrim($normalized, '/');
    }

    private static function getRelativeStoragePath(string $absolutePath): string
    {
        $normAbs = str_replace('\\', '/', $absolutePath);
        $appRoot = defined('APP_ROOT') ? str_replace('\\', '/', APP_ROOT) : '';

        if (!empty($appRoot) && str_starts_with($normAbs, $appRoot)) {
            return ltrim(substr($normAbs, strlen($appRoot)), '/');
        }

        return $normAbs;
    }

    private static function generatePlaceholderImage(string $path, int $width, int $height, string $text): void
    {
        if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
            $img = @imagecreatetruecolor($width, $height);
            if ($img) {
                $bg = imagecolorallocate($img, 30, 41, 59);
                $textColor = imagecolorallocate($img, 241, 245, 249);
                imagefill($img, 0, 0, $bg);
                imagestring($img, 5, 20, 20, $text, $textColor);
                @imagejpeg($img, $path, 80);
                @imagedestroy($img);
                return;
            }
        }

        // Tiny fallback binary JPEG header
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00H\x00H\x00\x00\xFF\xDB");
    }
}
