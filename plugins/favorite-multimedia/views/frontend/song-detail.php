<?php
/**
 * Favorite Multimedia — Song Detail, Audio & Music Video Player View
 */
$hasAudio = !empty($hasAudio);
$hasVideo = !empty($hasVideo);
$isDualMode = ($hasAudio && $hasVideo);

$defaultVideo = $defaultVideoSource ?? ($videoSources[0] ?? null);
$videoIsHls = ($defaultVideo && ($defaultVideo['player_type'] ?? '') === 'hls');
$videoIsEmbed = ($defaultVideo && ($defaultVideo['player_type'] ?? '') === 'embed');
$videoPlayableUrl = $defaultVideo['url'] ?? '';
$videoPlayableMime = $defaultVideo['mime_type'] ?? 'video/mp4';
$videoEmbedSandbox = array_key_exists('sandbox_policy', (array)$defaultVideo) ? $defaultVideo['sandbox_policy'] : \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedSandboxPolicy($videoPlayableUrl);
$videoEmbedAllow = $defaultVideo['allow_attribute'] ?? \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedAllowAttribute($videoPlayableUrl);
$videoEmbedReferrer = $defaultVideo['referrer_policy'] ?? \FavoriteCMS\Multimedia\Services\MediaSourceResolver::getEmbedReferrerPolicy($videoPlayableUrl);

// Determine initial playback mode
if ($isDualMode) {
    $initialMode = ($defaultPlaybackMode === 'video') ? 'video' : 'audio';
} elseif ($hasVideo) {
    $initialMode = 'video';
} elseif ($hasAudio) {
    $initialMode = 'audio';
} else {
    $initialMode = 'none';
}
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
    $currentNav = 'music';
    $contentType = 'song';
    include $componentsDir . '/content-layout.php';
    echo $themeManager->renderHeadTokens(); 
    ?>
    <style>
        .fav-mode-pill {
            background: var(--fm-color-bg-surface, #1e293b);
            color: var(--fm-color-text-secondary, #94a3b8);
            border: 1px solid var(--fm-color-border, #334155);
            padding: 8px 20px;
            border-radius: 9999px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .fav-mode-pill:hover {
            color: var(--fm-color-text-primary, #f8fafc);
            border-color: var(--fm-color-primary, #e50914);
        }
        .fav-mode-pill.active {
            background: var(--fm-color-primary, #2563eb);
            color: #ffffff;
            border-color: var(--fm-color-primary, #3b82f6);
            box-shadow: 0 2px 10px rgba(229, 9, 20, 0.4);
        }
    </style>
</head>
<body class="fm-theme-body" style="margin: 0; padding: 0;">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <div class="fav-mm-container" style="padding-top: 24px;">
        <div style="margin-bottom: 16px;">
            <?php if ($fmShowBackLink): ?>
                <a href="/multimedia/music" class="fm-back-link">&larr; Back to Music</a>
            <?php endif; ?>
            <h1 style="color: var(--fm-color-text-primary); font-size: 28px; margin: 6px 0 0 0;"><?php echo htmlspecialchars($song->title, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>

        <div class="<?= $fmLayoutClass ?>">
            <div class="fm-content-main">

        <!-- Access Control Gating (Fail-Closed) -->
        <?php if ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::LOGIN_REQUIRED): ?>
            <div class="fav-restriction-card" style="max-width: 680px; margin: 20px auto 40px auto; background: #1e293b; border-radius: 8px; padding: 36px 20px; text-align: center;">
                <div style="font-size: 40px; margin-bottom: 12px;">🔒</div>
                <h3 style="font-size: 20px; color: #fff; margin-bottom: 8px;">Login Required</h3>
                <p style="color: #94a3b8; font-size: 14px; max-width: 420px; margin: 0 auto 20px auto;">Sign in to unlock full audio and music video playback for this track.</p>
                <a href="/admin/login?redirect=<?php echo urlencode('/song/' . $song->slug); ?>" class="fav-btn-cta" style="display: inline-block;">Sign In to Listen</a>
            </div>
        <?php elseif ($accessState === \FavoriteCMS\Multimedia\Services\MultimediaAccessService::PREMIUM_REQUIRED): ?>
            <div class="fav-restriction-card" style="max-width: 680px; margin: 20px auto 40px auto; background: #1e293b; border-radius: 8px; padding: 36px 20px; text-align: center;">
                <div style="font-size: 40px; margin-bottom: 12px;">⭐</div>
                <h3 style="font-size: 20px; color: #fff; margin-bottom: 8px;">Premium Membership Required</h3>
                <p style="color: #94a3b8; font-size: 14px; max-width: 420px; margin: 0 auto 20px auto;">Upgrade with Favorite Digital to stream this exclusive track and its official music video.</p>
                <a href="<?php echo \FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter::getSubscriptionUrl(); ?>" class="fav-btn-cta" style="display: inline-block;">Upgrade Membership</a>
            </div>
        <?php elseif ($initialMode === 'none'): ?>
            <div class="fav-restriction-card" style="max-width: 680px; margin: 20px auto 40px auto; background: #1e293b; border: 1px dashed #334155; border-radius: 8px; padding: 36px 20px; text-align: center;">
                <div style="font-size: 40px; margin-bottom: 12px;">🎵</div>
                <h3 style="font-size: 20px; color: #fff; margin-bottom: 8px;">Media Coming Soon</h3>
                <p style="color: #94a3b8; font-size: 14px; margin: 0;">Playback sources for this track are currently being processed or prepared.</p>
            </div>
        <?php else: ?>

            <!-- Dual-Mode Toggle Pills (Rendered ONLY when BOTH Audio and Video exist) -->
            <?php if ($isDualMode): ?>
                <div class="fav-mode-switch-wrapper" style="display: flex; justify-content: center; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                    <button type="button" class="fav-mode-pill <?php echo ($initialMode === 'audio') ? 'active' : ''; ?>" id="fav_mode_pill_audio" onclick="switchSongPlaybackMode('audio')">
                        🎵 Audio Track
                    </button>
                    <button type="button" class="fav-mode-pill <?php echo ($initialMode === 'video') ? 'active' : ''; ?>" id="fav_mode_pill_video" onclick="switchSongPlaybackMode('video')">
                        🎬 Music Video
                    </button>
                    <?php if (\FavoriteCMS\Models\Setting::get('multimedia', 'enable_background_audio', 'yes') === 'yes' && $hasAudio && $defaultAudioSource): ?>
                        <button type="button" class="fav-mode-pill" id="fav_mode_pill_bg_audio" onclick="playSongInBackground(<?php echo (int)$song->id; ?>)" title="Play in persistent background audio player">
                            🎧 Background Audio
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- PLAYER CONTAINER 1: Audio Player Card -->
            <div id="fav_song_audio_container" class="fav-audio-player-box" style="margin-bottom: 32px; <?php echo ($initialMode !== 'audio') ? 'display:none;' : ''; ?>">
                <?php if ($hasAudio && $defaultAudioSource): ?>
                    <audio preload="metadata" style="display: none;"></audio>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div class="fav-audio-now-playing" style="margin-bottom: 0;">
                            <?php if ($song->cover): ?>
                                <img src="<?php echo htmlspecialchars($song->cover, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="fav-audio-artwork">
                            <?php else: ?>
                                <div class="fav-audio-artwork" style="display: flex; align-items: center; justify-content: center; font-size: 32px;">🎵</div>
                            <?php endif; ?>
                            <div class="fav-audio-info">
                                <h3 class="fav-audio-title"><?php echo htmlspecialchars($song->title, ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p class="fav-audio-artist"><?php echo htmlspecialchars($song->getArtist()?->name ?: 'Unknown Artist', ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>

                        <!-- Audio Source Switcher (Visible if 2+ audio sources exist) -->
                        <?php if (count($audioSources) > 1): ?>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <label for="fav_audio_source_select" style="font-size: 11px; color: #94a3b8; font-weight: 500;">Server:</label>
                                <select class="fav-control-select" id="fav_audio_source_select" onchange="switchSongAudioSource(this.value)" style="background:#1e293b; color:#fff; border:1px solid #334155; padding:4px 8px; border-radius:4px; font-size:12px;">
                                    <?php foreach ($audioSources as $as): ?>
                                        <option value="<?php echo (int)$as['id']; ?>" data-url="<?php echo htmlspecialchars($as['url'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo !empty($as['is_default']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($as['label'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Progress Bar -->
                    <div class="fav-progress-bar-container" style="background: rgba(255,255,255,0.15); height: 6px; border-radius: 3px; cursor: pointer;">
                        <div class="fav-progress-filled" style="background: #2563eb; height: 100%; width: 0%;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 12px; color: #94a3b8; margin-top: 6px;">
                        <span class="fav-time-display">0:00 / <?php echo $song->getDurationFormatted(); ?></span>
                        <?php if (!empty($downloadInfo['allowed']) && !empty($downloadInfo['download_url'])): ?>
                            <?php $dlOptions = $downloadInfo['options'] ?? []; ?>
                            <?php if (count($dlOptions) <= 1): ?>
                                <a href="<?php echo htmlspecialchars($downloadInfo['download_url'], ENT_QUOTES, 'UTF-8'); ?>" class="fav-btn-download" style="padding: 2px 8px; font-size: 11px;">
                                    &#8595; Download <?php echo !empty($downloadInfo['download_label']) ? htmlspecialchars($downloadInfo['download_label'], ENT_QUOTES, 'UTF-8') : 'Audio'; ?>
                                </a>
                            <?php else: ?>
                                <div class="fav-download-dropdown" style="display: inline-block;">
                                    <button type="button" class="fav-btn-download" onclick="this.nextElementSibling.classList.toggle('fav-show'); event.stopPropagation();" style="padding: 2px 8px; font-size: 11px; cursor: pointer;">
                                        &#8595; Download &#9662;
                                    </button>
                                    <div class="fav-download-dropdown-menu" style="bottom: 100%; top: auto; margin-bottom: 6px;">
                                        <div style="padding: 6px 10px; background: #1e293b; border-bottom: 1px solid #334155; font-size: 11px; font-weight: 700; color: #94a3b8;">Download Options (<?= count($dlOptions) ?>)</div>
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
                    </div>

                    <!-- Audio Controls -->
                    <div class="fav-audio-controls-row" style="display:flex; justify-content:center; align-items:center; gap:16px;">
                        <button type="button" class="fav-audio-btn-big fav-audio-btn-play" aria-label="Play">&#9654;</button>
                        <?php if (!empty($user)): ?>
                            <button type="button" class="fav-btn-favorite <?= !empty($isFavorite) ? 'active' : '' ?>" data-fmm-fav-toggle data-content-type="song" data-content-id="<?= (int)$song->id ?>">
                                <span class="fav-icon-star">★</span>
                                <span class="fav-btn-label"><?= !empty($isFavorite) ? 'In My List' : 'Add to My List' ?></span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Hidden data row for single track player init -->
                    <table style="display: none;">
                        <tr class="fav-playlist-row" 
                            data-song-id="<?php echo (int)$song->id; ?>"
                            data-stream-url="<?php echo htmlspecialchars($defaultAudioSource['url'], ENT_QUOTES, 'UTF-8'); ?>" 
                            data-title="<?php echo htmlspecialchars($song->title, ENT_QUOTES, 'UTF-8'); ?>" 
                            data-artist="<?php echo htmlspecialchars($song->getArtist()?->name ?: 'Unknown Artist', ENT_QUOTES, 'UTF-8'); ?>" 
                            data-cover="<?php echo htmlspecialchars($song->cover ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-can-download="<?php echo (!empty($downloadInfo['allowed']) && !empty($downloadInfo['download_url'])) ? '1' : '0'; ?>"
                            data-download-url="<?php echo htmlspecialchars($downloadInfo['download_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </tr>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; color: #94a3b8; margin: 20px 0;">Audio track not available.</p>
                <?php endif; ?>
            </div>

            <!-- PLAYER CONTAINER 2: Video / Music Video Player Stage -->
            <div id="fav_song_video_container" style="max-width: 960px; margin: 0 auto 32px auto; <?php echo ($initialMode !== 'video') ? 'display:none;' : ''; ?>">
                <?php if ($hasVideo && !empty($videoSources)): ?>
                    <div class="fav-video-wrapper" 
                         data-content-type="song" 
                         data-content-id="<?php echo (int)$song->id; ?>" 
                         data-resume-position="<?php echo (!empty($progress['should_resume'])) ? (float)$progress['position'] : 0; ?>"
                         data-sources='<?php echo htmlspecialchars(json_encode($videoSources), ENT_QUOTES, 'UTF-8'); ?>'
                         <?php if ($videoIsHls): ?>data-hls-src="<?php echo htmlspecialchars($videoPlayableUrl, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                        
                        <iframe src="<?php echo $videoIsEmbed ? htmlspecialchars($videoPlayableUrl, ENT_QUOTES, 'UTF-8') : 'about:blank'; ?>" class="fav-embed-element" style="<?php echo $videoIsEmbed ? '' : 'display:none;'; ?>" allow="<?php echo htmlspecialchars($videoEmbedAllow, ENT_QUOTES, 'UTF-8'); ?>" referrerpolicy="<?php echo htmlspecialchars($videoEmbedReferrer, ENT_QUOTES, 'UTF-8'); ?>" allowfullscreen <?php if ($videoEmbedSandbox !== null): ?>sandbox="<?php echo htmlspecialchars($videoEmbedSandbox, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>></iframe>

                        <!-- Universal Player Host: Video Element (for Direct Video & HLS) -->
                        <video class="fav-video-element" poster="<?php echo htmlspecialchars($song->cover ?? '', ENT_QUOTES, 'UTF-8'); ?>" preload="metadata" playsinline style="<?php echo $videoIsEmbed ? 'display:none;' : ''; ?>">
                            <?php if (!$videoIsHls && !$videoIsEmbed && $videoPlayableUrl): ?>
                                <source src="<?php echo htmlspecialchars($videoPlayableUrl, ENT_QUOTES, 'UTF-8'); ?>" type="<?php echo htmlspecialchars($videoPlayableMime, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php endif; ?>
                            <?php foreach ($subtitles as $sub): ?>
                                <track kind="subtitles" label="<?php echo htmlspecialchars($sub->label, ENT_QUOTES, 'UTF-8'); ?>" srclang="<?php echo htmlspecialchars($sub->language, ENT_QUOTES, 'UTF-8'); ?>" src="/multimedia/subtitle/<?php echo $sub->id; ?>" <?php echo $sub->is_default ? 'default' : ''; ?>>
                            <?php endforeach; ?>
                        </video>

                        <!-- Video Controls Overlay -->
                        <div class="fav-player-controls" style="<?php echo $videoIsEmbed ? 'display:none;' : ''; ?>">
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
                                        <button type="button" class="fav-btn-control fav-btn-volume" aria-label="Volume">&#128266;</button>
                                        <input type="range" class="fav-volume-slider" min="0" max="1" step="0.05" value="1">
                                    </div>
                                    <div class="fav-time-display">0:00 / 0:00</div>
                                </div>
                                <div class="fav-controls-right">
                                    <?php if (count($videoSources) > 1): ?>
                                        <select class="fav-control-select fav-source-select" title="Switch Video Source" aria-label="Switch Playback Source">
                                            <?php foreach ($videoSources as $vs): ?>
                                                <option value="<?php echo (int)$vs['id']; ?>" <?php echo !empty($vs['is_default']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($vs['label'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <?php if (!empty($subtitles)): ?>
                                        <select class="fav-control-select fav-subtitle-select" title="Subtitles">
                                            <option value="off">CC Off</option>
                                            <?php foreach ($subtitles as $sub): ?>
                                                <option value="<?php echo htmlspecialchars($sub->language, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $sub->is_default ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($sub->label, ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <select class="fav-control-select fav-speed-select" title="Playback Speed">
                                        <option value="0.5">0.5x</option>
                                        <option value="0.75">0.75x</option>
                                        <option value="1" selected>1x</option>
                                        <option value="1.25">1.25x</option>
                                        <option value="1.5">1.5x</option>
                                        <option value="2">2x</option>
                                    </select>
                                    <button type="button" class="fav-btn-control fav-btn-fullscreen" aria-label="Fullscreen">&#x26F6;</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: #94a3b8; margin: 20px 0;">Music video stream not available.</p>
                <?php endif; ?>
            </div>

        <?php endif; ?>

        <!-- Lyrics & Liner Notes -->
        <?php if (!empty($song->lyrics)): ?>
            <div style="max-width: 680px; margin: 0 auto; background: #1e293b; border-radius: 8px; padding: 24px;">
                <h3 style="margin-top: 0; font-size: 16px; color: #fff;">Lyrics</h3>
                <div style="white-space: pre-wrap; line-height: 1.8; color: #cbd5e1; font-size: 14px;">
                    <?php echo htmlspecialchars($song->lyrics, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Community Engagement Section (Unified Song Identity) -->
        <div class="fm-song-engagement-wrap" style="width: 100%; margin: 0;">
            <?php
            $contentType = 'song';
            $contentId = (int)$song->id;
            include __DIR__ . '/_engagement_section.php';
            ?>
        </div>

        <?php if (!empty($relatedSongs)): ?>
            <div style="margin: 40px auto 0 auto; border-top: 1px solid var(--fm-color-border); padding-top: 24px;">
                <h3 style="font-size: 20px; font-weight: 700; color: var(--fm-color-text-primary); margin: 0 0 16px 0;">🎵 Related Tracks You May Like</h3>
                <div class="fav-mm-grid">
                    <?php foreach ($relatedSongs as $item): ?>
                        <?php include __DIR__ . '/_discovery_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($relatedPlaylists)): ?>
            <div style="margin: 40px auto 0 auto;">
                <h3 style="font-size: 20px; font-weight: 700; color: var(--fm-color-text-primary); margin: 0 0 16px 0;">🎼 Playlists Featuring This Track</h3>
                <div class="fav-mm-grid">
                    <?php foreach ($relatedPlaylists as $item): ?>
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

    <!-- Scripts -->
    <?php if ($hasVideo): ?>
        <script src="/plugins/favorite-multimedia/assets/js/multimedia-player.js"></script>
    <?php endif; ?>
    <script src="/plugins/favorite-multimedia/assets/js/multimedia-audio.js"></script>
    <script>
        function switchSongPlaybackMode(mode) {
            const audioContainer = document.getElementById('fav_song_audio_container');
            const videoContainer = document.getElementById('fav_song_video_container');
            const audioPill = document.getElementById('fav_mode_pill_audio');
            const videoPill = document.getElementById('fav_mode_pill_video');

            if (mode === 'audio') {
                if (videoContainer) {
                    videoContainer.style.display = 'none';
                    const vid = videoContainer.querySelector('video');
                    if (vid) vid.pause();
                }
                if (audioContainer) {
                    audioContainer.style.display = 'block';
                }
                if (audioPill) audioPill.classList.add('active');
                if (videoPill) videoPill.classList.remove('active');
            } else if (mode === 'video') {
                if (audioContainer) {
                    audioContainer.style.display = 'none';
                    const aud = audioContainer.querySelector('audio');
                    if (aud) aud.pause();
                    const playBtn = audioContainer.querySelector('.fav-audio-btn-play');
                    if (playBtn) playBtn.innerHTML = '&#9654;';
                }
                if (window.__FM_GLOBAL_AUDIO_PLAYER_INSTANCE__) {
                    window.__FM_GLOBAL_AUDIO_PLAYER_INSTANCE__.pause();
                }
                if (videoContainer) {
                    videoContainer.style.display = 'block';
                }
                if (videoPill) videoPill.classList.add('active');
                if (audioPill) audioPill.classList.remove('active');
            }
        }

        function playSongInBackground(songId) {
            const videoContainer = document.getElementById('fav_song_video_container');
            let currentPos = 0;
            if (videoContainer) {
                const vid = videoContainer.querySelector('video');
                if (vid) {
                    currentPos = vid.currentTime || 0;
                    vid.pause();
                }
            }
            const audioContainer = document.getElementById('fav_song_audio_container');
            if (audioContainer) {
                const aud = audioContainer.querySelector('audio');
                if (aud) aud.pause();
            }
            if (window.__FM_GLOBAL_AUDIO_PLAYER_INSTANCE__) {
                window.__FM_GLOBAL_AUDIO_PLAYER_INSTANCE__.resolveAndPlay(songId, currentPos, true);
            } else if (window.FavoriteAudioPlayer && typeof window.FavoriteAudioPlayer.playSong === 'function') {
                window.FavoriteAudioPlayer.playSong(songId, { position: currentPos, autoPlay: true });
            } else {
                switchSongPlaybackMode('audio');
            }
        }

        function switchSongAudioSource(sourceId) {
            const sel = document.getElementById('fav_audio_source_select');
            if (!sel) return;
            const opt = sel.options[sel.selectedIndex];
            const newUrl = opt ? opt.getAttribute('data-url') : '';
            const audioBox = document.querySelector('.fav-audio-player-box');
            if (audioBox && newUrl) {
                const audio = audioBox.querySelector('audio');
                const playBtn = audioBox.querySelector('.fav-audio-btn-play');
                if (audio) {
                    const wasPlaying = !audio.paused;
                    audio.src = newUrl;
                    if (wasPlaying) {
                        audio.play().catch(() => {});
                        if (playBtn) playBtn.innerHTML = '&#10074;&#10074;';
                    }
                }
            }
        }
    </script>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>
