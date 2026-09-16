<?php
/**
 * Favorite Multimedia — Playback History View
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
    <title><?php echo htmlspecialchars($metaTitle ?? 'Playback History — Favorite Multimedia', ENT_QUOTES, 'UTF-8'); ?></title>
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
            <div style="margin-bottom: 28px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
                <div>
                    <a href="/multimedia/library" style="color: var(--fm-color-text-muted); font-size: 0.9rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 8px;">&larr; Back to Library</a>
                    <h1 style="font-family: var(--fm-font-heading); font-size: 2.2rem; font-weight: 800; margin-bottom: 6px;">
                        ⏱️ Playback History
                    </h1>
                    <p style="color: var(--fm-color-text-secondary); font-size: 1.05rem;">
                        Review all movies, episodes, and songs you've played (<?php echo $total; ?> recorded).
                    </p>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div style="display: flex; gap: 8px; margin-bottom: 32px; border-bottom: 1px solid var(--fm-color-border, #2a3548); padding-bottom: 12px; overflow-x: auto;">
                <?php
                $types = [
                    ''        => 'All History',
                    'movie'   => 'Movies',
                    'episode' => 'Episodes',
                    'song'    => 'Songs',
                ];
                foreach ($types as $k => $label):
                    $isActive = ($currentType === $k || ($currentType === null && $k === ''));
                ?>
                    <a href="/multimedia/history<?php echo $k !== '' ? '?type=' . $k : ''; ?>" 
                       class="fm-tab-pill <?php echo $isActive ? 'active' : ''; ?>"
                       style="padding: 8px 18px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; <?php echo $isActive ? 'background: var(--fm-color-primary); color: #fff;' : 'background: var(--fm-color-bg-surface); color: var(--fm-color-text-secondary); border: 1px solid var(--fm-color-border);'; ?>">
                        <?php echo $label; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- History List -->
            <?php if (!empty($items)): ?>
                <div class="fm-history-list" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 40px;">
                    <?php foreach ($items as $it): 
                        $title = (string)($it['title'] ?? '');
                        $poster = (string)($it['poster'] ?? '');
                        $url = (string)($it['url'] ?? '');
                        $percent = (float)($it['percentage'] ?? 0.0);
                        $date = (string)($it['last_played_at'] ?? '');
                        $type = (string)($it['content_type'] ?? 'video');
                    ?>
                        <div class="fm-history-item" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 18px; background: var(--fm-color-bg-surface, #121824); border-radius: var(--fm-radius-card, 12px); border: 1px solid var(--fm-color-border, #2a3548); gap: 16px;">
                            <div style="display: flex; align-items: center; gap: 14px; min-width: 0;">
                                <div style="width: 50px; height: 50px; border-radius: 6px; overflow: hidden; background: var(--fm-color-bg-elevated); flex-shrink: 0;">
                                    <?php if ($poster !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($poster, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
                                    <?php else: ?>
                                        <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">📁</div>
                                    <?php endif; ?>
                                </div>
                                <div style="min-width: 0;">
                                    <h3 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <div style="font-size: 0.8rem; color: var(--fm-color-text-muted); display: flex; gap: 10px;">
                                        <span><?php echo ucfirst($type); ?></span>
                                        <span>&bull;</span>
                                        <span><?php echo round($percent); ?>% played</span>
                                        <?php if ($date !== ''): ?>
                                            <span>&bull;</span>
                                            <span><?php echo htmlspecialchars(substr($date, 0, 10), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <?php if ($url !== ''): ?>
                                    <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-secondary fm-btn-sm">Play Again</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div style="display: flex; justify-content: center; gap: 8px; margin-top: 32px;">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="/multimedia/history?<?php echo http_build_query(array_merge($_GET, ['p' => $p])); ?>" 
                               class="fm-btn <?php echo $p === $page ? 'fm-btn-primary' : 'fm-btn-secondary'; ?>" 
                               style="padding: 6px 14px; font-size: 0.9rem;">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <?php
                $title = "No playback history";
                $message = "You haven't played any multimedia content yet. Start exploring and your watch & listen history will be logged here.";
                $icon = "⏱️";
                $actionUrl = "/multimedia";
                $actionText = "Explore Catalog";
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
