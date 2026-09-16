<?php
/**
 * Favorite Multimedia — Dedicated Music Landing Page (/multimedia/music).
 *
 * @var string $metaTitle
 * @var \FavoriteCMS\Models\User|null $user
 * @var array $continueListening
 * @var array $newSongs
 * @var array $popularSongs
 * @var array $featuredAlbums
 * @var array $artists
 * @var array $audioPlaylists
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$user = $user ?? current_user();
$componentsDir = __DIR__ . '/components';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle ?? 'Music Hub — Favorite Multimedia', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <?php echo $themeManager->renderHeadTokens(); ?>
</head>
<body class="fm-theme-body">

    <!-- Global Responsive Header -->
    <?php 
    $currentNav = 'music';
    include $componentsDir . '/header.php'; 
    ?>

    <!-- Music Landing Content -->
    <main class="fm-main-content">
        <div class="fm-container" style="padding-top: 32px;">
            <!-- Page Title Banner -->
            <div style="margin-bottom: 32px;">
                <h1 style="font-family: var(--fm-font-heading); font-size: 2.2rem; font-weight: 800; margin-bottom: 6px;">
                    🎵 Music Hub
                </h1>
                <p style="color: var(--fm-color-text-secondary); font-size: 1.05rem;">
                    Stream your favorite tracks, full albums, artist spotlights, and mood playlists.
                </p>
            </div>

            <!-- Continue Listening (Personalized) -->
            <?php if (!empty($continueListening)): ?>
                <?php
                $section = [
                    'id' => 'music_continue_listening',
                    'type' => 'continue_listening',
                    'title' => 'Continue Listening',
                    'subtitle' => 'Pick up where you left off',
                    'show_view_all' => false,
                    'show_metadata' => true,
                    'show_badges' => false,
                    'show_progress' => true,
                ];
                $items = $continueListening;
                $cardStyle = 'song_row';
                $layout = 'list';
                include $componentsDir . '/media-row.php';
                ?>
            <?php endif; ?>

            <!-- Popular Songs (Top Hits) -->
            <?php if (!empty($popularSongs)): ?>
                <?php
                $section = [
                    'id' => 'music_popular_songs',
                    'type' => 'popular_songs',
                    'title' => 'Top Chart Hits',
                    'subtitle' => 'The most streamed tracks this month',
                    'show_view_all' => true,
                    'show_metadata' => true,
                    'show_badges' => false,
                    'show_progress' => false,
                ];
                $items = $popularSongs;
                $cardStyle = 'song_row';
                $layout = 'list';
                include $componentsDir . '/media-row.php';
                ?>
            <?php endif; ?>

            <!-- Featured Albums Rail -->
            <?php if (!empty($featuredAlbums)): ?>
                <?php
                $section = [
                    'id' => 'music_featured_albums',
                    'type' => 'featured_albums',
                    'title' => 'Featured Albums &amp; EPs',
                    'subtitle' => 'Full-length studio records worth discovering',
                    'show_view_all' => false,
                    'show_metadata' => true,
                    'show_badges' => false,
                    'show_progress' => false,
                ];
                $items = $featuredAlbums;
                $cardStyle = 'album';
                $layout = 'rail';
                include $componentsDir . '/media-row.php';
                ?>
            <?php endif; ?>

            <!-- Artists in the Spotlight Rail -->
            <?php if (!empty($artists)): ?>
                <?php
                $section = [
                    'id' => 'music_artists',
                    'type' => 'artists',
                    'title' => 'Artists in the Spotlight',
                    'subtitle' => 'Iconic voices and breakout creators',
                    'show_view_all' => false,
                    'show_metadata' => false,
                    'show_badges' => false,
                    'show_progress' => false,
                ];
                $items = $artists;
                $cardStyle = 'artist';
                $layout = 'rail';
                include $componentsDir . '/media-row.php';
                ?>
            <?php endif; ?>

            <!-- Audio Playlists Rail -->
            <?php if (!empty($audioPlaylists)): ?>
                <?php
                $section = [
                    'id' => 'music_playlists',
                    'type' => 'audio_playlists',
                    'title' => 'Curated Vibe Playlists',
                    'subtitle' => 'Handcrafted mixes for work, focus, and chill',
                    'show_view_all' => false,
                    'show_metadata' => true,
                    'show_badges' => false,
                    'show_progress' => false,
                ];
                $items = $audioPlaylists;
                $cardStyle = 'playlist';
                $layout = 'rail';
                include $componentsDir . '/media-row.php';
                ?>
            <?php endif; ?>

            <!-- New Songs -->
            <?php if (!empty($newSongs)): ?>
                <?php
                $section = [
                    'id' => 'music_new_songs',
                    'type' => 'new_songs',
                    'title' => 'New Single Releases',
                    'subtitle' => 'Freshly dropped tracks',
                    'show_view_all' => false,
                    'show_metadata' => true,
                    'show_badges' => false,
                    'show_progress' => false,
                ];
                $items = $newSongs;
                $cardStyle = 'song_row';
                $layout = 'list';
                include $componentsDir . '/media-row.php';
                ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>

