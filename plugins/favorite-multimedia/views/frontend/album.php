<?php
/**
 * Favorite Multimedia — Album View
 * 
 * Powered by Theme Tokens & Modular Components
 *
 * @var string $metaTitle
 * @var object $album
 * @var object|null $artist
 * @var array $songs
 * @var object|null $user
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$componentsDir = __DIR__ . '/components';
$currentNav = 'music';
$contentType = 'album';
include $componentsDir . '/content-layout.php';

$albTitle = htmlspecialchars($album->title ?? 'Album', ENT_QUOTES, 'UTF-8');
$albCover = htmlspecialchars($album->cover ?? '', ENT_QUOTES, 'UTF-8');
$albDesc = htmlspecialchars($album->description ?? '', ENT_QUOTES, 'UTF-8');
$artistName = htmlspecialchars($artist?->name ?? 'Various Artists', ENT_QUOTES, 'UTF-8');
$artistSlug = htmlspecialchars($artist?->slug ?? '', ENT_QUOTES, 'UTF-8');
$releaseYear = !empty($album->release_date) ? substr((string)$album->release_date, 0, 4) : '';
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
        <div class="fav-mm-container" style="padding-top: 24px;">
            <?php if ($fmShowBackLink): ?>
                <a href="/multimedia/music" class="fm-back-link">&larr; Back to Music</a>
            <?php endif; ?>

            <div class="<?= $fmLayoutClass ?>">
                <div class="fm-content-main">
        <!-- Album Header Card -->
        <div class="fm-detail-hero" style="display:flex; gap:28px; align-items:center; flex-wrap:wrap; background:var(--fm-color-bg-surface); border:var(--fm-border-strength) solid var(--fm-color-border); border-radius:var(--fm-radius-card); padding:28px; margin-bottom:32px;">
            <?php if ($albCover): ?>
                <img src="<?= $albCover ?>" alt="<?= $albTitle ?>" style="width:180px; height:180px; border-radius:var(--fm-radius-card); object-fit:cover; box-shadow:var(--fm-shadow-card);">
            <?php else: ?>
                <div style="width:180px; height:180px; border-radius:var(--fm-radius-card); background:var(--fm-color-bg-elevated); display:flex; align-items:center; justify-content:center; font-size:56px; color:var(--fm-color-primary);">
                    💿
                </div>
            <?php endif; ?>
            <div style="flex:1; min-width:260px;">
                <span class="fm-pill fm-pill-type" style="display:inline-block; margin-bottom:8px;">ALBUM</span>
                <h1 style="font-size:32px; font-weight:800; margin:0 0 8px 0; color:var(--fm-color-text-primary);"><?= $albTitle ?></h1>
                <div style="font-size:15px; color:var(--fm-color-text-secondary); margin-bottom:12px;">
                    By <a href="/multimedia/artist/<?= $artistSlug ?>" style="color:var(--fm-color-primary); font-weight:600; text-decoration:none;"><?= $artistName ?></a>
                    <?php if ($releaseYear): ?> &bull; <?= $releaseYear ?><?php endif; ?>
                    &bull; <?= count($songs) ?> tracks
                </div>
                <?php if ($albDesc): ?>
                    <p style="margin:0 0 16px 0; color:var(--fm-color-text-muted); font-size:14px; line-height:1.6; max-width:640px;"><?= nl2br($albDesc) ?></p>
                <?php endif; ?>
                <div style="display:flex; gap:12px; align-items:center; margin-top:12px;">
                    <button type="button" class="fm-btn fm-btn-primary" data-fm-play-all="album" data-context-id="<?= (int)($album->id ?? 0) ?>">
                        <span>▶</span> Play Album
                    </button>
                </div>
            </div>
        </div>

        <!-- Album Tracklist -->
        <section class="fm-section" style="margin-bottom: 40px;">
            <div class="fm-section-header">
                <h2 class="fm-section-title">Tracklist</h2>
            </div>
            <?php if (empty($songs)): ?>
                <?php 
                $emptyIcon = '🎵';
                $emptyTitle = 'No Tracks Available';
                $emptyMessage = 'There are no songs in this album yet.';
                include $componentsDir . '/empty-state.php'; 
                ?>
            <?php else: ?>
                <div class="fm-song-list">
                    <?php 
                    $trackNum = 1;
                    foreach ($songs as $item): 
                        $index = $trackNum++;
                        include $componentsDir . '/song-row.php';
                    endforeach; 
                    ?>
                </div>
            <?php endif; ?>
        </section>
                </div><!-- /.fm-content-main -->
                <?php if ($fmSidebarEnabled): ?>
                    <?php include $componentsDir . '/sticky-sidebar.php'; ?>
                <?php endif; ?>
            </div><!-- /.fm-content-layout -->
        </div><!-- /.fav-mm-container -->
    </main>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>
