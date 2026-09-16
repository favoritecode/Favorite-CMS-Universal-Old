<?php
/**
 * Favorite Multimedia — Episode Player View
 */
$playableSources = $playableSources ?? [];
$defaultSource = $defaultSource ?? ($sources[0] ?? null);

// Normalize sources array
$sourcesList = !empty($playableSources) ? $playableSources : array_map(function($s) {
    $embedInfo = \FavoriteCMS\Multimedia\Services\MediaSourceResolver::detectEmbedService($s->url_or_path);
    $pType = ($embedInfo !== null || $s->source_type === 'embed') ? 'embed' : (($s->source_type === 'hls') ? 'hls' : 'video');
    $playUrl = $embedInfo ? $embedInfo['embed_url'] : (($pType === 'embed') ? $s->url_or_path : "/multimedia/stream/{$s->id}");
    return [
        'id'                => (int)$s->id,
        'label'             => $s->label ?: 'Stream',
        'source_type'       => $s->source_type,
        'player_type'       => $pType,
        'url'               => $playUrl,
        'stream_url'        => "/multimedia/stream/{$s->id}",
        'mime_type'         => $s->mime_type ?: 'video/mp4',
        'is_default'        => !empty($s->is_default),
        'can_auto_failover' => ($pType !== 'embed'),
    ];
}, $sources);

$defaultItem = is_array($defaultSource) ? $defaultSource : ($sourcesList[0] ?? null);
$isHls = ($defaultItem && ($defaultItem['player_type'] ?? '') === 'hls');
$isEmbed = ($defaultItem && ($defaultItem['player_type'] ?? '') === 'embed');
$playableUrl = $defaultItem['url'] ?? '';
$playableMime = $defaultItem['mime_type'] ?? 'video/mp4';
$playableId = $defaultItem['id'] ?? 0;
$embedSandbox = array_key_exists('sandbox_policy', (array)$defaultItem) ? $defaultItem['sandbox_policy'] : \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedSandboxPolicy($playableUrl);
$embedAllow = $defaultItem['allow_attribute'] ?? \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedAllowAttribute($playableUrl);
$embedReferrer = $defaultItem['referrer_policy'] ?? \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedReferrerPolicy($playableUrl);
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
    $contentType = 'episode';
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
                <?php if ($series): ?>
                    <a href="/series/<?php echo htmlspecialchars($series->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="fm-back-link">&larr; <?php echo htmlspecialchars($series->title ?? 'Series', ENT_QUOTES, 'UTF-8'); ?></a>
                <?php else: ?>
                    <a href="/series" class="fm-back-link">&larr; Web Series</a>
                <?php endif; ?>
            <?php endif; ?>
            <h1 style="color: var(--fm-color-text-primary, #fff); font-size: 24px; margin: 6px 0 0 0;">
                <?php echo htmlspecialchars($season->title ?? 'Season', ENT_QUOTES, 'UTF-8'); ?> • Ep <?php echo (int)($episode->episode_number ?? 1); ?>: <?php echo htmlspecialchars($episode->title ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </h1>
        </div>

        <div class="<?= $fmLayoutClass ?>">
            <div class="fm-content-main">

        <!-- Resume Prompt Banner -->
        <?php if (!empty($progress['should_resume'])): ?>
            <div class="fmm-resume-banner" id="fmm-resume-prompt" style="max-width: 960px; margin: 0 auto 20px auto;">
                <div class="fmm-resume-info">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                    <span>You were watching this episode. Resume from <strong><?= htmlspecialchars($progress['formatted_position'] ?? '0:00') ?></strong> (<?= round($progress['percentage'] ?? 0) ?>%)?</span>
                </div>
                <div class="fmm-resume-actions">
                    <button type="button" class="fmm-btn fmm-btn-primary" id="fmm-resume-accept-btn" style="padding: 6px 14px; font-size: 13px;">Resume</button>
                    <button type="button" class="fmm-btn fmm-btn-secondary" id="fmm-resume-start-over-btn" style="padding: 6px 14px; font-size: 13px;">Start Over</button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Player Stage -->
        <div class="fm-player-stage" style="width: 100%; max-width: 100%; margin: 0 0 32px 0;">
            <div class="fav-video-wrapper" 
                 data-content-type="episode" 
                 data-content-id="<?php echo (int)$episode->id; ?>" 
                 data-auto-play-next="<?php echo htmlspecialchars(\FavoriteCMS\Models\Setting::get('multimedia', 'auto_play_next', 'yes'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-auto-next-countdown="<?php echo (int)\FavoriteCMS\Models\Setting::get('multimedia', 'auto_next_countdown', 5); ?>"
                 data-resume-position="<?php echo (!empty($progress['should_resume'])) ? (float)$progress['position'] : 0; ?>"
                 <?php if (!empty($nextEpisode)): ?>
                     <?php 
                     $nextEpId = (int)(is_array($nextEpisode) ? ($nextEpisode['id'] ?? 0) : ($nextEpisode->id ?? 0));
                     $nextEpTitle = (string)(is_array($nextEpisode) ? ($nextEpisode['title'] ?? '') : ($nextEpisode->title ?? ''));
                     $nextEpSlug = (string)(is_array($nextEpisode) ? ($nextEpisode['slug'] ?? '') : ($nextEpisode->slug ?? ''));
                     ?>
                     data-next-episode-id="<?php echo $nextEpId; ?>"
                     data-next-episode-title="<?php echo htmlspecialchars($nextEpTitle, ENT_QUOTES, 'UTF-8'); ?>"
                     data-next-episode-url="/episode/<?php echo htmlspecialchars($nextEpSlug, ENT_QUOTES, 'UTF-8'); ?>"
                 <?php endif; ?>
                 data-sources='<?php echo htmlspecialchars(json_encode($sourcesList), ENT_QUOTES, 'UTF-8'); ?>'
                 <?php if ($isHls && $accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::ALLOW): ?>data-hls-src="<?php echo htmlspecialchars($playableUrl, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                <?php if ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::ALLOW): ?>
                    <?php if (empty($sourcesList)): ?>
                        <div class="fav-restriction-card" style="background: var(--fm-color-bg-surface); border: 1px dashed var(--fm-color-border); padding: 48px 24px; text-align: center;">
                            <div class="fav-restriction-icon" style="font-size: 40px; margin-bottom: 12px;">🎞️</div>
                            <h2 class="fav-restriction-title" style="font-size: 20px; color: var(--fm-color-text-primary); margin-bottom: 8px;">Episode Stream Coming Soon</h2>
                            <p class="fav-restriction-msg" style="color: var(--fm-color-text-secondary); font-size: 14px; max-width: 440px; margin: 0 auto;">This episode is scheduled or currently being prepared. Video stream will be available soon.</p>
                        </div>
                    <?php else: ?>
                        <iframe src="<?php echo $isEmbed ? htmlspecialchars($playableUrl, ENT_QUOTES, 'UTF-8') : 'about:blank'; ?>" class="fav-embed-element" style="<?php echo $isEmbed ? '' : 'display:none;'; ?>" allow="<?php echo htmlspecialchars($embedAllow, ENT_QUOTES, 'UTF-8'); ?>" referrerpolicy="<?php echo htmlspecialchars($embedReferrer, ENT_QUOTES, 'UTF-8'); ?>" allowfullscreen <?php if ($embedSandbox !== null): ?>sandbox="<?php echo htmlspecialchars($embedSandbox, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>></iframe>

                        <!-- Universal Player Host: Video Element (for Direct Video & HLS) -->
                        <video class="fav-video-element" poster="<?php echo htmlspecialchars(($episode->thumbnail ?: ($series?->backdrop ?: ($series?->poster ?: ''))) ?? '', ENT_QUOTES, 'UTF-8'); ?>" preload="metadata" playsinline style="<?php echo $isEmbed ? 'display:none;' : ''; ?>">
                            <?php if (!$isHls && !$isEmbed && $playableUrl): ?>
                                <source src="<?php echo htmlspecialchars($playableUrl, ENT_QUOTES, 'UTF-8'); ?>" type="<?php echo htmlspecialchars($playableMime, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php endif; ?>
                            <?php foreach ($subtitles as $sub): ?>
                                <track kind="subtitles" label="<?php echo htmlspecialchars($sub->label, ENT_QUOTES, 'UTF-8'); ?>" srclang="<?php echo htmlspecialchars($sub->language, ENT_QUOTES, 'UTF-8'); ?>" src="/multimedia/subtitle/<?php echo $sub->id; ?>" <?php echo $sub->is_default ? 'default' : ''; ?>>
                            <?php endforeach; ?>
                        </video>

                        <!-- Controls -->
                        <div class="fav-player-controls" style="<?php echo $isEmbed ? 'display:none;' : ''; ?>">
                            <div class="fav-progress-bar-container">
                                <div class="fav-progress-filled">
                                    <span class="fav-progress-scrubber"></span>
                                </div>
                            </div>
                            <div class="fav-controls-row">
                                <div class="fav-controls-left">
                                    <button type="button" class="fav-btn-control fav-btn-play" aria-label="Play">
                                        <span class="fav-icon-play">&#9654;</span>
                                        <span class="fav-icon-pause" style="display:none;">&#10074;&#10074;</span>
                                    </button>
                                    <div class="fav-volume-wrapper">
                                        <button type="button" class="fav-btn-control fav-btn-volume">&#128266;</button>
                                        <input type="range" class="fav-volume-slider" min="0" max="1" step="0.05" value="1">
                                    </div>
                                    <div class="fav-time-display">0:00 / 0:00</div>
                                </div>
                                <div class="fav-controls-right">
                                    <?php if (count($sourcesList) > 1): ?>
                                        <select class="fav-control-select fav-source-select" title="Switch Source" aria-label="Switch Playback Source">
                                            <?php foreach ($sourcesList as $s): ?>
                                                <option value="<?php echo (int)$s['id']; ?>" <?php echo !empty($s['is_default']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <?php if (!empty($subtitles)): ?>
                                        <select class="fav-control-select fav-subtitle-select">
                                            <option value="off">CC Off</option>
                                            <?php foreach ($subtitles as $sub): ?>
                                                <option value="<?php echo htmlspecialchars($sub->language, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sub->label, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <select class="fav-control-select fav-speed-select">
                                        <option value="0.75">0.75x</option>
                                        <option value="1" selected>1x</option>
                                        <option value="1.25">1.25x</option>
                                        <option value="1.5">1.5x</option>
                                        <option value="2">2x</option>
                                    </select>

                                    <?php if (!empty($downloadInfo['allowed']) && !empty($downloadInfo['download_url'])): ?>
                                        <?php $dlOptions = $downloadInfo['options'] ?? []; ?>
                                        <?php if (count($dlOptions) <= 1): ?>
                                            <a href="<?php echo htmlspecialchars($downloadInfo['download_url'], ENT_QUOTES, 'UTF-8'); ?>" class="fav-btn-download">
                                                &#8595; Download
                                            </a>
                                        <?php else: ?>
                                            <div class="fav-download-dropdown">
                                                <button type="button" class="fav-btn-download" onclick="this.nextElementSibling.classList.toggle('fav-show'); event.stopPropagation();" title="Download Options">
                                                    &#8595; Download
                                                </button>
                                                <div class="fav-download-dropdown-menu" style="bottom: 100%; top: auto; margin-bottom: 8px; margin-top: 0;">
                                                    <div style="padding: 8px 12px; background: var(--fm-color-bg-elevated); border-bottom: 1px solid var(--fm-color-border); font-size: 11px; font-weight: 700; color: var(--fm-color-text-muted);">Download Options (<?= count($dlOptions) ?>)</div>
                                                    <?php foreach ($dlOptions as $opt): ?>
                                                        <a href="<?php echo htmlspecialchars($opt['url'], ENT_QUOTES, 'UTF-8'); ?>" class="fav-download-item">
                                                            <div class="fav-download-item-title"><?php echo htmlspecialchars($opt['display_title'] ?? $opt['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                            <?php if (!empty($opt['meta'])): ?><div class="fav-download-item-meta"><?php echo htmlspecialchars($opt['meta'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <button type="button" class="fav-btn-control fav-btn-fullscreen">&#x26F6;</button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php elseif ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::LOGIN_REQUIRED): ?>
                    <div class="fav-restriction-card">
                        <div class="fav-restriction-icon">🔒</div>
                        <h2 class="fav-restriction-title">Member Login Required</h2>
                        <p class="fav-restriction-msg">Please sign in to stream this episode.</p>
                        <a href="/admin/login?redirect=<?php echo urlencode('/episode/' . $episode->slug); ?>" class="fav-btn-cta">Sign In</a>
                    </div>
                <?php elseif ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::PREMIUM_REQUIRED): ?>
                    <div class="fav-restriction-card">
                        <div class="fav-restriction-icon">⭐</div>
                        <h2 class="fav-restriction-title">Premium Access Required</h2>
                        <p class="fav-restriction-msg">This episode is part of a premium series. Upgrade via Favorite Digital to enjoy unlimited access.</p>
                        <a href="<?php echo \FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter::getSubscriptionUrl(); ?>" class="fav-btn-cta">Unlock with Premium</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php 
            $sidebarHasDownload = $fmSidebarEnabled && !empty($singleCfg['blocks']['download']);
            if (!$sidebarHasDownload && !empty($downloadInfo['allowed']) && !empty($downloadInfo['download_url'])): 
            ?>
                <!-- Download Button (deduplicated: rendered only if sidebar is not showing download) -->
                <?php $dlOptions = $downloadInfo['options'] ?? []; ?>
                <div style="margin-top: 12px; display: flex; justify-content: flex-end; position: relative;">
                    <?php if (count($dlOptions) <= 1): ?>
                        <a href="<?php echo htmlspecialchars($downloadInfo['download_url'], ENT_QUOTES, 'UTF-8'); ?>" class="fav-btn-download" style="padding: 8px 16px; font-size: 13px;">
                            &#8595; Download Episode<?php if (!empty($downloadInfo['download_label'])): ?> (<?php echo htmlspecialchars($downloadInfo['download_label'], ENT_QUOTES, 'UTF-8'); ?>)<?php elseif ($defaultSource && !empty($defaultSource->label)): ?> (<?php echo htmlspecialchars($defaultSource->label, ENT_QUOTES, 'UTF-8'); ?>)<?php else: ?> File<?php endif; ?>
                        </a>
                    <?php else: ?>
                        <div class="fav-download-dropdown">
                            <button type="button" class="fav-btn-download fav-download-dropdown-btn" onclick="this.nextElementSibling.classList.toggle('fav-show'); event.stopPropagation();" style="padding: 8px 16px; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                <span>&#8595; Download</span>
                                <span style="font-size: 10px;">▼</span>
                            </button>
                            <div class="fav-download-dropdown-menu">
                                <div style="padding: 8px 12px; background: var(--fm-color-bg-elevated); border-bottom: 1px solid var(--fm-color-border); font-size: 11px; font-weight: 700; color: var(--fm-color-text-muted); text-transform: uppercase;">Download Options (<?= count($dlOptions) ?>)</div>
                                <?php foreach ($dlOptions as $opt): ?>
                                    <a href="<?php echo htmlspecialchars($opt['url'], ENT_QUOTES, 'UTF-8'); ?>" class="fav-download-item">
                                        <div class="fav-download-item-title"><?php echo htmlspecialchars($opt['display_title'] ?? $opt['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php if (!empty($opt['meta'])): ?>
                                            <div class="fav-download-item-meta"><?php echo htmlspecialchars($opt['meta'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Episode Details -->
        <div style="width: 100%; margin: 0 auto; background: var(--fm-color-bg-surface); border: var(--fm-border-strength, 1px) solid var(--fm-color-border); border-radius: var(--fm-radius-card, 8px); padding: 24px; box-sizing: border-box;">
            <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
                <span class="fav-badge fav-badge-<?php echo strtolower($episode->getResolvedAccessMode()); ?>"><?php echo strtoupper($episode->getResolvedAccessMode()); ?></span>
                <?php if ($episode->duration): ?>
                    <span style="color: var(--fm-color-text-muted); font-size: 13px;"><?php echo round($episode->duration / 60); ?> minutes</span>
                <?php endif; ?>

                <?php if (!empty($nextEpisode)): ?>
                    <?php 
                    $nextEpSlug = (string)(is_array($nextEpisode) ? ($nextEpisode['slug'] ?? '') : ($nextEpisode->slug ?? ''));
                    $nextEpNum = (int)(is_array($nextEpisode) ? ($nextEpisode['episode_number'] ?? 1) : ($nextEpisode->episode_number ?? 1));
                    ?>
                    <a href="/episode/<?php echo htmlspecialchars($nextEpSlug, ENT_QUOTES, 'UTF-8'); ?>" class="fmm-btn fmm-btn-primary" style="margin-left: auto; font-size: 13px; padding: 6px 14px;">
                        Next: Ep <?php echo $nextEpNum; ?> &rarr;
                    </a>
                <?php endif; ?>

                <?php if (!empty($user)): ?>
                    <button type="button" class="fav-btn-favorite <?= !empty($isFavorite) ? 'active' : '' ?>" data-fmm-fav-toggle data-content-type="episode" data-content-id="<?= (int)$episode->id ?>" style="<?= empty($nextEpisode) ? 'margin-left: auto;' : '' ?>">
                        <span class="fav-icon-star">★</span>
                        <span class="fav-btn-label"><?= !empty($isFavorite) ? 'In My List' : 'Add to My List' ?></span>
                    </button>
                <?php endif; ?>
            </div>
            <?php if (!empty($episode->description)): ?>
                <div style="line-height: 1.6; color: var(--fm-color-text-secondary);">
                    <?php echo nl2br(htmlspecialchars($episode->description, ENT_QUOTES, 'UTF-8')); ?>
                </div>
            <?php endif; ?>

            <?php
            $contentType = 'episode';
            $contentId = (int)$episode->id;
            include __DIR__ . '/_engagement_section.php';
            ?>
        </div>
            </div><!-- /.fm-content-main -->
            <?php if ($fmSidebarEnabled): ?>
                <?php include $componentsDir . '/sticky-sidebar.php'; ?>
            <?php endif; ?>
        </div><!-- /.fm-content-layout -->
    </div><!-- /.fav-mm-container -->

    <?php include $componentsDir . '/mobile-nav.php'; ?>
    <script src="/plugins/favorite-multimedia/assets/js/multimedia-player.js"></script>
    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>

