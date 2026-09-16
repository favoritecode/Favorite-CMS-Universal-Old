<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

/**
 * Manages theme assets, URLs, and enforces strict distribution target paths.
 *
 * CRITICAL RULE:
 * Theme distribution assets MUST strictly reside under:
 *   Favorite-CMS-Assets/theme-assets/favorite-multimedia/release/
 * NEVER under plugin-assets/!
 */
final class ThemeAssetManager
{
    public const THEME_DIST_RELATIVE = 'theme-assets/favorite-multimedia/release';
    public const PLUGIN_DIST_RELATIVE = 'plugin-assets/favorite-multimedia/release';

    private string $appRoot;

    public function __construct(?string $appRoot = null)
    {
        $this->appRoot = $appRoot ?? (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4));
    }

    public function getPluginAssetPath(string $relativePath = ''): string
    {
        $base = $this->appRoot . '/plugins/favorite-multimedia/assets';
        return $relativePath !== '' ? $base . '/' . ltrim($relativePath, '/\\') : $base;
    }

    public function getPluginAssetUrl(string $relativePath = ''): string
    {
        $base = '/plugins/favorite-multimedia/assets';
        return $relativePath !== '' ? $base . '/' . ltrim($relativePath, '/') : $base;
    }

    public function getThemeReleaseDistPath(?string $assetsRepoRoot = null): string
    {
        $root = $assetsRepoRoot ?? dirname($this->appRoot) . '/Favorite-CMS-Assets';
        return rtrim($root, '/\\') . '/' . self::THEME_DIST_RELATIVE;
    }

    public function getPluginReleaseDistPath(?string $assetsRepoRoot = null): string
    {
        $root = $assetsRepoRoot ?? dirname($this->appRoot) . '/Favorite-CMS-Assets';
        return rtrim($root, '/\\') . '/' . self::PLUGIN_DIST_RELATIVE;
    }

    /**
     * Strictly verifies that theme assets are NEVER routed to plugin-assets.
     */
    public function validateDistributionPath(string $path, string $type = 'theme'): bool
    {
        $normalized = str_replace('\\', '/', $path);

        if ($type === 'theme') {
            // Must contain theme-assets/favorite-multimedia/release and NOT plugin-assets
            if (str_contains($normalized, 'plugin-assets/favorite-multimedia')) {
                return false;
            }
            return str_contains($normalized, self::THEME_DIST_RELATIVE);
        }

        if ($type === 'plugin') {
            return str_contains($normalized, self::PLUGIN_DIST_RELATIVE);
        }

        return false;
    }
}

