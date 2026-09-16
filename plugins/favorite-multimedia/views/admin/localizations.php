<?php
/**
 * Favorite Multimedia — Admin Localizations View
 */
$isEditing = ($editLocalization !== null);
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🌐 Metadata Localizations & Translations</h2>
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
        <a href="/admin/page/multimedia-localizations" class="fav-admin-subnav-link active">🌐 Localizations</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <?php if (isset($_SESSION['flash_success'])): ?>
        <div class="notice notice-success" style="padding:10px 14px; margin-bottom:16px; background:#dcfce7; border-left:4px solid #16a34a; border-radius:4px; color:#166534;">
            <?php echo htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="notice notice-error" style="padding:10px 14px; margin-bottom:16px; background:#fee2e2; border-left:4px solid #dc2626; border-radius:4px; color:#991b1b;">
            <?php echo htmlspecialchars($_SESSION['flash_error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['flash_error']); ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 380px 1fr; gap: 24px;">
        <!-- Form -->
        <div class="fav-admin-stat-card">
            <h3 style="margin-top:0; font-size:16px;">
                <?php echo $isEditing ? 'Edit Localization' : '+ Add Localization'; ?>
            </h3>

            <form method="POST" action="/admin/page/multimedia-localizations">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editLocalization->id ?? 0; ?>">

                <div class="fav-form-group">
                    <label class="fav-form-label">Content Type *</label>
                    <select name="content_type" class="fav-form-control" id="loc_content_type">
                        <option value="movie" <?php echo (($editLocalization?->content_type ?? $contentType) === 'movie') ? 'selected' : ''; ?>>Movie</option>
                        <option value="series" <?php echo (($editLocalization?->content_type ?? $contentType) === 'series') ? 'selected' : ''; ?>>Series</option>
                        <option value="episode" <?php echo (($editLocalization?->content_type ?? $contentType) === 'episode') ? 'selected' : ''; ?>>Episode</option>
                        <option value="song" <?php echo (($editLocalization?->content_type ?? $contentType) === 'song') ? 'selected' : ''; ?>>Song</option>
                    </select>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Content Item *</label>
                    <select name="content_id" class="fav-form-control">
                        <optgroup label="Movies">
                            <?php foreach ($movies as $m): ?>
                                <option value="<?php echo $m->id; ?>" <?php echo ((($editLocalization?->content_id ?? $contentId) == $m->id) && (($editLocalization?->content_type ?? $contentType) === 'movie')) ? 'selected' : ''; ?>>
                                    [Movie] <?php echo htmlspecialchars($m->title, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Series">
                            <?php foreach ($series as $s): ?>
                                <option value="<?php echo $s->id; ?>" <?php echo ((($editLocalization?->content_id ?? $contentId) == $s->id) && (($editLocalization?->content_type ?? $contentType) === 'series')) ? 'selected' : ''; ?>>
                                    [Series] <?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Episodes">
                            <?php foreach ($episodes as $ep): ?>
                                <option value="<?php echo $ep->id; ?>" <?php echo ((($editLocalization?->content_id ?? $contentId) == $ep->id) && (($editLocalization?->content_type ?? $contentType) === 'episode')) ? 'selected' : ''; ?>>
                                    [Ep] <?php echo htmlspecialchars($ep->getSeries()?->title . ' - ' . $ep->title, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Songs">
                            <?php foreach ($songs as $so): ?>
                                <option value="<?php echo $so->id; ?>" <?php echo ((($editLocalization?->content_id ?? $contentId) == $so->id) && (($editLocalization?->content_type ?? $contentType) === 'song')) ? 'selected' : ''; ?>>
                                    [Song] <?php echo htmlspecialchars($so->title, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">BCP 47 Language Code *</label>
                    <input type="text" name="language_code" class="fav-form-control" value="<?php echo htmlspecialchars($editLocalization->language_code ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="bn, ar, es, fr, hi, en-US" list="common_languages" required>
                    <datalist id="common_languages">
                        <?php foreach ($languages as $code => $info): ?>
                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($info['name'] . ' (' . $info['native'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="fav-form-hint">Standard BCP 47 code (e.g. <code>bn</code> for বাংলা, <code>ar</code> for العربية, <code>es</code> for Español).</div>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Localized Title *</label>
                    <input type="text" name="title" class="fav-form-control" value="<?php echo htmlspecialchars($editLocalization->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Translated title in chosen language" required>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Localized Tagline</label>
                    <input type="text" name="tagline" class="fav-form-control" value="<?php echo htmlspecialchars($editLocalization->tagline ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Short translated tagline">
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Localized Description / Synopsis</label>
                    <textarea name="description" class="fav-form-control" rows="5" placeholder="Full translated synopsis or summary..."><?php echo htmlspecialchars($editLocalization->description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <button type="submit" class="fav-admin-btn"><?php echo $isEditing ? 'Save Localization' : 'Add Localization'; ?></button>
                <?php if ($isEditing): ?>
                    <a href="/admin/page/multimedia-localizations" class="fav-admin-btn fav-admin-btn-secondary" style="margin-left:6px;">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Table -->
        <div>
            <table class="fav-admin-table">
                <thead>
                    <tr>
                        <th>Content Type</th>
                        <th>Content ID</th>
                        <th>Lang</th>
                        <th>Localized Title</th>
                        <th>Tagline / Description</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:24px;">No localized translations registered yet.</td></tr>
                    <?php else: foreach ($items as $loc): ?>
                        <tr>
                            <td>
                                <span class="fav-badge fav-badge-public"><?php echo strtoupper($loc->content_type); ?></span>
                            </td>
                            <td><code>#<?php echo (int)$loc->content_id; ?></code></td>
                            <td>
                                <code><?php echo htmlspecialchars($loc->language_code, ENT_QUOTES, 'UTF-8'); ?></code>
                                <div style="font-size:11px; color:#64748b;">
                                    <?php echo htmlspecialchars(\FavoriteCMS\Multimedia\Services\MediaLanguageService::getLanguageNativeLabel($loc->language_code), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </td>
                            <td><strong><?php echo htmlspecialchars($loc->title, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td style="max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px; color:#64748b;">
                                <?php echo htmlspecialchars($loc->tagline ?: $loc->description ?: '-', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="/admin/page/multimedia-localizations?edit=<?php echo $loc->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                                <form method="POST" action="/admin/page/multimedia-localizations" style="display:inline;" onsubmit="return confirm('Delete this localization?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $loc->id; ?>">
                                    <input type="hidden" name="content_type" value="<?php echo htmlspecialchars($loc->content_type, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="content_id" value="<?php echo (int)$loc->content_id; ?>">
                                    <input type="hidden" name="language_code" value="<?php echo htmlspecialchars($loc->language_code, ENT_QUOTES, 'UTF-8'); ?>">
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
