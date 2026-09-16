<?php
/**
 * Favorite Multimedia — Artist Profile & Catalog
 * 
 * Powered by Theme Tokens & Modular Components
 *
 * @var string $metaTitle
 * @var object $artist
 * @var array $songs
 * @var array $albums
 * @var array $videos
 * @var object|null $user
 * @var bool|null $isFollowing
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$componentsDir = __DIR__ . '/components';
$currentNav = 'music';
$contentType = 'artist';
include $componentsDir . '/content-layout.php';

$artistName = htmlspecialchars($artist->name ?? 'Artist', ENT_QUOTES, 'UTF-8');
$artistBio = htmlspecialchars($artist->biography ?? ($artist->bio ?? ''), ENT_QUOTES, 'UTF-8');
$artistPhoto = htmlspecialchars($artist->photo ?? '', ENT_QUOTES, 'UTF-8');
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
        <!-- Artist Hero Banner -->
        <div class="fm-detail-hero" style="display:flex; gap:28px; align-items:center; flex-wrap:wrap; background:var(--fm-color-bg-surface); border:var(--fm-border-strength) solid var(--fm-color-border); border-radius:var(--fm-radius-card); padding:28px; margin-bottom:32px;">
            <?php if ($artistPhoto): ?>
                <img src="<?= $artistPhoto ?>" alt="<?= $artistName ?>" style="width:130px; height:130px; border-radius:50%; object-fit:cover; border:3px solid var(--fm-color-primary); box-shadow:var(--fm-shadow-card);">
            <?php else: ?>
                <div style="width:130px; height:130px; border-radius:50%; background:var(--fm-color-primary); color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:48px; font-weight:700; box-shadow:var(--fm-shadow-card);">
                    <?= strtoupper(substr($artistName, 0, 1)) ?>
                </div>
            <?php endif; ?>
            <div style="flex:1; min-width:260px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:8px;">
                    <div>
                        <span class="fm-pill fm-pill-type" style="display:inline-block; margin-bottom:4px;">ARTIST</span>
                        <h1 style="font-size:32px; font-weight:800; margin:0; color:var(--fm-color-text-primary);"><?= $artistName ?></h1>
                    </div>
                    <?php if (!empty($user)): ?>
                        <button type="button" class="fmm-btn-follow <?= !empty($isFollowing) ? 'active' : '' ?>" data-fmm-subscribe-toggle data-target-type="artist" data-target-id="<?= (int)$artist->id ?>">
                            <span class="fmm-follow-icon"><?= !empty($isFollowing) ? '✓' : '+' ?></span>
                            <span class="fmm-follow-label"><?= !empty($isFollowing) ? 'Following' : 'Follow Artist' ?></span>
                        </button>
                    <?php endif; ?>
                </div>
                <?php if ($artistBio): ?>
                    <p style="margin:0; color:var(--fm-color-text-muted); font-size:14px; line-height:1.6; max-width:680px;"><?= nl2br($artistBio) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Discography: Popular Tracks -->
        <?php if (!empty($songs)): ?>
            <section class="fm-section" style="margin-bottom: 40px;">
                <div class="fm-section-header">
                    <h2 class="fm-section-title">Popular Tracks</h2>
                </div>
                <div class="fm-song-list">
                    <?php 
                    $trackNum = 1;
                    foreach ($songs as $item): 
                        $index = $trackNum++;
                        include $componentsDir . '/song-row.php';
                    endforeach; 
                    ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Discography: Albums -->
        <?php if (!empty($albums)): ?>
            <section class="fm-section" style="margin-bottom: 40px;">
                <div class="fm-section-header">
                    <h2 class="fm-section-title">Albums &amp; Singles</h2>
                </div>
                <div class="fm-grid fm-grid-album">
                    <?php foreach ($albums as $item): ?>
                        <?php include $componentsDir . '/album-card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Music Videos -->
        <?php if (!empty($videos)): ?>
            <section class="fm-section" style="margin-bottom: 40px;">
                <div class="fm-section-header">
                    <h2 class="fm-section-title">Music Videos</h2>
                </div>
                <div class="fm-grid fm-grid-landscape">
                    <?php foreach ($videos as $item): ?>
                        <?php 
                        $cardType = 'video';
                        include $componentsDir . '/landscape-card.php'; 
                        ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
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
