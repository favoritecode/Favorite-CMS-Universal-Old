<?php
/**
 * Favorite Multimedia — Admin Seasons View
 */
$isEditing = ($editSeason !== null);
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">📼 Seasons Management</h2>
        <a href="/admin/page/multimedia-series" class="fav-admin-btn fav-admin-btn-secondary">&larr; Back to Series</a>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link">📺 Web Series</a>
        <a href="/admin/page/multimedia-seasons" class="fav-admin-subnav-link active">📼 Seasons</a>
        <a href="/admin/page/multimedia-episodes" class="fav-admin-subnav-link">🎞️ Episodes</a>
        <a href="/admin/page/multimedia-songs" class="fav-admin-subnav-link">🎵 Songs</a>
        <a href="/admin/page/multimedia-playlists" class="fav-admin-subnav-link">🎼 Playlists</a>
        <a href="/admin/page/multimedia-genres" class="fav-admin-subnav-link">🏷️ Genres</a>
        <a href="/admin/page/multimedia-artists" class="fav-admin-subnav-link">🎤 Artists</a>
        <a href="/admin/page/multimedia-albums" class="fav-admin-subnav-link">💿 Albums</a>
        <a href="/admin/page/multimedia-sources" class="fav-admin-subnav-link">🎛️ Sources</a>
        <a href="/admin/page/multimedia-subtitles" class="fav-admin-subnav-link">💬 Subtitles</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <!-- Filter by Series -->
    <div style="margin-bottom: 20px; background: #fff; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 6px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <form method="GET" action="/admin/page/multimedia-seasons" style="display:flex; align-items:center; gap:8px;">
            <label style="font-weight: 600; margin-right: 4px;">Select Series:</label>
            <select name="series_id" class="fav-form-control" style="width: auto; display: inline-block;" onchange="this.form.submit()">
                <?php foreach ($seriesList as $s): ?>
                    <option value="<?php echo $s->id; ?>" <?php echo ($s->id == $selectedSeriesId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($selectedSeriesId > 0): ?>
            <div>
                <a href="/admin/page/multimedia-series?edit=<?php echo $selectedSeriesId; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size:12px; padding:5px 12px;">Edit Selected Series</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Season Form -->
    <div class="fav-admin-stat-card" style="margin-bottom: 24px;">
        <h3 style="margin-top:0; font-size:16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
            <?php echo $isEditing ? 'Edit Season: ' . htmlspecialchars($editSeason->title, ENT_QUOTES, 'UTF-8') : '+ Add Season to Series'; ?>
        </h3>
        <form method="POST" action="/admin/page/multimedia-seasons">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
            <input type="hidden" name="id" value="<?php echo $editSeason->id ?? 0; ?>">
            <input type="hidden" name="series_id" value="<?php echo $selectedSeriesId; ?>">

            <div class="fav-form-row">
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Season Number *</label>
                        <input type="number" name="season_number" class="fav-form-control" value="<?php echo $editSeason->season_number ?? (count($seasons) + 1); ?>" required min="1">
                        <span class="fav-form-hint">Numeric sequence (e.g. 1, 2, 3)</span>
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Season Title</label>
                        <input type="text" name="title" class="fav-form-control" value="<?php echo htmlspecialchars($editSeason->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Season 1">
                    </div>
                </div>
                <div class="fav-form-col">
                    <div class="fav-form-group">
                        <label class="fav-form-label">Release Date</label>
                        <input type="date" name="release_date" class="fav-form-control" value="<?php echo $editSeason->release_date ?? ''; ?>">
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="fav-admin-btn"><?php echo $isEditing ? 'Save Changes' : 'Create Season'; ?></button>
                <?php if ($isEditing): ?>
                    <a href="/admin/page/multimedia-seasons?series_id=<?php echo $selectedSeriesId; ?>" class="fav-admin-btn fav-admin-btn-secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Seasons List -->
    <table class="fav-admin-table">
        <thead>
            <tr>
                <th>Season #</th>
                <th>Title</th>
                <th>Episodes</th>
                <th>Release Date</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($seasons)): ?>
                <tr><td colspan="5" style="text-align:center; color:#94a3b8;">No seasons yet for this series.</td></tr>
            <?php else: foreach ($seasons as $s): ?>
                <tr>
                    <td><strong>Season <?php echo $s->season_number; ?></strong></td>
                    <td><?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <a href="/admin/page/multimedia-episodes?season_id=<?php echo $s->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;">
                            <?php echo count($s->getEpisodes()); ?> Episodes &rarr;
                        </a>
                        <a href="/admin/page/multimedia-episodes?season_id=<?php echo $s->id; ?>&new=1" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 6px; font-size: 11px; margin-left:4px;">
                            + Add Ep
                        </a>
                    </td>
                    <td><?php echo $s->release_date ?: '—'; ?></td>
                    <td style="text-align: right; white-space: nowrap;">
                        <a href="/admin/page/multimedia-seasons?series_id=<?php echo $selectedSeriesId; ?>&edit=<?php echo $s->id; ?>" class="fav-admin-btn" style="padding: 2px 8px; font-size: 11px;">Edit</a>
                        <form method="POST" action="/admin/page/multimedia-seasons" style="display:inline;" onsubmit="return confirm('Delete season?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $s->id; ?>">
                            <input type="hidden" name="series_id" value="<?php echo $selectedSeriesId; ?>">
                            <button type="submit" class="fav-admin-btn fav-admin-btn-danger" style="padding: 2px 8px; font-size: 11px;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script src="/plugins/favorite-multimedia/assets/js/multimedia-admin.js"></script>


