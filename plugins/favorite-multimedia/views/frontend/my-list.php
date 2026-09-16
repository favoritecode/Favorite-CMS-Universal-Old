<?php
/**
 * Favorite Multimedia — Full My List (Favorites) View
 *
 * @var string $metaTitle
 * @var array $items
 * @var string|null $currentType
 * @var int $page
 * @var int $totalPages
 * @var int $total
 * @var \FavoriteCMS\Models\User $user
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$componentsDir = __DIR__ . '/components';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle ?? 'My List — Favorite Multimedia', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <?php echo $themeManager->renderHeadTokens(); ?>
</head>
<body class="fm-theme-body">

    <!-- Global Responsive Header -->
    <?php 
    $currentNav = 'library';
    include $componentsDir . '/header.php'; 
    ?>

    <!-- Main Content -->
    <main class="fm-main-content">
        <div class="fm-container" style="padding-top: 36px; padding-bottom: 60px;">

            <!-- Header -->
            <div style="margin-bottom: 28px;">
                <a href="/multimedia/library" style="color: var(--fm-color-text-muted); font-size: 0.9rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 8px;">&larr; Back to Library</a>
                <h1 style="font-family: var(--fm-font-heading); font-size: 2.2rem; font-weight: 800; margin-bottom: 6px;">
                    ⭐ My List
                </h1>
                <p style="color: var(--fm-color-text-secondary); font-size: 1.05rem;">
                    Your saved movies, web series, songs, albums, and playlists (<?php echo $total; ?> items).
                </p>
            </div>

            <!-- Content Type Filter Pills -->
            <div style="display: flex; gap: 8px; margin-bottom: 32px; border-bottom: 1px solid var(--fm-color-border, #2a3548); padding-bottom: 12px; overflow-x: auto;">
                <?php
                $types = [
                    ''         => 'All Types',
                    'movie'    => 'Movies',
                    'series'   => 'Series',
                    'song'     => 'Songs',
                    'album'    => 'Albums',
                    'playlist' => 'Playlists',
                ];
                foreach ($types as $k => $label):
                    $isActive = ($currentType === $k || ($currentType === null && $k === ''));
                ?>
                    <a href="/multimedia/my-list<?php echo $k !== '' ? '?type=' . $k : ''; ?>" 
                       class="fm-tab-pill <?php echo $isActive ? 'active' : ''; ?>"
                       style="padding: 8px 18px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; <?php echo $isActive ? 'background: var(--fm-color-primary); color: #fff;' : 'background: var(--fm-color-bg-surface); color: var(--fm-color-text-secondary); border: 1px solid var(--fm-color-border);'; ?>">
                        <?php echo $label; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- List Grid -->
            <?php if (!empty($items)): ?>
                <div class="fm-grid fm-grid-posters" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: var(--fm-layout-card-gap, 20px); margin-bottom: 40px;">
                    <?php foreach ($items as $it): 
                        $item = $it;
                        include $componentsDir . '/poster-card.php';
                    endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div style="display: flex; justify-content: center; gap: 8px; margin-top: 32px;">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="/multimedia/my-list?<?php echo http_build_query(array_merge($_GET, ['p' => $p])); ?>" 
                               class="fm-btn <?php echo $p === $page ? 'fm-btn-primary' : 'fm-btn-secondary'; ?>" 
                               style="padding: 6px 14px; font-size: 0.9rem;">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <?php
                $title = "No items in My List";
                $message = "You have not added any titles to your favorites list yet. Look for the bookmark or heart icon on any title to save it.";
                $icon = "⭐";
                $actionUrl = "/multimedia";
                $actionText = "Discover Entertainment";
                include $componentsDir . '/empty-state.php';
                ?>
            <?php endif; ?>

        </div>
    </main>

    <!-- Global Toast Notifications -->
    <?php include $componentsDir . '/toast.php'; ?>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>
