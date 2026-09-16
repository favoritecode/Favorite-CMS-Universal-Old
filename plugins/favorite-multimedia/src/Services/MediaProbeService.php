<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Logger;

class MediaProbeService
{
    /**
     * Inspect media file and return normalized metadata array.
     */
    public static function probe(string $filePath): array
    {
        if (isset($GLOBALS['_test_probe_mock'][$filePath])) {
            return $GLOBALS['_test_probe_mock'][$filePath];
        }

        if (isset($GLOBALS['_test_probe_mock']['default'])) {
            return $GLOBALS['_test_probe_mock']['default'];
        }

        $realPath = realpath($filePath);
        if (!$realPath || !file_exists($realPath) || is_dir($realPath)) {
            return [
                'success' => false,
                'error'   => 'Media file does not exist or is not readable: ' . $filePath,
            ];
        }

        $fileSize = (int)filesize($realPath);

        // If FFprobe is available, use it for deep inspection
        if (FFmpegService::isProbeAvailable()) {
            $cmd = [
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                $realPath,
            ];

            $res = FFmpegService::executeFFprobe($cmd);
            if ($res['success']) {
                $data = json_decode($res['output'], true);
                if (is_array($data)) {
                    return self::normalizeProbeData($data, $realPath, $fileSize);
                }
            }
        }

        // Fallback inspection without FFprobe
        return self::fallbackInspection($realPath, $fileSize);
    }

    private static function normalizeProbeData(array $data, string $filePath, int $fileSize): array
    {
        $format = $data['format'] ?? [];
        $streams = $data['streams'] ?? [];

        $duration = (float)($format['duration'] ?? 0.0);
        $bitrate = (int)($format['bit_rate'] ?? 0);
        $formatName = (string)($format['format_name'] ?? pathinfo($filePath, PATHINFO_EXTENSION));

        $videoStream = null;
        $audioStream = null;

        foreach ($streams as $s) {
            $codecType = $s['codec_type'] ?? '';
            if ($codecType === 'video' && $videoStream === null) {
                $videoStream = $s;
            } elseif ($codecType === 'audio' && $audioStream === null) {
                $audioStream = $s;
            }
        }

        $width = isset($videoStream['width']) ? (int)$videoStream['width'] : 0;
        $height = isset($videoStream['height']) ? (int)$videoStream['height'] : 0;
        $videoCodec = (string)($videoStream['codec_name'] ?? '');
        $audioCodec = (string)($audioStream['codec_name'] ?? '');
        $audioChannels = isset($audioStream['channels']) ? (int)$audioStream['channels'] : 0;
        $sampleRate = isset($audioStream['sample_rate']) ? (int)$audioStream['sample_rate'] : 0;

        $resolution = self::calculateResolutionLabel($width, $height);

        return [
            'success'           => true,
            'file_path'         => $filePath,
            'file_size'         => $fileSize,
            'format'            => $formatName,
            'duration'          => $duration,
            'duration_formatted'=> self::formatDuration($duration),
            'bitrate'           => $bitrate,
            'width'             => $width,
            'height'            => $height,
            'resolution'        => $resolution,
            'video_codec'       => $videoCodec,
            'audio_codec'       => $audioCodec,
            'audio_channels'    => $audioChannels,
            'audio_sample_rate' => $sampleRate,
            'has_video'         => ($videoStream !== null),
            'has_audio'         => ($audioStream !== null),
        ];
    }

    private static function fallbackInspection(string $filePath, int $fileSize): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $isVideo = in_array($ext, ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'm4v'], true);
        $isAudio = in_array($ext, ['mp3', 'aac', 'wav', 'flac', 'ogg', 'm4a'], true);

        return [
            'success'           => true,
            'file_path'         => $filePath,
            'file_size'         => $fileSize,
            'format'            => $ext,
            'duration'          => 0.0,
            'duration_formatted'=> '00:00',
            'bitrate'           => 0,
            'width'             => $isVideo ? 1920 : 0,
            'height'            => $isVideo ? 1080 : 0,
            'resolution'        => $isVideo ? '1080p' : '',
            'video_codec'       => $isVideo ? 'h264' : '',
            'audio_codec'       => $isAudio ? 'aac' : '',
            'audio_channels'    => $isAudio ? 2 : 0,
            'audio_sample_rate' => $isAudio ? 44100 : 0,
            'has_video'         => $isVideo,
            'has_audio'         => $isAudio,
        ];
    }

    public static function calculateResolutionLabel(int $width, int $height): string
    {
        if ($height >= 2160 || $width >= 3840) {
            return '4K';
        }
        if ($height >= 1440 || $width >= 2560) {
            return '1440p';
        }
        if ($height >= 1080 || $width >= 1920) {
            return '1080p';
        }
        if ($height >= 720 || $width >= 1280) {
            return '720p';
        }
        if ($height >= 480 || $width >= 854) {
            return '480p';
        }
        if ($height >= 360 || $width >= 640) {
            return '360p';
        }
        if ($height > 0) {
            return "{$height}p";
        }
        return '';
    }

    public static function formatDuration(float $seconds): string
    {
        $sec = (int)round($seconds);
        $h = floor($sec / 3600);
        $m = floor(($sec % 3600) / 60);
        $s = $sec % 60;

        if ($h > 0) {
            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%02d:%02d', $m, $s);
    }

    /**
     * Probe all audio streams present in a media container.
     */
    public static function probeAudioTracks(string $filePath): array
    {
        if (isset($GLOBALS['_test_probe_mock']['audio_tracks'])) {
            return $GLOBALS['_test_probe_mock']['audio_tracks'];
        }

        $realPath = realpath($filePath);
        if (!$realPath || !file_exists($realPath) || is_dir($realPath)) {
            return [];
        }

        if (FFmpegService::isProbeAvailable()) {
            $cmd = [
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_streams',
                '-select_streams', 'a',
                $realPath,
            ];

            $res = FFmpegService::executeFFprobe($cmd);
            if ($res['success']) {
                $data = json_decode($res['output'], true);
                if (is_array($data) && !empty($data['streams'])) {
                    $tracks = [];
                    foreach ($data['streams'] as $s) {
                        $tags = $s['tags'] ?? [];
                        $lang = strtolower(trim((string)($tags['language'] ?? 'und')));
                        $title = trim((string)($tags['title'] ?? $tags['handler_name'] ?? ''));
                        $tracks[] = [
                            'stream_index' => (int)($s['index'] ?? 0),
                            'codec'        => (string)($s['codec_name'] ?? 'aac'),
                            'channels'     => (int)($s['channels'] ?? 2),
                            'language'     => $lang !== 'und' ? $lang : 'en',
                            'title'        => $title ?: MediaLanguageService::getLanguageLabel($lang !== 'und' ? $lang : 'en'),
                            'is_default'   => !empty($s['disposition']['default']),
                        ];
                    }
                    return $tracks;
                }
            }
        }

        return [
            [
                'stream_index' => 0,
                'codec'        => 'aac',
                'channels'     => 2,
                'language'     => 'en',
                'title'        => 'English (Default)',
                'is_default'   => true,
            ]
        ];
    }
}
