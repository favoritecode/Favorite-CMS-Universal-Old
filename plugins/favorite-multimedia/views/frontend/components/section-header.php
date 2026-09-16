<?php
/**
 * Section Header Component.
 *
 * @var string $title
 * @var string|null $subtitle
 * @var string|null $viewAllUrl
 * @var bool $showViewAll
 */
?>
<div class="fm-section-header">
    <div class="fm-section-header-text">
        <h2 class="fm-section-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php if (!empty($subtitle)): ?>
            <p class="fm-section-subtitle"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($showViewAll) && !empty($viewAllUrl)): ?>
        <a href="<?php echo htmlspecialchars($viewAllUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fm-section-view-all">
            <span>View All</span>
            <span class="fm-arrow" aria-hidden="true">→</span>
        </a>
    <?php endif; ?>
</div>

