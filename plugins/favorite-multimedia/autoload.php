<?php

declare(strict_types=1);

/**
 * Favorite Multimedia PSR-4 Autoloader
 *
 * Automatically maps FavoriteCMS\Multimedia\ namespace to plugins/favorite-multimedia/src/
 * ensuring modular, zero-configuration loading on standalone hosts.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'FavoriteCMS\\Multimedia\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    // Support candidate class naming for Core PluginManager
    if ($class === 'FavoriteCMS\\Plugins\\FavoriteMultimediaPlugin') {
        $file = __DIR__ . '/src/FavoriteMultimediaPlugin.php';
        if (file_exists($file)) {
            require_once $file;
            if (class_exists('FavoriteCMS\\Multimedia\\FavoriteMultimediaPlugin') && !class_exists('FavoriteCMS\\Plugins\\FavoriteMultimediaPlugin', false)) {
                class_alias('FavoriteCMS\\Multimedia\\FavoriteMultimediaPlugin', 'FavoriteCMS\\Plugins\\FavoriteMultimediaPlugin');
            }
        }
    }
});
