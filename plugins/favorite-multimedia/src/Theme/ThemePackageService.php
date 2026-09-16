<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

/**
 * Manages Theme Manifest definition and compatibility contracts.
 */
class ThemePackageService
{
    public const THEME_NAME = 'Favorite Multimedia Theme';
    public const THEME_SLUG = 'favorite-multimedia-theme';
    public const THEME_VERSION = '1.0.2';
    public const REQUIRES_PLUGIN = 'favorite-multimedia';
    public const MINIMUM_PLUGIN_VERSION = '1.0.6';
    public const THEME_SCHEMA_VERSION = '1.0.0';

    /**
     * Returns canonical theme manifest metadata.
     */
    public static function getManifest(): array
    {
        return [
            'id'                     => self::THEME_SLUG,
            'name'                   => self::THEME_NAME,
            'slug'                   => self::THEME_SLUG,
            'version'                => self::THEME_VERSION,
            'description'            => 'Professional unified Video and Music streaming frontend experience for Favorite CMS.',
            'author'                 => 'Favorite CMS Team',
            'license'                => 'Proprietary / Builtin',
            'requires_plugin'        => self::REQUIRES_PLUGIN,
            'minimum_plugin_version' => self::MINIMUM_PLUGIN_VERSION,
            'theme_schema_version'   => self::THEME_SCHEMA_VERSION,
            'screenshot'             => 'screenshot.png',
            'menu_locations'         => [
                'primary' => 'Primary Header Navigation',
                'footer'  => 'Footer Navigation',
            ],
            'regions'                => [
                [
                    'id'          => 'sidebar-primary',
                    'name'        => 'Primary Sidebar',
                    'description' => 'Sidebar displayed alongside content.',
                ],
                [
                    'id'          => 'footer-1',
                    'name'        => 'Footer Column',
                    'description' => 'Footer area.',
                ],
            ],
            'sections'               => [
                [
                    'id'          => 'hero',
                    'name'        => 'Multimedia Hero',
                    'description' => 'Featured titles banner showcase.',
                    'enabled'     => true,
                ],
                [
                    'id'          => 'continue-watching',
                    'name'        => 'Continue Watching',
                    'description' => 'User in-progress resume shelf.',
                    'enabled'     => true,
                ],
                [
                    'id'          => 'trending',
                    'name'        => 'Trending Titles',
                    'description' => 'Trending movies and series.',
                    'enabled'     => true,
                ],
                [
                    'id'          => 'music-spotlight',
                    'name'        => 'Music Spotlight',
                    'description' => 'Featured albums and songs.',
                    'enabled'     => true,
                ],
            ],
        ];
    }

    /**
     * Validate a theme manifest array against schema requirements.
     *
     * @return array<int, string> Array of error messages, empty if valid.
     */
    public static function validateManifest(array $manifest): array
    {
        $errors = [];
        $requiredFields = ['name', 'slug', 'version', 'requires_plugin', 'minimum_plugin_version', 'theme_schema_version'];

        foreach ($requiredFields as $field) {
            if (empty($manifest[$field])) {
                $errors[] = "Manifest missing required field: '{$field}'";
            }
        }

        if (isset($manifest['slug']) && $manifest['slug'] !== self::THEME_SLUG) {
            $errors[] = "Invalid theme slug: '{$manifest['slug']}'. Expected '" . self::THEME_SLUG . "'";
        }

        return $errors;
    }

    /**
     * Verify compatibility of the theme with the current running plugin environment.
     *
     * @return array{compatible: bool, errors: array<int, string>}
     */
    public static function verifyCompatibility(string $installedPluginVersion, string $pluginSlug = 'favorite-multimedia'): array
    {
        $errors = [];

        if ($pluginSlug !== self::REQUIRES_PLUGIN) {
            $errors[] = "Missing required plugin: '" . self::REQUIRES_PLUGIN . "'. Found: '{$pluginSlug}'";
        }

        if (version_compare($installedPluginVersion, self::MINIMUM_PLUGIN_VERSION, '<')) {
            $errors[] = "Installed plugin version ({$installedPluginVersion}) is older than required minimum (" . self::MINIMUM_PLUGIN_VERSION . ")";
        }

        return [
            'compatible' => empty($errors),
            'errors'     => $errors,
        ];
    }

    /**
     * Build the distribution ZIP package from theme source directory.
     * Guarantees all zip entries are prefixed with self::THEME_SLUG . '/'
     */
    public static function buildZipPackage(string $sourceDir, string $outputZipPath): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($outputZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Failed to open zip archive: {$outputZipPath}");
        }

        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $filePath = str_replace('\\', '/', $file->getRealPath());
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            $zipPath = self::THEME_SLUG . '/' . $relativePath;
            $zip->addFile($filePath, $zipPath);
        }

        return $zip->close();
    }
}
