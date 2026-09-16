<?php
/**
 * Content Rail Scroll Controls Component (Left / Right smooth scroll buttons).
 *
 * @var string $targetId
 */
?>
<div class="fm-rail-controls" aria-hidden="true">
    <button type="button" class="fm-rail-btn fm-rail-prev fm-rail-btn-prev" data-target="<?php echo htmlspecialchars($targetId, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Scroll left" tabindex="-1">‹</button>
    <button type="button" class="fm-rail-btn fm-rail-next fm-rail-btn-next" data-target="<?php echo htmlspecialchars($targetId, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Scroll right" tabindex="-1">›</button>
</div>
