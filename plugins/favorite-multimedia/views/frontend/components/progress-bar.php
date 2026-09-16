<?php
/**
 * Progress Bar Component.
 *
 * @var float $percentage
 */
$percentage = min(100, max(0, (float)($percentage ?? 0)));
?>
<div class="fm-progress-bar" role="progressbar" aria-valuenow="<?php echo round($percentage); ?>" aria-valuemin="0" aria-valuemax="100">
    <div class="fm-progress-fill" style="width: <?php echo $percentage; ?>%;"></div>
</div>

