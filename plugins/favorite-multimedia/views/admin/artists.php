<?php
/**
 * Favorite Multimedia — Admin Artists View
 */
$isEditing = ($editArtist !== null);
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🎤 Artists Management</h2>
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
        <a href="/admin/page/multimedia-artists" class="fav-admin-subnav-link active">🎤 Artists</a>
        <a href="/admin/page/multimedia-albums" class="fav-admin-subnav-link">💿 Albums</a>
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link">🎛️ Sources</a>
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link">💬 Subtitles</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <div style="display: grid; grid-template-columns: 340px 1fr; gap: 24px;">
        <!-- Form -->
        <div class="fav-admin-stat-card">
            <h3 style="margin-top:0; font-size:16px;">
                <?php echo $isEditing ? 'Edit Artist: ' . htmlspecialchars($editArtist->name, ENT_QUOTES, 'UTF-8') : 'Add New Artist'; ?>
            </h3>
            <form method="POST" action="/admin/page/multimedia-artists">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editArtist->id ?? 0; ?>">

                <div class="fav-form-group">
                    <label class="fav-form-label">Artist / Band Name *</label>
                    <input type="text" name="name" class="fav-form-control" value="<?php echo htmlspecialchars($editArtist->name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Slug</label>
                    <input type="text" name="slug" class="fav-form-control" value="<?php echo htmlspecialchars($editArtist->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Photo URL / Path</label>
                    <input type="text" name="photo" class="fav-form-control" value="<?php echo htmlspecialchars($editArtist->photo ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Biography</label>
                    <textarea name="biography" class="fav-form-control" rows="4"><?php echo htmlspecialchars($editArtist->biography ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Status</label>
                    <select name="status" class="fav-form-control">
                        <option value="active" <?php echo ($editArtist?->status === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($editArtist?->status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <button type="submit" class="fav-admin-btn"><?php echo $isEditing ? 'Save Artist' : 'Add Artist'; ?></button>
                <?php if ($isEditing): ?>
                    <a href="/admin/page/multimedia-artists" class="fav-admin-btn fav-admin-btn-secondary" style="margin-left: 6px;">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Table -->
        <div>
            <table class="fav-admin-table">
                <thead>
                    <tr>
                        <th style="width: 45px;">Photo</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:24px;">No artists added yet.</td></tr>
                    <?php else: foreach ($items as $a): ?>
                        <tr>
                            <td>
                                <?php if ($a->photo): ?>
                                    <img src="<?php echo htmlspecialchars($a->photo, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 36px; height: 36px; object-fit: cover; border-radius: 50%;">
                                <?php else: ?>
                                    <div style="width: 36px; height: 36px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px;">🎤</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($a->name, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><?php echo ucfirst($a->status ?? 'active'); ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="/admin/page/multimedia-artists?edit=<?php echo $a->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                                <form method="POST" action="/admin/page/multimedia-artists" style="display:inline;" onsubmit="return confirm('Delete artist?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $a->id; ?>">
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

