# Favorite Multimedia — Production-Ready Media Plugin for Favorite CMS

**Favorite Multimedia** is a comprehensive, production-grade multimedia management, streaming, and catalog system designed natively for the Favorite CMS ecosystem. It provides native support for Movies, Web Series (Seasons & Episodes), Songs, and continuous Audio Playlists.

---

## 1. Key Features

- **URL-First & Cloud Storage**: Movies, episodes, and songs support direct URLs, HLS streaming manifests (`.m3u8`), cloud object storage (S3-compatible / MinIO / Cloudflare R2 / AWS S3), third-party embeds (YouTube, Vimeo), or local uploads.
- **Centralized 3-Tier Access Control**: `PUBLIC`, `LOGIN`, `PREMIUM` enforced server-side across all frontend pages, player views, stream endpoints, subtitle tracks, and download routes.
- **Granular Access Inheritance**: Series access &rarr; Season &rarr; Episode with explicit episode-level overrides (e.g. Free teaser episode for a Premium series).
- **Separated Download Security Engine**: Viewing access does not automatically grant download permission. Downloads can be globally toggled or configured per-content and per-source (`inherit`, `allow`, `deny`).
- **HTTP 206 Partial Content Streaming**: True byte-range seeking for local media files without memory exhaustion.
- **Multi-Language Audio & Subtitles**: BCP 47 standard language support with native names (e.g. বাংলা, العربية, English), forced subtitles, SDH tracks, automatic SRT-to-WebVTT conversion, and deterministic audio track selection.
- **Full Localization**: Localized metadata (titles, descriptions) with deterministic base fallback and zero N+1 database queries.
- **Cloud & Object Storage Abstraction**: High-performance local disk storage and S3-compatible cloud storage with HMAC-SHA256 signed delivery tokens, expiration windows, and automatic orphan cleanup.
- **FFmpeg Media Processing**: Automated background transcode pipeline for HLS generation, MP4 conversions, and automatic thumbnail extraction, with clear capability detection and zero-crash fallback when FFmpeg is not installed.
- **Scheduled Publishing & Release Calendar**: Automated status transitions (`draft`, `scheduled`, `published`), interactive release calendar, and queue management.
- **Community & Moderation Queue**: 5-star ratings, user reviews, threaded comments, community reporting, and a dedicated admin moderation queue.
- **Personal Library & Progress Resuming**: Continue Watching shelf, Continue Listening, resume prompts ($\ge 90\%$ completion threshold), and "My List" bookmarking.
- **Passive Analytics & Privacy Engine**: Executive KPIs, 6-bucket drop-off distribution, next-episode continuation rate, discovery attribution, and secure CSV exporting with formula injection protection (CWE-1236) and UTF-8 BOM encoding for Bangla and Arabic compatibility.
- **Zero Core Modification**: Built 100% on Favorite CMS Universal's existing extension APIs (`AdminMenu`, `Router`, `Hook`, `Database::registerPrefixableTables()`, `Migrator`). Core files in `Favorite-CMS-Universal/app/` are never modified.

---

## 2. Requirements & Compatibility

- **PHP**: 8.1.0 or higher (Tested and compatible with PHP 8.1 through PHP 8.5)
- **Favorite CMS Universal**: v1.0.0 or higher
- **Database**: MySQL 5.7+ / MariaDB 10.3+ or SQLite 3.35+ (via `pdo_mysql` or `pdo_sqlite`)
- **Optional Tools**:
  - `ffmpeg` & `ffprobe`: Enables automated video transcoding, HLS packaging, and thumbnail extraction. If absent, the plugin operates smoothly with manual media sources and informs the administrator without fatal errors.
  - `favorite-digital`: Sole authoritative gatekeeper for **PREMIUM** subscriber content entitlement.
  - `favorite-pay`: Transactional payment gateway and checkout provider.

---

## 3. Installation & Directory Structure

Place the plugin in the Favorite CMS plugins directory:

```text
Favorite-CMS-Universal/plugins/favorite-multimedia/
├── autoload.php                   # PSR-4 Autoloader
├── plugin.json                    # Plugin manifest and table registrations
├── plugin.php                     # Entrypoint & bootstrap hook
├── README.md                      # Complete plugin documentation
├── assets/
│   ├── css/
│   │   ├── multimedia-player.css   # Player styles using #2563EB Favorite Blue tokens
│   │   ├── multimedia-frontend.css # Public catalog grid and card styles
│   │   └── multimedia-admin.css    # Admin forms, tables, and badge styles
│   └── js/
│       ├── multimedia-player.js    # Responsive HTML5 & HLS video player engine
│       ├── multimedia-audio.js     # Audio & interactive playlist controller
│       └── multimedia-admin.js     # Admin auto URL type detection helper
├── database/
│   └── migrations/
│       ├── 001_create_favorite_multimedia_tables.php     # Core schema migration
│       └── 002_create_multimedia_user_library_tables.php # User library & progress migration
├── src/
│   ├── Controllers/
│   │   ├── MediaPlaybackController.php    # Streaming, progress & library API handler
│   │   ├── MultimediaAdminController.php  # Admin panel CRUD dispatcher
│   │   └── MultimediaFrontendController.php # Catalog, library, history & my-list controller
│   ├── Integrations/
│   │   ├── FavoriteDigitalAdapter.php     # Entitlement pass adapter
│   │   └── FavoritePayAdapter.php         # Checkout intent adapter
│   ├── Models/
│   │   ├── Album.php, Artist.php, Episode.php, Genre.php, MediaSource.php,
│   │   ├── Movie.php, Playlist.php, PlaylistItem.php, Season.php,
│   │   ├── Series.php, Song.php, Subtitle.php, AnalyticsEvent.php,
│   │   └── PlaybackProgress.php, Favorite.php
│   ├── Permissions/
│   │   └── MultimediaPermission.php      # Permissions & capability mappings
│   ├── Services/
│   │   ├── MediaDeliveryService.php       # HTTP 206 streaming & attachment engine
│   │   ├── MediaSourceResolver.php        # URL parsing, detection & SSRF check
│   │   ├── MultimediaAccessService.php    # Centralized access & download service
│   │   ├── MultimediaDiscoveryService.php # Related content, trending, popular & personalized discovery
│   │   ├── PlaybackProgressService.php    # Progress tracking, resume & next episode logic
│   │   └── UserLibraryService.php         # Library compilation & favorites hydrator
│   └── FavoriteMultimediaPlugin.php       # Main service provider
└── views/
    ├── admin/                             # Admin dashboards, studios, and settings views
    └── frontend/                          # Catalog, discover, genre, artist, album, library, and player views
```

---

## 4. Activation & Lifecycle

### Activation
When activated in Favorite CMS:
1. `PluginManager::activatePlugin('favorite-multimedia')` automatically runs `001_create_favorite_multimedia_tables.php`.
2. Tables are registered with `Database::registerPrefixableTables()`.
3. Default permissions (`multimedia.view`, `multimedia.create`, etc.) are seeded and linked to the `admin` role.
4. Admin menus and frontend routes are registered.

### Deactivation
Deactivation safely unhooks runtime routes and menus. **User data, catalog items, and media records are never deleted on deactivation.**

---

## 5. Supported Media Types & URL Formats

> [!IMPORTANT]
> **Important Rule**: An arbitrary webpage URL is not guaranteed to be a playable media source. Entering a generic HTML website link into the source field will be classified as `Unknown Media Type`.

| Media Category | Supported Extensions & Formats | Notes |
|---|---|---|
| **Direct Video** | `.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v` | Played via HTML5 `<video>`, supports byte-range streaming and download. |
| **HLS Streams** | `.m3u8` manifests | Played natively (Safari/iOS) or via lightweight HLS.js. |
| **Direct Audio** | `.mp3`, `.m4a`, `.aac`, `.wav`, `.ogg`, `.flac`, `.opus` | Played via HTML5 `<audio>` and continuous playlist controller. |
| **Third-Party Embeds** | YouTube, Vimeo, Dailymotion, SoundCloud | Rendered within secure responsive embed iframes. |
| **Subtitles** | `.vtt` (WebVTT), `.srt` | Multi-language subtitle tracks. |

> [!NOTE]
> **Streaming Manifest Note**: M3U8 is a streaming manifest and is not automatically downloadable as a single MP4 file. The download button will only be rendered if a direct downloadable media file source is configured.

---

## 6. Access Modes & Download Behavior

Every media item enforces one of exactly three access modes:
1. **`PUBLIC`**: Free and unhindered access for all visitors.
2. **`LOGIN`**: Requires the user to be logged in to an active Favorite CMS user account.
3. **`PREMIUM`**: Requires an active entitlement pass verified via `FavoriteDigitalAdapter`.

### Download Permission Resolution
Download permission is resolved independently from viewing:
- **Global Setting**: `multimedia.enable_downloads` (`yes` / `no`).
- **Content Policy**: `download_policy` on Movie/Series/Episode/Song (`inherit`, `allow`, `deny`).
- **Source Policy**: `allow_download` on Media Source (`inherit`, `allow`, `deny`).
- A user must first pass **Viewing Access** before download permission is granted.

---

## 7. Personal Library, Playback Progress & History (Phase 5)

### Playback Progress & Resuming
- **Automatic Progress Sync**: Video and audio players throttle playback progress pings to `/multimedia/api/progress` at 15-second intervals, on pause, seek, and completion.
- **Completion Threshold**: Content is marked `is_completed = true` when playback reaches $\ge 90.0\%$ or on player `ended` event.
- **Resume Intelligence**:
  - `should_resume` is enabled only when position is $\ge 5.0$ seconds and incomplete.
  - Completed items replay from 0:00.
  - Frontend video player presents an intuitive Resume prompt with position preview and "Start Over" option.

### Personal Library & Favorites ("My List")
- Users can bookmark titles to **My List** via the toggle button on movies, series, episodes, and songs.
- Database enforces unique `(user_id, content_type, content_id)` constraints preventing duplicate entries.
- Accessible at `/multimedia/my-list` and unified dashboard at `/multimedia/library`.

### Continue Watching & Listening
- **Continue Watching**: Hydrated shelf on `/multimedia` and `/multimedia/library` displaying incomplete movies and episodes with dynamic progress bars and remaining time.
- **Continue Listening**: Resume listening to songs and playlist tracks.
- **Access Enforcement**: Content permissions are evaluated in real time. Items whose access changed to Premium display a locked state and never leak playable media streams.

### Series Progress & Next Episode
- **Series Progress**: Shows overall percentage completed across all published episodes in all seasons.
- **Next Episode Auto-Prompt**: Video player detects episode completion and displays a 10-second countdown overlay with "Play Now" and "Cancel" buttons, automatically transitioning between seasons.

---

## 8. Ecosystem Boundaries: Favorite Digital & Favorite Pay

### Absolute Entitlement Principle
> [!IMPORTANT]
> **Favorite Digital is the SOLE and EXCLUSIVE authority for PREMIUM content access.**
> Favorite Multimedia never independently decides that a user has Premium entitlement.

```
Favorite Pay (Checkout / Payment Gateway)
      │  (Payment Successful)
      ▼
Favorite Digital (Subscription / Membership Authority)
      ▲
      │  (Check Active Entitlement: Allow or Deny)
Favorite Multimedia (MultimediaAccessService & Media Delivery)
```

- **Favorite Pay Boundary**: Favorite Pay is checkout and payment infrastructure only. A successful payment transaction recorded in Favorite Pay **NEVER** directly authorizes media playback.
- **Favorite Digital Authority**: An active, unexpired subscription recorded in Favorite Digital is required to stream or download PREMIUM content.
- **Fail-Closed Gatekeeper**: If Favorite Digital is missing, disabled, uninstalled, expired, or throws an error, all PREMIUM access attempts **FAIL CLOSED (HTTP 403)**. Free (`PUBLIC`) and user-account (`LOGIN`) content remain completely operational.

---

## 9. Operational Background Jobs & Automation

Favorite Multimedia includes CLI runners for scheduled operations and background media transcoding:

### Scheduled Release Processor
Automatically publishes scheduled movies, episodes, and songs when their release time arrives:
```bash
# Run manually or via system cron (recommended: every 5-15 minutes)
php plugins/favorite-multimedia/bin/release-due.php --limit=100
```
*Note: Also hooked to the Favorite CMS scheduler action `multimedia_process_due_releases`.*

### Media Processing Worker (FFmpeg)
Processes queued transcoding jobs (HLS multi-rendition packaging, MP4 normalization, thumbnail generation):
```bash
# Run manually or via system cron (recommended: every 1-5 minutes)
php plugins/favorite-multimedia/bin/process-media.php --limit=5
```
*Note: Also hooked to the Favorite CMS scheduler action `multimedia_process_media_queue`.*

### Storage Management & Cleanup
Audits storage files, migrates drivers between local and S3, and purges orphaned storage records:
```bash
# Inspect storage driver statistics
php plugins/favorite-multimedia/bin/manage-storage.php --stats

# Clean up orphaned media files older than 24 hours
php plugins/favorite-multimedia/bin/manage-storage.php --cleanup-orphans --older-than=86400
```

---

## 10. Security Highlights

- **SSRF Protection**: Remote URLs are strictly filtered against loopback, private RFC 1918 subnets, IPv6 loopback, cloud metadata endpoints (`169.254.169.254`), and DNS rebinding.
- **Cache-Control Differentiation**: Protected streams, downloads, and subtitles enforce `Cache-Control: private, no-cache, no-store, must-revalidate` so proxies never cache authenticated chunks.
- **CSRF Protection**: All admin forms validate session CSRF tokens with constant-time string comparisons (`hash_equals`).
- **SQL Injection Prevention**: All queries use PDO prepared statements with parameterized inputs.
- **XSS Escaping**: All output in views is escaped with `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')`.
- **CWE-1236 Spreadsheet Formula Protection**: Exported analytics and reporting CSVs sanitize cells starting with `=`, `+`, `-`, or `@`.
- **Chunked File Delivery**: File downloads and proxy streaming utilize 64KB chunk buffers preventing memory spikes.

---

## 11. Troubleshooting & Diagnostics

- **FFmpeg Not Detected**:
  Verify FFmpeg is installed and accessible in the system `$PATH` (`ffmpeg -version`). If unavailable, the Media Processing studio displays an informational status; direct URL streaming and manual source registration continue to work normally.
- **S3 / CDN CORS**:
  When using cloud storage with HLS or WebVTT subtitles, ensure your S3 bucket CORS configuration permits `GET` and `HEAD` from your CMS domain.
- **Logs**:
  Plugin events, transcode statuses, and security blocks are recorded in `storage/logs/favorite_cms.log`.


