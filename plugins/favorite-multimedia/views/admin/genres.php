<?php
/**
 * Favorite Multimedia — Admin Genres View
 */
$isEditing = ($editGenre !== null);
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🏷️ Genres Taxonomy</h2>
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
        <a href="/admin/page/multimedia-genres" class="fav-admin-subnav-link active">🏷️ Genres</a>
        <a href="/admin/page/multimedia-artists" class="fav-admin-subnav-link">🎤 Artists</a>
        <a href="/admin/page/multimedia-albums" class="fav-admin-subnav-link">💿 Albums</a>
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link">🎛️ Sources</a>
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link">💬 Subtitles</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <div style="display: grid; grid-template-columns: 320px 1fr; gap: 24px;">
        <!-- Add / Edit Form -->
        <div class="fav-admin-stat-card">
            <h3 style="margin-top:0; font-size:16px;">
                <?php echo $isEditing ? 'Edit Genre: ' . htmlspecialchars($editGenre->name, ENT_QUOTES, 'UTF-8') : 'Add New Genre'; ?>
            </h3>
            <form method="POST" action="/admin/page/multimedia-genres">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editGenre->id ?? 0; ?>">

                <div class="fav-form-group">
                    <label class="fav-form-label">Genre Name *</label>
                    <input type="text" name="name" class="fav-form-control" value="<?php echo htmlspecialchars($editGenre->name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required placeholder="e.g. Action, Drama, Jazz">
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Slug</label>
                    <input type="text" name="slug" class="fav-form-control" value="<?php echo htmlspecialchars($editGenre->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="auto-generated">
                </div>

                <div class="fav-form-group">
                    <label class="fav-form-label">Description</label>
                    <textarea name="description" class="fav-form-control" rows="3"><?php echo htmlspecialchars($editGenre->description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <button type="submit" class="fav-admin-btn"><?php echo $isEditing ? 'Save Genre' : 'Add Genre'; ?></button>
                <?php if ($isEditing): ?>
                    <a href="/admin/page/multimedia-genres" class="fav-admin-btn fav-admin-btn-secondary" style="margin-left: 6px;">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Genres Table -->
        <div>
            <table class="fav-admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Description</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:24px;">No genres created yet.</td></tr>
                    <?php else: foreach ($items as $g): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($g->name, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($g->slug, ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars($g->description ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="/admin/page/multimedia-genres?edit=<?php echo $g->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                                <form method="POST" action="/admin/page/multimedia-genres" style="display:inline;" onsubmit="return confirm('Delete genre?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $g->id; ?>">
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

