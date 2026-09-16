<?php
/**
 * Favorite Multimedia — Admin Release Calendar & Editorial Automation View
 */

$appTimezone = $timezone ?? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::getAppTimezone();
$scheduledQueue = $queue ?? [];
$calendarItems = $calendarEvents ?? [];
$currentMonth = $month ?? date('Y-m');
$currentTypeFilter = $typeFilter ?? '';
$currentStatusFilter = $statusFilter ?? '';
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="fav-admin-title" style="margin: 0;">📅 Editorial Release Calendar &amp; Automation</h2>
        <div style="display: flex; gap: 10px;">
            <form method="POST" action="/admin/page/multimedia-releases" style="margin: 0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="run_due">
                <button type="submit" class="fav-admin-btn fav-admin-btn-primary" onclick="return confirm('Run due release processor now?');">
                    ⚡ Run Due Releases Now
                </button>
            </form>
        </div>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link">📺 Web Series</a>
        <a href="/admin/page/multimedia-seasons" class="fav-admin-subnav-link">📼 Seasons</a>
        <a href="/admin/page/multimedia-episodes" class="fav-admin-subnav-link">🎞️ Episodes</a>
        <a href="/admin/page/multimedia-songs" class="fav-admin-subnav-link">🎵 Songs</a>
        <a href="/admin/page/multimedia-playlists" class="fav-admin-subnav-link">🎼 Playlists</a>
        <a href="/admin/page/multimedia-genres" class="fav-admin-subnav-link">🏷️ Genres</a>
        <a href="/admin/page/multimedia-artists" class="fav-admin-subnav-link">🎤 Artists</a>
        <a href="/admin/page/multimedia-albums" class="fav-admin-subnav-link">💿 Albums</a>
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link">🎛️ Sources</a>
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link">💬 Subtitles</a>
        <a href="/admin/page/multimedia-access" class="fav-admin-subnav-link">🔐 Access Control</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-moderation" class="fav-admin-subnav-link">🛡️ Moderation</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
        <a href="/admin/page/multimedia-releases" class="fav-admin-subnav-link active">📅 Releases</a>
    </div>

    <!-- Operational Metrics -->
    <div class="fav-admin-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="fav-admin-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
            <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase;">Scheduled Total</div>
            <div style="font-size:28px; font-weight:700; color:#2563eb; margin-top:4px;"><?php echo (int)($metrics['scheduled_total'] ?? 0); ?></div>
            <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Awaiting release timestamp</div>
        </div>

        <div class="fav-admin-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
            <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase;">Due / Overdue</div>
            <div style="font-size:28px; font-weight:700; color:<?php echo (!empty($metrics['due_overdue'])) ? '#dc2626' : '#16a34a'; ?>; margin-top:4px;">
                <?php echo (int)($metrics['due_overdue'] ?? 0); ?>
            </div>
            <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Ready for immediate publication</div>
        </div>

        <div class="fav-admin-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
            <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase;">Published Today</div>
            <div style="font-size:28px; font-weight:700; color:#0f172a; margin-top:4px;"><?php echo (int)($metrics['published_today'] ?? 0); ?></div>
            <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Past 24 hours</div>
        </div>

        <div class="fav-admin-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
            <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase;">System Timezone</div>
            <div style="font-size:20px; font-weight:700; color:#475569; margin-top:8px; word-break:break-all;">
                <?php echo htmlspecialchars($appTimezone, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div style="font-size:12px; color:#94a3b8; margin-top:4px;">UTC storage, localized display</div>
        </div>
    </div>

    <!-- Filters & Month Navigation -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:24px; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:16px;">
        <form method="GET" action="/admin/page/multimedia-releases" style="display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin:0;">
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:13px; font-weight:600; color:#475569;">Month:</label>
                <input type="month" name="month" value="<?php echo htmlspecialchars($currentMonth, ENT_QUOTES, 'UTF-8'); ?>" class="fav-form-control" style="padding:6px 10px; font-size:13px;">
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:13px; font-weight:600; color:#475569;">Type:</label>
                <select name="type" class="fav-form-control" style="padding:6px 10px; font-size:13px;">
                    <option value="">All Types</option>
                    <option value="movie" <?php echo ($currentTypeFilter === 'movie') ? 'selected' : ''; ?>>🎬 Movies</option>
                    <option value="series" <?php echo ($currentTypeFilter === 'series') ? 'selected' : ''; ?>>📺 Series</option>
                    <option value="episode" <?php echo ($currentTypeFilter === 'episode') ? 'selected' : ''; ?>>🎞️ Episodes</option>
                    <option value="song" <?php echo ($currentTypeFilter === 'song') ? 'selected' : ''; ?>>🎵 Songs</option>
                </select>
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:13px; font-weight:600; color:#475569;">Status:</label>
                <select name="status" class="fav-form-control" style="padding:6px 10px; font-size:13px;">
                    <option value="">All Statuses</option>
                    <option value="scheduled" <?php echo ($currentStatusFilter === 'scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="published" <?php echo ($currentStatusFilter === 'published') ? 'selected' : ''; ?>>Published</option>
                    <option value="draft" <?php echo ($currentStatusFilter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                    <option value="unpublished" <?php echo ($currentStatusFilter === 'unpublished') ? 'selected' : ''; ?>>Unpublished</option>
                </select>
            </div>

            <button type="submit" class="fav-admin-btn fav-admin-btn-secondary" style="padding:6px 14px; font-size:13px;">Apply Filters</button>
            <?php if ($currentMonth !== date('Y-m') || $currentTypeFilter !== '' || $currentStatusFilter !== ''): ?>
                <a href="/admin/page/multimedia-releases" style="font-size:13px; color:#64748b; text-decoration:underline;">Reset</a>
            <?php endif; ?>
        </form>

        <!-- View Switcher -->
        <div style="display:flex; gap:6px;">
            <button type="button" class="fav-admin-btn fav-admin-btn-secondary view-toggle-btn active" data-view="queue" onclick="switchReleaseView('queue')">
                📋 Queue (<?php echo count($scheduledQueue); ?>)
            </button>
            <button type="button" class="fav-admin-btn fav-admin-btn-secondary view-toggle-btn" data-view="calendar" onclick="switchReleaseView('calendar')">
                🗓️ Calendar View (<?php echo count($calendarItems); ?>)
            </button>
        </div>
    </div>

    <!-- Section 1: Scheduled Queue View -->
    <div id="release-view-queue" class="release-view-pane" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:30px;">
        <div style="padding:14px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:15px; font-weight:600; color:#0f172a;">
                ⏳ Scheduled Releases Queue
            </h3>
            <span style="font-size:12px; color:#64748b;">
                Showing <?php echo count($scheduledQueue); ?> pending scheduled items
            </span>
        </div>

        <?php if (empty($scheduledQueue)): ?>
            <div style="padding:40px; text-align:center; color:#94a3b8;">
                <div style="font-size:36px; margin-bottom:8px;">🏖️</div>
                <div style="font-size:14px; font-weight:500;">No items currently scheduled for release.</div>
                <div style="font-size:13px; margin-top:4px;">You can schedule movies, episodes, series, or songs from their respective edit forms.</div>
            </div>
        <?php else: ?>
            <table class="fav-admin-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f1f5f9; text-align:left; border-bottom:1px solid #e2e8f0;">
                        <th style="padding:10px 14px; font-weight:600; color:#475569;">Type</th>
                        <th style="padding:10px 14px; font-weight:600; color:#475569;">Title / Content</th>
                        <th style="padding:10px 14px; font-weight:600; color:#475569;">Scheduled Release (<?php echo htmlspecialchars($appTimezone, ENT_QUOTES, 'UTF-8'); ?>)</th>
                        <th style="padding:10px 14px; font-weight:600; color:#475569;">Unpublish Date</th>
                        <th style="padding:10px 14px; font-weight:600; color:#475569;">Status</th>
                        <th style="padding:10px 14px; font-weight:600; color:#475569; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scheduledQueue as $item):
                        $isOverdue = (!empty($item['publish_at']) && strtotime($item['publish_at']) <= time());
                        $typeIcon = match($item['content_type']) {
                            'movie'   => '🎬 Movie',
                            'series'  => '📺 Series',
                            'episode' => '🎞️ Episode',
                            'song'    => '🎵 Song',
                            default   => '📄 Content',
                        };
                        $editUrl = match($item['content_type']) {
                            'movie'   => '/admin/page/multimedia-movies?edit=' . (int)$item['id'],
                            'series'  => '/admin/page/multimedia-series?edit=' . (int)$item['id'],
                            'episode' => '/admin/page/multimedia-episodes?edit=' . (int)$item['id'],
                            'song'    => '/admin/page/multimedia-songs?edit=' . (int)$item['id'],
                            default   => '#',
                        };
                    ?>
                        <tr style="border-bottom:1px solid #f1f5f9; <?php echo $isOverdue ? 'background:#fef2f2;' : ''; ?>">
                            <td style="padding:10px 14px;">
                                <span style="display:inline-block; padding:2px 8px; border-radius:4px; font-size:12px; font-weight:600; background:#e0f2fe; color:#0369a1;">
                                    <?php echo htmlspecialchars($typeIcon, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="padding:10px 14px;">
                                <a href="<?php echo $editUrl; ?>" style="font-weight:600; color:#0f172a; text-decoration:none;">
                                    <?php echo htmlspecialchars($item['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if (!empty($item['parent_title'])): ?>
                                    <div style="font-size:11px; color:#64748b;">
                                        Series: <?php echo htmlspecialchars($item['parent_title'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 14px;">
                                <div style="font-weight:500; color:<?php echo $isOverdue ? '#dc2626' : '#0f172a'; ?>;">
                                    <?php echo htmlspecialchars($item['publish_at_local'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php if ($isOverdue): ?>
                                    <span style="font-size:11px; font-weight:600; color:#dc2626;">⚠️ Due for release</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 14px; color:#64748b;">
                                <?php echo !empty($item['unpublish_at_local']) ? htmlspecialchars($item['unpublish_at_local'], ENT_QUOTES, 'UTF-8') : '—'; ?>
                            </td>
                            <td style="padding:10px 14px;">
                                <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; background:#fef3c7; color:#b45309;">
                                    Scheduled
                                </span>
                            </td>
                            <td style="padding:10px 14px; text-align:right;">
                                <div style="display:inline-flex; gap:6px; align-items:center;">
                                    <form method="POST" action="/admin/page/multimedia-releases" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="publish_now">
                                        <input type="hidden" name="content_type" value="<?php echo htmlspecialchars($item['content_type'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="content_id" value="<?php echo (int)$item['id']; ?>">
                                        <button type="submit" class="fav-admin-btn fav-admin-btn-primary" style="padding:4px 8px; font-size:12px;" title="Publish Now" onclick="return confirm('Publish immediately? This will make the item live and notify followers.');">
                                            🚀 Publish Now
                                        </button>
                                    </form>

                                    <form method="POST" action="/admin/page/multimedia-releases" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="content_type" value="<?php echo htmlspecialchars($item['content_type'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="content_id" value="<?php echo (int)$item['id']; ?>">
                                        <button type="submit" class="fav-admin-btn fav-admin-btn-secondary" style="padding:4px 8px; font-size:12px;" title="Cancel Schedule" onclick="return confirm('Cancel schedule and revert item to draft?');">
                                            ✕ Cancel
                                        </button>
                                    </form>

                                    <a href="<?php echo $editUrl; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding:4px 8px; font-size:12px; text-decoration:none;">
                                        ✏️ Edit
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Section 2: Editorial Calendar View -->
    <div id="release-view-calendar" class="release-view-pane" style="display:none; background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:30px;">
        <div style="padding:14px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:15px; font-weight:600; color:#0f172a;">
                🗓️ Release Timeline (<?php echo htmlspecialchars($currentMonth, ENT_QUOTES, 'UTF-8'); ?>)
            </h3>
            <span style="font-size:12px; color:#64748b;">
                Grouped by local release dates in <?php echo htmlspecialchars($appTimezone, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

        <?php if (empty($calendarItems)): ?>
            <div style="padding:40px; text-align:center; color:#94a3b8;">
                <div style="font-size:36px; margin-bottom:8px;">📅</div>
                <div style="font-size:14px; font-weight:500;">No releases found for <?php echo htmlspecialchars($currentMonth, ENT_QUOTES, 'UTF-8'); ?>.</div>
                <div style="font-size:13px; margin-top:4px;">Try selecting another month or adjusting your filters.</div>
            </div>
        <?php else:
            // Group events by local date
            $groupedByDate = [];
            foreach ($calendarItems as $evt) {
                $rawDate = $evt['publish_at_local'] ?? $evt['published_at_local'] ?? '';
                $dayKey = !empty($rawDate) ? substr($rawDate, 0, 10) : 'Unknown Date';
                $groupedByDate[$dayKey][] = $evt;
            }
            ksort($groupedByDate);
        ?>
            <div style="padding:20px; display:flex; flex-direction:column; gap:20px;">
                <?php foreach ($groupedByDate as $dateKey => $events): ?>
                    <div style="border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                        <div style="background:#f1f5f9; padding:8px 14px; font-weight:700; font-size:13px; color:#334155; display:flex; justify-content:space-between;">
                            <span>📆 <?php echo htmlspecialchars($dateKey, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span style="font-weight:500; font-size:12px; color:#64748b;"><?php echo count($events); ?> release(s)</span>
                        </div>
                        <div style="padding:10px 14px; display:flex; flex-direction:column; gap:8px;">
                            <?php foreach ($events as $e):
                                $badgeStyle = match($e['status']) {
                                    'published' => 'background:#dcfce7; color:#166534;',
                                    'scheduled' => 'background:#fef3c7; color:#b45309;',
                                    'draft'     => 'background:#f1f5f9; color:#475569;',
                                    default     => 'background:#fee2e2; color:#991b1b;',
                                };
                                $typeIcon = match($e['content_type']) {
                                    'movie'   => '🎬',
                                    'series'  => '📺',
                                    'episode' => '🎞️',
                                    'song'    => '🎵',
                                    default   => '📄',
                                };
                                $timeDisplay = !empty($e['publish_at_local']) ? substr($e['publish_at_local'], 11, 5) : (!empty($e['published_at_local']) ? substr($e['published_at_local'], 11, 5) : '');
                            ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 10px; background:#fafafa; border:1px solid #f1f5f9; border-radius:4px;">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <span style="font-size:16px;"><?php echo $typeIcon; ?></span>
                                        <div>
                                            <span style="font-weight:600; font-size:13px; color:#0f172a;">
                                                <?php echo htmlspecialchars($e['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <?php if (!empty($timeDisplay)): ?>
                                                <span style="font-size:11px; color:#64748b; margin-left:6px;">at <?php echo htmlspecialchars($timeDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; <?php echo $badgeStyle; ?>">
                                            <?php echo htmlspecialchars(ucfirst($e['status']), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function switchReleaseView(view) {
    document.querySelectorAll('.release-view-pane').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.view-toggle-btn').forEach(btn => btn.classList.remove('active'));

    const pane = document.getElementById('release-view-' + view);
    const btn = document.querySelector('.view-toggle-btn[data-view="' + view + '"]');
    if (pane) pane.style.display = 'block';
    if (btn) btn.classList.add('active');
}
</script>

