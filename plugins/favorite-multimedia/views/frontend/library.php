<?php
/**
 * Favorite Multimedia — Personal User Library Experience
 *
 * @var string $metaTitle
 * @var array $dashboard
 * @var \FavoriteCMS\Models\User $user
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$componentsDir = __DIR__ . '/components';
$activeTab = $_GET['tab'] ?? 'overview';

$cw = $dashboard['continue_watching'] ?? [];
$cl = $dashboard['continue_listening'] ?? [];
$myList = $dashboard['my_list'] ?? [];
$recentHistory = $dashboard['recent_history'] ?? [];
$favoritesCount = (int)($dashboard['favorites_count'] ?? count($myList));
$historyCount = (int)($dashboard['history_count'] ?? count($recentHistory));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle ?? 'My Library — Favorite Multimedia', ENT_QUOTES, 'UTF-8'); ?></title>
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

    <!-- Main Library Content -->
    <main class="fm-main-content">
        <div class="fm-container" style="padding-top: 36px; padding-bottom: 60px;">

            <!-- Header Banner -->
            <div style="margin-bottom: 28px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1 style="font-family: var(--fm-font-heading); font-size: 2.2rem; font-weight: 800; margin-bottom: 6px;">
                        👤 My Library
                    </h1>
                    <p style="color: var(--fm-color-text-secondary); font-size: 1.05rem;">
                        Welcome back, <strong><?php echo htmlspecialchars($user->username ?? 'User', ENT_QUOTES, 'UTF-8'); ?></strong>! Continue where you left off.
                    </p>
                </div>
                <div style="display: flex; gap: 12px;">
                    <a href="/multimedia/my-list" class="fm-btn fm-btn-secondary" style="font-size: 0.9rem;">
                        ⭐ My List (<?php echo $favoritesCount; ?>)
                    </a>
                    <a href="/multimedia/history" class="fm-btn fm-btn-secondary" style="font-size: 0.9rem;">
                        ⏱️ Full History (<?php echo $historyCount; ?>)
                    </a>
                </div>
            </div>

            <!-- Library Navigation Tabs -->
            <div class="fm-library-tabs" style="display: flex; gap: 8px; margin-bottom: 32px; border-bottom: 1px solid var(--fm-color-border, #2a3548); padding-bottom: 12px; overflow-x: auto;">
                <a href="/multimedia/library?tab=overview" class="fm-tab-pill <?php echo $activeTab === 'overview' ? 'active' : ''; ?>" style="padding: 8px 18px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; <?php echo $activeTab === 'overview' ? 'background: var(--fm-color-primary); color: #fff;' : 'background: var(--fm-color-bg-surface); color: var(--fm-color-text-secondary); border: 1px solid var(--fm-color-border);'; ?>">Overview</a>
                <a href="/multimedia/library?tab=watching" class="fm-tab-pill <?php echo $activeTab === 'watching' ? 'active' : ''; ?>" style="padding: 8px 18px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; <?php echo $activeTab === 'watching' ? 'background: var(--fm-color-primary); color: #fff;' : 'background: var(--fm-color-bg-surface); color: var(--fm-color-text-secondary); border: 1px solid var(--fm-color-border);'; ?>">Continue Watching (<?php echo count($cw); ?>)</a>
                <a href="/multimedia/library?tab=listening" class="fm-tab-pill <?php echo $activeTab === 'listening' ? 'active' : ''; ?>" style="padding: 8px 18px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; <?php echo $activeTab === 'listening' ? 'background: var(--fm-color-primary); color: #fff;' : 'background: var(--fm-color-bg-surface); color: var(--fm-color-text-secondary); border: 1px solid var(--fm-color-border);'; ?>">Continue Listening (<?php echo count($cl); ?>)</a>
                <a href="/multimedia/library?tab=favorites" class="fm-tab-pill <?php echo $activeTab === 'favorites' ? 'active' : ''; ?>" style="padding: 8px 18px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; <?php echo $activeTab === 'favorites' ? 'background: var(--fm-color-primary); color: #fff;' : 'background: var(--fm-color-bg-surface); color: var(--fm-color-text-secondary); border: 1px solid var(--fm-color-border);'; ?>">My List (<?php echo count($myList); ?>)</a>
            </div>

            <!-- 1. CONTINUE WATCHING -->
            <?php if (in_array($activeTab, ['overview', 'watching'], true)): ?>
                <section class="fm-library-section" style="margin-bottom: 48px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                        <h2 style="font-family: var(--fm-font-heading); font-size: 1.35rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                            <span>▶️</span>
                            <span>Continue Watching</span>
                        </h2>
                    </div>

                    <?php if (!empty($cw)): ?>
                        <div class="fm-grid fm-grid-posters" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: var(--fm-layout-card-gap, 20px);">
                            <?php foreach ($cw as $item): ?>
                                <article class="fm-card fm-continue-watching-card" style="background: var(--fm-color-bg-surface); border-radius: var(--fm-radius-card); border: 1px solid var(--fm-color-border); overflow: hidden; display: flex; flex-direction: column;">
                                    <div style="position: relative; aspect-ratio: 16/9; background: var(--fm-color-bg-elevated); overflow: hidden;">
                                        <?php if (!empty($item['poster'])): ?>
                                            <img src="<?php echo htmlspecialchars($item['poster'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
                                        <?php else: ?>
                                            <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2rem;">🎬</div>
                                        <?php endif; ?>
                                        <div style="position: absolute; bottom: 0; left: 0; right: 0; height: 4px; background: rgba(255,255,255,0.2);">
                                            <div style="height: 100%; background: var(--fm-color-primary, #e50914); width: <?php echo min(100, max(0, (float)$item['percentage'])); ?>%;"></div>
                                        </div>
                                    </div>
                                    <div style="padding: 14px; display: flex; flex-direction: column; flex: 1; justify-content: space-between; gap: 10px;">
                                        <div>
                                            <h3 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                            <div style="font-size: 0.8rem; color: var(--fm-color-text-muted);">Left: <?php echo htmlspecialchars($item['remaining_formatted'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-primary fm-btn-sm" style="width: 100%; text-align: center; justify-content: center;">
                                            Resume
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <?php
                        $title = "No active video progress";
                        $message = "Videos and series you start watching will automatically appear here so you can resume anytime.";
                        $icon = "🎬";
                        $actionUrl = "/movies";
                        $actionText = "Browse Movies";
                        include $componentsDir . '/empty-state.php';
                        ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- 2. CONTINUE LISTENING -->
            <?php if (in_array($activeTab, ['overview', 'listening'], true)): ?>
                <section class="fm-library-section" style="margin-bottom: 48px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                        <h2 style="font-family: var(--fm-font-heading); font-size: 1.35rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                            <span>🎧</span>
                            <span>Continue Listening</span>
                        </h2>
                    </div>

                    <?php if (!empty($cl)): ?>
                        <div class="fm-songs-list" style="background: var(--fm-color-bg-surface, #121824); border-radius: var(--fm-radius-card, 12px); border: 1px solid var(--fm-color-border, #2a3548); padding: 8px 16px;">
                            <?php 
                            $idx = 1;
                            foreach ($cl as $item): 
                                $song = $item['song'] ?? null;
                                if (!$song) continue;
                                $trackNumber = $idx++;
                                include $componentsDir . '/song-row.php';
                            endforeach; 
                            ?>
                        </div>
                    <?php else: ?>
                        <?php
                        $title = "No songs in progress";
                        $message = "Tracks and playlists you listen to will appear here with your saved listening position.";
                        $icon = "🎵";
                        $actionUrl = "/multimedia/music";
                        $actionText = "Explore Music";
                        include $componentsDir . '/empty-state.php';
                        ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- 3. MY LIST (FAVORITES) -->
            <?php if (in_array($activeTab, ['overview', 'favorites'], true)): ?>
                <section class="fm-library-section" style="margin-bottom: 48px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                        <h2 style="font-family: var(--fm-font-heading); font-size: 1.35rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                            <span>⭐</span>
                            <span>My List (Favorites)</span>
                        </h2>
                        <?php if (count($myList) > 6 && $activeTab === 'overview'): ?>
                            <a href="/multimedia/my-list" style="color: var(--fm-color-primary); font-size: 0.9rem; font-weight: 600;">View All &rarr;</a>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($myList)): ?>
                        <div class="fm-grid fm-grid-posters" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: var(--fm-layout-card-gap, 20px);">
                            <?php foreach ($myList as $fav): 
                                $item = $fav;
                                include $componentsDir . '/poster-card.php';
                            endforeach; ?>
                        </div>
                    <?php else: ?>
                        <?php
                        $title = "Your list is empty";
                        $message = "Add movies, series, songs, and albums to your personal list to easily find them later.";
                        $icon = "⭐";
                        $actionUrl = "/multimedia";
                        $actionText = "Explore Catalog";
                        include $componentsDir . '/empty-state.php';
                        ?>
                    <?php endif; ?>
                </section>
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
