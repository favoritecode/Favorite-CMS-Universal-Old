<?php
/**
 * Skeleton Card Component to prevent cumulative layout shift (CLS).
 *
 * @var string $aspect 'poster', 'landscape', 'square'
 */
$aspect = $aspect ?? 'poster';
?>
<div class="fm-card fm-skeleton-card" aria-hidden="true">
    <div class="fm-card-media fm-skeleton-box fm-aspect-<?php echo htmlspecialchars($aspect, ENT_QUOTES, 'UTF-8'); ?>"></div>
    <div class="fm-card-body">
        <div class="fm-skeleton-line fm-skeleton-title"></div>
        <div class="fm-skeleton-line fm-skeleton-subtitle"></div>
    </div>
</div>

