<?php
/**
 * Favorite Multimedia — Admin Subtitles View
 */
$isEditing = ($editSubtitle !== null);
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">💬 Subtitles Management</h2>
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
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link active">💬 Subtitles</a>
        <a href="/admin/page/multimedia-localizations" class="fav-admin-subnav-link">🌐 Localizations</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <div style="display: grid; grid-template-columns: 360px 1fr; gap: 24px;">
        <!-- Form -->
        <div class="fav-admin-stat-card">
            <h3 style="margin-top:0; font-size:16px;">
                <?php echo $isEditing ? 'Edit Subtitle Track' : '+ Add Subtitle Track'; ?>
            </h3>

            <form method="POST" action="/admin/page/multimedia-subtitles">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editSubtitle->id ?? 0; ?>">

                <div class="fav-form-group">
                    <label class="fav-form-label">Content Type *</label>
                    <select name="content_type" class="fav-form-control" id="sub_content_type">
                        <option value="movie" <?php echo ($editSubtitle?->content_type === 'movie') ? 'selected' : ''; ?>>Movie</option>
                        <option value="episode" <?php echo ($editSubtitle?->content_type === 'episode') ? 'selected' : ''; ?>>Episode</option>
                    </select>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Content Item *</label>
                    <select name="content_id" class="fav-form-control">
                        <optgroup label="Movies">
                            <?php foreach ($movies as $m): ?>
                                <option value="<?php echo $m->id; ?>" <?php echo (($editSubtitle?->content_id ?? 0) == $m->id && ($editSubtitle?->content_type ?? 'movie') === 'movie') ? 'selected' : ''; ?>>
                                    [Movie] <?php echo htmlspecialchars($m->title, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Episodes">
                            <?php foreach ($episodes as $ep): ?>
                                <option value="<?php echo $ep->id; ?>" <?php echo (($editSubtitle?->content_id ?? 0) == $ep->id && ($editSubtitle?->content_type ?? '') === 'episode') ? 'selected' : ''; ?>>
                                    [Ep] <?php echo htmlspecialchars($ep->getSeries()?->title . ' - ' . $ep->title, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">BCP 47 Language Code (e.g. en, bn, ar, es, fr) *</label>
                    <input type="text" name="language_code" class="fav-form-control" value="<?php echo htmlspecialchars($editSubtitle->language_code ?? $editSubtitle->language ?? 'en', ENT_QUOTES, 'UTF-8'); ?>" placeholder="en" required>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Label (e.g. English, বাংলা, العربية) *</label>
                    <input type="text" name="label" class="fav-form-control" value="<?php echo htmlspecialchars($editSubtitle->label ?? 'English', ENT_QUOTES, 'UTF-8'); ?>" placeholder="English" required>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Subtitle File URL or Local Path *</label>
                    <input type="text" name="file_or_url" class="fav-form-control" value="<?php echo htmlspecialchars($editSubtitle->file_or_url ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="storage/subtitles/track.vtt or https://..." required>
                    <div class="fav-form-hint">Supports WebVTT (.vtt) and SubRip (.srt). Local files must reside within project root.</div>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Format</label>
                    <select name="format" class="fav-form-control">
                        <option value="vtt" <?php echo ($editSubtitle?->format === 'vtt') ? 'selected' : ''; ?>>WebVTT (.vtt)</option>
                        <option value="srt" <?php echo ($editSubtitle?->format === 'srt') ? 'selected' : ''; ?>>SubRip (.srt)</option>
                    </select>
                </div>

                <div class="fav-form-group" style="display:flex; flex-direction:column; gap:8px;">
                    <label style="font-size: 13px; font-weight: 600;">
                        <input type="checkbox" name="is_default" value="1" <?php echo ($editSubtitle?->is_default) ? 'checked' : ''; ?>>
                        ⭐ Set as Default Subtitle Track
                    </label>
                    <label style="font-size: 13px; font-weight: 600;">
                        <input type="checkbox" name="is_forced" value="1" <?php echo (!empty($editSubtitle?->is_forced)) ? 'checked' : ''; ?>>
                        ⚡ Forced Subtitle (for foreign dialogue/signs)
                    </label>
                    <label style="font-size: 13px; font-weight: 600;">
                        <input type="checkbox" name="is_sdh" value="1" <?php echo (!empty($editSubtitle?->is_sdh)) ? 'checked' : ''; ?>>
                        🦻 SDH / Deaf & Hard of Hearing
                    </label>
                </div>

                <button type="submit" class="fav-admin-btn"><?php echo $isEditing ? 'Save Subtitle' : 'Add Subtitle'; ?></button>
                <?php if ($isEditing): ?>
                    <a href="/admin/page/multimedia-subtitles" class="fav-admin-btn fav-admin-btn-secondary" style="margin-left:6px;">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Table -->
        <div>
            <table class="fav-admin-table">
                <thead>
                    <tr>
                        <th>Content</th>
                        <th>Lang</th>
                        <th>Label</th>
                        <th>Format</th>
                        <th>Flags</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:24px;">No subtitles registered yet.</td></tr>
                    <?php else: foreach ($items as $sub): ?>
                        <tr>
                            <td>
                                <span class="fav-badge fav-badge-public"><?php echo strtoupper($sub->content_type); ?></span>
                                <strong><?php echo htmlspecialchars($sub->getContentTitle(), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span style="font-size:11px; color:#64748b;">(#<?php echo $sub->content_id; ?>)</span>
                            </td>
                            <td><code><?php echo htmlspecialchars($sub->getLanguageCode(), ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($sub->label, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><code><?php echo strtoupper($sub->format); ?></code></td>
                            <td>
                                <?php if ($sub->is_default): ?><span class="fav-badge" style="background:#fef3c7; color:#92400e;">⭐ Default</span><?php endif; ?>
                                <?php if ($sub->isForced()): ?><span class="fav-badge" style="background:#fee2e2; color:#991b1b;">⚡ Forced</span><?php endif; ?>
                                <?php if ($sub->isSdh()): ?><span class="fav-badge" style="background:#e0e7ff; color:#3730a3;">🦻 SDH</span><?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="/multimedia/subtitle/<?php echo $sub->id; ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;">View VTT</a>
                                <a href="/admin/page/multimedia-subtitles?edit=<?php echo $sub->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                                <form method="POST" action="/admin/page/multimedia-subtitles" style="display:inline;" onsubmit="return confirm('Delete subtitle?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $sub->id; ?>">
                                    <button type="submit" class="fav-admin-btn fav-admin-btn-danger" style="padding: 2px 8px; font-size: 11px;">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

