<?php
/**
 * 1:1 Square Album Card Component.
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
$title = (string)($itemObj->title ?? $itemObj->name ?? 'Untitled Album');
$slug = (string)($itemObj->slug ?? (string)$id);
$url = '/multimedia/album/' . $slug;
$cover = (string)($itemObj->cover_image ?? $itemObj->poster_image ?? $itemObj->cover ?? '');
$year = (string)($itemObj->release_year ?? $itemObj->year ?? '');
$artistName = '';

if (method_exists($item, 'getArtist')) {
    $artist = $item->getArtist();
    $artistName = $artist ? (string)$artist->name : '';
} elseif (isset($itemObj->artist_name)) {
    $artistName = (string)$itemObj->artist_name;
}
?>
<article class="fm-card fm-album-card" data-id="<?php echo $id; ?>">
    <div class="fm-card-media">
        <?php if ($cover !== ''): ?>
            <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" class="fm-card-img fm-aspect-square" loading="lazy">
        <?php else: ?>
            <div class="fm-card-placeholder fm-aspect-square" aria-hidden="true">
                <span>💿</span>
            </div>
        <?php endif; ?>

        <!-- Hover Play Button -->
        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="fm-card-hover-overlay" aria-label="Play <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="fm-hover-play-btn fm-btn-circle" aria-hidden="true">▶</div>
        </a>
    </div>

    <div class="fm-card-body">
        <h3 class="fm-card-title">
            <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></a>
        </h3>

        <div class="fm-card-meta">
            <?php if ($artistName !== ''): ?>
                <span class="fm-card-artist"><?php echo htmlspecialchars($artistName, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <?php if (!empty($showMetadata) && $year !== ''): ?>
                <span class="fm-meta-year"><?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </div>
    </div>
</article>

