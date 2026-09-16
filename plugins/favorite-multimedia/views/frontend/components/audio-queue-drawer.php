<?php
/**
 * Favorite Multimedia — Audio Queue Drawer Component.
 * Slide-over queue manager with real-time list, jump-to-play, remove, and clear operations.
 */
?>
<div id="fm-audio-queue-drawer" class="fm-audio-queue-drawer" role="complementary" aria-label="Playback Queue">
    <div class="fm-queue-header">
        <div class="fm-queue-header-left">
            <span class="fm-queue-title">Playing Next</span>
            <span id="fm-queue-drawer-count" class="fm-queue-count">0 tracks</span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <button type="button" id="fm-queue-clear-btn" class="fm-btn-icon" style="font-size: 13px; width: auto; padding: 4px 8px; border-radius: 4px;" aria-label="Clear Queue" title="Clear Queue">
                Clear
            </button>
            <button type="button" id="fm-queue-drawer-close" class="fm-btn-icon" aria-label="Close Queue">
                ✕
            </button>
        </div>
    </div>

    <!-- Scrollable Queue Items -->
    <div id="fm-queue-drawer-list" class="fm-queue-list">
        <div class="fm-queue-empty">Queue is empty. Select a track or album to listen.</div>
    </div>
</div>

