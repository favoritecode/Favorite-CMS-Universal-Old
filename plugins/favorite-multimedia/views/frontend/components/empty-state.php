<?php
/**
 * Graceful Empty State Component.
 *
 * @var string $title
 * @var string|null $message
 * @var string|null $icon
 * @var string|null $actionUrl
 * @var string|null $actionText
 */
?>
<div class="fm-empty-state">
    <div class="fm-empty-icon" aria-hidden="true"><?php echo htmlspecialchars($icon ?? '📁', ENT_QUOTES, 'UTF-8'); ?></div>
    <h3 class="fm-empty-title"><?php echo htmlspecialchars($title ?? 'No items found', ENT_QUOTES, 'UTF-8'); ?></h3>
    <?php if (!empty($message)): ?>
        <p class="fm-empty-desc"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if (!empty($actionUrl) && !empty($actionText)): ?>
        <a href="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-primary fm-btn-sm fm-empty-action">
            <?php echo htmlspecialchars($actionText, ENT_QUOTES, 'UTF-8'); ?>
        </a>
    <?php endif; ?>
</div>

