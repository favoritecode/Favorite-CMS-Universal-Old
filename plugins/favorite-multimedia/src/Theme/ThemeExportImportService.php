<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageConfig;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageManager;

/**
 * Safe Export and Import Service for Theme Studio configurations.
 *
 * Backs up and migrates visual ThemeConfig and HomepageConfig.
 * Strictly prevents leaking or importing user accounts, credentials,
 * protected stream tokens, or external payment secrets.
 */
class ThemeExportImportService
{
    public const EXPORT_FORMAT = 'favorite_multimedia_theme_export';
    public const CURRENT_VERSION = '1.0.0';
    public const BACKUP_SETTING_KEY = 'theme_config_backup';
    public const HOMEPAGE_BACKUP_SETTING_KEY = 'homepage_config_backup';

    /**
     * Export active Theme and Homepage configuration into a safe versioned array.
     */
    public function export(): array
    {
        $themeManager = ThemeManager::getInstance();
        $homepageManager = HomepageManager::getInstance();

        return [
            'format'          => self::EXPORT_FORMAT,
            'schema_version'  => self::CURRENT_VERSION,
            'exported_at'     => date('c'),
            'plugin_slug'     => 'favorite-multimedia',
            'plugin_version'  => '1.0.6',
            'theme_config'    => $themeManager->getActiveConfig()->toArray(),
            'homepage_config' => $homepageManager->getActiveConfig()->toArray(),
        ];
    }

    /**
     * Export as formatted JSON string.
     */
    public function exportJson(): string
    {
        return (string)json_encode($this->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Import and validate configuration array.
     *
     * Automatically backs up existing configuration before applying.
     *
     * @return array{success: bool, message: string, errors?: array<int, string>}
     */
    public function import(array $payload): array
    {
        // 1. Format and version validation
        if (($payload['format'] ?? '') !== self::EXPORT_FORMAT) {
            return [
                'success' => false,
                'message' => 'Invalid configuration format. Expected ' . self::EXPORT_FORMAT . '.',
                'errors'  => ['Format mismatch'],
            ];
        }

        $version = (string)($payload['schema_version'] ?? '');
        if ($version !== self::CURRENT_VERSION) {
            return [
                'success' => false,
                'message' => "Unsupported schema version: '{$version}'. Expected " . self::CURRENT_VERSION . '.',
                'errors'  => ["Version '{$version}' incompatible with " . self::CURRENT_VERSION],
            ];
        }

        // 2. Validate theme config presence and structure
        $rawTheme = $payload['theme_config'] ?? null;
        if (!is_array($rawTheme)) {
            return [
                'success' => false,
                'message' => 'Theme configuration missing or invalid in import file.',
                'errors'  => ['Missing theme_config block'],
            ];
        }

        // 3. Instantiate, sanitize, and validate ThemeConfig
        $themeConfig = new ThemeConfig($rawTheme);
        $themeConfig = $themeConfig->sanitize();
        $themeErrors = $themeConfig->validate();
        if (!empty($themeErrors)) {
            return [
                'success' => false,
                'message' => 'Theme configuration failed validation: ' . implode(', ', $themeErrors),
                'errors'  => $themeErrors,
            ];
        }

        // 4. Validate HomepageConfig if present
        $homepageConfig = null;
        if (isset($payload['homepage_config'])) {
            if (!is_array($payload['homepage_config'])) {
                return [
                    'success' => false,
                    'message' => 'Homepage configuration must be an array.',
                    'errors'  => ['Invalid homepage_config structure'],
                ];
            }
            $hpSections = $payload['homepage_config']['sections'] ?? $payload['homepage_config'];
            $hp = new HomepageConfig(is_array($hpSections) ? $hpSections : []);
            $hp = $hp->sanitize();
            $hpErrors = $hp->validate();
            if (!empty($hpErrors)) {
                return [
                    'success' => false,
                    'message' => 'Homepage configuration failed validation: ' . implode(', ', $hpErrors),
                    'errors'  => $hpErrors,
                ];
            }
            $homepageConfig = $hp;
        }

        // 5. Create automatic backup of current configurations
        $this->createBackup();

        // 6. Save sanitized configurations
        $themeSaved = ThemeManager::getInstance()->saveConfig($themeConfig);
        if (!$themeSaved) {
            return [
                'success' => false,
                'message' => 'Failed to save imported theme configuration to database.',
                'errors'  => ['Database write error on theme_config'],
            ];
        }

        if ($homepageConfig !== null) {
            HomepageManager::getInstance()->saveConfig($homepageConfig);
        }

        return [
            'success' => true,
            'message' => 'Theme and Homepage configurations imported successfully.',
        ];
    }

    /**
     * Backup current active configs into setting store.
     */
    public function createBackup(): bool
    {
        try {
            $backupData = [
                'timestamp'       => date('c'),
                'theme_config'    => ThemeManager::getInstance()->getActiveConfig()->toArray(),
                'homepage_config' => HomepageManager::getInstance()->getActiveConfig()->toArray(),
            ];
            Setting::set(ThemeManager::SETTING_GROUP, self::BACKUP_SETTING_KEY, $backupData, 'json');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Restore configuration from previous backup.
     */
    public function restoreBackup(): bool
    {
        try {
            $raw = Setting::get(ThemeManager::SETTING_GROUP, self::BACKUP_SETTING_KEY);
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            if (!is_array($raw) || !isset($raw['theme_config'])) {
                return false;
            }

            $themeConfig = new ThemeConfig($raw['theme_config']);
            ThemeManager::getInstance()->saveConfig($themeConfig);

            if (isset($raw['homepage_config']) && is_array($raw['homepage_config'])) {
                $hpConfig = new HomepageConfig($raw['homepage_config']['sections'] ?? $raw['homepage_config']);
                HomepageManager::getInstance()->saveConfig($hpConfig);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
