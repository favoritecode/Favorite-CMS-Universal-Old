<?php
/**
 * Favorite Multimedia — Admin Albums View
 */
$isEditing = ($editAlbum !== null);
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">💿 Albums Management</h2>
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
        <a href="/admin/page/multimedia-albums" class="fav-admin-subnav-link active">💿 Albums</a>
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link">🎛️ Sources</a>
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link">💬 Subtitles</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <div style="display: grid; grid-template-columns: 340px 1fr; gap: 24px;">
        <!-- Form -->
        <div class="fav-admin-stat-card">
            <h3 style="margin-top:0; font-size:16px;">
                <?php echo $isEditing ? 'Edit Album: ' . htmlspecialchars($editAlbum->title, ENT_QUOTES, 'UTF-8') : 'Add New Album'; ?>
            </h3>
            <form method="POST" action="/admin/page/multimedia-albums">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editAlbum->id ?? 0; ?>">

                <div class="fav-form-group">
                    <label class="fav-form-label">Album Title *</label>
                    <input type="text" name="title" class="fav-form-control" value="<?php echo htmlspecialchars($editAlbum->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Slug</label>
                    <input type="text" name="slug" class="fav-form-control" value="<?php echo htmlspecialchars($editAlbum->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Artist</label>
                    <select name="artist_id" class="fav-form-control">
                        <option value="0">-- None / Various --</option>
                        <?php foreach ($artists as $a): ?>
                            <option value="<?php echo $a->id; ?>" <?php echo (($editAlbum?->artist_id ?? 0) == $a->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a->name, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Cover Artwork URL / Path</label>
                    <input type="text" name="cover" class="fav-form-control" value="<?php echo htmlspecialchars($editAlbum->cover ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Release Date</label>
                    <input type="date" name="release_date" class="fav-form-control" value="<?php echo $editAlbum->release_date ?? ''; ?>">
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Status</label>
                    <select name="status" class="fav-form-control">
                        <option value="published" <?php echo ($editAlbum?->status === 'published') ? 'selected' : ''; ?>>Published</option>
                        <option value="draft" <?php echo ($editAlbum?->status === 'draft') ? 'selected' : ''; ?>>Draft</option>
                    </select>
                </div>

                <button type="submit" class="fav-admin-btn"><?php echo $isEditing ? 'Save Album' : 'Add Album'; ?></button>
                <?php if ($isEditing): ?>
                    <a href="/admin/page/multimedia-albums" class="fav-admin-btn fav-admin-btn-secondary" style="margin-left: 6px;">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Table -->
        <div>
            <table class="fav-admin-table">
                <thead>
                    <tr>
                        <th style="width: 45px;">Cover</th>
                        <th>Title</th>
                        <th>Artist</th>
                        <th>Release Date</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:24px;">No albums added yet.</td></tr>
                    <?php else: foreach ($items as $al): ?>
                        <tr>
                            <td>
                                <?php if ($al->cover): ?>
                                    <img src="<?php echo htmlspecialchars($al->cover, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 36px; height: 36px; object-fit: cover; border-radius: 4px;">
                                <?php else: ?>
                                    <div style="width: 36px; height: 36px; background: #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 14px;">💿</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($al->title, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><?php echo htmlspecialchars($al->getArtist()?->name ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $al->release_date ?: '—'; ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="/admin/page/multimedia-albums?edit=<?php echo $al->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                                <form method="POST" action="/admin/page/multimedia-albums" style="display:inline;" onsubmit="return confirm('Delete album?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $al->id; ?>">
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

<script src="/plugins/favorite-multimedia/assets/js/multimedia-admin.js"></script>


