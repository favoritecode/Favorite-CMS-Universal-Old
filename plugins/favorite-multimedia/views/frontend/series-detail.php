<?php
/**
 * Favorite Multimedia — Web Series Detail View (Seasons & Episodes)
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if (!empty($metaDescription)): ?>
        <meta name="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <?php 
    $themeManager = \FavoriteCMS\Multimedia\Theme\ThemeManager::getInstance();
    $componentsDir = __DIR__ . '/components';
    $currentNav = 'series';
    $contentType = 'series';
    include $componentsDir . '/content-layout.php';
    echo $themeManager->renderHeadTokens(); 
    ?>
</head>
<body class="fm-theme-body" style="margin: 0; padding: 0;">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <div class="fav-mm-container" style="padding-top: 24px;">
        <div style="margin-bottom: 16px;">
            <?php if ($fmShowBackLink): ?>
                <a href="/series" class="fm-back-link">&larr; Back to Series</a>
            <?php endif; ?>
            <h1 style="color: var(--fm-color-text-primary); font-size: 28px; margin: 8px 0 0 0;"><?php echo htmlspecialchars($series->title, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>

        <div class="<?= $fmLayoutClass ?>">
            <div class="fm-content-main">

        <!-- Series Info Card -->
        <div style="background: var(--fm-color-bg-surface); border: var(--fm-border-strength, 1px) solid var(--fm-color-border); border-radius: var(--fm-radius-card, 8px); padding: 24px; margin-bottom: 32px; display: flex; gap: 24px; flex-wrap: wrap;">
            <?php if ($series->poster): ?>
                <img src="<?php echo htmlspecialchars($series->poster, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 160px; height: 240px; object-fit: cover; border-radius: 6px; flex-shrink: 0;">
            <?php else: ?>
                <div class="fav-mm-placeholder fav-mm-placeholder-series" style="width: 160px; height: 240px; border-radius: 6px; flex-shrink: 0;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg>
                    <span>Series</span>
                </div>
            <?php endif; ?>
            <div style="flex-grow: 1;">
                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
                    <span class="fav-badge fav-badge-<?php echo strtolower($series->access_mode ?? 'public'); ?>"><?php echo strtoupper($series->access_mode ?? 'public'); ?></span>
                    <?php if ($series->release_year): ?>
                        <span style="color: #94a3b8;"><?php echo $series->release_year; ?></span>
                    <?php endif; ?>
                    <?php if ($series->language): ?>
                        <span style="color: #94a3b8;">• <?php echo htmlspecialchars($series->language, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>

                    <?php if (!empty($user)): ?>
                        <?php $sidebarHasActions = !empty($fmSidebarEnabled) && !empty($singleCfg['blocks']['actions']); ?>
                        <?php if (!$sidebarHasActions): ?>
                            <div style="margin-left: auto; display: flex; gap: 8px; align-items: center;">
                                <button type="button" class="fmm-btn-follow <?= !empty($isFollowing) ? 'active' : '' ?>" data-fmm-subscribe-toggle data-target-type="series" data-target-id="<?= (int)$series->id ?>">
                                    <span class="fmm-follow-icon"><?= !empty($isFollowing) ? '✓' : '+' ?></span>
                                    <span class="fmm-follow-label"><?= !empty($isFollowing) ? 'Following' : 'Follow Series' ?></span>
                                </button>
                                <button type="button" class="fav-btn-favorite <?= !empty($isFavorite) ? 'active' : '' ?>" data-fmm-fav-toggle data-content-type="series" data-content-id="<?= (int)$series->id ?>">
                                    <span class="fav-icon-star">★</span>
                                    <span class="fav-btn-label"><?= !empty($isFavorite) ? 'In My List' : 'Add to My List' ?></span>
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($seriesProgress) && ($seriesProgress['total_episodes'] ?? 0) > 0): ?>
                    <div style="background: var(--fm-color-bg-elevated); border: 1px solid var(--fm-color-border); border-radius: 6px; padding: 12px 16px; margin-bottom: 16px;">
                        <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:6px; color:var(--fm-color-text-secondary);">
                            <span>Series Progress: <strong><?= (int)($seriesProgress['completed_episodes'] ?? 0) ?></strong> of <strong><?= (int)($seriesProgress['total_episodes'] ?? 0) ?></strong> episodes watched</span>
                            <span><?= round((float)($seriesProgress['overall_percentage'] ?? 0)) ?>%</span>
                        </div>
                        <div style="height:6px; background:rgba(255,255,255,0.1); border-radius:3px; overflow:hidden; margin-bottom:10px;">
                            <div style="height:100%; background:var(--fm-color-primary, #0ea5e9); width:<?= min(100, (float)($seriesProgress['overall_percentage'] ?? 0)) ?>%;"></div>
                        </div>
                        <?php if (!empty($seriesProgress['next_episode'])): ?>
                            <?php $nxt = $seriesProgress['next_episode']; ?>
                            <a href="/episode/<?= htmlspecialchars($nxt->slug, ENT_QUOTES, 'UTF-8') ?>" class="fmm-btn fmm-btn-primary" style="font-size:13px; padding:6px 14px;">
                                ▶ Continue: Ep <?= (int)$nxt->episode_number ?> — <?= htmlspecialchars($nxt->title, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div style="color: var(--fm-color-text-secondary); line-height: 1.6; margin-bottom: 16px;">
                    <?php echo nl2br(htmlspecialchars($series->description ?? '', ENT_QUOTES, 'UTF-8')); ?>
                </div>

                <?php if ($series->cast): ?>
                    <p style="font-size: 13px; color: var(--fm-color-text-muted); margin: 4px 0;"><strong style="color: var(--fm-color-text-primary);">Cast:</strong> <?php echo htmlspecialchars($series->cast, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if ($series->director): ?>
                    <p style="font-size: 13px; color: var(--fm-color-text-muted); margin: 4px 0;"><strong style="color: var(--fm-color-text-primary);">Director:</strong> <?php echo htmlspecialchars($series->director, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Seasons & Episodes -->
        <div class="fav-mm-series-seasons">
            <?php if (empty($seasons)): ?>
                <p style="color: #94a3b8;">No seasons released yet.</p>
            <?php else: foreach ($seasons as $season): ?>
                <div style="margin-bottom: 32px;">
                    <h2 class="fav-mm-season-title" style="color: var(--fm-color-text-primary); border-color: var(--fm-color-border);">
                        <?php echo htmlspecialchars($season->title, ENT_QUOTES, 'UTF-8'); ?>
                    </h2>

                    <div class="fav-mm-episodes-list">
                        <?php $episodes = $season->getEpisodes(true); ?>
                        <?php if (empty($episodes)): ?>
                            <p style="color: var(--fm-color-text-muted); font-size: 13px;">No episodes listed yet.</p>
                        <?php else: foreach ($episodes as $ep): ?>
                            <a href="/episode/<?php echo htmlspecialchars($ep->slug, ENT_QUOTES, 'UTF-8'); ?>" class="fav-mm-episode-card" style="background: var(--fm-color-bg-surface); border-color: var(--fm-color-border); color: var(--fm-color-text-primary);">
                                <?php if ($ep->thumbnail): ?>
                                    <img src="<?php echo htmlspecialchars($ep->thumbnail, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="fav-mm-episode-thumb">
                                <?php else: ?>
                                    <div class="fav-mm-episode-thumb" style="display: flex; align-items: center; justify-content: center; font-size: 12px; color: var(--fm-color-text-muted);">Ep <?php echo $ep->episode_number; ?></div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-size: 11px; color: var(--fm-color-primary); font-weight: 700; text-transform: uppercase;">Episode <?php echo $ep->episode_number; ?></div>
                                    <h4 style="margin: 2px 0 4px 0; font-size: 14px; font-weight: 600; color: var(--fm-color-text-primary);"><?php echo htmlspecialchars($ep->title, ENT_QUOTES, 'UTF-8'); ?></h4>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <span class="fav-badge fav-badge-<?php echo strtolower($ep->getResolvedAccessMode()); ?>" style="font-size: 9px;"><?php echo strtoupper($ep->getResolvedAccessMode()); ?></span>
                                        <?php if ($ep->duration): ?>
                                            <span style="font-size: 11px; color: var(--fm-color-text-muted);"><?php echo round($ep->duration / 60); ?> min</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <?php
        $contentType = 'series';
        $contentId = (int)$series->id;
        include __DIR__ . '/_engagement_section.php';
        ?>

        <?php if (!empty($relatedSeries)): ?>
            <div style="margin-top: 40px; border-top: 1px solid var(--fm-color-border); padding-top: 24px;">
                <h3 style="font-size: 20px; font-weight: 700; color: var(--fm-color-text-primary); margin: 0 0 16px 0;">📺 Related Web Series You May Like</h3>
                <div class="fav-mm-grid">
                    <?php foreach ($relatedSeries as $item): ?>
                        <?php include __DIR__ . '/_discovery_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
            </div><!-- /.fm-content-main -->
            <?php if ($fmSidebarEnabled): ?>
                <?php include $componentsDir . '/sticky-sidebar.php'; ?>
            <?php endif; ?>
        </div><!-- /.fm-content-layout -->
    </div><!-- /.fav-mm-container -->

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/multimedia-player.js?v=1.0.7"></script>
    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>

