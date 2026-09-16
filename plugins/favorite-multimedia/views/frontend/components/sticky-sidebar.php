<?php
/**
 * Favorite Multimedia — Reusable Sticky Sidebar Component for Single Content
 * 
 * Supports: Movie, Series, Episode, Song, Album, Artist, Playlist.
 * Configurable via Theme Studio: single_content
 *
 * @var string $contentType (movie, series, episode, song, album, artist, playlist)
 * @var object|null $movie
 * @var object|null $series
 * @var object|null $episode
 * @var object|null $song
 * @var object|null $album
 * @var object|null $artist
 * @var object|null $playlist
 * @var array|null $downloadInfo
 * @var object|null $user
 * @var bool|null $isFavorite
 * @var bool|null $isFollowing
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$themeConfig = $themeManager->getActiveConfig();
$singleCfg = $themeConfig->get('single_content', null, []);

$contentType = $contentType ?? 'movie';
$isGlobalEnabled = $singleCfg['sidebar_enabled'] ?? true;
$isTypeEnabled = $singleCfg['enabled_types'][$contentType] ?? true;

if (!$isGlobalEnabled || !$isTypeEnabled) {
    return;
}

$sidebarPos = $singleCfg['sidebar_position'] ?? 'right';
$isSticky = !empty($singleCfg['sidebar_sticky']);
$surface = $singleCfg['sidebar_surface'] ?? 'surface';
$blocksCfg = $singleCfg['blocks'] ?? [
    'poster' => true,
    'actions' => true,
    'metadata' => true,
    'download' => true,
];
$width = $singleCfg['sidebar_width'] ?? '320px';
$offset = $singleCfg['sidebar_sticky_offset'] ?? '20px';

$sidebarClass = 'fm-sticky-sidebar surface-' . htmlspecialchars($surface, ENT_QUOTES, 'UTF-8');
if ($isSticky) {
    $sidebarClass .= ' is-sticky';
}
$sidebarClass .= ' pos-' . htmlspecialchars($sidebarPos, ENT_QUOTES, 'UTF-8');
?>
<aside class="<?= $sidebarClass ?>" data-fm-sidebar data-content-type="<?= htmlspecialchars($contentType, ENT_QUOTES, 'UTF-8') ?>" style="--fm-sidebar-width: <?= htmlspecialchars($width, ENT_QUOTES, 'UTF-8') ?>; --fm-sidebar-sticky-offset: <?= htmlspecialchars($offset, ENT_QUOTES, 'UTF-8') ?>;" aria-label="Content sidebar">
    <div class="fm-sidebar-inner">
        <?php
        // -------------------------------------------------------------
        // BLOCK: POSTER / ARTWORK
        // -------------------------------------------------------------
        if (!empty($blocksCfg['poster'])):
            $posterUrl = '';
            $posterAlt = '';
            $posterIcon = '🎬';

            switch ($contentType) {
                case 'movie':
                    $posterUrl = $movie->poster ?? '';
                    $posterAlt = $movie->title ?? 'Movie Poster';
                    $posterIcon = '🎬';
                    break;
                case 'series':
                    $posterUrl = $series->poster ?? '';
                    $posterAlt = $series->title ?? 'Series Poster';
                    $posterIcon = '📺';
                    break;
                case 'episode':
                    $posterUrl = $episode->thumbnail ?? ($series->poster ?? '');
                    $posterAlt = $episode->title ?? 'Episode Thumbnail';
                    $posterIcon = '📺';
                    break;
                case 'song':
                    $posterUrl = $song->cover ?? '';
                    $posterAlt = $song->title ?? 'Song Cover';
                    $posterIcon = '🎵';
                    break;
                case 'album':
                    $posterUrl = $album->cover ?? '';
                    $posterAlt = $album->title ?? 'Album Cover';
                    $posterIcon = '💿';
                    break;
                case 'artist':
                    $posterUrl = $artist->photo ?? '';
                    $posterAlt = $artist->name ?? 'Artist Photo';
                    $posterIcon = '🎤';
                    break;
                case 'playlist':
                    $posterUrl = $playlist->cover ?? '';
                    $posterAlt = $playlist->title ?? 'Playlist Cover';
                    $posterIcon = '📑';
                    break;
            }
        ?>
            <div class="fm-sidebar-block fm-sidebar-block-poster">
                <div class="fm-sidebar-poster-wrap">
                    <?php if ($posterUrl): ?>
                        <img src="<?= htmlspecialchars($posterUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($posterAlt, ENT_QUOTES, 'UTF-8') ?>" class="fm-sidebar-poster <?= $contentType === 'artist' ? 'is-circle' : '' ?>" loading="lazy">
                    <?php else: ?>
                        <div class="fm-sidebar-poster-placeholder <?= $contentType === 'artist' ? 'is-circle' : '' ?>">
                            <span><?= $posterIcon ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // -------------------------------------------------------------
        // BLOCK: QUICK ACTIONS (Play, Add to List, Follow)
        // -------------------------------------------------------------
        if (!empty($blocksCfg['actions'])):
        ?>
            <div class="fm-sidebar-block fm-sidebar-block-actions">
                <div class="fm-sidebar-actions-stack">
                    <?php if ($contentType === 'movie'): ?>
                        <a href="#fmm-player" class="fm-sidebar-btn fm-sidebar-btn-primary" onclick="window.scrollTo({top: 0, behavior: 'smooth'}); return false;">
                            <span>▶</span> Play Movie
                        </a>
                        <?php if (!empty($user)): ?>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-secondary fav-btn-favorite <?= !empty($isFavorite) ? 'active' : '' ?>" data-fmm-fav-toggle data-content-type="movie" data-content-id="<?= (int)($movie->id ?? 0) ?>">
                                <span class="fav-icon-star">★</span>
                                <span class="fav-btn-label"><?= !empty($isFavorite) ? 'In My List' : 'Add to My List' ?></span>
                            </button>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'series'): ?>
                        <?php if (!empty($seriesProgress['next_episode'])): ?>
                            <a href="/episode/<?= htmlspecialchars($seriesProgress['next_episode']->slug, ENT_QUOTES, 'UTF-8') ?>" class="fm-sidebar-btn fm-sidebar-btn-primary">
                                <span>▶</span> Continue Ep <?= (int)$seriesProgress['next_episode']->episode_number ?>
                            </a>
                        <?php elseif (!empty($seasons[0]) && !empty($seasons[0]->getEpisodes()[0])): ?>
                            <a href="/episode/<?= htmlspecialchars($seasons[0]->getEpisodes()[0]->slug, ENT_QUOTES, 'UTF-8') ?>" class="fm-sidebar-btn fm-sidebar-btn-primary">
                                <span>▶</span> Start Series
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($user)): ?>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-secondary fmm-btn-follow <?= !empty($isFollowing) ? 'active' : '' ?>" data-fmm-subscribe-toggle data-target-type="series" data-target-id="<?= (int)($series->id ?? 0) ?>">
                                <span class="fmm-follow-icon"><?= !empty($isFollowing) ? '✓' : '+' ?></span>
                                <span class="fmm-follow-label"><?= !empty($isFollowing) ? 'Following' : 'Follow Series' ?></span>
                            </button>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-secondary fav-btn-favorite <?= !empty($isFavorite) ? 'active' : '' ?>" data-fmm-fav-toggle data-content-type="series" data-content-id="<?= (int)($series->id ?? 0) ?>">
                                <span class="fav-icon-star">★</span>
                                <span class="fav-btn-label"><?= !empty($isFavorite) ? 'In My List' : 'Add to My List' ?></span>
                            </button>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'episode'): ?>
                        <?php if (!empty($nextEpisode)): ?>
                            <?php 
                            $nextSlug = is_array($nextEpisode) ? ($nextEpisode['slug'] ?? '') : ($nextEpisode->slug ?? '');
                            $nextEpNum = (int)(is_array($nextEpisode) ? ($nextEpisode['episode_number'] ?? 1) : ($nextEpisode->episode_number ?? 1));
                            ?>
                            <a href="/episode/<?= htmlspecialchars((string)$nextSlug, ENT_QUOTES, 'UTF-8') ?>" class="fm-sidebar-btn fm-sidebar-btn-primary">
                                <span>▶</span> Next: Ep <?= $nextEpNum ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($user)): ?>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-secondary fav-btn-favorite <?= !empty($isFavorite) ? 'active' : '' ?>" data-fmm-fav-toggle data-content-type="episode" data-content-id="<?= (int)($episode->id ?? 0) ?>">
                                <span class="fav-icon-star">★</span>
                                <span class="fav-btn-label"><?= !empty($isFavorite) ? 'In My List' : 'Add to My List' ?></span>
                            </button>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'song'): ?>
                        <button type="button" class="fm-sidebar-btn fm-sidebar-btn-primary fav-audio-btn-play" aria-label="Play Track">
                            <span>▶</span> Play Audio
                        </button>
                        <?php if (!empty($isDualMode) || !empty($hasVideo)): ?>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-secondary" onclick="if(typeof switchSongPlaybackMode==='function'){switchSongPlaybackMode('video');} window.scrollTo({top:0,behavior:'smooth'});">
                                <span>🎬</span> Watch Video
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($user)): ?>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-secondary fav-btn-favorite <?= !empty($isFavorite) ? 'active' : '' ?>" data-fmm-fav-toggle data-content-type="song" data-content-id="<?= (int)($song->id ?? 0) ?>">
                                <span class="fav-icon-star">★</span>
                                <span class="fav-btn-label"><?= !empty($isFavorite) ? 'In My List' : 'Add to My List' ?></span>
                            </button>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'album'): ?>
                        <button type="button" class="fm-sidebar-btn fm-sidebar-btn-primary" data-fm-play-all="album" data-context-id="<?= (int)($album->id ?? 0) ?>">
                            <span>▶</span> Play Album
                        </button>

                    <?php elseif ($contentType === 'artist'): ?>
                        <?php if (!empty($user)): ?>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-secondary fmm-btn-follow <?= !empty($isFollowing) ? 'active' : '' ?>" data-fmm-subscribe-toggle data-target-type="artist" data-target-id="<?= (int)($artist->id ?? 0) ?>">
                                <span class="fmm-follow-icon"><?= !empty($isFollowing) ? '✓' : '+' ?></span>
                                <span class="fmm-follow-label"><?= !empty($isFollowing) ? 'Following' : 'Follow Artist' ?></span>
                            </button>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'playlist'): ?>
                        <?php if (!empty($tracksData)): ?>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-primary fav-audio-btn-play" aria-label="Play Playlist">
                                <span>▶</span> Play All
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($user)): ?>
                            <button type="button" class="fm-sidebar-btn fm-sidebar-btn-secondary fmm-btn-follow <?= !empty($isFollowing) ? 'active' : '' ?>" data-fmm-subscribe-toggle data-target-type="playlist" data-target-id="<?= (int)($playlist->id ?? 0) ?>">
                                <span class="fmm-follow-icon"><?= !empty($isFollowing) ? '✓' : '+' ?></span>
                                <span class="fmm-follow-label"><?= !empty($isFollowing) ? 'Following' : 'Follow Playlist' ?></span>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // -------------------------------------------------------------
        // BLOCK: DEDUPLICATED DOWNLOAD CONTROLS
        // -------------------------------------------------------------
        if (!empty($blocksCfg['download']) && !empty($downloadInfo['allowed']) && !empty($downloadInfo['download_url'])):
            $dlOptions = $downloadInfo['options'] ?? [];
        ?>
            <div class="fm-sidebar-block fm-sidebar-block-download" data-sidebar-download>
                <div class="fm-sidebar-block-title">Download</div>
                <?php if (count($dlOptions) <= 1): ?>
                    <a href="<?= htmlspecialchars($downloadInfo['download_url'], ENT_QUOTES, 'UTF-8') ?>" class="fm-sidebar-btn fm-sidebar-btn-download" title="Download File">
                        <span>&#8595;</span> Download <?= !empty($downloadInfo['download_label']) ? htmlspecialchars($downloadInfo['download_label'], ENT_QUOTES, 'UTF-8') : 'File' ?>
                    </a>
                <?php else: ?>
                    <div class="fav-download-dropdown" style="width:100%;">
                        <button type="button" class="fm-sidebar-btn fm-sidebar-btn-download fav-download-dropdown-btn" onclick="this.nextElementSibling.classList.toggle('fav-show'); event.stopPropagation();" style="width:100%; display:flex; justify-content:space-between; align-items:center;">
                            <span>&#8595; Download</span>
                            <span style="font-size:10px;">▼</span>
                        </button>
                        <div class="fav-download-dropdown-menu" style="width:100%; min-width:240px; box-sizing:border-box;">
                            <div style="padding:8px 12px; background:var(--fm-color-bg-elevated); border-bottom:1px solid var(--fm-color-border); font-size:11px; font-weight:700; color:var(--fm-color-text-muted); text-transform:uppercase;">Download Options (<?= count($dlOptions) ?>)</div>
                            <?php foreach ($dlOptions as $opt): ?>
                                <a href="<?= htmlspecialchars($opt['url'], ENT_QUOTES, 'UTF-8') ?>" class="fav-download-item">
                                    <div class="fav-download-item-title"><?= htmlspecialchars($opt['display_title'] ?? $opt['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($opt['meta'])): ?><div class="fav-download-item-meta"><?= htmlspecialchars($opt['meta'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        // -------------------------------------------------------------
        // BLOCK: METADATA SUMMARY
        // -------------------------------------------------------------
        if (!empty($blocksCfg['metadata'])):
        ?>
            <div class="fm-sidebar-block fm-sidebar-block-metadata">
                <div class="fm-sidebar-block-title">Information</div>
                <dl class="fm-sidebar-meta-list">
                    <?php if ($contentType === 'movie' && !empty($movie)): ?>
                        <?php if (!empty($movie->release_year)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Year</dt>
                                <dd><?= htmlspecialchars((string)$movie->release_year, ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($movie->getDurationFormatted()): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Duration</dt>
                                <dd><?= htmlspecialchars($movie->getDurationFormatted(), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($movie->language)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Language</dt>
                                <dd><?= htmlspecialchars($movie->language, ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($movie->access_mode)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Access</dt>
                                <dd><span class="fav-badge fav-badge-<?= strtolower($movie->access_mode) ?>"><?= strtoupper($movie->access_mode) ?></span></dd>
                            </div>
                        <?php endif; ?>
                        <?php $genres = $movie->getGenres(); if (!empty($genres)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Genres</dt>
                                <dd>
                                    <?php foreach ($genres as $g): ?>
                                        <a href="/movies?genre=<?= urlencode($g->slug) ?>" class="fm-sidebar-tag"><?= htmlspecialchars($g->name, ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php endforeach; ?>
                                </dd>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'series' && !empty($series)): ?>
                        <?php if (!empty($series->release_year)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Year</dt>
                                <dd><?= htmlspecialchars((string)$series->release_year, ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($seasons)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Seasons</dt>
                                <dd><?= count($seasons) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($series->language)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Language</dt>
                                <dd><?= htmlspecialchars($series->language, ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($series->access_mode)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Access</dt>
                                <dd><span class="fav-badge fav-badge-<?= strtolower($series->access_mode) ?>"><?= strtoupper($series->access_mode) ?></span></dd>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'episode' && !empty($episode)): ?>
                        <?php if (!empty($series)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Series</dt>
                                <dd><a href="/series/<?= htmlspecialchars($series->slug, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--fm-color-primary);"><?= htmlspecialchars($series->title, ENT_QUOTES, 'UTF-8') ?></a></dd>
                            </div>
                        <?php endif; ?>
                        <div class="fm-sidebar-meta-item">
                            <dt>Episode</dt>
                            <dd>Ep <?= (int)$episode->episode_number ?></dd>
                        </div>
                        <?php if ($episode->duration): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Duration</dt>
                                <dd><?= round($episode->duration / 60) ?> min</dd>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'song' && !empty($song)): ?>
                        <?php if ($song->getArtist()): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Artist</dt>
                                <dd><a href="/multimedia/artist/<?= htmlspecialchars($song->getArtist()->slug, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--fm-color-primary);"><?= htmlspecialchars($song->getArtist()->name, ENT_QUOTES, 'UTF-8') ?></a></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($song->getDurationFormatted()): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Duration</dt>
                                <dd><?= htmlspecialchars($song->getDurationFormatted(), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($song->access_mode)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Access</dt>
                                <dd><span class="fav-badge fav-badge-<?= strtolower($song->access_mode) ?>"><?= strtoupper($song->access_mode) ?></span></dd>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'album' && !empty($album)): ?>
                        <?php if (!empty($artist)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Artist</dt>
                                <dd><a href="/multimedia/artist/<?= htmlspecialchars($artist->slug, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--fm-color-primary);"><?= htmlspecialchars($artist->name, ENT_QUOTES, 'UTF-8') ?></a></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($releaseYear)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Year</dt>
                                <dd><?= htmlspecialchars((string)$releaseYear, ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endif; ?>
                        <div class="fm-sidebar-meta-item">
                            <dt>Tracks</dt>
                            <dd><?= count($songs ?? []) ?></dd>
                        </div>

                    <?php elseif ($contentType === 'artist' && !empty($artist)): ?>
                        <?php if (!empty($albums)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Albums</dt>
                                <dd><?= count($albums) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($songs)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Tracks</dt>
                                <dd><?= count($songs) ?></dd>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($contentType === 'playlist' && !empty($playlist)): ?>
                        <div class="fm-sidebar-meta-item">
                            <dt>Tracks</dt>
                            <dd><?= count($tracksData ?? []) ?></dd>
                        </div>
                        <?php if (!empty($playlist->access_mode)): ?>
                            <div class="fm-sidebar-meta-item">
                                <dt>Access</dt>
                                <dd><span class="fav-badge fav-badge-<?= strtolower($playlist->access_mode) ?>"><?= strtoupper($playlist->access_mode) ?></span></dd>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </dl>
            </div>
        <?php endif; ?>
    </div>
</aside>
