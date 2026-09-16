<?php
/**
 * Audio / Video Playlist Card Component.
 *
 * @var object|array $item
 * @var bool $showMetadata
 */

if (is_array($item)) {
    $itemObj = (object)$item;
} else {
    $itemObj = $item;
}

$id = (int)($itemObj->id ?? 0);
$title = (string)($itemObj->title ?? $itemObj->name ?? 'Untitled Playlist');
$slug = (string)($itemObj->slug ?? (string)$id);
$url = '/playlist/' . $slug;
$cover = (string)($itemObj->cover_image ?? $itemObj->poster_image ?? $itemObj->cover ?? '');
$type = (string)($itemObj->type ?? 'audio');
$trackCount = isset($itemObj->items_count) ? (int)$itemObj->items_count : (isset($itemObj->songs_count) ? (int)$itemObj->songs_count : null);
?>
<article class="fm-card fm-playlist-card" data-id="<?php echo $id; ?>">
    <div class="fm-card-media">
        <?php if ($cover !== ''): ?>
            <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" class="fm-card-img fm-aspect-square" loading="lazy">
        <?php else: ?>
            <div class="fm-card-placeholder fm-aspect-square" aria-hidden="true">
                <span>📑</span>
            </div>
        <?php endif; ?>

        <!-- Playlist Type Badge -->
        <div class="fm-card-badges-top">
            <span class="fm-badge fm-badge-secondary"><?php echo strtolower($type) === 'video' ? 'Video Playlist' : 'Audio Playlist'; ?></span>
        </div>

        <!-- Hover Play Button -->
        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="fm-card-hover-overlay" aria-label="Open Playlist <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="fm-hover-play-btn fm-btn-circle" aria-hidden="true">▶</div>
        </a>
    </div>

    <div class="fm-card-body">
        <h3 class="fm-card-title">
            <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></a>
        </h3>

        <?php if (!empty($showMetadata)): ?>
            <div class="fm-card-meta">
                <?php if ($trackCount !== null): ?>
                    <span class="fm-meta-count"><?php echo $trackCount; ?> items</span>
                <?php else: ?>
                    <span class="fm-meta-count">Playlist</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</article>

