# Changelog

All notable changes to **Favorite CMS Universal** are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.12] - 2026-09-15

### Added & Security
- **Authoritative 6-Role Permission Architecture**:
  - Aligned server-side permissions across all six core roles: Super Admin, Admin, Editor, Moderator, Author, Subscriber.
  - Added `moderate_comments` and `edit_others_posts` permissions with upgrade migration `016_update_role_permissions.php`.
  - Enforced strict authorization separation: Moderator is granted "Edit Other Users' Posts" without inheriting trash, restore, or permanent deletion rights over other users' posts.
  - Granted Editor role full content management capabilities: Post Approval & Rejection, Comment Moderation, Pages, Categories/Tags, Media, and Navigation Menus.
  - Restricted Author strictly to own content creation and editing; blocked from editing other users' posts, post moderation, comment moderation, pages, and menus.
  - Restricted Subscriber to basic account management; blocked from post creation, media uploads, and administrative functions.
  - Updated Admin navigation layout with capability-level visibility controls for Posts, Pages, Media, Comments, and Menus.
  - Added comprehensive 15-scenario integration test suite (`RolePermissionMatrixTest.php`).

## [1.0.11] - 2026-09-15

### Fixed & Security
- **Super Admin Role Integrity & Safe Live-Site Recovery**:
  - Enforced zero Super Admin system invariant: prevented number of active Super Admin accounts from becoming zero through role demotion, self-demotion, deactivation, suspension, banning, or account deletion.
  - Implemented row-level locking (`SELECT id FROM roles WHERE slug = 'super-admin' FOR UPDATE`) across all user status, role, and deletion transactions to ensure strict concurrency protection.
  - Fixed runtime role resolution mismatch where database Super Admin failed to resolve capabilities due to stale session role values or unnormalized role slugs.
  - Hardened `hasRole()`, `isSuperAdmin()`, and `hasPermission()` with full slug normalization and bypass checks.
  - Added single-use, authenticated, rate-limited, password-verified Emergency Super Admin Recovery flow for the verified legitimate site administrator (User ID 1 matching site settings).

## [1.0.0-beta] - 2026-09-04

### Added
- **Dual-Mode Professional Content Editor**:
  - **Visual Mode**: Rich text WYSIWYG editor with format dropdowns (H1–H6, P, Pre), bold/italic/underline/strikethrough styling, alignment, lists, blockquotes, horizontal rules, interactive table builder, link manager, and automatic paste sanitization (stripping MS Word XML junk).
  - **Code Mode**: Syntax-friendly monospace editor with synchronized line-number gutter, Tab indentation handling, quick HTML insert tags, and large content capacity.
  - Seamless bidirectional synchronization between Visual and Code modes.
  - Local browser autosave snapshot every 20 seconds for disaster recovery.
  - Live Theme Preview rendering drafts directly within active theme styling.
- **Role-Aware Large Media System**:
  - Configured role allowances: **7 GB** for Administrators, **500 MB** for Moderators, and **200 MB** for Normal Users / Subscribers.
  - Server technical ceiling detection evaluating `upload_max_filesize`, `post_max_size`, `memory_limit`, and available disk space.
  - Early HTTP 413 error reporting on `post_max_size` overflows to protect against silent POST truncation.
  - Drag-and-drop file upload zone with real-time percentage and byte upload progress reporting.
  - Direct executable file rejection and double-extension attack defense (`.php.jpg`, etc.).
- **Core Widget Architecture & Theme Layout Customizer**:
  - Modular widget engine with `WidgetInterface`, `AbstractWidget`, `WidgetRegistry`, and `WidgetInstanceManager`.
  - 10 built-in widgets: Search, Recent Posts, Categories, Tags, Navigation Menu, Pages, Custom HTML, Image, Featured Post, Recent Comments.
  - Multi-instance widget support across theme-declared regions (sidebars, multi-column footers, header strips).
  - One-click **Reset to Theme Defaults** restoration.
  - Visual Theme Customizer (`/admin/customize`) with sidebar position toggles (Right, Left, Full Width), custom logo, brand accent color, and homepage section reordering.
- **Public User Signup & Account System**:
  - Dedicated registration endpoints (`/register`, `/signup`, `/admin/register`).
  - Automatic `subscriber` role assignment with `active` status and `password_hash()` encryption.
  - Administrative toggle in **Settings &rarr; General &rarr; Membership** (`allow_registration`).
  - Dynamic theme header navigation displaying **Sign Up** / **Log In** for visitors and **+ Create Post** / **Dashboard** for authenticated users.
- **Content Moderation Workflow**:
  - Normal user post submissions are strictly overridden on the server side to `pending` review.
  - Prevention of client-side privilege escalation (tampering `status=published` is overridden to `pending`).
  - Dedicated **Pending Review** and **Rejected** tabs in `/admin/posts` with live post counters.
  - One-click **Approve** and **Reject** actions in table row actions and inside the post editor sidebar.
  - Moderator role direct publishing capability (`publish_direct`) allowing moderators to publish immediately without review.
- **User Account Lifecycle (Suspension & Bans)**:
  - Account operational statuses: `active`, `suspended`, `banned`.
  - Suspended users are prevented from creating new posts, updating existing posts, or uploading media files.
  - Banned users cannot log in. Active sessions of banned accounts are immediately terminated upon their very next request.
  - Historical posts, media, and comments of suspended or banned accounts remain intact.
  - Administrative user table (`/admin/users`) with status badges, post counts, quick role changes, and suspend/ban/restore actions.
- **Core Architecture & Extensibility**:
  - Service container and lightweight dependency injection framework (`FavoriteCMS\Core\Application`).
  - Idempotent PDO database migration runner maintaining 13 core migrations (`database/migrations/`).
  - Multi-tier persistent installation state with automatic self-healing lock mechanism (`storage/installed.lock`).
  - Priority-based action and filter hook system (`FavoriteCMS\Core\Hook`).
  - Dynamic plugin frontend route registration engine (`FavoriteCMS\Core\Router`).
  - Dynamic admin menu registration engine (`FavoriteCMS\Core\AdminMenu`).
  - Isolated plugin settings storage service (`FavoriteCMS\Models\PluginSetting`).
- **Comprehensive Quality Assurance**:
  - Complete automated test suite expanded to **109 tests and 511 assertions** with 100% pass rate.
  - Full PHP syntax validation (`php -l`) across all production and test files.
