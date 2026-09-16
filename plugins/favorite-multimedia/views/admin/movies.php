<?php
/**
 * Favorite Multimedia — Admin Movies View
 */
$isEditing = ($editMovie !== null);
$selectedGenres = $isEditing ? array_column($editMovie->getGenres(), 'id') : [];
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🎬 Movies Management</h2>
        <?php if ($isEditing): ?>
            <a href="/admin/page/multimedia-movies" class="fav-admin-btn fav-admin-btn-secondary">&larr; Back to Movies List</a>
        <?php endif; ?>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link active">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link">📺 Web Series</a>
        <a href="/admin/page/multimedia-seasons" class="fav-admin-subnav-link">📼 Seasons</a>
        <a href="/admin/page/multimedia-episodes" class="fav-admin-subnav-link">🎞️ Episodes</a>
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

    <?php if ($isEditing || isset($_GET['new'])): ?>
        <!-- Movie Form -->
        <div class="fav-admin-stat-card" style="margin-bottom: 28px;">
            <h3 style="margin-top:0; font-size:18px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <?php echo $isEditing ? 'Edit Movie: ' . htmlspecialchars($editMovie->title, ENT_QUOTES, 'UTF-8') : 'Create New Movie'; ?>
            </h3>

            <form method="POST" action="/admin/page/multimedia-movies" id="fav_movie_form" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editMovie->id ?? 0; ?>">

                <!-- 1. Basic Information -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">📌 Basic Information</h4>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Movie Title *</label>
                                <input type="text" name="title" id="fav_movie_title" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" required placeholder="e.g. Interstellar">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">URL Slug</label>
                                <input type="text" name="slug" id="fav_movie_slug" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="auto-generated from title">
                                <span class="fav-form-hint">Unique URL path: /movie/your-slug</span>
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-group">
                        <label class="fav-form-label">Synopsis / Description</label>
                        <textarea name="description" class="fav-form-control" rows="4" placeholder="Brief summary of the movie..."><?php echo htmlspecialchars($editMovie->description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Release Year</label>
                                <input type="number" name="release_year" class="fav-form-control" value="<?php echo $editMovie->release_year ?? date('Y'); ?>">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Release Date</label>
                                <input type="date" name="release_date" class="fav-form-control" value="<?php echo $editMovie->release_date ?? ''; ?>">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Duration (Seconds)</label>
                                <input type="number" name="duration" class="fav-form-control" value="<?php echo $editMovie->duration ?? 7200; ?>" placeholder="e.g. 7200 for 2 hours">
                                <span class="fav-form-hint"><?php echo $editMovie ? $editMovie->getDurationFormatted() : 'e.g. 7200 = 2 hours'; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Language</label>
                                <input type="text" name="language" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->language ?? 'English', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Country</label>
                                <input type="text" name="country" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->country ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Director</label>
                                <input type="text" name="director" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->director ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Cast Members</label>
                                <input type="text" name="cast" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->cast ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Comma separated actor names">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Trailer URL</label>
                                <input type="text" name="trailer_url" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->trailer_url ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="YouTube or video URL">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Video / Media Stream -->
                <div class="fav-form-section" style="border: 2px solid #3b82f6; background: #f0f7ff;">
                <?php try { ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #bfdbfe; padding-bottom: 8px;">
                        <h4 style="margin:0; font-size: 15px; font-weight: 700; color: #1e40af; display: flex; align-items: center; gap: 8px;">
                            🎥 Video / Media Stream
                            <?php if ($isEditing && $editMovie): ?>
                                <?php $curStatus = $mediaStatuses[$editMovie->id] ?? ['status' => 'no_media', 'label' => 'No Media', 'class' => 'fav-badge-gray']; ?>
                                <span class="fav-badge <?= htmlspecialchars($curStatus['class'] ?? 'fav-badge-gray', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($curStatus['label'] ?? 'No Media', ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </h4>
                        <?php if ($isEditing && $editMovie): ?>
                            <a href="/admin/page/multimedia-sources?content_type=movie&content_id=<?= (int)$editMovie->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size: 11px; padding: 3px 8px;" target="_blank">🎛️ Advanced Sources &rarr;</a>
                        <?php endif; ?>
                    </div>

                    <?php 
                        $curDefault = ($isEditing && $editMovie) ? ($defaultSources[$editMovie->id] ?? null) : null;
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
                            <span class="fav-form-hint">Server upload limit: <strong><?= htmlspecialchars($uploadMax) ?></strong> (post_max_size: <?= htmlspecialchars($postMax) ?>). For large files, use a direct URL or increase upload limits.</span>
                            <?php if ($ffmpegActive): ?>
                                <div style="font-size: 12px; color: #15803d; margin-top: 5px; font-weight: 500;">✓ FFmpeg is active: uploaded MP4 files will automatically generate adaptive HLS renditions.</div>
                            <?php else: ?>
                                <div style="font-size: 12px; color: #b45309; margin-top: 5px; font-weight: 500;">ℹ FFmpeg is not installed: direct MP4/WebM uploads will play directly in HTML5 player without transcoding.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- URL / Stream Pane -->
                    <div id="fav_pane_url" style="display: none;">
                        <div class="fav-form-group">
                            <label class="fav-form-label" id="fav_video_url_label">Direct Video URL (MP4, WebM)</label>
                            <input type="text" name="video_url" id="fav_video_url" class="fav-form-control" placeholder="https://example.com/videos/movie.mp4">
                            <span class="fav-form-hint" id="fav_video_url_hint">Direct HTTPS link to MP4 or WebM video file.</span>
                        </div>
                    </div>

                    <div class="fav-form-row" style="margin-top: 10px;">
                        <div class="fav-form-col">
                            <div class="fav-form-group" style="margin-bottom: 0;">
                                <label class="fav-form-label">Stream Label (Optional)</label>
                                <input type="text" name="source_label" class="fav-form-control" placeholder="e.g. Main 1080p Feature">
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-group" style="margin-top: 10px; margin-bottom: 0;">
                        <label class="fav-form-label">Primary / Legacy Download URL (Optional)</label>
                        <input type="text" name="download_url" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->download_url ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://cdn.example.com/files/movie.mp4">
                        <span class="fav-form-hint">Quick direct download link. You can also configure multiple download options below.</span>
                    </div>

                    <!-- Multiple Download Links Section -->
                    <div style="margin-top: 16px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <h5 style="margin: 0; font-size: 14px; color: #1e3a8a; font-weight: 700;">Download Links (<?= !empty($downloadSources) ? count($downloadSources) : 0 ?>)</h5>
                                <span style="font-size: 12px; color: #64748b;">Add multiple qualities or mirror links (e.g. 1080p Google Drive, 720p Direct).</span>
                            </div>
                            <button type="button" class="fav-admin-btn fav-admin-btn-secondary" id="fav_btn_add_movie_dl" style="font-size: 12px; padding: 4px 10px;">+ Add Download Link</button>
                        </div>
                        <table class="fav-admin-table" id="fav_table_movie_dl" style="font-size: 12px; margin-top: 8px; <?= empty($downloadSources) ? 'display: none;' : '' ?>">
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
                            <tbody id="fav_tbody_movie_dl">
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
                        <div id="fav_empty_movie_dl" style="font-size: 12px; color: #94a3b8; font-style: italic; padding: 6px 0; <?= !empty($downloadSources) ? 'display: none;' : '' ?>">
                            No additional download links configured. Click "+ Add Download Link" to add one.
                        </div>
                        <div id="fav_del_movie_dl_container"></div>
                    </div>

                    <!-- Configured Attached Sources Table (if editing) -->
                    <?php if ($isEditing && $editMovie && !empty($attachedSources)): ?>
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
                                                <button type="submit" form="form_reorder_up_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 1px 5px; font-size: 11px;" <?= ($idx === 0) ? 'disabled' : '' ?>>▲</button>
                                                <button type="submit" form="form_reorder_dn_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 1px 5px; font-size: 11px;" <?= ($idx === count($attachedSources) - 1) ? 'disabled' : '' ?>>▼</button>
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
                                                    <button type="submit" form="form_setdefault_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 6px; font-size: 11px;">Set Default</button>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="submit" form="form_toggle_<?= (int)$s->id ?>" class="fav-badge <?= ($s->status === 'active') ? 'fav-badge-green' : 'fav-badge-gray' ?>" style="cursor: pointer; border: none;">
                                                    <?= ($s->status === 'active') ? 'Active' : 'Disabled' ?>
                                                </button>
                                            </td>
                                            <td style="text-align: right; white-space: nowrap;">
                                                <button type="submit" form="form_delete_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-danger" style="padding: 2px 6px; font-size: 11px;" onclick="return confirm('Delete this playback source?');">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- Optional Inline Subtitle Upload -->
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



                <!-- 3. Artwork & Imagery -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">🎨 Artwork &amp; Imagery</h4>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Poster Image URL or Local Path</label>
                                <input type="text" name="poster" id="fav_movie_poster" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->poster ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://... or /uploads/...">
                                <input type="file" name="poster_file" class="fav-form-control" accept="image/*" style="margin-top: 6px;">
                                <span class="fav-form-hint">Upload JPG/PNG/WebP poster directly or paste URL above. Recommended: 2:3 vertical.</span>
                                <?php if (!empty($editMovie->poster)): ?>
                                    <img src="<?php echo htmlspecialchars($editMovie->poster, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="fav-img-preview" id="fav_poster_preview">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Backdrop / Hero Image URL</label>
                                <input type="text" name="backdrop" id="fav_movie_backdrop" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->backdrop ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://... or /uploads/...">
                                <input type="file" name="backdrop_file" class="fav-form-control" accept="image/*" style="margin-top: 6px;">
                                <span class="fav-form-hint">Upload widescreen hero image directly or paste URL above. Recommended: 16:9 widescreen.</span>
                                <?php if (!empty($editMovie->backdrop)): ?>
                                    <img src="<?php echo htmlspecialchars($editMovie->backdrop, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="fav-img-preview" id="fav_backdrop_preview" style="max-width: 160px; max-height: 90px;">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Access & Governance -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">🔐 Access Control &amp; Policies</h4>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Access Mode *</label>
                                <select name="access_mode" class="fav-form-control">
                                    <option value="public" <?php echo ($editMovie?->access_mode === 'public') ? 'selected' : ''; ?>>PUBLIC (Free for everyone)</option>
                                    <option value="login" <?php echo ($editMovie?->access_mode === 'login') ? 'selected' : ''; ?>>LOGIN REQUIRED (Logged-in users)</option>
                                    <option value="premium" <?php echo ($editMovie?->access_mode === 'premium') ? 'selected' : ''; ?>>PREMIUM (Entitlement / subscription required)</option>
                                </select>
                                <span class="fav-form-hint">Enforced authoritative access level via MultimediaAccessService</span>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Download Policy *</label>
                                <select name="download_policy" class="fav-form-control">
                                    <option value="inherit" <?php echo ($editMovie?->download_policy === 'inherit') ? 'selected' : ''; ?>>Inherit Global Setting</option>
                                    <option value="allow" <?php echo ($editMovie?->download_policy === 'allow') ? 'selected' : ''; ?>>Allow Downloads</option>
                                    <option value="deny" <?php echo ($editMovie?->download_policy === 'deny') ? 'selected' : ''; ?>>Deny Downloads</option>
                                </select>
                                <span class="fav-form-hint">Viewing permission and downloading permission are independent.</span>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Publication Status *</label>
                                <select name="status" id="fav_pub_status" class="fav-form-control" onchange="toggleSchedulingFields(this.value)">
                                    <option value="published" <?php echo ($editMovie?->status === 'published' || !$isEditing) ? 'selected' : ''; ?>>Published (Live)</option>
                                    <option value="scheduled" <?php echo ($editMovie?->status === 'scheduled') ? 'selected' : ''; ?>>Scheduled for Release</option>
                                    <option value="draft" <?php echo ($editMovie?->status === 'draft') ? 'selected' : ''; ?>>Draft (Hidden)</option>
                                    <option value="unpublished" <?php echo ($editMovie?->status === 'unpublished') ? 'selected' : ''; ?>>Unpublished (Archived)</option>
                                </select>
                                <span class="fav-form-hint">Drafts and scheduled items are hidden from public catalog and search.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Scheduling Row -->
                    <?php 
                    $tz = \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::getAppTimezone();
                    $pubAtLocal = !empty($editMovie?->publish_at) ? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::utcToLocal($editMovie->publish_at, 'Y-m-d\TH:i') : '';
                    $unpubAtLocal = !empty($editMovie?->unpublish_at) ? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::utcToLocal($editMovie->unpublish_at, 'Y-m-d\TH:i') : '';
                    $showSched = ($editMovie?->status === 'scheduled');
                    ?>
                    <div class="fav-form-row" id="fav_scheduling_row" style="margin-top: 10px; <?php echo $showSched ? '' : 'display:none;'; ?>">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Schedule Release Time (<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>)</label>
                                <input type="datetime-local" name="publish_at" class="fav-form-control" value="<?php echo htmlspecialchars($pubAtLocal, ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="fav-form-hint">Automatically publishes when due (Timezone: <?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>).</span>
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

                    <div class="fav-form-group" style="margin-top: 10px;">
                        <label style="font-size: 13px; font-weight: 600; cursor:pointer;">
                            <input type="checkbox" name="featured" value="1" <?php echo ($editMovie?->featured) ? 'checked' : ''; ?>>
                            ⭐ Feature this movie on frontend homepage / hero banner
                        </label>
                    </div>
                </div>

                <!-- 4. Classification (Genres) -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">🏷️ Genres &amp; Classification</h4>
                    <div style="display: flex; gap: 14px; flex-wrap: wrap; padding: 4px 0;">
                        <?php if (empty($genres)): ?>
                            <span style="color:#94a3b8; font-size:13px;">No genres created yet. <a href="/admin/page/multimedia-genres" target="_blank" style="color:#2563eb;">+ Add Genres</a></span>
                        <?php else: foreach ($genres as $g): ?>
                            <label style="font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; background:#fff; border:1px solid #cbd5e1; padding:4px 10px; border-radius:4px;">
                                <input type="checkbox" name="genres[]" value="<?php echo $g->id; ?>" <?php echo in_array($g->id, $selectedGenres) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($g->name, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- 5. SEO Metadata -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">🔍 SEO &amp; Social Metadata</h4>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">SEO Page Title (Optional)</label>
                                <input type="text" name="seo_title" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->seo_title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Defaults to Movie Title">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">SEO Meta Description (Optional)</label>
                                <input type="text" name="seo_description" class="fav-form-control" value="<?php echo htmlspecialchars($editMovie->seo_description ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Brief snippet for search engine previews">
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="submit" name="submit_action" value="publish" class="fav-admin-btn" style="padding: 10px 22px; font-size: 14px; background: #16a34a; border-color: #16a34a;">🚀 Publish Now</button>
                    <button type="submit" name="submit_action" value="draft" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">📝 Save Draft</button>
                    <button type="submit" name="submit_action" value="schedule" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">📅 Schedule</button>
                    <?php if ($isEditing): ?>
                        <a href="/admin/page/multimedia-movies" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">Cancel</a>
                        <a href="/movie/<?php echo htmlspecialchars($editMovie->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">Preview on Frontend &rarr;</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($isEditing && $editMovie): ?>
                <!-- Independent Action Forms for Sources (Outside main movie form) -->
                <?php if (!empty($attachedSources)): ?>
                    <?php foreach ($attachedSources as $s): ?>
                        <form id="form_reorder_up_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="reorder">
                            <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                            <input type="hidden" name="direction" value="up">
                            <input type="hidden" name="redirect_to" value="/admin/page/multimedia-movies?edit=<?= (int)$editMovie->id ?>">
                        </form>
                        <form id="form_reorder_dn_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="reorder">
                            <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                            <input type="hidden" name="direction" value="down">
                            <input type="hidden" name="redirect_to" value="/admin/page/multimedia-movies?edit=<?= (int)$editMovie->id ?>">
                        </form>
                        <form id="form_setdefault_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="set_default">
                            <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                            <input type="hidden" name="redirect_to" value="/admin/page/multimedia-movies?edit=<?= (int)$editMovie->id ?>">
                        </form>
                        <form id="form_toggle_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                            <input type="hidden" name="redirect_to" value="/admin/page/multimedia-movies?edit=<?= (int)$editMovie->id ?>">
                        </form>
                        <form id="form_delete_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                            <input type="hidden" name="content_type" value="movie">
                            <input type="hidden" name="content_id" value="<?= (int)$editMovie->id ?>">
                            <input type="hidden" name="redirect_to" value="/admin/page/multimedia-movies?edit=<?= (int)$editMovie->id ?>">
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Add Another Playback Source Subform (Dedicated form outside main movie form) -->
                <div class="fav-form-section" style="border: 1px dashed #3b82f6; background: #f8fafc; margin-top: 20px;">
                    <details>
                        <summary style="font-weight: 700; cursor: pointer; color: #1e40af; font-size: 14px; padding: 4px 0;">➕ Add Another Playback Source (Mirror / Fallback / External Embed)</summary>
                        <form id="form_add_another_source" method="POST" action="/admin/page/multimedia-sources" enctype="multipart/form-data" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="add_source">
                            <input type="hidden" name="content_type" value="movie">
                            <input type="hidden" name="content_id" value="<?= (int)$editMovie->id ?>">
                            <input type="hidden" name="redirect_to" value="/admin/page/multimedia-movies?edit=<?= (int)$editMovie->id ?>">

                            <p style="font-size: 12px; color: #64748b; margin-top: 0; margin-bottom: 12px;">Attach a second or mirror stream to this movie. Viewers will be able to select between sources, and player will fail over automatically if the primary stream fails.</p>
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
                <!-- Attached Media Sources & Subtitles -->
                <hr style="margin: 28px 0; border: none; border-top: 1px solid #e2e8f0;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
                    <!-- Media Sources -->
                    <div class="fav-form-section" style="margin-bottom: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h4 style="margin:0; font-size:14px; font-weight:700;">🎛️ Media Sources (<?php $movieSources = $editMovie->getSources(false); echo count($movieSources); ?>)</h4>
                            <a href="/admin/page/multimedia-sources?content_type=movie&content_id=<?php echo $editMovie->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size:11px; padding:3px 8px;">+ Manage Sources</a>
                        </div>
                        <?php if (empty($movieSources)): ?>
                            <p style="color:#94a3b8; font-size:12px; margin:0;">No stream sources attached yet. Click "+ Manage Sources" to attach MP4, HLS, or embed URL.</p>
                        <?php else: ?>
                            <table class="fav-admin-table" style="font-size:12px; margin:0;">
                                <thead><tr><th>Label</th><th>Type</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($movieSources as $ms): ?>
                                        <?php
                                            $msLabel = (string)($ms->label ?? '');
                                            if ($msLabel === '') { $msLabel = 'Main Stream'; }
                                            $msType = strtoupper((string)($ms->source_type ?? 'video'));
                                            $msStatus = ucfirst((string)($ms->status ?? 'active'));
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($msLabel, ENT_QUOTES, 'UTF-8'); ?></strong> <?php if (!empty($ms->is_default)) echo '⭐'; ?></td>
                                            <td><code><?php echo htmlspecialchars($msType, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                            <td><?php echo htmlspecialchars($msStatus, ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- Subtitles -->
                    <div class="fav-form-section" style="margin-bottom: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h4 style="margin:0; font-size:14px; font-weight:700;">💬 Subtitle Tracks (<?php $movieSubs = $editMovie->getSubtitles(); echo count($movieSubs); ?>)</h4>
                            <a href="/admin/page/multimedia-subtitles?content_type=movie&content_id=<?php echo $editMovie->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size:11px; padding:3px 8px;">+ Manage Subtitles</a>
                        </div>
                        <?php if (empty($movieSubs)): ?>
                            <p style="color:#94a3b8; font-size:12px; margin:0;">No subtitles registered yet. WebVTT (.vtt) files supported.</p>
                        <?php else: ?>
                            <table class="fav-admin-table" style="font-size:12px; margin:0;">
                                <thead><tr><th>Language</th><th>Label</th><th>Default</th></tr></thead>
                                <tbody>
                                    <?php foreach ($movieSubs as $sub): ?>
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
    <?php endif; ?>

    <!-- Movies Table with Bulk Actions -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:16px;">All Movies (<?php echo count($items); ?>)</h3>
        <?php if (!$isEditing && !isset($_GET['new'])): ?>
            <a href="/admin/page/multimedia-movies?new=1" class="fav-admin-btn">+ Add New Movie</a>
        <?php endif; ?>
    </div>

    <form method="POST" action="/admin/page/multimedia-movies" id="movies-bulk-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="bulk">

        <div class="bulk-actions-wrap" style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
            <select name="bulk_action" class="fav-form-control" style="width: auto; max-width: 200px; display: inline-block;">
                <option value="">Bulk Actions</option>
                <option value="publish">Publish</option>
                <option value="draft">Move to Draft</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="fav-admin-btn fav-admin-btn-secondary">Apply</button>
            <span class="bulk-count-badge" style="font-size: 13px; color: #64748b;">0 selected</span>
        </div>

        <table class="fav-admin-table">
            <thead>
                <tr>
                    <th style="width: 32px; text-align: center;">
                        <input type="checkbox" data-select-all>
                    </th>
                    <th style="width: 50px;">Poster</th>
                    <th>Title</th>
                    <th>Year</th>
                    <th>Duration</th>
                    <th>Media</th>
                    <th>Access</th>
                    <th>Downloads</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="10" style="padding: 0;">
                            <div class="fav-empty-card">
                                <div style="font-size: 44px; margin-bottom: 10px;">🎬</div>
                                <h3 style="font-size: 18px; margin: 0 0 8px 0; color: #1e293b;">No Movies Published Yet</h3>
                                <p style="color: #64748b; font-size: 14px; margin: 0 0 18px 0;">Get started by publishing your first movie with direct video upload or URL.</p>
                                <a href="/admin/page/multimedia-movies?new=1" class="fav-admin-btn" style="padding: 10px 24px; font-size: 14px;">+ Add Your First Movie</a>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($items as $item): ?>
                    <tr>
                        <td style="text-align: center;">
                            <input type="checkbox" name="ids[]" value="<?php echo (int)$item->id; ?>" class="bulk-cb">
                        </td>
                        <td>
                            <?php if ($item->poster): ?>
                                <img src="<?php echo htmlspecialchars($item->poster, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 40px; height: 55px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                <div style="width: 40px; height: 55px; background: #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #64748b;">No Img</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div style="font-size: 11px; color: #64748b;">/movie/<?php echo htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8'); ?></div>
                        </td>
                        <td><?php echo $item->release_year ?: '—'; ?></td>
                        <td><?php echo $item->getDurationFormatted() ?: '—'; ?></td>
                        <td>
                            <?php $st = $mediaStatuses[$item->id] ?? ['status' => 'no_media', 'label' => 'No Media', 'class' => 'fav-badge-gray']; ?>
                            <span class="fav-badge <?php echo $st['class']; ?>"><?php echo htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td><span class="fav-badge fav-badge-<?php echo strtolower($item->access_mode ?? 'public'); ?>"><?php echo strtoupper($item->access_mode ?? 'public'); ?></span></td>
                        <td><?php echo ucfirst($item->download_policy ?? 'inherit'); ?></td>
                        <td><?php echo ucfirst($item->status ?? 'published'); ?></td>
                        <td style="text-align: right; white-space: nowrap;">
                            <a href="/admin/page/multimedia-sources?content_type=movie&content_id=<?php echo $item->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 4px 8px; font-size: 11px;">Media Sources</a>
                            <a href="/admin/page/multimedia-subtitles?content_type=movie&content_id=<?php echo $item->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 4px 8px; font-size: 11px;">Subtitles</a>
                            <a href="/movie/<?php echo htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 4px 8px; font-size: 11px;">View</a>
                            <a href="/admin/page/multimedia-movies?edit=<?php echo $item->id; ?>" class="fav-admin-btn" style="padding: 4px 8px; font-size: 11px;">Edit</a>
                            <button type="button" class="fav-admin-btn fav-admin-btn-danger fmm-delete-single-btn" data-id="<?php echo $item->id; ?>" data-title="<?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 4px 8px; font-size: 11px;">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </form>

    <!-- Dedicated Single-Delete Form (prevents HTML5 nested form breakage) -->
    <form method="POST" action="/admin/page/multimedia-movies" id="fmm-movie-single-delete-form" style="display:none;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="fmm-movie-single-delete-id" value="">
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const titleInput = document.getElementById('fav_movie_title');
    const slugInput = document.getElementById('fav_movie_slug');
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

    const posterInput = document.getElementById('fav_movie_poster');
    if (posterInput) {
        posterInput.addEventListener('change', function() {
            let prev = document.getElementById('fav_poster_preview');
            if (this.value) {
                if (!prev) {
                    prev = document.createElement('img');
                    prev.id = 'fav_poster_preview';
                    prev.className = 'fav-img-preview';
                    posterInput.parentNode.appendChild(prev);
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
            if (urlInput) urlInput.placeholder = 'https://example.com/videos/movie.mp4';
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

// Dynamic Download Links Handler
(function() {
    var newIdx = 0;
    var btnAdd = document.getElementById('fav_btn_add_movie_dl');
    var tbody = document.getElementById('fav_tbody_movie_dl');
    var table = document.getElementById('fav_table_movie_dl');
    var emptyNotice = document.getElementById('fav_empty_movie_dl');
    var delContainer = document.getElementById('fav_del_movie_dl_container');

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

