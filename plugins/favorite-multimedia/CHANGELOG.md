# Changelog

All notable changes to the **Favorite Multimedia** plugin for Favorite CMS are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.7] - 2026-09-09 (Song 500 Forensic Fix, Star Ratings, Discussion UX, Bulk Actions & Unified Playback)

### Fixed
- **Song 500 Internal Server Error (`TypeError: checkDownloadPermission()`)**: Fixed fatal type collision in `MultimediaFrontendController::song()` where array data returned from `MediaSourcePlaybackService::getPlayableSources()` was passed directly to `MultimediaAccessService::checkDownloadPermission()`, expecting `?MediaSource $source`. Hardened both `MultimediaAccessService::checkDownloadPermission()` and `DownloadSourceService::getDownloadOptions()` to accept `object|array|null` and safely normalize array representation into model instances, completely resolving HTTP 500 errors on song detail views.
- **Admin Settings Moderation Mode Persistence**: Ensured `review_moderation_mode` is properly updated and persisted in `MultimediaAdminController::settings()` POST processing.
- **Star Rating & Engagement UX**:
  - Eliminated disruptive browser `alert()` popups across the entire engagement interface; replaced with responsive, styled toasts (`window.FavoriteToast`).
  - Fixed star rating network handling and guest redirect preserving return URL.
  - Implemented in-flight submission locking (`isRatingInFlight`, `isCommentInFlight`, `isReviewInFlight`) preventing duplicate concurrent requests.
  - Real-time client-side rating counter and average recalculation on rating submission without full page reloads.
- **Discussion & Comment Submission**:
  - Always render discussion form with guest guidance and seamless redirect preserving content context.
  - Dynamic client-side comment hydration into the discussion thread on successful post with live counter increment.
  - Client-side and server-side length enforcement (1-1000 characters) and anti-empty checks.
- **Admin Movies & Songs Bulk Actions**:
  - Eliminated HTML5 nested form pointer corruption by routing row single-delete actions to external dedicated hidden forms.
  - Implemented multi-select master checkbox with indeterminate visual state and dynamic selected count badge.
  - Added bulk operations: `publish` (strictly enforcing media readiness gate), `draft`, and `delete` (with cascading database cleanup and permission checks).

### Added
- **Unified Media Volume Memory (`window.FavoriteMediaVolume`)**:
  - Implemented single canonical volume controller storing user audio and video volume in `localStorage['fm_media_volume']` (normalized float `0.0` - `1.0`).
  - Default initial first-play volume safely clamped to `0.25` (25%).
  - Audio and video players stay synchronized in real time: adjusting volume on either player persists and updates the other without feedback loops.
  - Smart unmute memory: unmuting safely restores previous non-zero volume (fallback to default) rather than getting stuck at 0.
- **Persistent Background Audio Playback**:
  - Unbroken audio playback across page switching, tab switching, and background minimization (`visibilitychange` / `document.hidden` guards prevent unintended pauses).
  - Media Session API integration: displays active track metadata (title, artist, album, artwork), playback state, and enables hardware media keys and notification controls (`play`, `pause`, `previoustrack`, `nexttrack`, `seekto`).
- **Floating Video / Picture-in-Picture (PiP)**:
  - Added dedicated PiP button (`.fav-btn-pip`) on video player controls using standard HTML5 Picture-in-Picture API (`video.requestPictureInPicture()`).
  - Mutual playback exclusion (`fm:audio:play` and `fm:video:play`) ensuring audio player pauses when video plays, and video pauses when audio plays.
- **Legitimate Background Audio Mode for Dual-Source Content**:
  - Dedicated "Background Audio" toggle for songs with dual video and audio streams, switching seamlessly from video playback to background audio stream without third-party ripping or scraping.
- **Admin Playback Settings**:
  - New configuration panel in Multimedia Settings: `default_media_volume` (clamped 5%-50%), `remember_media_volume` (Yes/No), `enable_pip` (Yes/No), and `enable_background_audio` (Yes/No).
  - Dynamic injection of configuration into frontend player via `window.FavoriteMultimediaConfig`.

## [1.0.6] - 2026-09-08 (Auto Next Playback for Playlists & Episodes)

### Added
- **Auto Next Playback Service (`NextItemResolverService`)**: Professional next-item resolution engine for video/audio playlists and series episodes.
  - Episode progression across same season (`episode_number > current`) and automatic rollover into subsequent season (`season_number > current`).
  - Strict loop protection with bounded iteration traversal preventing recursion on corrupted or circular data.
  - Safe skip filtering for draft, future-scheduled (`publish_at > NOW()`), deleted, and source-less media items.
  - Fail-closed entitlement checks enforcing `MultimediaAccessService` and Favorite Digital adapter integration; unauthorized items never leak protected stream URLs.
- **Playlist Sequencing & Loop Mode**:
  - Sequential playback following playlist item sort order (`sort_order ASC, id ASC`).
  - Playlist context preservation across tracks belonging to multiple playlists.
  - Support for repeat/loop mode (seamlessly cycling to the first playable track) or clean termination (`playlist_completed`) when the final track ends.
  - Safe skipping of unplayable or restricted tracks in background audio playlist playback.
- **Player UI "Up Next" Overlay**:
  - Polished Up Next card with thumbnail preview, title, episode/track badge, and 5-second countdown timer.
  - Action controls: "Play Now" (instant navigation) and "Cancel" (cancels countdown and maintains completed player state).
  - Celebration card on series and playlist completion with clean navigation.
- **Browser Autoplay Rejection Guard**:
  - Catches browser `NotAllowedError` / `AbortError` on unmuted autoplay restrictions without triggering false stream failover.
  - Renders a clean, accessible "Click to Play" overlay button.
- **Global Settings for Auto-Play Next**:
  - Added admin settings `auto_play_next` (Enabled/Disabled) and `auto_next_countdown` (3-30 seconds).
- **REST API Endpoints**:
  - Exposing `/multimedia/api/next-episode/{id}` and `/multimedia/api/next-playlist-item/{playlistId}/{currentItemId}`.

## [1.0.5] - 2026-09-07 (Main Form Publishing, Inline External Embed & Edit Workflow Fix)

### Fixed
- **HTML Form Nesting in Movie & Episode Forms**: Auxiliary action forms (`reorder`, `set_default`, `toggle_status`, `delete`) and the dedicated "Add Another Playback Source" subform were nested inside the main `<form>`. Per WHATWG HTML5 parsing rules, inner form closing tags reset the parser's form pointer to `null`, completely detaching `Publish Now`, `Save Draft`, `Schedule`, `access_mode`, `status`, and subsequent fields. Moved all auxiliary forms outside the main form element, restoring full update lifecycle and field submission integrity.
- **Duplicate Form Field Collision**: Addressed `<input type="text" name="video_url">` collisions where empty subform inputs overwrote the main video URL on form submission.
- **Inline External Embed & Iframe Snippet Support**: Added `MediaSourceResolver::extractIframeUrl()` to parse `src` from pasted `<iframe>` tags, decode HTML entities, and normalize protocol-relative URLs (`//`). Expanded `isKnownEmbedHost()` with mainstream embed providers (Cloudflare Stream, Twitch, Facebook, Wistia, Rumble, Streamtape, BunnyCDN, Mux, Loom, Spotify) and subdomain wildcard matching.
- **Trusted Embed Domain Normalization**: In `MediaSourceResolver::isEmbedDomainAllowed()` and Settings save, lines in `trusted_embed_domains` are now split on newlines, commas, and semicolons and stripped of schemes (`https://`, `http://`), ports, and trailing slashes/paths. Allows domains entered with protocol or trailing paths to match correctly while preserving strict rejection of spoofed hostnames and SSRF attempts.
- **Embed MIME Type Hardening**: Ensured manual and resolved embed sources consistently declare `mime_type = 'text/html'`.
- **Actionable Flash Error Reporting**: Fixed dropped warnings on No Media Protection draft demotion (`$_SESSION['flash_warning']` was ignored by Core layout). Controller now writes detailed failure reasons (e.g. untrusted embed domain) to `$_SESSION['flash_error']` and unsets `$_SESSION['flash_success']`.
- **Multimedia Sidebar Navigation**: Restored the canonical `Dashboard` submenu item under `multimedia` while hiding only the duplicate `Multimedia` child via targeted admin styles and DOM cleanup.

## [1.0.4] - 2026-09-07 (Critical Publish / Update Workflow Fix)

### Fixed
- **First Publish auto-reverts to Draft**: `MediaSourceResolver::resolve()` previously returned `valid=false` for URLs with
  unrecognized extensions (CDN signed URLs, dynamic streaming endpoints), aborting source creation silently. The No Media
  Protection then fired, demoting newly published content to Draft. These URLs are now treated as generic `video` type
  and successfully saved. Source type can be corrected via Advanced Sources if needed.
- **Metadata-only edits demote Published content to Draft**: The No Media Protection in `movies()`, `episodes()`, and
  `songs()` previously used `activeOnly=true`, meaning any content whose source was disabled (via the Sources panel) would
  be silently demoted to Draft on every metadata update. Changed to `activeOnly=false` — a disabled source still means
  "media is configured"; only zero sources of any status triggers draft demotion.
- **Live DNS lookups blocking URL saves**: `validateUrlSecurity()` performed `gethostbynamel()` DNS resolution on every URL
  entered in the admin form. On production servers with restrictive outbound DNS this caused timeouts, hangs, or false SSRF
  rejections for legitimate CDN hosts. Removed — admin-entered URLs are served to browsers, not fetched server-side.
- **"No Media" badge shown for content with sources**: `getMediaStatusForContent()` caught all `Throwable` exceptions and
  silently returned `status='no_media'`, causing the "No Media" badge to appear even when sources exist but a temporary DB
  error occurred. Now returns a distinct `status='error'` / `label='Check Failed'` badge.

### Internal
- Song No Media Protection warning messages updated to match new activeOnly=false logic.
- Episode / Movie No Media Protection warning messages updated accordingly.

---

## [1.0.3] - 2026-09-07 (Playback Reliability, Multi-Source & Song Video Support)

### Added
- **Song Video / Music Video Full Source Parity**:
  - Songs now have 100% video publishing parity with Movies and Episodes:
    - Direct MP4/WebM uploads with automatic FFmpeg adaptive HLS transcoding
    - Direct Video URL streams
    - Adaptive HLS (`.m3u8`) streaming playlists
    - YouTube video links (normalized to privacy-enhanced embed endpoints)
    - Vimeo video links (normalized to player embed endpoints)
    - Trusted External Embeds (with domain allowlist security and sandboxing)
- **Single Canonical Song Identity with Dual-Mode Support**:
  - `multimedia_songs` extended with `playback_type` (`audio`, `video`, `audio_video`) and `default_playback_mode` (`audio`, `video`).
  - A Song can be Audio-only, Video-only (Music Video), or Audio + Video (Dual Mode), maintaining a single canonical database record and slug.
  - Reviews, ratings, favorites, playlists, and analytics stay bound to the song entity regardless of playback mode.
- **Independent Default Source Enforcement per Media Kind**:
  - `multimedia_sources` extended with `media_kind` (`audio`, `video`).
  - Dual-mode songs maintain independent primary defaults: an audio default and a video default. Setting a new audio default preserves the video default and vice-versa.
- **Frontend Dual-Mode Switcher & Dedicated Players**:
  - When both Audio and Video sources exist, song pages display interactive `🎵 Audio Track` | `🎬 Music Video` toggle pills. Switching players seamlessly pauses the background media.
  - When only one media type exists, that player renders directly without superfluous mode toggles.
  - Multiple audio tracks render an audio server switcher ($N_{audio} \ge 2$); multiple video tracks render the video source switcher ($N_{video} \ge 2$).
- **Audio Playlist Compatibility for Music Videos**:
  - Playlists gracefully prioritize audio tracks for dual-mode songs. Video-only tracks skip automatically during autoplay with a non-blocking notification, avoiding playback queue crashes.
- **Publication Readiness Enforcement**:
  - Publishing readiness validates media source presence matching `playback_type`: Audio requires $\ge 1$ audio source, Video requires $\ge 1$ video source, and Dual-Mode requires $\ge 1$ of each; otherwise reverts safely to Draft with actionable admin notices.
- **Generic External Embed with Domain Allowlist**:
  - Support for trusted third-party video players and external iframe embeds.
  - Admin-configurable `trusted_embed_domains` setting (in Admin > Settings) validating external embed URLs against allowed hostnames or wildcards (e.g. `*.example.com`).
  - Private IP and loopback range rejection (`127.0.0.0/8`, `10.0.0.0/8`, `192.168.0.0/16`, `172.16.0.0/12`, `localhost`) to protect against SSRF.
  - Sandboxed iframe rendering with `sandbox="allow-scripts allow-same-origin allow-presentation allow-forms"` and `allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"`.
- **Multiple Playback Sources per Content**:
  - Support for attaching multiple video/embed sources to Movies and Episodes with custom labels (e.g., "Primary HLS Stream", "Backup MP4 (1080p)", "YouTube Mirror", "External Partner Player").
  - Single default source enforcement (`MediaSource::enforceSingleDefault()`): setting a new default automatically unsets any previous default.
  - Graceful fallback promotion (`MediaSource::promoteNextDefault()`): deleting or disabling a default source automatically designates the next active source as default.
  - Admin inline multi-source controls: reorder sources (Move Up / Move Down), toggle active status, set default source, and delete attached sources.
- **Automatic Playback Failover**:
  - Client-side error interception for fatal HTML5 media errors (Code 2: Network Error, Code 3: Decode Error, Code 4: Source Not Supported) and HLS fatal errors (`networkError`, `mediaError`).
  - Automatic, non-blocking failover progression to the next available playback source with cycle/loop protection.
  - Non-blocking toast notifications informing viewers when playback failover occurs.
- **Viewer Source Switcher**:
  - Frontend player source selector dropdown rendered seamlessly when multiple active sources exist ($N \ge 2$), preserving playback position (`currentTime`) during transitions.
  - Fully hidden and omitted when only 1 source exists for complete backward compatibility.
  - Floating switcher overlay for external iframe embeds allowing switching back to internal sources.
- **Centralized Playback Service (`MediaSourcePlaybackService`)**:
  - Centralized, fail-closed playback resolution enforcing `MultimediaAccessService::checkAccess()` (PUBLIC, LOGIN, PREMIUM).
  - Returns structured, normalized source payloads for frontend player initialization.

### Fixed
- **YouTube & Vimeo Playback Normalization**:
  - Fixed player breakdown caused by raw watch/share URLs (`youtube.com/watch?v=...`, `youtu.be/...`, `vimeo.com/...`) by normalizing to privacy-enhanced embed endpoints:
    - YouTube: `https://www.youtube-nocookie.com/embed/{id}` with strict hostname validation (`youtube.com`, `www.youtube.com`, `m.youtube.com`, `youtu.be`, `www.youtube-nocookie.com`).
    - Vimeo: `https://player.vimeo.com/video/{id}` with strict hostname validation (`vimeo.com`, `www.vimeo.com`, `player.vimeo.com`).
  - Added strict extraction of video IDs via URL path and query parsing, stripping untrusted URL parameters.

---

## [1.0.2] - 2026-09-07 (Runtime Null-Safety & View Isolation Fix)

### Fixed
- **Edit Movie / Episode / Song 500 Error Resolution**:
  - Eliminated inline `FFmpegService::isAvailable()` binary execution from view templates (`movies.php`, `episodes.php`, `songs.php`), moving detection to safe controller pre-evaluation.
  - Hardened `FFmpegService::isAvailable()` and `FFmpegService::isProbeAvailable()` to safely check `function_exists('exec')` and handle disabled execution functions with full exception containment.
  - Implemented strict null-safety on all `strtoupper()` and `htmlspecialchars()` calls inside admin views, preventing PHP 8.1–8.5 deprecation warnings and fatal TypeError crashes when `source_type` or `label` is null or missing.
  - Enhanced `MediaSource::getDefault()` with automatic fallback to any attached source if no source with `status = 'active'` is found, ensuring processing and pending sources are displayed correctly on edit screens.
  - Added safe model accessors on `MediaSource`: `getSourceType()`, `getSourceLabel()`, `getUrlOrPath()`, and `getStatus()`.
  - Wrapped `getMediaStatusForContent()` in `try/catch` with safe fallback badge metadata.
  - Isolated the `Video / Media Stream` section in `movies.php`, `episodes.php`, and `songs.php` in localized `try/catch` error containment that renders a user-friendly notice (`"Media source could not be loaded. Please edit or replace the source."`) instead of aborting the entire page with a 500 error.

---

## [1.0.1] - 2026-09-07 (Simple Media Publishing UX Update)

### Added
- **Direct Media Publishing on Content Forms**:
  - Unified Video & Media Source panel on Movie and Episode create/edit screens with tabbed selectors: Upload Video File, Direct MP4/WebM URL, HLS Stream, YouTube URL, Vimeo URL.
  - Unified Audio Source panel on Song create/edit screens with tabbed selectors: Upload Audio File (MP3, M4A, FLAC, WAV, AAC, OGG), Direct Audio URL.
  - Direct artwork uploads: Poster, Backdrop, Thumbnail, and Album Cover files can now be uploaded directly from content forms without leaving the page.
  - Inline subtitle track file uploads (`.vtt`, `.srt`) directly from Movie and Episode editor forms.
  - Quick submit action controls: `🚀 Publish Now` (sets status to published and saves), `📝 Save Draft` (sets status to draft and saves), and `📅 Schedule` (sets status to scheduled with release date).
  - Clear server upload limits display (`upload_max_filesize`, `post_max_size`) and live FFmpeg background processing status indicators.
- **Empty States & Usability**:
  - Friendly, high-visibility "Add Your First..." cards for empty states across Movies, Web Series, Episodes, and Songs lists.
  - Media status badges in list tables: `Ready`, `Processing`, `No Media`, and `Failed` with color-coded pills.
  - Submenu labeled "Advanced Sources" to clarify that daily publishing happens directly on content forms while maintaining route backward compatibility.
- **No-Media Safety & Protection**:
  - Protection against broken public player pages: saving content as published with 0 attached media sources automatically reverts status to draft and issues an admin flash warning.
  - Frontend detail players render a graceful "Media Coming Soon" placeholder card if public content has no active media sources.
- **Auto-Upsert Media Sources**:
  - Automatic `MediaSource` record creation and updating when saving content forms, preventing duplicate sources on repeated edits.
  - Direct HTML5 playback fallback for uploaded MP4/WebM files and external URLs when FFmpeg is not available.

### Added
- **Migration 004**: `004_create_multimedia_subscription_notification_tables.php` creating `multimedia_subscriptions`, `multimedia_notifications`, and `multimedia_notification_preferences`.
- **Content Subscriptions**:
  - Follow/Subscribe to Series, Artists, and Playlists with idempotent toggle operations.
  - Database composite unique index `(user_id, target_type, target_id)` strictly preventing duplicate subscriptions.
  - Dedicated Following directory page at `/multimedia/following` displaying user's subscribed series, artists, and playlists.
- **Release Alerts & Notification System**:
  - In-app notification inbox at `/multimedia/notifications` with unread count badge, All/Unread filter tabs, and mark-read controls.
  - New episode alerts: automatic notification fan-out to series followers when new episodes are published.
  - New artist song alerts: automatic notification fan-out to artist followers when new tracks are published.
  - Playlist update alerts: notification to playlist followers on track additions.
  - Engagement reply alerts: notifications to parent comment authors on new discussion replies (with self-reply suppression).
  - Moderation outcome alerts: notifications to review/comment authors on approval or rejection transitions.
- **Delivery Protection & Privacy**:
  - DB-level unique `dedupe_key` constraint preventing duplicate notifications on repeat edits and multi-artist releases.
  - Notification preferences: user toggles for Content Updates, Engagement Replies, and Moderation Updates.
  - Authoritative playback entitlement: notifications never include stream URLs and never bypass `MultimediaAccessService`.
  - Author privacy: zero sensitive leakage of emails, phones, or password hashes in notification payloads.
  - Personal cache isolation: private `no-store` cache controls on all notification pages and APIs.
- **Cascade Deletion**: Automatic cleanup of subscriptions when parent series, artists, or playlists are removed.

## [1.3.0] - 2026-09-05 (Phase 7 Ratings, Reviews, Comments, Reporting & Moderation)

### Added
- **Migration 003**: `003_create_multimedia_engagement_tables.php` creating `multimedia_ratings`, `multimedia_reviews`, `multimedia_review_helpful`, `multimedia_comments`, and `multimedia_reports`.
- **Star Rating Engine**: Numeric 1–5 star ratings with unique `(user_id, content_type, content_id)` database constraints, live SQL average and count aggregation, and real-time rating updates without duplicating count.
- **Audience Reviews**:
  - Full-length reviews (3 to 3,000 characters) with optional headline title and linked rating score.
  - Strict user ownership enforcement (only author can edit or delete their review).
  - Moderator status workflow (`approved`, `pending`, `rejected`, `hidden`) with configurable auto-approval policy (`review_moderation_mode`).
  - "Contains Spoiler" flag toggle with frontend spoiler warning and click-to-reveal barrier.
  - "Helpful" feedback reactions: one helpful vote per user per review with count increments and undo support.
- **Discussion Comments**:
  - Threaded comment discussion with shallow 1-level reply nesting.
  - Strict user ownership checking for edits and deletions.
- **Community Safety & Reporting**:
  - Report modal for reviews and comments with whitelisted reasons (`spam`, `harassment`, `off_topic`, `other`) and optional notes.
  - Unique report constraint preventing duplicate reports by the same user on the same target.
- **Administrative Moderation Dashboard**:
  - Dedicated moderation queue view at `/admin/page/multimedia-moderation` protected by `MultimediaPermission::MODERATE`.
  - Tabbed queue for Pending Reviews, Reported Reviews, All Comments, and Open Reports.
  - Single and bulk moderation actions: Approve, Reject, Hide, Delete, Dismiss Report, and Action Report with session CSRF protection.
- **Privacy & Safety Protections**:
  - Author formatting strictly protects user privacy, never exposing email, phone, internal ID, or password hash.
  - Graceful "Deleted User" display when an author account is removed.
  - XSS output escaping using `htmlspecialchars()` with UTF-8 encoding across all engagement views.
  - Parameterized SQL prepared statements across all engagement queries.
  - CSRF validation on all state mutations.
- **Cascade Deletion**: Complete cleanup of ratings, reviews, comments, and reports when parent content is deleted.
- **Discovery Boost Integration**: Content items with $\ge 3$ ratings and $\ge 4.0$ star average receive a modest quality boost (+1.5 pts) in Popular discovery ranking.

## [1.2.0] - 2026-09-05 (Phase 6 Discovery Engine, Related Content & Trending)

### Added
- **Centralized Discovery Engine**: Introduced `MultimediaDiscoveryService` for deterministic, explainable, privacy-safe media discovery.
- **Related Content Rails**:
  - Related Movies: Multi-factor scoring via shared genres (+10 pts), director match (+15 pts), cast overlap (+5 pts), and logarithmic views bonus.
  - Related Series: Content-type preserving recommendations based on shared genres and director relationships.
  - Related Songs: Music affinity scoring prioritizing same artist (+30 pts), same album (+25 pts), and shared genres (+15 pts).
  - Related Playlists: Discovers published playlists containing tracks by the same artist or tagged with the genre.
- **Trending Now Feed**:
  - Time-bounded rolling engagement window (default 7 days, configurable via admin settings).
  - Weighted engagement formula: plays (3.0), views (1.0), downloads (2.0), recent favorites (4.0).
  - Manipulation resistance: Counts distinct actors `COALESCE(user_id, ip_hash)` to protect rankings against repeated flooding.
  - Cold catalog smoothing: Blends top published items when window activity is sparse.
- **All-Time Popular Ranking**: Deterministic formula aggregating views, plays, and unique favorites.
- **Recently Added Feed**: Chronological catalog arrivals by publication and release dates.
- **Personalized Recommendations**:
  - "Because You Watched [Title]": Derives recommendations from meaningful recent playback ($\ge 30$s or $\ge 5\%$).
  - "Because You Liked [Title]": Leverages user bookmarks as an explicit preference signal.
  - Personalized Discovery Feed: Synthesizes preferred genres and artists with completion exclusions and diversity caps ($\le 3$ items per primary genre).
  - Cold-Start Fallback: New users without history or likes seamlessly receive a curated discovery blend with zero broken UI.
- **Dedicated Discovery Pages & Taxonomy Routes**:
  - `/multimedia/discover`: Comprehensive discovery hub with multi-shelf overview, tab filters (Trending, Popular, Recent, Genres), and format filters.
  - `/multimedia/genre/{slug}`: Browse movies, series, and songs tagged with specific taxonomy genres.
  - `/multimedia/artist/{slug}`: Artist profile featuring discography, albums, and playlists.
  - `/multimedia/album/{slug}`: Album detail with artwork, release year, and tracklist.
- **Access Safety & Zero-Leakage**: All discovery candidates are hydrated with live `MultimediaAccessService` entitlement evaluation. Restricted items display lock badges and never leak stream URLs.
- **Admin Discovery Configuration**: Added settings for global discovery toggle, trending toggle, rolling window days, and maximum rail items.

## [1.1.0] - 2026-09-05 (Phase 5 Personal Library, Playback Progress & Favorites)

### Added
- **Playback Progress Tracking**: Lightweight, throttled (15-second intervals, pause, seek, ended) progress reporting via `/multimedia/api/progress`.
- **Completion & Resume Logic**:
  - Automatically marks content completed at $\ge 90.0\%$ completion threshold or explicit player ended event.
  - Generates intelligent resume recommendation when position is $\ge 5.0$ seconds and incomplete.
  - Completed items restart from 0:00 upon subsequent playback.
- **Continue Watching Feed**: Hydrated, access-verified continue watching shelf for movies and episodes with dynamic remaining time calculation.
- **Continue Listening Shelf**: Seamless audio playback resume for individual tracks and curated playlists.
- **Series & Episode Progress**:
  - Calculates series-level completion percentage based on published episodes across all seasons.
  - Smart Next Episode resolution: seamlessly advances across seasons or terminates cleanly at the series finale.
  - Post-play next episode auto-prompt countdown overlay on video completion.
- **Personal Library & My List**:
  - Bookmarking and favorites engine with unique `(user_id, content_type, content_id)` database constraints.
  - Comprehensive frontend user dashboard at `/multimedia/library`, dedicated `/multimedia/my-list`, and `/multimedia/history`.
  - Filterable by content type (`movie`, `series`, `song`, `playlist`).
  - Clear all history and individual item deletion with user data isolation.
- **Access Safety & Zero-Leakage**: Favorites and history feeds strictly evaluate active user entitlements via `MultimediaAccessService`. Restricted items display locked state and never leak media stream URLs.
- **Automated Test Coverage**: Added `FavoriteMultimediaPhase5Test.php` with 14 tests and 95 assertions verifying migrations, models, services, guest safety, cross-user isolation, completion rules, cascade deletions, and Bangla/Unicode titles.

---

## [1.0.0] - 2026-09-05 (Phase 4 Production Release)

### Added
- **E2E Lifecycle Verification**: Full verification of plugin installation, activation, deactivation, and reactivation with complete data preservation.
- **Operational Logging**: Integrated with `FavoriteCMS\Core\Logger` for operational visibility:
  - Security audit logging for blocked SSRF and DNS rebinding attempts.
  - Informational logging for media access denials (login required, premium membership required).
  - Error and warning logging for missing local files, path traversal attempts, and unreachable remote media hosts without logging sensitive credentials or auth tokens.
- **HTTP HEAD Request Support**: Added native handling of HTTP `HEAD` requests on media streaming and download endpoints, allowing media players and CDNs to inspect headers, content lengths, and range capabilities without transmitting message bodies.
- **Cache-Control Differentiation**: Protected media streams, downloads, and subtitle tracks strictly enforce `Cache-Control: private, no-cache, no-store, must-revalidate` and `Pragma: no-cache` to prevent upstream caching of gated media chunks, while public streams send safe public cache headers.
- **DNS Rebinding Protection**: Enhanced `MediaSourceResolver::validateUrlSecurity()` to inspect DNS-resolved IP addresses against private and reserved ranges.
- **Multibyte Unicode Support**: Verified full UTF-8/Bengali query handling across Movie, Series, Song, and Search models using parameterized PDO statements and LIMIT/OFFSET pagination.
- **Comprehensive Test Suite**: Added `FavoriteMultimediaPhase4Test.php` covering lifecycle idempotency, HEAD requests, Cache-Control protection, SSRF edge cases, and graceful missing media handling.

### Changed
- Cleaned all `console.log` invocations from frontend media player scripts (`multimedia-player.js`, `multimedia-audio.js`), replacing them with silent promise rejection handlers to keep browser developer consoles clean.
- Made `MultimediaAccessService::findContentModel()` public for cross-service inspection.

---

## [0.3.0] - 2026-09-05 (Phase 3 Content Workflow & Frontend Polish)

### Added
- Content publishing workflows: draft, published, and unlisted visibility states.
- Enhanced frontend catalog hub, movie detail, series episodes list, and continuous audio playlist player.
- Dedicated subtitle track delivery engine (`MediaDeliveryService::deliverSubtitle`).
- Granular download permission policy (`multimedia.enable_downloads`, content policy, and source policy).
- Automated test coverage in `FavoriteMultimediaPhase3Test.php`.

---

## [0.2.0] - 2026-09-05 (Phase 2 Integration Audit & Production Stabilization)

### Added
- Ecosystem integrations: `FavoriteDigitalAdapter` (membership pass entitlement) and `FavoritePayAdapter` (checkout orchestration).
- HTTP 206 Partial Content (Byte range) streaming engine with seeking support.
- Centralized 3-tier access control service (`MultimediaAccessService`): `PUBLIC`, `LOGIN`, `PREMIUM`.
- Access inheritance: Series &rarr; Season &rarr; Episode with explicit episode overrides.
- Automated test coverage in `FavoriteMultimediaPhase2Test.php`.

---

## [0.1.0] - 2026-09-05 (Phase 1 Initial Architecture & Schema)

### Added
- Core multimedia database schema (14 tables with indexes for slugs, statuses, foreign keys, and ordering).
- Models: `Movie`, `Series`, `Season`, `Episode`, `Song`, `Playlist`, `PlaylistItem`, `Genre`, `Artist`, `Album`, `MediaSource`, `Subtitle`, `AnalyticsEvent`.
- URL-first architecture: direct video/audio, HLS streaming manifests (`.m3u8`), and third-party embeds (YouTube, Vimeo, Dailymotion, SoundCloud).
- Rigorous SSRF protection blocking loopback, private RFC 1918 subnets, IPv6 loopback, and cloud metadata services.
- Admin dashboard, media management interfaces, and initial automated tests in `FavoriteMultimediaTest.php`.

