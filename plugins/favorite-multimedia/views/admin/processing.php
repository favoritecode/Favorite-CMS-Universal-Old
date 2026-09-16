<?php
/**
 * Favorite Multimedia — Admin Media Processing & Transcoding Queue View
 */

$capabilities = $ffmpegCapabilities ?? \FavoriteCMS\Multimedia\Services\FFmpegService::getCapabilities();
$jobList = $jobs ?? [];
$counts = $statusCounts ?? \FavoriteCMS\Multimedia\Models\MediaProcessingJob::countByStatus();
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="fav-admin-title" style="margin: 0;">⚙️ Media Processing &amp; Transcoding Pipeline</h2>
        <div style="display: flex; gap: 10px;">
            <form method="POST" action="/admin/page/multimedia-processing" style="margin: 0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="run_queue">
                <button type="submit" class="fav-admin-btn fav-admin-btn-primary" onclick="return confirm('Process pending media transcoding jobs now?');">
                    ⚡ Process Queue Now
                </button>
            </form>
        </div>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link">📺 Web Series</a>
        <a href="/admin/page/multimedia-episodes" class="fav-admin-subnav-link">🎞️ Episodes</a>
        <a href="/admin/page/multimedia-songs" class="fav-admin-subnav-link">🎵 Songs</a>
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link">🎛️ Sources</a>
        <a href="/admin/page/multimedia-releases" class="fav-admin-subnav-link">📅 Releases</a>
        <a href="/admin/page/multimedia-processing" class="fav-admin-subnav-link active">⚙️ Processing</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <!-- FFmpeg Capability Diagnostic Widget -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; margin-bottom:24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <h4 style="margin: 0 0 6px 0; font-size: 15px; color: #1e293b;">
                    🎞️ FFmpeg Integration Engine
                </h4>
                <div style="font-size: 13px; color: #64748b;">
                    <?php if (!empty($capabilities['available'])): ?>
                        <span style="color: #10b981; font-weight: 600;">● Active</span> — FFmpeg <?php echo htmlspecialchars((string)($capabilities['version'] ?? '')); ?> detected at <code><?php echo htmlspecialchars((string)($capabilities['ffmpeg_path'] ?? '')); ?></code>
                    <?php else: ?>
                        <span style="color: #f59e0b; font-weight: 600;">● Standby (Optional)</span> — FFmpeg binary not detected on host system. Direct MP4/HLS/embed streaming operates normally.
                    <?php endif; ?>
                </div>
            </div>
            <div style="display: flex; gap: 8px;">
                <span class="fav-badge <?php echo !empty($capabilities['available']) ? 'fav-badge-published' : 'fav-badge-draft'; ?>">
                    FFmpeg: <?php echo !empty($capabilities['available']) ? 'Ready' : 'Not Found'; ?>
                </span>
                <span class="fav-badge <?php echo !empty($capabilities['probe_available']) ? 'fav-badge-published' : 'fav-badge-draft'; ?>">
                    FFprobe: <?php echo !empty($capabilities['probe_available']) ? 'Ready' : 'Not Found'; ?>
                </span>
                <span class="fav-badge fav-badge-scheduled">
                    Adaptive HLS: <?php echo !empty($capabilities['available']) ? 'Supported' : 'Fallback Mode'; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Operational Metrics -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="fav-admin-card" style="padding: 16px; border-radius: 8px; background: #fff; border: 1px solid #e2e8f0;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">⏳ Pending Jobs</div>
            <div style="font-size: 26px; font-weight: 700; color: #f59e0b; margin-top: 4px;">
                <?php echo (int)($counts['pending'] ?? 0); ?>
            </div>
        </div>
        <div class="fav-admin-card" style="padding: 16px; border-radius: 8px; background: #fff; border: 1px solid #e2e8f0;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">⚡ In Progress</div>
            <div style="font-size: 26px; font-weight: 700; color: #3b82f6; margin-top: 4px;">
                <?php echo (int)($counts['processing'] ?? 0); ?>
            </div>
        </div>
        <div class="fav-admin-card" style="padding: 16px; border-radius: 8px; background: #fff; border: 1px solid #e2e8f0;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">✅ Completed</div>
            <div style="font-size: 26px; font-weight: 700; color: #10b981; margin-top: 4px;">
                <?php echo (int)($counts['completed'] ?? 0); ?>
            </div>
        </div>
        <div class="fav-admin-card" style="padding: 16px; border-radius: 8px; background: #fff; border: 1px solid #e2e8f0;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">❌ Failed</div>
            <div style="font-size: 26px; font-weight: 700; color: #ef4444; margin-top: 4px;">
                <?php echo (int)($counts['failed'] ?? 0); ?>
            </div>
        </div>
    </div>

    <!-- Transcoding Queue Table -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; color: #1e293b;">
                📋 Transcoding &amp; Processing Queue
            </h3>
            <span style="font-size: 13px; color: #64748b;">
                Showing <?php echo count($jobList); ?> recent jobs
            </span>
        </div>

        <?php if (empty($jobList)): ?>
            <div style="padding: 40px; text-align: center; color: #94a3b8;">
                <div style="font-size: 32px; margin-bottom: 8px;">✨</div>
                No media processing jobs in queue. Upload new media in Movies, Episodes, or Songs to trigger transcoding.
            </div>
        <?php else: ?>
            <table class="fav-admin-table" style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; text-align: left;">
                        <th style="padding: 12px 16px;">Job ID</th>
                        <th style="padding: 12px 16px;">Target Content</th>
                        <th style="padding: 12px 16px;">Pipeline Type</th>
                        <th style="padding: 12px 16px;">Status</th>
                        <th style="padding: 12px 16px;">Progress</th>
                        <th style="padding: 12px 16px;">Created</th>
                        <th style="padding: 12px 16px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobList as $job): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px 16px; font-weight: 600; color: #475569;">
                                #<?php echo (int)$job->id; ?>
                            </td>
                            <td style="padding: 12px 16px;">
                                <div style="font-weight: 600; color: #1e293b;">
                                    <?php echo htmlspecialchars($job->getContentTitle()); ?>
                                </div>
                                <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase;">
                                    <?php echo htmlspecialchars((string)$job->content_type); ?> #<?php echo (int)$job->content_id; ?>
                                </div>
                            </td>
                            <td style="padding: 12px 16px;">
                                <span class="fav-badge fav-badge-scheduled">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', (string)$job->job_type)); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 16px;">
                                <?php if ($job->isCompleted()): ?>
                                    <span class="fav-badge fav-badge-published">Completed</span>
                                <?php elseif ($job->isProcessing()): ?>
                                    <span class="fav-badge" style="background:#dbeafe; color:#1e40af;">Processing</span>
                                <?php elseif ($job->isPending()): ?>
                                    <span class="fav-badge fav-badge-draft">Pending</span>
                                <?php elseif ($job->isFailed()): ?>
                                    <span class="fav-badge" style="background:#fee2e2; color:#b91c1c;">Failed</span>
                                <?php else: ?>
                                    <span class="fav-badge fav-badge-unpublished"><?php echo htmlspecialchars((string)$job->status); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; min-width: 140px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                                        <div style="height: 100%; width: <?php echo (int)$job->progress; ?>%; background: <?php echo $job->isFailed() ? '#ef4444' : '#10b981'; ?>;"></div>
                                    </div>
                                    <span style="font-size: 12px; color: #64748b; font-weight: 600;">
                                        <?php echo (int)$job->progress; ?>%
                                    </span>
                                </div>
                                <?php if (!empty($job->error_message)): ?>
                                    <div style="font-size: 11px; color: #ef4444; margin-top: 4px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars((string)$job->error_message); ?>">
                                        ⚠️ <?php echo htmlspecialchars((string)$job->error_message); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; font-size: 12px; color: #64748b;">
                                <?php echo htmlspecialchars((string)$job->created_at); ?>
                            </td>
                            <td style="padding: 12px 16px; text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <?php if ($job->canRetry()): ?>
                                        <form method="POST" action="/admin/page/multimedia-processing" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="retry_job">
                                            <input type="hidden" name="job_id" value="<?php echo (int)$job->id; ?>">
                                            <button type="submit" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 4px 8px; font-size: 12px;">
                                                🔄 Retry
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($job->canCancel()): ?>
                                        <form method="POST" action="/admin/page/multimedia-processing" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="cancel_job">
                                            <input type="hidden" name="job_id" value="<?php echo (int)$job->id; ?>">
                                            <button type="submit" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 4px 8px; font-size: 12px; color:#ef4444;" onclick="return confirm('Cancel this processing job?');">
                                                ⏹️ Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
