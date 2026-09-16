<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Subtitle;

class MediaLanguageService
{
    /**
     * Map of canonical language codes to human-friendly native and international labels.
     */
    protected static array $languages = [
        'bn'    => ['name' => 'Bangla', 'native' => 'বাংলা', 'dir' => 'ltr'],
        'en'    => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr'],
        'en-us' => ['name' => 'English (US)', 'native' => 'English (US)', 'dir' => 'ltr'],
        'en-gb' => ['name' => 'English (UK)', 'native' => 'English (UK)', 'dir' => 'ltr'],
        'ar'    => ['name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl'],
        'hi'    => ['name' => 'Hindi', 'native' => 'हिन्दी', 'dir' => 'ltr'],
        'es'    => ['name' => 'Spanish', 'native' => 'Español', 'dir' => 'ltr'],
        'fr'    => ['name' => 'French', 'native' => 'Français', 'dir' => 'ltr'],
        'de'    => ['name' => 'German', 'native' => 'Deutsch', 'dir' => 'ltr'],
        'ja'    => ['name' => 'Japanese', 'native' => '日本語', 'dir' => 'ltr'],
        'ko'    => ['name' => 'Korean', 'native' => '한국어', 'dir' => 'ltr'],
        'zh'    => ['name' => 'Chinese', 'native' => '中文', 'dir' => 'ltr'],
        'pt'    => ['name' => 'Portuguese', 'native' => 'Português', 'dir' => 'ltr'],
        'ru'    => ['name' => 'Russian', 'native' => 'Русский', 'dir' => 'ltr'],
        'it'    => ['name' => 'Italian', 'native' => 'Italiano', 'dir' => 'ltr'],
        'tr'    => ['name' => 'Turkish', 'native' => 'Türkçe', 'dir' => 'ltr'],
        'ur'    => ['name' => 'Urdu', 'native' => 'اردو', 'dir' => 'rtl'],
    ];

    /**
     * Get array of all supported canonical languages.
     *
     * @return array<string, array{name: string, native: string, dir: string}>
     */
    public static function getSupportedLanguages(): array
    {
        return self::$languages;
    }

    /**
     * Validate BCP 47 language code.
     */
    public static function isValidLanguageCode(string $code): bool
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 32) {
            return false;
        }

        // BCP 47 format: 2-3 letter primary subtag optionally followed by subtags
        return (bool)preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $code);
    }

    public static function isValidCode(string $code): bool
    {
        return self::isValidLanguageCode($code);
    }

    public static function getLanguageNativeLabel(string $code): string
    {
        return self::getLanguageLabel($code, true);
    }

    /**
     * Normalize language code to lowercase standard tag.
     */
    public static function normalizeLanguageCode(string $code): string
    {
        return strtolower(trim($code));
    }

    /**
     * Get human-friendly display label for a language code.
     */
    public static function getLanguageLabel(string $code, bool $preferNative = true): string
    {
        $norm = self::normalizeLanguageCode($code);
        if (isset(self::$languages[$norm])) {
            return $preferNative ? self::$languages[$norm]['native'] : self::$languages[$norm]['name'];
        }

        // Check primary tag if regional
        $primary = explode('-', $norm)[0];
        if (isset(self::$languages[$primary])) {
            return $preferNative ? self::$languages[$primary]['native'] : self::$languages[$primary]['name'];
        }

        return strtoupper($norm);
    }

    /**
     * Check if a language code is written Right-to-Left (RTL).
     */
    public static function isRtl(string $code): bool
    {
        $norm = self::normalizeLanguageCode($code);
        $primary = explode('-', $norm)[0];
        return in_array($primary, ['ar', 'ur', 'he', 'fa'], true);
    }

    /**
     * Get default system media language.
     */
    public static function getDefaultLanguage(): string
    {
        try {
            if (class_exists(\FavoriteCMS\Models\Setting::class)) {
                return (string)\FavoriteCMS\Models\Setting::get('multimedia.default_language', 'en');
            }
        } catch (\Throwable) {
        }
        return 'en';
    }

    /**
     * Select audio track based on deterministic priority:
     * 1. Explicit current session choice
     * 2. Authenticated user saved preference
     * 3. Content default track
     * 4. First available valid track
     *
     * @param MediaSource[] $tracks
     */
    public static function selectAudioTrack(
        array $tracks,
        ?string $userPreferredLang = null,
        ?string $sessionLang = null
    ): ?MediaSource {
        if (empty($tracks)) {
            return null;
        }

        // 1. Explicit session choice
        if ($sessionLang !== null && $sessionLang !== '') {
            $norm = self::normalizeLanguageCode($sessionLang);
            foreach ($tracks as $t) {
                if (self::normalizeLanguageCode((string)($t->language_code ?? '')) === $norm) {
                    return $t;
                }
            }
        }

        // 2. User saved preference
        if ($userPreferredLang !== null && $userPreferredLang !== '') {
            $norm = self::normalizeLanguageCode($userPreferredLang);
            foreach ($tracks as $t) {
                if (self::normalizeLanguageCode((string)($t->language_code ?? '')) === $norm) {
                    return $t;
                }
            }
        }

        // 3. Content default track
        foreach ($tracks as $t) {
            if (!empty($t->is_default)) {
                return $t;
            }
        }

        // 4. First available
        return $tracks[0];
    }

    /**
     * Select subtitle track based on deterministic priority:
     * 1. If forced subtitle exists and user subtitles are disabled, return forced subtitle
     * 2. Explicit session choice
     * 3. Authenticated user saved preference
     * 4. Content default subtitle
     * 5. null if subtitles disabled by user
     *
     * @param Subtitle[] $subtitles
     */
    public static function selectSubtitleTrack(
        array $subtitles,
        ?string $userPreferredLang = null,
        ?string $sessionLang = null,
        bool $userSubtitleEnabled = true
    ): ?Subtitle {
        if (empty($subtitles)) {
            return null;
        }

        // Check if forced subtitle exists (foreign-language dialogue)
        $forced = null;
        foreach ($subtitles as $s) {
            if (!empty($s->is_forced)) {
                $forced = $s;
                break;
            }
        }

        // If user explicitly disabled subtitles, only return forced (if any)
        if (!$userSubtitleEnabled && ($sessionLang === null || $sessionLang === 'off')) {
            return $forced;
        }

        if ($sessionLang === 'off') {
            return $forced;
        }

        // 1. Explicit session selection
        if ($sessionLang !== null && $sessionLang !== '') {
            $norm = self::normalizeLanguageCode($sessionLang);
            foreach ($subtitles as $s) {
                if (self::normalizeLanguageCode($s->getLanguageCode()) === $norm) {
                    return $s;
                }
            }
        }

        // 2. User saved preference
        if ($userPreferredLang !== null && $userPreferredLang !== '') {
            $norm = self::normalizeLanguageCode($userPreferredLang);
            foreach ($subtitles as $s) {
                if (self::normalizeLanguageCode($s->getLanguageCode()) === $norm) {
                    return $s;
                }
            }
        }

        // 3. Content default subtitle
        foreach ($subtitles as $s) {
            if (!empty($s->is_default)) {
                return $s;
            }
        }

        return $forced ?: ($subtitles[0] ?? null);
    }

    /**
     * Convert SubRip (.srt) subtitle format into clean, standard WebVTT format.
     * Sanitizes cue text against XSS while preserving legitimate timing and cues.
     */
    public static function convertSrtToVtt(string $srtContent): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $srtContent);

        // Replace comma timestamps (00:01:20,000) with period timestamps (00:01:20.000)
        $content = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $content);

        // Sanitize cue text against malicious scripts/HTML
        $lines = explode("\n", (string)$content);
        $cleanLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Don't strip timing lines (e.g. 00:00:01.000 --> 00:00:04.000) or sequence numbers
            if (str_contains($line, '-->') || is_numeric($trimmed)) {
                $cleanLines[] = $line;
            } else {
                // Allow only safe inline formatting tags (<b>, <i>, <u>, <c>)
                $cleanText = strip_tags($line, '<b><i><u><c><v>');
                $cleanLines[] = $cleanText;
            }
        }

        $vtt = implode("\n", $cleanLines);

        if (!str_starts_with(trim($vtt), 'WEBVTT')) {
            $vtt = "WEBVTT\n\n" . ltrim($vtt);
        }

        return $vtt;
    }

    /**
     * Sanitize WebVTT content against malicious scripts and unauthorized tags.
     */
    public static function sanitizeVttContent(string $vttContent): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $vttContent));
        $cleanLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'WEBVTT') || str_contains($line, '-->') || is_numeric($trimmed) || str_starts_with($trimmed, 'NOTE')) {
                $cleanLines[] = $line;
            } else {
                $clean = strip_tags($line, '<b><i><u><c><v>');
                $clean = preg_replace('/javascript\s*:/i', '', (string)$clean);
                $cleanLines[] = $clean;
            }
        }

        return implode("\n", $cleanLines);
    }

    /**
     * Select best matching track from an array of track dictionaries based on priority.
     */
    public static function selectBestTrack(array $tracks, ?string $sessionLang = null, ?string $userPreferredLang = null): ?array
    {
        if (empty($tracks)) {
            return null;
        }

        // 1. Explicit session selection
        if ($sessionLang !== null && $sessionLang !== '' && $sessionLang !== 'off') {
            $norm = self::normalizeLanguageCode($sessionLang);
            foreach ($tracks as $t) {
                $lang = self::normalizeLanguageCode((string)($t['language_code'] ?? $t['language'] ?? ''));
                if ($lang === $norm) {
                    return $t;
                }
            }
        }

        // 2. User saved preference
        if ($userPreferredLang !== null && $userPreferredLang !== '') {
            $norm = self::normalizeLanguageCode($userPreferredLang);
            foreach ($tracks as $t) {
                $lang = self::normalizeLanguageCode((string)($t['language_code'] ?? $t['language'] ?? ''));
                if ($lang === $norm) {
                    return $t;
                }
            }
        }

        // 3. Content default track
        foreach ($tracks as $t) {
            if (!empty($t['is_default'])) {
                return $t;
            }
        }

        // 4. First track fallback
        return $tracks[0] ?? null;
    }
}
