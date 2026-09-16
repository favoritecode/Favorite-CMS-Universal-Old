<?php
/**
 * Favorite Multimedia — Admin Series View
 */
$isEditing = ($editSeries !== null);
$selectedGenres = $isEditing ? array_column($editSeries->getGenres(), 'id') : [];
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">📺 Web Series Management</h2>
        <?php if ($isEditing): ?>
            <a href="/admin/page/multimedia-series" class="fav-admin-btn fav-admin-btn-secondary">&larr; Back to Series List</a>
        <?php endif; ?>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link">🏠 Dashboard</a>
        <a href="/admin/page/multimedia-movies" class="fav-admin-subnav-link">🎬 Movies</a>
        <a href="/admin/page/multimedia-series" class="fav-admin-subnav-link active">📺 Web Series</a>
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
        <div class="fav-admin-stat-card" style="margin-bottom: 28px;">
            <h3 style="margin-top:0; font-size:18px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <?php echo $isEditing ? 'Edit Series: ' . htmlspecialchars($editSeries->title, ENT_QUOTES, 'UTF-8') : 'Create New Web Series'; ?>
            </h3>

            <form method="POST" action="/admin/page/multimedia-series">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $isEditing ? 'edit' : 'create'; ?>">
                <input type="hidden" name="id" value="<?php echo $editSeries->id ?? 0; ?>">

                <!-- 1. Basic Information -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">📌 Series Information</h4>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Series Title *</label>
                                <input type="text" name="title" id="fav_series_title" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" required placeholder="e.g. Breaking Bad">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Slug</label>
                                <input type="text" name="slug" id="fav_series_slug" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="auto-generated from title">
                                <span class="fav-form-hint">URL path: /series/your-slug</span>
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-group">
                        <label class="fav-form-label">Synopsis</label>
                        <textarea name="description" class="fav-form-control" rows="4" placeholder="Overview of the series storyline..."><?php echo htmlspecialchars($editSeries->description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Release Year</label>
                                <input type="number" name="release_year" class="fav-form-control" value="<?php echo $editSeries->release_year ?? date('Y'); ?>">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Language</label>
                                <input type="text" name="language" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->language ?? 'English', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Country</label>
                                <input type="text" name="country" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->country ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Director</label>
                                <input type="text" name="director" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->director ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Cast Members</label>
                                <input type="text" name="cast" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->cast ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Actors, lead cast">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Trailer URL</label>
                                <input type="text" name="trailer_url" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->trailer_url ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="YouTube or video URL">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Artwork & Imagery -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">🎨 Artwork &amp; Imagery</h4>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Poster URL / Path</label>
                                <input type="text" name="poster" id="fav_series_poster" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->poster ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://... or /uploads/...">
                                <span class="fav-form-hint">2:3 vertical series cover poster</span>
                                <?php if (!empty($editSeries->poster)): ?>
                                    <img src="<?php echo htmlspecialchars($editSeries->poster, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="fav-img-preview" id="fav_series_poster_prev">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Backdrop URL</label>
                                <input type="text" name="backdrop" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->backdrop ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://... or /uploads/...">
                                <span class="fav-form-hint">16:9 widescreen series banner</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Access & Governance -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">🔐 Access Control &amp; Inheritance</h4>
                    <div class="fav-hierarchy-banner">
                        <strong>Hierarchy Inheritance Note:</strong> Child Seasons and Episodes inherit this Series access mode by default, unless an individual Episode sets an explicit override (e.g. Free Pilot episode).
                    </div>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Series Base Access Mode *</label>
                                <select name="access_mode" class="fav-form-control">
                                    <option value="public" <?php echo ($editSeries?->access_mode === 'public') ? 'selected' : ''; ?>>PUBLIC (Free for all episodes by default)</option>
                                    <option value="login" <?php echo ($editSeries?->access_mode === 'login') ? 'selected' : ''; ?>>LOGIN REQUIRED (Members only)</option>
                                    <option value="premium" <?php echo ($editSeries?->access_mode === 'premium') ? 'selected' : ''; ?>>PREMIUM (Favorite Digital entitlement required)</option>
                                </select>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Default Download Policy *</label>
                                <select name="download_policy" class="fav-form-control">
                                    <option value="inherit" <?php echo ($editSeries?->download_policy === 'inherit') ? 'selected' : ''; ?>>Inherit Global Setting</option>
                                    <option value="allow" <?php echo ($editSeries?->download_policy === 'allow') ? 'selected' : ''; ?>>Allow Downloads</option>
                                    <option value="deny" <?php echo ($editSeries?->download_policy === 'deny') ? 'selected' : ''; ?>>Deny Downloads</option>
                                </select>
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">Publication Status *</label>
                                <select name="status" id="fav_pub_status" class="fav-form-control" onchange="toggleSchedulingFields(this.value)">
                                    <option value="published" <?php echo ($editSeries?->status === 'published' || !$isEditing) ? 'selected' : ''; ?>>Published (Live)</option>
                                    <option value="scheduled" <?php echo ($editSeries?->status === 'scheduled') ? 'selected' : ''; ?>>Scheduled for Release</option>
                                    <option value="draft" <?php echo ($editSeries?->status === 'draft') ? 'selected' : ''; ?>>Draft (Hidden)</option>
                                    <option value="unpublished" <?php echo ($editSeries?->status === 'unpublished') ? 'selected' : ''; ?>>Unpublished (Archived)</option>
                                </select>
                                <span class="fav-form-hint">Drafts and scheduled items are hidden from public catalog and search.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Scheduling Row -->
                    <?php 
                    $tz = \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::getAppTimezone();
                    $pubAtLocal = !empty($editSeries?->publish_at) ? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::utcToLocal($editSeries->publish_at, 'Y-m-d\TH:i') : '';
                    $unpubAtLocal = !empty($editSeries?->unpublish_at) ? \FavoriteCMS\Multimedia\Services\MultimediaReleaseService::utcToLocal($editSeries->unpublish_at, 'Y-m-d\TH:i') : '';
                    $showSched = ($editSeries?->status === 'scheduled');
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
                            <input type="checkbox" name="featured" value="1" <?php echo ($editSeries?->featured) ? 'checked' : ''; ?>>
                            ⭐ Feature this series on homepage / hero banner
                        </label>
                    </div>
                </div>

                <!-- 4. Classification -->
                <div class="fav-form-section">
                    <h4 class="fav-form-section-title">🏷️ Genres</h4>
                    <div style="display: flex; gap: 14px; flex-wrap: wrap; padding: 4px 0;">
                        <?php if (empty($genres)): ?>
                            <span style="color:#94a3b8; font-size:13px;">No genres created yet.</span>
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
                    <h4 class="fav-form-section-title">🔍 SEO Metadata</h4>
                    <div class="fav-form-row">
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">SEO Title (Optional)</label>
                                <input type="text" name="seo_title" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->seo_title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Defaults to Series Title">
                            </div>
                        </div>
                        <div class="fav-form-col">
                            <div class="fav-form-group">
                                <label class="fav-form-label">SEO Description (Optional)</label>
                                <input type="text" name="seo_description" class="fav-form-control" value="<?php echo htmlspecialchars($editSeries->seo_description ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search engine preview text">
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="submit" class="fav-admin-btn" style="padding: 10px 24px; font-size: 14px;"><?php echo $isEditing ? 'Save Series' : 'Create Series'; ?></button>
                    <?php if ($isEditing): ?>
                        <a href="/admin/page/multimedia-series" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">Cancel</a>
                        <a href="/series/<?php echo htmlspecialchars($editSeries->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 10px 18px; font-size: 14px;">Preview Series &rarr;</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($isEditing): ?>
                <!-- Seasons & Episodes Hierarchy Overview -->
                <hr style="margin: 28px 0; border: none; border-top: 1px solid #e2e8f0;">
                <div class="fav-form-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h4 style="margin:0; font-size:15px; font-weight:700;">📼 Seasons &amp; Episodes Hierarchy</h4>
                        <a href="/admin/page/multimedia-seasons?series_id=<?php echo $editSeries->id; ?>" class="fav-admin-btn" style="font-size:12px; padding:5px 12px;">+ Add Season</a>
                    </div>
                    <?php $seriesSeasons = $editSeries->getSeasons(); ?>
                    <?php if (empty($seriesSeasons)): ?>
                        <div class="fav-empty-state" style="padding:20px;">
                            <p style="margin:0;">No seasons added yet for this series. Click "+ Add Season" to get started.</p>
                        </div>
                    <?php else: foreach ($seriesSeasons as $season): ?>
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:12px 16px; margin-bottom:12px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; padding-bottom:8px; margin-bottom:8px;">
                                <div>
                                    <strong style="font-size:14px;">Season <?php echo $season->season_number; ?>: <?php echo htmlspecialchars($season->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span style="color:#64748b; font-size:12px; margin-left:8px;"><?php echo count($season->getEpisodes()); ?> Episodes</span>
                                </div>
                                <div style="display:flex; gap:6px;">
                                    <a href="/admin/page/multimedia-episodes?season_id=<?php echo $season->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size:11px; padding:3px 8px;">+ Add / Manage Episodes</a>
                                    <a href="/admin/page/multimedia-seasons?series_id=<?php echo $editSeries->id; ?>&edit=<?php echo $season->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="font-size:11px; padding:3px 8px;">Edit Season</a>
                                </div>
                            </div>
                            <?php $seasonEpisodes = $season->getEpisodes(); ?>
                            <?php if (empty($seasonEpisodes)): ?>
                                <p style="color:#94a3b8; font-size:12px; margin:4px 0;">No episodes yet in Season <?php echo $season->season_number; ?>.</p>
                            <?php else: ?>
                                <div style="display:flex; flex-direction:column; gap:6px;">
                                    <?php foreach ($seasonEpisodes as $ep): ?>
                                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:13px; padding:4px 8px; background:#f8fafc; border-radius:4px;">
                                            <div>
                                                <strong>E<?php echo $ep->episode_number; ?>:</strong> <?php echo htmlspecialchars($ep->title, ENT_QUOTES, 'UTF-8'); ?>
                                                <span class="fav-badge fav-badge-<?php echo strtolower($ep->getResolvedAccessMode()); ?>" style="font-size:10px; margin-left:8px;">
                                                    <?php echo strtoupper($ep->access_mode === 'inherit' ? 'Inherit (' . $ep->getResolvedAccessMode() . ')' : $ep->access_mode); ?>
                                                </span>
                                            </div>
                                            <div style="display:flex; gap:6px;">
                                                <a href="/admin/page/multimedia-sources?content_type=episode&content_id=<?php echo $ep->id; ?>" style="font-size:11px; color:#2563eb; text-decoration:none;">Sources (<?php echo count($ep->getSources(false)); ?>)</a>
                                                <span style="color:#cbd5e1;">|</span>
                                                <a href="/admin/page/multimedia-episodes?season_id=<?php echo $season->id; ?>&edit=<?php echo $ep->id; ?>" style="font-size:11px; color:#2563eb; text-decoration:none;">Edit</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:16px;">All Series</h3>
        <?php if (!$isEditing && !isset($_GET['new'])): ?>
            <a href="/admin/page/multimedia-series?new=1" class="fav-admin-btn">+ Add New Series</a>
        <?php endif; ?>
    </div>

    <table class="fav-admin-table">
        <thead>
            <tr>
                <th style="width: 50px;">Poster</th>
                <th>Title</th>
                <th>Year</th>
                <th>Access</th>
                <th>Seasons</th>
                <th>Status</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="7" style="padding: 0;">
                        <div class="fav-empty-card">
                            <div class="fav-empty-icon">📺</div>
                            <h3 class="fav-empty-title">Add Your First Web Series</h3>
                            <p class="fav-empty-desc">Create serialized multi-season shows and attach seasons and episodes with video streams.</p>
                            <a href="/admin/page/multimedia-series?new=1" class="fav-admin-btn" style="padding: 9px 20px; font-size: 14px;">+ Add First Series</a>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?php if ($item->poster): ?>
                            <img src="<?php echo htmlspecialchars($item->poster, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 40px; height: 55px; object-fit: cover; border-radius: 4px;">
                        <?php else: ?>
                            <div style="width: 40px; height: 55px; background: #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #64748b;">No Img</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div style="font-size: 11px; color: #64748b;">/series/<?php echo htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8'); ?></div>
                    </td>
                    <td><?php echo $item->release_year ?: '—'; ?></td>
                    <td><span class="fav-badge fav-badge-<?php echo strtolower($item->access_mode ?? 'public'); ?>"><?php echo strtoupper($item->access_mode ?? 'public'); ?></span></td>
                    <td><a href="/admin/page/multimedia-seasons?series_id=<?php echo $item->id; ?>" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 2px 8px; font-size: 11px;"><?php echo count($item->getSeasons()); ?> Seasons &rarr;</a></td>
                    <td><?php echo ucfirst($item->status ?? 'published'); ?></td>
                    <td style="text-align: right; white-space: nowrap;">
                        <a href="/series/<?php echo htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="fav-admin-btn fav-admin-btn-secondary" style="padding: 4px 8px; font-size: 11px;">View</a>
                        <a href="/admin/page/multimedia-series?edit=<?php echo $item->id; ?>" class="fav-admin-btn" style="padding: 4px 8px; font-size: 11px;">Edit</a>
                        <form method="POST" action="/admin/page/multimedia-series" style="display:inline;" onsubmit="return confirm('Delete this series?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $item->id; ?>">
                            <button type="submit" class="fav-admin-btn fav-admin-btn-danger" style="padding: 4px 8px; font-size: 11px;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const titleInput = document.getElementById('fav_series_title');
    const slugInput = document.getElementById('fav_series_slug');
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

    const posterInput = document.getElementById('fav_series_poster');
    if (posterInput) {
        posterInput.addEventListener('change', function() {
            let prev = document.getElementById('fav_series_poster_prev');
            if (this.value) {
                if (!prev) {
                    prev = document.createElement('img');
                    prev.id = 'fav_series_poster_prev';
                    prev.className = 'fav-img-preview';
                    posterInput.parentNode.appendChild(prev);
                }
                prev.src = this.value;
                prev.style.display = 'block';
            } else if (prev) {
                prev.style.display = 'none';
            }
        });
function toggleSchedulingFields(status) {
    const row = document.getElementById('fav_scheduling_row');
    if (row) {
        row.style.display = (status === 'scheduled') ? 'flex' : 'none';
    }
}
</script>
<script src="/plugins/favorite-multimedia/assets/js/multimedia-admin.js"></script>


