<?php
/**
 * Favorite Multimedia — Standalone Player Embed View
 */
$isHls = ($source->source_type === 'hls');
$isEmbed = ($source->source_type === 'embed');
$embedSandbox = \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedSandboxPolicy((string)($source->url_or_path ?? ''));
$embedAllow = \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedAllowAttribute((string)($source->url_or_path ?? ''));
$embedReferrer = \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedReferrerPolicy((string)($source->url_or_path ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Player</title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background: #000;
            overflow: hidden;
        }
        .fav-video-wrapper {
            height: 100vh;
            border-radius: 0;
            box-shadow: none;
        }
    </style>
</head>
<body>

    <div class="fav-video-wrapper" <?php if ($isHls && $accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::ALLOW): ?>data-hls-src="/multimedia/stream/<?php echo $source->id; ?>"<?php endif; ?>>
        <?php if ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::ALLOW): ?>
            <?php if ($isEmbed): ?>
                <iframe src="<?php echo htmlspecialchars($source->url_or_path, ENT_QUOTES, 'UTF-8'); ?>" class="fav-embed-element" allow="<?php echo htmlspecialchars($embedAllow, ENT_QUOTES, 'UTF-8'); ?>" referrerpolicy="<?php echo htmlspecialchars($embedReferrer, ENT_QUOTES, 'UTF-8'); ?>" allowfullscreen <?php if ($embedSandbox !== null): ?>sandbox="<?php echo htmlspecialchars($embedSandbox, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>></iframe>
            <?php else: ?>
                <?php
                    $subtitles = \FavoriteCMS\Multimedia\Models\Subtitle::getForContent((string)$source->content_type, (int)$source->content_id);
                    $audioTracks = \FavoriteCMS\Multimedia\Models\MediaSource::getAudioTracksForContent((string)$source->content_type, (int)$source->content_id, true);
                ?>
                <video class="fav-video-element" poster="<?php echo htmlspecialchars($source->poster ?? '', ENT_QUOTES, 'UTF-8'); ?>" preload="metadata" playsinline>
                    <?php if (!$isHls): ?>
                        <source src="/multimedia/stream/<?php echo $source->id; ?>" type="<?php echo htmlspecialchars($source->mime_type ?: 'video/mp4', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <?php foreach ($subtitles as $sub): ?>
                        <track kind="subtitles"
                               src="/multimedia/subtitle/<?php echo $sub->id; ?>"
                               srclang="<?php echo htmlspecialchars($sub->getLanguageCode(), ENT_QUOTES, 'UTF-8'); ?>"
                               label="<?php echo htmlspecialchars($sub->label, ENT_QUOTES, 'UTF-8'); ?>"
                               <?php if ($sub->is_default): ?>default<?php endif; ?>>
                    <?php endforeach; ?>
                </video>

                <div class="fav-player-controls">
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
                            <?php if (!empty($subtitles)): ?>
                                <select class="fav-control-select fav-subtitle-select" aria-label="Subtitles">
                                    <option value="off">CC Off</option>
                                    <?php foreach ($subtitles as $sub): ?>
                                        <option value="<?php echo htmlspecialchars($sub->getLanguageCode(), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $sub->is_default ? 'selected' : ''; ?>>
                                            💬 <?php echo htmlspecialchars($sub->label, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <?php if (count($audioTracks) > 1): ?>
                                <select class="fav-control-select fav-audio-select" aria-label="Audio Track">
                                    <?php foreach ($audioTracks as $at): ?>
                                        <option value="<?php echo htmlspecialchars($at->language_code ?? 'en', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $at->is_default ? 'selected' : ''; ?>>
                                            🔊 <?php echo htmlspecialchars($at->label ?: $at->language_code, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
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
                                    <a href="<?php echo htmlspecialchars($downloadInfo['download_url'], ENT_QUOTES, 'UTF-8'); ?>" class="fav-btn-download" target="_blank">&#8595; Download</a>
                                <?php else: ?>
                                    <div class="fav-download-dropdown">
                                        <button type="button" class="fav-btn-download" onclick="this.nextElementSibling.classList.toggle('fav-show'); event.stopPropagation();" title="Download Options">&#8595; Download</button>
                                        <div class="fav-download-dropdown-menu" style="bottom: 100%; top: auto; margin-bottom: 8px;">
                                            <div style="padding: 6px 10px; background: #1e293b; border-bottom: 1px solid #334155; font-size: 11px; font-weight: 700; color: #94a3b8;">Download Options</div>
                                            <?php foreach ($dlOptions as $opt): ?>
                                                <a href="<?php echo htmlspecialchars($opt['url'], ENT_QUOTES, 'UTF-8'); ?>" class="fav-download-item" target="_blank">
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
                <h3 class="fav-restriction-title">Sign in Required</h3>
                <p class="fav-restriction-msg">Authentication is required to stream this media.</p>
                <a href="/admin/login" target="_blank" class="fav-btn-cta">Sign In</a>
            </div>
        <?php elseif ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::PREMIUM_REQUIRED): ?>
            <div class="fav-restriction-card">
                <div class="fav-restriction-icon">⭐</div>
                <h3 class="fav-restriction-title">Premium Access Required</h3>
                <p class="fav-restriction-msg">Unlock this content with a Favorite Digital membership pass.</p>
                <a href="<?php echo \FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter::getSubscriptionUrl(); ?>" target="_blank" class="fav-btn-cta">Get Premium</a>
            </div>
        <?php endif; ?>
    </div>

    <script src="/plugins/favorite-multimedia/assets/js/multimedia-player.js"></script>
</body>
</html>

