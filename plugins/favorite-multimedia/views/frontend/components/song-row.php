<?php
/**
 * Audio Song Row Component.
 *
 * @var object|array $item
 * @var int|null $index
 * @var bool $showMetadata
 */

if (is_array($item)) {
    $itemObj = (object)$item;
} else {
    $itemObj = $item;
}

$id = (int)($itemObj->id ?? 0);
$title = (string)($itemObj->title ?? 'Untitled Track');
$slug = (string)($itemObj->slug ?? (string)$id);
$url = '/song/' . $slug;
$playUrl = '/multimedia/play/' . $id;
$cover = (string)($itemObj->cover_image ?? $itemObj->poster ?? '');
$artistName = (string)($itemObj->artist_name ?? '');
$albumTitle = (string)($itemObj->album_title ?? '');
$duration = isset($itemObj->duration) ? (int)$itemObj->duration : 0;
$formattedDuration = ($duration > 0) ? sprintf('%d:%02d', floor($duration / 60), $duration % 60) : '--:--';
$trackNum = isset($index) ? str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) : '';
?>
<div class="fm-song-row" data-id="<?php echo $id; ?>" data-song-id="<?php echo $id; ?>" data-title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" data-artist="<?php echo htmlspecialchars($artistName, ENT_QUOTES, 'UTF-8'); ?>" data-cover="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" data-duration="<?php echo $duration; ?>">
    <div class="fm-song-left">
        <?php if ($trackNum !== ''): ?>
            <span class="fm-song-num"><?php echo $trackNum; ?></span>
        <?php endif; ?>

        <div class="fm-song-thumb-wrap" role="button" data-fm-play-song="<?php echo $id; ?>" aria-label="Play <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($cover !== ''): ?>
                <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" class="fm-song-thumb" loading="lazy">
            <?php else: ?>
                <div class="fm-song-thumb fm-song-thumb-empty">🎵</div>
            <?php endif; ?>
            <div class="fm-song-thumb-play" aria-hidden="true">▶</div>
        </div>

        <div class="fm-song-meta">
            <h4 class="fm-song-title">
                <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></a>
            </h4>
            <div class="fm-song-subtext">
                <?php if ($artistName !== ''): ?>
                    <span class="fm-song-artist"><?php echo htmlspecialchars($artistName, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if ($albumTitle !== ''): ?>
                    <span class="fm-song-album">• <?php echo htmlspecialchars($albumTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="fm-song-right">
        <span class="fm-song-duration"><?php echo $formattedDuration; ?></span>
        <button type="button" class="fm-btn-icon fm-song-queue-btn" data-fm-queue-add="<?php echo $id; ?>" data-title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" data-artist="<?php echo htmlspecialchars($artistName, ENT_QUOTES, 'UTF-8'); ?>" data-cover="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" data-duration="<?php echo $duration; ?>" title="Add to Queue" aria-label="Add to Queue">
            📑
        </button>
        <button type="button" class="fm-btn fm-btn-primary fm-btn-circle fm-song-play-btn" data-fm-play-song="<?php echo $id; ?>" aria-label="Play Track">
            <span>▶</span>
        </button>
    </div>
</div>

