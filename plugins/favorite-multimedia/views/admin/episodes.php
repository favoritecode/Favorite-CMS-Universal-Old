<?php
/**
 * Favorite Multimedia — Admin Episodes View
 */
$isEditing = ($editEpisode !== null);
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🎞️ Episodes Management</h2>
        <a href="/admin/page/multimedia-seasons" class="fav-admin-btn fav-admin-btn-secondary">&larr; Back to Seasons</a>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link">📺 Web Series</a>
        <a href="/admin/page/multimedia-seasons" class="fav-admin-subnav-link">📼 Seasons</a>
        <a href="/admin/page/multimedia-episodes" class="fav-admin-subnav-link active">🎞️ Episodes</a>
        <a href="/admin/page/multimedia-songs" class="fav-admin-subnav-link">🎵 Songs</a>
        <a href="/admin/page/multimedia-playlists" class="fav-admin-subnav-link">🎼 Playlists</a>
        <a href="/admin/page/multimedia-genres" class="fav-admin-subnav-link">🏷️ Genres</a>
        <a href="/admin/page/multimedia-artists" class="fav-admin-subnav-link">🎤 Artists</a>
        <a href="/admin/page/multimedia-albums" class="fav-admin-subnav-link">💿 Albums</a>
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link">🎛️ Advanced Sources</a>
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link">💬 Subtitles</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-moderation" class="fav-admin-subnav-link">🛡️ Moderation</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
        <a href="/admin/page/multimedia-releases" class="fav-admin-subnav-link">📅 Releases</a>
    </div>

    <!-- Filter by Season -->
    <div style="margin-bottom: 20px; background: #fff; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 6px;">
        <form method="GET" action="/admin/page/multimedia-episodes" style="display:flex; align-items:center; gap:8px;">
            <label style="font-weight: 600; margin-right: 4px;">Filter by Season:</label>
            <select name="season_id" class="fav-form-control" style="width: auto; display: inline-block;" onchange="this.form.submit()">
                <option value="0">-- All Seasons --</option>
                <?php foreach ($seasons as $s): ?>
                    <option value="<?php echo $s->id; ?>" <?php echo ($s->id == $selectedSeasonId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s->getSeries()?->title . ' — ' . $s->title, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Episode Form -->
    <div class="fav-admin-stat-card" style="margin-bottom: 24px;">
        <h3 style="margin-top:0; font-size:16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
            <?php echo $isEditing ? 'Edit Episode: ' . htmlspecialchars($editEpisode->title, ENT_QUOTES, 'UTF-8') : '+ Add New Episode'; ?>
        </h3>

        <?php 
            $curSeries = $editEpisode ? $editEpisode->getSeries() : null;
            $parentSeriesAccess = $curSeries ? ($curSeries->access_mode ?? 'public') : 'public';
        ?>
        <div class="fav-hierarchy-banner">
            <strong>Parent Series Access:</strong> <span class="fav-badge fav-badge-<?php echo strtolower($parentSeriesAccess); ?>"><?php echo strtoupper($parentSeriesAccess); ?></span>
            &nbsp;|&nbsp; 
            <strong>Free Pilot Option:</strong> You can set this episode to <code>PUBLIC</code> to allow free streaming of a pilot episode even when the series is <code>PREMIUM</code>!
        </div>

        <form method="POST" action="/admin/page/multimedia-episodes" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
            <input type="hidden" name="id" value="<?php echo $editEpisode->id ?? 0; ?>">

            <div class="fav-form-row">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Parent Season *</label>
                        <select name="season_id" class="fav-form-control" required id="fav_ep_season">
                            <?php foreach ($seasons as $s): ?>
                                <option value="<?php echo $s->id; ?>" data-series="<?php echo $s->series_id; ?>" data-series-access="<?php echo $s->getSeries()?->access_mode ?? 'public'; ?>" <?php echo (($editEpisode?->season_id ?? $selectedSeasonId) == $s->id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s->getSeries()?->title . ' — ' . $s->title, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="series_id" id="fav_ep_series" value="<?php echo $editEpisode?->series_id ?? ($seasons[0]->series_id ?? 0); ?>">
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Episode Number *</label>
                        <input type="number" name="episode_number" class="fav-form-control" value="<?php echo $editEpisode->episode_number ?? (count($episodes) + 1); ?>" required min="1">
                        <span class="fav-form-hint">Sequential episode number in season</span>
                    </div>
                </div>
            </div>

            <div class="fav-form-row">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Episode Title *</label>
                        <input type="text" name="title" id="fav_ep_title" class="fav-form-control" value="<?php echo htmlspecialchars($editEpisode->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" required placeholder="e.g. Pilot">
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Slug</label>
                        <input type="text" name="slug" id="fav_ep_slug" class="fav-form-control" value="<?php echo htmlspecialchars($editEpisode->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="auto-generated from title">
                        <span class="fav-form-hint">URL: /episode/your-slug</span>
                    </div>
                </div>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Synopsis</label>
                <textarea name="description" class="fav-form-control" rows="3" placeholder="Episode summary..."><?php echo htmlspecialchars($editEpisode->description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- Episode Video / Media Stream -->
            <div class="fav-form-section" style="border: 2px solid #3b82f6; background: #f0f7ff;">
            <?php try { ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #bfdbfe; padding-bottom: 8px;">
                    <h4 style="margin:0; font-size: 15px; font-weight: 700; color: #1e40af; display: flex; align-items: center; gap: 8px;">
                        🎥 Episode Video / Media Stream
                        <?php if ($isEditing && $editEpisode): ?>
                            <?php $curStatus = $mediaStatuses[$editEpisode->id] ?? ['status' => 'no_media', 'label' => 'No Media', 'class' => 'fav-badge-gray']; ?>
                            <span class="fav-badge <?= htmlspecialchars($curStatus['class'] ?? 'fav-badge-gray', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($curStatus['label'] ?? 'No Media', ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </h4>
                    <?php if ($isEditing && $editEpisode): ?>
                        <a href="/admin/page/multimedia-sources?content_type=episode&content_id=<?= (int)$editEpisode->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size: 11px; padding: 3px 8px;" target="_blank">🎛️ Advanced Sources &rarr;</a>
                    <?php endif; ?>
                </div>

                <?php 
                    $curDefault = ($isEditing && $editEpisode) ? ($defaultSources[$editEpisode->id] ?? null) : null;
                    $uploadMax = $uploadMax ?? (ini_get('upload_max_filesize') ?: '2M');
                    $postMax = $postMax ?? (ini_get('post_max_size') ?: '8M');
                    $ffmpegActive = $ffmpegActive ?? false;
                ?>

                <?php if ($curDefault): ?>
                    <?php
                        $streamType = strtoupper((string)(is_object($curDefault) ? ($curDefault->source_type ?? 'video') : ($curDefault['source_type'] ?? 'video')));
                        $streamLabel = (string)(is_object($curDefault) ? ($curDefault->label ?? '') : ($curDefault['label'] ?? ''));
                        if ($streamLabel === '') { $streamLabel = 'Main Stream'; }
                        $streamUrl = (string)(is_object($curDefault) ? ($curDefault->url_or_path ?? '') : ($curDefault['url_or_path'] ?? ''));
                    ?>
                    <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; font-size: 13px;">
                        <strong>Current Default Stream:</strong> <code><?= htmlspecialchars($streamType, ENT_QUOTES, 'UTF-8') ?></code> &bull;
                        <span style="color: #475569;"><?= htmlspecialchars($streamLabel, ENT_QUOTES, 'UTF-8') ?></span> &bull;
                        <a href="<?= htmlspecialchars($streamUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color: #2563eb; word-break: break-all;"><?= htmlspecialchars($streamUrl, ENT_QUOTES, 'UTF-8') ?></a>
                    </div>
                <?php endif; ?>

                <div class="fav-media-tabs">
                    <button type="button" class="fav-media-tab-btn active" id="fav_tab_btn_upload" onclick="switchMediaTab('upload')">📁 Upload Video File</button>
                    <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_url" onclick="switchMediaTab('url')">🔗 Direct Video URL</button>
                    <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_hls" onclick="switchMediaTab('hls')">📡 HLS Stream (.m3u8)</button>
                    <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_youtube" onclick="switchMediaTab('youtube')">📺 YouTube</button>
                    <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_vimeo" onclick="switchMediaTab('vimeo')">📼 Vimeo</button>
                    <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_embed" onclick="switchMediaTab('embed')">🌐 External Embed</button>
                </div>
                <input type="hidden" name="source_type" id="fav_source_type_input" value="auto">

                <!-- Upload Pane -->
                <div id="fav_pane_upload" style="display: block;">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Select Video File (MP4, WebM, MKV, MOV)</label>
                        <input type="file" name="video_file" id="fav_video_file" class="fav-form-control" accept="video/mp4,video/webm,video/quicktime,video/x-matroska">
                        <span class="fav-form-hint">Server upload limit: <strong><?= htmlspecialchars($uploadMax) ?></strong> (post_max_size: <?= htmlspecialchars($postMax) ?>). For large files, use a direct URL.</span>
                        <?php if ($ffmpegActive): ?>
                            <div style="font-size: 12px; color: #15803d; margin-top: 5px; font-weight: 500;">✓ FFmpeg is active: uploaded MP4 files will automatically generate multi-bitrate HLS streams.</div>
                        <?php else: ?>
                            <div style="font-size: 12px; color: #b45309; margin-top: 5px; font-weight: 500;">ℹ FFmpeg is not installed: direct MP4/WebM uploads will play directly in HTML5 player without transcoding.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- URL / Stream Pane -->
                <div id="fav_pane_url" style="display: none;">
                    <div class="fav-form-group">
                        <label class="fav-form-label" id="fav_video_url_label">Direct Video URL (MP4, WebM)</label>
                        <input type="text" name="video_url" id="fav_video_url" class="fav-form-control" placeholder="https://example.com/episodes/ep1.mp4">
                        <span class="fav-form-hint" id="fav_video_url_hint">Direct HTTPS link to MP4 or WebM video file.</span>
                    </div>
                </div>

                <div class="fav-form-row" style="margin-top: 10px;">
                    <div class="fav-form-col">
                        <div class="fav-form-group" style="margin-bottom: 0;">
                            <label class="fav-form-label">Stream Label (Optional)</label>
                            <input type="text" name="source_label" class="fav-form-control" placeholder="e.g. 1080p Web Stream">
                        </div>
                    </div>
                </div>

                <div class="fav-form-group" style="margin-top: 10px; margin-bottom: 0;">
                    <label class="fav-form-label">Primary / Legacy Download URL (Optional)</label>
                    <input type="text" name="download_url" class="fav-form-control" value="<?php echo htmlspecialchars($editEpisode->download_url ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://cdn.example.com/files/episode.mp4">
                    <span class="fav-form-hint">Quick direct download link. You can also configure multiple download options below.</span>
                </div>

                <!-- Multiple Download Links Section -->
                <div style="margin-top: 16px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div>
                            <h5 style="margin: 0; font-size: 14px; color: #1e3a8a; font-weight: 700;">Download Links (<?= !empty($downloadSources) ? count($downloadSources) : 0 ?>)</h5>
                            <span style="font-size: 12px; color: #64748b;">Add multiple qualities or mirror links for this episode.</span>
                        </div>
                        <button type="button" class="fav-admin-btn fav-admin-btn-secondary" id="fav_btn_add_ep_dl" style="font-size: 12px; padding: 4px 10px;">+ Add Download Link</button>
                    </div>
                    <table class="fav-admin-table" id="fav_table_ep_dl" style="font-size: 12px; margin-top: 8px; <?= empty($downloadSources) ? 'display: none;' : '' ?>">
                        <thead>
                            <tr>
                                <th style="width: 50px;">Order</th>
                                <th style="width: 150px;">Label</th>
                                <th>Download URL</th>
                                <th style="width: 75px;">Quality</th>
                                <th style="width: 70px;">Format</th>
                                <th style="width: 90px;">Provider</th>
                                <th style="width: 55px; text-align: center;">Active</th>
                                <th style="width: 50px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="fav_tbody_ep_dl">
                            <?php if (!empty($downloadSources)): ?>
                                <?php foreach ($downloadSources as $dIdx => $dl): ?>
                                    <tr class="fav-dl-row" data-id="<?= (int)$dl->id ?>">
                                        <td>
                                            <input type="number" name="download_sources[<?= $dIdx ?>][sort_order]" class="fav-form-control" value="<?= (int)$dl->sort_order ?>" style="width: 50px; padding: 3px; text-align: center;">
                                            <input type="hidden" name="download_sources[<?= $dIdx ?>][id]" value="<?= (int)$dl->id ?>">
                                        </td>
                                        <td>
                                            <input type="text" name="download_sources[<?= $dIdx ?>][label]" class="fav-form-control" value="<?= htmlspecialchars($dl->label ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 1080p Drive" style="padding: 3px 6px;">
                                        </td>
                                        <td>
                                            <input type="text" name="download_sources[<?= $dIdx ?>][url]" class="fav-form-control" value="<?= htmlspecialchars($dl->url ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="https://..." style="padding: 3px 6px;">
                                        </td>
                                        <td>
                                            <input type="text" name="download_sources[<?= $dIdx ?>][quality]" class="fav-form-control" value="<?= htmlspecialchars($dl->quality ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="1080p" style="padding: 3px 6px;">
                                        </td>
                                        <td>
                                            <input type="text" name="download_sources[<?= $dIdx ?>][format]" class="fav-form-control" value="<?= htmlspecialchars($dl->format ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="MP4" style="padding: 3px 6px;">
                                        </td>
                                        <td>
                                            <input type="text" name="download_sources[<?= $dIdx ?>][provider]" class="fav-form-control" value="<?= htmlspecialchars($dl->provider ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Drive" style="padding: 3px 6px;">
                                        </td>
                                        <td style="text-align: center;">
                                            <input type="checkbox" name="download_sources[<?= $dIdx ?>][is_active]" value="1" <?= (!empty($dl->is_active)) ? 'checked' : '' ?>>
                                        </td>
                                        <td style="text-align: right;">
                                            <button type="button" class="fav-admin-btn fav-admin-btn-danger fav-btn-remove-dl" style="padding: 1px 6px; font-size: 11px;">✕</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div id="fav_empty_ep_dl" style="font-size: 12px; color: #94a3b8; font-style: italic; padding: 6px 0; <?= !empty($downloadSources) ? 'display: none;' : '' ?>">
                        No additional download links configured. Click "+ Add Download Link" to add one.
                    </div>
                    <div id="fav_del_ep_dl_container"></div>
                </div>

                <!-- Configured Attached Sources Table (if editing) -->
                <?php if ($isEditing && $editEpisode && !empty($attachedSources)): ?>
                    <div style="margin-top: 16px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <h5 style="margin: 0; font-size: 14px; color: #1e3a8a; font-weight: 700;">Configured Playback Sources (<?= count($attachedSources) ?>)</h5>
                            <span style="font-size: 12px; color: #64748b;">Primary source plays by default; viewer source switcher is displayed if 2+ active sources exist.</span>
                        </div>
                        <table class="fav-admin-table" style="margin-top: 8px; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">Order</th>
                                    <th>Label</th>
                                    <th>Format</th>
                                    <th>Location / URL</th>
                                    <th>Default</th>
                                    <th>Status</th>
                                    <th style="text-align: right; width: 140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attachedSources as $idx => $s): ?>
                                    <tr style="<?= ($s->status === 'inactive') ? 'opacity: 0.6;' : '' ?>">
                                        <td>
                                            <button type="submit" form="form_reorder_up_ep_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 1px 5px; font-size: 11px;" <?= ($idx === 0) ? 'disabled' : '' ?>>▲</button>
                                            <button type="submit" form="form_reorder_dn_ep_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 1px 5px; font-size: 11px;" <?= ($idx === count($attachedSources) - 1) ? 'disabled' : '' ?>>▼</button>
                                        </td>
                                        <td><strong><?= htmlspecialchars($s->label ?: 'Stream', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td><code><?= htmlspecialchars(strtoupper((string)($s->source_type ?? 'video')), ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <a href="<?= htmlspecialchars((string)$s->url_or_path, ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color: #2563eb;"><?= htmlspecialchars((string)$s->url_or_path, ENT_QUOTES, 'UTF-8') ?></a>
                                        </td>
                                        <td>
                                            <?php if (!empty($s->is_default)): ?>
                                                <span class="fav-badge fav-badge-green">⭐ Primary</span>
                                            <?php else: ?>
                                                <button type="submit" form="form_setdefault_ep_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 6px; font-size: 11px;">Set Default</button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="submit" form="form_toggle_ep_<?= (int)$s->id ?>" class="fav-badge <?= ($s->status === 'active') ? 'fav-badge-green' : 'fav-badge-gray' ?>" style="cursor: pointer; border: none;">
                                                <?= ($s->status === 'active') ? 'Active' : 'Disabled' ?>
                                            </button>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <button type="submit" form="form_delete_ep_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-danger" style="padding: 2px 6px; font-size: 11px;" onclick="return confirm('Delete this playback source?');">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Optional Inline Subtitle Track Upload -->
                <details style="margin-top: 14px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px;">
                    <summary style="font-weight: 600; cursor: pointer; color: #1e40af; font-size: 13px;">+ Add Subtitle Track (Optional)</summary>
                    <div class="fav-form-row" style="margin-top: 10px;">
                        <div class="fav-form-col">
                            <label class="fav-form-label">Subtitle File (.vtt or .srt)</label>
                            <input type="file" name="subtitle_file" class="fav-form-control" accept=".vtt,.srt,text/vtt">
                        </div>
                        <div class="fav-form-col">
                            <label class="fav-form-label">Language Code</label>
                            <input type="text" name="subtitle_lang" class="fav-form-control" placeholder="e.g. en, bn, es" value="en">
                        </div>
                        <div class="fav-form-col">
                            <label class="fav-form-label">Label</label>
                            <input type="text" name="subtitle_label" class="fav-form-control" placeholder="e.g. English" value="English">
                        </div>
                    </div>
                </details>
            <?php } catch (\Throwable $e) { ?>
                <div class="fav-media-error-notice" style="background: #fef2f2; border: 1px solid #f87171; color: #991b1b; padding: 12px 16px; border-radius: 6px; margin: 10px 0; font-size: 13px;">
                    ⚠️ Media source could not be loaded. Please edit or replace the source.
                </div>
            <?php } ?>
            </div>



            <div class="fav-form-row">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Thumbnail URL or Local Path</label>
                        <input type="text" name="thumbnail" id="fav_ep_thumb" class="fav-form-control" value="<?php echo htmlspecialchars($editEpisode->thumbnail ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://... or /uploads/...">
                        <input type="file" name="thumbnail_file" class="fav-form-control" accept="image/*" style="margin-top: 6px;">
                        <span class="fav-form-hint">Upload still frame image directly or paste URL above. Recommended: 16:9 widescreen.</span>
                        <?php if (!empty($editEpisode->thumbnail)): ?>
                            <img src="<?php echo htmlspecialchars($editEpisode->thumbnail, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="fav-img-preview" id="fav_ep_thumb_prev" style="max-width: 140px; max-height: 80px;">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Duration (Seconds)</label>
                        <input type="number" name="duration" class="fav-form-control" value="<?php echo $editEpisode->duration ?? 2700; ?>" placeholder="e.g. 2700 for 45 min">
                        <span class="fav-form-hint"><?php echo $editEpisode ? round(($editEpisode->duration ?? 0) / 60) . ' minutes' : 'e.g. 2700 = 45m'; ?></span>
                    </div>
                </div>
            </div>

            <div class="fav-form-row">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Access Mode (Override Behavior) *</label>
                        <select name="access_mode" class="fav-form-control" id="fav_ep_access_select">
                            <option value="inherit" <?php echo ($editEpisode?->access_mode === 'inherit') ? 'selected' : ''; ?>>
                                Inherit from Series (Current: <?php echo strtoupper($parentSeriesAccess); ?>)
                            </option>
                            <option value="public" <?php echo ($editEpisode?->access_mode === 'public') ? 'selected' : ''; ?>>
                                PUBLIC (Free for all — Free Pilot Episode Override)
                            </option>
                            <option value="login" <?php echo ($editEpisode?->access_mode === 'login') ? 'selected' : ''; ?>>
                                LOGIN REQUIRED (Logged-in members only)
                            </option>
                            <option value="premium" <?php echo ($editEpisode?->access_mode === 'premium') ? 'selected' : ''; ?>>
                                PREMIUM (Favorite Digital entitlement required)
                            </option>
                        </select>
                        <span class="fav-form-hint">Resolved effective access: <strong><?php echo strtoupper($editEpisode ? $editEpisode->getResolvedAccessMode() : $parentSeriesAccess); ?></strong></span>
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Download Policy *</label>
                        <select name="download_policy" class="fav-form-control">
                            <option value="inherit" <?php echo ($editEpisode?->download_policy === 'inherit') ? 'selected' : ''; ?>>Inherit from Series</option>
                            <option value="allow" <?php echo ($editEpisode?->download_policy === 'allow') ? 'selected' : ''; ?>>Allow Downloads</option>
                            <option value="deny" <?php echo ($editEpisode?->download_policy === 'deny') ? 'selected' : ''; ?>>Deny Downloads</option>
                        </select>
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Publication Status *</label>
                        <select name="status" id="fav_pub_status" class="fav-form-control" onchange="toggleSchedulingFields(this.value)">
                            <option value="published" <?php echo ($editEpisode?->status === 'published' || !$isEditing) ? 'selected' : ''; ?>>Published (Live)</option>
                            <option value="scheduled" <?php echo ($editEpisode?->status === 'scheduled') ? 'selected' : ''; ?>>Scheduled for Release</option>
                            <option value="draft" <?php echo ($editEpisode?->status === 'draft') ? 'selected' : ''; ?>>Draft (Hidden)</option>
                            <option value="unpublished" <?php echo ($editEpisode?->status === 'unpublished') ? 'selected' : ''; ?>>Unpublished (Archived)</option>
                        </select>
                        <span class="fav-form-hint">Drafts and scheduled items are hidden from visitors.</span>
                    </div>
                </div>
            </div>

            <!-- Scheduling Row -->
            <?php 
            $tz = \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::getAppTimezone();
            $pubAtLocal = !empty($editEpisode?->publish_at) ? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::utcToLocal($editEpisode->publish_at, 'Y-m-d\TH:i') : '';
            $unpubAtLocal = !empty($editEpisode?->unpublish_at) ? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::utcToLocal($editEpisode->unpublish_at, 'Y-m-d\TH:i') : '';
            $showSched = ($editEpisode?->status === 'scheduled');
            ?>
            <div class="fav-form-row" id="fav_scheduling_row" style="margin-top: 10px; <?php echo $showSched ? '' : 'display:none;'; ?>">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Schedule Release Time (<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>)</label>
                        <input type="datetime-local" name="publish_at" class="fav-form-control" value="<?php echo htmlspecialchars($pubAtLocal, ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="fav-form-hint">Automatically publishes when due and notifies series followers.</span>
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Optional Unpublish Time (<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>)</label>
                        <input type="datetime-local" name="unpublish_at" class="fav-form-control" value="<?php echo htmlspecialchars($unpubAtLocal, ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="fav-form-hint">Automatically unpublishes after this time (optional).</span>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" name="submit_action" value="publish" class="fav-admin-btn" style="padding: 10px 22px; font-size: 14px; background: #16a34a; border-color: #16a34a;">🚀 Publish Now</button>
                <button type="submit" name="submit_action" value="draft" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">📝 Save Draft</button>
                <button type="submit" name="submit_action" value="schedule" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">📅 Schedule</button>
                <?php if ($isEditing): ?>
                    <a href="/admin/page/multimedia-episodes?season_id=<?php echo $editEpisode->season_id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 16px; font-size: 14px;">Cancel</a>
                    <a href="/episode/<?php echo htmlspecialchars($editEpisode->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 16px; font-size: 14px;">Preview Episode &rarr;</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($isEditing && $editEpisode): ?>
            <!-- Independent Action Forms for Episode Sources (Outside main episode form) -->
            <?php if (!empty($attachedSources)): ?>
                <?php foreach ($attachedSources as $s): ?>
                    <form id="form_reorder_up_ep_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="direction" value="up">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-episodes?season_id=<?= (int)$editEpisode->season_id ?>&edit=<?= (int)$editEpisode->id ?>">
                    </form>
                    <form id="form_reorder_dn_ep_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="direction" value="down">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-episodes?season_id=<?= (int)$editEpisode->season_id ?>&edit=<?= (int)$editEpisode->id ?>">
                    </form>
                    <form id="form_setdefault_ep_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="set_default">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-episodes?season_id=<?= (int)$editEpisode->season_id ?>&edit=<?= (int)$editEpisode->id ?>">
                    </form>
                    <form id="form_toggle_ep_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-episodes?season_id=<?= (int)$editEpisode->season_id ?>&edit=<?= (int)$editEpisode->id ?>">
                    </form>
                    <form id="form_delete_ep_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="content_type" value="episode">
                        <input type="hidden" name="content_id" value="<?= (int)$editEpisode->id ?>">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-episodes?season_id=<?= (int)$editEpisode->season_id ?>&edit=<?= (int)$editEpisode->id ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Add Another Playback Source Subform (Dedicated form outside main episode form) -->
            <div class="fav-form-section" style="border: 1px dashed #3b82f6; background: #f8fafc; margin-top: 20px;">
                <details>
                    <summary style="font-weight: 700; cursor: pointer; color: #1e40af; font-size: 14px; padding: 4px 0;">➕ Add Another Playback Source (Mirror / Fallback / External Embed)</summary>
                    <form id="form_add_another_source_ep" method="POST" action="/admin/page/multimedia-sources" enctype="multipart/form-data" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="add_source">
                        <input type="hidden" name="content_type" value="episode">
                        <input type="hidden" name="content_id" value="<?= (int)$editEpisode->id ?>">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-episodes?season_id=<?= (int)$editEpisode->season_id ?>&edit=<?= (int)$editEpisode->id ?>">

                        <p style="font-size: 12px; color: #64748b; margin-top: 0; margin-bottom: 12px;">Attach a second or mirror stream to this episode. Viewers will be able to select between sources, and player will fail over automatically if the primary stream fails.</p>
                        <div class="fav-form-row">
                            <div class="fav-form-col">
                                <label class="fav-form-label">Source Format</label>
                                <select name="source_type" class="fav-form-control">
                                    <option value="auto">Auto-Detect</option>
                                    <option value="video">Direct Video (MP4, WebM)</option>
                                    <option value="hls">HLS (.m3u8)</option>
                                    <option value="embed">External Embed / Player</option>
                                </select>
                            </div>
                            <div class="fav-form-col">
                                <label class="fav-form-label">Stream Label</label>
                                <input type="text" name="source_label" class="fav-form-control" placeholder="e.g. Server 2 (Backup), YouTube Mirror">
                            </div>
                        </div>
                        <div class="fav-form-group" style="margin-top: 8px;">
                            <label class="fav-form-label">Media Stream URL (or YouTube/Vimeo/Embed Link)</label>
                            <input type="text" name="video_url" class="fav-form-control" placeholder="https://...">
                        </div>
                        <div class="fav-form-group" style="margin-top: 8px;">
                            <label class="fav-form-label">Or Upload Alternate Video File</label>
                            <input type="file" name="video_file" class="fav-form-control" accept="video/mp4,video/webm,video/quicktime,video/x-matroska">
                        </div>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 13px; font-weight: 600; cursor: pointer;">
                                <input type="checkbox" name="is_default" value="1"> Make this the Primary Source
                            </label>
                        </div>
                        <button type="submit" class="fav-admin-btn fav-admin-btn-primary" style="margin-top: 12px; font-size: 12px;">+ Save &amp; Attach Source</button>
                    </form>
                </details>
            </div>
        <?php endif; ?>

        <?php if ($isEditing): ?>
            <!-- Attached Media Sources & Subtitles for Episode -->
            <hr style="margin: 24px 0; border: none; border-top: 1px solid #e2e8f0;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
                <!-- Media Sources -->
                <div class="fav-form-section" style="margin-bottom: 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h4 style="margin:0; font-size:14px; font-weight:700;">🎛️ Media Sources (<?php $epSources = $editEpisode->getSources(false); echo count($epSources); ?>)</h4>
                        <a href="/admin/page/multimedia-sources?content_type=episode&content_id=<?php echo $editEpisode->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size:11px; padding:3px 8px;">+ Manage Sources</a>
                    </div>
                    <?php if (empty($epSources)): ?>
                        <p style="color:#94a3b8; font-size:12px; margin:0;">No video stream attached yet. Click "+ Manage Sources" to attach video URL or path.</p>
                    <?php else: ?>
                        <table class="fav-admin-table" style="font-size:12px; margin:0;">
                            <thead><tr><th>Label</th><th>Format</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($epSources as $es): ?>
                                    <?php
                                        $esLabel = (string)($es->label ?? '');
                                        if ($esLabel === '') { $esLabel = 'Main Stream'; }
                                        $esType = strtoupper((string)($es->source_type ?? 'video'));
                                        $esStatus = ucfirst((string)($es->status ?? 'active'));
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($esLabel, ENT_QUOTES, 'UTF-8'); ?></strong> <?php if (!empty($es->is_default)) echo '⭐'; ?></td>
                                        <td><code><?php echo htmlspecialchars($esType, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td><?php echo htmlspecialchars($esStatus, ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Subtitles -->
                <div class="fav-form-section" style="margin-bottom: 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h4 style="margin:0; font-size:14px; font-weight:700;">💬 Subtitle Tracks (<?php $epSubs = $editEpisode->getSubtitles(); echo count($epSubs); ?>)</h4>
                        <a href="/admin/page/multimedia-subtitles?content_type=episode&content_id=<?php echo $editEpisode->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size:11px; padding:3px 8px;">+ Manage Subtitles</a>
                    </div>
                    <?php if (empty($epSubs)): ?>
                        <p style="color:#94a3b8; font-size:12px; margin:0;">No subtitles registered yet.</p>
                    <?php else: ?>
                        <table class="fav-admin-table" style="font-size:12px; margin:0;">
                            <thead><tr><th>Language</th><th>Label</th><th>Default</th></tr></thead>
                            <tbody>
                                <?php foreach ($epSubs as $sub): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($sub->language, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td><?php echo htmlspecialchars($sub->label, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo $sub->is_default ? '⭐ Yes' : 'No'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Episodes List -->
    <table class="fav-admin-table">
        <thead>
            <tr>
                <th>Ep #</th>
                <th>Title</th>
                <th>Media</th>
                <th>Access Mode</th>
                <th>Duration</th>
                <th>Sources</th>
                <th>Status</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($episodes)): ?>
                <tr>
                    <td colspan="8" style="padding: 0;">
                        <div class="fav-empty-card">
                            <div style="font-size: 44px; margin-bottom: 10px;">🎞️</div>
                            <h3 style="font-size: 18px; margin: 0 0 8px 0; color: #1e293b;">No Episodes Added Yet</h3>
                            <p style="color: #64748b; font-size: 14px; margin: 0 0 18px 0;">Add your first episode with video upload or direct URL stream.</p>
                            <a href="/admin/page/multimedia-episodes?new=1<?php echo ($selectedSeasonId > 0) ? '&season_id=' . $selectedSeasonId : ''; ?>" class="fav-admin-btn" style="padding: 10px 24px; font-size: 14px;">+ Add First Episode</a>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($episodes as $ep): ?>
                <tr>
                    <td><strong>E<?php echo $ep->episode_number; ?></strong></td>
                    <td>
                        <strong><?php echo htmlspecialchars($ep->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div style="font-size: 11px; color:#64748b;">/episode/<?php echo htmlspecialchars($ep->slug, ENT_QUOTES, 'UTF-8'); ?></div>
                    </td>
                    <td>
                        <?php $epSt = $mediaStatuses[$ep->id] ?? ['status' => 'no_media', 'label' => 'No Media', 'class' => 'fav-badge-gray']; ?>
                        <span class="fav-badge <?php echo $epSt['class']; ?>"><?php echo htmlspecialchars($epSt['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </td>
                    <td><span class="fav-badge fav-badge-<?php echo strtolower($ep->getResolvedAccessMode()); ?>"><?php echo strtoupper($ep->access_mode === 'inherit' ? 'Inherit (' . $ep->getResolvedAccessMode() . ')' : $ep->access_mode); ?></span></td>
                    <td><?php echo round(($ep->duration ?? 0) / 60); ?>m</td>
                    <td><a href="/admin/page/multimedia-sources?content_type=episode&content_id=<?php echo $ep->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;"><?php echo count($ep->getSources(false)); ?> Sources</a></td>
                    <td><?php echo ucfirst($ep->status ?? 'published'); ?></td>
                    <td style="text-align: right; white-space: nowrap;">
                        <a href="/episode/<?php echo htmlspecialchars($ep->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;">View</a>
                        <a href="/admin/page/multimedia-episodes?season_id=<?php echo $ep->season_id; ?>&edit=<?php echo $ep->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                        <form method="POST" action="/admin/page/multimedia-episodes" style="display:inline;" onsubmit="return confirm('Delete episode?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $ep->id; ?>">
                            <input type="hidden" name="season_id" value="<?php echo $ep->season_id; ?>">
                            <button type="submit" class="fav-admin-btn fav-admin-btn-danger" style="padding: 2px 8px; font-size: 11px;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const seasonSelect = document.getElementById('fav_ep_season');
    if (seasonSelect) {
        seasonSelect.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const seriesId = selected.getAttribute('data-series');
            if (seriesId) {
                document.getElementById('fav_ep_series').value = seriesId;
            }
        });
    }

    const titleInput = document.getElementById('fav_ep_title');
    const slugInput = document.getElementById('fav_ep_slug');
    if (titleInput && slugInput) {
        titleInput.addEventListener('input', function() {
            if (!slugInput.value || slugInput.dataset.autoSlug !== 'false') {
                slugInput.value = this.value
                    .toLowerCase()
                    .replace(/[^\w\s-]/g, '')
                    .trim()
                    .replace(/\s+/g, '-');
            }
        });
        slugInput.addEventListener('input', function() {
            slugInput.dataset.autoSlug = 'false';
        });
    }

    const thumbInput = document.getElementById('fav_ep_thumb');
    if (thumbInput) {
        thumbInput.addEventListener('change', function() {
            let prev = document.getElementById('fav_ep_thumb_prev');
            if (this.value) {
                if (!prev) {
                    prev = document.createElement('img');
                    prev.id = 'fav_ep_thumb_prev';
                    prev.className = 'fav-img-preview';
                    prev.style.maxWidth = '140px';
                    prev.style.maxHeight = '80px';
                    thumbInput.parentNode.appendChild(prev);
                }
                prev.src = this.value;
                prev.style.display = 'block';
            } else if (prev) {
                prev.style.display = 'none';
            }
        });
    }
});

function toggleSchedulingFields(status) {
    const row = document.getElementById('fav_scheduling_row');
    if (row) {
        row.style.display = (status === 'scheduled') ? 'flex' : 'none';
    }
}

function switchMediaTab(tab) {
    document.querySelectorAll('.fav-media-tab-btn').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('fav_tab_btn_' + tab);
    if (btn) btn.classList.add('active');

    const uploadPane = document.getElementById('fav_pane_upload');
    const urlPane = document.getElementById('fav_pane_url');
    const urlInput = document.getElementById('fav_video_url');
    const urlLabel = document.getElementById('fav_video_url_label');
    const urlHint = document.getElementById('fav_video_url_hint');

    if (tab === 'upload') {
        if (uploadPane) uploadPane.style.display = 'block';
        if (urlPane) urlPane.style.display = 'none';
    } else {
        if (uploadPane) uploadPane.style.display = 'none';
        if (urlPane) urlPane.style.display = 'block';
        if (tab === 'url') {
            if (urlLabel) urlLabel.innerText = 'Direct Video URL (MP4, WebM)';
            if (urlInput) urlInput.placeholder = 'https://example.com/videos/episode.mp4';
            if (urlHint) urlHint.innerText = 'Direct HTTPS link to MP4 or WebM video file.';
        } else if (tab === 'hls') {
            if (urlLabel) urlLabel.innerText = 'HLS Stream URL (.m3u8)';
            if (urlInput) urlInput.placeholder = 'https://example.com/live/master.m3u8';
            if (urlHint) urlHint.innerText = 'HTTP Live Streaming master manifest URL.';
        } else if (tab === 'youtube') {
            if (urlLabel) urlLabel.innerText = 'YouTube URL';
            if (urlInput) urlInput.placeholder = 'https://www.youtube.com/watch?v=...';
            if (urlHint) urlHint.innerText = 'Standard YouTube watch or share URL.';
        } else if (tab === 'vimeo') {
            if (urlLabel) urlLabel.innerText = 'Vimeo URL';
            if (urlInput) urlInput.placeholder = 'https://vimeo.com/123456789';
            if (urlHint) urlHint.innerText = 'Standard Vimeo video URL.';
        } else if (tab === 'embed') {
            if (urlLabel) urlLabel.innerText = 'External Embed Player URL';
            if (urlInput) urlInput.placeholder = 'https://player.example.com/embed/...';
            if (urlHint) urlHint.innerText = 'Direct HTTPS embed URL from an allowlisted player domain (configured under Settings).';
        }
    }

    const typeInput = document.getElementById('fav_source_type_input');
    if (typeInput) {
        typeInput.value = (tab === 'upload') ? 'upload' : (tab === 'embed' ? 'embed' : (tab === 'hls' ? 'hls' : 'auto'));
    }
}

// Dynamic Download Links Handler for Episodes
(function() {
    var newIdx = 0;
    var btnAdd = document.getElementById('fav_btn_add_ep_dl');
    var tbody = document.getElementById('fav_tbody_ep_dl');
    var table = document.getElementById('fav_table_ep_dl');
    var emptyNotice = document.getElementById('fav_empty_ep_dl');
    var delContainer = document.getElementById('fav_del_ep_dl_container');

    if (btnAdd && tbody) {
        btnAdd.addEventListener('click', function() {
            newIdx++;
            if (table) table.style.display = '';
            if (emptyNotice) emptyNotice.style.display = 'none';

            var tr = document.createElement('tr');
            tr.className = 'fav-dl-row';
            tr.innerHTML = '<td><input type="number" name="new_download_sources[' + newIdx + '][sort_order]" class="fav-form-control" value="' + (tbody.children.length) + '" style="width: 50px; padding: 3px; text-align: center;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][label]" class="fav-form-control" placeholder="e.g. 1080p Direct" style="padding: 3px 6px;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][url]" class="fav-form-control" placeholder="https://..." style="padding: 3px 6px;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][quality]" class="fav-form-control" placeholder="1080p" style="padding: 3px 6px;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][format]" class="fav-form-control" placeholder="MP4" style="padding: 3px 6px;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][provider]" class="fav-form-control" placeholder="Direct" style="padding: 3px 6px;"></td>' +
                '<td style="text-align: center;"><input type="checkbox" name="new_download_sources[' + newIdx + '][is_active]" value="1" checked></td>' +
                '<td style="text-align: right;"><button type="button" class="fav-admin-btn fav-admin-btn-danger fav-btn-remove-dl" style="padding: 1px 6px; font-size: 11px;">✕</button></td>';

            tbody.appendChild(tr);
        });

        tbody.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('fav-btn-remove-dl')) {
                var row = e.target.closest('tr');
                if (row) {
                    var id = row.getAttribute('data-id');
                    if (id && delContainer) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'delete_download_sources[]';
                        hidden.value = id;
                        delContainer.appendChild(hidden);
                    }
                    row.remove();
                    if (tbody.children.length === 0) {
                        if (table) table.style.display = 'none';
                        if (emptyNotice) emptyNotice.style.display = '';
                    }
                }
            }
        });
    }
})();
</script>
<script src="/plugins/favorite-multimedia/assets/js/multimedia-admin.js"></script>


