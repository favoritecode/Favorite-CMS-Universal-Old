<?php
/**
 * Favorite Multimedia — My Submissions View
 *
 * Scoped content dashboard for creators/non-admin authors.
 */
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2 class="fav-admin-title" style="margin: 0; font-size: 22px; font-weight: 700; color: #0f172a;">My Multimedia Submissions</h2>
            <p style="margin: 4px 0 0 0; color: #64748b; font-size: 13px;">Manage your media creations, check review status, and make updates.</p>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php if (\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::canUserSubmit('movie')): ?>
                <a href="/admin/page/multimedia-movies?new=1" class="fav-admin-btn fav-admin-btn-primary" style="font-size: 12px; padding: 6px 14px;">+ Add Movie</a>
            <?php endif; ?>
            <?php if (\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::canUserSubmit('song')): ?>
                <a href="/admin/page/multimedia-songs?new=1" class="fav-admin-btn fav-admin-btn-primary" style="font-size: 12px; padding: 6px 14px;">+ Add Song</a>
            <?php endif; ?>
            <?php if (\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::canUserSubmit('series')): ?>
                <a href="/admin/page/multimedia-series?new=1" class="fav-admin-btn fav-admin-btn-secondary" style="font-size: 12px; padding: 6px 14px;">+ Add Series</a>
            <?php endif; ?>
            <?php if (\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::canUserSubmit('playlist')): ?>
                <a href="/admin/page/multimedia-playlists?new=1" class="fav-admin-btn fav-admin-btn-secondary" style="font-size: 12px; padding: 6px 14px;">+ New Playlist</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Filter Tabs -->
    <div style="display: flex; gap: 8px; border-bottom: 2px solid #e2e8f0; margin-bottom: 16px; overflow-x: auto;">
        <?php
        $filterType = $filterType ?? 'all';
        $typeCounts = $typeCounts ?? [];
        $statuses = [
            'all'       => 'All Items',
            'pending'   => 'Pending Review',
            'published' => 'Published',
            'draft'     => 'Drafts',
            'rejected'  => 'Action Needed (Rejected)',
        ];
        foreach ($statuses as $stKey => $stLabel):
            $isActive = ($filterStatus === $stKey);
            $cnt = $counts[$stKey] ?? 0;
            $color = $isActive ? '#2563eb' : '#64748b';
            $border = $isActive ? '#2563eb' : 'transparent';
            $statusUrl = '/admin/page/multimedia-my-submissions?status=' . urlencode($stKey) . ($filterType !== 'all' ? '&type=' . urlencode($filterType) : '');
        ?>
            <a href="<?= htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') ?>"
               style="padding: 10px 16px; text-decoration: none; font-weight: 600; font-size: 13px; border-bottom: 3px solid <?= $border ?>; color: <?= $color ?>; white-space: nowrap;">
                <?= htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8') ?>
                <span style="background: <?= $isActive ? '#dbeafe' : '#f1f5f9' ?>; color: <?= $isActive ? '#1e40af' : '#475569' ?>; padding: 2px 7px; border-radius: 12px; font-size: 11px; margin-left: 4px;">
                    <?= (int)$cnt ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Content Type Filter Pills -->
    <div style="display: flex; gap: 8px; margin-bottom: 20px; overflow-x: auto; align-items: center; flex-wrap: wrap;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b;">Content Type:</span>
        <?php
        $types = [
            'all'      => 'All Types',
            'movie'    => 'Movies',
            'series'   => 'Series',
            'episode'  => 'Episodes',
            'song'     => 'Songs',
            'album'    => 'Albums',
            'playlist' => 'Playlists',
        ];
        foreach ($types as $tKey => $tLabel):
            $isTypeActive = ($filterType === $tKey);
            $tCnt = $typeCounts[$tKey] ?? 0;
            $bg = $isTypeActive ? '#1e293b' : '#f1f5f9';
            $textColor = $isTypeActive ? '#ffffff' : '#475569';
            $typeUrl = '/admin/page/multimedia-my-submissions?' . ($filterStatus !== 'all' ? 'status=' . urlencode($filterStatus) . '&' : '') . 'type=' . urlencode($tKey);
        ?>
            <a href="<?= htmlspecialchars($typeUrl, ENT_QUOTES, 'UTF-8') ?>"
               style="padding: 4px 12px; border-radius: 16px; text-decoration: none; font-size: 12px; font-weight: 600; background: <?= $bg ?>; color: <?= $textColor ?>; display: inline-flex; align-items: center; gap: 6px;">
                <?= htmlspecialchars($tLabel, ENT_QUOTES, 'UTF-8') ?>
                <span style="background: <?= $isTypeActive ? 'rgba(255,255,255,0.2)' : '#e2e8f0' ?>; color: <?= $isTypeActive ? '#ffffff' : '#334155' ?>; padding: 1px 6px; border-radius: 10px; font-size: 10px;">
                    <?= (int)$tCnt ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Submissions Table -->
    <div style="background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden;">
        <?php if (empty($items)): ?>
            <div style="padding: 48px; text-align: center; color: #64748b;">
                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 12px auto; display: block; opacity: 0.6;">
                    <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect>
                    <line x1="7" y1="2" x2="7" y2="22"></line>
                    <line x1="17" y1="2" x2="17" y2="22"></line>
                    <line x1="2" y1="12" x2="22" y2="12"></line>
                </svg>
                <div style="font-weight: 600; font-size: 15px; color: #1e293b;">No submissions found</div>
                <p style="margin: 4px 0 16px 0; font-size: 13px;">You have no media items under the "<?= htmlspecialchars(ucfirst($filterStatus), ENT_QUOTES, 'UTF-8') ?>" filter.</p>
                <a href="/admin/page/multimedia-movies?new=1" class="fav-admin-btn fav-admin-btn-primary" style="font-size: 13px; padding: 8px 18px;">Create Your First Movie</a>
            </div>
        <?php else: ?>
            <table class="fav-admin-table" style="width: 100%; font-size: 13px;">
                <thead>
                    <tr>
                        <th style="width: 90px;">Type</th>
                        <th>Title</th>
                        <th style="width: 130px;">Status</th>
                        <th style="width: 140px;">Last Updated</th>
                        <th style="text-align: right; width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        $cType = strtolower((string)$item->content_type);
                        $st = (string)$item->status;
                        $editUrl = match ($cType) {
                            'movie'    => "/admin/page/multimedia-movies?edit=" . (int)$item->id,
                            'series'   => "/admin/page/multimedia-series?edit=" . (int)$item->id,
                            'episode'  => "/admin/page/multimedia-episodes?edit=" . (int)$item->id,
                            'song'     => "/admin/page/multimedia-songs?edit=" . (int)$item->id,
                            'album'    => "/admin/page/multimedia-albums?edit=" . (int)$item->id,
                            'playlist' => "/admin/page/multimedia-playlists?edit=" . (int)$item->id,
                            default    => "#",
                        };
                        $viewUrl = match ($cType) {
                            'movie'    => "/movie/" . urlencode((string)$item->slug),
                            'series'   => "/series/" . urlencode((string)$item->slug),
                            'episode'  => "/episode/" . urlencode((string)$item->slug),
                            'song'     => "/song/" . urlencode((string)$item->slug),
                            'playlist' => "/playlist/" . urlencode((string)$item->slug),
                            'album'    => "/multimedia/album/" . urlencode((string)$item->slug),
                            default    => "#",
                        };
                    ?>
                        <tr>
                            <td>
                                <span class="fav-badge fav-badge-gray" style="text-transform: uppercase; font-size: 10px; font-weight: 700;">
                                    <?= htmlspecialchars($cType, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars((string)$item->title, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (!empty($item->slug)): ?>
                                    <div style="font-size: 11px; color: #64748b;">/<?= htmlspecialchars($cType, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars((string)$item->slug, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if ($st === 'rejected' && !empty($item->rejection_reason)): ?>
                                    <div style="margin-top: 6px; padding: 6px 10px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 4px; font-size: 11px; color: #991b1b;">
                                        <strong>Editorial Feedback:</strong> <?= htmlspecialchars((string)$item->rejection_reason, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($st === 'published'): ?>
                                    <span class="fav-badge fav-badge-green">Published</span>
                                <?php elseif ($st === 'pending'): ?>
                                    <span class="fav-badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;">Pending Review</span>
                                <?php elseif ($st === 'rejected'): ?>
                                    <span class="fav-badge" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;">Action Needed</span>
                                <?php elseif ($st === 'scheduled'): ?>
                                    <span class="fav-badge" style="background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe;">Scheduled</span>
                                <?php else: ?>
                                    <span class="fav-badge fav-badge-gray">Draft</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: #64748b; font-size: 12px;">
                                <?= !empty($item->updated_at) ? date('M j, Y H:i', strtotime((string)$item->updated_at)) : '—' ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" class="fav-admin-btn" style="padding: 3px 8px; font-size: 11px;">Edit</a>
                                <?php if ($st === 'rejected'): ?>
                                    <form method="POST" action="/admin/page/multimedia-my-submissions" style="display:inline-block; margin-left: 4px;" onsubmit="return confirm('Submit this item for review?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="resubmit">
                                        <input type="hidden" name="content_type" value="<?= htmlspecialchars($cType, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="id" value="<?= (int)$item->id ?>">
                                        <button type="submit" class="fav-admin-btn fav-admin-btn-primary" style="padding: 3px 8px; font-size: 11px; cursor: pointer;">Resubmit</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($st === 'published' && $viewUrl !== '#'): ?>
                                    <a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 3px 8px; font-size: 11px;">View</a>
                                <?php endif; ?>
                                <button type="button" class="fav-admin-btn fav-admin-btn-danger fmm-delete-single-btn" data-type="<?= htmlspecialchars($cType, ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int)$item->id ?>" data-title="<?= htmlspecialchars((string)$item->title, ENT_QUOTES, 'UTF-8') ?>" style="padding: 3px 8px; font-size: 11px; margin-left: 4px;">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script src="/plugins/favorite-multimedia/assets/js/multimedia-admin.js"></script>


