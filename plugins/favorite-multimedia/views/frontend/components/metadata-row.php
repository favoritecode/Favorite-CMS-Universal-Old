<?php
/**
 * Metadata Row Component (Year, duration, quality, genres).
 *
 * @var array<string> $items
 */
if (empty($items)) return;
?>
<div class="fm-meta-row">
    <?php foreach ($items as $idx => $meta): 
        if (trim((string)$meta) === '') continue;
    ?>
        <?php if ($idx > 0): ?>
            <span class="fm-meta-sep" aria-hidden="true">•</span>
        <?php endif; ?>
        <span class="fm-meta-item"><?php echo htmlspecialchars((string)$meta, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endforeach; ?>
</div>

