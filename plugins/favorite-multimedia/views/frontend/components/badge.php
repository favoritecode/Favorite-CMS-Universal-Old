<?php
/**
 * Badge Component.
 *
 * @var string $text
 * @var string|null $variant 'primary', 'secondary', 'premium', 'new', 'rating'
 */
$variant = $variant ?? 'primary';
?>
<span class="fm-badge fm-badge-<?php echo htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'); ?>">
    <?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?>
</span>

