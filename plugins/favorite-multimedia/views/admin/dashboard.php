<?php
/**
 * Favorite Multimedia — Admin Dashboard View
 */
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🎬 Multimedia Management Hub</h2>
        <div>
            <a href="/admin/page/multimedia-movies?new=1" class="fav-admin-btn">+ Add Movie</a>
            <a href="/admin/page/multimedia-series?new=1" class="fav-admin-btn">+ Add Series</a>
            <a href="/admin/page/multimedia-songs?new=1" class="fav-admin-btn">+ Add Song</a>
            <a href="/admin/page/multimedia-playlists?new=1" class="fav-admin-btn">+ Add Playlist</a>
        </div>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="fav-admin-subnav">
        <a href="/admin/page/multimedia" class="fav-admin-subnav-link active">🏠 Dashboard</a>
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
        <a href="/admin/page/multimedia-access" class="fav-admin-subnav-link">🔒 Access Control</a>
        <a href="/admin/page/multimedia-moderation" class="fav-admin-subnav-link">🛡️ Moderation</a>
        <a href="/admin/page/multimedia-releases" class="fav-admin-subnav-link">📅 Releases</a>
        <a href="/admin/page/multimedia-processing" class="fav-admin-subnav-link">⚙️ Processing</a>
        <a href="/admin/page/multimedia-storage" class="fav-admin-subnav-link">☁️ Storage</a>
        <a href="/admin/page/multimedia-localizations" class="fav-admin-subnav-link">🌐 Localizations</a>
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link">⚙️ Settings</a>
    </div>

    <!-- Stats Grid -->
    <div class="fav-admin-stats-grid">
        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Total Movies</p>
            <p class="fav-admin-stat-number"><?php echo number_format($stats['total_movies'] ?? 0); ?></p>
        </div>
        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Web Series</p>
            <p class="fav-admin-stat-number"><?php echo number_format($stats['total_series'] ?? 0); ?></p>
        </div>
        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Total Episodes</p>
            <p class="fav-admin-stat-number"><?php echo number_format($stats['total_episodes'] ?? 0); ?></p>
        </div>
        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Songs &amp; Audio</p>
            <p class="fav-admin-stat-number"><?php echo number_format($stats['total_songs'] ?? 0); ?></p>
        </div>
        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Playlists</p>
            <p class="fav-admin-stat-number"><?php echo number_format($stats['total_playlists'] ?? 0); ?></p>
        </div>
        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Media Plays</p>
            <p class="fav-admin-stat-number" style="color: #10b981;"><?php echo number_format($stats['total_plays'] ?? 0); ?></p>
        </div>
        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Media Downloads</p>
            <p class="fav-admin-stat-number" style="color: #0ea5e9;"><?php echo number_format($stats['total_downloads'] ?? 0); ?></p>
        </div>
    </div>

    <!-- Recent Content 2x2 Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 20px; margin-top: 20px;">
        <!-- Recent Movies -->
        <div class="fav-admin-stat-card" style="padding: 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 style="font-size: 15px; margin: 0; font-weight:700;">🎬 Recent Movies</h3>
                <a href="/admin/page/multimedia-movies" style="font-size:12px; color:#2563eb; text-decoration:none;">View All &rarr;</a>
            </div>
            <table class="fav-admin-table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Year</th>
                        <th>Access</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentMovies)): ?>
                        <tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 16px;">No movies yet.</td></tr>
                    <?php else: foreach ($recentMovies as $m): ?>
                        <tr>
                            <td><strong><a href="/admin/page/multimedia-movies?edit=<?php echo $m->id; ?>"><?php echo htmlspecialchars($m->title, ENT_QUOTES, 'UTF-8'); ?></a></strong></td>
                            <td><?php echo $m->release_year ?: '—'; ?></td>
                            <td><span class="fav-badge fav-badge-<?php echo strtolower($m->access_mode ?? 'public'); ?>"><?php echo strtoupper($m->access_mode ?? 'public'); ?></span></td>
                            <td><?php echo htmlspecialchars($m->status ?? 'published', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Series -->
        <div class="fav-admin-stat-card" style="padding: 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 style="font-size: 15px; margin: 0; font-weight:700;">📺 Recent Web Series</h3>
                <a href="/admin/page/multimedia-series" style="font-size:12px; color:#2563eb; text-decoration:none;">View All &rarr;</a>
            </div>
            <table class="fav-admin-table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Seasons</th>
                        <th>Access</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentSeries)): ?>
                        <tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 16px;">No series yet.</td></tr>
                    <?php else: foreach ($recentSeries as $ser): ?>
                        <tr>
                            <td><strong><a href="/admin/page/multimedia-series?edit=<?php echo $ser->id; ?>"><?php echo htmlspecialchars($ser->title, ENT_QUOTES, 'UTF-8'); ?></a></strong></td>
                            <td><?php echo count($ser->getSeasons()); ?> Seasons</td>
                            <td><span class="fav-badge fav-badge-<?php echo strtolower($ser->access_mode ?? 'public'); ?>"><?php echo strtoupper($ser->access_mode ?? 'public'); ?></span></td>
                            <td><?php echo htmlspecialchars($ser->status ?? 'published', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Songs -->
        <div class="fav-admin-stat-card" style="padding: 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 style="font-size: 15px; margin: 0; font-weight:700;">🎵 Recent Songs</h3>
                <a href="/admin/page/multimedia-songs" style="font-size:12px; color:#2563eb; text-decoration:none;">View All &rarr;</a>
            </div>
            <table class="fav-admin-table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Artist</th>
                        <th>Access</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentSongs)): ?>
                        <tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 16px;">No songs yet.</td></tr>
                    <?php else: foreach ($recentSongs as $s): ?>
                        <tr>
                            <td><strong><a href="/admin/page/multimedia-songs?edit=<?php echo $s->id; ?>"><?php echo htmlspecialchars($s->title, ENT_QUOTES, 'UTF-8'); ?></a></strong></td>
                            <td><?php echo htmlspecialchars($s->getArtist()?->name ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="fav-badge fav-badge-<?php echo strtolower($s->access_mode ?? 'public'); ?>"><?php echo strtoupper($s->access_mode ?? 'public'); ?></span></td>
                            <td><?php echo $s->getDurationFormatted() ?: '—'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Playlists -->
        <div class="fav-admin-stat-card" style="padding: 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 style="font-size: 15px; margin: 0; font-weight:700;">🎼 Recent Playlists</h3>
                <a href="/admin/page/multimedia-playlists" style="font-size:12px; color:#2563eb; text-decoration:none;">View All &rarr;</a>
            </div>
            <table class="fav-admin-table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Tracks</th>
                        <th>Access</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentPlaylists)): ?>
                        <tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 16px;">No playlists yet.</td></tr>
                    <?php else: foreach ($recentPlaylists as $pl): ?>
                        <tr>
                            <td><strong><a href="/admin/page/multimedia-playlists?edit=<?php echo $pl->id; ?>"><?php echo htmlspecialchars($pl->title, ENT_QUOTES, 'UTF-8'); ?></a></strong></td>
                            <td><?php echo count($pl->getSongs()); ?> Tracks</td>
                            <td><span class="fav-badge fav-badge-<?php echo strtolower($pl->access_mode ?? 'public'); ?>"><?php echo strtoupper($pl->access_mode ?? 'public'); ?></span></td>
                            <td><?php echo htmlspecialchars($pl->status ?? 'published', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

