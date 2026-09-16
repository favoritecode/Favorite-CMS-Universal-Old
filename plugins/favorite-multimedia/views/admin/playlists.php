<?php
/**
 * Favorite Multimedia — Admin Playlists View
 */
$isEditing = ($editPlaylist !== null);
$playlistSongs = $isEditing ? $editPlaylist->getSongs() : [];
$playlistSongIds = array_column($playlistSongs, 'id');
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🎼 Playlists Management</h2>
        <?php if ($isEditing): ?>
            <a href="/admin/page/multimedia-playlists" class="fav-admin-btn fav-admin-btn-secondary">&larr; Back to Playlists</a>
        <?php endif; ?>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link">📺 Web Series</a>
        <a href="/admin/page/multimedia-seasons" class="fav-admin-subnav-link">📼 Seasons</a>
        <a href="/admin/page/multimedia-episodes" class="fav-admin-subnav-link">🎞️ Episodes</a>
        <a href="/admin/page/multimedia-songs" class="fav-admin-subnav-link">🎵 Songs</a>
        <a href="/admin/page/multimedia-playlists" class="fav-admin-subnav-link active">🎼 Playlists</a>
        <a href="/admin/page/multimedia-genres" class="fav-admin-subnav-link">🏷️ Genres</a>
        <a href="/admin/page/multimedia-artists" class="fav-admin-subnav-link">🎤 Artists</a>
        <a href="/admin/page/multimedia-albums" class="fav-admin-subnav-link">💿 Albums</a>
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link">🎛️ Sources</a>
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link">💬 Subtitles</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <?php if ($isEditing || !empty($isCreating) || isset($_GET['new'])): ?>
        <div class="fav-admin-stat-card" style="margin-bottom: 28px;">
            <h3 style="margin-top:0; font-size:18px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <?php echo $isEditing ? 'Edit Playlist: ' . htmlspecialchars($editPlaylist->title, ENT_QUOTES, 'UTF-8') : '+ Create New Playlist'; ?>
            </h3>

            <form method="POST" action="/admin/page/multimedia-playlists">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editPlaylist->id ?? 0; ?>">

                <!-- SECTION 1: Basic Info -->
                <div class="fav-form-section">
                    <div class="fav-form-section-title">🎼 Playlist Details</div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Playlist Title *</label>
                                <input type="text" name="title" id="fav_playlist_title" class="fav-form-control" value="<?php echo htmlspecialchars($editPlaylist->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Chill Synthwave 2026" required>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Slug</label>
                                <input type="text" name="slug" id="fav_playlist_slug" class="fav-form-control" value="<?php echo htmlspecialchars($editPlaylist->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="chill-synthwave-2026">
                                <div class="fav-form-hint">Leave blank to auto-generate from title.</div>
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-group">
                        <label class="fav-form-label">Description / Bio</label>
                        <textarea name="description" class="fav-form-control" rows="3" placeholder="Curated playlist description..."><?php echo htmlspecialchars($editPlaylist->description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>

                <!-- SECTION 2: Artwork & Access -->
                <div class="fav-form-section">
                    <div class="fav-form-section-title">🎨 Artwork &amp; Access Control</div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Cover Artwork URL / Path</label>
                                <input type="text" name="cover" id="fav_playlist_cover" class="fav-form-control" value="<?php echo htmlspecialchars($editPlaylist->cover ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://.../playlist-cover.jpg">
                                <img id="fav_playlist_cover_preview" src="<?php echo htmlspecialchars($editPlaylist->cover ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="fav-img-preview <?php echo empty($editPlaylist->cover) ? 'fav-img-hidden' : ''; ?>" alt="Playlist Cover Preview" style="width: 80px; height: 80px;">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Playlist Access Mode *</label>
                                <select name="access_mode" class="fav-form-control">
                                    <option value="public" <?php echo ($editPlaylist?->access_mode === 'public') ? 'selected' : ''; ?>>PUBLIC (Anyone can browse playlist)</option>
                                    <option value="login" <?php echo ($editPlaylist?->access_mode === 'login') ? 'selected' : ''; ?>>LOGIN REQUIRED</option>
                                    <option value="premium" <?php echo ($editPlaylist?->access_mode === 'premium') ? 'selected' : ''; ?>>PREMIUM ONLY</option>
                                </select>
                                <div class="fav-form-hint">Controls playlist browsing visibility. Individual songs retain their own access permission.</div>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Status</label>
                                <select name="status" class="fav-form-control">
                                    <option value="published" <?php echo ($editPlaylist?->status === 'published') ? 'selected' : ''; ?>>Published</option>
                                    <option value="draft" <?php echo ($editPlaylist?->status === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Select Songs -->
                <div class="fav-form-section">
                    <div class="fav-form-section-title">🎵 Track Selection (<?php echo count($playlistSongIds); ?> selected)</div>

                    <div class="fav-form-group">
                        <label class="fav-form-label">Check Songs to Include in this Playlist:</label>
                        <div style="max-height: 260px; overflow-y: auto; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; background: #fff;">
                            <?php if (empty($allSongs)): ?>
                                <p style="color:#64748b; margin:0; font-size:13px;">No songs available. Please create songs first.</p>
                            <?php else: foreach ($allSongs as $s): ?>
                                <label style="display: flex; align-items: center; justify-content: space-between; padding: 6px 8px; font-size: 13px; cursor: pointer; border-radius: 4px; border-bottom: 1px solid #f1f5f9;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" name="songs[]" value="<?php echo $s->id; ?>" <?php echo in_array($s->id, $playlistSongIds) ? 'checked' : ''; ?>>
                                        <strong><?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span style="color:#64748b;">— <?php echo htmlspecialchars($s->getArtist()?->name ?: 'Various', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span class="fav-badge fav-badge-<?php echo strtolower($s->access_mode ?? 'public'); ?>" style="font-size: 10px;"><?php echo strtoupper($s->access_mode ?? 'public'); ?></span>
                                        <span style="color: #94a3b8; font-size: 11px;"><?php echo $s->getDurationFormatted(); ?></span>
                                    </div>
                                </label>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 16px;">
                    <button type="submit" class="fav-admin-btn"><?php echo $isEditing ? 'Save Playlist' : 'Create Playlist'; ?></button>
                    <?php if ($isEditing): ?>
                        <a href="/admin/page/multimedia-playlists" class="fav-admin-btn fav-admin-btn-secondary" style="margin-left: 8px;">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:16px;">All Playlists (<?php echo count($items); ?>)</h3>
        <?php if (!$isEditing && !isset($_GET['new'])): ?>
            <a href="/admin/page/multimedia-playlists?new=1" class="fav-admin-btn">+ Add New Playlist</a>
        <?php endif; ?>
    </div>

    <table class="fav-admin-table">
        <thead>
            <tr>
                <th style="width: 45px;">Cover</th>
                <th>Title</th>
                <th>Tracks</th>
                <th>Access</th>
                <th>Status</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:24px;">No playlists created yet.</td></tr>
            <?php else: foreach ($items as $pl): ?>
                <tr>
                    <td>
                        <?php if ($pl->cover): ?>
                            <img src="<?php echo htmlspecialchars($pl->cover, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 36px; height: 36px; object-fit: cover; border-radius: 4px;">
                        <?php else: ?>
                            <div style="width: 36px; height: 36px; background: #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 14px;">🎼</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($pl->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div style="font-size: 11px; color:#64748b;">/playlist/<?php echo htmlspecialchars($pl->slug, ENT_QUOTES, 'UTF-8'); ?></div>
                    </td>
                    <td><?php echo count($pl->getSongs()); ?> songs</td>
                    <td><span class="fav-badge fav-badge-<?php echo strtolower($pl->access_mode ?? 'public'); ?>"><?php echo strtoupper($pl->access_mode ?? 'public'); ?></span></td>
                    <td><?php echo ucfirst($pl->status ?? 'published'); ?></td>
                    <td style="text-align: right; white-space: nowrap;">
                        <a href="/playlist/<?php echo htmlspecialchars($pl->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;">Play &rarr;</a>
                        <a href="/admin/page/multimedia-playlists?edit=<?php echo $pl->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                        <form method="POST" action="/admin/page/multimedia-playlists" style="display:inline;" onsubmit="return confirm('Delete this playlist?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $pl->id; ?>">
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
    const titleInput = document.getElementById('fav_playlist_title');
    const slugInput = document.getElementById('fav_playlist_slug');
    if (titleInput && slugInput && !slugInput.value) {
        titleInput.addEventListener('input', function() {
            slugInput.value = titleInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        });
    }

    const coverInput = document.getElementById('fav_playlist_cover');
    const coverPreview = document.getElementById('fav_playlist_cover_preview');
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
</script>
<script src="/plugins/favorite-multimedia/assets/js/multimedia-admin.js"></script>

