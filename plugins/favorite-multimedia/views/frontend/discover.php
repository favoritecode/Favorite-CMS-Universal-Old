<?php
/**
 * Favorite Multimedia — Frontend Discover Page
 */
$tab = $currentTab ?? 'all';
$type = $currentType ?? 'all';
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
    <?php 
    $themeManager = \FavoriteCMS\Multimedia\Theme\ThemeManager::getInstance();
    $componentsDir = __DIR__ . '/components';
    $currentNav = 'search';
    echo $themeManager->renderHeadTokens(); 
    ?>
</head>
<body class="fm-theme-body" style="margin: 0; padding: 0;">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <div class="fav-mm-container" style="padding-top: 24px;">
        <div style="margin-bottom: 24px;">
            <h1 class="fav-mm-title" style="color: var(--fm-color-text-primary, #fff);">Discover Multimedia</h1>
            <p style="margin: 4px 0 0 0; color: var(--fm-color-text-muted, #64748b); font-size: 14px;">Explore trending hits, timeless classics, and tailored picks</p>
        </div>

        <!-- Search Bar -->
        <form method="GET" action="/multimedia/search" class="fav-mm-filter-bar">
            <input type="text" name="q" class="fav-mm-search-input" placeholder="Search movies, series, songs, artists, genres..." required>
            <button type="submit" style="background:#2563eb; color:#fff; border:none; padding:10px 20px; border-radius:6px; font-weight:600; cursor:pointer;">Search</button>
        </form>

        <!-- Filter Navigation Pills -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:28px;">
            <!-- Primary Discovery Tabs -->
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="/multimedia/discover?tab=all&type=<?= urlencode($type) ?>" class="fav-mm-tab <?= ($tab === 'all') ? 'active' : '' ?>">🌟 Overview</a>
                <a href="/multimedia/discover?tab=trending&type=<?= urlencode($type) ?>" class="fav-mm-tab <?= ($tab === 'trending') ? 'active' : '' ?>">🔥 Trending Now</a>
                <a href="/multimedia/discover?tab=popular&type=<?= urlencode($type) ?>" class="fav-mm-tab <?= ($tab === 'popular') ? 'active' : '' ?>">⭐ Most Popular</a>
                <a href="/multimedia/discover?tab=recent&type=<?= urlencode($type) ?>" class="fav-mm-tab <?= ($tab === 'recent') ? 'active' : '' ?>">✨ Recently Added</a>
                <a href="/multimedia/discover?tab=genres" class="fav-mm-tab <?= ($tab === 'genres') ? 'active' : '' ?>">🏷️ Browse by Genre</a>
            </div>

            <!-- Content Type Filter (when not in genres tab) -->
            <?php if ($tab !== 'genres'): ?>
                <div style="display:flex; gap:6px; align-items:center;">
                    <span style="font-size:13px; color:#64748b; font-weight:600;">Format:</span>
                    <a href="/multimedia/discover?tab=<?= urlencode($tab) ?>&type=all" class="fav-mm-pill <?= ($type === 'all') ? 'active' : '' ?>">All</a>
                    <a href="/multimedia/discover?tab=<?= urlencode($tab) ?>&type=movie" class="fav-mm-pill <?= ($type === 'movie') ? 'active' : '' ?>">Movies</a>
                    <a href="/multimedia/discover?tab=<?= urlencode($tab) ?>&type=series" class="fav-mm-pill <?= ($type === 'series') ? 'active' : '' ?>">Series</a>
                    <a href="/multimedia/discover?tab=<?= urlencode($tab) ?>&type=song" class="fav-mm-pill <?= ($type === 'song') ? 'active' : '' ?>">Music</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($tab === 'all'): ?>
            <!-- Overview Mode: Multi-shelf view -->

            <?php if (!empty($personalized)): ?>
                <!-- Recommended for You -->
                <section style="margin-bottom: 40px;">
                    <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:16px;">
                        <h2 style="font-size:20px; font-weight:700; margin:0; display:flex; align-items:center; gap:8px;">
                            <span>🎯</span> Recommended For You
                        </h2>
                        <span style="color:#64748b; font-size:13px;">Based on your watch &amp; like preferences</span>
                    </div>
                    <div class="fav-mm-grid">
                        <?php foreach ($personalized as $item): ?>
                            <?php include __DIR__ . '/_discovery_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($trending)): ?>
                <!-- Trending Now -->
                <section style="margin-bottom: 40px;">
                    <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:16px;">
                        <h2 style="font-size:20px; font-weight:700; margin:0; display:flex; align-items:center; gap:8px;">
                            <span>🔥</span> Trending This Week
                        </h2>
                        <a href="/multimedia/discover?tab=trending&type=<?= urlencode($type) ?>" style="color:#2563eb; font-weight:600; font-size:14px; text-decoration:none;">View All &rarr;</a>
                    </div>
                    <div class="fav-mm-grid">
                        <?php foreach (array_slice($trending, 0, 8) as $item): ?>
                            <?php include __DIR__ . '/_discovery_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($popular)): ?>
                <!-- Most Popular -->
                <section style="margin-bottom: 40px;">
                    <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:16px;">
                        <h2 style="font-size:20px; font-weight:700; margin:0; display:flex; align-items:center; gap:8px;">
                            <span>⭐</span> All-Time Popular
                        </h2>
                        <a href="/multimedia/discover?tab=popular&type=<?= urlencode($type) ?>" style="color:#2563eb; font-weight:600; font-size:14px; text-decoration:none;">View All &rarr;</a>
                    </div>
                    <div class="fav-mm-grid">
                        <?php foreach (array_slice($popular, 0, 8) as $item): ?>
                            <?php include __DIR__ . '/_discovery_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($recentlyAdded)): ?>
                <!-- Recently Added -->
                <section style="margin-bottom: 40px;">
                    <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:16px;">
                        <h2 style="font-size:20px; font-weight:700; margin:0; display:flex; align-items:center; gap:8px;">
                            <span>✨</span> Fresh Arrivals
                        </h2>
                        <a href="/multimedia/discover?tab=recent&type=<?= urlencode($type) ?>" style="color:#2563eb; font-weight:600; font-size:14px; text-decoration:none;">View All &rarr;</a>
                    </div>
                    <div class="fav-mm-grid">
                        <?php foreach (array_slice($recentlyAdded, 0, 8) as $item): ?>
                            <?php include __DIR__ . '/_discovery_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($genres)): ?>
                <!-- Browse Genres Shelf -->
                <section style="margin-bottom: 40px;">
                    <h2 style="font-size:20px; font-weight:700; margin:0 0 16px 0;">🏷️ Popular Genres</h2>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <?php foreach ($genres as $g): ?>
                            <a href="/multimedia/genre/<?= urlencode($g->slug) ?>" class="fav-mm-genre-pill">
                                <?= htmlspecialchars($g->name, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        <?php elseif ($tab === 'genres'): ?>
            <!-- Genres Tab -->
            <section style="margin-bottom: 40px;">
                <h2 style="font-size:22px; font-weight:700; margin:0 0 20px 0;">All Catalog Genres</h2>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:14px;">
                    <?php foreach ($genres as $g): ?>
                        <a href="/multimedia/genre/<?= urlencode($g->slug) ?>" style="display:block; padding:16px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; text-decoration:none; color:#0f172a; font-weight:600; text-align:center; transition:all 0.15s;" onmouseover="this.style.borderColor='#2563eb'; this.style.color='#2563eb';" onmouseout="this.style.borderColor='#e2e8f0'; this.style.color='#0f172a';">
                            <?= htmlspecialchars($g->name, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

        <?php else: ?>
            <!-- Single Specific Tab Grid (trending, popular, recent) -->
            <?php
                $activeList = match ($tab) {
                    'trending' => $trending,
                    'popular'  => $popular,
                    'recent'   => $recentlyAdded,
                    default    => [],
                };
            ?>
            <section style="margin-bottom: 40px;">
                <?php if (empty($activeList)): ?>
                    <div style="text-align:center; padding:48px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; color:#64748b;">
                        <p style="font-size:16px; margin:0;">No items found in this section yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fav-mm-grid">
                        <?php foreach ($activeList as $item): ?>
                            <?php include __DIR__ . '/_discovery_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    </div>
    <?php include $componentsDir . '/mobile-nav.php'; ?>
    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>

