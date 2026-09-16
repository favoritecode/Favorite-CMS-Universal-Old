<?php
/**
 * Favorite Multimedia — Browse by Genre View
 */
$genreName = htmlspecialchars($genre->name ?? 'Genre', ENT_QUOTES, 'UTF-8');
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
    $currentNav = 'movies';
    echo $themeManager->renderHeadTokens(); 
    ?>
</head>
<body class="fm-theme-body" style="margin: 0; padding: 0;">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <div class="fav-mm-container" style="padding-top: 24px;">
        <div style="margin-bottom: 24px;">
            <h1 class="fav-mm-title" style="color: var(--fm-color-text-primary, #fff); font-size: 26px; margin: 0;"><?= $genreName ?></h1>
            <p style="margin: 4px 0 0 0; color: var(--fm-color-text-muted, #64748b); font-size: 14px;">
                <?= (int)$total ?> titles tagged in <?= $genreName ?>
            </p>
        </div>

        <?php if (!empty($movies)): ?>
            <!-- Movies in this genre -->
            <section style="margin-bottom: 40px;">
                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:16px;">
                    <h2 style="font-size:20px; font-weight:700; margin:0;">🎬 Movies in <?= $genreName ?> (<?= count($movies) ?>)</h2>
                    <a href="/movies?genre=<?= urlencode($genre->slug) ?>" style="color:#2563eb; font-weight:600; font-size:14px; text-decoration:none;">View all movies &rarr;</a>
                </div>
                <div class="fav-mm-grid">
                    <?php foreach ($movies as $item): ?>
                        <?php include __DIR__ . '/_discovery_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($series)): ?>
            <!-- Web Series in this genre -->
            <section style="margin-bottom: 40px;">
                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:16px;">
                    <h2 style="font-size:20px; font-weight:700; margin:0;">📺 Web Series in <?= $genreName ?> (<?= count($series) ?>)</h2>
                    <a href="/series?genre=<?= urlencode($genre->slug) ?>" style="color:#2563eb; font-weight:600; font-size:14px; text-decoration:none;">View all series &rarr;</a>
                </div>
                <div class="fav-mm-grid">
                    <?php foreach ($series as $item): ?>
                        <?php include __DIR__ . '/_discovery_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($songs)): ?>
            <!-- Songs in this genre -->
            <section style="margin-bottom: 40px;">
                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:16px;">
                    <h2 style="font-size:20px; font-weight:700; margin:0;">🎵 Music in <?= $genreName ?> (<?= count($songs) ?>)</h2>
                    <a href="/songs?genre=<?= urlencode($genre->slug) ?>" style="color:#2563eb; font-weight:600; font-size:14px; text-decoration:none;">View all songs &rarr;</a>
                </div>
                <div class="fav-mm-grid">
                    <?php foreach ($songs as $item): ?>
                        <?php include __DIR__ . '/_discovery_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (empty($items)): ?>
            <div style="text-align:center; padding:48px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; color:#64748b;">
                <p style="font-size:16px; margin:0 0 12px 0;">No published multimedia items currently tagged in this genre.</p>
                <a href="/multimedia/discover" style="color:#2563eb; font-weight:600; text-decoration:none;">Browse All Discover &rarr;</a>
            </div>
        <?php endif; ?>

    </div>
    <?php include $componentsDir . '/mobile-nav.php'; ?>
    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>

