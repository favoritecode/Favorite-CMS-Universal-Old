<?php
/**
 * 2:3 Vertical Poster Card Component (Movies, Series, Collections).
 *
 * @var object|array $item
 * @var bool $showMetadata
 * @var bool $showBadges
 * @var bool $showProgress
 * @var int|null $rank Optional numeric rank for Top 10 rails (1-10)
 */

use FavoriteCMS\Multimedia\Models\Series;

// Normalize item properties
if (is_array($item)) {
    $itemObj = (object)$item;
} else {
    $itemObj = $item;
}

$id = (int)($itemObj->id ?? 0);
$title = (string)($itemObj->title ?? $itemObj->name ?? 'Untitled');
$slug = (string)($itemObj->slug ?? (string)$id);
$isSeries = ($item instanceof Series) || isset($itemObj->total_seasons);
$url = $isSeries ? '/series/' . $slug : '/movie/' . $slug;
$playUrl = '/multimedia/play/' . $id;
$poster = (string)($itemObj->poster_image ?? $itemObj->poster ?? '');
$year = (string)($itemObj->release_year ?? $itemObj->year ?? '');
$rating = !empty($itemObj->rating) ? number_format((float)$itemObj->rating, 1) : '';
$showBadges = $showBadges ?? true;
$showMetadata = $showMetadata ?? true;
$showProgress = $showProgress ?? true;
$accessMode = (string)($itemObj->access_mode ?? '');
$isPremium = (!empty($itemObj->is_premium) && (int)$itemObj->is_premium === 1) || strtolower($accessMode) === 'premium';
$quality = (string)($itemObj->quality ?? 'HD');
$percentage = isset($itemObj->percentage) ? (float)$itemObj->percentage : (isset($itemObj->progress->percentage) ? (float)$itemObj->progress->percentage : null);
?>
<article class="fm-card fm-poster-card" data-id="<?php echo $id; ?>" data-kind="<?php echo $isSeries ? 'series' : 'movie'; ?>">
    <div class="fm-card-media">
        <?php if ($poster !== ''): ?>
            <img src="<?php echo htmlspecialchars($poster, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" class="fm-card-img fm-aspect-poster" loading="lazy">
        <?php else: ?>
            <div class="fm-card-placeholder fm-aspect-poster" aria-hidden="true">
                <span>🎬</span>
            </div>
        <?php endif; ?>

        <!-- Rank Decoration for Top 10 -->
        <?php if (!empty($rank)): ?>
            <div class="fm-rank-num" aria-label="Rank <?php echo (int)$rank; ?>"><?php echo (int)$rank; ?></div>
        <?php endif; ?>

        <!-- Badges Overlay -->
        <?php if (!empty($showBadges)): ?>
            <div class="fm-card-badges-top">
                <?php if ($isPremium): ?>
                    <span class="fm-badge fm-badge-premium">PREMIUM</span>
                <?php endif; ?>
                <?php if (!empty($itemObj->is_new)): ?>
                    <span class="fm-badge fm-badge-new">New</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Hover Action Overlay -->
        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="fm-card-hover-overlay" aria-label="View <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
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
        <h3 class="fm-card-title">
            <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></a>
        </h3>

        <?php if (!empty($showMetadata)): ?>
            <div class="fm-card-meta">
                <?php if ($rating !== ''): ?>
                    <span class="fm-meta-rating"><span aria-hidden="true">★</span> <?php echo $rating; ?></span>
                <?php endif; ?>
                <?php if ($year !== ''): ?>
                    <span class="fm-meta-year"><?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <span class="fm-meta-badge"><?php echo $isSeries ? 'Series' : 'Movie'; ?></span>
            </div>
        <?php endif; ?>
    </div>
</article>
