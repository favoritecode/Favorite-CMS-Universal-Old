<?php
/**
 * Favorite Multimedia — Admin Community Moderation Dashboard
 *
 * Provides a unified administrative queue for reviewing user submissions,
 * community abuse reports, reviews, and comments.
 */
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="wrap fav-mm-admin">
    <div class="fav-mm-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="font-size:24px; font-weight:700; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
            Community &amp; Content Moderation Queue
        </h1>
        <div style="font-size:14px; color:#64748b;">
            Pending Submissions: <strong><?php echo (int)($counts['pending_content'] ?? 0); ?></strong> |
            Pending Reviews: <strong><?php echo (int)($counts['pending_reviews'] ?? 0); ?></strong> |
            Open Reports: <strong><?php echo (int)($counts['open_reports'] ?? 0); ?></strong>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="notice notice-success" style="padding:12px; background:#dcfce7; color:#166534; border-radius:6px; margin-bottom:16px;">
            <?php echo htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div style="display:flex; gap:8px; border-bottom:2px solid #e2e8f0; margin-bottom:24px; overflow-x:auto;">
        <a href="/admin/page/multimedia-moderation?tab=pending_content" style="padding:10px 18px; text-decoration:none; font-weight:600; font-size:14px; border-bottom:3px solid <?php echo $tab === 'pending_content' ? '#2563eb' : 'transparent'; ?>; color:<?php echo $tab === 'pending_content' ? '#2563eb' : '#64748b'; ?>; white-space:nowrap;">
            Pending Submissions (<?php echo (int)($counts['pending_content'] ?? 0); ?>)
        </a>
        <a href="/admin/page/multimedia-moderation?tab=pending_reviews" style="padding:10px 18px; text-decoration:none; font-weight:600; font-size:14px; border-bottom:3px solid <?php echo $tab === 'pending_reviews' ? '#2563eb' : 'transparent'; ?>; color:<?php echo $tab === 'pending_reviews' ? '#2563eb' : '#64748b'; ?>; white-space:nowrap;">
            Pending Reviews (<?php echo (int)($counts['pending_reviews'] ?? 0); ?>)
        </a>
        <a href="/admin/page/multimedia-moderation?tab=reported_reviews" style="padding:10px 18px; text-decoration:none; font-weight:600; font-size:14px; border-bottom:3px solid <?php echo $tab === 'reported_reviews' ? '#2563eb' : 'transparent'; ?>; color:<?php echo $tab === 'reported_reviews' ? '#2563eb' : '#64748b'; ?>; white-space:nowrap;">
            Reported Reviews
        </a>
        <a href="/admin/page/multimedia-moderation?tab=comments" style="padding:10px 18px; text-decoration:none; font-weight:600; font-size:14px; border-bottom:3px solid <?php echo $tab === 'comments' ? '#2563eb' : 'transparent'; ?>; color:<?php echo $tab === 'comments' ? '#2563eb' : '#64748b'; ?>; white-space:nowrap;">
            All Comments
        </a>
        <a href="/admin/page/multimedia-moderation?tab=reports" style="padding:10px 18px; text-decoration:none; font-weight:600; font-size:14px; border-bottom:3px solid <?php echo $tab === 'reports' ? '#2563eb' : 'transparent'; ?>; color:<?php echo $tab === 'reports' ? '#2563eb' : '#64748b'; ?>; white-space:nowrap;">
            All Reports (<?php echo (int)($counts['open_reports'] ?? 0); ?>)
        </a>
    </div>

    <!-- Items Table -->
    <div style="background:#fff; border-radius:8px; border:1px solid #e2e8f0; overflow:hidden;">
        <?php if (empty($items)): ?>
            <div style="padding:48px; text-align:center; color:#64748b;">
                <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 10px auto; display:block; opacity:0.6;">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <div style="font-weight:600; font-size:16px;">Queue is clear!</div>
                <p style="margin:4px 0 0 0; font-size:14px;">No items requiring moderation in this view.</p>
            </div>
        <?php else: ?>
            <table style="width:100%; border-collapse:collapse; text-align:left; font-size:14px;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; color:#475569;">
                        <th style="padding:12px 16px;">ID</th>
                        <th style="padding:12px 16px;">Author / Creator</th>
                        <th style="padding:12px 16px;">Target</th>
                        <th style="padding:12px 16px;">Content / Title</th>
                        <th style="padding:12px 16px;">Status</th>
                        <th style="padding:12px 16px;">Submitted</th>
                        <th style="padding:12px 16px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px 16px; color:#64748b;">#<?php echo (int)$item->id; ?></td>
                            <td style="padding:12px 16px;">
                                <?php
                                $author = \FavoriteCMS\Multimedia\Services\MultimediaEngagementService::formatAuthorDisplay((int)($item->user_id ?? $item->reporter_user_id ?? 0));
                                echo htmlspecialchars($author['display_name'], ENT_QUOTES, 'UTF-8');
                                ?>
                            </td>
                            <td style="padding:12px 16px; color:#334155; font-weight:600;">
                                <?php if (!empty($item->content_type)): ?>
                                    <span style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:12px; text-transform:uppercase;">
                                        <?php echo htmlspecialchars($item->content_type, ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int)$item->id; ?>
                                    </span>
                                <?php elseif (!empty($item->target_type)): ?>
                                    <span style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:12px; text-transform:uppercase;">
                                        <?php echo htmlspecialchars($item->target_type, ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int)$item->target_id; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px; max-width:350px;">
                                <?php if (!empty($item->title)): ?>
                                    <div style="font-weight:600; margin-bottom:4px; color:#0f172a;">
                                        <?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($tab !== 'pending_content'): ?>
                                    <div style="color:#334155; line-height:1.4; word-break:break-word;">
                                        <?php echo nl2br(htmlspecialchars($item->body ?? ($item->reason . (!empty($item->notes) ? ': ' . $item->notes : '')), ENT_QUOTES, 'UTF-8')); ?>
                                    </div>
                                    <?php if (!empty($item->rating)): ?>
                                        <div style="color:#eab308; font-size:12px; margin-top:4px;">
                                            <?php echo str_repeat('★', (int)$item->rating); ?> (<?php echo (int)$item->rating; ?>/5)
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item->contains_spoiler)): ?>
                                        <span style="display:inline-block; font-size:11px; background:#fef3c7; color:#92400e; padding:1px 5px; border-radius:3px; margin-top:4px;">Contains Spoiler</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px;">
                                <?php
                                $st = (string)($item->status ?? 'open');
                                $bg = match($st) {
                                    'published', 'approved', 'actioned' => '#dcfce7; color:#166534;',
                                    'pending', 'open'      => '#fef3c7; color:#92400e;',
                                    'rejected', 'dismissed'=> '#fee2e2; color:#991b1b;',
                                    default                => '#f1f5f9; color:#475569;',
                                };
                                ?>
                                <span style="display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:600; background:<?php echo $bg; ?>">
                                    <?php echo ucfirst(htmlspecialchars($st, ENT_QUOTES, 'UTF-8')); ?>
                                </span>
                            </td>
                            <td style="padding:12px 16px; color:#64748b; font-size:13px;">
                                <?php echo htmlspecialchars(substr((string)$item->created_at, 0, 16), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:12px 16px; text-align:right; white-space:nowrap;">
                                <?php if ($tab === 'pending_content'): ?>
                                    <?php
                                    $editUrl = match (strtolower((string)$item->content_type)) {
                                        'movie'    => "/admin/page/multimedia-movies?edit=" . (int)$item->id,
                                        'series'   => "/admin/page/multimedia-series?edit=" . (int)$item->id,
                                        'episode'  => "/admin/page/multimedia-episodes?edit=" . (int)$item->id,
                                        'song'     => "/admin/page/multimedia-songs?edit=" . (int)$item->id,
                                        'album'    => "/admin/page/multimedia-albums?edit=" . (int)$item->id,
                                        'playlist' => "/admin/page/multimedia-playlists?edit=" . (int)$item->id,
                                        default    => "#",
                                    };
                                    $previewUrl = match (strtolower((string)$item->content_type)) {
                                        'movie'    => "/movie/" . rawurlencode((string)$item->slug) . "?preview=1",
                                        'series'   => "/series/" . rawurlencode((string)$item->slug) . "?preview=1",
                                        'episode'  => "/episode/" . rawurlencode((string)$item->slug) . "?preview=1",
                                        'song'     => "/song/" . rawurlencode((string)$item->slug) . "?preview=1",
                                        'album'    => "/multimedia/album/" . rawurlencode((string)$item->slug) . "?preview=1",
                                        'playlist' => "/playlist/" . rawurlencode((string)$item->slug) . "?preview=1",
                                        default    => "#",
                                    };
                                    ?>
                                    <a href="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="button" style="font-size:12px; padding:3px 8px; margin-right:4px; text-decoration:none; background:#0ea5e9; color:#fff; border-color:#0ea5e9;">Preview</a>
                                    <a href="<?php echo htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'); ?>" class="button" style="font-size:12px; padding:3px 8px; margin-right:4px; text-decoration:none;">Review</a>
                                    <form method="POST" action="/admin/page/multimedia-moderation" style="display:inline-block;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="target_type" value="content">
                                        <input type="hidden" name="content_type" value="<?php echo htmlspecialchars($item->content_type, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="target_id" value="<?php echo (int)$item->id; ?>">
                                        <button type="submit" name="action" value="approve" class="button" style="font-size:12px; padding:3px 8px; margin-right:4px; background:#16a34a; color:#fff; border-color:#16a34a;">Approve</button>
                                        <button type="button" class="button" style="font-size:12px; padding:3px 8px; background:#dc2626; color:#fff; border-color:#dc2626;" onclick="var r = prompt('Enter rejection reason:'); if (r !== null) { this.form.rejection_reason.value = r; this.form.action_val.value = 'reject'; this.form.submit(); }">Reject</button>
                                        <input type="hidden" name="action" value="reject" id="action_val">
                                        <input type="hidden" name="rejection_reason" value="">
                                    </form>
                                <?php elseif ($tab === 'reports' || !empty($item->reporter_user_id)): ?>
                                    <!-- Report actions -->
                                    <form method="POST" action="/admin/page/multimedia-moderation" style="display:inline-block;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="target_type" value="report">
                                        <input type="hidden" name="target_id" value="<?php echo (int)($item->report_id ?? $item->id); ?>">
                                        <button type="submit" name="action" value="dismiss" class="button" style="font-size:12px; padding:3px 8px; margin-right:4px;">Dismiss</button>
                                        <button type="submit" name="action" value="action" class="button" style="font-size:12px; padding:3px 8px; background:#dc2626; color:#fff; border-color:#dc2626;">Actioned</button>
                                    </form>
                                <?php else: ?>
                                    <!-- Review / Comment actions -->
                                    <form method="POST" action="/admin/page/multimedia-moderation" style="display:inline-block;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="target_type" value="<?php echo str_contains($tab, 'comment') ? 'comment' : 'review'; ?>">
                                        <input type="hidden" name="target_id" value="<?php echo (int)$item->id; ?>">
                                        
                                        <?php if ($st !== 'approved'): ?>
                                            <button type="submit" name="action" value="approve" class="button" style="font-size:12px; padding:3px 8px; margin-right:4px; background:#16a34a; color:#fff; border-color:#16a34a;">Approve</button>
                                        <?php endif; ?>
                                        <?php if ($st !== 'rejected'): ?>
                                            <button type="submit" name="action" value="reject" class="button" style="font-size:12px; padding:3px 8px; margin-right:4px;">Reject</button>
                                        <?php endif; ?>
                                        <button type="submit" name="action" value="delete" class="button" style="font-size:12px; padding:3px 8px; color:#dc2626; border-color:#fca5a5;" onclick="return confirm('Permanently delete this item?');">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div style="padding:16px; display:flex; justify-content:center; gap:8px; border-top:1px solid #e2e8f0;">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <a href="/admin/page/multimedia-moderation?tab=<?php echo urlencode($tab); ?>&p=<?php echo $p; ?>"
                           style="padding:6px 12px; border-radius:4px; text-decoration:none; font-size:13px; font-weight:600; background:<?php echo $p === $page ? '#2563eb' : '#f1f5f9'; ?>; color:<?php echo $p === $page ? '#fff' : '#334155'; ?>;">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
