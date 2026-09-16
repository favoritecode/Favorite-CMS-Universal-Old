<?php
/**
 * Favorite Multimedia — Activity Notifications Inbox
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($metaTitle ?? 'Notifications', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <meta name="csrf-token" content="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <?php 
    $themeManager = \FavoriteCMS\Multimedia\Theme\ThemeManager::getInstance();
    $componentsDir = __DIR__ . '/components';
    $currentNav = 'library';
    echo $themeManager->renderHeadTokens(); 
    ?>
</head>
<body class="fm-theme-body" style="margin: 0; padding: 0;">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <div class="fav-mm-container" style="padding-top: 24px;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
            <h1 style="color: var(--fm-color-text-primary, #fff); font-size: 26px; margin: 0;">Notifications</h1>
            <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
                <span class="fmm-unread-badge" id="fmm-main-unread-badge"><?= htmlspecialchars($unreadBadge ?? (string)$unreadCount, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>

        <!-- Notification Toolbar -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; background: #1e293b; padding: 14px 20px; border-radius: 8px;">
            <div style="display: flex; gap: 8px;">
                <a href="/multimedia/notifications?filter=all" class="fmm-filter-btn <?= ($filter === 'all') ? 'active' : '' ?>">All Notifications</a>
                <a href="/multimedia/notifications?filter=unread" class="fmm-filter-btn <?= ($filter === 'unread') ? 'active' : '' ?>">
                    Unread <?= !empty($unreadCount) ? "({$unreadCount})" : '' ?>
                </a>
            </div>

            <div style="display: flex; gap: 10px; align-items: center;">
                <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
                    <button type="button" class="fmm-btn-action" id="fmm-mark-all-read-btn">
                        ✓ Mark All as Read
                    </button>
                <?php endif; ?>
                <button type="button" class="fmm-btn-action secondary" id="fmm-open-prefs-btn">
                    ⚙ Settings
                </button>
            </div>
        </div>

        <!-- Preferences Modal / Drawer -->
        <div id="fmm-prefs-modal" class="fmm-modal" style="display: none;">
            <div class="fmm-modal-content" style="max-width: 480px; background: #1e293b; color: #f8fafc; border: 1px solid #334155;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="margin: 0; font-size: 18px; color: #fff;">Notification Preferences</h3>
                    <button type="button" class="fmm-modal-close" id="fmm-close-prefs-btn" style="color: #94a3b8; background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <p style="font-size: 13px; color: #94a3b8; margin-top: 0; margin-bottom: 20px;">Choose which in-app release alerts and community notifications you want to receive.</p>

                <form id="fmm-prefs-form" style="display: flex; flex-direction: column; gap: 16px;">
                    <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="notify_content_updates" value="1" <?= !empty($preferences['notify_content_updates']) ? 'checked' : '' ?> style="margin-top: 3px;">
                        <div>
                            <span style="font-weight: 600; color: #fff; font-size: 14px;">Content Release Alerts</span>
                            <div style="font-size: 12px; color: #94a3b8;">New episodes of followed series, songs by followed artists, and playlist updates.</div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="notify_engagement_replies" value="1" <?= !empty($preferences['notify_engagement_replies']) ? 'checked' : '' ?> style="margin-top: 3px;">
                        <div>
                            <span style="font-weight: 600; color: #fff; font-size: 14px;">Discussion Replies</span>
                            <div style="font-size: 12px; color: #94a3b8;">Notifications when someone replies directly to your comments.</div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="notify_moderation_updates" value="1" <?= !empty($preferences['notify_moderation_updates']) ? 'checked' : '' ?> style="margin-top: 3px;">
                        <div>
                            <span style="font-weight: 600; color: #fff; font-size: 14px;">Moderation Updates</span>
                            <div style="font-size: 12px; color: #94a3b8;">Status notifications regarding your submitted reviews and comments.</div>
                        </div>
                    </label>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 12px;">
                        <button type="button" class="fmm-btn-action secondary" id="fmm-cancel-prefs-btn">Cancel</button>
                        <button type="submit" class="fmm-btn-action primary">Save Preferences</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Notification List -->
        <?php if (empty($notifications)): ?>
            <div style="background: #1e293b; border-radius: 8px; padding: 48px 24px; text-align: center; color: #94a3b8;">
                <div style="font-size: 40px; margin-bottom: 12px;">📭</div>
                <h3 style="color: #fff; font-size: 18px; margin: 0 0 6px 0;">No notifications</h3>
                <p style="margin: 0; font-size: 14px;">
                    <?= ($filter === 'unread') ? 'You have no unread notifications right now.' : 'You are completely caught up! Follow your favorite series and artists to receive release alerts.' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="fmm-notification-list" style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($notifications as $n): ?>
                    <?php
                        $icon = match ($n['type']) {
                            'new_episode'      => '📺',
                            'new_artist_song'  => '🎵',
                            'playlist_updated' => '📑',
                            'comment_reply'    => '💬',
                            'review_approved'  => '✅',
                            'review_rejected'  => '⚠️',
                            'comment_approved' => '✅',
                            'comment_rejected' => '⚠️',
                            default            => '🔔',
                        };
                    ?>
                    <div class="fmm-notification-item <?= empty($n['is_read']) ? 'unread' : 'read' ?>" data-notif-id="<?= (int)$n['id'] ?>">
                        <div class="fmm-notif-icon"><?= $icon ?></div>
                        <div class="fmm-notif-body">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px;">
                                <h4 class="fmm-notif-title"><?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                                <span class="fmm-notif-time"><?= htmlspecialchars($n['time_ago'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <p class="fmm-notif-message"><?= htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8') ?></p>
                            
                            <div class="fmm-notif-actions">
                                <?php if (!empty($n['is_available']) && !empty($n['target_url']) && $n['target_url'] !== '#'): ?>
                                    <a href="<?= htmlspecialchars($n['target_url'], ENT_QUOTES, 'UTF-8') ?>" class="fmm-notif-link">View Details &rarr;</a>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: #64748b; font-style: italic;">Item unavailable</span>
                                <?php endif; ?>

                                <?php if (empty($n['is_read'])): ?>
                                    <button type="button" class="fmm-btn-mark-read" data-fmm-read-action="<?= (int)$n['id'] ?>">Mark read</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if (!empty($totalPages) && $totalPages > 1): ?>
                <div style="display: flex; justify-content: center; gap: 8px; margin-top: 32px;">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <a href="/multimedia/notifications?filter=<?= urlencode($filter) ?>&p=<?= $p ?>" class="fav-pagination-link <?= ($p === $page) ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
    <?php include $componentsDir . '/mobile-nav.php'; ?>
    <script src="/plugins/favorite-multimedia/assets/js/multimedia-engagement.js?v=1.0.7"></script>
    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>

