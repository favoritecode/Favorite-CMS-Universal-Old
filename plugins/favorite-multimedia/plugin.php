<?php
/**
 * Plugin Name: Favorite Multimedia
 * Plugin URI: https://github.com/favoritecode/Favorite-CMS-Universal
 * Description: A complete multimedia management system for Movies, Web Series, Songs, and Audio Playlists for Favorite CMS.
 * Version: 1.0.7
 * Author: Favorite CMS Team
 */

declare(strict_types=1);

namespace FavoriteCMS\Multimedia;

require_once __DIR__ . '/autoload.php';

// Bootstrap plugin when Favorite CMS boots
if (isset($app) && $app instanceof \FavoriteCMS\Core\Application) {
    FavoriteMultimediaPlugin::bootstrap($app);
} elseif (function_exists('app')) {
    $coreApp = app();
    if ($coreApp instanceof \FavoriteCMS\Core\Application) {
        FavoriteMultimediaPlugin::bootstrap($coreApp);
    }
}

