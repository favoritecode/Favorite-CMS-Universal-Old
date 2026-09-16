<?php
/**
 * Favorite Multimedia — Interactive Playlist Player View
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <?php 
    $themeManager = \FavoriteCMS\Multimedia\Theme\ThemeManager::getInstance();
    $componentsDir = __DIR__ . '/components';
    $currentNav = 'playlists';
    $contentType = 'playlist';
    include $componentsDir . '/content-layout.php';
    echo $themeManager->renderHeadTokens(); 
    ?>
</head>
<body class="fm-theme-body" style="margin: 0; padding: 0;">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <div class="fav-mm-container" style="padding-top: 24px;">
        <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div>
                <?php if ($fmShowBackLink): ?>
                    <a href="/multimedia/music" class="fm-back-link">&larr; Back to Music</a>
                <?php endif; ?>
                <h1 style="color: var(--fm-color-text-primary); font-size: 28px; margin: 6px 0 0 0;"><?php echo htmlspecialchars($playlist->title, ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
            <?php if (!empty($user)): ?>
                <button type="button" class="fmm-btn-follow <?= !empty($isFollowing) ? 'active' : '' ?>" data-fmm-subscribe-toggle data-target-type="playlist" data-target-id="<?= (int)$playlist->id ?>">
                    <span class="fmm-follow-icon"><?= !empty($isFollowing) ? '✓' : '+' ?></span>
                    <span class="fmm-follow-label"><?= !empty($isFollowing) ? 'Following' : 'Follow Playlist' ?></span>
                </button>
            <?php endif; ?>
        </div>

        <div class="<?= $fmLayoutClass ?>">
            <div class="fm-content-main">

        <!-- Playlist Player Box -->
        <div class="fav-audio-player-box">
            <?php if ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::ALLOW): ?>
                <audio preload="metadata" style="display:none;"></audio>

                <!-- Now Playing Bar -->
                <div class="fav-audio-now-playing">
                    <img src="<?php echo htmlspecialchars($playlist->cover ?: '', ENT_QUOTES, 'UTF-8'); ?>" alt="" class="fav-audio-artwork" onerror="this.src='/themes/default/assets/images/placeholder.png';">
                    <div class="fav-audio-info">
                        <h3 class="fav-audio-title">Select track to play</h3>
                        <p class="fav-audio-artist"><?php echo htmlspecialchars($playlist->title, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="fav-progress-bar-container" style="background: rgba(255,255,255,0.15); height: 6px; border-radius: 3px; cursor: pointer;">
                    <div class="fav-progress-filled" style="background: #2563eb; height: 100%; width: 0%;"></div>
                </div>

                <div style="display: flex; justify-content: space-between; font-size: 12px; color: #94a3b8; margin-top: 6px;">
                    <span class="fav-time-display">0:00 / 0:00</span>
                    <a href="#" class="fav-btn-download fav-audio-download-link" style="display: none; padding: 2px 8px; font-size: 11px;">
                        &#8595; Download Track
                    </a>
                </div>

                <!-- Controls -->
                <div class="fav-audio-controls-row">
                    <button type="button" class="fav-btn-control fav-audio-btn-shuffle" aria-label="Shuffle" title="Shuffle" style="font-size: 16px; opacity: 0.6;">🔀</button>
                    <button type="button" class="fav-btn-control fav-audio-btn-prev" aria-label="Previous Track" style="font-size: 20px;">&#9198;</button>
                    <button type="button" class="fav-audio-btn-big fav-audio-btn-play" aria-label="Play">&#9654;</button>
                    <button type="button" class="fav-btn-control fav-audio-btn-next" aria-label="Next Track" style="font-size: 20px;">&#9197;</button>
                    <button type="button" class="fav-btn-control fav-audio-btn-loop" aria-label="Repeat" title="Repeat" style="font-size: 16px; opacity: 0.6;">🔁</button>
                </div>
                <div class="fav-audio-status-notice" style="display: none; color: #38bdf8; font-size: 12px; text-align: center; margin: 4px auto 10px auto; max-width: 400px; padding: 4px 10px; background: rgba(37,99,235,0.15); border-radius: 4px; border: 1px solid rgba(37,99,235,0.3);"></div>

                <!-- Tracks Table -->
                <table class="fav-playlist-table">
                    <thead>
                        <tr>
                            <th style="width: 30px;">#</th>
                            <th>Title</th>
                            <th>Artist</th>
                            <th>Duration</th>
                            <th>Access</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tracksData)): ?>
                            <tr><td colspan="5" style="text-align:center; color:#64748b; padding: 16px;">This playlist has no tracks yet.</td></tr>
                        <?php else: foreach ($tracksData as $idx => $t): ?>
                            <tr class="fav-playlist-row <?php echo ($t['access'] !== \FavoriteCMS\Multimedia\Services\MultimediaAccessService::ALLOW) ? 'fav-locked-track' : ''; ?>" 
                                data-index="<?php echo $idx; ?>"
                                data-video-only="<?php echo !empty($t['is_video_only']) ? '1' : '0'; ?>"
                                data-playback-type="<?php echo htmlspecialchars($t['playback_type'] ?? 'audio', ENT_QUOTES, 'UTF-8'); ?>"
                                data-stream-url="<?php echo htmlspecialchars($t['stream_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-title="<?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-artist="<?php echo htmlspecialchars($t['artist'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-cover="<?php echo htmlspecialchars($t['cover'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-access="<?php echo htmlspecialchars($t['access'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-can-download="<?php echo $t['can_download'] ? '1' : '0'; ?>"
                                data-download-url="<?php echo htmlspecialchars($t['download_url'], ENT_QUOTES, 'UTF-8'); ?>">
                                <td><?php echo $idx + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (!empty($t['is_video_only'])): ?>
                                        <span class="fav-badge fav-badge-purple" style="font-size: 9px; margin-left: 4px;">🎬 Video</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($t['artist'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo $t['duration'] ?: '—'; ?></td>
                                <td><span class="fav-badge fav-badge-<?php echo strtolower($t['access']); ?>" style="font-size:9px;"><?php echo strtoupper($t['access']); ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

            <?php elseif ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::LOGIN_REQUIRED): ?>
                <div style="text-align: center; padding: 24px;">
                    <div style="font-size: 36px; margin-bottom: 8px;">🔒</div>
                    <h3>Sign in required</h3>
                    <p style="color: #94a3b8; font-size: 14px;">Sign in to unlock this audio playlist.</p>
                    <a href="/admin/login?redirect=<?php echo urlencode('/playlist/' . $playlist->slug); ?>" class="fav-btn-cta">Sign In</a>
                </div>
            <?php elseif ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::PREMIUM_REQUIRED): ?>
                <div style="text-align: center; padding: 24px;">
                    <div style="font-size: 36px; margin-bottom: 8px;">⭐</div>
                    <h3>Premium Playlist</h3>
                    <p style="color: #94a3b8; font-size: 14px;">This playlist requires a Premium pass from Favorite Digital.</p>
                    <a href="<?php echo \FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter::getSubscriptionUrl(); ?>" class="fav-btn-cta">Upgrade Membership</a>
                </div>
            <?php endif; ?>

            <?php
            $contentType = 'playlist';
            $contentId = (int)$playlist->id;
            include __DIR__ . '/_engagement_section.php';
            ?>
        </div>
            </div><!-- /.fm-content-main -->
            <?php if ($fmSidebarEnabled): ?>
                <?php include $componentsDir . '/sticky-sidebar.php'; ?>
            <?php endif; ?>
        </div><!-- /.fm-content-layout -->
    </div><!-- /.fav-mm-container -->

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/multimedia-audio.js"></script>
    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>

