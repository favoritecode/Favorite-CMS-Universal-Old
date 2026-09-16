<?php
/**
 * Media Row Component (Rail, Grid, or List container).
 *
 * @var array $section
 * @var array $items
 * @var string $cardStyle 'poster', 'landscape', 'album', 'artist', 'playlist', 'song_row', 'mixed'
 * @var string $layout 'rail', 'grid', 'list'
 * @var \FavoriteCMS\Models\User|null $user
 */

use FavoriteCMS\Multimedia\Theme\Homepage\SectionRegistry;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Playlist;

if (empty($items)) {
    return;
}

$secId = (string)($section['id'] ?? 'sec_' . bin2hex(random_bytes(3)));
$type = (string)($section['type'] ?? '');
$title = (string)($section['title'] ?? 'Section');
$subtitle = (string)($section['subtitle'] ?? '');
$showViewAll = (bool)($section['show_view_all'] ?? true);
$showMetadata = (bool)($section['show_metadata'] ?? true);
$showBadges = (bool)($section['show_badges'] ?? true);
$showProgress = (bool)($section['show_progress'] ?? true);
$visibility = (string)($section['device_visibility'] ?? 'all');
$hideClasses = '';
if (isset($section['visible_desktop']) && !$section['visible_desktop']) $hideClasses .= ' fm-hide-desktop';
if (isset($section['visible_tablet']) && !$section['visible_tablet']) $hideClasses .= ' fm-hide-tablet';
if (isset($section['visible_mobile']) && !$section['visible_mobile']) $hideClasses .= ' fm-hide-mobile';

// Resolve View All URL
$viewAllUrl = null;
switch ($type) {
    case SectionRegistry::TYPE_LATEST_MOVIES:
    case SectionRegistry::TYPE_POPULAR_MOVIES:
    case SectionRegistry::TYPE_FEATURED_MOVIES:
        $viewAllUrl = '/movies';
        break;
    case SectionRegistry::TYPE_LATEST_SERIES:
    case SectionRegistry::TYPE_POPULAR_SERIES:
    case SectionRegistry::TYPE_FEATURED_SERIES:
    case SectionRegistry::TYPE_LATEST_EPISODES:
        $viewAllUrl = '/series';
        break;
    case SectionRegistry::TYPE_MUSIC_SPOTLIGHT:
    case SectionRegistry::TYPE_NEW_SONGS:
    case SectionRegistry::TYPE_POPULAR_SONGS:
    case SectionRegistry::TYPE_ALBUMS:
    case SectionRegistry::TYPE_FEATURED_ALBUMS:
    case SectionRegistry::TYPE_AUDIO_PLAYLISTS:
    case SectionRegistry::TYPE_ARTISTS:
        $viewAllUrl = '/multimedia/music';
        break;
    case SectionRegistry::TYPE_VIDEO_PLAYLISTS:
        $viewAllUrl = '/playlists';
        break;
    case SectionRegistry::TYPE_GENRES:
    case SectionRegistry::TYPE_TRENDING:
    case SectionRegistry::TYPE_TOP_10:
    case SectionRegistry::TYPE_EDITORS_PICKS:
        $viewAllUrl = '/multimedia/discover';
        break;
}

$isTop10 = ($type === SectionRegistry::TYPE_TOP_10);
$componentsDir = __DIR__;
?>
<section class="fm-section fm-section-<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?> fm-visibility-<?php echo htmlspecialchars($visibility, ENT_QUOTES, 'UTF-8'); ?><?php echo $hideClasses; ?>" id="<?php echo htmlspecialchars($secId, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="fm-section-inner">
        <!-- Section Header -->
        <?php include $componentsDir . '/section-header.php'; ?>

        <?php if ($layout === 'rail'): ?>
            <!-- Rail Layout with Controls -->
            <div class="fm-rail-wrapper">
                <?php 
                $targetId = 'rail_' . $secId; 
                if (count($items) > 1) {
                    include $componentsDir . '/content-rail-controls.php'; 
                } 
                ?>
                <div class="fm-rail-track" id="<?php echo htmlspecialchars($targetId, ENT_QUOTES, 'UTF-8'); ?>" tabindex="0" role="region" aria-label="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?> media rail">
                    <?php foreach ($items as $idx => $item): 
                        $rank = $isTop10 ? ($idx + 1) : null;
                        $resolvedCard = $cardStyle;
                        if ($resolvedCard === 'mixed') {
                            if ($item instanceof Song) $resolvedCard = 'song_row';
                            elseif ($item instanceof Album) $resolvedCard = 'album';
                            elseif ($item instanceof Artist) $resolvedCard = 'artist';
                            elseif ($item instanceof Playlist) $resolvedCard = 'playlist';
                            elseif ($item instanceof Series) $resolvedCard = 'poster';
                            else $resolvedCard = 'poster';
                        }
                        $cardFile = $componentsDir . '/' . str_replace('_', '-', $resolvedCard) . '-card.php';
                        if (!file_exists($cardFile)) {
                            $cardFile = $componentsDir . '/poster-card.php';
                        }
                        include $cardFile;
                    endforeach; ?>
                </div>
            </div>
        <?php elseif ($layout === 'list'): ?>
            <!-- List Layout (Vertical Stack for Song Rows) -->
            <div class="fm-list-wrapper">
                <?php foreach ($items as $idx => $item): 
                    $index = $idx;
                    include $componentsDir . '/song-row.php';
                endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Grid Layout -->
            <div class="fm-grid-wrapper fm-grid fm-grid-<?php echo htmlspecialchars($cardStyle, ENT_QUOTES, 'UTF-8'); ?>">
                <?php foreach ($items as $idx => $item): 
                    $rank = $isTop10 ? ($idx + 1) : null;
                    $resolvedCard = $cardStyle;
                    if ($resolvedCard === 'mixed') {
                        if ($item instanceof Song) $resolvedCard = 'song_row';
                        elseif ($item instanceof Album) $resolvedCard = 'album';
                        elseif ($item instanceof Artist) $resolvedCard = 'artist';
                        elseif ($item instanceof Playlist) $resolvedCard = 'playlist';
                        else $resolvedCard = 'poster';
                    }
                    $cardFile = $componentsDir . '/' . str_replace('_', '-', $resolvedCard) . '-card.php';
                    if (!file_exists($cardFile)) {
                        $cardFile = $componentsDir . '/poster-card.php';
                    }
                    include $cardFile;
                endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
