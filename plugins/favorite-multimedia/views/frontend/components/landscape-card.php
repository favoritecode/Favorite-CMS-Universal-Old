<?php
/**
 * 16:9 Landscape Card Component (Episodes, Continue Watching, Genres).
 *
 * @var object|array $item
 * @var bool $showMetadata
 * @var bool $showBadges
 * @var bool $showProgress
 */

use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Genre;

if (is_array($item)) {
    $itemObj = (object)$item;
} else {
    $itemObj = $item;
}

$id = (int)($itemObj->id ?? 0);
$title = (string)($itemObj->title ?? $itemObj->name ?? 'Untitled');
$slug = (string)($itemObj->slug ?? (string)$id);
$isGenre = ($item instanceof Genre) || isset($itemObj->color_hex);
$isEpisode = ($item instanceof Episode) || isset($itemObj->episode_number) || (isset($cardType) && $cardType === 'episode');

if ($isGenre) {
    $url = '/multimedia/genre/' . $slug;
} elseif ($isEpisode) {
    $url = '/episode/' . $slug;
} elseif (!empty($itemObj->url)) {
    $url = (string)$itemObj->url;
} else {
    $url = '/movie/' . $slug;
}

$image = (string)($itemObj->backdrop_image ?? $itemObj->thumbnail ?? $itemObj->poster ?? $itemObj->image ?? '');
$percentage = isset($itemObj->percentage) ? (float)$itemObj->percentage : (isset($itemObj->progress->percentage) ? (float)$itemObj->progress->percentage : null);
$subtitle = (string)($itemObj->subtitle ?? $itemObj->series_title ?? '');
if ($isEpisode && isset($itemObj->season_number, $itemObj->episode_number)) {
    $subtitle = "S{$itemObj->season_number}:E{$itemObj->episode_number}";
}
?>
<article class="fm-card fm-landscape-card" data-id="<?php echo $id; ?>">
    <div class="fm-card-media">
        <?php if ($image !== ''): ?>
            <img src="<?php echo htmlspecialchars($image, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" class="fm-card-img fm-aspect-landscape" loading="lazy">
        <?php else: ?>
            <div class="fm-card-placeholder fm-aspect-landscape" aria-hidden="true" style="<?php echo !empty($itemObj->color_hex) ? 'background: ' . htmlspecialchars((string)$itemObj->color_hex, ENT_QUOTES, 'UTF-8') : ''; ?>">
                <span><?php echo $isGenre ? '🏷' : '📺'; ?></span>
            </div>
        <?php endif; ?>

        <!-- Play / Browse Link Overlay -->
        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="fm-card-hover-overlay" aria-label="Play <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="fm-hover-play-btn" aria-hidden="true">▶</div>
        </a>

        <!-- Watch Progress Bar -->
        <?php if (!empty($showProgress) && $percentage !== null && $percentage > 0): ?>
            <div class="fm-card-progress-bar" role="progressbar" aria-valuenow="<?php echo round($percentage); ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="fm-card-progress-fill" style="width: <?php echo min(100, max(0, $percentage)); ?>%;"></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="fm-card-body">
        <?php if ($subtitle !== ''): ?>
            <div class="fm-card-subheading"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <h3 class="fm-card-title">
            <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></a>
        </h3>

        <?php if (!empty($showMetadata) && !empty($itemObj->remaining_formatted)): ?>
            <div class="fm-card-meta">
                <span class="fm-meta-remaining"><?php echo htmlspecialchars((string)$itemObj->remaining_formatted, ENT_QUOTES, 'UTF-8'); ?> left</span>
            </div>
        <?php endif; ?>
    </div>
</article>
