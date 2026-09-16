<?php
/**
 * Load More / Pagination Component.
 *
 * @var int $page
 * @var int $totalPages
 * @var string|null $baseUrl
 */
if ($totalPages <= 1) return;
?>
<div class="fm-pagination-wrap" aria-label="Pagination">
    <div class="fm-pagination-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
    <div class="fm-pagination-links">
        <?php if ($page > 1): ?>
            <a href="<?php echo htmlspecialchars($baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'p=' . ($page - 1), ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-secondary fm-btn-sm">← Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
            <a href="<?php echo htmlspecialchars($baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'p=' . ($page + 1), ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-primary fm-btn-sm">Next →</a>
        <?php endif; ?>
    </div>
</div>

