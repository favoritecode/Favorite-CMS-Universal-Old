<?php
/**
 * Favorite Multimedia — User Subscriptions & Following Directory
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($metaTitle ?? 'Following', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <meta name="csrf-token" content="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <?php 
    $themeManager = \FavoriteCMS\Multimedia\Theme\ThemeManager::getInstance();
    $componentsDir = __DIR__ . '/components';
    $currentNav = 'library';
    echo $themeManager->renderHeadTokens(); 
    ?>
</head>
<body class="fm-theme-body" style="margin: 0; padding: 0;">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <div class="fav-mm-container" style="padding-top: 24px;">
        <div style="margin-bottom: 24px;">
            <h1 style="color: var(--fm-color-text-primary, #fff); font-size: 26px; margin: 0;">Following Directory</h1>
            <p style="color: var(--fm-color-text-muted, #94a3b8); font-size: 14px; margin: 4px 0 0 0;">Manage your content subscriptions and release alert sources.</p>
        </div>

        <!-- Navigation Tabs -->
        <div style="display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid #1e293b; padding-bottom: 12px; flex-wrap: wrap;">
            <a href="/multimedia/following?tab=all" class="fmm-filter-btn <?= ($tab === 'all') ? 'active' : '' ?>">
                All (<?= (int)($total ?? 0) ?>)
            </a>
            <a href="/multimedia/following?tab=series" class="fmm-filter-btn <?= ($tab === 'series') ? 'active' : '' ?>">
                Web Series (<?= (int)($seriesCount ?? 0) ?>)
            </a>
            <a href="/multimedia/following?tab=artist" class="fmm-filter-btn <?= ($tab === 'artist') ? 'active' : '' ?>">
                Artists (<?= (int)($artistCount ?? 0) ?>)
            </a>
            <a href="/multimedia/following?tab=playlist" class="fmm-filter-btn <?= ($tab === 'playlist') ? 'active' : '' ?>">
                Playlists (<?= (int)($playlistCount ?? 0) ?>)
            </a>
        </div>

        <!-- Following Items Grid -->
        <?php if (empty($items)): ?>
            <div style="background: #1e293b; border-radius: 8px; padding: 48px 24px; text-align: center; color: #94a3b8;">
                <div style="font-size: 40px; margin-bottom: 12px;">📡</div>
                <h3 style="color: #fff; font-size: 18px; margin: 0 0 6px 0;">Not following anything yet</h3>
                <p style="margin: 0; font-size: 14px;">Follow your favorite web series, artists, or playlists to get alerted whenever new releases drop.</p>
                <div style="margin-top: 16px;">
                    <a href="/multimedia/discover" class="fmm-btn-action primary" style="display: inline-block; text-decoration: none;">Explore Discover Hub</a>
                </div>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
                <?php foreach ($items as $sub): ?>
                    <?php
                        $target = $sub['item'];
                        $isAvailable = !empty($target['is_available']);
                        $typeLabel = match ($sub['target_type']) {
                            'series'   => 'Web Series',
                            'artist'   => 'Artist',
                            'playlist' => 'Playlist',
                            default    => 'Content',
                        };
                    ?>
                    <div class="fmm-following-card" data-sub-card="<?= (int)$sub['id'] ?>">
                        <div style="display: flex; gap: 14px; align-items: center;">
                            <?php if (!empty($target['poster'])): ?>
                                <img src="<?= htmlspecialchars($target['poster'], ENT_QUOTES, 'UTF-8') ?>" alt="" style="width: 54px; height: 54px; border-radius: <?= ($sub['target_type'] === 'artist') ? '50%' : '6px' ?>; object-fit: cover;">
                            <?php else: ?>
                                <div style="width: 54px; height: 54px; border-radius: <?= ($sub['target_type'] === 'artist') ? '50%' : '6px' ?>; background: #334155; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                    <?= ($sub['target_type'] === 'artist') ? '🎤' : (($sub['target_type'] === 'series') ? '📺' : '📑') ?>
                                </div>
                            <?php endif; ?>

                            <div style="flex: 1; min-width: 0;">
                                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #38bdf8; letter-spacing: 0.5px;">
                                    <?= $typeLabel ?>
                                </span>
                                <h4 style="margin: 2px 0 4px 0; font-size: 15px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?php if ($isAvailable): ?>
                                        <a href="<?= htmlspecialchars($target['url'], ENT_QUOTES, 'UTF-8') ?>" style="color: #fff; text-decoration: none;">
                                            <?= htmlspecialchars($target['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-style: italic;">Unavailable Item</span>
                                    <?php endif; ?>
                                </h4>
                                <span style="font-size: 12px; color: #64748b;">Followed <?= date('M j, Y', strtotime($sub['created_at'])) ?></span>
                            </div>
                        </div>

                        <div style="margin-top: 14px; display: flex; justify-content: space-between; align-items: center;">
                            <?php if ($isAvailable): ?>
                                <a href="<?= htmlspecialchars($target['url'], ENT_QUOTES, 'UTF-8') ?>" style="font-size: 12px; color: #38bdf8; text-decoration: none; font-weight: 600;">View &rarr;</a>
                            <?php else: ?>
                                <span style="font-size: 12px; color: #64748b;">Archived</span>
                            <?php endif; ?>

                            <button type="button" class="fmm-btn-follow active" data-fmm-subscribe-toggle data-target-type="<?= htmlspecialchars($sub['target_type'], ENT_QUOTES, 'UTF-8') ?>" data-target-id="<?= (int)$sub['target_id'] ?>" style="font-size: 12px; padding: 4px 10px;">
                                <span class="fmm-follow-icon">✓</span>
                                <span class="fmm-follow-label">Following</span>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
    <?php include $componentsDir . '/mobile-nav.php'; ?>
    <script src="/plugins/favorite-multimedia/assets/js/multimedia-engagement.js?v=1.0.7"></script>
    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>

