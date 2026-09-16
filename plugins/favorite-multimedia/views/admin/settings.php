<?php
/**
 * Favorite Multimedia — Admin Settings View
 */
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">⚙️ Multimedia Global Settings</h2>
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
        <a href="/admin/page/multimedia-analytics" class="fav-admin-subnav-link">📊 Analytics</a>
        <a href="/admin/page/multimedia-settings" class="fav-admin-subnav-link active">⚙️ Settings</a>
    </div>

    <div class="fav-admin-stat-card" style="max-width: 680px;">
        <form method="POST" action="/admin/page/multimedia-settings">
            <?php echo csrf_field(); ?>

            <div class="fav-form-group">
                <label class="fav-form-label">Global Downloads Feature *</label>
                <select name="enable_downloads" class="fav-form-control">
                    <option value="yes" <?php echo ($enableDownloads === 'yes') ? 'selected' : ''; ?>>Enabled (Allow users to download when content policy permits)</option>
                    <option value="no" <?php echo ($enableDownloads === 'no') ? 'selected' : ''; ?>>Disabled (Globally disable media download button site-wide)</option>
                </select>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    When set to Disabled, all download routes and player download buttons are denied regardless of content permissions.
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Default Video Preferred Resolution</label>
                <select name="default_video_resolution" class="fav-form-control">
                    <option value="1080p" <?php echo ($resolution === '1080p') ? 'selected' : ''; ?>>1080p Full HD</option>
                    <option value="720p" <?php echo ($resolution === '720p') ? 'selected' : ''; ?>>720p HD</option>
                    <option value="480p" <?php echo ($resolution === '480p') ? 'selected' : ''; ?>>480p SD</option>
                    <option value="4k" <?php echo ($resolution === '4k') ? 'selected' : ''; ?>>4K Ultra HD</option>
                </select>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Auto Next Playback for Episodes &amp; Playlists *</label>
                <select name="auto_play_next" class="fav-form-control">
                    <option value="yes" <?php echo ($autoPlayNext === 'yes') ? 'selected' : ''; ?>>Enabled (Prompt "Up Next" preview and countdown when media finishes)</option>
                    <option value="no" <?php echo ($autoPlayNext === 'no') ? 'selected' : ''; ?>>Disabled (Do not auto-advance to next item)</option>
                </select>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    When enabled, player displays an "Up Next" card with preview, title, and countdown timer upon playback completion.
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Auto Next Countdown Duration (Seconds)</label>
                <input type="number" name="auto_next_countdown" value="<?php echo (int)($autoNextCountdown ?? 5); ?>" min="3" max="30" class="fav-form-control" style="max-width: 140px;">
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Number of seconds before auto-loading the next episode or playlist item (default: 5 seconds).
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Player Accent Brand Color</label>
                <input type="color" name="player_theme_color" value="<?php echo htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8'); ?>" style="height: 38px; width: 80px; padding: 2px; border: 1px solid #cbd5e1; border-radius: 4px; cursor: pointer;">
                <span style="font-size: 13px; color: #64748b; margin-left: 8px;">Default: #2563EB (Favorite Blue)</span>
            </div>

            <hr style="border:0; border-top:1px solid #e2e8f0; margin: 24px 0;">
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; color: #0f172a;">🎵 &amp; 🎬 Media Playback, Volume &amp; Background Mode</h3>

            <div class="fav-form-group">
                <label class="fav-form-label">Default First-Play Volume (Percentage)</label>
                <input type="number" name="default_media_volume" value="<?php echo (int)($defaultMediaVolume ?? 25); ?>" min="5" max="50" class="fav-form-control" style="max-width: 140px;">
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Initial volume percentage (5% to 50%) for users without saved volume history (default: 25%).
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Remember User Volume *</label>
                <select name="remember_media_volume" class="fav-form-control">
                    <option value="yes" <?php echo (($rememberMediaVolume ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Enabled (Persist and unify volume across audio and video sessions)</option>
                    <option value="no" <?php echo (($rememberMediaVolume ?? '') === 'no') ? 'selected' : ''; ?>>Disabled (Always reset to default first-play volume)</option>
                </select>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    When enabled, volume adjustments on any player (Audio or Video) are remembered in browser storage and restored across reloads, auto-next, and source failover.
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Picture-in-Picture (PiP) Floating Video *</label>
                <select name="enable_pip" class="fav-form-control">
                    <option value="yes" <?php echo (($enablePip ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Enabled (Allow users to detach video into a floating PiP window)</option>
                    <option value="no" <?php echo (($enablePip ?? '') === 'no') ? 'selected' : ''; ?>>Disabled (Hide Picture-in-Picture button)</option>
                </select>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    When supported by the browser, displays a dedicated PiP button on native and HLS HTML5 video players.
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Legitimate Background Audio Mode *</label>
                <select name="enable_background_audio" class="fav-form-control">
                    <option value="yes" <?php echo (($enableBackgroundAudio ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Enabled (Allow dual-mode content to switch seamlessly to background audio)</option>
                    <option value="no" <?php echo (($enableBackgroundAudio ?? '') === 'no') ? 'selected' : ''; ?>>Disabled (Disable background audio mode toggle)</option>
                </select>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Permits switching playback to the persistent audio player when configured audio streams exist. Strictly excludes third-party scraping or unauthorized ripping.
                </small>
            </div>

            <hr style="border:0; border-top:1px solid #e2e8f0; margin: 24px 0;">
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; color: #0f172a;">🛡️ External Embed Player &amp; Sandbox Security</h3>

            <div class="fav-form-group">
                <label class="fav-form-label">Embed Sandbox Policy *</label>
                <select name="embed_sandbox_mode" class="fav-form-control">
                    <option value="compatible" <?php echo (($embedSandboxMode ?? 'compatible') === 'compatible') ? 'selected' : ''; ?>>Compatible (Recommended: allows scripts, forms, same-origin, popups, and presentation for trusted providers)</option>
                    <option value="off-for-trusted-only" <?php echo (($embedSandboxMode ?? '') === 'off-for-trusted-only') ? 'selected' : ''; ?>>Omit Sandbox for Trusted Providers (Maximum compatibility for external ad-backed players; untrusted remains sandboxed)</option>
                    <option value="strict" <?php echo (($embedSandboxMode ?? '') === 'strict') ? 'selected' : ''; ?>>Strict Sandbox (High isolation: blocks popups and form actions; external players may show AdBlock/Sandbox warning)</option>
                </select>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Controls the `sandbox` iframe attribute applied to external embed players. Untrusted domains are always locked to strict sandbox isolation.
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Trusted External Embed Domains (One per line)</label>
                <textarea name="trusted_embed_domains" class="fav-form-control" rows="4" placeholder="youtube.com&#10;vimeo.com&#10;dailymotion.com&#10;streamtape.com" style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($trustedEmbedDomains ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Domains listed here receive the selected trusted sandbox policy. Direct IP addresses and localhost are automatically rejected for security.
                </small>
            </div>

            <hr style="border:0; border-top:1px solid #e2e8f0; margin: 24px 0;">
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; color: #0f172a;">🧭 Content Discovery &amp; Trending Settings</h3>

            <div class="fav-form-group">
                <label class="fav-form-label">Content Discovery Engine *</label>
                <select name="enable_discovery" class="fav-form-control">
                    <option value="yes" <?php echo ($enableDiscovery === 'yes') ? 'selected' : ''; ?>>Enabled (Show related content rails, personalized feeds &amp; discovery)</option>
                    <option value="no" <?php echo ($enableDiscovery === 'no') ? 'selected' : ''; ?>>Disabled (Hide all discovery and recommendation rails)</option>
                </select>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Trending Now Feed *</label>
                <select name="enable_trending" class="fav-form-control">
                    <option value="yes" <?php echo ($enableTrending === 'yes') ? 'selected' : ''; ?>>Enabled (Calculate and display trending content based on recent engagement)</option>
                    <option value="no" <?php echo ($enableTrending === 'no') ? 'selected' : ''; ?>>Disabled (Fall back to all-time popular ranking)</option>
                </select>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Trending Window Duration (Days)</label>
                <input type="number" name="trending_window_days" value="<?php echo (int)($trendingWindowDays ?? 7); ?>" min="1" max="90" class="fav-form-control" style="max-width: 140px;">
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Rolling number of days considered for trending calculations (default: 7 days).
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Maximum Discovery Items Per Rail</label>
                <input type="number" name="max_discovery_items" value="<?php echo (int)($maxDiscoveryItems ?? 10); ?>" min="4" max="30" class="fav-form-control" style="max-width: 140px;">
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Maximum number of candidate cards shown in horizontal discovery rails (default: 10).
                </small>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">User Review Moderation Policy</label>
                <select name="review_moderation_mode" class="fav-form-control" style="max-width: 250px;">
                    <option value="auto_approve" <?php echo ($reviewModerationMode ?? 'auto_approve') === 'auto_approve' ? 'selected' : ''; ?>>Auto-Approve (Immediate publication)</option>
                    <option value="require_approval" <?php echo ($reviewModerationMode ?? '') === 'require_approval' ? 'selected' : ''; ?>>Require Moderation (Hold in queue)</option>
                </select>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Choose whether user-written reviews are immediately visible or held in the moderation queue.
                </small>
            </div>

            <hr style="border:0; border-top:1px solid #e2e8f0; margin: 24px 0;">
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; color: #0f172a;">🌐 Trusted External Embed Domains</h3>
            <div class="fav-form-group">
                <label class="fav-form-label">Allowed External Player Domains (One per line)</label>
                <textarea name="trusted_embed_domains" class="fav-form-control" rows="4" placeholder="player.vimeo.com&#10;youtube.com&#10;embed.example.com"><?php echo htmlspecialchars($trustedEmbedDomains ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    Enter trusted third-party player domains (e.g. <code>player.twitch.tv</code>, <code>dailymotion.com</code>). Embed URLs from these domains are allowed for safe sandboxed iframe playback. (YouTube and Vimeo are always trusted by default).
                </small>
            </div>

            <hr style="border:0; border-top:1px solid #e2e8f0; margin: 24px 0;">
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; color: #0f172a;">Community Submissions &amp; Upload Security Policy</h3>

            <div class="fav-form-group">
                <label class="fav-form-label">User Submissions Master Switch *</label>
                <select name="user_submissions_enabled" class="fav-form-control">
                    <option value="yes" <?php echo (($userSubmissionsEnabled ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Enabled (Allow non-admin users with appropriate roles to submit media)</option>
                    <option value="no" <?php echo (($userSubmissionsEnabled ?? '') === 'no') ? 'selected' : ''; ?>>Disabled (Globally lock media submissions to Admins &amp; Moderators only)</option>
                </select>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Subscriber Role Media Submissions *</label>
                <select name="allow_subscriber_submissions" class="fav-form-control">
                    <option value="no" <?php echo (($allowSubscriberSubmissions ?? 'no') === 'no') ? 'selected' : ''; ?>>Disabled (Subscribers cannot submit content - Recommended)</option>
                    <option value="yes" <?php echo (($allowSubscriberSubmissions ?? '') === 'yes') ? 'selected' : ''; ?>>Enabled (Subscribers can submit content to pending review)</option>
                </select>
                <small style="color:#64748b; font-size:12px; display:block; margin-top:4px;">
                    When enabled, registered subscribers can submit media which is strictly held for moderator approval.
                </small>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="fav-form-group">
                    <label class="fav-form-label">Author Submissions</label>
                    <select name="allow_author_submissions" class="fav-form-control">
                        <option value="yes" <?php echo (($allowAuthorSubmissions ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Enabled</option>
                        <option value="no" <?php echo (($allowAuthorSubmissions ?? '') === 'no') ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                </div>
                <div class="fav-form-group">
                    <label class="fav-form-label">Contributor Submissions</label>
                    <select name="allow_contributor_submissions" class="fav-form-control">
                        <option value="yes" <?php echo (($allowContributorSubmissions ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Enabled</option>
                        <option value="no" <?php echo (($allowContributorSubmissions ?? '') === 'no') ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                </div>
            </div>

            <div class="fav-form-group">
                <label class="fav-form-label">Require Moderator Approval for Non-Admins *</label>
                <select name="require_moderation" class="fav-form-control">
                    <option value="yes" <?php echo (($requireModeration ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Strict (Submissions forced to Pending Review until moderator approves)</option>
                    <option value="no" <?php echo (($requireModeration ?? '') === 'no') ? 'selected' : ''; ?>>Trust Authors (Direct publishing allowed for Author role)</option>
                </select>
            </div>

            <div style="margin: 16px 0; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                <label class="fav-form-label" style="margin-bottom: 8px;">Allowed Content Types for Non-Admin Creators:</label>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; font-size: 13px;">
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="allow_user_movie_upload" value="yes" <?php echo (($allowUserMovieUpload ?? 'yes') === 'yes') ? 'checked' : ''; ?>> Movies
                    </label>
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="allow_user_series_upload" value="yes" <?php echo (($allowUserSeriesUpload ?? 'yes') === 'yes') ? 'checked' : ''; ?>> Web Series
                    </label>
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="allow_user_episode_upload" value="yes" <?php echo (($allowUserEpisodeUpload ?? 'yes') === 'yes') ? 'checked' : ''; ?>> Episodes
                    </label>
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="allow_user_song_upload" value="yes" <?php echo (($allowUserSongUpload ?? 'yes') === 'yes') ? 'checked' : ''; ?>> Songs
                    </label>
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="allow_user_album_upload" value="yes" <?php echo (($allowUserAlbumUpload ?? 'yes') === 'yes') ? 'checked' : ''; ?>> Albums
                    </label>
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="allow_user_playlist_creation" value="yes" <?php echo (($allowUserPlaylistCreation ?? 'yes') === 'yes') ? 'checked' : ''; ?>> Playlists
                    </label>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="fav-form-group">
                    <label class="fav-form-label">Max Video Upload (MB)</label>
                    <input type="number" name="max_video_upload_mb" value="<?php echo (int)($maxVideoUploadMb ?? 500); ?>" min="5" max="5000" class="fav-form-control">
                </div>
                <div class="fav-form-group">
                    <label class="fav-form-label">Max Audio Upload (MB)</label>
                    <input type="number" name="max_audio_upload_mb" value="<?php echo (int)($maxAudioUploadMb ?? 100); ?>" min="1" max="500" class="fav-form-control">
                </div>
                <div class="fav-form-group">
                    <label class="fav-form-label">Max Artwork Image (MB)</label>
                    <input type="number" name="max_image_upload_mb" value="<?php echo (int)($maxImageUploadMb ?? 10); ?>" min="1" max="50" class="fav-form-control">
                </div>
                <div class="fav-form-group">
                    <label class="fav-form-label">Max Subtitle File (MB)</label>
                    <input type="number" name="max_subtitle_upload_mb" value="<?php echo (int)($maxSubtitleUploadMb ?? 5); ?>" min="1" max="20" class="fav-form-control">
                </div>
            </div>

            <div style="margin-top: 24px;">
                <button type="submit" class="fav-admin-btn">Save Settings</button>
            </div>
        </form>
    </div>
</div>

