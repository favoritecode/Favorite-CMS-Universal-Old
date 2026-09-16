<?php
/**
 * Favorite Multimedia — Dynamic Streaming Homepage (Hub).
 *
 * Fully powered by the No-Code Homepage Section Engine.
 *
 * @var string $metaTitle
 * @var \FavoriteCMS\Models\User|null $user
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageManager;
use FavoriteCMS\Multimedia\Theme\Homepage\SectionRenderer;

$themeManager = ThemeManager::getInstance();
$homepageConfig = HomepageManager::getInstance()->getActiveConfig();
$renderer = new SectionRenderer();
$user = $user ?? current_user();
$componentsDir = __DIR__ . '/components';

if (!empty($GLOBALS['fm_canonical_header_rendered'])) {
    ?>
    <div class="fm-main-content">
        <?php echo $renderer->renderAll($homepageConfig, $user); ?>
    </div>
    <?php
    return;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle ?? 'Multimedia Hub', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <?php echo $themeManager->renderHeadTokens(); ?>
</head>
<body class="fm-theme-body">

    <!-- Global Responsive Header -->
    <?php 
    $currentNav = 'home';
    include $componentsDir . '/header.php'; 
    ?>

    <!-- Main Dynamic Homepage Content -->
    <main class="fm-main-content">
        <?php echo $renderer->renderAll($homepageConfig, $user); ?>
    </main>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>
