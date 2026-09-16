<?php
/**
 * Rating Component.
 *
 * @var float|string $score
 * @var bool $showIcon
 */
$formatted = is_numeric($score) ? number_format((float)$score, 1) : (string)$score;
?>
<span class="fm-rating" aria-label="Rating <?php echo $formatted; ?> out of 10">
    <span class="fm-rating-star" aria-hidden="true">★</span>
    <span class="fm-rating-val"><?php echo $formatted; ?></span>
</span>

