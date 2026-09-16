<?php
/**
 * Favorite Multimedia — Songs Catalog View
 * 
 * Powered by Theme Tokens & Modular Components
 *
 * @var string $metaTitle
 * @var array $items
 * @var string|null $searchQuery
 * @var int $page
 * @var int $totalPages
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$componentsDir = __DIR__ . '/components';
$currentNav = 'music';
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
                <h1 class="fm-page-title">🎵 Music &amp; Songs</h1>
                <p class="fm-page-subtitle">Stream high-fidelity tracks, discover chart-toppers, and explore independent artists.</p>
            </div>

            <!-- Search Bar -->
            <form method="GET" action="/songs" class="fm-filter-bar">
                <input type="text" name="q" class="fm-filter-input" value="<?php echo htmlspecialchars($searchQuery ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search songs, artists, lyrics...">
                <button type="submit" class="fm-btn fm-btn-primary fm-filter-btn">Search</button>
            </form>
        </div>

        <!-- Songs List -->
        <?php if (empty($items)): ?>
            <?php 
            $emptyIcon = '🎵';
            $emptyTitle = 'No Songs Found';
            $emptyMessage = 'No tracks matched your search query.';
            $emptyActionUrl = '/songs';
            $emptyActionLabel = 'View All Songs';
            include $componentsDir . '/empty-state.php'; 
            ?>
        <?php else: ?>
            <div class="fm-song-list">
                <?php 
                $trackNum = 1;
                foreach ($items as $item): 
                    $index = $trackNum++;
                    include $componentsDir . '/song-row.php';
                endforeach; 
                ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="fm-pagination" aria-label="Songs pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/songs?p=<?php echo $i; ?><?php echo $searchQuery ? '&q=' . urlencode($searchQuery) : ''; ?>" 
                           class="fm-page-link <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>
