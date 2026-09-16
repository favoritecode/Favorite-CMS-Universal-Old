<?php
/**
 * Circular / Portrait Artist Card Component.
 *
 * @var object|array $item
 */

if (is_array($item)) {
    $itemObj = (object)$item;
} else {
    $itemObj = $item;
}

$id = (int)($itemObj->id ?? 0);
$name = (string)($itemObj->name ?? 'Artist');
$slug = (string)($itemObj->slug ?? (string)$id);
$url = '/multimedia/artist/' . $slug;
$photo = (string)($itemObj->photo_image ?? $itemObj->photo ?? $itemObj->avatar ?? '');
?>
<article class="fm-artist-card" data-id="<?php echo $id; ?>">
    <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="fm-artist-media-link" aria-label="View <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($photo !== ''): ?>
            <img src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" class="fm-artist-avatar" loading="lazy">
        <?php else: ?>
            <div class="fm-artist-avatar fm-artist-placeholder" aria-hidden="true">
                <span>🎤</span>
            </div>
        <?php endif; ?>
    </a>

    <div class="fm-artist-info">
        <h3 class="fm-artist-name">
            <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></a>
        </h3>
        <span class="fm-artist-label">Artist</span>
    </div>
</article>

