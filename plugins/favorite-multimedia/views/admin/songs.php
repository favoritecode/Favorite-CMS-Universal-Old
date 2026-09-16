<?php
/**
 * Favorite Multimedia — Admin Songs View (Full Audio + Video Source Parity)
 */
$isEditing = ($editSong !== null);
$selectedGenres = $isEditing ? array_column($editSong->getGenres(), 'id') : [];
$curPlaybackType = $isEditing ? $editSong->getPlaybackType() : 'audio';
$curDefaultMode = $isEditing ? $editSong->getDefaultPlaybackMode() : 'audio';
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🎵 Songs &amp; Music Videos Management</h2>
        <?php if ($isEditing): ?>
            <a href="/admin/page/multimedia-songs" class="fav-admin-btn fav-admin-btn-secondary">&larr; Back to Songs List</a>
        <?php endif; ?>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link">📺 Web Series</a>
        <a href="/admin/page/multimedia-seasons" class="fav-admin-subnav-link">📼 Seasons</a>
        <a href="/admin/page/multimedia-episodes" class="fav-admin-subnav-link">🎞️ Episodes</a>
        <a href="/admin/page/multimedia-songs" class="fav-admin-subnav-link active">🎵 Songs</a>
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
        <div class="fav-admin-stat-card" style="margin-bottom: 28px;">
            <h3 style="margin-top:0; font-size:18px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <?php echo $isEditing ? 'Edit Song: ' . htmlspecialchars($editSong->title, ENT_QUOTES, 'UTF-8') : '+ Create New Song'; ?>
            </h3>

            <form method="POST" action="/admin/page/multimedia-songs" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editSong->id ?? 0; ?>">

                <!-- SECTION 1: Basic Information -->
                <div class="fav-form-section">
                    <div class="fav-form-section-title">🎵 Track Information &amp; Playback Type</div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Song Title *</label>
                                <input type="text" name="title" id="fav_song_title" class="fav-form-control" value="<?php echo htmlspecialchars($editSong->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Midnight Horizon" required>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Slug</label>
                                <input type="text" name="slug" id="fav_song_slug" class="fav-form-control" value="<?php echo htmlspecialchars($editSong->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="midnight-horizon">
                                <div class="fav-form-hint">Leave blank to auto-generate from song title.</div>
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <label class="fav-form-label">Artist</label>
                                    <a href="/admin/page/multimedia-artists" target="_blank" style="font-size:11px; color:#2563eb; text-decoration:none;">+ New Artist</a>
                                </div>
                                <select name="artist_id" class="fav-form-control">
                                    <option value="0">-- None / Unknown --</option>
                                    <?php foreach ($artists as $a): ?>
                                        <option value="<?php echo $a->id; ?>" <?php echo (($editSong?->artist_id ?? 0) == $a->id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($a->name, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <label class="fav-form-label">Album</label>
                                    <a href="/admin/page/multimedia-albums" target="_blank" style="font-size:11px; color:#2563eb; text-decoration:none;">+ New Album</a>
                                </div>
                                <select name="album_id" class="fav-form-control">
                                    <option value="0">-- Single / No Album --</option>
                                    <?php foreach ($albums as $al): ?>
                                        <option value="<?php echo $al->id; ?>" <?php echo (($editSong?->album_id ?? 0) == $al->id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($al->title, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Language</label>
                                <input type="text" name="language" class="fav-form-control" value="<?php echo htmlspecialchars($editSong->language ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. English, Instrumental">
                            </div>
                        </div>
                    </div>

                    <!-- Playback Capabilities -->
                    <div class="fav-form-group" style="margin-top: 10px; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <label class="fav-form-label" style="font-weight: 700; color: #1e3a8a; margin-bottom: 8px; display: block;">
                            🎛️ Playback Capabilities (Single Canonical Song Entity)
                        </label>
                        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                            <label style="font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                                <input type="radio" name="playback_type" value="audio" <?php echo ($curPlaybackType === 'audio') ? 'checked' : ''; ?> onchange="updatePlaybackTypeVisibility(this.value)">
                                <span>🎵 <strong>Audio Only</strong> (Standard Audio Track)</span>
                            </label>
                            <label style="font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                                <input type="radio" name="playback_type" value="video" <?php echo ($curPlaybackType === 'video') ? 'checked' : ''; ?> onchange="updatePlaybackTypeVisibility(this.value)">
                                <span>🎬 <strong>Video Only</strong> (Music Video)</span>
                            </label>
                            <label style="font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                                <input type="radio" name="playback_type" value="audio_video" <?php echo ($curPlaybackType === 'audio_video') ? 'checked' : ''; ?> onchange="updatePlaybackTypeVisibility(this.value)">
                                <span>🎛️ <strong>Audio + Music Video</strong> (Dual Mode)</span>
                            </label>
                        </div>

                        <!-- Dual Mode Viewer Preference -->
                        <div id="fav_dual_mode_preference" style="margin-top: 12px; padding: 10px 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; <?php echo ($curPlaybackType === 'audio_video') ? '' : 'display:none;'; ?>">
                            <label class="fav-form-label" style="margin-bottom: 6px; font-size: 12px; color: #1e40af; font-weight: 600;">
                                Dual-Mode Initial Player for Visitors:
                            </label>
                            <div style="display: flex; gap: 20px; align-items: center;">
                                <label style="font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                    <input type="radio" name="default_playback_mode" value="audio" <?php echo ($curDefaultMode === 'audio') ? 'checked' : ''; ?>>
                                    🎵 Audio First (Visitors start on Audio Player)
                                </label>
                                <label style="font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                    <input type="radio" name="default_playback_mode" value="video" <?php echo ($curDefaultMode === 'video') ? 'checked' : ''; ?>>
                                    🎬 Music Video First (Visitors start on Music Video)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2A: Audio Source & Media -->
                <div id="fav_audio_source_section" class="fav-form-section" style="border: 2px solid #3b82f6; background: #f0f7ff; <?php echo ($curPlaybackType === 'video') ? 'display:none;' : ''; ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #bfdbfe; padding-bottom: 8px;">
                        <h4 style="margin:0; font-size: 15px; font-weight: 700; color: #1e40af; display: flex; align-items: center; gap: 8px;">
                            🔊 Audio Source &amp; Media (Track Publishing)
                            <?php if ($isEditing && $editSong): ?>
                                <?php $hasAud = !empty($attachedAudioSources); ?>
                                <span class="fav-badge <?php echo $hasAud ? 'fav-badge-success' : 'fav-badge-gray'; ?>">
                                    <?php echo $hasAud ? 'Ready (' . count($attachedAudioSources) . ')' : 'No Media'; ?>
                                </span>
                            <?php endif; ?>
                        </h4>
                        <?php if ($isEditing && $editSong): ?>
                            <a href="/admin/page/multimedia-sources?content_type=song&content_id=<?= (int)$editSong->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size: 11px; padding: 3px 8px;" target="_blank">🎛️ Advanced Sources &rarr;</a>
                        <?php endif; ?>
                    </div>

                    <?php 
                        $curDefaultAudio = ($isEditing && $editSong) ? ($defaultAudioSources[$editSong->id] ?? null) : null;
                    ?>
                    <?php if ($curDefaultAudio): ?>
                        <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; font-size: 13px;">
                            <strong>Current Default Audio:</strong> <code><?= htmlspecialchars(strtoupper((string)$curDefaultAudio->source_type), ENT_QUOTES, 'UTF-8') ?></code> &bull;
                            <span style="color: #475569;"><?= htmlspecialchars((string)($curDefaultAudio->label ?: 'Main Audio'), ENT_QUOTES, 'UTF-8') ?></span> &bull;
                            <a href="<?= htmlspecialchars((string)$curDefaultAudio->url_or_path, ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color: #2563eb; word-break: break-all;"><?= htmlspecialchars((string)$curDefaultAudio->url_or_path, ENT_QUOTES, 'UTF-8') ?></a>
                        </div>
                    <?php endif; ?>

                    <div class="fav-media-tabs">
                        <button type="button" class="fav-media-tab-btn active" id="fav_tab_btn_audio_upload" onclick="switchAudioTab('upload')">📁 Upload Audio File</button>
                        <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_audio_url" onclick="switchAudioTab('url')">🔗 Direct Audio URL</button>
                    </div>

                    <!-- Upload Audio Pane -->
                    <div id="fav_pane_audio_upload" style="display: block;">
                        <div class="fav-form-group">
                            <label class="fav-form-label">Select Audio File (MP3, M4A, FLAC, WAV, AAC, OGG, WMA)</label>
                            <input type="file" name="audio_file" id="fav_audio_file" class="fav-form-control" accept="audio/*,.mp3,.m4a,.flac,.wav,.aac,.ogg,.wma">
                            <span class="fav-form-hint">Server upload limit: <strong><?= htmlspecialchars($uploadMax) ?></strong> (post_max_size: <?= htmlspecialchars($postMax) ?>). Plays smoothly across all desktop and mobile browsers.</span>
                        </div>
                    </div>

                    <!-- Audio URL Pane -->
                    <div id="fav_pane_audio_url" style="display: none;">
                        <div class="fav-form-group">
                            <label class="fav-form-label">Direct Audio Stream URL</label>
                            <input type="text" name="audio_url" id="fav_audio_url" class="fav-form-control" placeholder="https://example.com/audio/track.mp3 or storage/multimedia/song.mp3">
                            <span class="fav-form-hint">Direct HTTPS link to audio stream file.</span>
                        </div>
                    </div>

                    <div class="fav-form-row" style="margin-top: 10px;">
                        <div class="fav-form-col">
                            <div class="fav-form-group" style="margin-bottom: 0;">
                                <label class="fav-form-label">Audio Stream Label (Optional)</label>
                                <input type="text" name="audio_source_label" class="fav-form-control" placeholder="e.g. Master Audio / Studio Mix">
                            </div>
                        </div>
                    </div>

                    <!-- Configured Audio Sources Table (if editing) -->
                    <?php if ($isEditing && $editSong && !empty($attachedAudioSources)): ?>
                        <div style="margin-top: 16px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <h5 style="margin: 0; font-size: 14px; color: #1e3a8a; font-weight: 700;">Configured Audio Sources (<?= count($attachedAudioSources) ?>)</h5>
                                <span style="font-size: 12px; color: #64748b;">Primary audio plays by default; switcher is enabled if multiple audio sources exist.</span>
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
                                    <?php foreach ($attachedAudioSources as $idx => $s): ?>
                                        <tr style="<?= ($s->status === 'inactive') ? 'opacity: 0.6;' : '' ?>">
                                            <td>
                                                <button type="submit" form="form_reorder_up_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 1px 5px; font-size: 11px;" <?= ($idx === 0) ? 'disabled' : '' ?>>▲</button>
                                                <button type="submit" form="form_reorder_dn_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 1px 5px; font-size: 11px;" <?= ($idx === count($attachedAudioSources) - 1) ? 'disabled' : '' ?>>▼</button>
                                            </td>
                                            <td><strong><?= htmlspecialchars($s->label ?: 'Audio Track', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                            <td><code><?= htmlspecialchars(strtoupper((string)($s->source_type ?? 'audio')), ENT_QUOTES, 'UTF-8') ?></code></td>
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
                                                <button type="submit" form="form_delete_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-danger" style="padding: 2px 6px; font-size: 11px;" onclick="return confirm('Delete this audio source?');">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SECTION 2B: Video / Music Video Source (100% Parity with Movies/Episodes) -->
                <div id="fav_video_source_section" class="fav-form-section" style="border: 2px solid #8b5cf6; background: #faf5ff; <?php echo ($curPlaybackType === 'audio') ? 'display:none;' : ''; ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #e9d5ff; padding-bottom: 8px;">
                        <h4 style="margin:0; font-size: 15px; font-weight: 700; color: #6b21a8; display: flex; align-items: center; gap: 8px;">
                            🎥 Music Video Publishing (Direct, HLS, YouTube, Vimeo, Embed)
                            <?php if ($isEditing && $editSong): ?>
                                <?php $hasVid = !empty($attachedVideoSources); ?>
                                <span class="fav-badge <?php echo $hasVid ? 'fav-badge-success' : 'fav-badge-gray'; ?>">
                                    <?php echo $hasVid ? 'Ready (' . count($attachedVideoSources) . ')' : 'No Video'; ?>
                                </span>
                            <?php endif; ?>
                        </h4>
                        <?php if ($isEditing && $editSong): ?>
                            <a href="/admin/page/multimedia-sources?content_type=song&content_id=<?= (int)$editSong->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size: 11px; padding: 3px 8px;" target="_blank">🎛️ Advanced Sources &rarr;</a>
                        <?php endif; ?>
                    </div>

                    <?php 
                        $curDefaultVideo = ($isEditing && $editSong) ? ($defaultVideoSources[$editSong->id] ?? null) : null;
                    ?>
                    <?php if ($curDefaultVideo): ?>
                        <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; font-size: 13px;">
                            <strong>Current Default Video:</strong> <code><?= htmlspecialchars(strtoupper((string)$curDefaultVideo->source_type), ENT_QUOTES, 'UTF-8') ?></code> &bull;
                            <span style="color: #475569;"><?= htmlspecialchars((string)($curDefaultVideo->label ?: 'Music Video'), ENT_QUOTES, 'UTF-8') ?></span> &bull;
                            <a href="<?= htmlspecialchars((string)$curDefaultVideo->url_or_path, ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color: #7c3aed; word-break: break-all;"><?= htmlspecialchars((string)$curDefaultVideo->url_or_path, ENT_QUOTES, 'UTF-8') ?></a>
                        </div>
                    <?php endif; ?>

                    <div class="fav-media-tabs">
                        <button type="button" class="fav-media-tab-btn active" id="fav_tab_btn_video_upload" onclick="switchVideoTab('upload')">📁 Upload Video File</button>
                        <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_video_url" onclick="switchVideoTab('url')">🔗 Direct Video URL</button>
                        <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_video_hls" onclick="switchVideoTab('hls')">📡 HLS Stream (.m3u8)</button>
                        <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_video_youtube" onclick="switchVideoTab('youtube')">📺 YouTube</button>
                        <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_video_vimeo" onclick="switchVideoTab('vimeo')">📼 Vimeo</button>
                        <button type="button" class="fav-media-tab-btn" id="fav_tab_btn_video_embed" onclick="switchVideoTab('embed')">🌐 External Embed</button>
                    </div>
                    <input type="hidden" name="source_type" id="fav_video_source_type_input" value="auto">

                    <!-- Video Upload Pane -->
                    <div id="fav_pane_video_upload" style="display: block;">
                        <div class="fav-form-group">
                            <label class="fav-form-label">Select Video File (MP4, WebM, MKV, MOV)</label>
                            <input type="file" name="video_file" id="fav_video_file" class="fav-form-control" accept="video/mp4,video/webm,video/quicktime,video/x-matroska">
                            <span class="fav-form-hint">Server upload limit: <strong><?= htmlspecialchars($uploadMax) ?></strong> (post_max_size: <?= htmlspecialchars($postMax) ?>). Direct streaming playback supported across all modern browsers.</span>
                            <?php if ($ffmpegActive): ?>
                                <div style="font-size: 12px; color: #15803d; margin-top: 5px; font-weight: 500;">✓ FFmpeg is active: uploaded MP4 files will automatically generate adaptive HLS renditions.</div>
                            <?php else: ?>
                                <div style="font-size: 12px; color: #b45309; margin-top: 5px; font-weight: 500;">ℹ FFmpeg is not installed: direct MP4/WebM uploads will play directly in HTML5 player without transcoding.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Video URL / Stream / Embed Pane -->
                    <div id="fav_pane_video_url" style="display: none;">
                        <div class="fav-form-group">
                            <label class="fav-form-label" id="fav_video_url_label">Direct Video URL (MP4, WebM)</label>
                            <input type="text" name="video_url" id="fav_video_url" class="fav-form-control" placeholder="https://example.com/videos/music-video.mp4">
                            <span class="fav-form-hint" id="fav_video_url_hint">Direct HTTPS link to MP4 or WebM video file.</span>
                        </div>
                    </div>

                    <div class="fav-form-row" style="margin-top: 10px;">
                        <div class="fav-form-col">
                            <div class="fav-form-group" style="margin-bottom: 0;">
                                <label class="fav-form-label">Video Stream Label (Optional)</label>
                                <input type="text" name="video_source_label" class="fav-form-control" placeholder="e.g. Official 4K Music Video / Live Performance">
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-group" style="margin-top: 10px; margin-bottom: 0;">
                        <label class="fav-form-label">Primary / Legacy Download URL (Optional)</label>
                        <input type="text" name="download_url" class="fav-form-control" value="<?php echo htmlspecialchars($editSong->download_url ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://cdn.example.com/files/song.mp3">
                        <span class="fav-form-hint">Quick direct download link. You can also configure multiple audio or video download options below.</span>
                    </div>

                    <!-- Multiple Download Links Section -->
                    <div style="margin-top: 16px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <h5 style="margin: 0; font-size: 14px; color: #1e3a8a; font-weight: 700;">Download Links (<?= !empty($downloadSources) ? count($downloadSources) : 0 ?>)</h5>
                                <span style="font-size: 12px; color: #64748b;">Add multiple download choices (e.g. MP3 320kbps, FLAC, Music Video MP4).</span>
                            </div>
                            <button type="button" class="fav-admin-btn fav-admin-btn-secondary" id="fav_btn_add_song_dl" style="font-size: 12px; padding: 4px 10px;">+ Add Download Link</button>
                        </div>
                        <table class="fav-admin-table" id="fav_table_song_dl" style="font-size: 12px; margin-top: 8px; <?= empty($downloadSources) ? 'display: none;' : '' ?>">
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
                            <tbody id="fav_tbody_song_dl">
                                <?php if (!empty($downloadSources)): ?>
                                    <?php foreach ($downloadSources as $dIdx => $dl): ?>
                                        <tr class="fav-dl-row" data-id="<?= (int)$dl->id ?>">
                                            <td>
                                                <input type="number" name="download_sources[<?= $dIdx ?>][sort_order]" class="fav-form-control" value="<?= (int)$dl->sort_order ?>" style="width: 50px; padding: 3px; text-align: center;">
                                                <input type="hidden" name="download_sources[<?= $dIdx ?>][id]" value="<?= (int)$dl->id ?>">
                                            </td>
                                            <td>
                                                <input type="text" name="download_sources[<?= $dIdx ?>][label]" class="fav-form-control" value="<?= htmlspecialchars($dl->label ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. MP3 320k / FLAC" style="padding: 3px 6px;">
                                            </td>
                                            <td>
                                                <input type="text" name="download_sources[<?= $dIdx ?>][url]" class="fav-form-control" value="<?= htmlspecialchars($dl->url ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="https://..." style="padding: 3px 6px;">
                                            </td>
                                            <td>
                                                <input type="text" name="download_sources[<?= $dIdx ?>][quality]" class="fav-form-control" value="<?= htmlspecialchars($dl->quality ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="320kbps" style="padding: 3px 6px;">
                                            </td>
                                            <td>
                                                <input type="text" name="download_sources[<?= $dIdx ?>][format]" class="fav-form-control" value="<?= htmlspecialchars($dl->format ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="MP3" style="padding: 3px 6px;">
                                            </td>
                                            <td>
                                                <input type="text" name="download_sources[<?= $dIdx ?>][provider]" class="fav-form-control" value="<?= htmlspecialchars($dl->provider ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Direct" style="padding: 3px 6px;">
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
                        <div id="fav_empty_song_dl" style="font-size: 12px; color: #94a3b8; font-style: italic; padding: 6px 0; <?= !empty($downloadSources) ? 'display: none;' : '' ?>">
                            No additional download links configured. Click "+ Add Download Link" to add one.
                        </div>
                        <div id="fav_del_song_dl_container"></div>
                    </div>

                    <!-- Configured Video Sources Table (if editing) -->
                    <?php if ($isEditing && $editSong && !empty($attachedVideoSources)): ?>
                        <div style="margin-top: 16px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <h5 style="margin: 0; font-size: 14px; color: #6b21a8; font-weight: 700;">Configured Video Sources (<?= count($attachedVideoSources) ?>)</h5>
                                <span style="font-size: 12px; color: #64748b;">Primary video plays by default; viewer source switcher is displayed if 2+ active video sources exist.</span>
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
                                    <?php foreach ($attachedVideoSources as $idx => $s): ?>
                                        <tr style="<?= ($s->status === 'inactive') ? 'opacity: 0.6;' : '' ?>">
                                            <td>
                                                <button type="submit" form="form_reorder_up_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 1px 5px; font-size: 11px;" <?= ($idx === 0) ? 'disabled' : '' ?>>▲</button>
                                                <button type="submit" form="form_reorder_dn_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 1px 5px; font-size: 11px;" <?= ($idx === count($attachedVideoSources) - 1) ? 'disabled' : '' ?>>▼</button>
                                            </td>
                                            <td><strong><?= htmlspecialchars($s->label ?: 'Music Video', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                            <td><code><?= htmlspecialchars(strtoupper((string)($s->source_type ?? 'video')), ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <a href="<?= htmlspecialchars((string)$s->url_or_path, ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color: #7c3aed;"><?= htmlspecialchars((string)$s->url_or_path, ENT_QUOTES, 'UTF-8') ?></a>
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
                                                <button type="submit" form="form_delete_<?= (int)$s->id ?>" class="fav-admin-btn fav-admin-btn-danger" style="padding: 2px 6px; font-size: 11px;" onclick="return confirm('Delete this video source?');">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SECTION 2C: Artwork, Duration & Metadata -->
                <div class="fav-form-section">
                    <div class="fav-form-section-title">🖼️ Artwork &amp; Track Metadata</div>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Cover Artwork URL or Local Path</label>
                                <input type="text" name="cover" id="fav_song_cover" class="fav-form-control" value="<?php echo htmlspecialchars($editSong->cover ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://.../cover.jpg">
                                <input type="file" name="cover_file" class="fav-form-control" accept="image/*" style="margin-top: 6px;">
                                <span class="fav-form-hint">Upload square cover image directly or paste URL above. Recommended: 1:1 square.</span>
                                <img id="fav_song_cover_preview" src="<?php echo htmlspecialchars($editSong->cover ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="fav-img-preview <?php echo empty($editSong->cover) ? 'fav-img-hidden' : ''; ?>" alt="Cover Preview" style="width: 80px; height: 80px; object-fit: cover;">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Duration (Seconds)</label>
                                <input type="number" name="duration" class="fav-form-control" value="<?php echo $editSong->duration ?? 180; ?>" min="0">
                                <div class="fav-form-hint"><?php echo $editSong ? 'Formatted: ' . ($editSong->getDurationFormatted() ?: '00:00') : 'e.g. 180 for 3:00'; ?></div>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Release Date</label>
                                <input type="date" name="release_date" class="fav-form-control" value="<?php echo $editSong->release_date ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Access & Settings -->
                <div class="fav-form-section">
                    <div class="fav-form-section-title">🔒 Access Control &amp; Publishing</div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Access Mode *</label>
                                <select name="access_mode" class="fav-form-control">
                                    <option value="public" <?php echo ($editSong?->access_mode === 'public') ? 'selected' : ''; ?>>PUBLIC (Free for all)</option>
                                    <option value="login" <?php echo ($editSong?->access_mode === 'login') ? 'selected' : ''; ?>>LOGIN REQUIRED</option>
                                    <option value="premium" <?php echo ($editSong?->access_mode === 'premium') ? 'selected' : ''; ?>>PREMIUM</option>
                                </select>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Download Policy *</label>
                                <select name="download_policy" class="fav-form-control">
                                    <option value="inherit" <?php echo ($editSong?->download_policy === 'inherit') ? 'selected' : ''; ?>>Inherit Global</option>
                                    <option value="allow" <?php echo ($editSong?->download_policy === 'allow') ? 'selected' : ''; ?>>Allow Download</option>
                                    <option value="deny" <?php echo ($editSong?->download_policy === 'deny') ? 'selected' : ''; ?>>Deny Download</option>
                                </select>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Publication Status *</label>
                                <select name="status" id="fav_pub_status" class="fav-form-control" onchange="toggleSchedulingFields(this.value)">
                                    <option value="published" <?php echo ($editSong?->status === 'published' || !$isEditing) ? 'selected' : ''; ?>>Published (Live)</option>
                                    <option value="scheduled" <?php echo ($editSong?->status === 'scheduled') ? 'selected' : ''; ?>>Scheduled for Release</option>
                                    <option value="draft" <?php echo ($editSong?->status === 'draft') ? 'selected' : ''; ?>>Draft (Hidden)</option>
                                    <option value="unpublished" <?php echo ($editSong?->status === 'unpublished') ? 'selected' : ''; ?>>Unpublished (Archived)</option>
                                </select>
                                <span class="fav-form-hint">Drafts and scheduled items are hidden from visitors.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Scheduling Row -->
                    <?php 
                    $tz = \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::getAppTimezone();
                    $pubAtLocal = !empty($editSong?->publish_at) ? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::utcToLocal($editSong->publish_at, 'Y-m-d\TH:i') : '';
                    $unpubAtLocal = !empty($editSong?->unpublish_at) ? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::utcToLocal($editSong->unpublish_at, 'Y-m-d\TH:i') : '';
                    $showSched = ($editSong?->status === 'scheduled');
                    ?>
                    <div class="fav-form-row" id="fav_scheduling_row" style="margin-top: 10px; <?php echo $showSched ? '' : 'display:none;'; ?>">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Schedule Release Time (<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>)</label>
                                <input type="datetime-local" name="publish_at" class="fav-form-control" value="<?php echo htmlspecialchars($pubAtLocal, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Auto-Unpublish Time (Optional)</label>
                                <input type="datetime-local" name="unpublish_at" class="fav-form-control" value="<?php echo htmlspecialchars($unpubAtLocal, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: Notes & Lyrics -->
                <div class="fav-form-section">
                    <div class="fav-form-section-title">📝 Liner Notes &amp; Lyrics</div>

                    <div class="fav-form-group">
                        <label class="fav-form-label">Description / Liner Notes</label>
                        <textarea name="description" class="fav-form-control" rows="2" placeholder="Brief notes about the track..."><?php echo htmlspecialchars($editSong->description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="fav-form-group">
                        <label class="fav-form-label">Lyrics</label>
                        <textarea name="lyrics" class="fav-form-control" rows="5" placeholder="Enter song lyrics here..."><?php echo htmlspecialchars($editSong->lyrics ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>

                <!-- SECTION 5: SEO -->
                <div class="fav-form-section">
                    <div class="fav-form-section-title">🔍 Search Engine Optimization</div>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Custom SEO Title</label>
                                <input type="text" name="seo_title" class="fav-form-control" value="<?php echo htmlspecialchars($editSong->seo_title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Defaults to Song Title">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">SEO Meta Description</label>
                                <input type="text" name="seo_description" class="fav-form-control" value="<?php echo htmlspecialchars($editSong->seo_description ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Brief description for search engines">
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 16px;">
                    <button type="submit" name="submit_action" value="publish" class="fav-admin-btn" style="padding: 10px 22px; font-size: 14px; background: #16a34a; border-color: #16a34a;">🚀 Publish Now</button>
                    <button type="submit" name="submit_action" value="draft" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">📝 Save Draft</button>
                    <button type="submit" name="submit_action" value="schedule" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">📅 Schedule</button>
                    <?php if ($isEditing): ?>
                        <a href="/admin/page/multimedia-songs" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Independent Helper Forms for Sources (Outside main song form) -->
            <?php if ($isEditing && $editSong && !empty($attachedSources)): ?>
                <?php foreach ($attachedSources as $s): ?>
                    <form id="form_reorder_up_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="direction" value="up">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-songs?edit=<?= (int)$editSong->id ?>">
                    </form>
                    <form id="form_reorder_dn_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="direction" value="down">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-songs?edit=<?= (int)$editSong->id ?>">
                    </form>
                    <form id="form_setdefault_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="set_default">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-songs?edit=<?= (int)$editSong->id ?>">
                    </form>
                    <form id="form_toggle_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-songs?edit=<?= (int)$editSong->id ?>">
                    </form>
                    <form id="form_delete_<?= (int)$s->id ?>" method="POST" action="/admin/page/multimedia-sources" style="display:none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$s->id ?>">
                        <input type="hidden" name="content_type" value="song">
                        <input type="hidden" name="content_id" value="<?= (int)$editSong->id ?>">
                        <input type="hidden" name="redirect_to" value="/admin/page/multimedia-songs?edit=<?= (int)$editSong->id ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Add Additional Sources Subforms (when editing) -->
            <?php if ($isEditing && $editSong): ?>
                <div style="display: flex; gap: 16px; margin-top: 24px; flex-wrap: wrap;">
                    <!-- Additional Audio Source Subform -->
                    <div style="flex: 1; min-width: 300px; border: 1px dashed #3b82f6; background: #f0f7ff; border-radius: 6px; padding: 12px 16px;">
                        <details>
                            <summary style="font-weight: 700; cursor: pointer; color: #1e40af; font-size: 13px;">➕ Add Another Audio Track / Mirror</summary>
                            <form id="form_add_another_audio_source" method="POST" action="/admin/page/multimedia-sources" enctype="multipart/form-data" style="margin-top: 10px;">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="add_source">
                                <input type="hidden" name="content_type" value="song">
                                <input type="hidden" name="content_id" value="<?= (int)$editSong->id ?>">
                                <input type="hidden" name="media_kind" value="audio">
                                <input type="hidden" name="source_type" value="audio">
                                <input type="hidden" name="redirect_to" value="/admin/page/multimedia-songs?edit=<?= (int)$editSong->id ?>">

                                <div class="fav-form-group">
                                    <label class="fav-form-label">Audio Track Label</label>
                                    <input type="text" name="source_label" class="fav-form-control" placeholder="e.g. Master FLAC / Instrumental">
                                </div>
                                <div class="fav-form-group">
                                    <label class="fav-form-label">Direct Audio Stream URL</label>
                                    <input type="text" name="audio_url" class="fav-form-control" placeholder="https://...">
                                </div>
                                <div class="fav-form-group">
                                    <label class="fav-form-label">Or Upload Audio File</label>
                                    <input type="file" name="audio_file" class="fav-form-control" accept="audio/*,.mp3,.m4a,.flac,.wav,.aac,.ogg,.wma">
                                </div>
                                <div style="margin-top: 8px;">
                                    <label style="font-size: 12px; font-weight: 600; cursor: pointer;">
                                        <input type="checkbox" name="is_default" value="1"> Make Primary Audio Source
                                    </label>
                                </div>
                                <button type="submit" class="fav-admin-btn fav-admin-btn-primary" style="margin-top: 10px; font-size: 12px;">+ Save &amp; Attach Audio Source</button>
                            </form>
                        </details>
                    </div>

                    <!-- Additional Video Source Subform -->
                    <div style="flex: 1; min-width: 300px; border: 1px dashed #8b5cf6; background: #faf5ff; border-radius: 6px; padding: 12px 16px;">
                        <details>
                            <summary style="font-weight: 700; cursor: pointer; color: #6b21a8; font-size: 13px;">➕ Add Another Video / Music Video Source</summary>
                            <form id="form_add_another_video_source" method="POST" action="/admin/page/multimedia-sources" enctype="multipart/form-data" style="margin-top: 10px;">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="add_source">
                                <input type="hidden" name="content_type" value="song">
                                <input type="hidden" name="content_id" value="<?= (int)$editSong->id ?>">
                                <input type="hidden" name="media_kind" value="video">
                                <input type="hidden" name="redirect_to" value="/admin/page/multimedia-songs?edit=<?= (int)$editSong->id ?>">

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
                                        <input type="text" name="source_label" class="fav-form-control" placeholder="e.g. YouTube Video, 1080p Mirror">
                                    </div>
                                </div>
                                <div class="fav-form-group" style="margin-top: 8px;">
                                    <label class="fav-form-label">Video / Stream / YouTube URL</label>
                                    <input type="text" name="video_url" class="fav-form-control" placeholder="https://...">
                                </div>
                                <div class="fav-form-group" style="margin-top: 8px;">
                                    <label class="fav-form-label">Or Upload Alternate Video File</label>
                                    <input type="file" name="video_file" class="fav-form-control" accept="video/mp4,video/webm,video/quicktime,video/x-matroska">
                                </div>
                                <div style="margin-top: 8px;">
                                    <label style="font-size: 12px; font-weight: 600; cursor: pointer;">
                                        <input type="checkbox" name="is_default" value="1"> Make Primary Video Source
                                    </label>
                                </div>
                                <button type="submit" class="fav-admin-btn" style="background:#7c3aed; color:#fff; border-color:#7c3aed; margin-top: 10px; font-size: 12px;">+ Save &amp; Attach Video Source</button>
                            </form>
                        </details>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:16px;">All Songs &amp; Music Videos (<?php echo count($items); ?>)</h3>
        <?php if (!$isEditing && !isset($_GET['new'])): ?>
            <a href="/admin/page/multimedia-songs?new=1" class="fav-admin-btn">+ Add New Song</a>
        <?php endif; ?>
    </div>

    <form method="POST" action="/admin/page/multimedia-songs" id="songs-bulk-form">
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
                    <th style="width: 45px;">Cover</th>
                    <th>Title</th>
                    <th>Artist</th>
                    <th>Album</th>
                    <th>Capabilities</th>
                    <th>Duration</th>
                    <th>Media Status</th>
                    <th>Access</th>
                    <th>Plays</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="12" style="padding: 0;">
                            <div class="fav-empty-card">
                                <div style="font-size: 44px; margin-bottom: 10px;">🎵</div>
                                <h3 style="font-size: 18px; margin: 0 0 8px 0; color: #1e293b;">No Songs Published Yet</h3>
                                <p style="color: #64748b; font-size: 14px; margin: 0 0 18px 0;">Publish your first song by uploading an audio track or adding a music video.</p>
                                <a href="/admin/page/multimedia-songs?new=1" class="fav-admin-btn" style="padding: 10px 24px; font-size: 14px;">+ Add First Song</a>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($items as $s): ?>
                    <tr>
                        <td style="text-align: center;">
                            <input type="checkbox" name="ids[]" value="<?php echo (int)$s->id; ?>" class="bulk-cb">
                        </td>
                        <td>
                            <?php if ($s->cover): ?>
                                <img src="<?php echo htmlspecialchars($s->cover, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 36px; height: 36px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                <div style="width: 36px; height: 36px; background: #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 14px;">🎵</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($s->featured): ?><span style="font-size: 11px; color: #f59e0b;">⭐</span><?php endif; ?>
                            <div style="font-size: 11px; color:#64748b;">/song/<?php echo htmlspecialchars($s->slug, ENT_QUOTES, 'UTF-8'); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($s->getArtist()?->name ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($s->getAlbum()?->title ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php $pType = $s->getPlaybackType(); ?>
                            <?php if ($pType === 'audio'): ?>
                                <span class="fav-badge" style="background:#dbeafe; color:#1e40af; font-size:11px;">🎵 Audio</span>
                            <?php elseif ($pType === 'video'): ?>
                                <span class="fav-badge" style="background:#f3e8ff; color:#6b21a8; font-size:11px;">🎬 Video</span>
                            <?php else: ?>
                                <span class="fav-badge" style="background:#fef3c7; color:#92400e; font-size:11px;">🎛️ Dual-Mode</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $s->getDurationFormatted() ?: '—'; ?></td>
                        <td>
                            <?php 
                                $hasA = $s->hasAudio();
                                $hasV = $s->hasVideo();
                            ?>
                            <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                <?php if ($s->getPlaybackType() !== 'video'): ?>
                                    <span class="fav-badge <?= $hasA ? 'fav-badge-success' : 'fav-badge-danger' ?>" style="font-size: 10px;">
                                        🎵 <?= $hasA ? 'Audio' : 'No Audio' ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($s->getPlaybackType() !== 'audio'): ?>
                                    <span class="fav-badge <?= $hasV ? 'fav-badge-success' : 'fav-badge-danger' ?>" style="font-size: 10px;">
                                        🎬 <?= $hasV ? 'Video' : 'No Video' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="fav-badge fav-badge-<?php echo strtolower($s->access_mode ?? 'public'); ?>"><?php echo strtoupper($s->access_mode ?? 'public'); ?></span></td>
                        <td><?php echo number_format((int)($s->plays_count ?? 0)); ?></td>
                        <td><?php echo ucfirst($s->status ?? 'published'); ?></td>
                        <td style="text-align: right; white-space: nowrap;">
                            <a href="/admin/page/multimedia-sources?content_type=song&content_id=<?php echo $s->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;">Sources</a>
                            <a href="/song/<?php echo htmlspecialchars($s->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;">View</a>
                            <a href="/admin/page/multimedia-songs?edit=<?php echo $s->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                            <button type="button" class="fav-admin-btn fav-admin-btn-danger fmm-delete-single-btn" data-id="<?php echo $s->id; ?>" data-title="<?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 2px 8px; font-size: 11px;">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </form>

    <!-- Dedicated Single-Delete Form (prevents HTML5 nested form breakage) -->
    <form method="POST" action="/admin/page/multimedia-songs" id="fmm-song-single-delete-form" style="display:none;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="fmm-song-single-delete-id" value="">
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto slug generation
    const titleInput = document.getElementById('fav_song_title');
    const slugInput = document.getElementById('fav_song_slug');
    if (titleInput && slugInput && !slugInput.value) {
        titleInput.addEventListener('input', function() {
            slugInput.value = titleInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        });
    }

    // Cover preview
    const coverInput = document.getElementById('fav_song_cover');
    const coverPreview = document.getElementById('fav_song_cover_preview');
    if (coverInput && coverPreview) {
        coverInput.addEventListener('input', function() {
            if (coverInput.value.trim()) {
                coverPreview.src = coverInput.value.trim();
                coverPreview.classList.remove('fav-img-hidden');
            } else {
                coverPreview.classList.add('fav-img-hidden');
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

function updatePlaybackTypeVisibility(type) {
    const audioSec = document.getElementById('fav_audio_source_section');
    const videoSec = document.getElementById('fav_video_source_section');
    const dualPref = document.getElementById('fav_dual_mode_preference');

    if (audioSec) {
        audioSec.style.display = (type === 'audio' || type === 'audio_video') ? 'block' : 'none';
    }
    if (videoSec) {
        videoSec.style.display = (type === 'video' || type === 'audio_video') ? 'block' : 'none';
    }
    if (dualPref) {
        dualPref.style.display = (type === 'audio_video') ? 'block' : 'none';
    }
}

function switchAudioTab(tab) {
    document.querySelectorAll('#fav_audio_source_section .fav-media-tab-btn').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('fav_tab_btn_audio_' + tab);
    if (btn) btn.classList.add('active');

    const uploadPane = document.getElementById('fav_pane_audio_upload');
    const urlPane = document.getElementById('fav_pane_audio_url');
    if (tab === 'upload') {
        if (uploadPane) uploadPane.style.display = 'block';
        if (urlPane) urlPane.style.display = 'none';
    } else {
        if (uploadPane) uploadPane.style.display = 'none';
        if (urlPane) urlPane.style.display = 'block';
    }
}

function switchVideoTab(tab) {
    document.querySelectorAll('#fav_video_source_section .fav-media-tab-btn').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('fav_tab_btn_video_' + tab);
    if (btn) btn.classList.add('active');

    const uploadPane = document.getElementById('fav_pane_video_upload');
    const urlPane = document.getElementById('fav_pane_video_url');
    const urlLabel = document.getElementById('fav_video_url_label');
    const urlInput = document.getElementById('fav_video_url');
    const urlHint = document.getElementById('fav_video_url_hint');
    const typeInput = document.getElementById('fav_video_source_type_input');

    if (tab === 'upload') {
        if (uploadPane) uploadPane.style.display = 'block';
        if (urlPane) urlPane.style.display = 'none';
        if (typeInput) typeInput.value = 'auto';
    } else {
        if (uploadPane) uploadPane.style.display = 'none';
        if (urlPane) urlPane.style.display = 'block';

        if (tab === 'url') {
            if (urlLabel) urlLabel.textContent = 'Direct Video URL (MP4, WebM)';
            if (urlInput) urlInput.placeholder = 'https://example.com/video/music-video.mp4';
            if (urlHint) urlHint.textContent = 'Direct link to an MP4 or WebM video stream.';
            if (typeInput) typeInput.value = 'video';
        } else if (tab === 'hls') {
            if (urlLabel) urlLabel.textContent = 'HLS Master Playlist URL (.m3u8)';
            if (urlInput) urlInput.placeholder = 'https://example.com/hls/music-video/master.m3u8';
            if (urlHint) urlHint.textContent = 'HTTP Live Streaming playlist URL. Native HLS playback supported.';
            if (typeInput) typeInput.value = 'hls';
        } else if (tab === 'youtube') {
            if (urlLabel) urlLabel.textContent = 'YouTube Video URL';
            if (urlInput) urlInput.placeholder = 'https://www.youtube.com/watch?v=... or https://youtu.be/...';
            if (urlHint) urlHint.textContent = 'Direct link to YouTube music video.';
            if (typeInput) typeInput.value = 'embed';
        } else if (tab === 'vimeo') {
            if (urlLabel) urlLabel.textContent = 'Vimeo Video URL';
            if (urlInput) urlInput.placeholder = 'https://vimeo.com/...';
            if (urlHint) urlHint.textContent = 'Direct link to Vimeo music video.';
            if (typeInput) typeInput.value = 'embed';
        } else if (tab === 'embed') {
            if (urlLabel) urlLabel.textContent = 'External Embed / Player URL';
            if (urlInput) urlInput.placeholder = 'https://player.example.com/embed/...';
            if (urlHint) urlHint.textContent = 'Trusted third-party embed player URL.';
            if (typeInput) typeInput.value = 'embed';
        }
    }
}

// Dynamic Download Links Handler for Songs
(function() {
    var newIdx = 0;
    var btnAdd = document.getElementById('fav_btn_add_song_dl');
    var tbody = document.getElementById('fav_tbody_song_dl');
    var table = document.getElementById('fav_table_song_dl');
    var emptyNotice = document.getElementById('fav_empty_song_dl');
    var delContainer = document.getElementById('fav_del_song_dl_container');

    if (btnAdd && tbody) {
        btnAdd.addEventListener('click', function() {
            newIdx++;
            if (table) table.style.display = '';
            if (emptyNotice) emptyNotice.style.display = 'none';

            var tr = document.createElement('tr');
            tr.className = 'fav-dl-row';
            tr.innerHTML = '<td><input type="number" name="new_download_sources[' + newIdx + '][sort_order]" class="fav-form-control" value="' + (tbody.children.length) + '" style="width: 50px; padding: 3px; text-align: center;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][label]" class="fav-form-control" placeholder="e.g. MP3 320k" style="padding: 3px 6px;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][url]" class="fav-form-control" placeholder="https://..." style="padding: 3px 6px;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][quality]" class="fav-form-control" placeholder="320kbps" style="padding: 3px 6px;"></td>' +
                '<td><input type="text" name="new_download_sources[' + newIdx + '][format]" class="fav-form-control" placeholder="MP3" style="padding: 3px 6px;"></td>' +
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
