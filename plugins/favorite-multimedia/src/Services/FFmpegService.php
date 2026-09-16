<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Logger;
use FavoriteCMS\Models\Setting;

class FFmpegService
{
    private static ?bool $cachedAvailable = null;
    private static ?bool $cachedProbeAvailable = null;
    private static ?string $cachedVersion = null;
    private static ?array $cachedCapabilities = null;

    /**
     * Reset cached capability detections (useful for testing).
     */
    public static function resetCache(): void
    {
        self::$cachedAvailable = null;
        self::$cachedProbeAvailable = null;
        self::$cachedVersion = null;
        self::$cachedCapabilities = null;
    }

    /**
     * Test mock override for simulating FFmpeg availability and execution in test suites.
     */
    public static function setMockEnvironment(?array $mockConfig): void
    {
        self::resetCache();
        if ($mockConfig === null) {
            unset($GLOBALS['_test_ffmpeg_mock']);
        } else {
            $GLOBALS['_test_ffmpeg_mock'] = $mockConfig;
        }
    }

    /**
     * Resolve path to ffmpeg binary.
     */
    public static function getFFmpegPath(): ?string
    {
        if (isset($GLOBALS['_test_ffmpeg_mock']['ffmpeg_path'])) {
            return $GLOBALS['_test_ffmpeg_mock']['ffmpeg_path'];
        }

        try {
            $setting = Setting::get('multimedia', 'ffmpeg_path', '');
            if (!empty($setting) && is_string($setting)) {
                return trim($setting);
            }
        } catch (\Throwable) {
        }

        $env = getenv('FFMPEG_PATH');
        if ($env && is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return self::findBinaryInPath('ffmpeg');
    }

    /**
     * Resolve path to ffprobe binary.
     */
    public static function getFFprobePath(): ?string
    {
        if (isset($GLOBALS['_test_ffmpeg_mock']['ffprobe_path'])) {
            return $GLOBALS['_test_ffmpeg_mock']['ffprobe_path'];
        }

        try {
            $setting = Setting::get('multimedia', 'ffprobe_path', '');
            if (!empty($setting) && is_string($setting)) {
                return trim($setting);
            }
        } catch (\Throwable) {
        }

        $env = getenv('FFPROBE_PATH');
        if ($env && is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return self::findBinaryInPath('ffprobe');
    }

    /**
     * Check if FFmpeg is installed and executable.
     */
    public static function isAvailable(): bool
    {
        if (isset($GLOBALS['_test_ffmpeg_mock']['available'])) {
            return (bool)$GLOBALS['_test_ffmpeg_mock']['available'];
        }

        if (self::$cachedAvailable !== null) {
            return self::$cachedAvailable;
        }

        try {
            if (!function_exists('exec')) {
                self::$cachedAvailable = false;
                return false;
            }

            $binary = self::getFFmpegPath();
            if (!$binary) {
                self::$cachedAvailable = false;
                return false;
            }

            $res = self::runRawCommand([$binary, '-version']);
            self::$cachedAvailable = ($res['exit_code'] === 0);
            return self::$cachedAvailable;
        } catch (\Throwable) {
            self::$cachedAvailable = false;
            return false;
        }
    }

    /**
     * Check if FFprobe is installed and executable.
     */
    public static function isProbeAvailable(): bool
    {
        if (isset($GLOBALS['_test_ffmpeg_mock']['probe_available'])) {
            return (bool)$GLOBALS['_test_ffmpeg_mock']['probe_available'];
        }

        if (self::$cachedProbeAvailable !== null) {
            return self::$cachedProbeAvailable;
        }

        try {
            if (!function_exists('exec')) {
                self::$cachedProbeAvailable = false;
                return false;
            }

            $binary = self::getFFprobePath();
            if (!$binary) {
                self::$cachedProbeAvailable = false;
                return false;
            }

            $res = self::runRawCommand([$binary, '-version']);
            self::$cachedProbeAvailable = ($res['exit_code'] === 0);
            return self::$cachedProbeAvailable;
        } catch (\Throwable) {
            self::$cachedProbeAvailable = false;
            return false;
        }
    }

    /**
     * Get detected FFmpeg version string.
     */
    public static function getVersion(): ?string
    {
        if (isset($GLOBALS['_test_ffmpeg_mock']['version'])) {
            return $GLOBALS['_test_ffmpeg_mock']['version'];
        }

        if (self::$cachedVersion !== null) {
            return self::$cachedVersion;
        }

        if (!self::isAvailable()) {
            return null;
        }

        $binary = self::getFFmpegPath();
        $res = self::runRawCommand([$binary, '-version']);
        if ($res['exit_code'] === 0 && preg_match('/ffmpeg version ([^\s]+)/i', $res['output'], $matches)) {
            self::$cachedVersion = $matches[1];
            return self::$cachedVersion;
        }

        return 'Unknown';
    }

    /**
     * Get full diagnostics and capabilities.
     */
    public static function getCapabilities(): array
    {
        if (isset($GLOBALS['_test_ffmpeg_mock']['capabilities'])) {
            return (array)$GLOBALS['_test_ffmpeg_mock']['capabilities'];
        }

        if (self::$cachedCapabilities !== null) {
            return self::$cachedCapabilities;
        }

        $available = self::isAvailable();
        $probeAvailable = self::isProbeAvailable();
        $version = $available ? self::getVersion() : null;

        self::$cachedCapabilities = [
            'available'       => $available,
            'probe_available' => $probeAvailable,
            'version'         => $version,
            'ffmpeg_path'     => self::getFFmpegPath() ?? 'Not found',
            'ffprobe_path'    => self::getFFprobePath() ?? 'Not found',
            'hls_supported'   => $available,
            'mp4_supported'   => $available,
            'webp_supported'  => $available,
        ];

        return self::$cachedCapabilities;
    }

    /**
     * Execute arbitrary FFmpeg CLI command with arguments.
     */
    public static function executeFFmpeg(array $args, int $timeoutSeconds = 600): array
    {
        if (isset($GLOBALS['_test_ffmpeg_mock']['execute_callback'])) {
            $cb = $GLOBALS['_test_ffmpeg_mock']['execute_callback'];
            return $cb($args, $timeoutSeconds);
        }

        $binary = self::getFFmpegPath();
        if (!$binary || !self::isAvailable()) {
            return [
                'success'   => false,
                'exit_code' => 127,
                'output'    => 'FFmpeg binary not found or unavailable.',
            ];
        }

        array_unshift($args, $binary);
        return self::runRawCommand($args, $timeoutSeconds);
    }

    /**
     * Execute FFprobe command with arguments.
     */
    public static function executeFFprobe(array $args, int $timeoutSeconds = 60): array
    {
        if (isset($GLOBALS['_test_ffmpeg_mock']['probe_callback'])) {
            $cb = $GLOBALS['_test_ffmpeg_mock']['probe_callback'];
            return $cb($args, $timeoutSeconds);
        }

        $binary = self::getFFprobePath();
        if (!$binary || !self::isProbeAvailable()) {
            return [
                'success'   => false,
                'exit_code' => 127,
                'output'    => 'FFprobe binary not found or unavailable.',
            ];
        }

        array_unshift($args, $binary);
        return self::runRawCommand($args, $timeoutSeconds);
    }

    private static function findBinaryInPath(string $binary): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $cmd = $isWindows ? "where {$binary} 2>NUL" : "which {$binary} 2>/dev/null";

        $output = [];
        $returnCode = 1;
        try {
            @exec($cmd, $output, $returnCode);
        } catch (\Throwable) {
            $returnCode = 1;
        }

        if ($returnCode === 0 && !empty($output[0])) {
            return trim($output[0]);
        }

        // Common fallback directories on Windows / Unix
        $candidates = $isWindows
            ? [
                "C:\\ffmpeg\\bin\\{$binary}.exe",
                "C:\\Program Files\\ffmpeg\\bin\\{$binary}.exe",
                "C:\\tools\\ffmpeg\\bin\\{$binary}.exe",
            ]
            : [
                "/usr/bin/{$binary}",
                "/usr/local/bin/{$binary}",
                "/opt/homebrew/bin/{$binary}",
            ];

        foreach ($candidates as $cand) {
            if (file_exists($cand) && is_executable($cand)) {
                return $cand;
            }
        }

        return null;
    }

    private static function runRawCommand(array $cmdArray, int $timeoutSeconds = 60): array
    {
        if (!function_exists('exec')) {
            return [
                'success'   => false,
                'exit_code' => 127,
                'output'    => 'exec() is disabled or unavailable on this system.',
            ];
        }

        $escaped = array_map(fn($part) => escapeshellarg((string)$part), $cmdArray);
        $commandLine = implode(' ', $escaped) . ' 2>&1';

        $output = [];
        $exitCode = 1;

        try {
            @exec($commandLine, $output, $exitCode);
        } catch (\Throwable $e) {
            return [
                'success'   => false,
                'exit_code' => 1,
                'output'    => 'Execution error: ' . $e->getMessage(),
            ];
        }

        $outStr = implode("\n", $output);
        return [
            'success'   => ($exitCode === 0),
            'exit_code' => $exitCode,
            'output'    => $outStr,
        ];
    }
}
