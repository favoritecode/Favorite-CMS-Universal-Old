<?php
/**
 * Favorite Multimedia — Admin Media Sources View
 */
$isEditing = ($editSource !== null);
$curContentType = $editSource ? $editSource->content_type : $contentType;
$curContentId = $editSource ? $editSource->content_id : $contentId;
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🎛️ Media Sources Management</h2>
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
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link active">🎛️ Sources</a>
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link">💬 Subtitles</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <!-- Filter by Content -->
    <div style="margin-bottom: 20px; background: #fff; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 6px;">
        <form method="GET" action="/admin/page/multimedia-sources" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
            <div>
                <label style="font-weight: 600; margin-right: 6px;">Content Type:</label>
                <select name="content_type" class="fav-form-control" style="width: auto; display: inline-block;" id="filter_content_type" onchange="this.form.submit()">
                    <option value="movie" <?php echo ($curContentType === 'movie') ? 'selected' : ''; ?>>Movie</option>
                    <option value="episode" <?php echo ($curContentType === 'episode') ? 'selected' : ''; ?>>Episode</option>
                    <option value="song" <?php echo ($curContentType === 'song') ? 'selected' : ''; ?>>Song</option>
                </select>
            </div>
            <div>
                <label style="font-weight: 600; margin-right: 6px;">Item:</label>
                <select name="content_id" class="fav-form-control" style="width: auto; display: inline-block;" onchange="this.form.submit()">
                    <option value="0">-- All Items --</option>
                    <?php if ($curContentType === 'movie'): ?>
                        <?php foreach ($movies as $m): ?>
                            <option value="<?php echo $m->id; ?>" <?php echo ($m->id == $curContentId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($m->title, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php elseif ($curContentType === 'episode'): ?>
                        <?php foreach ($episodes as $ep): ?>
                            <option value="<?php echo $ep->id; ?>" <?php echo ($ep->id == $curContentId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ep->getSeries()?->title . ' - ' . $ep->title, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php elseif ($curContentType === 'song'): ?>
                        <?php foreach ($songs as $s): ?>
                            <option value="<?php echo $s->id; ?>" <?php echo ($s->id == $curContentId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>
    </div>

    <!-- Source Form -->
    <div class="fav-admin-stat-card" style="margin-bottom: 28px;">
        <h3 style="margin-top:0; font-size:16px;">
            <?php echo $isEditing ? 'Edit Media Source' : '+ Add Media Source'; ?>
        </h3>

        <form method="POST" action="/admin/page/multimedia-sources">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
            <input type="hidden" name="id" value="<?php echo $editSource->id ?? 0; ?>">

            <div class="fav-form-row">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Content Type *</label>
                        <select name="content_type" class="fav-form-control" required id="form_content_type">
                            <option value="movie" <?php echo ($curContentType === 'movie') ? 'selected' : ''; ?>>Movie</option>
                            <option value="episode" <?php echo ($curContentType === 'episode') ? 'selected' : ''; ?>>Episode</option>
                            <option value="song" <?php echo ($curContentType === 'song') ? 'selected' : ''; ?>>Song</option>
                        </select>
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Content Item *</label>
                        <select name="content_id" class="fav-form-control" required id="form_content_id">
                            <?php if ($curContentType === 'movie'): ?>
                                <?php foreach ($movies as $m): ?>
                                    <option value="<?php echo $m->id; ?>" <?php echo ($m->id == $curContentId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($m->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php elseif ($curContentType === 'episode'): ?>
                                <?php foreach ($episodes as $ep): ?>
                                    <option value="<?php echo $ep->id; ?>" <?php echo ($ep->id == $curContentId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ep->getSeries()?->title . ' - ' . $ep->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php elseif ($curContentType === 'song'): ?>
                                <?php foreach ($songs as $s): ?>
                                    <option value="<?php echo $s->id; ?>" <?php echo ($s->id == $curContentId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Media Source URL or Local Path *</label>
                <input type="text" name="url_or_path" id="fav_source_url" class="fav-form-control" value="<?php echo htmlspecialchars($editSource->url_or_path ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/stream.m3u8, https://.../video.mp4, or storage/multimedia/filename.mp4" required>
                <div class="fav-form-hint">
                    🌐 <strong>Remote:</strong> Full URL starting with <code>http://</code> or <code>https://</code> (direct MP4/MP3, HLS .m3u8, or YouTube/Vimeo embed).<br>
                    📁 <strong>Local:</strong> Relative path within project root (e.g. <code>storage/multimedia/movie.mp4</code>). Protected against directory traversal.
                </div>
                <div id="fav_detection_status" class="fav-detection-box" style="display: none; margin-top: 8px;"></div>
            </div>

            <div class="fav-form-row">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Source Type *</label>
                        <select name="source_type" id="fav_source_type" class="fav-form-control">
                            <option value="video" <?php echo ($editSource?->source_type === 'video') ? 'selected' : ''; ?>>Direct Video (MP4, WebM)</option>
                            <option value="hls" <?php echo ($editSource?->source_type === 'hls') ? 'selected' : ''; ?>>HLS / M3U8 Stream</option>
                            <option value="audio" <?php echo ($editSource?->source_type === 'audio') ? 'selected' : ''; ?>>Direct Audio (MP3, M4A, WAV)</option>
                            <option value="embed" <?php echo ($editSource?->source_type === 'embed') ? 'selected' : ''; ?>>Embed (YouTube, Vimeo, etc.)</option>
                            <option value="unknown" <?php echo ($editSource?->source_type === 'unknown') ? 'selected' : ''; ?>>Unknown Media Type</option>
                        </select>
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Quality / Label</label>
                        <input type="text" name="label" class="fav-form-control" value="<?php echo htmlspecialchars($editSource->label ?? '1080p', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 1080p, 720p, Server 1">
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Allow Download *</label>
                        <select name="allow_download" class="fav-form-control">
                            <option value="inherit" <?php echo ($editSource?->allow_download === 'inherit') ? 'selected' : ''; ?>>Inherit Global</option>
                            <option value="allow" <?php echo ($editSource?->allow_download === 'allow') ? 'selected' : ''; ?>>Allow</option>
                            <option value="deny" <?php echo ($editSource?->allow_download === 'deny') ? 'selected' : ''; ?>>Deny</option>
                        </select>
                        <div class="fav-form-hint">Applies to direct video/audio only. HLS & embeds cannot be downloaded.</div>
                    </div>
                </div>
            </div>

            <div class="fav-form-row">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Status</label>
                        <select name="status" class="fav-form-control">
                            <option value="active" <?php echo ($editSource?->status === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($editSource?->status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label style="font-size: 13px; font-weight: 600; padding-top: 24px; display: block;">
                            <input type="checkbox" name="is_default" value="1" <?php echo ($editSource?->is_default ?? 1) ? 'checked' : ''; ?>>
                            Set as Default Source
                        </label>
                    </div>
                </div>
            </div>

            <button type="submit" class="fav-admin-btn"><?php echo $isEditing ? 'Save Source' : 'Add Source'; ?></button>
            <?php if ($isEditing): ?>
                <a href="/admin/page/multimedia-sources" class="fav-admin-btn fav-admin-btn-secondary" style="margin-left: 6px;">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Sources Table -->
    <table class="fav-admin-table">
        <thead>
            <tr>
                <th>Type</th>
                <th>Content Item</th>
                <th>Label</th>
                <th>Source Format</th>
                <th>URL / Location</th>
                <th>Download</th>
                <th>Default</th>
                <th>Status</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="9" style="text-align:center; color:#94a3b8; padding:24px;">No media sources registered yet.</td></tr>
            <?php else: foreach ($items as $src): ?>
                <tr>
                    <td><span class="fav-badge fav-badge-public"><?php echo htmlspecialchars(strtoupper((string)($src->content_type ?? 'movie')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td>
                        <strong><?php echo htmlspecialchars($src->getContentTitle(), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span style="font-size: 11px; color: #64748b;">(#<?php echo (int)($src->content_id ?? 0); ?>)</span>
                    </td>
                    <td><strong><?php echo htmlspecialchars((string)($src->label ?? 'Main Stream'), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td><code><?php echo htmlspecialchars(strtoupper((string)($src->source_type ?? 'video')), ENT_QUOTES, 'UTF-8'); ?></code> <span style="font-size: 11px; color: #64748b;">(<?php echo htmlspecialchars((string)($src->source_mode ?? 'url'), ENT_QUOTES, 'UTF-8'); ?>)</span></td>
                    <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <a href="<?php echo htmlspecialchars((string)($src->url_or_path ?? ''), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="color: #2563eb;"><?php echo htmlspecialchars((string)($src->url_or_path ?? ''), ENT_QUOTES, 'UTF-8'); ?></a>
                    </td>
                    <td><?php echo htmlspecialchars(ucfirst((string)($src->allow_download ?? 'inherit')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo !empty($src->is_default) ? '⭐ Yes' : 'No'; ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)($src->status ?? 'active')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="text-align: right; white-space: nowrap;">
                        <a href="/multimedia/stream/<?php echo $src->id; ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;">Test Stream</a>
                        <a href="/admin/page/multimedia-sources?edit=<?php echo $src->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                        <form method="POST" action="/admin/page/multimedia-sources" style="display:inline;" onsubmit="return confirm('Delete source?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $src->id; ?>">
                            <input type="hidden" name="content_type" value="<?php echo $src->content_type; ?>">
                            <input type="hidden" name="content_id" value="<?php echo $src->content_id; ?>">
                            <button type="submit" class="fav-admin-btn fav-admin-btn-danger" style="padding: 2px 8px; font-size: 11px;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script src="/plugins/favorite-multimedia/assets/js/multimedia-admin.js"></script>

