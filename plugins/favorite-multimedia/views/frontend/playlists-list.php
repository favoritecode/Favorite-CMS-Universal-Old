<?php
/**
 * Favorite Multimedia — Playlists Catalog View
 * 
 * Powered by Theme Tokens & Modular Components
 *
 * @var string $metaTitle
 * @var array $items
 * @var int $page
 * @var int $totalPages
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$componentsDir = __DIR__ . '/components';
$currentNav = 'playlists';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <?php echo $themeManager->renderHeadTokens(); ?>
</head>
<body class="fm-theme-body">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <main class="fm-main-content">
        <div class="fm-page-header">
            <div class="fm-page-title-wrap">
                <h1 class="fm-page-title">🎼 Curated Playlists</h1>
                <p class="fm-page-subtitle">Expertly selected music playlists and continuous audio sets for every mood.</p>
            </div>
        </div>

        <!-- Playlists Grid -->
        <?php if (empty($items)): ?>
            <?php 
            $emptyIcon = '🎼';
            $emptyTitle = 'No Playlists Available';
            $emptyMessage = 'No curated playlists have been published yet.';
            include $componentsDir . '/empty-state.php'; 
            ?>
        <?php else: ?>
            <div class="fm-grid fm-grid-album">
                <?php foreach ($items as $item): ?>
                    <?php include $componentsDir . '/playlist-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>
