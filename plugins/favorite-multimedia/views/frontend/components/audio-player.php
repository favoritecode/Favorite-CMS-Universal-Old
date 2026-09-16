<?php
/**
 * Favorite Multimedia — Master Persistent Audio Player Bundle.
 * Combines Mini-Player, Expanded Modal, Queue Drawer, and Audio Engine Scripts.
 */
$compDir = __DIR__;
?>
<!-- Persistent Audio Player System -->
<?php include $compDir . '/mini-player.php'; ?>
<?php include $compDir . '/expanded-player.php'; ?>
<?php include $compDir . '/audio-queue-drawer.php'; ?>

<!-- Global Audio Toast Notification Container -->
<div id="fm-audio-toast-container" class="fm-audio-toast-container" aria-live="polite"></div>

<!-- Playback Configuration & Unified Volume Settings -->
<?php
try {
    $defaultVolumeVal = max(5, min(50, (int)\FavoriteCMS\Models\Setting::get('multimedia', 'default_media_volume', 25))) / 100;
    $rememberVolumeVal = \FavoriteCMS\Models\Setting::get('multimedia', 'remember_media_volume', 'yes') === 'yes';
    $enablePipVal = \FavoriteCMS\Models\Setting::get('multimedia', 'enable_pip', 'yes') === 'yes';
    $enableBgAudioVal = \FavoriteCMS\Models\Setting::get('multimedia', 'enable_background_audio', 'yes') === 'yes';
} catch (\Throwable) {
    $defaultVolumeVal = 0.25;
    $rememberVolumeVal = true;
    $enablePipVal = true;
    $enableBgAudioVal = true;
}
?>
<script>
window.FavoriteMultimediaConfig = window.FavoriteMultimediaConfig || {};
window.FavoriteMultimediaConfig.defaultVolume = <?php echo json_encode($defaultVolumeVal); ?>;
window.FavoriteMultimediaConfig.rememberVolume = <?php echo json_encode($rememberVolumeVal); ?>;
window.FavoriteMultimediaConfig.enablePip = <?php echo json_encode($enablePipVal); ?>;
window.FavoriteMultimediaConfig.enableBackgroundAudio = <?php echo json_encode($enableBgAudioVal); ?>;
</script>

<!-- Persistent Audio Player Scripts -->
<script src="/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-state.js"></script>
<script src="/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-queue.js"></script>
<script src="/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-player.js"></script>
<script src="/plugins/favorite-multimedia/assets/js/audio-player/favorite-audio-ui.js"></script>

