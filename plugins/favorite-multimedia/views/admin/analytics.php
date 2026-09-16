<?php
/**
 * Favorite Multimedia — Admin Analytics & Business Intelligence Dashboard
 */
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <h2 class="fav-admin-title" style="margin:0;">📊 Multimedia Analytics &amp; Intelligence</h2>
            <p style="color:#64748b; font-size:13px; margin:4px 0 0 0;">
                Privacy-first reporting, content performance, completion insights &amp; operational metrics.
            </p>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <a href="/admin/page/multimedia-analytics?export=csv&report=<?php echo htmlspecialchars($tab === 'content' ? 'content_performance' : ($tab === 'overview' ? 'overview' : 'drop_off'), ENT_QUOTES, 'UTF-8'); ?>&range=<?php echo htmlspecialchars($range, ENT_QUOTES, 'UTF-8'); ?>&content_type=<?php echo htmlspecialchars($contentType, ENT_QUOTES, 'UTF-8'); ?>"
               class="fav-admin-btn fav-admin-btn-secondary" style="font-size:13px;">
                📥 Export CSV
            </a>
        </div>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link">📺 Web Series</a>
        <a href="/admin/page/multimedia-episodes" class="fav-admin-subnav-link">🎞️ Episodes</a>
        <a href="/admin/page/multimedia-songs" class="fav-admin-subnav-link">🎵 Songs</a>
        <a href="/admin/page/multimedia-playlists" class="fav-admin-subnav-link">🎼 Playlists</a>
        <a href="/admin/page/multimedia-localizations" class="fav-admin-subnav-link">🌐 Localizations</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link active">📊 Analytics</a>
        <a href="/admin/page/multimedia-processing" class="fav-admin-subnav-link">⚙️ Processing</a>
        <a href="/admin/page/multimedia-storage" class="fav-admin-subnav-link">💾 Storage</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <!-- Date Filter & Tab Selection Bar -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:20px;">
        <form method="GET" action="/admin/page/multimedia-analytics" style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:16px;">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($tab === 'content'): ?>
                <input type="hidden" name="content_type" value="<?php echo htmlspecialchars($contentType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>

            <!-- Date Range Controls -->
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span style="font-weight:600; font-size:13px; color:#475569;">Date Range:</span>
                <select name="range" class="fav-admin-select" style="font-size:13px; padding:6px 12px; width:auto;" onchange="this.form.submit()">
                    <option value="today" <?php echo $range === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="last_7_days" <?php echo $range === 'last_7_days' ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="last_30_days" <?php echo $range === 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                    <option value="last_90_days" <?php echo $range === 'last_90_days' ? 'selected' : ''; ?>>Last 90 Days</option>
                    <option value="all_time" <?php echo $range === 'all_time' ? 'selected' : ''; ?>>All Time</option>
                    <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>

                <?php if ($range === 'custom'): ?>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars(substr($dateFilter['start'], 0, 10), ENT_QUOTES, 'UTF-8'); ?>" class="fav-admin-input" style="font-size:13px; padding:6px 10px; width:auto;">
                    <span style="color:#94a3b8;">to</span>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars(substr($dateFilter['end'], 0, 10), ENT_QUOTES, 'UTF-8'); ?>" class="fav-admin-input" style="font-size:13px; padding:6px 10px; width:auto;">
                <?php endif; ?>

                <button type="submit" class="fav-admin-btn fav-admin-btn-primary" style="padding:6px 14px; font-size:13px;">Apply</button>
            </div>

            <!-- Active Date Label -->
            <div style="font-size:12px; color:#64748b; font-weight:500;">
                Showing: <strong><?php echo htmlspecialchars($dateFilter['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span style="color:#94a3b8; margin-left:6px;">(<?php echo htmlspecialchars($dateFilter['start'], ENT_QUOTES, 'UTF-8'); ?> &rarr; <?php echo htmlspecialchars($dateFilter['end'], ENT_QUOTES, 'UTF-8'); ?>)</span>
            </div>
        </form>

        <!-- Secondary Analytics Tab Navigation -->
        <div style="display:flex; gap:8px; border-top:1px solid #f1f5f9; padding-top:12px; margin-top:12px; overflow-x:auto;">
            <?php
            $tabs = [
                'overview'   => '📈 Overview',
                'content'    => '🎬 Content Performance',
                'engagement' => '❤️ Audience & Engagement',
                'funnel'     => '🔒 Premium & Discovery',
                'operations' => '⚙️ Operations & Storage',
            ];
            foreach ($tabs as $tKey => $tLabel):
                $active = ($tab === $tKey);
                $url = "/admin/page/multimedia-analytics?tab={$tKey}&range=" . urlencode($range);
            ?>
                <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                   style="padding:6px 14px; border-radius:6px; font-size:13px; font-weight:<?php echo $active ? '600' : '400'; ?>; text-decoration:none; <?php echo $active ? 'background:#2563eb; color:#fff;' : 'background:#f8fafc; color:#475569; border:1px solid #e2e8f0;'; ?>">
                    <?php echo $tLabel; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- TAB 1: OVERVIEW -->
    <?php if ($tab === 'overview'): ?>
        <!-- Summary Metric Cards -->
        <div class="fav-admin-stats-grid">
            <div class="fav-admin-stat-card">
                <p class="fav-admin-stat-label">Total Plays</p>
                <p class="fav-admin-stat-number" style="color: #10b981;"><?php echo number_format($overviewStats['total_plays'] ?? 0); ?></p>
                <p style="font-size:11px; color:#64748b; margin:4px 0 0 0;">Streams initiated</p>
            </div>
            <div class="fav-admin-stat-card">
                <p class="fav-admin-stat-label">Total Views</p>
                <p class="fav-admin-stat-number" style="color: #3b82f6;"><?php echo number_format($overviewStats['total_views'] ?? 0); ?></p>
                <p style="font-size:11px; color:#64748b; margin:4px 0 0 0;">Page &amp; detail views</p>
            </div>
            <div class="fav-admin-stat-card">
                <p class="fav-admin-stat-label">Unique Viewers</p>
                <p class="fav-admin-stat-number" style="color: #6366f1;"><?php echo number_format($overviewStats['unique_users'] ?? 0); ?></p>
                <p style="font-size:11px; color:#64748b; margin:4px 0 0 0;">Authenticated accounts</p>
            </div>
            <div class="fav-admin-stat-card">
                <p class="fav-admin-stat-label">Total Watch Time</p>
                <p class="fav-admin-stat-number" style="color: #8b5cf6;"><?php echo htmlspecialchars($overviewStats['watch_time_formatted'] ?? '0s', ENT_QUOTES, 'UTF-8'); ?></p>
                <p style="font-size:11px; color:#64748b; margin:4px 0 0 0;">Validated player progress</p>
            </div>
            <div class="fav-admin-stat-card">
                <p class="fav-admin-stat-label">Completion Rate</p>
                <p class="fav-admin-stat-number" style="color: #f59e0b;"><?php echo $overviewStats['completion_rate']; ?>%</p>
                <p style="font-size:11px; color:#64748b; margin:4px 0 0 0;">Canonical &ge; 90% threshold</p>
            </div>
            <div class="fav-admin-stat-card">
                <p class="fav-admin-stat-label">Favorites &amp; Rating</p>
                <p class="fav-admin-stat-number" style="color: #ec4899;"><?php echo number_format($overviewStats['favorites']); ?> ❤️</p>
                <p style="font-size:11px; color:#64748b; margin:4px 0 0 0;">★ <?php echo $overviewStats['avg_rating']; ?> (<?php echo number_format($overviewStats['total_ratings']); ?> ratings)</p>
            </div>
        </div>

        <!-- Drop-Off & Progress Distribution -->
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-top:20px;">
            <h3 style="margin:0 0 16px 0; font-size:15px; font-weight:600;">Audience Watch-Progress &amp; Drop-Off Buckets</h3>
            <?php
            $bucketLabels = [
                '0_10'   => '0% - 10% (Bounced / Early Exit)',
                '10_25'  => '10% - 25% (First Quarter)',
                '25_50'  => '25% - 50% (Midway Drop)',
                '50_75'  => '50% - 75% (Third Quarter)',
                '75_90'  => '75% - 90% (Late Drop-Off)',
                '90_100' => '90% - 100% (Completed Play)',
            ];
            $bucketColors = [
                '0_10'   => '#ef4444',
                '10_25'  => '#f97316',
                '25_50'  => '#f59e0b',
                '50_75'  => '#3b82f6',
                '75_90'  => '#6366f1',
                '90_100' => '#10b981',
            ];
            $totalProgSessions = $dropOff['total_sessions'] ?? 0;
            ?>
            <?php if ($totalProgSessions === 0): ?>
                <p style="color:#94a3b8; font-size:13px; margin:0;">No playback progress recorded in this date range.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($dropOff['buckets'] as $bKey => $bCount):
                        $bPct = $dropOff['percentages'][$bKey] ?? 0.0;
                        $color = $bucketColors[$bKey] ?? '#3b82f6';
                    ?>
                        <div>
                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                                <span style="font-weight:500;"><?php echo $bucketLabels[$bKey]; ?></span>
                                <span style="color:#64748b;"><?php echo number_format($bCount); ?> sessions (<strong><?php echo $bPct; ?>%</strong>)</span>
                            </div>
                            <div style="background:#f1f5f9; border-radius:4px; height:10px; overflow:hidden;">
                                <div style="background:<?php echo $color; ?>; width:<?php echo min(100, $bPct); ?>%; height:100%; border-radius:4px;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Top Content Quick View -->
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; font-size:15px; font-weight:600;">Top Performing Movies</h3>
                <a href="/admin/page/multimedia-analytics?tab=content&content_type=movie&range=<?php echo urlencode($range); ?>" style="font-size:12px; color:#2563eb; text-decoration:none;">View all &rarr;</a>
            </div>
            <table class="fav-admin-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Plays</th>
                        <th>Unique Viewers</th>
                        <th>Watch Time</th>
                        <th>Completion</th>
                        <th>Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($contentData['items'])): ?>
                        <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:20px;">No movie playback data in this range.</td></tr>
                    <?php else: foreach (array_slice($contentData['items'], 0, 5) as $topIt): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($topIt['localized_title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if ($topIt['localized_title'] !== $topIt['title']): ?>
                                    <span style="color:#94a3b8; font-size:11px;">(<?php echo htmlspecialchars($topIt['title'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td><span style="color:#10b981; font-weight:600;"><?php echo number_format($topIt['plays']); ?></span></td>
                            <td><?php echo number_format($topIt['unique_users']); ?></td>
                            <td><?php echo htmlspecialchars($topIt['watch_formatted'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $topIt['completion_rate']; ?>%</td>
                            <td>★ <?php echo $topIt['rating_avg']; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- TAB 2: CONTENT PERFORMANCE -->
    <?php if ($tab === 'content'): ?>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
            <!-- Content Type Filter Tabs -->
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                <div style="display:flex; gap:6px;">
                    <?php foreach (['movie' => '🎬 Movies', 'series' => '📺 Series', 'episode' => '🎞️ Episodes', 'song' => '🎵 Songs', 'playlist' => '🎼 Playlists'] as $cKey => $cName):
                        $cActive = ($contentType === $cKey);
                        $cUrl = "/admin/page/multimedia-analytics?tab=content&content_type={$cKey}&range=" . urlencode($range);
                    ?>
                        <a href="<?php echo htmlspecialchars($cUrl, ENT_QUOTES, 'UTF-8'); ?>"
                           style="padding:6px 12px; border-radius:4px; font-size:12px; text-decoration:none; <?php echo $cActive ? 'background:#0ea5e9; color:#fff; font-weight:600;' : 'background:#f1f5f9; color:#475569;'; ?>">
                            <?php echo $cName; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:12px; color:#64748b;">
                    Total in Catalog: <strong><?php echo number_format($contentData['total'] ?? 0); ?></strong>
                </div>
            </div>

            <!-- Content Performance Table -->
            <table class="fav-admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Access</th>
                        <th>
                            <a href="/admin/page/multimedia-analytics?tab=content&content_type=<?php echo urlencode($contentType); ?>&range=<?php echo urlencode($range); ?>&sort=plays&order=<?php echo ($sortBy === 'plays' && $sortOrder === 'DESC') ? 'ASC' : 'DESC'; ?>" style="color:inherit; text-decoration:none;">
                                Plays <?php echo $sortBy === 'plays' ? ($sortOrder === 'DESC' ? '▼' : '▲') : ''; ?>
                            </a>
                        </th>
                        <th>Views</th>
                        <th>Unique</th>
                        <th>Watch Time</th>
                        <th>Completion</th>
                        <th>Favorites</th>
                        <th>Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($contentData['items'])): ?>
                        <tr><td colspan="10" style="text-align:center; color:#94a3b8; padding:24px;">No items found.</td></tr>
                    <?php else: foreach ($contentData['items'] as $it): ?>
                        <tr>
                            <td>#<?php echo $it['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($it['localized_title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if ($it['localized_title'] !== $it['title']): ?>
                                    <div style="color:#94a3b8; font-size:11px;"><?php echo htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fav-badge fav-badge-<?php echo strtolower($it['access_mode']); ?>">
                                    <?php echo strtoupper($it['access_mode']); ?>
                                </span>
                            </td>
                            <td><span style="color:#10b981; font-weight:600;"><?php echo number_format($it['plays']); ?></span></td>
                            <td><?php echo number_format($it['views']); ?></td>
                            <td><?php echo number_format($it['unique_users']); ?></td>
                            <td><?php echo htmlspecialchars($it['watch_formatted'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <div style="background:#e2e8f0; width:50px; height:6px; border-radius:3px; overflow:hidden;">
                                        <div style="background:#10b981; width:<?php echo min(100, $it['completion_rate']); ?>%; height:100%;"></div>
                                    </div>
                                    <span style="font-size:11px; font-weight:600;"><?php echo $it['completion_rate']; ?>%</span>
                                </div>
                            </td>
                            <td><?php echo number_format($it['favorites']); ?></td>
                            <td>★ <?php echo $it['rating_avg']; ?> <span style="color:#94a3b8; font-size:11px;">(<?php echo $it['rating_count']; ?>)</span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- TAB 3: AUDIENCE & ENGAGEMENT -->
    <?php if ($tab === 'engagement'): ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px;">
            <!-- Ratings Distribution -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 16px 0; font-size:15px; font-weight:600;">⭐ Ratings Breakdown</h3>
                <div style="display:flex; align-items:center; gap:16px; margin-bottom:16px;">
                    <div style="font-size:36px; font-weight:700; color:#f59e0b;">
                        <?php echo $engagementStats['avg_rating'] ?? '0.0'; ?>
                    </div>
                    <div>
                        <div style="font-size:13px; font-weight:600;">Average Rating</div>
                        <div style="font-size:12px; color:#64748b;"><?php echo number_format($engagementStats['total_ratings'] ?? 0); ?> total reviews/ratings</div>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php
                    $dist = $engagementStats['distribution'] ?? [];
                    $totR = $engagementStats['total_ratings'] ?? 0;
                    for ($s = 5; $s >= 1; $s--):
                        $c = $dist[$s] ?? 0;
                        $pct = $totR > 0 ? round(($c / $totR) * 100, 1) : 0.0;
                    ?>
                        <div style="display:flex; align-items:center; gap:8px; font-size:12px;">
                            <span style="width:30px; font-weight:600;"><?php echo $s; ?> ★</span>
                            <div style="flex:1; background:#f1f5f9; border-radius:4px; height:8px; overflow:hidden;">
                                <div style="background:#f59e0b; width:<?php echo $pct; ?>%; height:100%;"></div>
                            </div>
                            <span style="width:70px; text-align:right; color:#64748b;"><?php echo number_format($c); ?> (<?php echo $pct; ?>%)</span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Follower Growth -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 16px 0; font-size:15px; font-weight:600;">👥 Subscription &amp; Follower Growth</h3>
                <div style="font-size:32px; font-weight:700; color:#3b82f6; margin-bottom:12px;">
                    +<?php echo number_format($followerGrowth['total_growth'] ?? 0); ?>
                </div>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:8px; background:#f8fafc; border-radius:6px;">
                        <span>📺 Web Series Followers</span>
                        <strong>+<?php echo number_format($followerGrowth['by_target']['series'] ?? 0); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:8px; background:#f8fafc; border-radius:6px;">
                        <span>🎤 Artist Followers</span>
                        <strong>+<?php echo number_format($followerGrowth['by_target']['artist'] ?? 0); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:8px; background:#f8fafc; border-radius:6px;">
                        <span>🎼 Playlist Subscribers</span>
                        <strong>+<?php echo number_format($followerGrowth['by_target']['playlist'] ?? 0); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Notifications Analytics -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 16px 0; font-size:15px; font-weight:600;">🔔 Notification Engagement</h3>
                <div style="font-size:32px; font-weight:700; color:#10b981; margin-bottom:4px;">
                    <?php echo $notificationData['read_rate'] ?? 0; ?>%
                </div>
                <p style="font-size:12px; color:#64748b; margin:0 0 16px 0;">Read rate of generated release alerts</p>
                <div style="display:flex; justify-content:space-between; font-size:13px; padding:8px; background:#f8fafc; border-radius:6px; margin-bottom:8px;">
                    <span>Generated Notifications</span>
                    <strong><?php echo number_format($notificationData['total_generated'] ?? 0); ?></strong>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:13px; padding:8px; background:#f8fafc; border-radius:6px;">
                    <span>Opened &amp; Read</span>
                    <strong><?php echo number_format($notificationData['read_count'] ?? 0); ?></strong>
                </div>
            </div>

            <!-- Resume Engagement -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 16px 0; font-size:15px; font-weight:600;">🔄 Continue Watching Retention</h3>
                <div style="font-size:32px; font-weight:700; color:#8b5cf6; margin-bottom:4px;">
                    <?php echo $resumeStats['resume_rate'] ?? 0; ?>%
                </div>
                <p style="font-size:12px; color:#64748b; margin:0 0 16px 0;">Active partial sessions resumed</p>
                <div style="display:flex; justify-content:space-between; font-size:13px; padding:8px; background:#f8fafc; border-radius:6px;">
                    <span>Partial Playback Sessions</span>
                    <strong><?php echo number_format($resumeStats['partial_sessions'] ?? 0); ?></strong>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 4: PREMIUM FUNNEL & DISCOVERY -->
    <?php if ($tab === 'funnel'): ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px;">
            <!-- Premium Gatekeeper Metrics -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 12px 0; font-size:15px; font-weight:600;">🔒 Premium Conversion &amp; Gatekeeper</h3>
                <p style="font-size:12px; color:#64748b; margin:0 0 16px 0;">
                    Monitors access outcomes. <em>Favorite Digital is the exclusive entitlement authority; Favorite Pay is checkout infrastructure.</em>
                </p>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#f8fafc; border-radius:6px;">
                        <span>Total Premium Attempts</span>
                        <strong><?php echo number_format($premiumFunnel['total_attempts'] ?? 0); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#ecfdf5; border-radius:6px; color:#065f46;">
                        <span>✅ Entitled Plays (Favorite Digital Active)</span>
                        <strong><?php echo number_format($premiumFunnel['entitled_plays'] ?? 0); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#fef2f2; border-radius:6px; color:#991b1b;">
                        <span>⛔ Premium Required Denials (403)</span>
                        <strong><?php echo number_format($premiumFunnel['premium_denials'] ?? 0); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#f0f9ff; border-radius:6px; color:#0369a1;">
                        <span>Conversion / Entitlement Rate</span>
                        <strong><?php echo $premiumFunnel['conversion_rate'] ?? 0; ?>%</strong>
                    </div>
                </div>
            </div>

            <!-- Language & Track Usage -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 12px 0; font-size:15px; font-weight:600;">🌐 Multi-Language Usage</h3>
                <p style="font-size:12px; color:#64748b; margin:0 0 16px 0;">
                    Aggregate preference distribution. Subtitles enabled: <strong><?php echo $languageStats['subtitle_enabled_rate'] ?? 100; ?>%</strong>
                </p>
                <h4 style="font-size:13px; margin:12px 0 8px 0;">Audio Languages</h4>
                <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:16px;">
                    <?php if (empty($languageStats['audio_distribution'])): ?>
                        <p style="font-size:12px; color:#94a3b8; margin:0;">No language preferences recorded yet.</p>
                    <?php else: foreach ($languageStats['audio_distribution'] as $a): ?>
                        <div style="display:flex; justify-content:space-between; font-size:12px;">
                            <span><?php echo htmlspecialchars($a['label'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($a['code'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                            <span style="font-weight:600;"><?php echo $a['percentage']; ?>%</span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <h4 style="font-size:13px; margin:12px 0 8px 0;">Subtitle Languages</h4>
                <div style="display:flex; flex-direction:column; gap:6px;">
                    <?php if (empty($languageStats['subtitle_distribution'])): ?>
                        <p style="font-size:12px; color:#94a3b8; margin:0;">No subtitle preferences recorded yet.</p>
                    <?php else: foreach ($languageStats['subtitle_distribution'] as $s): ?>
                        <div style="display:flex; justify-content:space-between; font-size:12px;">
                            <span><?php echo htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($s['code'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                            <span style="font-weight:600;"><?php echo $s['percentage']; ?>%</span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Discovery Attribution -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 12px 0; font-size:15px; font-weight:600;">🧭 Discovery Attribution</h3>
                <p style="font-size:12px; color:#64748b; margin:0 0 16px 0;">Traffic source breakdown</p>
                <table class="fav-admin-table" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>Source Tag</th>
                            <th>Plays</th>
                            <th>Views</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (($discoveryData ?? []) as $sTag => $sCounts): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($sTag, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><strong><?php echo number_format($sCounts['plays']); ?></strong></td>
                                <td><?php echo number_format($sCounts['views']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 5: OPERATIONS & STORAGE -->
    <?php if ($tab === 'operations'): ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px;">
            <!-- Processing Jobs Stats -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 12px 0; font-size:15px; font-weight:600;">⚙️ Media Transcoding Pipeline (Phase 10)</h3>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#ecfdf5; border-radius:6px; color:#065f46;">
                        <span>Completed Jobs</span>
                        <strong><?php echo number_format($operationalStats['processing']['completed'] ?? 0); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#fef2f2; border-radius:6px; color:#991b1b;">
                        <span>Failed Jobs</span>
                        <strong><?php echo number_format($operationalStats['processing']['failed'] ?? 0); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#f8fafc; border-radius:6px;">
                        <span>Pending / Processing</span>
                        <strong><?php echo number_format($operationalStats['processing']['pending'] ?? 0); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#f8fafc; border-radius:6px;">
                        <span>Average Duration</span>
                        <strong><?php echo $operationalStats['processing']['avg_duration_sec'] ?? 0; ?>s</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#f8fafc; border-radius:6px;">
                        <span>Failure Rate</span>
                        <strong><?php echo $operationalStats['processing']['failure_rate'] ?? 0; ?>%</strong>
                    </div>
                </div>
            </div>

            <!-- Storage Utilization -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h3 style="margin:0 0 12px 0; font-size:15px; font-weight:600;">💾 Storage Utilization (Phase 11)</h3>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#f8fafc; border-radius:6px;">
                        <span>Local Disk Files</span>
                        <strong><?php echo number_format($operationalStats['storage']['local_files'] ?? 0); ?> (<?php echo $operationalStats['storage']['local_formatted'] ?? '0 B'; ?>)</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#f8fafc; border-radius:6px;">
                        <span>S3 Object Storage Files</span>
                        <strong><?php echo number_format($operationalStats['storage']['s3_files'] ?? 0); ?> (<?php echo $operationalStats['storage']['s3_formatted'] ?? '0 B'; ?>)</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; padding:10px; background:#f0fdf4; border-radius:6px; color:#166534; font-weight:600;">
                        <span>Total Media Storage</span>
                        <span><?php echo $operationalStats['storage']['total_formatted'] ?? '0 B'; ?></span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recent Media Activity Log (All Tabs) -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-top:20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px 0; font-weight:600;">Recent Audit Activity Log (Last 50 events in range)</h3>
        <table class="fav-admin-table">
            <thead>
                <tr>
                    <th>Event Type</th>
                    <th>Content Type</th>
                    <th>Content ID</th>
                    <th>User</th>
                    <th>Source</th>
                    <th>IP Hash</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentEvents)): ?>
                    <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:20px;">No events recorded in this date range.</td></tr>
                <?php else: foreach ($recentEvents as $ev): ?>
                    <tr>
                        <td>
                            <?php
                            $badgeClass = match ($ev->event_type) {
                                'play'           => 'fav-badge-public',
                                'download'       => 'fav-badge-login',
                                'premium_denied' => 'fav-badge-premium',
                                default          => 'fav-badge-login',
                            };
                            ?>
                            <span class="fav-badge <?php echo $badgeClass; ?>"><?php echo strtoupper($ev->event_type); ?></span>
                        </td>
                        <td><?php echo ucfirst($ev->content_type); ?></td>
                        <td>#<?php echo $ev->content_id; ?></td>
                        <td><?php echo $ev->user_id ? "User #{$ev->user_id}" : 'Guest'; ?></td>
                        <td><code><?php echo htmlspecialchars($ev->discovery_source ?? 'direct', ENT_QUOTES, 'UTF-8'); ?></code></td>
                        <td><code><?php echo htmlspecialchars($ev->ip_hash ?: '—', ENT_QUOTES, 'UTF-8'); ?></code></td>
                        <td><?php echo $ev->created_at; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
